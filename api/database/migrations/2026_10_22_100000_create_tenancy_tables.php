<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_platform_admin')->default(false);
            $t->string('phone', 32)->nullable();
        });

        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('status', 20)->default('active');
            $t->unsignedSmallInteger('vat_rate_bps')->default(1200); // 12% Philippine VAT
            $t->unsignedSmallInteger('reservation_ttl_minutes')->default(120);
            $t->jsonb('settings')->default('{}');
            $t->timestampsTz();
            $t->softDeletesTz();
        });
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_chk CHECK (status IN ('active','suspended'))");

        Schema::create('stores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('code', 24);
            $t->string('address_line');
            $t->string('barangay')->nullable();
            $t->string('city');
            $t->string('province');
            $t->string('postal_code', 12)->nullable();
            $t->string('country', 2)->default('PH');
            $t->decimal('lat', 9, 6)->nullable();
            $t->decimal('lng', 9, 6)->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('email')->nullable();
            $t->jsonb('hours')->default('{}');
            $t->boolean('supports_pickup')->default(true);
            $t->boolean('supports_delivery')->default(false);
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('row_version')->default(1);
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->unique(['id', 'tenant_id']); // target for composite tenant FKs
            $t->index(['tenant_id', 'is_active']);
        });
        DB::statement('CREATE UNIQUE INDEX stores_tenant_code_uq ON stores (tenant_id, code) WHERE deleted_at IS NULL');
        DB::statement('ALTER TABLE stores ADD CONSTRAINT stores_geo_chk CHECK ((lat IS NULL OR lat BETWEEN -90 AND 90) AND (lng IS NULL OR lng BETWEEN -180 AND 180))');

        Schema::create('inventory_locations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('store_id');
            $t->string('name');
            $t->string('code', 24);
            $t->boolean('is_sellable')->default(true);
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->foreign(['store_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('stores');
            $t->unique(['id', 'tenant_id']);
            $t->unique(['store_id', 'code']);
        });

        Schema::create('tenant_memberships', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 30);
            $t->unsignedBigInteger('store_id')->nullable(); // null = all stores
            $t->timestampsTz();
            $t->softDeletesTz(); // revocation
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->foreign(['store_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('stores');
            $t->index('user_id');
        });
        DB::statement("ALTER TABLE tenant_memberships ADD CONSTRAINT tenant_memberships_role_chk CHECK (role IN ('owner','store_manager','inventory_manager','cashier','marketplace_manager','staff'))");
        DB::statement('CREATE UNIQUE INDEX tenant_memberships_active_uq ON tenant_memberships (tenant_id, user_id) WHERE deleted_at IS NULL');

        // The tenant is bound to the token row itself; a client can never choose it per request.
        Schema::table('personal_access_tokens', function (Blueprint $t) {
            $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', fn (Blueprint $t) => $t->dropConstrainedForeignId('tenant_id'));
        Schema::dropIfExists('tenant_memberships');
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('stores');
        Schema::dropIfExists('tenants');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_platform_admin', 'phone']));
    }
};
