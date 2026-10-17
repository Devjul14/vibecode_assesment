<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->decimal('old_hours', 6, 2)->nullable();
            $table->decimal('new_hours', 6, 2);
            $table->decimal('delta_pct', 7, 2)->nullable();
            $table->string('kind', 20)->default('revision')->comment('initial / revision / rework');
            $table->boolean('scope_change')->default(false);
            $table->text('reason');
            $table->string('actor_role', 20);
            $table->string('actor_name')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_revisions');
    }
};
