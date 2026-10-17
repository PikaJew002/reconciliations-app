<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plaid_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('item_id')->unique();
            $table->text('access_token');
            $table->string('institution_id');
            $table->string('institution_name')->nullable();
            $table->text('cursor')->nullable();
            $table->string('transactions_update_status')->nullable();
            $table->string('link_session_id')->nullable();
            $table->unsignedSmallInteger('days_requested');
            $table->date('import_from');
            $table->string('error_code')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'institution_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plaid_items');
    }
};
