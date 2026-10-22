<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_registers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('store_id');
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->foreign(['store_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('stores');
            $t->unique(['id', 'tenant_id']);
            $t->unique(['store_id', 'name']);
        });

        Schema::create('pos_sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('register_id');
            $t->foreignId('opened_by')->constrained('users');
            $t->timestampTz('opened_at');
            $t->timestampTz('closed_at')->nullable();
            $t->unsignedBigInteger('opening_float_cents')->default(0);
            $t->unsignedBigInteger('closing_cash_cents')->nullable();
            $t->timestampsTz();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->foreign(['register_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('pos_registers');
            $t->unique(['id', 'tenant_id']);
        });
        DB::statement('CREATE UNIQUE INDEX pos_sessions_one_open_uq ON pos_sessions (register_id) WHERE closed_at IS NULL');

        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('store_id');
            $t->unsignedBigInteger('pos_session_id')->nullable();
            $t->foreignId('cashier_id')->constrained('users');
            $t->string('receipt_no', 40)->nullable();
            $t->string('status', 10)->default('completed');
            $t->unsignedBigInteger('subtotal_cents');
            $t->unsignedBigInteger('discount_cents')->default(0);
            $t->unsignedBigInteger('vat_cents')->default(0);
            $t->unsignedBigInteger('total_cents');
            $t->string('payment_method', 16);
            $t->string('payment_reference', 64)->nullable(); // never card numbers; external reference only
            $t->unsignedBigInteger('tendered_cents')->nullable();
            $t->unsignedBigInteger('change_cents')->default(0);
            $t->string('customer_name')->nullable();
            $t->string('customer_phone', 32)->nullable();
            $t->string('idempotency_key', 100);
            $t->timestampTz('completed_at');
            $t->timestampTz('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users');
            $t->string('void_reason')->nullable();
            $t->timestampsTz();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->foreign(['store_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('stores');
            $t->foreign(['pos_session_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('pos_sessions');
            $t->unique(['id', 'tenant_id']);
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->unique(['tenant_id', 'receipt_no']);
            $t->index(['tenant_id', 'store_id', 'completed_at']);
        });
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_status_chk CHECK (status IN ('completed','voided'))");
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_payment_chk CHECK (payment_method IN ('cash','gcash','maya','card','bank_transfer','other'))");
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_total_chk CHECK (total_cents = subtotal_cents - discount_cents)');

        Schema::create('sale_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('sale_id');
            $t->unsignedBigInteger('listing_id');
            $t->unsignedBigInteger('inventory_item_id');
            $t->foreignId('variant_id')->constrained('product_variants');
            $t->string('sku', 64);
            $t->string('name');
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('unit_price_cents');
            $t->unsignedBigInteger('discount_cents')->default(0);
            $t->unsignedBigInteger('vat_cents')->default(0);
            $t->unsignedBigInteger('line_total_cents');
            $t->unsignedBigInteger('unit_cost_cents')->default(0); // snapshot for margin reports; tenant-private
            $t->unsignedInteger('returned_quantity')->default(0);
            $t->foreign(['sale_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('sales')->cascadeOnDelete();
            $t->foreign(['listing_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('tenant_listings');
            $t->foreign(['inventory_item_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('inventory_items');
        });
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_qty_chk CHECK (quantity > 0 AND returned_quantity <= quantity)');

        Schema::create('marketplace_carts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->timestampsTz();
        });

        Schema::create('marketplace_cart_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cart_id')->constrained('marketplace_carts')->cascadeOnDelete();
            $t->foreignId('listing_id')->constrained('tenant_listings')->cascadeOnDelete();
            $t->unsignedInteger('quantity');
            $t->timestampsTz();
            $t->unique(['cart_id', 'listing_id']);
        });
        DB::statement('ALTER TABLE marketplace_cart_items ADD CONSTRAINT mci_qty_chk CHECK (quantity > 0)');

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('store_id');
            $t->foreignId('customer_id')->constrained('users');
            $t->string('order_no', 32)->nullable();
            $t->string('status', 20)->default('pending');
            $t->string('fulfillment_type', 12);
            $t->unsignedBigInteger('subtotal_cents');
            $t->unsignedBigInteger('delivery_fee_cents')->default(0);
            $t->unsignedBigInteger('total_cents');
            $t->string('contact_name');
            $t->string('contact_phone', 32);
            $t->text('delivery_address')->nullable();
            $t->text('notes')->nullable();
            $t->string('idempotency_key', 100);
            $t->timestampTz('placed_at');
            $t->timestampTz('confirmed_at')->nullable();
            $t->timestampTz('ready_at')->nullable();
            $t->timestampTz('fulfilled_at')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->unsignedBigInteger('row_version')->default(1);
            $t->timestampsTz();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->foreign(['store_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('stores');
            $t->unique(['id', 'tenant_id']);
            $t->unique(['customer_id', 'idempotency_key']);
            $t->unique('order_no');
            $t->index(['tenant_id', 'status', 'placed_at']);
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_chk CHECK (status IN ('pending','reserved','confirmed','preparing','ready_for_pickup','fulfilled','cancelled','expired','failed'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_fulfillment_chk CHECK (fulfillment_type IN ('pickup','reservation','delivery'))");

        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('listing_id');
            $t->unsignedBigInteger('inventory_item_id');
            $t->foreignId('variant_id')->constrained('product_variants');
            $t->string('sku', 64);
            $t->string('name');
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('unit_price_cents');
            $t->unsignedBigInteger('line_total_cents');
            $t->unique(['id', 'tenant_id']);
            $t->foreign(['order_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('orders')->cascadeOnDelete();
            $t->foreign(['listing_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('tenant_listings');
            $t->foreign(['inventory_item_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('inventory_items');
        });
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_qty_chk CHECK (quantity > 0)');

        Schema::create('reservations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('order_id');
            $t->string('status', 12)->default('active');
            $t->timestampTz('expires_at');
            $t->timestampTz('released_at')->nullable();
            $t->timestampsTz();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->foreign(['order_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('orders');
            $t->unique(['id', 'tenant_id']);
            $t->unique('order_id');
        });
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_status_chk CHECK (status IN ('active','released','converted','expired','cancelled'))");
        DB::statement("CREATE INDEX reservations_active_expiry ON reservations (expires_at) WHERE status = 'active'");

        Schema::create('reservation_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('order_item_id');
            $t->unsignedBigInteger('inventory_item_id');
            $t->unsignedBigInteger('location_id');
            $t->unsignedInteger('quantity');
            $t->foreign(['reservation_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('reservations');
            $t->foreign(['order_item_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('order_items');
            $t->foreign(['inventory_item_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('inventory_items');
            $t->foreign(['location_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('inventory_locations');
        });
        DB::statement('ALTER TABLE reservation_lines ADD CONSTRAINT reservation_lines_qty_chk CHECK (quantity > 0)');
    }

    public function down(): void
    {
        foreach (['reservation_lines', 'reservations', 'order_items', 'orders', 'marketplace_cart_items', 'marketplace_carts', 'sale_items', 'sales', 'pos_sessions', 'pos_registers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
