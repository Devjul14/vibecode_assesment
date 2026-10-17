<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capacity_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_member_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->decimal('hours', 5, 2)->nullable()->comment('null = seluruh jam kerja dalam rentang');
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['team_member_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capacity_entries');
    }
};
