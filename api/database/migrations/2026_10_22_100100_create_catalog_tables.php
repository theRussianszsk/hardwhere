<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shared canonical catalog: no tenant data lives here except attribution on tenant-submitted candidates/aliases/images. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $t->string('slug')->unique();
            $t->string('name');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestampsTz();
        });

        // Category-specific attribute schema; is_variant_axis marks attributes that make two products materially different.
        Schema::create('attribute_definitions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('key', 40);
            $t->string('label');
            $t->string('data_type', 12)->default('text');
            $t->string('unit', 12)->nullable();
            $t->boolean('is_variant_axis')->default(false);
            $t->timestampsTz();
            $t->unique(['category_id', 'key']);
        });
        DB::statement("ALTER TABLE attribute_definitions ADD CONSTRAINT attribute_definitions_type_chk CHECK (data_type IN ('text','number','boolean'))");

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('category_id')->constrained();
            $t->string('brand')->nullable();
            $t->string('manufacturer')->nullable();
            $t->string('name');
            $t->string('model_number')->nullable();
            $t->string('product_type')->nullable();
            $t->text('description')->nullable();
            $t->jsonb('attributes')->default('{}');
            $t->jsonb('compatibility')->default('[]');
            $t->jsonb('included_components')->default('[]');
            $t->text('safety_info')->nullable();
            $t->text('warranty')->nullable();
            $t->jsonb('ph_specs')->default('{}'); // e.g. 220V 60Hz, PS/ICC mark, Type A/B plug
            $t->jsonb('synonyms')->default('[]');
            $t->text('search_text')->default('');
            $t->string('embedding_ref')->nullable(); // pointer into an external vector index, if one is configured
            $t->string('status', 12)->default('approved');
            $t->foreignId('submitted_by_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $t->unsignedBigInteger('row_version')->default(1);
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->index(['category_id', 'status']);
            $t->index('brand');
        });
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_chk CHECK (status IN ('approved','candidate','rejected'))");
        DB::statement('CREATE INDEX products_search_trgm ON products USING gin (search_text gin_trgm_ops)');
        DB::statement('CREATE INDEX products_attributes_gin ON products USING gin (attributes jsonb_path_ops)');

        Schema::create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('model_number')->nullable();
            $t->string('part_number')->nullable();
            $t->jsonb('attributes')->default('{}');
            $t->string('uom', 16)->default('pc');
            $t->unsignedInteger('pack_quantity')->default(1);
            $t->string('variant_key');
            $t->boolean('is_default')->default(false);
            $t->string('status', 12)->default('approved');
            $t->unsignedBigInteger('row_version')->default(1);
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->index('model_number');
            $t->index('part_number');
        });
        DB::statement('CREATE UNIQUE INDEX product_variants_key_uq ON product_variants (product_id, variant_key) WHERE deleted_at IS NULL');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_pack_chk CHECK (pack_quantity >= 1)');
        DB::statement("ALTER TABLE product_variants ADD CONSTRAINT product_variants_status_chk CHECK (status IN ('approved','candidate','rejected'))");

        Schema::create('product_barcodes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $t->string('code', 20)->unique(); // a barcode identifies exactly one variant, globally
            $t->string('type', 8)->default('EAN13');
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE product_barcodes ADD CONSTRAINT product_barcodes_type_chk CHECK (type IN ('UPC','EAN8','EAN13','GTIN14','OTHER'))");

        Schema::create('product_aliases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete(); // null = global alias
            $t->string('type', 16)->default('name');
            $t->string('alias');
            $t->string('normalized');
            $t->timestampsTz();
            $t->index('normalized');
            $t->index(['tenant_id', 'normalized']);
        });
        DB::statement("ALTER TABLE product_aliases ADD CONSTRAINT product_aliases_type_chk CHECK (type IN ('name','sku','part_number','synonym'))");
        DB::statement('ALTER TABLE product_aliases ADD CONSTRAINT product_aliases_target_chk CHECK (product_id IS NOT NULL OR variant_id IS NOT NULL)');

        Schema::create('product_images', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete(); // null = canonical image
            $t->string('path'); // relative path on the public disk, or an absolute https URL
            $t->string('sha256', 64)->nullable();
            $t->boolean('is_primary')->default(false);
            $t->string('alt')->nullable();
            $t->string('source', 16)->default('seed');
            $t->timestampsTz();
            $t->index(['product_id', 'is_primary']);
        });

        Schema::create('product_attributes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $t->string('key', 40);
            $t->string('value_text')->nullable();
            $t->decimal('value_number', 14, 4)->nullable();
            $t->string('unit', 12)->nullable();
            $t->unique(['variant_id', 'key']);
            $t->index(['key', 'value_number']);
        });
    }

    public function down(): void
    {
        foreach (['product_attributes', 'product_images', 'product_aliases', 'product_barcodes', 'product_variants', 'products', 'attribute_definitions', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
