<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Jobs\RunUserReconciliationPipeline;
use App\Models\CategorizationRun;
use App\Models\Category;
use App\Models\ReconciliationRun;
use App\Models\TransactionCategorizationRule;
use App\Services\Reconciliation\ReconciliationReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReconciliationController extends Controller
{
    public function unmatchedTransactions(Request $request, ReconciliationReviewService $review): Response
    {
        $userId = $request->user()->id;

        return Inertia::render('Reconciliation/UnmatchedTransactions', [
            'summary' => $review->summaryForUser($userId),
            ...$review->unmatchedTransactionsForUser($userId),
            'categories' => $this->categoriesPayload($userId),
            'matchModes' => TransactionCategorizationRule::allMatchModes(),
            ...$this->sharedRunProps($userId),
        ]);
    }

    public function needsReview(Request $request, ReconciliationReviewService $review): Response
    {
        $userId = $request->user()->id;
        $needsReview = $review->needsReviewForUser($userId);

        return Inertia::render('Reconciliation/NeedsReview', [
            'summary' => $review->summaryForUser($userId, $needsReview),
            ...$needsReview,
            'categories' => $this->categoriesPayload($userId),
            ...$this->sharedRunProps($userId),
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
                ->back(fallback: route('reconciliation.unmatched-transactions'))
                ->with('success', 'A reconciliation run is already in progress.');
        }

        $run = ReconciliationRun::query()->create([
            'user_id' => $userId,
            'status' => 'pending',
            'metadata' => [],
        ]);

        RunUserReconciliationPipeline::dispatch($run->id);

        return redirect()
            ->back(fallback: route('reconciliation.unmatched-transactions'))
            ->with('success', 'Reconciliation queued for your imported data.');
    }

    /**
     * @return list<array{id: int, name: string, kind: string}>
     */
    protected function categoriesPayload(int $userId): array
    {
        return Category::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'kind'])
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'kind' => $category->kind,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     activeRun: array{id: int, status: string, error_message: ?string, metadata: array<string, mixed>}|null,
     *     activeCategorizeRuns: list<array{id: int, status: string, error_message: ?string, metadata: array<string, mixed>}>
     * }
     */
    protected function sharedRunProps(int $userId): array
    {
        return [
            'activeRun' => $this->activeRunPayload($userId),
            'activeCategorizeRuns' => $this->activeCategorizeRunsPayload($userId),
        ];
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

    /**
     * @return list<array{id: int, status: string, error_message: ?string, metadata: array<string, mixed>}>
     */
    protected function activeCategorizeRunsPayload(int $userId): array
    {
        return CategorizationRun::query()
            ->where('user_id', $userId)
            ->where(function ($query) {
                $query->whereIn('status', ['pending', 'processing'])
                    ->orWhere(function ($recent) {
                        $recent->whereIn('status', ['completed', 'failed'])
                            ->where('completed_at', '>=', now()->subMinutes(5));
                    });
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (CategorizationRun $run) => [
                'id' => $run->id,
                'status' => $run->status,
                'error_message' => $run->error_message,
                'metadata' => $run->metadata ?? [],
            ])
            ->all();
    }
}
