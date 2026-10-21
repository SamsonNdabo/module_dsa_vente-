<?php
// database/migrations/2024_03_18_add_two_factor_email_enabled_to_utilisateurs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utilisateurs', function (Blueprint $table) {
            if (!Schema::hasColumn('utilisateurs', 'two_factor_email_enabled')) {
                $table->boolean('two_factor_email_enabled')
                    ->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('utilisateurs', function (Blueprint $table) {
            if (Schema::hasColumn('utilisateurs', 'two_factor_email_enabled')) {
                $table->dropColumn('two_factor_email_enabled');
            }
        });
    }
};