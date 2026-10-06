<?php

use App\Services\Reconciliation\OrderTaxReconciler;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        app(OrderTaxReconciler::class)->learnExemptFromUntaxedOrders();
    }

    public function down(): void
    {
        //
    }
};
