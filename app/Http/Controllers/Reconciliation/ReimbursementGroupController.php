<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Models\BankTransaction;
use App\Models\ReimbursementGroup;
use App\Services\Reconciliation\ReimbursementGroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ReimbursementGroupController extends Controller
{
    public function store(Request $request, ReimbursementGroupService $service): RedirectResponse
    {
        $validated = $request->validate([
            'transaction_ids' => ['required', 'array', 'min:1'],
            'transaction_ids.*' => ['integer', 'distinct'],
            'name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $service->create(
                $request->user()->id,
                $validated['transaction_ids'],
                $validated['name'] ?? null,
                $validated['notes'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('reconciliation.needs-review')
            ->with('success', 'Reimbursement group created.');
    }

    public function addTransactions(
        Request $request,
        ReimbursementGroup $reimbursementGroup,
        ReimbursementGroupService $service,
    ): RedirectResponse {
        abort_unless($reimbursementGroup->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'transaction_ids' => ['required', 'array', 'min:1'],
            'transaction_ids.*' => ['integer', 'distinct'],
        ]);

        try {
            $service->addTransactions($reimbursementGroup, $validated['transaction_ids']);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('reconciliation.needs-review')
            ->with('success', 'Transactions added to reimbursement group.');
    }

    public function removeTransaction(
        Request $request,
        ReimbursementGroup $reimbursementGroup,
        BankTransaction $transaction,
        ReimbursementGroupService $service,
    ): RedirectResponse {
        abort_unless($reimbursementGroup->user_id === $request->user()->id, 403);
        abort_unless($transaction->user_id === $request->user()->id, 403);

        try {
            $service->removeTransaction($reimbursementGroup, $transaction);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->back(fallback: route('reconciliation.needs-review'))
            ->with('success', 'Transaction removed from reimbursement group.');
    }

    public function updateLeg(
        Request $request,
        ReimbursementGroup $reimbursementGroup,
        BankTransaction $transaction,
        ReimbursementGroupService $service,
    ): RedirectResponse {
        abort_unless($reimbursementGroup->user_id === $request->user()->id, 403);
        abort_unless($transaction->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            $service->updateLegAmount(
                $reimbursementGroup,
                $transaction,
                (float) $validated['amount'],
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->back(fallback: route('reconciliation.needs-review'))
            ->with('success', 'Reimbursement amount updated.');
    }

    public function close(
        Request $request,
        ReimbursementGroup $reimbursementGroup,
        ReimbursementGroupService $service,
    ): RedirectResponse {
        abort_unless($reimbursementGroup->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'remainder_category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(fn ($query) => $query
                    ->where('user_id', $request->user()->id)
                    ->where('is_active', true)),
            ],
            'remainder_classification' => [
                'nullable',
                Rule::in([
                    BankTransaction::CLASSIFICATION_EXPENSE,
                    BankTransaction::CLASSIFICATION_BILL,
                    BankTransaction::CLASSIFICATION_INCOME,
                ]),
            ],
        ]);

        try {
            $service->close(
                $reimbursementGroup,
                $validated['remainder_category_id'] ?? null,
                $validated['remainder_classification'] ?? BankTransaction::CLASSIFICATION_EXPENSE,
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->back(fallback: route('reconciliation.needs-review'))
            ->with('success', 'Reimbursement group closed.');
    }

    public function reopen(
        Request $request,
        ReimbursementGroup $reimbursementGroup,
        ReimbursementGroupService $service,
    ): RedirectResponse {
        abort_unless($reimbursementGroup->user_id === $request->user()->id, 403);

        try {
            $service->reopen($reimbursementGroup);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('reconciliation.needs-review')
            ->with('success', 'Reimbursement group reopened.');
    }

    public function destroy(
        Request $request,
        ReimbursementGroup $reimbursementGroup,
        ReimbursementGroupService $service,
    ): RedirectResponse {
        abort_unless($reimbursementGroup->user_id === $request->user()->id, 403);

        try {
            $service->destroy($reimbursementGroup);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->back(fallback: route('reconciliation.needs-review'))
            ->with('success', 'Reimbursement group deleted.');
    }
}
