<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone')->nullable()->after('role');
            $table->string('position')->nullable()->after('phone');
            $table->string('branch')->nullable()->after('position');
            $table->string('status')->default('active')->after('branch');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['phone', 'position', 'branch', 'status']);
            $table->dropSoftDeletes();
        });
    }
};
