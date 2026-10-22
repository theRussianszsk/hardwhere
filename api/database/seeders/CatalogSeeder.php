<?php

namespace Database\Seeders;

use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\ProductImage;
use App\Services\CatalogService;
use Illuminate\Database\Seeder;

/** Shared canonical catalog from data/catalog.php. Deterministic: fixed barcodes, fixed variant structure, stable refs. */
class CatalogSeeder extends Seeder
{
    /** @var array<string, int> demo reference => product_variants.id, filled by run() for the demo seeder and tests */
    public static array $refs = [];

    /** @var array<string, int> demo reference => indicative shelf price in centavos */
    public static array $srp = [];

    /** @var array<string, string> demo reference => top-level category slug */
    public static array $top = [];

    public static function ean13(string $body12): string
    {
        $sum = 0;
        foreach (str_split($body12) as $i => $d) {
            $sum += (int) $d * ($i % 2 ? 3 : 1);
        }

        return $body12.((10 - $sum % 10) % 10);
    }

    /** Products of the original small catalog. Unit tests seed only these, so they stay fast and their matcher world stays stable. */
    public const CORE = ['HP1630', 'GSB13', 'GWS060', 'DCD771-KIT', 'BCD001-12V', 'MK4329', 'STH16', 'TLPL6', 'TL25101', 'TEK100', 'NAIL3', 'BOLTM10', 'PVC12', 'GATE12',
        'TEFLON', 'THHN20', 'BRK20', 'LED9WW', 'CEMENT', 'REBAR10', 'PLY12', 'BOY1L', 'HHAT-Y', 'GLOVE', 'TAPE5', 'MOTUL1', 'HOSE15', 'TILEADH', 'HINGE3', 'CB440', 'BITSET5'];

    public function run(CatalogService $catalog): void
    {
        $this->seedCatalog($catalog);
    }

    public function seedCatalog(CatalogService $catalog, bool $coreOnly = false): void
    {
        $data = require __DIR__.'/data/catalog.php';
        if ($coreOnly) {
            $data['products'] = array_values(array_filter($data['products'], fn ($p) => array_intersect(array_column($p['v'], 0), self::CORE)));
        }
        self::$refs = self::$srp = self::$top = [];

        $catId = [];
        $sort = 0;
        foreach ($data['categories'] as $slug => [$name, $parent]) {
            $catId[$slug] = Category::updateOrCreate(['slug' => $slug], ['name' => $name, 'parent_id' => $parent ? $catId[$parent] : null, 'sort' => $sort++])->id;
        }

        foreach ($data['attributes'] as [$key, $label, $unit, $type, $axis, $cat]) {
            AttributeDefinition::updateOrCreate(['category_id' => $cat ? $catId[$cat] : null, 'key' => $key], ['label' => $label, 'unit' => $unit, 'data_type' => $type, 'is_variant_axis' => $axis]);
        }

        $synthetic = 0;
        $barcode = function (string|bool|null $code) use (&$synthetic): ?string {
            return match (true) {
                $code === true => self::ean13('48099'.sprintf('%07d', ++$synthetic)), // 480 = GS1 Philippines; demo-only range
                is_string($code) && strlen($code) === 12 => self::ean13($code),
                default => $code ?: null,
            };
        };

        foreach ($data['products'] as $p) {
            $top = Category::query()->find($catId[$p['cat']])->parent?->slug ?? $p['cat'];
            $variants = array_map(function ($v) use ($barcode) {
                $code = $barcode($v[6]);

                return ['name' => $v[1], 'model_number' => $v[2], 'attributes' => $v[3], 'pack_quantity' => $v[4], 'uom' => $v[5], 'barcodes' => $code ? [$code => 'EAN13'] : []];
            }, $p['v']);

            $product = $catalog->createProduct([
                'category_id' => $catId[$p['cat']], 'brand' => $p['brand'], 'name' => $p['name'], 'model_number' => $p['model'], 'product_type' => $p['type'],
                'synonyms' => $p['syn'], 'attributes' => $p['attrs'] ?? [], 'compatibility' => $p['compat'] ?? [], 'included_components' => $p['incl'] ?? [],
                'description' => $p['desc'] ?? trim(($p['brand'] ? $p['brand'].' ' : '').$p['name']).'. Demo catalog entry; specifications are indicative.',
                'ph_specs' => $p['ph'] ?? [], 'warranty' => $p['warranty'] ?? null, 'safety_info' => $p['safety'] ?? null,
                'variants' => $variants,
            ]);
            ProductImage::create(['product_id' => $product->id, 'path' => "/demo-images/$top.svg", 'is_primary' => true, 'alt' => "{$p['name']} (demo image)", 'source' => 'seed']);

            foreach ($p['v'] as $idx => $v) {
                self::$refs[$v[0]] = $product->variants[$idx]->id;
                self::$srp[$v[0]] = (int) round($v[7] * 100);
                self::$top[$v[0]] = $top;
            }
        }
    }
}
