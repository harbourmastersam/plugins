<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('database_viewer_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('database_id');
            $table->char('channel_hash', 64)->unique('database_viewer_sessions_channel_hash_unique');
            $table->timestamp('created_at');
            $table->timestamp('expires_at')->index('database_viewer_sessions_expires_at_index');
            $table->timestamp('last_activity_at');
            $table->timestamp('revoked_at')->nullable()->index('database_viewer_sessions_revoked_at_index');
            $table->index(['user_id', 'revoked_at', 'expires_at'], 'database_viewer_sessions_active_user_index');
            $table->index(['server_id', 'database_id'], 'database_viewer_sessions_context_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_viewer_sessions');
    }
};
