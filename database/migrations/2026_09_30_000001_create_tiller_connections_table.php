<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiller_connections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('callback_url');

            $table->text('webhook_secret');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiller_connections');
    }
};
