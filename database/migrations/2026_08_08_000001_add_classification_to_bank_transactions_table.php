<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->string('classification')->nullable()->after('status');
            $table->string('classification_source')->nullable()->after('classification');
            $table->decimal('classification_confidence', 5, 2)->nullable()->after('classification_source');
            $table->uuid('transfer_group_id')->nullable()->after('classification_confidence');

            $table->index('classification');
            $table->index('transfer_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropIndex(['classification']);
            $table->dropIndex(['transfer_group_id']);
            $table->dropColumn([
                'classification',
                'classification_source',
                'classification_confidence',
                'transfer_group_id',
            ]);
        });
    }
};
