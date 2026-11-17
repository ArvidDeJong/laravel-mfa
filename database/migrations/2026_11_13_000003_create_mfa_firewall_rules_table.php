<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mfa_firewall_rules')) {
            return;
        }

        Schema::create('mfa_firewall_rules', function (Blueprint $table) {
            $table->id();
            $table->string('action', 10);
            $table->string('range', 50);
            $table->string('guard')->nullable();
            $table->string('note')->nullable();
            $table->boolean('is_location')->default(false);
            $table->boolean('allows_pin')->default(false);
            $table->boolean('never_block')->default(false);
            $table->boolean('automatic')->default(false);
            $table->string('created_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->string('lifted_by')->nullable();
            $table->timestamps();

            $table->index(['action', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_firewall_rules');
    }
};
