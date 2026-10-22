<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per customer search. subject_key groups searches for the same thing: "p:<product id>" when the search resolved to a
        // single catalog product, otherwise "q:<sorted normalized words>". searcher_key is a salted hash, never a raw user id or IP.
        Schema::create('demand_signals', function (Blueprint $t) {
            $t->id();
            $t->string('source', 16);
            $t->string('subject_key', 160);
            $t->string('subject_label', 160);
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $t->string('brand', 80)->nullable();
            $t->unsignedInteger('result_count');
            $t->string('searcher_key', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['created_at', 'subject_key']);
        });
        DB::statement("ALTER TABLE demand_signals ADD CONSTRAINT demand_signals_source_chk CHECK (source IN ('text_search','photo_search'))");

        // Hardware stores that are not on the platform yet. Contacted only when demand matches what they carry.
        Schema::create('prospect_vendors', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('contact_name')->nullable();
            $t->string('city')->nullable();
            $t->jsonb('categories')->default('[]'); // category slugs
            $t->jsonb('brands')->default('[]');
            $t->timestampTz('unsubscribed_at')->nullable();
            $t->timestampsTz();
        });

        Schema::create('demand_alerts', function (Blueprint $t) {
            $t->id();
            $t->string('subject_key', 160);
            $t->string('subject_label', 160);
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $t->string('brand', 80)->nullable();
            $t->unsignedInteger('searcher_count');
            $t->unsignedInteger('search_count');
            $t->unsignedSmallInteger('window_hours');
            $t->boolean('unserved'); // no live listing matched any of the searches
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['subject_key', 'created_at']);
        });

        Schema::create('demand_alert_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('demand_alert_id')->constrained()->cascadeOnDelete();
            $t->string('audience', 10);
            $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('prospect_vendor_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('email');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['demand_alert_id', 'email']);
        });
        DB::statement("ALTER TABLE demand_alert_deliveries ADD CONSTRAINT demand_alert_deliveries_audience_chk CHECK ((audience = 'member' AND tenant_id IS NOT NULL) OR (audience = 'prospect' AND prospect_vendor_id IS NOT NULL))");
    }

    public function down(): void
    {
        foreach (['demand_alert_deliveries', 'demand_alerts', 'prospect_vendors', 'demand_signals'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
