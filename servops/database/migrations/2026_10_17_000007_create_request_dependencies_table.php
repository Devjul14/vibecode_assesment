<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_request_id')->nullable()->constrained('service_requests')->cascadeOnDelete();
            $table->string('type', 10);
            $table->string('external_party')->nullable();
            $table->string('description')->nullable();
            $table->dateTime('expected_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['service_request_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_dependencies');
    }
};
