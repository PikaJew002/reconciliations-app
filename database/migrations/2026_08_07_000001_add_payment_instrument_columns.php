<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_last_four', 4)->nullable()->after('currency');
            $table->index('payment_last_four');
        });

        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->string('card_last_four', 4)->nullable()->after('normalized_description');
            $table->index('card_last_four');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['payment_last_four']);
            $table->dropColumn('payment_last_four');
        });

        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropIndex(['card_last_four']);
            $table->dropColumn('card_last_four');
        });
    }
};
