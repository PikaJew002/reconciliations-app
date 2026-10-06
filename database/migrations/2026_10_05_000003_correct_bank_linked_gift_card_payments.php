<?php

use App\Services\Reconciliation\OrderPaymentResolutionService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        app(OrderPaymentResolutionService::class)->correctBankLinkedGiftCardPayments();
    }

    public function down(): void
    {
        // Relabeled payments cannot be distinguished from cards that were
        // imported that way.
    }
};
