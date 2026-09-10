<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('enabled')->default(false);
            $table->boolean('is_default')->default(false);
            // NULL values may repeat, but the single non-NULL sentinel may not.
            $table->unsignedTinyInteger('default_guard')->nullable()->unique();
            $table->unsignedInteger('archive_backup_host_id');
            $table->unsignedBigInteger('inactivity_minutes')->nullable();
            $table->boolean('running_counts_as_active')->default(true);
            $table->unsignedBigInteger('archive_retention_minutes')->nullable();
            $table->string('final_delivery_mode')->default('none');
            $table->unsignedBigInteger('final_delivery_grace_minutes')->default(10080);
            $table->unsignedBigInteger('attachment_max_bytes')->default(20971520);
            $table->timestamps();
            $table->foreign('archive_backup_host_id')->references('id')->on('backup_hosts')->restrictOnDelete();
        });

        Schema::create('lifecycle_warning_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_id')->constrained('lifecycle_policies')->cascadeOnDelete();
            $table->string('phase');
            $table->unsignedBigInteger('offset_minutes');
            $table->boolean('database_enabled')->default(true);
            $table->boolean('email_enabled')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['policy_id', 'phase', 'offset_minutes']);
        });

        Schema::create('server_archives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('owner_id')->nullable();
            $table->unsignedInteger('original_server_id')->nullable()->index();
            $table->uuid('original_server_uuid');
            $table->string('original_uuid_short');
            $table->string('server_name');
            $table->text('description')->nullable();
            $table->json('original_node')->nullable();
            $table->json('original_allocations')->nullable();
            $table->unsignedInteger('backup_host_id');
            $table->unsignedBigInteger('backup_id')->nullable()->unique();
            $table->string('object_key')->nullable();
            $table->uuid('original_backup_uuid')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('checksum')->nullable();
            $table->string('status')->index();
            $table->longText('manifest')->nullable();
            $table->json('policy_snapshot');
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('retention_expires_at')->nullable()->index();
            $table->timestamp('pending_deletion_at')->nullable();
            $table->timestamp('final_delivery_sent_at')->nullable();
            $table->timestamp('final_delivery_expires_at')->nullable();
            $table->unsignedInteger('restored_server_id')->nullable();
            $table->unsignedBigInteger('restore_backup_id')->nullable()->unique();
            $table->timestamp('restored_at')->nullable();
            $table->timestamp('remote_object_deleted_at')->nullable();
            $table->string('deletion_reason')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('backup_host_id')->references('id')->on('backup_hosts')->restrictOnDelete();
        });

        Schema::create('server_lifecycle_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('server_id')->unique();
            $table->foreignId('policy_id')->nullable()->constrained('lifecycle_policies')->nullOnDelete();
            $table->boolean('automatic_enabled')->default(false);
            $table->timestamp('exempt_until')->nullable();
            $table->timestamp('last_activity_at');
            $table->string('last_activity_event')->nullable();
            $table->timestamp('archive_due_at')->nullable()->index();
            $table->string('status')->default('active');
            $table->uuid('current_archive_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('current_archive_id')->references('id')->on('server_archives')->nullOnDelete();
        });

        Schema::create('lifecycle_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warning_rule_id')->constrained('lifecycle_warning_rules')->cascadeOnDelete();
            $table->unsignedInteger('server_id')->nullable();
            $table->uuid('archive_id')->nullable();
            $table->string('subject_key');
            $table->string('channel');
            $table->timestamp('target_at');
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['warning_rule_id', 'subject_key', 'channel', 'target_at'], 'lifecycle_delivery_cycle_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_notification_deliveries');
        Schema::dropIfExists('server_lifecycle_states');
        Schema::dropIfExists('server_archives');
        Schema::dropIfExists('lifecycle_warning_rules');
        Schema::dropIfExists('lifecycle_policies');
    }
};
