<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mfa_challenges')) {
            return;
        }

        Schema::create('mfa_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('guard');
            $table->string('authenticatable_type');
            $table->string('authenticatable_id');
            $table->string('channel');
            $table->string('recipient')->nullable();
            $table->string('token', 64)->nullable()->unique();
            $table->string('code');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['guard', 'authenticatable_type', 'authenticatable_id'], 'mfa_challenges_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_challenges');
    }
};
