<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plaid_link_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('plaid_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('link_session_id')->nullable();
            $table->string('event');
            $table->string('institution_id')->nullable();
            $table->string('error_type')->nullable();
            $table->string('error_code')->nullable();
            $table->string('request_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plaid_link_events');
    }
};
