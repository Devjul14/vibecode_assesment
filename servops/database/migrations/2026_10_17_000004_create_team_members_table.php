<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('role_title');
            $table->string('seniority', 20);
            $table->decimal('daily_hours', 4, 2)->default(8);
            $table->decimal('focus_factor', 3, 2)->default(0.75);
            $table->decimal('routine_hours_per_day', 4, 2)->default(0);
            $table->decimal('meeting_hours_per_day', 4, 2)->default(0);
            $table->unsignedTinyInteger('wip_limit')->default(3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
    }
};
