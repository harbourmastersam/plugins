<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_attribute_mappings', fn (Blueprint $table) => $table->json('transforms')->default('[]'));
    }

    public function down(): void
    {
        Schema::table('user_attribute_mappings', fn (Blueprint $table) => $table->dropColumn('transforms'));
    }
};
