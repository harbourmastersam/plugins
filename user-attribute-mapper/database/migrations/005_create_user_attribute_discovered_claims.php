<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_attribute_discovered_claims', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 191)->index();
            $table->string('claim_path', 512);
            $table->string('claim_type', 32);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedBigInteger('observation_count')->default(1);
            $table->unique(['provider', 'claim_path'], 'uam_discovered_provider_path_unique');
        });
    }

    public function down(): void { Schema::dropIfExists('user_attribute_discovered_claims'); }
};
