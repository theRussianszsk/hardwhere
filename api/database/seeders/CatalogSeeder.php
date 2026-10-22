<?php

namespace Database\Seeders;

use App\Ai\CatalogVocabulary;
use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\ProductImage;
use App\Services\CatalogService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/** Shared canonical catalog. Deterministic: fixed barcodes, fixed variant structure. */
class CatalogSeeder extends Seeder
{
    /** @var array<string, int> demo reference => product_variants.id, filled by run() for the demo seeder and tests */
    public static array $refs = [];

    public static function ean13(string $body12): string
    {
        $sum = 0;
        foreach (str_split($body12) as $i => $d) {
            $sum += (int) $d * ($i % 2 ? 3 : 1);
        }

        return $body12.((10 - $sum % 10) % 10);
    }

    public function run(CatalogService $catalog): void
    {
        $cats = [
            'power-tools' => 'Power Tools', 'hand-tools' => 'Hand Tools', 'fasteners' => 'Fasteners', 'plumbing' => 'Plumbing', 'electrical' => 'Electrical Supplies',
            'construction-materials' => 'Construction Materials', 'paint-coatings' => 'Paint & Coatings', 'safety-equipment' => 'Safety Equipment',
            'measuring-tools' => 'Measuring Tools', 'automotive' => 'Automotive Hardware', 'outdoor-garden' => 'Outdoor & Garden',
            'building-supplies' => 'Building Supplies', 'accessories-parts' => 'Accessories & Parts',
        ];
        $catId = [];
        $i = 0;
        foreach ($cats as $slug => $name) {
            $catId[$slug] = Category::updateOrCreate(['slug' => $slug], ['name' => $name, 'sort' => $i++])->id;
        }
        Category::updateOrCreate(['slug' => 'drills'], ['name' => 'Drills', 'parent_id' => $catId['power-tools'], 'sort' => 1]);

        foreach ([
            ['voltage_v', 'Voltage', 'V'], ['wattage_w', 'Wattage', 'W'], ['amperage_a', 'Current rating', 'A'], ['capacity_ml', 'Capacity', 'mL'], ['capacity_l', 'Capacity', 'L'],
            ['pack_quantity', 'Pack quantity', null], ['size', 'Size', null], ['color', 'Color', null], ['diameter_mm', 'Diameter', 'mm'], ['length_mm', 'Length', 'mm'],
            ['thread_size', 'Thread size', null], ['drive_size', 'Drive size', null], ['weight_oz', 'Weight', 'oz'], ['material', 'Material', null], ['gauge_mm2', 'Wire gauge', 'mm²'],
            ['battery_type', 'Battery type', null], ['kit', 'Kit contents', null],
        ] as [$key, $label, $unit]) {
            AttributeDefinition::updateOrCreate(['category_id' => null, 'key' => $key], ['label' => $label, 'unit' => $unit, 'data_type' => $key === 'pack_quantity' || str_ends_with($key, '_v') ? 'number' : 'text', 'is_variant_axis' => true]);
        }
        AttributeDefinition::updateOrCreate(['category_id' => null, 'key' => 'chuck_mm'], ['label' => 'Chuck size', 'unit' => 'mm', 'data_type' => 'number', 'is_variant_axis' => false]);

        $b = fn (string $n) => self::ean13($n);
        // [category, brand, name, model, synonyms, shared attrs, [variants: ref, name, model, attrs, pack, uom, barcode]]
        $products = [
            ['power-tools', 'Makita', 'Hammer Drill 16mm', 'HP1630', ['rotary hammer', 'impact drill', 'martilyo drill'], ['wattage_w' => 710, 'chuck_mm' => 13], [
                ['HP1630', 'Makita HP1630 Hammer Drill 16mm, 710W 220V', 'HP1630', ['voltage_v' => 220, 'wattage_w' => 710], 1, 'pc', null],
                ['HP1630K', 'Makita HP1630K Hammer Drill 16mm with carrying case', 'HP1630K', ['voltage_v' => 220, 'wattage_w' => 710, 'kit' => 'with case'], 1, 'set', $b('488838100163')]]],
            ['power-tools', 'Bosch', 'Impact Drill GSB 13 RE', 'GSB 13 RE', ['hammer drill', 'bosch drill'], [], [
                ['GSB13', 'Bosch GSB 13 RE Impact Drill 600W 220V', 'GSB 13 RE', ['voltage_v' => 220, 'wattage_w' => 600], 1, 'pc', $b('316192100013')]]],
            ['power-tools', 'Bosch', 'Angle Grinder GWS 060 4"', 'GWS 060', ['grinder', 'gilingan'], [], [
                ['GWS060', 'Bosch GWS 060 Angle Grinder 4", 670W', 'GWS 060', ['voltage_v' => 220, 'wattage_w' => 670], 1, 'pc', $b('316192100606')]]],
            ['power-tools', 'DeWalt', 'Cordless Drill Driver DCD771', 'DCD771', ['cordless drill', 'drill driver'], [], [
                ['DCD771-KIT', 'DeWalt DCD771 18V Cordless Drill Driver, 2 batteries + charger', 'DCD771C2', ['voltage_v' => 18, 'kit' => '2 batteries'], 1, 'set', $b('885911100771')],
                ['DCD771-BARE', 'DeWalt DCD771 18V Cordless Drill Driver, tool only', 'DCD771N', ['voltage_v' => 18, 'kit' => 'bare tool'], 1, 'pc', $b('885911100772')]]],
            ['power-tools', 'Black+Decker', 'Cordless Drill BCD001', 'BCD001', ['cordless drill', 'drill driver'], [], [
                ['BCD001-12V', 'Black+Decker BCD001 Cordless Drill 12V', 'BCD001', ['voltage_v' => 12], 1, 'pc', $b('885911200121')],
                ['BCD001-20V', 'Black+Decker BCD001 Cordless Drill 20V', 'BCD001', ['voltage_v' => 20], 1, 'pc', $b('885911200201')]]],
            ['power-tools', 'Makita', 'Jigsaw 4329', '4329', ['saw', 'jig saw'], [], [
                ['MK4329', 'Makita 4329 Jigsaw 450W 220V', '4329', ['voltage_v' => 220, 'wattage_w' => 450], 1, 'pc', $b('088381100329')]]],
            ['hand-tools', 'Stanley', 'Claw Hammer 51-188', '51-188', ['martilyo', 'hammer'], [], [
                ['STH16', 'Stanley 51-188 Claw Hammer 16oz', '51-188', ['weight_oz' => 16], 1, 'pc', '4800000100017'],
                ['STH20', 'Stanley 51-189 Claw Hammer 20oz', '51-189', ['weight_oz' => 20], 1, 'pc', $b('480000010002')]]],
            ['hand-tools', 'Tolsen', 'Combination Pliers', null, ['pliers', 'plais', 'alicate'], [], [
                ['TLPL6', 'Tolsen Combination Pliers 6"', '10001', ['length_mm' => 150], 1, 'pc', $b('692229500016')],
                ['TLPL8', 'Tolsen Combination Pliers 8"', '10002', ['length_mm' => 200], 1, 'pc', $b('692229500023')]]],
            ['hand-tools', 'Tolsen', 'Claw Hammer 25101', '25101', ['martilyo', 'hammer'], [], [
                ['TL25101', 'Tolsen Claw Hammer 25101 16oz fiberglass handle', '25101', ['weight_oz' => 16], 1, 'pc', $b('692229525101')]]],
            ['fasteners', null, 'Tek Screw (self-drilling) #10 x 1"', null, ['tek screw', 'self drilling screw', 'turnilyo'], ['material' => 'zinc'], [
                ['TEK100', 'Tek screw #10 x 1" zinc, pack of 100', null, ['size' => '#10 x 1"', 'material' => 'zinc'], 100, 'pack', null],
                ['TEK500', 'Tek screw #10 x 1" zinc, pack of 500', null, ['size' => '#10 x 1"', 'material' => 'zinc'], 500, 'pack', null]]],
            ['fasteners', null, 'Common Wire Nail 3"', null, ['nails', 'pako', 'wire nail'], [], [
                ['NAIL3', 'Common wire nail 3" (per kg)', null, ['length_mm' => 76], 1, 'kg', null]]],
            ['fasteners', null, 'Hex Bolt M10 x 50 zinc', null, ['bolt', 'hex bolt'], [], [
                ['BOLTM10', 'Hex bolt M10 x 50mm zinc, pack of 50', null, ['thread_size' => 'M10', 'length_mm' => 50, 'material' => 'zinc'], 50, 'pack', null]]],
            ['plumbing', 'Neltex', 'PVC Pipe Series 1000', null, ['pvc pipe', 'tubo'], [], [
                ['PVC12', 'Neltex PVC pipe 1/2" x 3m', null, ['diameter_mm' => 12.7, 'length_mm' => 3000], 1, 'pc', null],
                ['PVC34', 'Neltex PVC pipe 3/4" x 3m', null, ['diameter_mm' => 19.05, 'length_mm' => 3000], 1, 'pc', null]]],
            ['plumbing', null, 'Brass Gate Valve 1/2"', null, ['gate valve', 'valve'], [], [
                ['GATE12', 'Brass gate valve 1/2"', null, ['diameter_mm' => 12.7, 'material' => 'brass'], 1, 'pc', $b('480000200012')]]],
            ['plumbing', null, 'Teflon Tape', null, ['plumbers tape', 'ptfe tape'], [], [
                ['TEFLON', 'Teflon tape 1/2" x 10m', null, ['length_mm' => 10000], 1, 'pc', $b('480000200029')]]],
            ['electrical', 'Phelps Dodge', 'THHN Wire (stranded)', null, ['thhn', 'electrical wire', 'kawad'], [], [
                ['THHN20', 'Phelps Dodge THHN wire 2.0mm² x 75m', null, ['gauge_mm2' => 2.0, 'length_mm' => 75000], 1, 'roll', $b('480000300020')],
                ['THHN35', 'Phelps Dodge THHN wire 3.5mm² x 75m', null, ['gauge_mm2' => 3.5, 'length_mm' => 75000], 1, 'roll', $b('480000300035')]]],
            ['electrical', 'Royu', 'Circuit Breaker 2-pole', null, ['breaker', 'circuit breaker'], [], [
                ['BRK20', 'Royu circuit breaker 2-pole 20A', null, ['amperage_a' => 20], 1, 'pc', $b('480000400020')],
                ['BRK30', 'Royu circuit breaker 2-pole 30A', null, ['amperage_a' => 30], 1, 'pc', $b('480000400030')]]],
            ['electrical', 'Philips', 'LED Bulb 9W E27', null, ['led bulb', 'ilaw', 'light bulb'], ['wattage_w' => 9], [
                ['LED9WW', 'Philips LED bulb 9W E27 warm white', null, ['wattage_w' => 9, 'color' => 'warm white'], 1, 'pc', $b('892829000091')],
                ['LED9WD', 'Philips LED bulb 9W E27 daylight', null, ['wattage_w' => 9, 'color' => 'daylight'], 1, 'pc', $b('892829000107')]]],
            ['construction-materials', 'Republic', 'Portland Cement Type 1 40kg', null, ['semento', 'cement'], [], [
                ['CEMENT', 'Republic Portland cement 40kg bag', null, ['weight_oz' => 1411], 1, 'bag', null]]],
            ['construction-materials', null, 'Deformed Rebar 10mm x 6m (Grade 40)', null, ['rebar', 'bakal', 'steel bar'], [], [
                ['REBAR10', 'Deformed rebar 10mm x 6m Grade 40', null, ['diameter_mm' => 10, 'length_mm' => 6000], 1, 'pc', null]]],
            ['construction-materials', null, 'Marine Plywood 4x8', null, ['plywood', 'marine ply'], [], [
                ['PLY12', 'Marine plywood 1/2" x 4ft x 8ft', null, ['size' => '1/2" x 4x8'], 1, 'sheet', null]]],
            ['paint-coatings', 'Boysen', 'Permacoat Flat Latex White', null, ['paint', 'pintura', 'latex'], ['color' => 'white'], [
                ['BOY1L', 'Boysen Permacoat flat latex white 1L', null, ['color' => 'white', 'capacity_l' => 1], 1, 'can', $b('480000500011')],
                ['BOY4L', 'Boysen Permacoat flat latex white 4L', null, ['color' => 'white', 'capacity_l' => 4], 1, 'can', $b('480000500048')],
                ['BOY16L', 'Boysen Permacoat flat latex white 16L', null, ['color' => 'white', 'capacity_l' => 16], 1, 'pail', $b('480000500165')]]],
            ['safety-equipment', null, 'Safety Hard Hat', null, ['helmet', 'hard hat'], [], [
                ['HHAT-Y', 'Safety hard hat yellow', null, ['color' => 'yellow'], 1, 'pc', $b('480000600013')],
                ['HHAT-W', 'Safety hard hat white', null, ['color' => 'white'], 1, 'pc', $b('480000600020')]]],
            ['safety-equipment', null, 'Leather Work Gloves', null, ['gloves', 'guwantes'], [], [
                ['GLOVE', 'Leather work gloves, pair', null, ['material' => 'leather'], 1, 'pair', null]]],
            ['measuring-tools', 'Stanley', 'PowerLock Tape Measure', null, ['tape measure', 'metro'], [], [
                ['TAPE5', 'Stanley PowerLock tape measure 5m', null, ['length_mm' => 5000], 1, 'pc', $b('076174330001')],
                ['TAPE8', 'Stanley PowerLock tape measure 8m', null, ['length_mm' => 8000], 1, 'pc', $b('076174330087')]]],
            ['automotive', 'Motul', '5100 4T 10W-40 Motor Oil', null, ['engine oil', 'langis'], [], [
                ['MOTUL1', 'Motul 5100 4T 10W-40 1L', null, ['capacity_l' => 1], 1, 'bottle', $b('354400510011')]]],
            ['outdoor-garden', null, 'Garden Hose 1/2"', null, ['hose', 'hose pang-hardin'], [], [
                ['HOSE15', 'Garden hose 1/2" x 15m', null, ['diameter_mm' => 12.7, 'length_mm' => 15000], 1, 'roll', null]]],
            ['building-supplies', null, 'Tile Adhesive 20kg', null, ['tile glue', 'pandikit tiles'], [], [
                ['TILEADH', 'Tile adhesive 20kg bag', null, ['weight_oz' => 705], 1, 'bag', null]]],
            ['building-supplies', null, 'Steel Door Hinge 3"', null, ['hinge', 'bisagra'], [], [
                ['HINGE3', 'Steel door hinge 3", pack of 2', null, ['length_mm' => 76], 2, 'pack', $b('480000700032')]]],
            ['accessories-parts', 'Makita', 'Carbon Brush CB-440', 'CB-440', ['carbon brush', 'replacement brush'], [], [
                ['CB440', 'Makita carbon brush CB-440 (pair)', 'CB-440', [], 2, 'pair', $b('088381100440')]]],
            ['accessories-parts', 'Bosch', 'Drill Bit Set 5-piece', null, ['drill bits', 'mecha'], [], [
                ['BITSET5', 'Bosch drill bit set 5-piece (4-8mm)', null, [], 5, 'set', $b('316192100055')]]],
        ];

        foreach ($products as [$cat, $brand, $name, $model, $synonyms, $attrs, $variants]) {
            $product = $catalog->createProduct([
                'category_id' => $catId[$cat], 'brand' => $brand, 'name' => $name, 'model_number' => $model, 'synonyms' => $synonyms, 'attributes' => $attrs,
                'description' => "$name — demo catalog entry.", 'ph_specs' => in_array($cat, ['power-tools', 'electrical'], true) ? ['voltage' => '220V 60Hz', 'plug' => 'Type A/B'] : [],
                'warranty' => in_array($cat, ['power-tools'], true) ? '1 year manufacturer warranty' : null,
                'safety_info' => $cat === 'power-tools' ? 'Wear eye and ear protection. Disconnect from power before changing accessories.' : null,
                'variants' => array_map(fn ($v) => ['name' => $v[1], 'model_number' => $v[2], 'attributes' => $v[3], 'pack_quantity' => $v[4], 'uom' => $v[5], 'barcodes' => $v[6] ? [$v[6] => strlen($v[6]) === 13 ? 'EAN13' : 'OTHER'] : []], $variants),
            ]);
            ProductImage::create(['product_id' => $product->id, 'path' => "/demo-images/$cat.svg", 'is_primary' => true, 'alt' => "$name (demo image)", 'source' => 'seed']);
            foreach ($variants as $idx => $v) {
                self::$refs[$v[0]] = $product->variants[$idx]->id;
            }
        }
        Cache::forget(CatalogVocabulary::CACHE_KEY); // live AI prompts must use the categories and attributes just seeded
    }
}
