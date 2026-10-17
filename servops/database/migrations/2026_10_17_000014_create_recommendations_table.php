<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('team_member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->unsignedTinyInteger('severity')->default(1)->comment('1=info, 2=warning, 3=critical');
            $table->string('title');
            $table->text('rationale');
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('open');
            $table->string('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->string('fingerprint', 64)->unique();
            $table->timestamps();
            $table->index(['status', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
