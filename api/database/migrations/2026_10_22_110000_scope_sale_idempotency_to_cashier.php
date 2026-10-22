<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Idempotency keys are chosen by devices, so two cashiers may legitimately pick the same key: uniqueness is per cashier. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sales DROP CONSTRAINT IF EXISTS sales_tenant_id_idempotency_key_unique');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_cashier_idempotency_key_unique UNIQUE (tenant_id, cashier_id, idempotency_key)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales DROP CONSTRAINT IF EXISTS sales_cashier_idempotency_key_unique');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_tenant_id_idempotency_key_unique UNIQUE (tenant_id, idempotency_key)');
    }
};
