<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Shared canonical catalog: intentionally not tenant-scoped.
        Schema::create('catalog_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('brand')->nullable()->index();
            $table->string('model_number')->nullable()->index();
            $table->string('barcode', 32)->nullable()->index();
            $table->string('category')->nullable()->index();
            $table->json('sku_aliases')->nullable();
            $table->json('attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_products');
    }
};
