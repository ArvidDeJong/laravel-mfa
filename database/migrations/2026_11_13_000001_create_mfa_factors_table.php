<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mfa_factors')) {
            return;
        }

        Schema::create('mfa_factors', function (Blueprint $table) {
            $table->id();
            $table->string('authenticatable_type');
            $table->string('authenticatable_id');
            $table->string('type');
            $table->text('secret');
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->unsignedBigInteger('last_used_step')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['authenticatable_type', 'authenticatable_id', 'type'], 'mfa_factors_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_factors');
    }
};
