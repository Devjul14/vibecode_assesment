<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('requester')->after('password');
            $table->foreignId('team_member_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('team_member_id')->constrained()->nullOnDelete();
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_member_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['role', 'last_login_at']);
        });
    }
};
