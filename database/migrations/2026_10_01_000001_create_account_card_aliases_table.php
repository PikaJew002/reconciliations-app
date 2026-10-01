<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_card_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('account_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('last_four', 4);
            $table->timestamps();

            $table->unique(['account_id', 'last_four']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_card_aliases');
    }
};
