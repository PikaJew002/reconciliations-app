<?php

namespace App\Http\Controllers\Plans;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plans\LinkPlannedOccurrenceRequest;
use App\Http\Requests\Plans\UpdatePlannedOccurrenceRequest;
use App\Jobs\MatchPlannedOccurrences;
use App\Models\BankTransaction;
use App\Models\PlannedOccurrence;
use App\Models\PlannedOccurrenceMatchRun;
use App\Services\Plans\PlannedOccurrenceMatcher;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlannedOccurrenceController extends Controller
{
    public function update(
        UpdatePlannedOccurrenceRequest $request,
        PlannedOccurrence $plannedOccurrence,
    ): RedirectResponse {
        if ($plannedOccurrence->user_id !== $request->user()->id) {
            throw new NotFoundHttpException;
        }

        if ($plannedOccurrence->isResolved()) {
            abort(422, 'Resolved occurrences use the linked transaction.');
        }

        $expectedDate = Carbon::parse($request->validated('expected_date'))->toDateString();
        $scheduledDate = $plannedOccurrence->periodDate()->toDateString();

        $plannedOccurrence->update([
            'expected_date' => $expectedDate,
            'expected_amount' => $request->validated('expected_amount'),
            'date_customized' => $expectedDate !== $scheduledDate,
            'amount_customized' => true,
        ]);

        $this->dispatchMatchJob($plannedOccurrence);

        $month = $request->string('month')->toString();
        $label = $plannedOccurrence->classification === BankTransaction::CLASSIFICATION_BILL
            ? 'Bill'
            : 'Paycheck';

        return redirect()
            ->route('plans.index', array_filter(['month' => $month !== '' ? $month : null]))
            ->with('success', $label.' updated for this date only. Matching existing transactions…');
    }

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
        $label = $plannedOccurrence->classification === BankTransaction::CLASSIFICATION_BILL
            ? 'Bill'
            : 'Paycheck';

        return redirect()
            ->route('plans.index', array_filter(['month' => $month !== '' ? $month : null]))
            ->with('success', $label.' linked to plan.');
    }

    private function dispatchMatchJob(PlannedOccurrence $occurrence): void
    {
        $run = PlannedOccurrenceMatchRun::query()->create([
            'user_id' => $occurrence->user_id,
            'status' => 'pending',
            'metadata' => [
                'occurrence_id' => $occurrence->id,
                'template_id' => $occurrence->template_id,
                'classification' => $occurrence->classification,
            ],
        ]);

        MatchPlannedOccurrences::dispatch($occurrence->user_id, $run->id);
    }
}
