<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level')->comment('1=basic, 2=independent, 3=expert');
            $table->timestamps();
            $table->unique(['team_member_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_skills');
    }
};
