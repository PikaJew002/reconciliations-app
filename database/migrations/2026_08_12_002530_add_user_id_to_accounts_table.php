<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });

        // Prefer an owner inferred from bank transactions (MIN if somehow mixed).
        $owners = DB::table('bank_transactions')
            ->select('account_id', DB::raw('MIN(user_id) as user_id'))
            ->whereNotNull('account_id')
            ->whereNotNull('user_id')
            ->groupBy('account_id')
            ->get();

        foreach ($owners as $owner) {
            DB::table('accounts')
                ->where('id', $owner->account_id)
                ->whereNull('user_id')
                ->update(['user_id' => $owner->user_id]);
        }

        $fallbackUserId = DB::table('users')->min('id');

        if ($fallbackUserId !== null) {
            DB::table('accounts')
                ->whereNull('user_id')
                ->update(['user_id' => $fallbackUserId]);
        } else {
            DB::table('accounts')->whereNull('user_id')->delete();
        }

        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
