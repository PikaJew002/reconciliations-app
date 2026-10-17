<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plaid_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plaid_item_id')->constrained()->cascadeOnDelete();
            $table->string('plaid_account_id');
            $table->string('persistent_account_id')->nullable();
            $table->string('name');
            $table->string('official_name')->nullable();
            $table->string('mask', 4)->nullable();
            $table->string('type');
            $table->string('subtype')->nullable();
            $table->foreignUuid('account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['plaid_item_id', 'plaid_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plaid_accounts');
    }
};
