<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_states', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id')->unique();   // Telegram chat ID
            $table->string('step')->default('idle');   // Joriy qadam
            $table->json('data')->nullable();          // To'plangan ma'lumotlar
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_states');
    }
};
