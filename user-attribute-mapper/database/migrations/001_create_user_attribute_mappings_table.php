<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_attribute_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 191);
            $table->string('source_claim', 512);
            $table->string('target_attribute', 191);
            $table->boolean('enabled')->default(true);
            $table->string('missing_claim_behavior', 20)->default('preserve');
            $table->unsignedInteger('priority')->default(100);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['provider', 'enabled']);
            $table->unique(['provider', 'source_claim', 'target_attribute'], 'uam_mapping_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_attribute_mappings');
    }
};
