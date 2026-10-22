<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned inventory. Every tenant table carries tenant_id, and cross-table links use composite
 * (id, tenant_id) foreign keys so a row can never point at another tenant's parent, even through a bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% on % is not allowed: table is append-only', TG_OP, TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('contact_name')->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('email')->nullable();
            $t->unsignedSmallInteger('lead_time_days')->nullable();
            $t->text('notes')->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->unique(['id', 'tenant_id']);
        });

        Schema::create('inventory_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->foreignId('variant_id')->constrained('product_variants');
            $t->string('tenant_sku', 64);
            $t->unsignedBigInteger('cost_cents')->default(0); // private to the tenant, never serialized publicly
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->unsignedInteger('reorder_threshold')->default(0);
            $t->boolean('track_stock')->default(true);
            $t->unsignedBigInteger('default_location_id')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('row_version')->default(1);
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->foreign(['supplier_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('suppliers');
            $t->foreign(['default_location_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('inventory_locations');
            $t->unique(['id', 'tenant_id']);
        });
        DB::statement('CREATE UNIQUE INDEX inventory_items_sku_uq ON inventory_items (tenant_id, tenant_sku) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX inventory_items_variant_uq ON inventory_items (tenant_id, variant_id) WHERE deleted_at IS NULL');

        // Provenance of "this tenant item IS that canonical variant" (AI-assisted or manual).
        Schema::create('tenant_product_mappings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('inventory_item_id');
            $t->foreignId('variant_id')->constrained('product_variants');
            $t->string('source', 16);
            $t->unsignedBigInteger('ai_analysis_id')->nullable();
            $t->decimal('confidence', 4, 3)->nullable();
            $t->string('supplier_sku', 64)->nullable();
            $t->foreignId('confirmed_by')->constrained('users');
            $t->timestampTz('confirmed_at');
            $t->timestampsTz();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->foreign(['inventory_item_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('inventory_items');
            $t->unique('inventory_item_id');
        });
        DB::statement("ALTER TABLE tenant_product_mappings ADD CONSTRAINT tpm_source_chk CHECK (source IN ('ai_intake','manual','barcode_scan','catalog_search'))");

        Schema::create('tenant_listings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('store_id');
            $t->unsignedBigInteger('inventory_item_id');
            $t->foreignId('product_id')->constrained();
            $t->foreignId('variant_id')->constrained('product_variants');
            $t->string('tenant_sku', 64);
            $t->string('title_override')->nullable();
            $t->text('description')->nullable();
            $t->unsignedBigInteger('price_cents');
            $t->unsignedBigInteger('sale_price_cents')->nullable();
            $t->string('currency', 3)->default('PHP');
            $t->string('uom', 16)->default('pc');
            $t->string('visibility', 10)->default('hidden');
            $t->string('publication_status', 12)->default('draft');
            $t->boolean('pickup_available')->default(true);
            $t->boolean('delivery_available')->default(false);
            $t->unsignedInteger('min_order_qty')->default(1);
            $t->unsignedInteger('max_order_qty')->nullable();
            $t->unsignedInteger('lead_time_hours')->default(0);
            $t->unsignedInteger('stock_buffer')->default(0); // units withheld from the marketplace
            $t->boolean('show_exact_stock')->default(false);
            $t->unsignedBigInteger('row_version')->default(1);
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->foreign(['store_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('stores');
            $t->foreign(['inventory_item_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('inventory_items');
            $t->unique(['id', 'tenant_id']);
            $t->index(['variant_id', 'publication_status', 'visibility']);
            $t->index(['tenant_id', 'store_id']);
        });
        DB::statement('CREATE UNIQUE INDEX tenant_listings_store_variant_uq ON tenant_listings (tenant_id, store_id, variant_id) WHERE deleted_at IS NULL');
        DB::statement("ALTER TABLE tenant_listings ADD CONSTRAINT tl_visibility_chk CHECK (visibility IN ('public','hidden'))");
        DB::statement("ALTER TABLE tenant_listings ADD CONSTRAINT tl_publication_chk CHECK (publication_status IN ('draft','published','unpublished'))");
        DB::statement('ALTER TABLE tenant_listings ADD CONSTRAINT tl_price_chk CHECK (sale_price_cents IS NULL OR sale_price_cents < price_cents)');
        DB::statement('ALTER TABLE tenant_listings ADD CONSTRAINT tl_qty_chk CHECK (min_order_qty >= 1 AND (max_order_qty IS NULL OR max_order_qty >= min_order_qty))');

        Schema::create('stock_snapshots', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('inventory_item_id');
            $t->unsignedBigInteger('location_id');
            $t->integer('on_hand')->default(0);
            $t->integer('reserved')->default(0);
            $t->unsignedBigInteger('version')->default(0);
            $t->timestampTz('updated_at')->useCurrent();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->foreign(['inventory_item_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('inventory_items');
            $t->foreign(['location_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('inventory_locations');
            $t->unique(['inventory_item_id', 'location_id']);
            $t->index(['tenant_id', 'location_id']);
        });
        // The database refuses oversell even if service code is wrong.
        DB::statement('ALTER TABLE stock_snapshots ADD CONSTRAINT stock_snapshots_qty_chk CHECK (on_hand >= 0 AND reserved >= 0 AND reserved <= on_hand)');

        Schema::create('stock_ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('inventory_item_id');
            $t->unsignedBigInteger('location_id');
            $t->unsignedBigInteger('listing_id')->nullable();
            $t->string('movement_type', 24);
            $t->integer('quantity_delta');
            $t->integer('before_quantity');
            $t->integer('after_quantity');
            $t->integer('reserved_delta')->default(0);
            $t->integer('before_reserved')->default(0);
            $t->integer('after_reserved')->default(0);
            $t->string('reference_type', 32)->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('actor_type', 8)->default('user');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->text('note')->nullable();
            $t->timestampTz('occurred_at')->useCurrent();
            $t->foreign('tenant_id')->references('id')->on('tenants');
            $t->foreign(['inventory_item_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('inventory_items');
            $t->foreign(['location_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('inventory_locations');
            $t->index(['tenant_id', 'inventory_item_id', 'id']);
            $t->index(['reference_type', 'reference_id']);
        });
        DB::statement("ALTER TABLE stock_ledger_entries ADD CONSTRAINT sle_type_chk CHECK (movement_type IN ('opening_balance','purchase_receipt','manual_adjustment','sale','return','reservation','reservation_release','order_fulfillment','transfer_in','transfer_out','damage','loss','correction'))");
        DB::statement('ALTER TABLE stock_ledger_entries ADD CONSTRAINT sle_math_chk CHECK (after_quantity = before_quantity + quantity_delta AND after_reserved = before_reserved + reserved_delta)');
        DB::statement("ALTER TABLE stock_ledger_entries ADD CONSTRAINT sle_actor_chk CHECK (actor_type IN ('user','system'))");
        DB::statement('CREATE TRIGGER stock_ledger_append_only BEFORE UPDATE OR DELETE ON stock_ledger_entries FOR EACH ROW EXECUTE FUNCTION forbid_mutation()');

        Schema::create('purchase_receipts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->unsignedBigInteger('location_id');
            $t->string('reference', 64)->nullable();
            $t->foreignId('received_by')->constrained('users');
            $t->timestampTz('received_at');
            $t->unsignedBigInteger('total_cost_cents')->default(0);
            $t->text('notes')->nullable();
            $t->timestampsTz();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->foreign(['supplier_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('suppliers');
            $t->foreign(['location_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('inventory_locations');
            $t->unique(['id', 'tenant_id']);
        });

        Schema::create('purchase_receipt_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('purchase_receipt_id');
            $t->unsignedBigInteger('inventory_item_id');
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('unit_cost_cents');
            $t->foreign(['purchase_receipt_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('purchase_receipts')->cascadeOnDelete();
            $t->foreign(['inventory_item_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('inventory_items');
        });
        DB::statement('ALTER TABLE purchase_receipt_items ADD CONSTRAINT pri_qty_chk CHECK (quantity > 0)');
    }

    public function down(): void
    {
        foreach (['purchase_receipt_items', 'purchase_receipts', 'stock_ledger_entries', 'stock_snapshots', 'tenant_listings', 'tenant_product_mappings', 'inventory_items', 'suppliers'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS forbid_mutation()');
    }
};
