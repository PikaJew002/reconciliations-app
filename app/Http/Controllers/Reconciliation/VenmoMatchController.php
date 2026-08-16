<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Models\BankTransaction;
use App\Models\VenmoActivity;
use App\Services\Reconciliation\VenmoActivityMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VenmoMatchController extends Controller
{
    public function confirm(
        Request $request,
        VenmoActivity $venmoActivity,
        VenmoActivityMatcher $matcher,
    ): RedirectResponse {
        abort_unless($venmoActivity->user_id === $request->user()->id, 403);
        abort_unless($venmoActivity->isSuggested(), 422, 'Only suggested Venmo matches can be confirmed.');

        $matcher->confirm($venmoActivity);

        return redirect()
            ->route('reconciliation.needs-review')
            ->with('success', 'Venmo match confirmed.');
    }

    public function reject(
        Request $request,
        VenmoActivity $venmoActivity,
        VenmoActivityMatcher $matcher,
    ): RedirectResponse {
        abort_unless($venmoActivity->user_id === $request->user()->id, 403);
        abort_unless($venmoActivity->isSuggested(), 422, 'Only suggested Venmo matches can be dismissed.');

        $matcher->reject($venmoActivity);

        return redirect()
            ->route('reconciliation.needs-review')
            ->with('success', 'Venmo match dismissed.');
    }

    public function assign(
        Request $request,
        VenmoActivity $venmoActivity,
        VenmoActivityMatcher $matcher,
    ): RedirectResponse {
        abort_unless($venmoActivity->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'bank_transaction_id' => ['required', 'integer', 'exists:bank_transactions,id'],
        ]);

        $transaction = BankTransaction::query()->findOrFail($validated['bank_transaction_id']);

        abort_unless($transaction->user_id === $request->user()->id, 403);

        $matcher->assign($venmoActivity, $transaction);

        return redirect()
            ->route('reconciliation.needs-review')
            ->with('success', 'Venmo activity linked to the bank transaction.');
    }
}
