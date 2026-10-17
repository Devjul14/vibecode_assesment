<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 30);
            $table->foreignId('requester_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('business_process_id')->nullable()->constrained()->nullOnDelete();
            $table->string('requester_priority', 10);
            $table->string('impact_level', 10)->default('unknown');
            $table->unsignedInteger('affected_users')->nullable();
            $table->unsignedSmallInteger('affected_departments')->nullable();
            $table->foreignId('required_skill_id')->nullable()->constrained('skills')->nullOnDelete();
            $table->unsignedTinyInteger('required_level')->default(2);
            $table->decimal('estimate_hours', 6, 2)->nullable()->comment('null = belum diketahui');
            $table->string('estimate_confidence', 10)->default('medium');
            $table->decimal('remaining_hours', 6, 2)->nullable();
            $table->decimal('actual_hours', 6, 2)->default(0);
            $table->dateTime('deadline_at');
            $table->string('status', 30)->default('new');
            $table->foreignId('assignee_id')->nullable()->constrained('team_members')->nullOnDelete();
            $table->foreignId('pairing_member_id')->nullable()->constrained('team_members')->nullOnDelete();
            $table->foreignId('parent_request_id')->nullable()->constrained('service_requests')->nullOnDelete();
            $table->unsignedSmallInteger('rework_count')->default(0);
            $table->string('rework_cause', 30)->nullable();
            $table->dateTime('blocked_since')->nullable();
            $table->decimal('wait_hours_total', 7, 2)->default(0);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('accepted_at')->nullable();

            // Metrik hasil engine (denormalisasi untuk dashboard + audit alasan)
            $table->decimal('priority_score', 5, 2)->nullable();
            $table->json('priority_breakdown')->nullable();
            $table->unsignedInteger('priority_rank')->nullable();
            $table->boolean('needs_human_review')->default(false);
            $table->json('review_reasons')->nullable();
            $table->string('risk_level', 20)->nullable();
            $table->json('risk_reasons')->nullable();
            $table->decimal('slack_hours', 7, 2)->nullable();
            $table->dateTime('latest_start_at')->nullable();
            $table->dateTime('projected_finish_at')->nullable();
            $table->dateTime('metrics_computed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['assignee_id', 'status']);
            $table->index('deadline_at');
            $table->index('risk_level');
            $table->index('priority_rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
