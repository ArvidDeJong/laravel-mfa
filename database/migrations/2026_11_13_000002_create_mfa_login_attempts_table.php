<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mfa_login_attempts')) {
            return;
        }

        Schema::create('mfa_login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('guard');
            $table->string('authenticatable_type')->nullable();
            $table->string('authenticatable_id')->nullable();
            $table->string('identifier')->nullable();
            $table->string('channel')->nullable();
            $table->string('result');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['guard', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_login_attempts');
    }
};
