<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Cursor-based change feed over sync_changes. A cursor is "txid.id". Rows are ordered by (txid, id) and only rows whose
 * transaction is older than every still-open transaction are served (pg_snapshot_xmin), so a late-committing writer can never
 * land *behind* a cursor a client already saved. Cursors are positions, not authorization: audiences are recomputed per request.
 */
class ChangeFeed
{
    public const START = '0.0';

    /** @param  list<string>  $audiences */
    public function head(array $audiences): string
    {
        $row = $this->base($audiences)->orderByDesc('txid')->orderByDesc('id')->selectRaw('txid::text AS txid, id')->first();

        return $row ? "{$row->txid}.{$row->id}" : self::START;
    }

    /**
     * @param  list<string>  $audiences
     * @return array{changes: list<array{entity: string, entity_id: int}>, cursor: string, has_more: bool}
     */
    public function since(array $audiences, string $cursor, int $limit): array
    {
        [$txid, $id] = array_map('intval', explode('.', preg_match('/^\d+\.\d+$/', $cursor) ? $cursor : self::START)) + [0, 0];

        $rows = $this->base($audiences)->whereRaw('(txid, id) > (?::xid8, ?)', [(string) $txid, $id])
            ->orderBy('txid')->orderBy('id')->limit($limit + 1)->selectRaw('txid::text AS txid, id, entity, entity_id')->get();

        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);
        $last = $page->last();

        // Collapse repeated touches of the same entity inside the page; the projector always reads current state.
        $changes = $page->map(fn ($r) => ['entity' => $r->entity, 'entity_id' => (int) $r->entity_id])
            ->reverse()->unique(fn ($c) => $c['entity'].':'.$c['entity_id'])->reverse()->values()->all();

        return ['changes' => $changes, 'cursor' => $last ? "{$last->txid}.{$last->id}" : $cursor, 'has_more' => $hasMore];
    }

    /** @param  list<string>  $audiences */
    private function base(array $audiences)
    {
        return DB::table('sync_changes')->whereIn('audience', $audiences)
            ->when(config('hardwhere.sync.xmin_horizon'), fn ($q) => $q->whereRaw('txid < pg_snapshot_xmin(pg_current_snapshot())'));
    }
}
