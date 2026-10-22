<?php

/*
 * Shared canonical hardware catalog for the demo, shaped around a Philippine neighbourhood hardware store
 * (Metro Cebu beachhead): many items have no barcode (loose fasteners, PVC fittings, cut lumber, repacked sand),
 * and many products only differ by a commercially relevant axis (wattage, amperage, wire gauge, pack size, grade).
 *
 * Variant tuple: [ref, name, model_number, attributes, pack_quantity, uom, barcode, srp_pesos]
 *   barcode: 12 digits = EAN-13 body (check digit is computed), 13 digits = full code, true = synthetic EAN-13 assigned by the seeder, null = item has no barcode.
 *   srp_pesos: indicative shelf price used to derive each tenant's own cost and price.
 * Barcodes and SRPs are demo values, not manufacturer data. Refs are stable keys used by seeders and tests.
 */

$tools220 = ['voltage' => '220V 60Hz', 'plug' => 'Type A/B (flat or round pin)', 'dti_bps' => 'PS/ICC mark required'];
$elec = ['dti_bps' => 'PS/ICC mark required', 'code' => 'Philippine Electrical Code (PEC)'];
$toolSafety = 'Wear eye and ear protection. Unplug before changing discs, bits or blades.';
$toolWarranty = '1 year manufacturer warranty';

return [
    'categories' => [
        // slug => [name, parent slug|null]
        'power-tools' => ['Power Tools', null],
        'drills' => ['Drills & Rotary Hammers', 'power-tools'],
        'angle-grinders' => ['Angle Grinders', 'power-tools'],
        'saws' => ['Power Saws', 'power-tools'],
        'sanders' => ['Sanders & Polishers', 'power-tools'],
        'welding-machines' => ['Welding Machines', 'power-tools'],
        'hand-tools' => ['Hand Tools', null],
        'hammers' => ['Hammers', 'hand-tools'],
        'pliers-cutters' => ['Pliers & Cutters', 'hand-tools'],
        'screwdrivers' => ['Screwdrivers', 'hand-tools'],
        'wrenches-sockets' => ['Wrenches & Sockets', 'hand-tools'],
        'masonry-tools' => ['Masonry Tools', 'hand-tools'],
        'fasteners' => ['Fasteners', null],
        'nails' => ['Nails', 'fasteners'],
        'screws' => ['Screws', 'fasteners'],
        'bolts-nuts' => ['Bolts & Nuts', 'fasteners'],
        'anchors' => ['Anchors & Wall Plugs', 'fasteners'],
        'tie-wire-rivets' => ['Tie Wire & Rivets', 'fasteners'],
        'plumbing' => ['Plumbing', null],
        'pipes' => ['Pipes', 'plumbing'],
        'pipe-fittings' => ['Pipe Fittings', 'plumbing'],
        'valves-faucets' => ['Valves & Faucets', 'plumbing'],
        'plumbing-sealants' => ['Plumbing Sealants', 'plumbing'],
        'electrical' => ['Electrical Supplies', null],
        'wires-cables' => ['Wires & Cables', 'electrical'],
        'breakers-panels' => ['Breakers & Panel Boards', 'electrical'],
        'wiring-devices' => ['Switches & Outlets', 'electrical'],
        'lighting' => ['Lighting', 'electrical'],
        'conduits-boxes' => ['Conduits & Boxes', 'electrical'],
        'extension-cords' => ['Extension Cords', 'electrical'],
        'construction-materials' => ['Construction Materials', null],
        'cement' => ['Cement', 'construction-materials'],
        'steel-bars' => ['Steel Bars & Sections', 'construction-materials'],
        'hollow-blocks' => ['Hollow Blocks', 'construction-materials'],
        'aggregates' => ['Sand & Gravel', 'construction-materials'],
        'lumber' => ['Lumber & Plywood', 'construction-materials'],
        'roofing' => ['Roofing', 'construction-materials'],
        'paint-coatings' => ['Paint & Coatings', null],
        'latex-paint' => ['Latex Paint', 'paint-coatings'],
        'enamel-paint' => ['Enamel Paint', 'paint-coatings'],
        'primers-thinners' => ['Primers & Thinners', 'paint-coatings'],
        'waterproofing' => ['Waterproofing', 'paint-coatings'],
        'painting-tools' => ['Brushes & Rollers', 'paint-coatings'],
        'safety-equipment' => ['Safety Equipment', null],
        'head-protection' => ['Head Protection', 'safety-equipment'],
        'hand-protection' => ['Gloves', 'safety-equipment'],
        'eye-face' => ['Eye & Face Protection', 'safety-equipment'],
        'foot-protection' => ['Safety Shoes', 'safety-equipment'],
        'respiratory' => ['Masks & Respirators', 'safety-equipment'],
        'measuring-tools' => ['Measuring Tools', null],
        'tape-measures' => ['Tape Measures', 'measuring-tools'],
        'levels-squares' => ['Levels & Squares', 'measuring-tools'],
        'automotive' => ['Automotive Hardware', null],
        'engine-oil' => ['Engine Oil', 'automotive'],
        'car-batteries' => ['Batteries', 'automotive'],
        'outdoor-garden' => ['Outdoor & Garden', null],
        'hoses-watering' => ['Hoses & Sprayers', 'outdoor-garden'],
        'garden-tools' => ['Garden Tools', 'outdoor-garden'],
        'outdoor-power' => ['Outdoor Power Equipment', 'outdoor-garden'],
        'building-supplies' => ['Building Supplies', null],
        'adhesives-sealants' => ['Adhesives & Sealants', 'building-supplies'],
        'door-hardware' => ['Door Hardware & Locks', 'building-supplies'],
        'tiles-grout' => ['Tiles & Grout', 'building-supplies'],
        'ceiling-drywall' => ['Ceiling & Drywall', 'building-supplies'],
        'accessories-parts' => ['Accessories & Parts', null],
        'cutting-discs' => ['Cutting & Grinding Discs', 'accessories-parts'],
        'drill-bits' => ['Drill Bits', 'accessories-parts'],
        'carbon-brushes' => ['Carbon Brushes', 'accessories-parts'],
        'tool-batteries' => ['Tool Batteries & Chargers', 'accessories-parts'],
        'saw-blades' => ['Saw Blades', 'accessories-parts'],
    ],

    // [key, label, unit, data_type, is_variant_axis, category slug|null (null = applies to every category)]
    'attributes' => [
        ['voltage_v', 'Voltage', 'V', 'number', true, null],
        ['wattage_w', 'Wattage', 'W', 'number', true, null],
        ['amperage_a', 'Current rating', 'A', 'number', true, null],
        ['capacity_ml', 'Capacity', 'mL', 'number', true, null],
        ['capacity_l', 'Capacity', 'L', 'number', true, null],
        ['pack_quantity', 'Pack quantity', null, 'number', true, null],
        ['size', 'Size', null, 'text', true, null],
        ['color', 'Color', null, 'text', true, null],
        ['diameter_mm', 'Diameter', 'mm', 'number', true, null],
        ['length_mm', 'Length', 'mm', 'number', true, null],
        ['width_mm', 'Width', 'mm', 'number', true, null],
        ['thickness_mm', 'Thickness', 'mm', 'number', true, null],
        ['thread_size', 'Thread size', null, 'text', true, null],
        ['drive_size', 'Drive size', null, 'text', true, null],
        ['weight_oz', 'Head weight', 'oz', 'number', true, null],
        ['weight_lb', 'Head weight', 'lb', 'number', true, null],
        ['weight_kg', 'Weight', 'kg', 'number', true, null],
        ['material', 'Material', null, 'text', true, null],
        ['battery_type', 'Battery type', null, 'text', true, null],
        ['battery_ah', 'Battery capacity', 'Ah', 'number', true, null],
        ['kit', 'Kit contents', null, 'text', true, null],
        ['chuck_mm', 'Chuck size', 'mm', 'number', false, null],
        ['disc_mm', 'Disc diameter', 'mm', 'number', true, null],
        ['gauge_mm2', 'Wire gauge', 'mm²', 'number', true, 'electrical'],
        ['gangs', 'Number of gangs', null, 'number', true, 'electrical'],
        ['poles', 'Poles', null, 'number', true, 'electrical'],
        ['grade', 'Steel grade', null, 'text', true, 'construction-materials'],
        ['sheet_gauge', 'Sheet gauge', 'GA', 'number', true, 'construction-materials'],
        ['teeth', 'Teeth', null, 'number', true, 'accessories-parts'],
        ['shoe_size', 'Shoe size (EU)', null, 'number', true, 'safety-equipment'],
        ['viscosity', 'Viscosity grade', null, 'text', true, 'automotive'],
    ],

    'products' => [
        // ---------------- Power tools ----------------
        ['cat' => 'drills', 'brand' => 'Makita', 'name' => 'Hammer Drill 16mm', 'model' => 'HP1630', 'type' => 'Hammer drill', 'syn' => ['rotary hammer', 'impact drill', 'martilyo drill', 'drill pang semento'],
            'attrs' => ['wattage_w' => 710, 'chuck_mm' => 13], 'ph' => $tools220, 'warranty' => $toolWarranty, 'safety' => $toolSafety, 'incl' => ['side handle', 'depth gauge'], 'v' => [
                ['HP1630', 'Makita HP1630 Hammer Drill 16mm, 710W 220V', 'HP1630', ['voltage_v' => 220, 'wattage_w' => 710], 1, 'pc', null, 4500],
                ['HP1630K', 'Makita HP1630K Hammer Drill 16mm with carrying case', 'HP1630K', ['voltage_v' => 220, 'wattage_w' => 710, 'kit' => 'with case'], 1, 'set', '488838100163', 5300]]],
        ['cat' => 'drills', 'brand' => 'Bosch', 'name' => 'Impact Drill GSB 13 RE', 'model' => 'GSB 13 RE', 'type' => 'Impact drill', 'syn' => ['hammer drill', 'bosch drill'],
            'ph' => $tools220, 'warranty' => $toolWarranty, 'safety' => $toolSafety, 'v' => [
                ['GSB13', 'Bosch GSB 13 RE Impact Drill 600W 220V', 'GSB 13 RE', ['voltage_v' => 220, 'wattage_w' => 600], 1, 'pc', '316192100013', 3900]]],
        ['cat' => 'drills', 'brand' => 'Makita', 'name' => 'Rotary Hammer SDS-plus 24mm', 'model' => 'HR2470', 'type' => 'Rotary hammer', 'syn' => ['sds drill', 'chipping hammer', 'demolition drill'],
            'ph' => $tools220, 'warranty' => $toolWarranty, 'safety' => $toolSafety, 'incl' => ['side handle', 'depth gauge', 'carrying case'], 'v' => [
                ['HR2470', 'Makita HR2470 Rotary Hammer SDS-plus 24mm, 780W', 'HR2470', ['voltage_v' => 220, 'wattage_w' => 780], 1, 'pc', true, 7800]]],
        ['cat' => 'drills', 'brand' => 'DeWalt', 'name' => 'Cordless Drill Driver DCD771', 'model' => 'DCD771', 'type' => 'Cordless drill', 'syn' => ['cordless drill', 'drill driver', 'battery drill'],
            'warranty' => '3 years limited warranty', 'v' => [
                ['DCD771-KIT', 'DeWalt DCD771 18V Cordless Drill Driver, 2 batteries + charger', 'DCD771C2', ['voltage_v' => 18, 'kit' => '2 batteries'], 1, 'set', '885911100771', 8500],
                ['DCD771-BARE', 'DeWalt DCD771 18V Cordless Drill Driver, tool only', 'DCD771N', ['voltage_v' => 18, 'kit' => 'bare tool'], 1, 'pc', '885911100772', 5200]]],
        ['cat' => 'drills', 'brand' => 'Black+Decker', 'name' => 'Cordless Drill BCD001', 'model' => 'BCD001', 'type' => 'Cordless drill', 'syn' => ['cordless drill', 'drill driver'], 'v' => [
            ['BCD001-12V', 'Black+Decker BCD001 Cordless Drill 12V', 'BCD001', ['voltage_v' => 12], 1, 'pc', '885911200121', 3300],
            ['BCD001-20V', 'Black+Decker BCD001 Cordless Drill 20V', 'BCD001', ['voltage_v' => 20], 1, 'pc', '885911200201', 4100]]],
        ['cat' => 'drills', 'brand' => 'Ingco', 'name' => 'Impact Drill 13mm', 'model' => null, 'type' => 'Impact drill', 'syn' => ['budget drill', 'hammer drill'],
            'ph' => $tools220, 'warranty' => '6 months', 'safety' => $toolSafety, 'v' => [
                ['ING-ID650', 'Ingco Impact Drill 13mm 650W', null, ['voltage_v' => 220, 'wattage_w' => 650], 1, 'pc', true, 1450]]],

        // Business-plan trap: a 720W grinder must never be merged with an 840W one.
        ['cat' => 'angle-grinders', 'brand' => 'Bosch', 'name' => 'Angle Grinder GWS 060 4"', 'model' => 'GWS 060', 'type' => 'Angle grinder', 'syn' => ['grinder', 'gilingan', '4 inch grinder', 'disc grinder'],
            'ph' => $tools220, 'warranty' => $toolWarranty, 'safety' => $toolSafety, 'v' => [
                ['GWS060', 'Bosch GWS 060 Angle Grinder 4", 670W', 'GWS 060', ['voltage_v' => 220, 'wattage_w' => 670, 'disc_mm' => 100], 1, 'pc', '316192100606', 2350]]],
        ['cat' => 'angle-grinders', 'brand' => 'Makita', 'name' => 'Angle Grinder 4" (100mm)', 'model' => null, 'type' => 'Angle grinder', 'syn' => ['grinder', 'gilingan', '4 inch grinder', 'disc grinder'],
            'ph' => $tools220, 'warranty' => $toolWarranty, 'safety' => $toolSafety, 'incl' => ['side handle', 'wheel guard', 'lock nut wrench'], 'v' => [
                ['GA4030', 'Makita GA4030 Angle Grinder 4", 720W', 'GA4030', ['voltage_v' => 220, 'wattage_w' => 720, 'disc_mm' => 100], 1, 'pc', true, 3200],
                ['MK9556HN', 'Makita 9556HN Angle Grinder 4", 840W', '9556HN', ['voltage_v' => 220, 'wattage_w' => 840, 'disc_mm' => 100], 1, 'pc', true, 3700]]],
        ['cat' => 'angle-grinders', 'brand' => 'Ingco', 'name' => 'Angle Grinder 4-1/2"', 'model' => null, 'type' => 'Angle grinder', 'syn' => ['grinder', 'gilingan', 'budget grinder'],
            'ph' => $tools220, 'warranty' => '6 months', 'safety' => $toolSafety, 'v' => [
                ['ING-AG750', 'Ingco Angle Grinder 4-1/2" (115mm), 750W', null, ['voltage_v' => 220, 'wattage_w' => 750, 'disc_mm' => 115], 1, 'pc', true, 1150]]],

        ['cat' => 'saws', 'brand' => 'Makita', 'name' => 'Jigsaw 4329', 'model' => '4329', 'type' => 'Jigsaw', 'syn' => ['saw', 'jig saw', 'lagari'],
            'ph' => $tools220, 'warranty' => $toolWarranty, 'safety' => $toolSafety, 'v' => [
                ['MK4329', 'Makita 4329 Jigsaw 450W 220V', '4329', ['voltage_v' => 220, 'wattage_w' => 450], 1, 'pc', '088381100329', 3800]]],
        ['cat' => 'saws', 'brand' => 'Makita', 'name' => 'Circular Saw 7-1/4"', 'model' => '5806B', 'type' => 'Circular saw', 'syn' => ['circular saw', 'skill saw', 'lagari', 'wood cutter'],
            'ph' => $tools220, 'warranty' => $toolWarranty, 'safety' => $toolSafety, 'v' => [
                ['MK5806B', 'Makita 5806B Circular Saw 7-1/4" (185mm), 1050W', '5806B', ['voltage_v' => 220, 'wattage_w' => 1050, 'disc_mm' => 185], 1, 'pc', true, 5900]]],
        ['cat' => 'saws', 'brand' => 'Bosch', 'name' => 'Cut-off Machine GCO 220 14"', 'model' => 'GCO 220', 'type' => 'Cut-off saw', 'syn' => ['chop saw', 'cut off machine', 'steel cutter', 'pang putol bakal'],
            'ph' => $tools220, 'warranty' => $toolWarranty, 'safety' => $toolSafety, 'v' => [
                ['GCO220', 'Bosch GCO 220 Cut-off Machine 14" (355mm), 2200W', 'GCO 220', ['voltage_v' => 220, 'wattage_w' => 2200, 'disc_mm' => 355], 1, 'pc', true, 9500]]],
        ['cat' => 'sanders', 'brand' => 'Makita', 'name' => 'Finishing Sander', 'model' => 'BO3710', 'type' => 'Orbital sander', 'syn' => ['sander', 'liha machine', 'orbital sander'],
            'ph' => $tools220, 'warranty' => $toolWarranty, 'v' => [
                ['BO3710', 'Makita BO3710 Finishing Sander, 190W', 'BO3710', ['voltage_v' => 220, 'wattage_w' => 190], 1, 'pc', true, 4200]]],
        ['cat' => 'welding-machines', 'brand' => 'Ingco', 'name' => 'Inverter Welding Machine (MMA)', 'model' => null, 'type' => 'Stick welder', 'syn' => ['welding machine', 'welder', 'pang welding', 'inverter welder'],
            'ph' => $tools220, 'warranty' => '1 year', 'safety' => 'Use a welding mask and leather gloves. Work in a ventilated area away from flammables.', 'v' => [
                ['WELD160', 'Ingco inverter welding machine 160A', null, ['voltage_v' => 220, 'amperage_a' => 160], 1, 'pc', true, 4300],
                ['WELD200', 'Ingco inverter welding machine 200A', null, ['voltage_v' => 220, 'amperage_a' => 200], 1, 'pc', true, 5600]]],

        // ---------------- Hand tools ----------------
        ['cat' => 'hammers', 'brand' => 'Stanley', 'name' => 'Claw Hammer 51-188', 'model' => '51-188', 'type' => 'Claw hammer', 'syn' => ['martilyo', 'hammer', 'pako hammer'], 'v' => [
            ['STH16', 'Stanley 51-188 Claw Hammer 16oz', '51-188', ['weight_oz' => 16], 1, 'pc', '4800000100017', 350],
            ['STH20', 'Stanley 51-189 Claw Hammer 20oz', '51-189', ['weight_oz' => 20], 1, 'pc', '480000010002', 420]]],
        ['cat' => 'hammers', 'brand' => 'Tolsen', 'name' => 'Claw Hammer 25101', 'model' => '25101', 'type' => 'Claw hammer', 'syn' => ['martilyo', 'hammer'], 'v' => [
            ['TL25101', 'Tolsen Claw Hammer 25101 16oz fiberglass handle', '25101', ['weight_oz' => 16], 1, 'pc', '692229525101', 280]]],
        ['cat' => 'hammers', 'brand' => null, 'name' => 'Sledge Hammer, fiberglass handle', 'model' => null, 'type' => 'Sledge hammer', 'syn' => ['maso', 'sledge', 'pang guba'], 'v' => [
            ['SLEDGE4', 'Sledge hammer 4lb fiberglass handle', null, ['weight_lb' => 4], 1, 'pc', null, 550],
            ['SLEDGE8', 'Sledge hammer 8lb fiberglass handle', null, ['weight_lb' => 8], 1, 'pc', null, 950]]],
        ['cat' => 'pliers-cutters', 'brand' => 'Tolsen', 'name' => 'Combination Pliers', 'model' => null, 'type' => 'Pliers', 'syn' => ['pliers', 'plais', 'alicate'], 'v' => [
            ['TLPL6', 'Tolsen Combination Pliers 6"', '10001', ['length_mm' => 150], 1, 'pc', '692229500016', 230],
            ['TLPL8', 'Tolsen Combination Pliers 8"', '10002', ['length_mm' => 200], 1, 'pc', '692229500023', 295]]],
        ['cat' => 'pliers-cutters', 'brand' => null, 'name' => 'Bolt Cutter', 'model' => null, 'type' => 'Bolt cutter', 'syn' => ['bolt cutter', 'pamutol', 'rebar cutter'], 'v' => [
            ['BOLTCUT18', 'Bolt cutter 18"', null, ['length_mm' => 450], 1, 'pc', null, 620],
            ['BOLTCUT24', 'Bolt cutter 24"', null, ['length_mm' => 600], 1, 'pc', null, 850]]],
        ['cat' => 'pliers-cutters', 'brand' => 'Stanley', 'name' => 'Utility Knife 18mm', 'model' => null, 'type' => 'Utility knife', 'syn' => ['cutter', 'box cutter', 'kutsilyo'], 'v' => [
            ['CUTTER18', 'Stanley utility knife 18mm snap-off blade', null, ['width_mm' => 18], 1, 'pc', true, 120]]],
        ['cat' => 'pliers-cutters', 'brand' => null, 'name' => 'Hacksaw Frame 12"', 'model' => null, 'type' => 'Hacksaw', 'syn' => ['hacksaw', 'lagari bakal'], 'v' => [
            ['HACKSAW12', 'Hacksaw frame 12" with blade', null, ['length_mm' => 300], 1, 'pc', true, 380]]],
        ['cat' => 'screwdrivers', 'brand' => 'Stanley', 'name' => 'Screwdriver Set', 'model' => null, 'type' => 'Screwdriver set', 'syn' => ['screwdriver', 'distornilyador', 'phillips flat'], 'v' => [
            ['SDSET6', 'Stanley screwdriver set 6-piece (flat + Phillips)', null, ['kit' => '6 pcs'], 6, 'set', true, 450]]],
        ['cat' => 'wrenches-sockets', 'brand' => 'Tolsen', 'name' => 'Adjustable Wrench', 'model' => null, 'type' => 'Adjustable wrench', 'syn' => ['wrench', 'liyabe', 'adjustable spanner'], 'v' => [
            ['ADJW8', 'Tolsen adjustable wrench 8"', null, ['length_mm' => 200], 1, 'pc', true, 320],
            ['ADJW10', 'Tolsen adjustable wrench 10"', null, ['length_mm' => 250], 1, 'pc', true, 420],
            ['ADJW12', 'Tolsen adjustable wrench 12"', null, ['length_mm' => 300], 1, 'pc', true, 560]]],
        ['cat' => 'wrenches-sockets', 'brand' => 'Tolsen', 'name' => 'Socket Wrench Set', 'model' => null, 'type' => 'Socket set', 'syn' => ['socket set', 'liyabe set', 'ratchet set'], 'v' => [
            ['SOCK38', 'Tolsen socket set 3/8" drive, 20 pcs', null, ['drive_size' => '3/8"', 'kit' => '20 pcs'], 1, 'set', true, 1250],
            ['SOCK12', 'Tolsen socket set 1/2" drive, 24 pcs', null, ['drive_size' => '1/2"', 'kit' => '24 pcs'], 1, 'set', true, 1650]]],
        ['cat' => 'masonry-tools', 'brand' => null, 'name' => 'Pointing Trowel', 'model' => null, 'type' => 'Trowel', 'syn' => ['kutsara', 'trowel', 'pang palitada'], 'v' => [
            ['TROWEL7', 'Pointing trowel 7", wooden handle', null, ['length_mm' => 180], 1, 'pc', null, 120]]],
        ['cat' => 'masonry-tools', 'brand' => null, 'name' => 'Plastering Trowel', 'model' => null, 'type' => 'Trowel', 'syn' => ['palitada', 'plastering', 'steel trowel'], 'v' => [
            ['PLTROWEL', 'Plastering trowel 11" steel', null, ['length_mm' => 280], 1, 'pc', null, 180]]],
        ['cat' => 'masonry-tools', 'brand' => null, 'name' => 'Rubber Concrete Pail', 'model' => null, 'type' => 'Pail', 'syn' => ['balde', 'timba', 'mortar pail'], 'v' => [
            ['RUBPAIL', 'Rubber concrete pail (recycled tire)', null, ['material' => 'rubber'], 1, 'pc', null, 90]]],

        // ---------------- Fasteners (mostly sold loose, no barcode) ----------------
        ['cat' => 'fasteners', 'brand' => null, 'name' => 'Tek Screw (self-drilling) #10 x 1"', 'model' => null, 'type' => 'Self-drilling screw', 'syn' => ['tek screw', 'self drilling screw', 'turnilyo', 'roofing screw'], 'attrs' => ['material' => 'zinc'], 'v' => [
            ['TEK100', 'Tek screw #10 x 1" zinc, pack of 100', null, ['size' => '#10 x 1"', 'material' => 'zinc'], 100, 'pack', null, 220],
            ['TEK500', 'Tek screw #10 x 1" zinc, pack of 500', null, ['size' => '#10 x 1"', 'material' => 'zinc'], 500, 'pack', null, 890]]],
        ['cat' => 'nails', 'brand' => null, 'name' => 'Common Wire Nail', 'model' => null, 'type' => 'Common nail', 'syn' => ['nails', 'pako', 'lansang', 'wire nail'], 'v' => [
            ['NAIL1', 'Common wire nail 1" (per kg)', null, ['length_mm' => 25], 1, 'kg', null, 95],
            ['NAIL2', 'Common wire nail 2" (per kg)', null, ['length_mm' => 51], 1, 'kg', null, 88],
            ['NAIL3', 'Common wire nail 3" (per kg)', null, ['length_mm' => 76], 1, 'kg', null, 85],
            ['NAIL4', 'Common wire nail 4" (per kg)', null, ['length_mm' => 102], 1, 'kg', null, 85],
            ['NAIL3-BOX', 'Common wire nail 3", 25 kg box', null, ['length_mm' => 76], 25, 'box', null, 1950]]],
        ['cat' => 'nails', 'brand' => null, 'name' => 'Concrete Nail', 'model' => null, 'type' => 'Concrete nail', 'syn' => ['concrete nail', 'pako sa semento', 'lansang semento'], 'v' => [
            ['CNAIL1', 'Concrete nail 1" (per kg)', null, ['length_mm' => 25], 1, 'kg', null, 160],
            ['CNAIL2', 'Concrete nail 2" (per kg)', null, ['length_mm' => 51], 1, 'kg', null, 150]]],
        ['cat' => 'nails', 'brand' => null, 'name' => 'Umbrella Nail for GI Sheet', 'model' => null, 'type' => 'Roofing nail', 'syn' => ['umbrella nail', 'roofing nail', 'pako yero'], 'v' => [
            ['UMBNAIL', 'Umbrella roofing nail 2-1/2" (per kg)', null, ['length_mm' => 64], 1, 'kg', null, 110]]],
        ['cat' => 'screws', 'brand' => null, 'name' => 'Gypsum / Drywall Screw #6 x 1"', 'model' => null, 'type' => 'Drywall screw', 'syn' => ['gypsum screw', 'drywall screw', 'black screw'], 'v' => [
            ['GYPS100', 'Drywall screw #6 x 1" black, pack of 100', null, ['size' => '#6 x 1"'], 100, 'pack', null, 75],
            ['GYPS1000', 'Drywall screw #6 x 1" black, box of 1000', null, ['size' => '#6 x 1"'], 1000, 'box', null, 590]]],
        ['cat' => 'screws', 'brand' => null, 'name' => 'Wood Screw, flat head', 'model' => null, 'type' => 'Wood screw', 'syn' => ['wood screw', 'turnilyo kahoy'], 'v' => [
            ['WOODS1', 'Wood screw #8 x 1" flat head, pack of 100', null, ['size' => '#8 x 1"'], 100, 'pack', null, 95],
            ['WOODS2', 'Wood screw #8 x 2" flat head, pack of 100', null, ['size' => '#8 x 2"'], 100, 'pack', null, 145]]],
        ['cat' => 'bolts-nuts', 'brand' => null, 'name' => 'Hex Bolt with Nut, zinc plated', 'model' => null, 'type' => 'Hex bolt', 'syn' => ['bolt', 'hex bolt', 'perno', 'bolt and nut'], 'attrs' => ['material' => 'zinc'], 'v' => [
            ['BOLTM8', 'Hex bolt M8 x 40mm zinc with nut, pack of 50', null, ['thread_size' => 'M8', 'length_mm' => 40, 'material' => 'zinc'], 50, 'pack', null, 380],
            ['BOLTM10', 'Hex bolt M10 x 50mm zinc, pack of 50', null, ['thread_size' => 'M10', 'length_mm' => 50, 'material' => 'zinc'], 50, 'pack', null, 540],
            ['BOLTM10-PC', 'Hex bolt M10 x 50mm zinc with nut (per piece)', null, ['thread_size' => 'M10', 'length_mm' => 50, 'material' => 'zinc'], 1, 'pc', null, 14],
            ['BOLTM12', 'Hex bolt M12 x 75mm zinc with nut, pack of 25', null, ['thread_size' => 'M12', 'length_mm' => 75, 'material' => 'zinc'], 25, 'pack', null, 620]]],
        ['cat' => 'anchors', 'brand' => null, 'name' => 'Expansion Bolt (Sleeve Anchor)', 'model' => null, 'type' => 'Sleeve anchor', 'syn' => ['expansion bolt', 'anchor bolt', 'dynabolt'], 'v' => [
            ['EXPB8', 'Expansion bolt M8 x 60mm (per piece)', null, ['thread_size' => 'M8', 'length_mm' => 60], 1, 'pc', null, 18],
            ['EXPB10', 'Expansion bolt M10 x 80mm (per piece)', null, ['thread_size' => 'M10', 'length_mm' => 80], 1, 'pc', null, 28],
            ['EXPB12', 'Expansion bolt M12 x 100mm (per piece)', null, ['thread_size' => 'M12', 'length_mm' => 100], 1, 'pc', null, 45]]],
        ['cat' => 'anchors', 'brand' => null, 'name' => 'Plastic Wall Plug', 'model' => null, 'type' => 'Wall plug', 'syn' => ['wall plug', 'plastic anchor', 'tox'], 'v' => [
            ['PLUG6', 'Plastic wall plug 6mm, pack of 100', null, ['diameter_mm' => 6], 100, 'pack', null, 45],
            ['PLUG8', 'Plastic wall plug 8mm, pack of 100', null, ['diameter_mm' => 8], 100, 'pack', null, 60]]],
        ['cat' => 'tie-wire-rivets', 'brand' => null, 'name' => 'GI Tie Wire #16', 'model' => null, 'type' => 'Tie wire', 'syn' => ['tie wire', 'alambre', 'binding wire', 'pang tali bakal'], 'v' => [
            ['TIEW-KG', 'GI tie wire #16 (per kg)', null, ['material' => 'galvanized iron'], 1, 'kg', null, 95],
            ['TIEW-ROLL', 'GI tie wire #16, 25 kg roll', null, ['material' => 'galvanized iron'], 25, 'roll', null, 2150]]],
        ['cat' => 'tie-wire-rivets', 'brand' => null, 'name' => 'Blind Rivet 1/8"', 'model' => null, 'type' => 'Blind rivet', 'syn' => ['rivet', 'pop rivet', 'blind rivet'], 'v' => [
            ['RIVET18', 'Blind rivet 1/8" x 1/2" aluminum, pack of 100', null, ['size' => '1/8" x 1/2"', 'material' => 'aluminum'], 100, 'pack', null, 120]]],

        // ---------------- Plumbing ----------------
        ['cat' => 'pipes', 'brand' => 'Neltex', 'name' => 'PVC Pipe Series 1000', 'model' => null, 'type' => 'Pressure pipe', 'syn' => ['pvc pipe', 'tubo', 'blue pipe', 'water pipe'], 'v' => [
            ['PVC12', 'Neltex PVC pipe 1/2" x 3m', null, ['diameter_mm' => 12.7, 'length_mm' => 3000], 1, 'pc', null, 130],
            ['PVC34', 'Neltex PVC pipe 3/4" x 3m', null, ['diameter_mm' => 19.05, 'length_mm' => 3000], 1, 'pc', null, 175],
            ['PVC1', 'Neltex PVC pipe 1" x 3m', null, ['diameter_mm' => 25.4, 'length_mm' => 3000], 1, 'pc', null, 260]]],
        ['cat' => 'pipes', 'brand' => null, 'name' => 'PVC Sanitary Pipe (orange)', 'model' => null, 'type' => 'Drainage pipe', 'syn' => ['sanitary pipe', 'orange pipe', 'tubo kanal', 'drain pipe'], 'v' => [
            ['SAN2', 'PVC sanitary pipe 2" x 3m', null, ['diameter_mm' => 50.8, 'length_mm' => 3000], 1, 'pc', null, 230],
            ['SAN3', 'PVC sanitary pipe 3" x 3m', null, ['diameter_mm' => 76.2, 'length_mm' => 3000], 1, 'pc', null, 380],
            ['SAN4', 'PVC sanitary pipe 4" x 3m', null, ['diameter_mm' => 101.6, 'length_mm' => 3000], 1, 'pc', null, 520]]],
        ['cat' => 'pipes', 'brand' => null, 'name' => 'PPR Pipe PN20 (green)', 'model' => null, 'type' => 'PPR pipe', 'syn' => ['ppr pipe', 'hot water pipe', 'green pipe'], 'v' => [
            ['PPR20', 'PPR pipe 20mm x 4m PN20', null, ['diameter_mm' => 20, 'length_mm' => 4000], 1, 'pc', null, 260],
            ['PPR25', 'PPR pipe 25mm x 4m PN20', null, ['diameter_mm' => 25, 'length_mm' => 4000], 1, 'pc', null, 390]]],
        ['cat' => 'pipe-fittings', 'brand' => null, 'name' => 'PVC Elbow 90° (blue)', 'model' => null, 'type' => 'Elbow', 'syn' => ['elbow', 'siko', 'pvc elbow'], 'v' => [
            ['ELB12', 'PVC elbow 90° 1/2"', null, ['diameter_mm' => 12.7], 1, 'pc', null, 12],
            ['ELB34', 'PVC elbow 90° 3/4"', null, ['diameter_mm' => 19.05], 1, 'pc', null, 18]]],
        ['cat' => 'pipe-fittings', 'brand' => null, 'name' => 'PVC Tee (blue)', 'model' => null, 'type' => 'Tee', 'syn' => ['tee', 'pvc tee'], 'v' => [
            ['TEE12', 'PVC tee 1/2"', null, ['diameter_mm' => 12.7], 1, 'pc', null, 15],
            ['TEE34', 'PVC tee 3/4"', null, ['diameter_mm' => 19.05], 1, 'pc', null, 22]]],
        ['cat' => 'pipe-fittings', 'brand' => null, 'name' => 'PVC Coupling (blue)', 'model' => null, 'type' => 'Coupling', 'syn' => ['coupling', 'pvc coupling', 'dugtong'], 'v' => [
            ['CPL12', 'PVC coupling 1/2"', null, ['diameter_mm' => 12.7], 1, 'pc', null, 10]]],
        ['cat' => 'pipe-fittings', 'brand' => null, 'name' => 'Sanitary Elbow 90° (orange)', 'model' => null, 'type' => 'Elbow', 'syn' => ['sanitary elbow', 'siko kanal'], 'v' => [
            ['SELB4', 'Sanitary elbow 90° 4"', null, ['diameter_mm' => 101.6], 1, 'pc', null, 85]]],
        ['cat' => 'pipe-fittings', 'brand' => null, 'name' => 'GI Elbow 1/2"', 'model' => null, 'type' => 'Elbow', 'syn' => ['gi elbow', 'galvanized elbow'], 'v' => [
            ['GIELB12', 'GI elbow 90° 1/2"', null, ['diameter_mm' => 12.7, 'material' => 'galvanized iron'], 1, 'pc', null, 35]]],
        ['cat' => 'valves-faucets', 'brand' => null, 'name' => 'Brass Gate Valve 1/2"', 'model' => null, 'type' => 'Gate valve', 'syn' => ['gate valve', 'valve'], 'v' => [
            ['GATE12', 'Brass gate valve 1/2"', null, ['diameter_mm' => 12.7, 'material' => 'brass'], 1, 'pc', '480000200012', 320]]],
        ['cat' => 'valves-faucets', 'brand' => null, 'name' => 'PVC Ball Valve', 'model' => null, 'type' => 'Ball valve', 'syn' => ['ball valve', 'pvc valve'], 'v' => [
            ['BALLV12', 'PVC ball valve 1/2"', null, ['diameter_mm' => 12.7, 'material' => 'pvc'], 1, 'pc', null, 95],
            ['BALLV34', 'PVC ball valve 3/4"', null, ['diameter_mm' => 19.05, 'material' => 'pvc'], 1, 'pc', null, 125]]],
        ['cat' => 'valves-faucets', 'brand' => null, 'name' => 'Brass Hose Bibb Faucet 1/2"', 'model' => null, 'type' => 'Faucet', 'syn' => ['faucet', 'gripo', 'hose bibb', 'tap'], 'v' => [
            ['FAUCET12', 'Brass hose bibb faucet 1/2"', null, ['diameter_mm' => 12.7, 'material' => 'brass'], 1, 'pc', true, 280]]],
        ['cat' => 'valves-faucets', 'brand' => null, 'name' => 'Float Valve 1/2"', 'model' => null, 'type' => 'Float valve', 'syn' => ['float valve', 'boya', 'tank float'], 'v' => [
            ['FLOAT12', 'Float valve 1/2" with plastic ball', null, ['diameter_mm' => 12.7], 1, 'pc', true, 350]]],
        ['cat' => 'plumbing-sealants', 'brand' => null, 'name' => 'Teflon Tape', 'model' => null, 'type' => 'PTFE tape', 'syn' => ['plumbers tape', 'ptfe tape', 'teflon'], 'v' => [
            ['TEFLON', 'Teflon tape 1/2" x 10m', null, ['length_mm' => 10000], 1, 'pc', '480000200029', 25]]],
        ['cat' => 'plumbing-sealants', 'brand' => 'Neltex', 'name' => 'PVC Solvent Cement', 'model' => null, 'type' => 'Solvent cement', 'syn' => ['pvc glue', 'solvent cement', 'pandikit tubo'], 'v' => [
            ['SOLV100', 'Neltex PVC solvent cement 100cc', null, ['capacity_ml' => 100], 1, 'can', true, 85],
            ['SOLV400', 'Neltex PVC solvent cement 400cc', null, ['capacity_ml' => 400], 1, 'can', true, 260]]],

        // ---------------- Electrical ----------------
        ['cat' => 'wires-cables', 'brand' => 'Phelps Dodge', 'name' => 'THHN Wire (stranded)', 'model' => null, 'type' => 'Building wire', 'syn' => ['thhn', 'electrical wire', 'kawad', 'alambre kuryente'],
            'ph' => $elec, 'v' => [
                ['THHN20', 'Phelps Dodge THHN wire 2.0mm² x 75m', null, ['gauge_mm2' => 2.0, 'length_mm' => 75000], 1, 'roll', '480000300020', 3100],
                ['THHN35', 'Phelps Dodge THHN wire 3.5mm² x 75m', null, ['gauge_mm2' => 3.5, 'length_mm' => 75000], 1, 'roll', '480000300035', 4800],
                ['THHN55', 'Phelps Dodge THHN wire 5.5mm² x 75m', null, ['gauge_mm2' => 5.5, 'length_mm' => 75000], 1, 'roll', true, 7600],
                ['THHN20-M', 'Phelps Dodge THHN wire 2.0mm² (cut per meter)', null, ['gauge_mm2' => 2.0, 'length_mm' => 1000], 1, 'm', null, 45]]],
        ['cat' => 'wires-cables', 'brand' => null, 'name' => 'Flat Cord PDX #18 AWG', 'model' => null, 'type' => 'Flat cord', 'syn' => ['flat cord', 'pdx wire', 'duplex wire', 'kawad'], 'ph' => $elec, 'v' => [
            ['PDX18', 'Flat cord PDX #18 AWG (cut per meter)', null, ['gauge_mm2' => 0.8, 'length_mm' => 1000], 1, 'm', null, 18]]],
        ['cat' => 'breakers-panels', 'brand' => 'Royu', 'name' => 'Circuit Breaker 2-pole', 'model' => null, 'type' => 'Circuit breaker', 'syn' => ['breaker', 'circuit breaker', 'bolt-on breaker'], 'attrs' => ['poles' => 2], 'ph' => $elec, 'v' => [
            ['BRK15', 'Royu circuit breaker 2-pole 15A', null, ['amperage_a' => 15], 1, 'pc', true, 330],
            ['BRK20', 'Royu circuit breaker 2-pole 20A', null, ['amperage_a' => 20], 1, 'pc', '480000400020', 340],
            ['BRK30', 'Royu circuit breaker 2-pole 30A', null, ['amperage_a' => 30], 1, 'pc', '480000400030', 375],
            ['BRK40', 'Royu circuit breaker 2-pole 40A', null, ['amperage_a' => 40], 1, 'pc', true, 420],
            ['BRK60', 'Royu circuit breaker 2-pole 60A', null, ['amperage_a' => 60], 1, 'pc', true, 520]]],
        ['cat' => 'breakers-panels', 'brand' => null, 'name' => 'Panel Board, plug-in type', 'model' => null, 'type' => 'Panel board', 'syn' => ['panel board', 'breaker box', 'loadcenter'], 'ph' => $elec, 'v' => [
            ['PANEL4', 'Panel board plug-in, 4 branches', null, ['size' => '4 branches'], 1, 'pc', true, 1450],
            ['PANEL8', 'Panel board plug-in, 8 branches', null, ['size' => '8 branches'], 1, 'pc', true, 2350]]],
        ['cat' => 'wiring-devices', 'brand' => 'Panasonic', 'name' => 'Wall Switch, wide series', 'model' => null, 'type' => 'Wall switch', 'syn' => ['switch', 'swits', 'light switch'], 'ph' => $elec, 'v' => [
            ['SW1G', 'Panasonic wall switch 1-gang', null, ['gangs' => 1], 1, 'pc', true, 145],
            ['SW2G', 'Panasonic wall switch 2-gang', null, ['gangs' => 2], 1, 'pc', true, 195],
            ['SW3G', 'Panasonic wall switch 3-gang', null, ['gangs' => 3], 1, 'pc', true, 245]]],
        ['cat' => 'wiring-devices', 'brand' => 'Omni', 'name' => 'Duplex Convenience Outlet', 'model' => null, 'type' => 'Outlet', 'syn' => ['outlet', 'saksakan', 'socket', 'convenience outlet'], 'ph' => $elec, 'v' => [
            ['OUTLET2', 'Omni duplex convenience outlet, universal', null, ['gangs' => 2], 1, 'pc', true, 120]]],
        ['cat' => 'lighting', 'brand' => 'Philips', 'name' => 'LED Bulb 9W E27', 'model' => null, 'type' => 'LED bulb', 'syn' => ['led bulb', 'ilaw', 'light bulb', 'suga'], 'attrs' => ['wattage_w' => 9], 'v' => [
            ['LED9WW', 'Philips LED bulb 9W E27 warm white', null, ['wattage_w' => 9, 'color' => 'warm white'], 1, 'pc', '892829000091', 95],
            ['LED9WD', 'Philips LED bulb 9W E27 daylight', null, ['wattage_w' => 9, 'color' => 'daylight'], 1, 'pc', '892829000107', 95]]],
        ['cat' => 'lighting', 'brand' => 'Firefly', 'name' => 'LED Bulb E27 Daylight', 'model' => null, 'type' => 'LED bulb', 'syn' => ['led bulb', 'ilaw', 'suga'], 'v' => [
            ['FF-LED5', 'Firefly LED bulb 5W E27 daylight', null, ['wattage_w' => 5, 'color' => 'daylight'], 1, 'pc', true, 95],
            ['FF-LED9', 'Firefly LED bulb 9W E27 daylight', null, ['wattage_w' => 9, 'color' => 'daylight'], 1, 'pc', true, 135],
            ['FF-LED12', 'Firefly LED bulb 12W E27 daylight', null, ['wattage_w' => 12, 'color' => 'daylight'], 1, 'pc', true, 180]]],
        ['cat' => 'lighting', 'brand' => 'Firefly', 'name' => 'LED T8 Tube 4ft', 'model' => null, 'type' => 'LED tube', 'syn' => ['led tube', 'fluorescent', 'tube light'], 'v' => [
            ['FF-T8', 'Firefly LED T8 tube 18W 4ft daylight', null, ['wattage_w' => 18, 'length_mm' => 1200, 'color' => 'daylight'], 1, 'pc', true, 280]]],
        ['cat' => 'conduits-boxes', 'brand' => null, 'name' => 'PVC Electrical Conduit (orange)', 'model' => null, 'type' => 'Conduit', 'syn' => ['conduit', 'pvc conduit', 'orange pipe kuryente'], 'v' => [
            ['COND12', 'PVC electrical conduit 1/2" x 3m', null, ['diameter_mm' => 12.7, 'length_mm' => 3000], 1, 'pc', null, 55],
            ['COND34', 'PVC electrical conduit 3/4" x 3m', null, ['diameter_mm' => 19.05, 'length_mm' => 3000], 1, 'pc', null, 75]]],
        ['cat' => 'conduits-boxes', 'brand' => null, 'name' => 'Utility Box (plastic)', 'model' => null, 'type' => 'Electrical box', 'syn' => ['utility box', 'junction box', 'switch box'], 'v' => [
            ['UBOX24', 'Plastic utility box 2" x 4"', null, ['size' => '2x4'], 1, 'pc', null, 18],
            ['JBOX44', 'Plastic junction box 4" x 4"', null, ['size' => '4x4'], 1, 'pc', null, 25]]],
        ['cat' => 'extension-cords', 'brand' => 'Omni', 'name' => 'Extension Cord 4-gang', 'model' => null, 'type' => 'Extension cord', 'syn' => ['extension', 'extension cord', 'saksakan'], 'ph' => $elec, 'v' => [
            ['EXT3M', 'Omni extension cord 4-gang, 3m', null, ['gangs' => 4, 'length_mm' => 3000], 1, 'pc', true, 450],
            ['EXT5M', 'Omni extension cord 4-gang, 5m', null, ['gangs' => 4, 'length_mm' => 5000], 1, 'pc', true, 560]]],

        // ---------------- Construction materials (bulk, low margin, mostly unbarcoded) ----------------
        ['cat' => 'cement', 'brand' => 'Republic', 'name' => 'Portland Cement Type 1 40kg', 'model' => null, 'type' => 'Portland cement', 'syn' => ['semento', 'cement', 'simento'],
            'ph' => ['dti_bps' => 'PS/ICC mark required', 'standard' => 'PNS 07 Type 1'], 'v' => [
                ['CEMENT', 'Republic Portland cement 40kg bag', null, ['weight_kg' => 40], 1, 'bag', null, 265]]],
        ['cat' => 'cement', 'brand' => 'APO', 'name' => 'Portland Cement 40kg', 'model' => null, 'type' => 'Portland cement', 'syn' => ['semento', 'cement', 'simento', 'apo cement'],
            'ph' => ['dti_bps' => 'PS/ICC mark required'], 'v' => [
                ['APO40', 'APO Portland cement 40kg bag', null, ['weight_kg' => 40], 1, 'bag', null, 255]]],
        ['cat' => 'steel-bars', 'brand' => null, 'name' => 'Deformed Steel Bar 6m', 'model' => null, 'type' => 'Rebar', 'syn' => ['rebar', 'bakal', 'steel bar', 'deformed bar', 'varilla'],
            'ph' => ['dti_bps' => 'PS/ICC mark required', 'standard' => 'PNS 49'], 'v' => [
                ['REBAR10', 'Deformed rebar 10mm x 6m Grade 40', null, ['diameter_mm' => 10, 'length_mm' => 6000, 'grade' => '40'], 1, 'pc', null, 235],
                ['REBAR10-G33', 'Deformed rebar 10mm x 6m Grade 33', null, ['diameter_mm' => 10, 'length_mm' => 6000, 'grade' => '33'], 1, 'pc', null, 205],
                ['REBAR12', 'Deformed rebar 12mm x 6m Grade 40', null, ['diameter_mm' => 12, 'length_mm' => 6000, 'grade' => '40'], 1, 'pc', null, 335],
                ['REBAR16', 'Deformed rebar 16mm x 6m Grade 40', null, ['diameter_mm' => 16, 'length_mm' => 6000, 'grade' => '40'], 1, 'pc', null, 590]]],
        ['cat' => 'steel-bars', 'brand' => null, 'name' => 'Angle Bar 6m', 'model' => null, 'type' => 'Angle bar', 'syn' => ['angle bar', 'anggulo', 'steel angle'], 'v' => [
            ['ANGLE112', 'Angle bar 1-1/2" x 1-1/2" x 1/8" x 6m', null, ['size' => '1-1/2 x 1-1/2 x 1/8', 'length_mm' => 6000], 1, 'pc', null, 680]]],
        ['cat' => 'hollow-blocks', 'brand' => null, 'name' => 'Concrete Hollow Block (CHB)', 'model' => null, 'type' => 'Hollow block', 'syn' => ['chb', 'hollow blocks', 'blocks', 'bloke'], 'v' => [
            ['CHB4', 'Concrete hollow block 4"', null, ['width_mm' => 100], 1, 'pc', null, 16.5],
            ['CHB5', 'Concrete hollow block 5"', null, ['width_mm' => 125], 1, 'pc', null, 19],
            ['CHB6', 'Concrete hollow block 6"', null, ['width_mm' => 150], 1, 'pc', null, 26]]],
        ['cat' => 'aggregates', 'brand' => null, 'name' => 'Washed Sand', 'model' => null, 'type' => 'Sand', 'syn' => ['sand', 'buhangin', 'balas', 'sand for plastering'], 'v' => [
            ['SAND-CUM', 'Washed sand per cubic meter (delivered)', null, ['size' => '1 cu.m.'], 1, 'cu.m', null, 1500],
            ['SAND-SACK', 'Washed sand, repacked sack (~40kg)', null, ['weight_kg' => 40], 1, 'sack', null, 75]]],
        ['cat' => 'aggregates', 'brand' => null, 'name' => 'Crushed Gravel 3/4"', 'model' => null, 'type' => 'Gravel', 'syn' => ['gravel', 'graba', 'crushed stone', 'bato'], 'v' => [
            ['GRAVEL-CUM', 'Crushed gravel 3/4" per cubic meter (delivered)', null, ['size' => '1 cu.m.'], 1, 'cu.m', null, 1800]]],
        ['cat' => 'lumber', 'brand' => null, 'name' => 'Marine Plywood 4x8', 'model' => null, 'type' => 'Plywood', 'syn' => ['plywood', 'marine ply', 'playwud'], 'v' => [
            ['PLY14', 'Marine plywood 1/4" x 4ft x 8ft', null, ['thickness_mm' => 6, 'size' => '4x8'], 1, 'sheet', null, 520],
            ['PLY12', 'Marine plywood 1/2" x 4ft x 8ft', null, ['thickness_mm' => 12, 'size' => '4x8'], 1, 'sheet', null, 895],
            ['PLY34', 'Marine plywood 3/4" x 4ft x 8ft', null, ['thickness_mm' => 18, 'size' => '4x8'], 1, 'sheet', null, 1450]]],
        ['cat' => 'lumber', 'brand' => null, 'name' => 'Coco Lumber 10ft', 'model' => null, 'type' => 'Lumber', 'syn' => ['coco lumber', 'kahoy', 'lubi', 'coconut lumber'], 'v' => [
            ['COCO22', 'Coco lumber 2" x 2" x 10ft', null, ['size' => '2x2', 'length_mm' => 3048], 1, 'pc', null, 95],
            ['COCO23', 'Coco lumber 2" x 3" x 10ft', null, ['size' => '2x3', 'length_mm' => 3048], 1, 'pc', null, 145],
            ['COCO24', 'Coco lumber 2" x 4" x 10ft', null, ['size' => '2x4', 'length_mm' => 3048], 1, 'pc', null, 190]]],
        ['cat' => 'roofing', 'brand' => null, 'name' => 'GI Corrugated Roofing Sheet', 'model' => null, 'type' => 'Roofing sheet', 'syn' => ['yero', 'gi sheet', 'roofing', 'atop'], 'v' => [
            ['GI26-8', 'GI corrugated sheet gauge 26 x 8ft', null, ['sheet_gauge' => 26, 'length_mm' => 2438], 1, 'sheet', null, 420],
            ['GI26-10', 'GI corrugated sheet gauge 26 x 10ft', null, ['sheet_gauge' => 26, 'length_mm' => 3048], 1, 'sheet', null, 525],
            ['GI26-12', 'GI corrugated sheet gauge 26 x 12ft', null, ['sheet_gauge' => 26, 'length_mm' => 3658], 1, 'sheet', null, 630],
            ['GI24-10', 'GI corrugated sheet gauge 24 x 10ft', null, ['sheet_gauge' => 24, 'length_mm' => 3048], 1, 'sheet', null, 690]]],

        // ---------------- Paint & coatings ----------------
        ['cat' => 'latex-paint', 'brand' => 'Boysen', 'name' => 'Permacoat Flat Latex White', 'model' => null, 'type' => 'Latex paint', 'syn' => ['paint', 'pintura', 'latex', 'pintal'], 'attrs' => ['color' => 'white'], 'v' => [
            ['BOY1L', 'Boysen Permacoat flat latex white 1L', null, ['color' => 'white', 'capacity_l' => 1], 1, 'can', '480000500011', 330],
            ['BOY4L', 'Boysen Permacoat flat latex white 4L', null, ['color' => 'white', 'capacity_l' => 4], 1, 'can', '480000500048', 1095],
            ['BOY16L', 'Boysen Permacoat flat latex white 16L', null, ['color' => 'white', 'capacity_l' => 16], 1, 'pail', '480000500165', 3650]]],
        ['cat' => 'enamel-paint', 'brand' => 'Boysen', 'name' => 'Quick Drying Enamel', 'model' => null, 'type' => 'Enamel paint', 'syn' => ['enamel', 'qde', 'pintura bakal', 'oil paint'], 'v' => [
            ['QDE-W1L', 'Boysen Quick Drying Enamel white 1L', null, ['color' => 'white', 'capacity_l' => 1], 1, 'can', true, 390],
            ['QDE-B1L', 'Boysen Quick Drying Enamel black 1L', null, ['color' => 'black', 'capacity_l' => 1], 1, 'can', true, 390],
            ['QDE-W4L', 'Boysen Quick Drying Enamel white 4L', null, ['color' => 'white', 'capacity_l' => 4], 1, 'can', true, 1390]]],
        ['cat' => 'primers-thinners', 'brand' => 'Boysen', 'name' => 'Red Oxide Metal Primer', 'model' => null, 'type' => 'Metal primer', 'syn' => ['primer', 'red oxide', 'anti rust'], 'v' => [
            ['REDOX1L', 'Boysen red oxide metal primer 1L', null, ['color' => 'red oxide', 'capacity_l' => 1], 1, 'can', true, 360]]],
        ['cat' => 'primers-thinners', 'brand' => 'Boysen', 'name' => 'Paint Thinner', 'model' => null, 'type' => 'Thinner', 'syn' => ['thinner', 'paint thinner', 'solvent'], 'v' => [
            ['THIN1L', 'Boysen paint thinner 1L', null, ['capacity_l' => 1], 1, 'bottle', true, 165],
            ['THIN4L', 'Boysen paint thinner 4L', null, ['capacity_l' => 4], 1, 'can', true, 560]]],
        ['cat' => 'waterproofing', 'brand' => 'Vulcaseal', 'name' => 'Waterproofing Sealant', 'model' => null, 'type' => 'Waterproofing', 'syn' => ['vulcaseal', 'sealant', 'leak sealer', 'tulo sa atop'], 'v' => [
            ['VULC025', 'Vulcaseal waterproofing sealant 1/4 L', null, ['capacity_l' => 0.25], 1, 'can', true, 190],
            ['VULC1L', 'Vulcaseal waterproofing sealant 1L', null, ['capacity_l' => 1], 1, 'can', true, 560]]],
        ['cat' => 'painting-tools', 'brand' => null, 'name' => 'Paint Brush', 'model' => null, 'type' => 'Paint brush', 'syn' => ['brush', 'brotsa', 'paint brush'], 'v' => [
            ['BRUSH2', 'Paint brush 2"', null, ['width_mm' => 50], 1, 'pc', null, 45],
            ['BRUSH3', 'Paint brush 3"', null, ['width_mm' => 75], 1, 'pc', null, 65],
            ['BRUSH4', 'Paint brush 4"', null, ['width_mm' => 100], 1, 'pc', null, 85]]],
        ['cat' => 'painting-tools', 'brand' => null, 'name' => 'Paint Roller 7" with Tray', 'model' => null, 'type' => 'Paint roller', 'syn' => ['roller', 'paint roller'], 'v' => [
            ['ROLLER7', 'Paint roller 7" with tray', null, ['width_mm' => 180], 1, 'set', true, 180]]],

        // ---------------- Safety ----------------
        ['cat' => 'head-protection', 'brand' => null, 'name' => 'Safety Hard Hat', 'model' => null, 'type' => 'Hard hat', 'syn' => ['helmet', 'hard hat', 'safety helmet'], 'v' => [
            ['HHAT-Y', 'Safety hard hat yellow', null, ['color' => 'yellow'], 1, 'pc', '480000600013', 195],
            ['HHAT-W', 'Safety hard hat white', null, ['color' => 'white'], 1, 'pc', '480000600020', 195]]],
        ['cat' => 'hand-protection', 'brand' => null, 'name' => 'Leather Work Gloves', 'model' => null, 'type' => 'Work gloves', 'syn' => ['gloves', 'guwantes', 'welding gloves'], 'v' => [
            ['GLOVE', 'Leather work gloves, pair', null, ['material' => 'leather'], 1, 'pair', null, 150]]],
        ['cat' => 'hand-protection', 'brand' => null, 'name' => 'Cotton Knitted Gloves', 'model' => null, 'type' => 'Work gloves', 'syn' => ['cotton gloves', 'guwantes', 'gwantes'], 'v' => [
            ['CGLOVE-PR', 'Cotton knitted gloves, pair', null, ['material' => 'cotton'], 1, 'pair', null, 18],
            ['CGLOVE-DZ', 'Cotton knitted gloves, dozen pairs', null, ['material' => 'cotton'], 12, 'dozen', null, 180]]],
        ['cat' => 'eye-face', 'brand' => null, 'name' => 'Safety Goggles', 'model' => null, 'type' => 'Goggles', 'syn' => ['goggles', 'safety glasses'], 'v' => [
            ['GOGGLES', 'Safety goggles, clear, anti-fog', null, ['color' => 'clear'], 1, 'pc', true, 120]]],
        ['cat' => 'eye-face', 'brand' => null, 'name' => 'Auto-darkening Welding Helmet', 'model' => null, 'type' => 'Welding helmet', 'syn' => ['welding mask', 'welding helmet', 'maskara'], 'v' => [
            ['WMASK', 'Auto-darkening welding helmet', null, [], 1, 'pc', true, 950]]],
        ['cat' => 'foot-protection', 'brand' => null, 'name' => 'Steel Toe Safety Shoes', 'model' => null, 'type' => 'Safety shoes', 'syn' => ['safety shoes', 'steel toe', 'sapatos'], 'v' => [
            ['SHOE40', 'Steel toe safety shoes, size 40', null, ['shoe_size' => 40], 1, 'pair', true, 1350],
            ['SHOE41', 'Steel toe safety shoes, size 41', null, ['shoe_size' => 41], 1, 'pair', true, 1350],
            ['SHOE42', 'Steel toe safety shoes, size 42', null, ['shoe_size' => 42], 1, 'pair', true, 1350],
            ['SHOE43', 'Steel toe safety shoes, size 43', null, ['shoe_size' => 43], 1, 'pair', true, 1350]]],
        ['cat' => 'respiratory', 'brand' => null, 'name' => 'N95 Dust Mask', 'model' => null, 'type' => 'Respirator', 'syn' => ['mask', 'dust mask', 'n95'], 'v' => [
            ['N95-PC', 'N95 dust mask (per piece)', null, [], 1, 'pc', null, 30],
            ['N95-BOX', 'N95 dust mask, box of 20', null, [], 20, 'box', true, 450]]],

        // ---------------- Measuring ----------------
        ['cat' => 'tape-measures', 'brand' => 'Stanley', 'name' => 'PowerLock Tape Measure', 'model' => null, 'type' => 'Tape measure', 'syn' => ['tape measure', 'metro', 'medida'], 'v' => [
            ['TAPE3', 'Stanley PowerLock tape measure 3m', null, ['length_mm' => 3000], 1, 'pc', true, 165],
            ['TAPE5', 'Stanley PowerLock tape measure 5m', null, ['length_mm' => 5000], 1, 'pc', '076174330001', 235],
            ['TAPE8', 'Stanley PowerLock tape measure 8m', null, ['length_mm' => 8000], 1, 'pc', '076174330087', 420]]],
        ['cat' => 'tape-measures', 'brand' => null, 'name' => 'Fiberglass Long Tape', 'model' => null, 'type' => 'Long tape', 'syn' => ['long tape', 'metro', 'surveyor tape'], 'v' => [
            ['LTAPE30', 'Fiberglass long tape 30m', null, ['length_mm' => 30000], 1, 'pc', true, 450],
            ['LTAPE50', 'Fiberglass long tape 50m', null, ['length_mm' => 50000], 1, 'pc', true, 650]]],
        ['cat' => 'levels-squares', 'brand' => null, 'name' => 'Aluminum Spirit Level', 'model' => null, 'type' => 'Spirit level', 'syn' => ['level', 'liyabel', 'lebel', 'spirit level'], 'v' => [
            ['LEVEL24', 'Aluminum spirit level 24"', null, ['length_mm' => 600], 1, 'pc', true, 380]]],
        ['cat' => 'levels-squares', 'brand' => null, 'name' => 'Steel Carpenter Square', 'model' => null, 'type' => 'Square', 'syn' => ['escuadra', 'square', 'framing square'], 'v' => [
            ['SQUARE', 'Steel carpenter square 16" x 24"', null, ['size' => '16x24'], 1, 'pc', null, 250]]],
        ['cat' => 'levels-squares', 'brand' => null, 'name' => 'Chalk Line Reel', 'model' => null, 'type' => 'Chalk line', 'syn' => ['chalk line', 'hilo', 'pisi'], 'v' => [
            ['CHALK', 'Chalk line reel 30m with chalk', null, ['length_mm' => 30000], 1, 'pc', true, 150]]],

        // ---------------- Automotive ----------------
        ['cat' => 'engine-oil', 'brand' => 'Motul', 'name' => '5100 4T 10W-40 Motor Oil', 'model' => null, 'type' => 'Motorcycle oil', 'syn' => ['engine oil', 'langis', 'motor oil', 'oil sa motor'], 'attrs' => ['viscosity' => '10W-40'], 'v' => [
            ['MOTUL08', 'Motul 5100 4T 10W-40 0.8L', null, ['capacity_l' => 0.8], 1, 'bottle', true, 340],
            ['MOTUL1', 'Motul 5100 4T 10W-40 1L', null, ['capacity_l' => 1], 1, 'bottle', '354400510011', 410]]],
        ['cat' => 'engine-oil', 'brand' => 'Petron', 'name' => 'Sprint 4T Motorcycle Oil', 'model' => null, 'type' => 'Motorcycle oil', 'syn' => ['engine oil', 'langis', 'motor oil'], 'v' => [
            ['SPRINT1', 'Petron Sprint 4T 1L', null, ['capacity_l' => 1], 1, 'bottle', true, 260]]],
        ['cat' => 'car-batteries', 'brand' => 'Motolite', 'name' => 'Gold Maintenance-free Car Battery', 'model' => null, 'type' => 'Car battery', 'syn' => ['battery', 'baterya', 'car battery'], 'warranty' => 'Manufacturer warranty, see store', 'v' => [
            ['MOTO-NS40', 'Motolite Gold NS40', 'NS40', ['size' => 'NS40', 'voltage_v' => 12], 1, 'pc', true, 5200],
            ['MOTO-NS60', 'Motolite Gold NS60', 'NS60', ['size' => 'NS60', 'voltage_v' => 12], 1, 'pc', true, 6500]]],

        // ---------------- Outdoor & garden ----------------
        ['cat' => 'hoses-watering', 'brand' => null, 'name' => 'Garden Hose 1/2"', 'model' => null, 'type' => 'Garden hose', 'syn' => ['hose', 'hose pang-hardin', 'hos'], 'v' => [
            ['HOSE15', 'Garden hose 1/2" x 15m', null, ['diameter_mm' => 12.7, 'length_mm' => 15000], 1, 'roll', null, 380],
            ['HOSE30', 'Garden hose 1/2" x 30m', null, ['diameter_mm' => 12.7, 'length_mm' => 30000], 1, 'roll', null, 650]]],
        ['cat' => 'hoses-watering', 'brand' => null, 'name' => 'Knapsack Sprayer', 'model' => null, 'type' => 'Sprayer', 'syn' => ['sprayer', 'pang spray', 'knapsack'], 'v' => [
            ['SPRAY16', 'Manual knapsack sprayer 16L', null, ['capacity_l' => 16], 1, 'pc', true, 1200]]],
        ['cat' => 'garden-tools', 'brand' => null, 'name' => 'Shovel', 'model' => null, 'type' => 'Shovel', 'syn' => ['pala', 'shovel'], 'v' => [
            ['SHOVEL-P', 'Shovel, pointed, wooden handle', null, ['size' => 'pointed'], 1, 'pc', null, 280],
            ['SHOVEL-S', 'Shovel, square, wooden handle', null, ['size' => 'square'], 1, 'pc', null, 280]]],
        ['cat' => 'garden-tools', 'brand' => null, 'name' => 'Bolo Knife', 'model' => null, 'type' => 'Bolo', 'syn' => ['bolo', 'itak', 'sundang', 'machete'], 'v' => [
            ['BOLO', 'Bolo knife 18" with wooden handle', null, ['length_mm' => 450], 1, 'pc', null, 350]]],
        ['cat' => 'garden-tools', 'brand' => null, 'name' => 'Wheelbarrow', 'model' => null, 'type' => 'Wheelbarrow', 'syn' => ['kartilya', 'wheelbarrow', 'karetilya'], 'v' => [
            ['WBARROW', 'Wheelbarrow, steel tray, pneumatic tire', null, ['capacity_l' => 65], 1, 'pc', null, 2400]]],
        ['cat' => 'outdoor-power', 'brand' => null, 'name' => 'Brush Cutter (grass cutter) 2-stroke', 'model' => null, 'type' => 'Brush cutter', 'syn' => ['grass cutter', 'brush cutter', 'pang sagbot'],
            'safety' => 'Wear face shield and boots. Mix fuel 25:1 with 2T oil.', 'warranty' => '6 months', 'v' => [
                ['BCUT52', 'Brush cutter 52cc 2-stroke', null, ['size' => '52cc'], 1, 'pc', true, 6500]]],

        // ---------------- Building supplies ----------------
        ['cat' => 'adhesives-sealants', 'brand' => null, 'name' => 'Tile Adhesive 20kg', 'model' => null, 'type' => 'Tile adhesive', 'syn' => ['tile glue', 'pandikit tiles', 'tile cement'], 'v' => [
            ['TILEADH', 'Tile adhesive 20kg bag', null, ['weight_kg' => 20], 1, 'bag', null, 465]]],
        ['cat' => 'adhesives-sealants', 'brand' => 'Pioneer', 'name' => 'Epoxy A+B', 'model' => null, 'type' => 'Epoxy', 'syn' => ['epoxy', 'pandikit', 'pioneer epoxy'], 'v' => [
            ['EPOXY-Q', 'Pioneer epoxy A+B, 1/4 pint set', null, ['size' => '1/4 pint'], 1, 'set', true, 120]]],
        ['cat' => 'adhesives-sealants', 'brand' => 'Rugby', 'name' => 'Contact Cement', 'model' => null, 'type' => 'Contact cement', 'syn' => ['rugby', 'contact cement', 'pandikit sapatos'], 'v' => [
            ['RUGBY100', 'Rugby contact cement 100cc', null, ['capacity_ml' => 100], 1, 'can', true, 75],
            ['RUGBY1L', 'Rugby contact cement 1L', null, ['capacity_ml' => 1000], 1, 'can', true, 420]]],
        ['cat' => 'adhesives-sealants', 'brand' => null, 'name' => 'Silicone Sealant 300ml', 'model' => null, 'type' => 'Silicone sealant', 'syn' => ['silicone', 'sealant', 'caulk'], 'v' => [
            ['SILI-CL', 'Silicone sealant clear 300ml', null, ['color' => 'clear', 'capacity_ml' => 300], 1, 'tube', true, 180],
            ['SILI-WH', 'Silicone sealant white 300ml', null, ['color' => 'white', 'capacity_ml' => 300], 1, 'tube', true, 180]]],
        ['cat' => 'door-hardware', 'brand' => null, 'name' => 'Steel Door Hinge 3"', 'model' => null, 'type' => 'Hinge', 'syn' => ['hinge', 'bisagra'], 'v' => [
            ['HINGE3', 'Steel door hinge 3", pack of 2', null, ['length_mm' => 76], 2, 'pack', '480000700032', 120]]],
        ['cat' => 'door-hardware', 'brand' => null, 'name' => 'Brass Padlock', 'model' => null, 'type' => 'Padlock', 'syn' => ['padlock', 'kandado', 'lock'], 'v' => [
            ['PADLOCK40', 'Brass padlock 40mm, 3 keys', null, ['width_mm' => 40], 1, 'pc', true, 280],
            ['PADLOCK50', 'Brass padlock 50mm, 3 keys', null, ['width_mm' => 50], 1, 'pc', true, 380]]],
        ['cat' => 'door-hardware', 'brand' => null, 'name' => 'Entrance Door Knob Lockset', 'model' => null, 'type' => 'Lockset', 'syn' => ['door knob', 'seradura', 'lockset'], 'v' => [
            ['KNOB', 'Entrance door knob lockset, stainless', null, ['material' => 'stainless'], 1, 'set', true, 650]]],
        ['cat' => 'door-hardware', 'brand' => null, 'name' => 'Barrel Bolt', 'model' => null, 'type' => 'Barrel bolt', 'syn' => ['barrel bolt', 'trangka', 'door bolt'], 'v' => [
            ['BBOLT4', 'Barrel bolt 4"', null, ['length_mm' => 100], 1, 'pc', null, 65]]],
        ['cat' => 'tiles-grout', 'brand' => null, 'name' => 'Tile Grout 2kg', 'model' => null, 'type' => 'Grout', 'syn' => ['grout', 'tile grout'], 'v' => [
            ['GROUT-WH', 'Tile grout white 2kg', null, ['color' => 'white', 'weight_kg' => 2], 1, 'bag', true, 75],
            ['GROUT-GR', 'Tile grout gray 2kg', null, ['color' => 'gray', 'weight_kg' => 2], 1, 'bag', true, 75]]],
        ['cat' => 'ceiling-drywall', 'brand' => null, 'name' => 'Gypsum Board 4x8', 'model' => null, 'type' => 'Gypsum board', 'syn' => ['gypsum', 'drywall', 'ceiling board'], 'v' => [
            ['GYPSUM12', 'Gypsum board 1/2" (12mm) x 4ft x 8ft', null, ['thickness_mm' => 12, 'size' => '4x8'], 1, 'sheet', null, 520]]],
        ['cat' => 'ceiling-drywall', 'brand' => null, 'name' => 'Fiber Cement Board 4x8', 'model' => null, 'type' => 'Fiber cement board', 'syn' => ['fiber cement', 'ficem', 'ceiling board'], 'v' => [
            ['FCB45', 'Fiber cement board 4.5mm x 4ft x 8ft', null, ['thickness_mm' => 4.5, 'size' => '4x8'], 1, 'sheet', null, 560]]],
        ['cat' => 'ceiling-drywall', 'brand' => null, 'name' => 'Metal Furring', 'model' => null, 'type' => 'Furring', 'syn' => ['metal furring', 'ceiling frame', 'furring'], 'v' => [
            ['FURRING', 'Metal furring channel 5m', null, ['length_mm' => 5000], 1, 'pc', null, 95]]],

        // ---------------- Accessories & parts ----------------
        ['cat' => 'cutting-discs', 'brand' => null, 'name' => 'Cutting Disc 4" for Metal', 'model' => null, 'type' => 'Cutting disc', 'syn' => ['cutting disc', 'disc', 'pang putol', 'cut off wheel'], 'compat' => ['4" (100mm) angle grinders'], 'v' => [
            ['CDISC4', 'Metal cutting disc 4" x 1mm (per piece)', null, ['disc_mm' => 100, 'thickness_mm' => 1], 1, 'pc', null, 35],
            ['CDISC4-BOX', 'Metal cutting disc 4" x 1mm, box of 25', null, ['disc_mm' => 100, 'thickness_mm' => 1], 25, 'box', true, 750]]],
        ['cat' => 'cutting-discs', 'brand' => null, 'name' => 'Diamond Blade 4" Segmented', 'model' => null, 'type' => 'Diamond blade', 'syn' => ['diamond blade', 'pang putol tiles', 'concrete blade'], 'compat' => ['4" (100mm) angle grinders'], 'v' => [
            ['DIAM4', 'Diamond blade 4" segmented, dry cut', null, ['disc_mm' => 100], 1, 'pc', true, 250]]],
        ['cat' => 'drill-bits', 'brand' => 'Bosch', 'name' => 'Drill Bit Set 5-piece', 'model' => null, 'type' => 'Drill bit set', 'syn' => ['drill bits', 'mecha', 'bits'], 'v' => [
            ['BITSET5', 'Bosch drill bit set 5-piece (4-8mm)', null, [], 5, 'set', '316192100055', 590]]],
        ['cat' => 'drill-bits', 'brand' => null, 'name' => 'Masonry Drill Bit', 'model' => null, 'type' => 'Masonry bit', 'syn' => ['concrete bit', 'masonry bit', 'mecha semento'], 'v' => [
            ['MBIT6', 'Masonry drill bit 6mm', null, ['diameter_mm' => 6], 1, 'pc', null, 45],
            ['MBIT8', 'Masonry drill bit 8mm', null, ['diameter_mm' => 8], 1, 'pc', null, 60],
            ['MBIT10', 'Masonry drill bit 10mm', null, ['diameter_mm' => 10], 1, 'pc', null, 85]]],
        ['cat' => 'carbon-brushes', 'brand' => 'Makita', 'name' => 'Carbon Brush CB-440', 'model' => 'CB-440', 'type' => 'Carbon brush', 'syn' => ['carbon brush', 'replacement brush', 'uling'], 'v' => [
            ['CB440', 'Makita carbon brush CB-440 (pair)', 'CB-440', [], 2, 'pair', '088381100440', 280]]],
        ['cat' => 'tool-batteries', 'brand' => 'DeWalt', 'name' => '20V MAX Battery 2.0Ah', 'model' => 'DCB203', 'type' => 'Tool battery', 'syn' => ['battery', 'baterya drill', 'li-ion pack'], 'compat' => ['DeWalt 18V/20V MAX tools'], 'v' => [
            ['DCB203', 'DeWalt DCB203 20V MAX Li-ion battery 2.0Ah', 'DCB203', ['voltage_v' => 20, 'battery_ah' => 2.0, 'battery_type' => 'Li-ion'], 1, 'pc', true, 3800]]],
        ['cat' => 'saw-blades', 'brand' => null, 'name' => 'Circular Saw Blade 7-1/4" TCT', 'model' => null, 'type' => 'Saw blade', 'syn' => ['saw blade', 'blade', 'lagari blade'], 'compat' => ['7-1/4" (185mm) circular saws'], 'v' => [
            ['BLADE24T', 'Circular saw blade 7-1/4" 24T', null, ['disc_mm' => 185, 'teeth' => 24], 1, 'pc', true, 450],
            ['BLADE40T', 'Circular saw blade 7-1/4" 40T', null, ['disc_mm' => 185, 'teeth' => 40], 1, 'pc', true, 650]]],
        ['cat' => 'saw-blades', 'brand' => 'Bosch', 'name' => 'Jigsaw Blade T101B', 'model' => 'T101B', 'type' => 'Jigsaw blade', 'syn' => ['jigsaw blade', 'blade'], 'compat' => ['T-shank jigsaws'], 'v' => [
            ['T101B', 'Bosch jigsaw blade T101B for wood, pack of 5', 'T101B', [], 5, 'pack', true, 280]]],
    ],
];
