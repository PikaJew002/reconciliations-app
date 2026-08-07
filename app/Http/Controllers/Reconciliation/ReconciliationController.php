<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Jobs\RunUserReconciliationPipeline;
use App\Models\ReconciliationRun;
use App\Services\Reconciliation\ReconciliationReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReconciliationController extends Controller
{
    public function index(Request $request, ReconciliationReviewService $review): Response
    {
        $userId = $request->user()->id;
        $data = $review->forUser($userId);

        return Inertia::render('Reconciliation/Index', [
            ...$data,
            'activeRun' => $this->activeRunPayload($userId),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $userId = $request->user()->id;

        $existing = ReconciliationRun::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'processing'])
            ->latest('id')
            ->first();

        if ($existing) {
            return redirect()
                ->route('reconciliation.index')
                ->with('success', 'A reconciliation run is already in progress.');
        }

        $run = ReconciliationRun::query()->create([
            'user_id' => $userId,
            'status' => 'pending',
            'metadata' => [],
        ]);

        RunUserReconciliationPipeline::dispatch($run->id);

        return redirect()
            ->route('reconciliation.index')
            ->with('success', 'Reconciliation queued for your imported data.');
    }

    /**
     * @return array{id: int, status: string, error_message: ?string, metadata: array<string, mixed>}|null
     */
    protected function activeRunPayload(int $userId): ?array
    {
        $run = ReconciliationRun::query()
            ->where('user_id', $userId)
            ->latest('id')
            ->first();

        if (! $run) {
            return null;
        }

        // Keep completed/failed visible briefly so the UI can stop polling and show results.
        if (! $run->isInProgress() && $run->completed_at?->lt(now()->subMinutes(5))) {
            return null;
        }

        return [
            'id' => $run->id,
            'status' => $run->status,
            'error_message' => $run->error_message,
            'metadata' => $run->metadata ?? [],
        ];
    }
}
