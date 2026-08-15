<?php

namespace App\Http\Controllers\Plans;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plans\LinkPlannedOccurrenceRequest;
use App\Http\Requests\Plans\UpdatePlannedOccurrenceBillsRequest;
use App\Models\BankTransaction;
use App\Models\PlannedOccurrence;
use App\Services\Plans\PlannedOccurrenceMatcher;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlannedOccurrenceController extends Controller
{
    public function link(
        LinkPlannedOccurrenceRequest $request,
        PlannedOccurrence $plannedOccurrence,
        PlannedOccurrenceMatcher $matcher,
    ): RedirectResponse {
        if ($plannedOccurrence->user_id !== $request->user()->id) {
            throw new NotFoundHttpException;
        }

        $transaction = BankTransaction::query()->findOrFail($request->integer('bank_transaction_id'));

        if ($transaction->user_id !== $request->user()->id) {
            throw new NotFoundHttpException;
        }

        try {
            $matcher->link($plannedOccurrence, $transaction);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        $month = $request->string('month')->toString();

        return redirect()
            ->route('plans.index', array_filter(['month' => $month !== '' ? $month : null]))
            ->with('success', 'Paycheck linked to plan.');
    }

    public function updateBills(
        UpdatePlannedOccurrenceBillsRequest $request,
        PlannedOccurrence $plannedOccurrence,
    ): RedirectResponse {
        if ($plannedOccurrence->user_id !== $request->user()->id) {
            throw new NotFoundHttpException;
        }

        $plannedOccurrence->bills()->delete();

        foreach ($request->bills() as $bill) {
            $plannedOccurrence->bills()->create([
                'category_id' => $bill['category_id'],
                'expected_amount' => $bill['expected_amount'],
                'source_template_bill_id' => null,
            ]);
        }

        $plannedOccurrence->update([
            'bills_customized' => true,
        ]);

        $month = $request->string('month')->toString();

        return redirect()
            ->route('plans.index', array_filter(['month' => $month !== '' ? $month : null]))
            ->with('success', 'Assigned bills updated for this paycheck.');
    }
}
