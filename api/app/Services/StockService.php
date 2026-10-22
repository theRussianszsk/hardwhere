<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\StockLedgerEntry;
use App\Models\StockSnapshot;
use App\Models\TenantListing;
use App\Support\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only writer of stock_snapshots and stock_ledger_entries. Every method must run inside a DB transaction:
 * rows are locked with SELECT ... FOR UPDATE in a deterministic order (item id, then location id) to avoid deadlocks,
 * availability is re-checked on the locked rows, and the CHECK constraint on stock_snapshots is the last line of defence.
 */
class StockService
{
    public const POSITIVE_ONLY = ['opening_balance', 'purchase_receipt', 'transfer_in'];

    public const NEGATIVE_ONLY = ['damage', 'loss', 'transfer_out'];

    public function __construct(private SyncRecorder $sync) {}

    /**
     * Lock every sellable snapshot of the given items at one store.
     *
     * @param  list<int>  $itemIds
     * @return Collection<int, StockSnapshot>
     */
    public function lockStoreStock(int $tenantId, int $storeId, array $itemIds): Collection
    {
        $this->assertInTransaction();
        sort($itemIds);

        return StockSnapshot::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('inventory_item_id', $itemIds)
            ->whereIn('location_id', fn ($q) => $q->select('id')->from('inventory_locations')
                ->where('tenant_id', $tenantId)->where('store_id', $storeId)->where('is_sellable', true)->whereNull('deleted_at'))
            ->orderBy('inventory_item_id')->orderBy('location_id')
            ->lockForUpdate()->get();
    }

    /**
     * Pick locations to satisfy $quantity from already-locked rows. $buffer is withheld from the pool (marketplace buffer).
     *
     * @param  Collection<int, StockSnapshot>  $locked
     * @return list<array{snapshot: StockSnapshot, quantity: int}>
     */
    public function allocate(Collection $locked, int $itemId, int $quantity, int $buffer = 0): array
    {
        $rows = $locked->where('inventory_item_id', $itemId)->sortBy('location_id');
        $available = max(0, $rows->sum(fn (StockSnapshot $s) => max(0, $s->on_hand - $s->reserved)) - $buffer);

        if ($available < $quantity) {
            throw ApiException::conflict('stock_unavailable', 'Not enough stock is available.', [
                'inventory_item_id' => $itemId, 'requested' => $quantity, 'available' => $available,
            ]);
        }

        $plan = [];
        $remaining = $quantity;
        foreach ($rows as $snapshot) {
            $take = min($remaining, max(0, $snapshot->on_hand - $snapshot->reserved));
            if ($take > 0) {
                $plan[] = ['snapshot' => $snapshot, 'quantity' => $take];
                $remaining -= $take;
            }
            if ($remaining === 0) {
                break;
            }
        }

        return $plan;
    }

    /** Apply a movement to a locked snapshot and append the ledger entry. */
    public function move(
        StockSnapshot $snapshot,
        int $quantityDelta,
        int $reservedDelta,
        string $movement,
        ?int $actorUserId,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $note = null,
        ?int $listingId = null,
    ): StockLedgerEntry {
        $this->assertInTransaction();

        $after = $snapshot->on_hand + $quantityDelta;
        $afterReserved = $snapshot->reserved + $reservedDelta;
        if ($after < 0 || $afterReserved < 0 || $afterReserved > $after) {
            throw ApiException::conflict('stock_unavailable', 'This movement would make stock negative or over-reserved.', [
                'inventory_item_id' => $snapshot->inventory_item_id, 'on_hand' => $snapshot->on_hand, 'reserved' => $snapshot->reserved,
            ]);
        }

        $entry = StockLedgerEntry::create([
            'tenant_id' => $snapshot->tenant_id,
            'inventory_item_id' => $snapshot->inventory_item_id,
            'location_id' => $snapshot->location_id,
            'listing_id' => $listingId,
            'movement_type' => $movement,
            'quantity_delta' => $quantityDelta,
            'before_quantity' => $snapshot->on_hand,
            'after_quantity' => $after,
            'reserved_delta' => $reservedDelta,
            'before_reserved' => $snapshot->reserved,
            'after_reserved' => $afterReserved,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'actor_type' => $actorUserId ? 'user' : 'system',
            'actor_id' => $actorUserId,
            'note' => $note,
            'occurred_at' => now(),
        ]);

        StockSnapshot::query()->withoutGlobalScopes()->whereKey($snapshot->id)->update([
            'on_hand' => $after, 'reserved' => $afterReserved, 'version' => DB::raw('version + 1'), 'updated_at' => now(),
        ]);
        $snapshot->forceFill(['on_hand' => $after, 'reserved' => $afterReserved])->syncOriginal();

        return $entry;
    }

    /** Manual/receipt-style movement at one location (creates the snapshot row on first use). */
    public function adjust(
        int $tenantId,
        ?int $actorUserId,
        int $itemId,
        int $locationId,
        string $movement,
        int $quantityDelta,
        ?string $note = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockLedgerEntry {
        $this->assertInTransaction();

        if ($quantityDelta === 0) {
            throw ApiException::invalid('zero_quantity', 'Quantity change cannot be zero.');
        }
        if (in_array($movement, self::POSITIVE_ONLY, true) && $quantityDelta < 0) {
            throw ApiException::invalid('invalid_direction', "$movement must increase stock.");
        }
        if (in_array($movement, self::NEGATIVE_ONLY, true) && $quantityDelta > 0) {
            throw ApiException::invalid('invalid_direction', "$movement must decrease stock.");
        }

        $location = InventoryLocation::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->find($locationId)
            ?? throw ApiException::notFound('Location');

        DB::table('stock_snapshots')->insertOrIgnore([
            'tenant_id' => $tenantId, 'inventory_item_id' => $itemId, 'location_id' => $location->id, 'on_hand' => 0, 'reserved' => 0, 'version' => 0, 'updated_at' => now(),
        ]);
        $snapshot = StockSnapshot::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where('inventory_item_id', $itemId)->where('location_id', $location->id)->lockForUpdate()->firstOrFail();

        $entry = $this->move($snapshot, $quantityDelta, 0, $movement, $actorUserId, $referenceType, $referenceId, $note);
        $this->touchItem($tenantId, $itemId);

        return $entry;
    }

    /** Tell sync consumers that an item's stock (and the listings that expose it) changed. */
    public function touchItem(int $tenantId, int $itemId): void
    {
        $this->sync->tenantEntity($tenantId, 'inventory_item', $itemId);
        TenantListing::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('inventory_item_id', $itemId)
            ->pluck('id')->each(fn ($id) => $this->sync->listing($tenantId, $id));
    }

    /**
     * SQL expression: units a customer could take right now at the listing's store (before the marketplace buffer).
     * Reservations already count against it.
     */
    public static function availableSql(string $listingTable = 'tenant_listings'): string
    {
        return "(SELECT COALESCE(SUM(GREATEST(ss.on_hand - ss.reserved, 0)), 0) FROM stock_snapshots ss
                 JOIN inventory_locations il ON il.id = ss.location_id AND il.tenant_id = ss.tenant_id
                 WHERE ss.inventory_item_id = $listingTable.inventory_item_id AND il.store_id = $listingTable.store_id
                   AND il.is_sellable AND il.deleted_at IS NULL)";
    }

    /** @param  Builder<TenantListing>  $query */
    public static function withAvailability(Builder $query): Builder
    {
        return $query->addSelect('tenant_listings.*')
            ->selectRaw('GREATEST('.self::availableSql().' - tenant_listings.stock_buffer, 0) AS marketplace_available')
            ->selectRaw(self::availableSql().' AS pos_available');
    }

    private function assertInTransaction(): void
    {
        DB::transactionLevel() > 0 || throw new LogicException('Stock changes must run inside a database transaction.');
    }
}
