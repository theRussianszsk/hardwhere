<?php

namespace Database\Seeders;

use App\Ai\MockVisionProvider;
use App\Enums\OrderStatus;
use App\Enums\TenantRole;
use App\Models\AiAnalysis;
use App\Models\Category;
use App\Models\InventoryLocation;
use App\Models\MarketplaceCart;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\ProductAlias;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\StockSnapshot;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\TenantListing;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AiIntakeService;
use App\Services\CatalogMatcher;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\PosService;
use App\Services\ReservationService;
use App\Services\StockService;
use App\Services\SyncRecorder;
use App\Support\ApiException;
use App\Support\Text;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic end-to-end demo set in Metro Cebu (the pilot beachhead), plus one Metro Manila store and one suspended tenant.
 *
 * - The SAME canonical products are stocked by several tenants with their own SKU conventions, prices, costs and quantities.
 * - 30 days of history are produced by the real services under a moved clock (Carbon::setTestNow): opening balances, POS
 *   sessions and sales, weekly purchase receipts, a transfer, damage/loss/correction, a void, and orders in every state.
 *   Because every stock change goes through StockService, the ledger always reconciles with the snapshots.
 * - AI examples cover a variant case, a low-confidence case, an unreadable photo, a conflicting barcode, a confirmed
 *   unbarcoded intake and a tenant-submitted catalog candidate. All are labelled mock results.
 *
 * Tenant A (Dela Cruz Hardware) intentionally does NOT stock the Makita HP1630 or the 500-pack Tek screw: those are the live demo intakes.
 * All demo accounts use the password "password". Never run this against production.
 */
class DemoSeeder extends Seeder
{
    private const HISTORY_DAYS = 30;

    /** price factor vs SRP, typical margin per top category is applied on top */
    private const TENANTS = [
        'dela-cruz-hardware' => ['name' => 'Dela Cruz Hardware', 'factor' => 1.00, 'plan' => 'multi_branch', 'type' => 'neighbourhood hardware', 'ttl' => 120],
        'mabini-builders' => ['name' => 'Mabini Builders Supply', 'factor' => 0.97, 'plan' => 'pro', 'type' => 'construction supply', 'ttl' => 1440],
        'visayas-tools' => ['name' => 'Visayas Tools Depot', 'factor' => 0.96, 'plan' => 'pro', 'type' => 'power tools specialist', 'ttl' => 240],
        'mactan-electrical' => ['name' => 'Mactan Electrical & Plumbing Center', 'factor' => 1.02, 'plan' => 'starter', 'type' => 'electrical and plumbing', 'ttl' => 120],
        'talisay-home' => ['name' => 'Talisay Home & Garden Hardware', 'factor' => 1.00, 'plan' => 'pro', 'type' => 'paint, garden and home', 'ttl' => 180],
        'kamuning-hardware' => ['name' => 'Kamuning Hardware', 'factor' => 1.05, 'plan' => 'pro', 'type' => 'neighbourhood hardware', 'ttl' => 120],
        'old-pier-hardware' => ['name' => 'Old Pier Hardware', 'factor' => 0.95, 'plan' => 'starter', 'type' => 'neighbourhood hardware', 'ttl' => 120],
    ];

    private const PLANS = [
        'starter' => ['label' => 'Starter (free)', 'price_cents' => 0, 'max_skus' => 300, 'ai_intakes_per_month' => 50, 'branches' => 1, 'marketplace' => false],
        'pro' => ['label' => 'Pro', 'price_cents' => 79900, 'max_skus' => null, 'ai_intakes_per_month' => 1000, 'branches' => 1, 'marketplace' => true],
        'multi_branch' => ['label' => 'Multi-branch', 'price_cents' => 199900, 'max_skus' => null, 'ai_intakes_per_month' => 1000, 'branches' => 3, 'marketplace' => true],
    ];

    private const MARGIN = [
        'construction-materials' => 0.10, 'fasteners' => 0.32, 'plumbing' => 0.30, 'electrical' => 0.25, 'power-tools' => 0.20, 'hand-tools' => 0.30,
        'paint-coatings' => 0.24, 'safety-equipment' => 0.35, 'measuring-tools' => 0.35, 'automotive' => 0.18, 'outdoor-garden' => 0.28,
        'building-supplies' => 0.28, 'accessories-parts' => 0.35,
    ];

    /** @var array<string, array<string, mixed>> slug => tenant, stores, locations, users, contexts, items, listings */
    private array $t = [];

    private int $sales = 0;

    private int $orders = 0;

    public function __construct(
        private InventoryService $inventory,
        private PosService $pos,
        private OrderService $orderService,
        private ReservationService $reservations,
        private CatalogMatcher $matcher,
        private AiIntakeService $intake,
        private StockService $stock,
        private SyncRecorder $sync,
    ) {}

    public function run(): void
    {
        mt_srand(20261022);
        $today = now();
        Carbon::setTestNow(self::manila($today->copy()->subDays(self::HISTORY_DAYS), 7, 0));

        try {
            $this->call(CatalogSeeder::class);
            $customers = $this->people();
            $this->tenants();
            $this->assortments();
            $this->history($customers, $today);
        } finally {
            Carbon::setTestNow();
        }

        $this->current($customers);
        $this->aiExamples($customers['ana']);
        $this->reservations->expireDue(); // past reservations nobody collected expire, like the scheduler would do

        // Suspension hides every listing of the tenant from the marketplace without touching its data.
        Tenant::query()->where('slug', 'old-pier-hardware')->update(['status' => 'suspended']);
    }

    // ------------------------------------------------------------------ people & tenants

    /** @return array<string, User> */
    private function people(): array
    {
        User::query()->forceCreate(['name' => 'Platform Admin', 'email' => 'admin@hardwhere.test', 'password' => bcrypt('password'), 'is_platform_admin' => true, 'email_verified_at' => now()]);

        // Personas from the business plan: homeowner, foreman/contractor, electrician, plus a Manila buyer.
        return [
            'ana' => $this->user('Ana Reyes', 'ana.customer@hardwhere.test', '+639171230001'),
            'ben' => $this->user('Ben Santos', 'ben.customer@hardwhere.test', '+639171230002'),
            'jun' => $this->user('Jun Ompad (foreman)', 'jun.foreman@hardwhere.test', '+639171230003'),
            'rico' => $this->user('Rico Villarin (electrician)', 'rico.electrician@hardwhere.test', '+639171230004'),
            'liza' => $this->user('Liza Gonzaga', 'liza.customer@hardwhere.test', '+639171230005'),
        ];
    }

    private function tenants(): void
    {
        $hours = ['mon-sat' => ['07:30', '18:00'], 'sun' => ['08:00', '12:00']];
        $yard = ['mon-sat' => ['07:00', '17:00'], 'sun' => null];

        // ---- A: Dela Cruz Hardware, two Cebu branches (Multi-branch plan). The hero tenant of the demo.
        $a = $this->tenant('dela-cruz-hardware');
        $mb = $this->store($a, 'Mabolo Branch', 'DCH-MB', '118 Juan Luna Ave', 'Mabolo', 'Cebu City', '6000', 10.3175, 123.9140, '+63322345001', true, $hours);
        $md = $this->store($a, 'Mandaue Branch', 'DCH-MD', '45 A. Soriano Ave', 'Centro', 'Mandaue City', '6014', 10.3365, 123.9420, '+63322345002', false, $hours);
        $this->member($a, 'Lito Dela Cruz', 'owner.a@hardwhere.test', TenantRole::Owner);
        $this->member($a, 'Maricel Abellana', 'manager.a@hardwhere.test', TenantRole::StoreManager, $mb);
        $this->member($a, 'Ino Inventory', 'inventory.a@hardwhere.test', TenantRole::InventoryManager);
        $this->member($a, 'Carlo Cashier', 'cashier.a@hardwhere.test', TenantRole::Cashier, $mb);
        $this->member($a, 'Joy Cashier', 'cashier2.a@hardwhere.test', TenantRole::Cashier, $md);
        $this->member($a, 'Mia Marketplace', 'market.a@hardwhere.test', TenantRole::MarketplaceManager);
        $this->member($a, 'Sam Staff', 'staff.a@hardwhere.test', TenantRole::Staff);
        // Former employee: membership revoked (soft-deleted). Must never get a token or a cache for tenant A again.
        $this->member($a, 'Former Staff', 'former.staff.a@hardwhere.test', TenantRole::Staff);
        TenantMembership::query()->where('tenant_id', $a->id)->whereHas('user', fn ($q) => $q->where('email', 'former.staff.a@hardwhere.test'))->update(['deleted_at' => now()]);
        $this->supplier($a, 'Cebu Tools & Fasteners Wholesale', 'Rey Tan', '+63322550101', 3);
        $this->supplier($a, 'Visayan Electrical Distributors', 'Nonoy Lim', '+63322550102', 2);

        // ---- B: Mabini Builders Supply, construction supply yard in Mandaue (delivers bulk).
        $b = $this->tenant('mabini-builders');
        $this->store($b, 'Subangdaku Yard', 'MBS-MD', 'M.C. Briones St', 'Subangdaku', 'Mandaue City', '6014', 10.3295, 123.9235, '+63323456001', true, $yard);
        $this->member($b, 'Berto Mabini', 'owner.b@hardwhere.test', TenantRole::Owner);
        $this->member($b, 'Cora Cashier', 'cashier.b@hardwhere.test', TenantRole::Cashier, $this->t['mabini-builders']['stores']['MBS-MD']);
        $this->member($b, 'Dodong Inventory', 'inventory.b@hardwhere.test', TenantRole::InventoryManager);
        $this->supplier($b, 'Mandaue Cement & Steel Trading', 'Manny Go', '+63323450201', 1);

        // ---- C: Visayas Tools Depot, power-tool specialist on Colon St.
        $c = $this->tenant('visayas-tools');
        $this->store($c, 'Colon Street', 'VTD-CB', '233 Colon St', 'Pari-an', 'Cebu City', '6000', 10.2965, 123.9005, '+63322551001', true, $hours);
        $this->member($c, 'Vicky Villamor', 'owner.c@hardwhere.test', TenantRole::Owner);
        $this->member($c, 'Pido Cashier', 'cashier.c@hardwhere.test', TenantRole::Cashier, $this->t['visayas-tools']['stores']['VTD-CB']);
        $this->supplier($c, 'Central Visayas Power Tools Distribution', 'Grace Uy', '+63322550301', 5);

        // ---- D: Mactan Electrical & Plumbing, Starter plan: POS + inventory only, no marketplace listing.
        $d = $this->tenant('mactan-electrical');
        $this->store($d, 'Pusok', 'MEP-LL', 'M.L. Quezon National Hwy', 'Pusok', 'Lapu-Lapu City', '6015', 10.3230, 123.9640, '+63323401001', false, $hours);
        $this->member($d, 'Dante Cabahug', 'owner.d@hardwhere.test', TenantRole::Owner);
        $this->supplier($d, 'Visayan Electrical Distributors', 'Nonoy Lim', '+63322550102', 2);

        // ---- E: Talisay Home & Garden.
        $e = $this->tenant('talisay-home');
        $this->store($e, 'Tabunok', 'THG-TL', 'Cebu South Rd', 'Tabunok', 'Talisay City', '6045', 10.2620, 123.8420, '+63322725001', false, $hours);
        $this->member($e, 'Elena Pepito', 'owner.e@hardwhere.test', TenantRole::Owner);
        $this->member($e, 'Tess Cashier', 'cashier.e@hardwhere.test', TenantRole::Cashier, $this->t['talisay-home']['stores']['THG-TL']);
        $this->supplier($e, 'Cebu Paint Center Wholesale', 'Ruel Sy', '+63322550501', 2);

        // ---- F: Kamuning Hardware, Metro Manila (roadmap: month-12 expansion). Far from Cebu buyers: sorts last by distance.
        $f = $this->tenant('kamuning-hardware');
        $this->store($f, 'Kamuning', 'KMH-QC', '45 K-1st St', 'Kamuning', 'Quezon City', '1103', 14.6285, 121.0366, '+6328123001', true, $hours);
        $this->member($f, 'Fely Ramos', 'owner.f@hardwhere.test', TenantRole::Owner);
        $this->supplier($f, 'Metro Manila Tools Wholesale', 'Rey Cruz', '+6328999001', 3);

        // ---- G: Old Pier Hardware, will be suspended: its published listings must never reach customers.
        $g = $this->tenant('old-pier-hardware');
        $this->store($g, 'Pier 3', 'OPH-CB', 'Sergio Osmeña Blvd', 'Tinago', 'Cebu City', '6000', 10.2930, 123.9050, '+63322560001', false, $hours);
        $this->member($g, 'Gorio Pier', 'owner.g@hardwhere.test', TenantRole::Owner);

        // One person, two employers: account switching must wipe the other tenant's cache.
        $this->member($a, 'Bea Bookkeeper', 'bookkeeper@hardwhere.test', TenantRole::Staff);
        $this->member($e, 'Bea Bookkeeper', 'bookkeeper@hardwhere.test', TenantRole::Staff);
    }

    // ------------------------------------------------------------------ assortment, prices, opening stock

    private function assortments(): void
    {
        $lists = [
            'dela-cruz-hardware' => ['DCH-MB', fn (string $r) => 'DCH-'.$r, [
                'GSB13', 'GWS060', 'GA4030', 'MK9556HN', 'ING-AG750', 'ING-ID650', 'DCD771-KIT', 'BCD001-12V', 'BCD001-20V',
                'STH16', 'TL25101', 'SLEDGE4', 'TLPL6', 'TLPL8', 'CUTTER18', 'HACKSAW12', 'SDSET6', 'ADJW10', 'TROWEL7', 'PLTROWEL', 'RUBPAIL',
                'TEK100', 'NAIL1', 'NAIL2', 'NAIL3', 'NAIL4', 'CNAIL2', 'UMBNAIL', 'GYPS100', 'WOODS1', 'BOLTM8', 'BOLTM10', 'BOLTM10-PC', 'EXPB10', 'PLUG6', 'TIEW-KG', 'RIVET18',
                'PVC12', 'PVC34', 'SAN2', 'SAN4', 'ELB12', 'ELB34', 'TEE12', 'CPL12', 'SELB4', 'GATE12', 'BALLV12', 'FAUCET12', 'TEFLON', 'SOLV100',
                'THHN20', 'THHN35', 'THHN20-M', 'PDX18', 'BRK15', 'BRK20', 'BRK30', 'BRK40', 'SW1G', 'SW2G', 'OUTLET2', 'LED9WW', 'LED9WD', 'FF-LED9', 'FF-LED12', 'COND12', 'UBOX24', 'JBOX44', 'EXT3M',
                'CEMENT', 'CHB4', 'CHB5', 'SAND-SACK', 'COCO22', 'COCO23', 'PLY14', 'PLY12', 'GI26-8', 'GI26-10',
                'BOY1L', 'BOY4L', 'QDE-W1L', 'QDE-B1L', 'THIN1L', 'VULC025', 'VULC1L', 'BRUSH2', 'BRUSH3', 'ROLLER7',
                'HHAT-Y', 'GLOVE', 'CGLOVE-PR', 'GOGGLES', 'N95-PC', 'TAPE3', 'TAPE5', 'TAPE8', 'LEVEL24', 'CHALK', 'MOTUL1', 'SPRINT1', 'HOSE15', 'SHOVEL-P',
                'TILEADH', 'EPOXY-Q', 'RUGBY100', 'SILI-CL', 'HINGE3', 'PADLOCK40', 'PADLOCK50', 'BBOLT4', 'GROUT-WH', 'CDISC4', 'CDISC4-BOX', 'DIAM4', 'BITSET5', 'MBIT6', 'MBIT8', 'MBIT10', 'CB440',
            ]],
            'mabini-builders' => ['MBS-MD', null, [
                'HP1630', 'HP1630K', 'GSB13', 'HR2470', 'GCO220', 'WELD160', 'WELD200', 'STH16', 'SLEDGE8', 'BOLTCUT24', 'TROWEL7', 'PLTROWEL', 'RUBPAIL',
                'TEK100', 'TEK500', 'NAIL2', 'NAIL3', 'NAIL4', 'NAIL3-BOX', 'CNAIL1', 'CNAIL2', 'UMBNAIL', 'BOLTM12', 'EXPB12', 'TIEW-KG', 'TIEW-ROLL', 'GYPS1000',
                'SAN3', 'SAN4', 'CEMENT', 'APO40', 'REBAR10', 'REBAR10-G33', 'REBAR12', 'REBAR16', 'ANGLE112', 'CHB4', 'CHB5', 'CHB6', 'SAND-CUM', 'SAND-SACK', 'GRAVEL-CUM',
                'PLY14', 'PLY12', 'PLY34', 'COCO22', 'COCO23', 'COCO24', 'GI26-8', 'GI26-10', 'GI26-12', 'GI24-10', 'BOY16L', 'TILEADH', 'GYPSUM12', 'FCB45', 'FURRING',
                'BRK30', 'CDISC4-BOX', 'WBARROW', 'SHOVEL-P', 'SHOVEL-S', 'HHAT-Y', 'HHAT-W', 'SHOE41', 'SHOE42', 'CGLOVE-DZ',
            ]],
            'visayas-tools' => ['VTD-CB', fn (string $r) => 'VTD-'.$r, [
                'HP1630', 'DCD771-BARE', 'DCD771-KIT', 'BCD001-20V', 'MK4329', 'MK5806B', 'GCO220', 'BO3710', 'GWS060', 'GA4030', 'MK9556HN', 'HR2470', 'WELD200',
                'STH20', 'STH16', 'TLPL8', 'SOCK38', 'SOCK12', 'ADJW8', 'ADJW12', 'SDSET6', 'HHAT-W', 'GOGGLES', 'WMASK', 'SHOE40', 'SHOE42', 'SHOE43',
                'DCB203', 'CB440', 'BLADE24T', 'BLADE40T', 'T101B', 'DIAM4', 'CDISC4', 'BITSET5', 'LTAPE30', 'TAPE8', 'LEVEL24',
            ]],
            'mactan-electrical' => ['MEP-LL', null, [
                'THHN20', 'THHN35', 'THHN55', 'THHN20-M', 'PDX18', 'BRK15', 'BRK20', 'BRK30', 'BRK40', 'BRK60', 'PANEL4', 'PANEL8', 'SW1G', 'SW2G', 'SW3G', 'OUTLET2',
                'LED9WD', 'FF-LED5', 'FF-LED9', 'FF-LED12', 'FF-T8', 'COND12', 'COND34', 'UBOX24', 'JBOX44', 'EXT3M', 'EXT5M',
                'PVC12', 'PVC34', 'PVC1', 'PPR20', 'PPR25', 'ELB12', 'ELB34', 'TEE12', 'TEE34', 'CPL12', 'GIELB12', 'GATE12', 'BALLV12', 'BALLV34', 'FAUCET12', 'FLOAT12',
                'TEFLON', 'SOLV100', 'SOLV400', 'SAN2', 'SAN3',
            ]],
            'talisay-home' => ['THG-TL', fn (string $r) => 'THG-'.strtolower($r), [
                'BOY1L', 'BOY4L', 'BOY16L', 'QDE-W1L', 'QDE-B1L', 'QDE-W4L', 'REDOX1L', 'THIN1L', 'THIN4L', 'VULC025', 'VULC1L', 'BRUSH2', 'BRUSH3', 'BRUSH4', 'ROLLER7',
                'HOSE15', 'HOSE30', 'SPRAY16', 'SHOVEL-P', 'SHOVEL-S', 'BOLO', 'WBARROW', 'BCUT52', 'HHAT-Y', 'GLOVE', 'CGLOVE-PR', 'CGLOVE-DZ', 'GOGGLES', 'N95-PC', 'N95-BOX',
                'SHOE41', 'SHOE42', 'PADLOCK40', 'PADLOCK50', 'KNOB', 'BBOLT4', 'SILI-CL', 'SILI-WH', 'RUGBY100', 'RUGBY1L', 'EPOXY-Q', 'GROUT-WH', 'GROUT-GR', 'TILEADH',
                'LED9WW', 'FF-LED9', 'EXT5M', 'MOTUL08', 'MOTUL1', 'SPRINT1', 'MOTO-NS40', 'MOTO-NS60',
            ]],
            'kamuning-hardware' => ['KMH-QC', null, ['HP1630', 'GSB13', 'GWS060', 'STH16', 'TEK100', 'CEMENT', 'BRK20', 'THHN20', 'BOY4L', 'TAPE5', 'LED9WW', 'PVC12']],
            'old-pier-hardware' => ['OPH-CB', fn (string $r) => 'OPH-'.$r, ['GWS060', 'GA4030', 'STH16', 'CEMENT', 'BRK20']],
        ];
        $numbered = ['mabini-builders' => 'MBS-%04d', 'mactan-electrical' => 'MEP%05d', 'kamuning-hardware' => 'KMH-%04d'];

        // Demo-critical quantities: out of stock, below reorder point, the last unit of a power tool.
        $overrides = [
            'dela-cruz-hardware' => ['BCD001-20V' => 0, 'BOLTM10' => 3, 'GA4030' => 2, 'MK9556HN' => 1, 'DCD771-KIT' => 4, 'THHN35' => 2, 'DIAM4' => 0],
            'mabini-builders' => ['HP1630' => 5, 'HP1630K' => 2, 'GCO220' => 1],
            'visayas-tools' => ['HP1630' => 1, 'MK9556HN' => 0],
            'talisay-home' => ['MOTO-NS60' => 0, 'BCUT52' => 1],
        ];

        foreach ($lists as $slug => [$code, $skuFn, $refs]) {
            $w = $this->t[$slug];
            $store = $w['stores'][$code];
            foreach ($refs as $i => $ref) {
                $sku = $skuFn ? $skuFn($ref) : sprintf($numbered[$slug], $i + 1);
                $price = $this->price($slug, $ref);
                $qty = $overrides[$slug][$ref] ?? $this->quantity($slug, $ref);
                $item = $this->inventory->createItem($w['owner'], CatalogSeeder::$refs[$ref], [
                    'tenant_sku' => $sku, 'cost_cents' => $this->cost($slug, $ref, $price), 'price_cents' => $price, 'quantity' => $qty,
                    'reorder_threshold' => $this->reorder($ref), 'store_id' => $store->id, 'supplier_id' => $this->supplierFor($slug, $ref), 'source' => 'manual',
                ]);
                $this->t[$slug]['items'][$ref] = $item;
                $listing = TenantListing::query()->where('inventory_item_id', $item->id)->firstOrFail();
                $this->t[$slug]['listings'][$code][$ref] = $this->publish($slug, $store, $listing, $ref, $qty);
            }
        }

        // Tenant A's Mandaue branch sells part of the same inventory items from its own location and listing.
        $a = $this->t['dela-cruz-hardware'];
        $md = $a['stores']['DCH-MD'];
        foreach (['TEK100', 'STH16', 'NAIL3', 'CEMENT', 'CHB4', 'PVC12', 'ELB12', 'THHN20', 'BRK20', 'BRK30', 'LED9WW', 'BOY4L', 'VULC1L', 'CDISC4', 'GWS060', 'GA4030', 'HHAT-Y', 'TAPE5', 'TIEW-KG', 'TEFLON', 'PLY12', 'COCO22', 'GLOVE'] as $ref) {
            $item = $a['items'][$ref];
            $variant = ProductVariant::findOrFail($item->variant_id);
            $listing = TenantListing::create(['tenant_id' => $a['tenant']->id, 'store_id' => $md->id, 'inventory_item_id' => $item->id, 'product_id' => $variant->product_id,
                'variant_id' => $variant->id, 'tenant_sku' => $item->tenant_sku, 'price_cents' => $this->round((int) ($this->price('dela-cruz-hardware', $ref) * 1.02)), 'uom' => $variant->uom,
                'visibility' => 'hidden', 'publication_status' => 'draft']);
            $qty = $ref === 'GA4030' ? 1 : max(1, intdiv($this->quantity('dela-cruz-hardware', $ref), 3));
            DB::transaction(fn () => $this->stock->adjust($a['tenant']->id, $a['owner']->userId, $item->id, $a['locations']['DCH-MD'], 'opening_balance', $qty, 'Opening balance (Mandaue branch)', 'inventory_item', $item->id));
            $this->sync->listing($a['tenant']->id, $listing->id);
            $this->t['dela-cruz-hardware']['listings']['DCH-MD'][$ref] = $this->publish('dela-cruz-hardware', $md, $listing, $ref, $qty);
        }

        // Tenant-specific alias and shelf photo (visible to tenant A only).
        $gsb = ProductVariant::findOrFail(CatalogSeeder::$refs['GSB13']);
        ProductAlias::create(['variant_id' => $gsb->id, 'product_id' => $gsb->product_id, 'tenant_id' => $a['tenant']->id, 'type' => 'name', 'alias' => 'Bosch pang-konkreto', 'normalized' => Text::normalize('Bosch pang-konkreto')]);
        ProductAlias::create(['variant_id' => CatalogSeeder::$refs['CHB4'], 'product_id' => ProductVariant::findOrFail(CatalogSeeder::$refs['CHB4'])->product_id, 'tenant_id' => $a['tenant']->id, 'type' => 'name', 'alias' => 'bloke kwatro', 'normalized' => Text::normalize('bloke kwatro')]);
        ProductImage::create(['product_id' => ProductVariant::findOrFail(CatalogSeeder::$refs['STH16'])->product_id, 'tenant_id' => $a['tenant']->id, 'path' => '/demo-images/hand-tools.svg', 'alt' => 'Shelf photo (tenant A)', 'source' => 'tenant_upload']);
    }

    /** Listing rules that show off visibility controls; Starter-plan tenants keep everything off the marketplace. */
    private function publish(string $slug, Store $store, TenantListing $listing, string $ref, int $qty): TenantListing
    {
        $ctx = $this->t[$slug]['owner'];
        if (! self::PLANS[self::TENANTS[$slug]['plan']]['marketplace'] && $slug !== 'old-pier-hardware') {
            return $listing;
        }
        if ($slug === 'dela-cruz-hardware' && in_array($ref, ['RUGBY100', 'SILI-CL'], true)) {
            return $listing; // still a draft: being priced
        }

        $d = ['publish' => true, 'pickup_available' => true, 'delivery_available' => $store->supports_delivery, 'lead_time_hours' => $qty === 0 ? 48 : 0];
        $uom = ProductVariant::findOrFail(CatalogSeeder::$refs[$ref])->uom;
        if ($uom === 'cu.m') {
            $d = ['pickup_available' => false, 'lead_time_hours' => 24] + $d; // delivered by truck only
        }
        if (in_array($ref, ['CHB4', 'CHB5', 'CHB6'], true) && $slug === 'mabini-builders') {
            $d += ['min_order_qty' => 50, 'show_exact_stock' => true];
        }
        if ($slug === 'mabini-builders' && in_array(CatalogSeeder::$top[$ref], ['construction-materials'], true)) {
            $d += ['show_exact_stock' => true];
        }
        if ($ref === 'CEMENT' && $slug === 'dela-cruz-hardware') {
            $d += ['stock_buffer' => 10, 'max_order_qty' => 20]; // keeps bags for walk-in contractors
        }
        if (in_array($slug, ['dela-cruz-hardware', 'talisay-home'], true) && crc32($slug.$ref.'promo') % 12 === 0) {
            $d['sale_price_cents'] = $this->round((int) ($listing->price_cents * 0.9));
        }

        return $this->inventory->updateListing($ctx, $listing->id, $d);
    }

    private function price(string $slug, string $ref): int
    {
        $jitter = (crc32($slug.$ref) % 900) / 10000 - 0.045; // +-4.5% per tenant and product

        return $this->round((int) (CatalogSeeder::$srp[$ref] * self::TENANTS[$slug]['factor'] * (1 + $jitter)));
    }

    private function cost(string $slug, string $ref, int $price): int
    {
        $margin = self::MARGIN[CatalogSeeder::$top[$ref]] + (crc32($slug.$ref.'c') % 400) / 10000 - 0.02;

        return (int) (round($price * (1 - $margin) / 25) * 25);
    }

    /** Shelf-price rounding in centavos: P10 steps over P1,000, P5 over P100, 50c over P20, else 25c. */
    private function round(int $cents): int
    {
        $step = match (true) {
            $cents >= 100000 => 1000,
            $cents >= 10000 => 500,
            $cents >= 2000 => 50,
            default => 25,
        };

        return max($step, (int) (round($cents / $step) * $step));
    }

    /** @return array{int, int} typical opening quantity range for this kind of item */
    private function range(string $ref): array
    {
        $v = ProductVariant::findOrFail(CatalogSeeder::$refs[$ref]);
        $srp = CatalogSeeder::$srp[$ref];
        $bulk = CatalogSeeder::$top[$ref] === 'construction-materials';

        return match (true) {
            $v->uom === 'cu.m' => [6, 20],
            $bulk && $srp < 3000 => [300, 1200],
            $bulk => [30, 200],
            in_array($v->uom, ['kg', 'm'], true) => [40, 200],
            $srp >= 300000 => [1, 6],
            $srp >= 100000 => [2, 10],
            $srp < 5000 => [40, 250],
            default => [6, 40],
        };
    }

    private function quantity(string $slug, string $ref): int
    {
        [$lo, $hi] = $this->range($ref);
        $qty = $lo + crc32($slug.$ref.'q') % ($hi - $lo + 1);

        return $slug === 'mabini-builders' && CatalogSeeder::$top[$ref] === 'construction-materials' ? $qty * 2 : $qty;
    }

    private function reorder(string $ref): int
    {
        [$lo, $hi] = $this->range($ref);

        return max(1, intdiv($lo + $hi, 6));
    }

    private function supplierFor(string $slug, string $ref): ?int
    {
        $suppliers = $this->t[$slug]['suppliers'] ?? [];
        if (! $suppliers) {
            return null;
        }

        return CatalogSeeder::$top[$ref] === 'electrical' && count($suppliers) > 1 ? $suppliers[1] : $suppliers[0];
    }

    // ------------------------------------------------------------------ 30 days of history

    /** @param  array<string, User>  $customers */
    private function history(array $customers, CarbonInterface $today): void
    {
        $plan = [ // store code => [tenant slug, cashier email, sales per day min..max]
            'DCH-MB' => ['dela-cruz-hardware', 'cashier.a@hardwhere.test', 4, 9],
            'DCH-MD' => ['dela-cruz-hardware', 'cashier2.a@hardwhere.test', 1, 4],
            'MBS-MD' => ['mabini-builders', 'cashier.b@hardwhere.test', 3, 6],
            'VTD-CB' => ['visayas-tools', 'cashier.c@hardwhere.test', 1, 3],
            'MEP-LL' => ['mactan-electrical', 'owner.d@hardwhere.test', 2, 5],
            'THG-TL' => ['talisay-home', 'cashier.e@hardwhere.test', 1, 3],
            'KMH-QC' => ['kamuning-hardware', 'owner.f@hardwhere.test', 1, 2],
        ];

        // Each store has fast movers and dead stock: only ~70% of the assortment ever sells.
        $movers = [];
        foreach ($plan as $code => [$slug]) {
            $refs = array_keys($this->t[$slug]['listings'][$code] ?? $this->t[$slug]['items']);
            usort($refs, fn ($x, $y) => crc32($code.$x) <=> crc32($code.$y));
            $movers[$code] = array_slice($refs, 0, (int) ceil(count($refs) * 0.7));
        }

        for ($daysAgo = self::HISTORY_DAYS - 1; $daysAgo >= 1; $daysAgo--) {
            $day = $today->copy()->subDays($daysAgo);

            foreach ($plan as $code => [$slug, $email, $min, $max]) {
                $w = $this->t[$slug];
                $store = $w['stores'][$code];
                $register = $w['registers'][$code];
                Carbon::setTestNow(self::manila($day, 7, 45));
                $session = PosSession::create(['tenant_id' => $w['tenant']->id, 'register_id' => $register->id, 'opened_by' => $w['users'][$email]->id, 'opened_at' => now(), 'opening_float_cents' => 200000]);
                $cash = 0;

                $count = $day->copy()->setTimezone('Asia/Manila')->isSunday() ? max(0, $min - 1) : mt_rand($min, $max);
                $minutes = array_map(fn () => mt_rand(0, 9 * 60 + 30), range(1, max(1, $count))); // 08:00-17:30, in order
                sort($minutes);
                for ($n = 0; $n < $count; $n++) {
                    Carbon::setTestNow(self::manila($day, 8 + intdiv($minutes[$n], 60), $minutes[$n] % 60));
                    $sale = $this->sell($slug, $code, $email, $movers[$code], $session->id);
                    $sale && $sale->payment_method === 'cash' && $cash += $sale->total_cents;
                }

                Carbon::setTestNow(self::manila($day, 18, 5));
                $session->update(['closed_at' => now(), 'closing_cash_cents' => 200000 + $cash]);
            }

            Carbon::setTestNow(self::manila($day, 15, 0));
            if ($daysAgo % 7 === 3) {
                foreach (['dela-cruz-hardware', 'mabini-builders', 'visayas-tools', 'mactan-electrical', 'talisay-home', 'kamuning-hardware'] as $slug) {
                    $this->restock($slug, $daysAgo);
                }
            }
            $this->incidents($daysAgo);
            $this->pastOrders($daysAgo, $customers);
        }
    }

    /** @param  list<string>  $movers */
    private function sell(string $slug, string $code, string $email, array $movers, int $sessionId): ?Sale
    {
        $w = $this->t[$slug];
        $listings = $w['listings'][$code] ?? null;
        $isManager = $email === 'manager.a@hardwhere.test';
        $ctx = $this->ctx($slug, $email);

        $lines = [];
        foreach ((array) array_rand(array_flip($movers), mt_rand(1, min(4, count($movers)))) as $ref) {
            $listing = $listings[$ref] ?? TenantListing::query()->where('inventory_item_id', $w['items'][$ref]->id)->where('store_id', $w['stores'][$code]->id)->first();
            if (! $listing) {
                continue;
            }
            $v = ProductVariant::findOrFail($listing->variant_id);
            $srp = CatalogSeeder::$srp[$ref];
            $qty = match (true) {
                $srp < 3000 && CatalogSeeder::$top[$ref] === 'construction-materials' => mt_rand(20, 150),
                $v->uom === 'bag' => $slug === 'mabini-builders' ? mt_rand(5, 40) : mt_rand(1, 10),
                in_array($v->uom, ['kg', 'm'], true) => mt_rand(1, 10),
                $v->uom === 'cu.m' => mt_rand(1, 3),
                $srp < 5000 => mt_rand(1, 12),
                default => mt_rand(1, 100) > 85 ? 2 : 1,
            };
            $lines[] = ['listing_id' => $listing->id, 'quantity' => $qty];
        }
        if (! $lines) {
            return null;
        }

        // Suki discount, only possible for roles with pos.discount (store manager).
        if ($isManager) {
            $first = TenantListing::query()->findOrFail($lines[0]['listing_id']);
            $lines[0]['discount_cents'] = (int) (($first->sale_price_cents ?? $first->price_cents) * $lines[0]['quantity'] * 0.05);
        }

        $roll = mt_rand(1, 100);
        $method = match (true) {
            $roll <= 62 => 'cash', $roll <= 87 => 'gcash', $roll <= 95 => 'maya', default => 'bank_transfer'
        };
        $payment = ['method' => $method];
        if ($method === 'cash') {
            $payment['tendered_cents'] = 100000000; // replaced below once the total is known
        } else {
            $payment['reference'] = strtoupper(substr($method, 0, 2)).sprintf('%010d', mt_rand(1, 2147483646));
        }

        $data = ['store_id' => $w['stores'][$code]->id, 'pos_session_id' => $sessionId, 'items' => $lines, 'payment' => $payment];
        if (mt_rand(1, 100) <= 20) {
            $data['customer'] = ['name' => ['Jun Ompad (foreman)', 'Engr. Pacaña', 'Walk-in contractor', 'Rico Villarin'][mt_rand(0, 3)], 'phone' => '+6391712'.sprintf('%05d', mt_rand(0, 99999))];
        }

        // Price the cart first so the cash tendered looks like real money (next P100/P500/P1000 note).
        $total = collect($lines)->sum(function ($l) {
            $listing = TenantListing::query()->findOrFail($l['listing_id']);

            return ($listing->sale_price_cents ?? $listing->price_cents) * $l['quantity'] - ($l['discount_cents'] ?? 0);
        });
        if ($method === 'cash') {
            $step = $total > 500000 ? 100000 : ($total > 50000 ? 50000 : 10000);
            $data['payment']['tendered_cents'] = (int) (ceil($total / $step) * $step);
        }

        try {
            [$sale] = $this->pos->checkout($ctx, $data, sprintf('seed-sale-%05d', ++$this->sales));

            return $sale;
        } catch (ApiException $e) {
            if ($e->errorCode !== 'stock_unavailable') {
                throw $e;
            }

            return null; // ran out: exactly what a cashier would hit at the counter
        }
    }

    /** Weekly purchase receipt for everything at or below its reorder point. */
    private function restock(string $slug, int $daysAgo): void
    {
        $w = $this->t[$slug];
        $code = array_key_first($w['stores']);
        $location = $w['locations'][$code];
        $ctx = $w['owner'];

        $items = [];
        foreach ($w['items'] as $ref => $item) {
            if ($slug === 'dela-cruz-hardware' && in_array($ref, ['BCD001-20V', 'BOLTM10', 'THHN35', 'DIAM4'], true)) {
                continue; // stays out of stock / low for the demo
            }
            $onHand = (int) StockSnapshot::query()->where('inventory_item_id', $item->id)->where('location_id', $location)->value('on_hand');
            if ($onHand <= $item->reorder_threshold && $item->reorder_threshold > 0) {
                $items[] = ['inventory_item_id' => $item->id, 'quantity' => $item->reorder_threshold * 3, 'unit_cost_cents' => $item->cost_cents];
            }
        }
        if ($items) {
            $this->inventory->receivePurchase($ctx, ['location_id' => $location, 'supplier_id' => $w['suppliers'][0] ?? null,
                'reference' => sprintf('PO-%s-%02d', strtoupper(substr($slug, 0, 3)), self::HISTORY_DAYS - $daysAgo), 'items' => $items]);
        }
    }

    private function incidents(int $daysAgo): void
    {
        $a = $this->t['dela-cruz-hardware'];
        $mb = $a['locations']['DCH-MB'];
        $owner = $a['owner'];
        $inv = $this->ctx('dela-cruz-hardware', 'inventory.a@hardwhere.test');

        match ($daysAgo) {
            22 => $this->inventory->adjust($inv, $a['items']['PVC12']->id, $mb, 'damage', -3, 'Cracked in storage'),
            15 => DB::transaction(function () use ($a, $owner, $mb) {
                // Transfer 10 bags of cement Mabolo -> Mandaue: two ledger rows, one per location.
                $this->stock->adjust($a['tenant']->id, $owner->userId, $a['items']['CEMENT']->id, $mb, 'transfer_out', -10, 'To Mandaue branch', 'transfer', 1);
                $this->stock->adjust($a['tenant']->id, $owner->userId, $a['items']['CEMENT']->id, $a['locations']['DCH-MD'], 'transfer_in', 10, 'From Mabolo branch', 'transfer', 1);
            }),
            12 => $this->inventory->adjust($inv, $a['items']['TEK100']->id, $mb, 'loss', -1, 'Missing during cycle count'),
            8 => $this->inventory->adjust($this->t['mabini-builders']['owner'], $this->t['mabini-builders']['items']['CEMENT']->id, $this->t['mabini-builders']['locations']['MBS-MD'], 'damage', -2, 'Bags torn, got wet in the rain'),
            5 => $this->inventory->adjust($inv, $a['items']['NAIL3']->id, $mb, 'correction', 2, 'Recount: scale was off'),
            4 => $this->inventory->adjust($this->t['talisay-home']['owner'], $this->t['talisay-home']['items']['BOY4L']->id, $this->t['talisay-home']['locations']['THG-TL'], 'damage', -1, 'Dented can, not sellable'),
            3 => $this->voidLatest(),
            default => null,
        };
    }

    private function voidLatest(): void
    {
        $sale = Sale::query()->where('store_id', $this->t['dela-cruz-hardware']['stores']['DCH-MB']->id)->where('status', 'completed')->latest('id')->first();
        $sale && $this->pos->void($this->ctx('dela-cruz-hardware', 'manager.a@hardwhere.test'), $sale->id, 'Customer changed mind');
    }

    /** @param  array<string, User>  $c */
    private function pastOrders(int $daysAgo, array $c): void
    {
        $flow = [OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::ReadyForPickup, OrderStatus::Fulfilled];
        match ($daysAgo) {
            25 => $this->order($c['ana'], 'dela-cruz-hardware', 'DCH-MB', ['TEK100' => 3, 'TAPE5' => 1], 'pickup', $flow),
            18 => $this->order($c['jun'], 'mabini-builders', 'MBS-MD', ['CEMENT' => 20, 'CHB4' => 100, 'SAND-CUM' => 2], 'delivery', $flow, 'Lot 12, Brgy. Tipolo, Mandaue City (site office, look for Engr. Pacaña)'),
            14 => $this->order($c['ana'], 'dela-cruz-hardware', 'DCH-MB', ['HHAT-Y' => 1], 'pickup', [], cancelBy: 'customer'),
            11 => $this->order($c['liza'], 'talisay-home', 'THG-TL', ['BOY4L' => 2, 'ROLLER7' => 1], 'pickup', $flow),
            9 => $this->order($c['ben'], 'kamuning-hardware', 'KMH-QC', ['STH16' => 1], 'pickup', []), // never collected: expires
            6 => $this->order($c['rico'], 'dela-cruz-hardware', 'DCH-MB', ['BRK20' => 2, 'THHN20' => 1], 'pickup', [OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::ReadyForPickup], cancelBy: 'vendor'),
            2 => $this->order($c['jun'], 'visayas-tools', 'VTD-CB', ['GA4030' => 1, 'CDISC4' => 10], 'pickup', $flow),
            default => null,
        };
    }

    // ------------------------------------------------------------------ "right now" state for the live demo

    /** @param  array<string, User>  $c */
    private function current(array $c): void
    {
        $this->order($c['ana'], 'dela-cruz-hardware', 'DCH-MB', ['TAPE5' => 1, 'LED9WD' => 4], 'pickup', []); // reserved, holding stock
        $this->order($c['rico'], 'dela-cruz-hardware', 'DCH-MB', ['BRK30' => 1, 'SW2G' => 2], 'pickup', [OrderStatus::Confirmed]);
        $this->order($c['jun'], 'mabini-builders', 'MBS-MD', ['REBAR10' => 30, 'TIEW-KG' => 5], 'delivery', [OrderStatus::Confirmed, OrderStatus::Preparing], 'Phase 2 site, Brgy. Tipolo, Mandaue City');
        $this->order($c['liza'], 'talisay-home', 'THG-TL', ['VULC1L' => 1, 'BRUSH3' => 2], 'pickup', [OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::ReadyForPickup]);
        $this->order($c['ben'], 'kamuning-hardware', 'KMH-QC', ['GSB13' => 1], 'pickup', []);

        // A reservation whose hold ran out a few minutes ago, released by expireDue() at the end of run().
        $late = $this->order($c['liza'], 'dela-cruz-hardware', 'DCH-MB', ['PADLOCK40' => 1], 'pickup', []);
        $late && Reservation::query()->where('order_id', $late)->update(['expires_at' => now()->subMinutes(5)]);

        // Today's open register at Mabolo, with two sales already rung up.
        $a = $this->t['dela-cruz-hardware'];
        $session = PosSession::create(['tenant_id' => $a['tenant']->id, 'register_id' => $a['registers']['DCH-MB']->id, 'opened_by' => $a['users']['cashier.a@hardwhere.test']->id,
            'opened_at' => now()->subHours(2), 'opening_float_cents' => 200000]);
        $movers = ['NAIL3', 'TEK100', 'CEMENT', 'LED9WW', 'PVC12', 'ELB12', 'TEFLON'];
        $this->sell('dela-cruz-hardware', 'DCH-MB', 'cashier.a@hardwhere.test', $movers, $session->id);
        $this->sell('dela-cruz-hardware', 'DCH-MB', 'manager.a@hardwhere.test', $movers, $session->id);

        // Ana's cart is a draft: it holds no stock.
        $cart = MarketplaceCart::create(['user_id' => $c['ana']->id]);
        foreach (['TEK100' => 1, 'TEFLON' => 2] as $ref => $qty) {
            $cart->items()->create(['listing_id' => $a['listings']['DCH-MB'][$ref]->id, 'quantity' => $qty]);
        }
    }

    /**
     * Place a marketplace order through the real service and walk it through vendor transitions.
     *
     * @param  array<string, int>  $lines  ref => quantity
     * @param  list<OrderStatus>  $steps
     */
    private function order(User $customer, string $slug, string $code, array $lines, string $type, array $steps, ?string $address = null, ?string $cancelBy = null): ?int
    {
        $w = $this->t[$slug];
        $items = [];
        foreach ($lines as $ref => $qty) {
            $items[] = ['listing_id' => $w['listings'][$code][$ref]->id, 'quantity' => $qty];
        }
        [$order] = $this->orderService->place($customer, ['items' => $items, 'fulfillment_type' => $type, 'contact_name' => $customer->name, 'contact_phone' => $customer->phone,
            'delivery_address' => $address], sprintf('seed-order-%04d', ++$this->orders));

        $vendor = $w['owner']->userId;
        foreach ($steps as $to) {
            Carbon::hasTestNow() && Carbon::setTestNow(now()->addMinutes(mt_rand(5, 25))); // only while replaying history
            $this->orderService->vendorTransition($order->id, $to, null, $vendor);
        }
        if ($cancelBy === 'customer') {
            $this->orderService->customerCancel($customer, $order->id, 'Found it elsewhere');
        } elseif ($cancelBy === 'vendor') {
            $this->orderService->vendorTransition($order->id, OrderStatus::Cancelled, 'Not picked up after 2 reminders', $vendor);
        }

        return $order->id;
    }

    // ------------------------------------------------------------------ AI examples (labelled mock results)

    private function aiExamples(User $ana): void
    {
        $a = $this->t['dela-cruz-hardware'];
        $c = $this->t['visayas-tools'];
        $staffA = $this->ctx('dela-cruz-hardware', 'staff.a@hardwhere.test');

        // Waiting for review in tenant A's intake queue.
        $this->analysis('makita-hp1630k', $a['tenant']->id, $a['users']['staff.a@hardwhere.test'], 'intake', 'Variant of an existing model');
        $this->analysis('ambiguous-cordless-drill', $a['tenant']->id, $a['users']['staff.a@hardwhere.test'], 'intake', 'Low-confidence brand and model');

        // Unbarcoded fasteners: staff picked the 500-pack variant, not the 100-pack the photo also resembled.
        $tek = $this->analysis('unbarcoded-tek-screw', $a['tenant']->id, $a['users']['staff.a@hardwhere.test'], 'intake');
        $this->intake->confirm($staffA, $tek->id, ['decision' => 'selected_variant', 'variant_id' => CatalogSeeder::$refs['TEK500'], 'inventory' => [
            'tenant_sku' => 'DCH-TEK500', 'cost_cents' => 60000, 'price_cents' => 89000, 'quantity' => 6, 'reorder_threshold' => 3, 'store_id' => $a['stores']['DCH-MB']->id,
        ]], 'seed-ai-confirm-1');

        // Unreadable photo: rejected, nothing stocked.
        $blurry = $this->analysis('blurry', $a['tenant']->id, $a['users']['staff.a@hardwhere.test'], 'intake');
        $this->intake->confirm($staffA, $blurry->id, ['decision' => 'rejected'], 'seed-ai-confirm-2');

        // Not in the catalog: tenant C submits a candidate product (visible only to C until a platform admin approves it).
        $foam = $this->analysis('unknown-product', $c['tenant']->id, $c['users']['owner.c@hardwhere.test'], 'intake');
        $this->intake->confirm($c['owner'], $foam->id, ['decision' => 'created_candidate', 'new_product' => [
            'category_id' => Category::query()->where('slug', 'adhesives-sealants')->value('id'), 'brand' => 'Kingfoam', 'name' => 'Expanding Foam Sealant 750ml', 'model_number' => 'KF-750',
            'product_type' => 'PU foam', 'synonyms' => ['pu foam', 'expanding foam'], 'variants' => [['name' => 'Kingfoam KF-750 expanding foam 750ml', 'model_number' => 'KF-750', 'attributes' => ['capacity_ml' => 750], 'uom' => 'can']],
        ], 'inventory' => ['tenant_sku' => 'VTD-KF750', 'cost_cents' => 21000, 'price_cents' => 32000, 'quantity' => 12, 'reorder_threshold' => 4, 'store_id' => $c['stores']['VTD-CB']->id]], 'seed-ai-confirm-3');

        // Barcode on the box belongs to another product: shown with a warning, waiting for review.
        $this->analysis('conflicting-barcode', $c['tenant']->id, $c['users']['owner.c@hardwhere.test'], 'intake');

        // POS photo search: candidates restricted to what tenant A actually carries.
        $this->analysis('barcode-visible', $a['tenant']->id, $a['users']['cashier.a@hardwhere.test'], 'pos_search', restrictTo: $this->intake->carriedBy($a['tenant']->id));

        // Customer photo search: no tenant, image not retained, restricted to publicly listed products.
        $this->analysis('makita-hp1630', null, $ana, 'marketplace_search', restrictTo: $this->intake->publiclyListed());
    }

    /** @param  Builder<ProductVariant>|null  $restrictTo */
    private function analysis(string $scenario, ?int $tenantId, User $user, string $purpose, ?string $label = null, ?Builder $restrictTo = null): AiAnalysis
    {
        $result = (new MockVisionProvider)->analyze($scenario, 'image/jpeg', ['hint' => $scenario]);
        $match = $this->matcher->match($result->extraction, $tenantId, $restrictTo);
        $analysis = AiAnalysis::create([
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'purpose' => $purpose, 'provider' => 'mock', 'model' => $result->model, 'is_mock' => true,
            'image_sha256' => hash('sha256', "seed:$purpose:$scenario"), 'image_mime' => 'image/jpeg', 'extracted' => $result->extraction->toArray(),
            'warnings' => array_values(array_filter(array_merge($match['warnings'], [$label]))), 'raw_response' => $result->raw,
            'overall_confidence' => $result->extraction->overallConfidence, 'classification' => $match['classification'], 'requires_confirmation' => $match['requires_confirmation'],
            'duration_ms' => 0,
        ]);
        foreach ($match['candidates'] as $rank => $cand) {
            $analysis->candidates()->create(['product_id' => $cand['product_id'], 'variant_id' => $cand['variant_id'], 'classification' => $cand['classification'], 'score' => $cand['score'],
                'matched_fields' => $cand['matched_fields'], 'mismatched_fields' => $cand['mismatched_fields'], 'warnings' => $cand['warnings'], 'rank' => $rank + 1]);
        }

        return $analysis;
    }

    // ------------------------------------------------------------------ builders

    /** Wall-clock time in the Philippines on the given calendar day, converted to the app timezone (UTC) for storage. */
    private static function manila(CarbonInterface $day, int $hour, int $minute): CarbonInterface
    {
        return $day->copy()->setTimezone('Asia/Manila')->setTime($hour, $minute)->setTimezone(config('app.timezone'));
    }

    private function tenant(string $slug): Tenant
    {
        $cfg = self::TENANTS[$slug];
        $tenant = Tenant::create(['name' => $cfg['name'], 'slug' => $slug, 'reservation_ttl_minutes' => $cfg['ttl'], 'settings' => [
            'plan' => $cfg['plan'], 'plan_limits' => self::PLANS[$cfg['plan']], 'business_type' => $cfg['type'], 'currency' => 'PHP', 'timezone' => 'Asia/Manila',
        ]])->refresh(); // load DB defaults such as vat_rate_bps
        $this->t[$slug] = ['tenant' => $tenant, 'stores' => [], 'locations' => [], 'registers' => [], 'users' => [], 'suppliers' => [], 'items' => [], 'listings' => []];

        return $tenant;
    }

    /** @param  array<string, mixed>  $hours */
    private function store(Tenant $t, string $name, string $code, string $addr, string $barangay, string $city, string $zip, float $lat, float $lng, string $phone, bool $delivery, array $hours): Store
    {
        $province = $city === 'Quezon City' ? 'Metro Manila' : 'Cebu';
        $store = Store::create(['tenant_id' => $t->id, 'name' => $name, 'code' => $code, 'address_line' => $addr, 'barangay' => $barangay, 'city' => $city, 'province' => $province,
            'postal_code' => $zip, 'lat' => $lat, 'lng' => $lng, 'phone' => $phone, 'email' => strtolower($code).'@hardwhere.test',
            'supports_pickup' => true, 'supports_delivery' => $delivery, 'hours' => $hours]);
        $loc = InventoryLocation::create(['tenant_id' => $t->id, 'store_id' => $store->id, 'name' => 'Main floor', 'code' => 'MAIN']);
        // Bulk goods sit in a non-sellable back yard at the construction supply; nothing is stocked there yet.
        $t->slug === 'mabini-builders' && InventoryLocation::create(['tenant_id' => $t->id, 'store_id' => $store->id, 'name' => 'Back yard (receiving)', 'code' => 'YARD', 'is_sellable' => false]);
        $register = PosRegister::create(['tenant_id' => $t->id, 'store_id' => $store->id, 'name' => 'Counter 1']);

        $this->t[$t->slug]['stores'][$code] = $store;
        $this->t[$t->slug]['locations'][$code] = $loc->id;
        $this->t[$t->slug]['registers'][$code] = $register;

        return $store;
    }

    private function member(Tenant $tenant, string $name, string $email, TenantRole $role, ?Store $store = null): User
    {
        $user = User::query()->where('email', $email)->first() ?? $this->user($name, $email);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role->value, 'store_id' => $store?->id]);
        $this->t[$tenant->slug]['users'][$email] = $user;
        if ($role === TenantRole::Owner) {
            $this->t[$tenant->slug]['owner'] = new TenantContext($tenant->id, $user->id, TenantRole::Owner, null, $tenant->vat_rate_bps, $tenant->reservation_ttl_minutes);
        }

        return $user;
    }

    private function ctx(string $slug, string $email): TenantContext
    {
        $w = $this->t[$slug];
        $m = TenantMembership::query()->where('tenant_id', $w['tenant']->id)->where('user_id', $w['users'][$email]->id)->firstOrFail();

        return new TenantContext($w['tenant']->id, $m->user_id, TenantRole::from($m->role), $m->store_id, $w['tenant']->vat_rate_bps, $w['tenant']->reservation_ttl_minutes);
    }

    private function supplier(Tenant $t, string $name, string $contact, string $phone, int $leadDays): void
    {
        $this->t[$t->slug]['suppliers'][] = Supplier::create(['tenant_id' => $t->id, 'name' => $name, 'contact_name' => $contact, 'phone' => $phone, 'lead_time_days' => $leadDays])->id;
    }

    private function user(string $name, string $email, ?string $phone = null): User
    {
        return User::query()->forceCreate(['name' => $name, 'email' => $email, 'password' => bcrypt('password'), 'phone' => $phone, 'email_verified_at' => now()]);
    }
}
