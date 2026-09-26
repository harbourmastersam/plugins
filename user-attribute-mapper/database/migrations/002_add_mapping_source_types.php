<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_attribute_mappings', function (Blueprint $table) {
            $table->string('source_type', 20)->default('claim')->after('provider');
            $table->string('source_value', 512)->default('')->after('source_type');
        });

        DB::table('user_attribute_mappings')->update(['source_value' => DB::raw('source_claim')]);

        Schema::table('user_attribute_mappings', function (Blueprint $table) {
            $table->dropUnique('uam_mapping_unique');
        });
        Schema::table('user_attribute_mappings', function (Blueprint $table) {
            $table->dropColumn('source_claim');
        });
        Schema::table('user_attribute_mappings', function (Blueprint $table) {
            $table->unique(['provider', 'source_type', 'source_value', 'target_attribute'], 'uam_source_mapping_unique');
        });
    }

    public function down(): void
    {
        Schema::table('user_attribute_mappings', function (Blueprint $table) {
            $table->string('source_claim', 512)->default('')->after('provider');
        });
        Schema::table('user_attribute_mappings', function (Blueprint $table) {
            $table->dropUnique('uam_source_mapping_unique');
        });

        DB::table('user_attribute_mappings')->update(['source_claim' => DB::raw('source_value')]);

        Schema::table('user_attribute_mappings', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'source_value']);
        });
        Schema::table('user_attribute_mappings', function (Blueprint $table) {
            $table->unique(['provider', 'source_claim', 'target_attribute'], 'uam_mapping_unique');
        });
    }
};
