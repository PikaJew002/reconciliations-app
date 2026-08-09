<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Models\TransactionTransferLink;
use App\Services\Reconciliation\TransferPairingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TransferLinkController extends Controller
{
    public function confirm(
        Request $request,
        TransactionTransferLink $transferLink,
        TransferPairingService $pairing,
    ): RedirectResponse {
        abort_unless($transferLink->user_id === $request->user()->id, 403);
        abort_unless($transferLink->isSuggested(), 422, 'Only suggested transfer links can be confirmed.');

        $pairing->confirmLink($transferLink);

        return redirect()
            ->route('reconciliation.index')
            ->with('success', 'Transfer confirmed and hidden from expense tracking.');
    }

    public function reject(
        Request $request,
        TransactionTransferLink $transferLink,
        TransferPairingService $pairing,
    ): RedirectResponse {
        abort_unless($transferLink->user_id === $request->user()->id, 403);
        abort_unless($transferLink->isSuggested(), 422, 'Only suggested transfer links can be rejected.');

        $pairing->rejectLink($transferLink);

        return redirect()
            ->route('reconciliation.index')
            ->with('success', 'Transfer suggestion dismissed.');
    }
}
