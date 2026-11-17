<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('mfa_firewall_rules', 'identifiers')) {
            return;
        }

        Schema::table('mfa_firewall_rules', function (Blueprint $table) {
            // The accounts that were tried before a block: who it was.
            $table->json('identifiers')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('mfa_firewall_rules', function (Blueprint $table) {
            $table->dropColumn('identifiers');
        });
    }
};
