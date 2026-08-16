<?php

namespace App\Http\Controllers\Plans;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plans\StorePlannedTemplateRequest;
use App\Http\Requests\Plans\UpdatePlannedTemplateAssignmentsRequest;
use App\Http\Requests\Plans\UpdatePlannedTemplateRequest;
use App\Jobs\MatchPlannedOccurrences;
use App\Models\BankTransaction;
use App\Models\BudgetYear;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\PlannedOccurrence;
use App\Models\PlannedOccurrenceMatchRun;
use App\Models\PlannedTemplate;
use App\Models\TransactionCategorizationRule;
use App\Services\Plans\PaycheckBillAssignmentService;
use App\Services\Plans\PlannedOccurrenceGenerator;
use App\Services\Reconciliation\TransactionMatchEvaluator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlannedTemplateController extends Controller
{
    public function index(
        Request $request,
        PlannedOccurrenceGenerator $generator,
        PaycheckBillAssignmentService $assignments,
    ): Response {
        $userId = $request->user()->id;
        $generator->ensureForUser($userId);

        $month = $request->string('month')->toString();

        try {
            $monthStart = $month !== ''
                ? Carbon::createFromFormat('Y-m', $month)->startOfMonth()
                : Carbon::now()->startOfMonth();
        } catch (\Throwable) {
            $monthStart = Carbon::now()->startOfMonth();
        }
        $monthEnd = $monthStart->copy()->addMonth();

        $templates = PlannedTemplate::query()
            ->where('user_id', $userId)
            ->with([
                'category:id,name,kind',
                'merchant:id,name',
                'assignedBills',
                'assignedPaycheck',
            ])
            ->orderBy('expected_day')
            ->orderBy('name')
            ->get()
            ->map(fn (PlannedTemplate $template) => $this->templatePayload($template, $assignments));

        $linkedIds = PlannedOccurrence::query()
            ->where('user_id', $userId)
            ->whereNotNull('bank_transaction_id')
            ->pluck('bank_transaction_id')
            ->all();

        $occurrences = PlannedOccurrence::query()
            ->where('user_id', $userId)
            ->where('expected_date', '>=', $monthStart)
            ->where('expected_date', '<', $monthEnd)
            ->with([
                'template:id,name',
                'category:id,name',
                'bankTransaction:id,posted_at,amount,description',
            ])
            ->orderBy('expected_date')
            ->orderBy('id')
            ->get();

        $paycheckOccurrences = $occurrences
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->values()
            ->map(fn (PlannedOccurrence $occurrence) => $this->occurrencePayload($occurrence));

        $billOccurrences = $occurrences
            ->where('classification', BankTransaction::CLASSIFICATION_BILL)
            ->values()
            ->map(fn (PlannedOccurrence $occurrence) => $this->occurrencePayload($occurrence));

        $paycheckLinkCandidates = $this->linkCandidates(
            $userId,
            $linkedIds,
            $monthStart,
            $monthEnd,
            credits: true,
        );
        $billLinkCandidates = $this->linkCandidates(
            $userId,
            $linkedIds,
            $monthStart,
            $monthEnd,
            credits: false,
        );

        $categories = $this->categoriesForKind($userId, Category::KIND_INCOME);
        $billCategories = $this->categoriesForKind($userId, Category::KIND_BILL);

        $merchants = Merchant::query()
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Merchant $merchant) => [
                'id' => $merchant->id,
                'name' => $merchant->name,
            ]);

        return Inertia::render('Plans/Index', [
            'month' => $monthStart->format('Y-m'),
            'paycheck_templates' => $templates
                ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
                ->values()
                ->all(),
            'bill_templates' => $templates
                ->where('classification', BankTransaction::CLASSIFICATION_BILL)
                ->values()
                ->all(),
            'paycheck_occurrences' => $paycheckOccurrences,
            'bill_occurrences' => $billOccurrences,
            'paycheck_link_candidates' => $paycheckLinkCandidates,
            'bill_link_candidates' => $billLinkCandidates,
            'categories' => $categories,
            'bill_categories' => $billCategories,
            'merchants' => $merchants,
            'match_modes' => PlannedTemplate::incomeMatchModes(),
            'bill_match_modes' => PlannedTemplate::billMatchModes(),
            'source_transactions' => $this->sourceTransactions($userId),
            'active_match_runs' => $this->activeMatchRunsPayload($userId),
            'month_in_budget_year' => $this->monthInBudgetYear($userId, $monthStart),
            'month_beyond_occurrence_horizon' => PlannedOccurrenceGenerator::isBeyondHorizon($monthStart),
        ]);
    }

    public function store(
        StorePlannedTemplateRequest $request,
        PlannedOccurrenceGenerator $generator,
    ): RedirectResponse {
        $template = PlannedTemplate::query()->create([
            'user_id' => $request->user()->id,
            ...$request->templateAttributes(),
        ]);

        $generator->syncTemplate($template);
        $this->dispatchMatchJob($template);

        return redirect()
            ->route('plans.index')
            ->with('success', $request->planLabel().' created. Matching existing transactions…');
    }

    public function update(
        UpdatePlannedTemplateRequest $request,
        PlannedTemplate $plannedTemplate,
        PlannedOccurrenceGenerator $generator,
    ): RedirectResponse {
        $this->ensureOwned($request, $plannedTemplate);

        $plannedTemplate->update($request->templateAttributes());
        $template = $plannedTemplate->fresh();
        $generator->syncTemplate($template);
        $this->dispatchMatchJob($template);

        return redirect()
            ->route('plans.index', array_filter(['month' => $request->string('month')->toString() ?: null]))
            ->with('success', $request->planLabel().' updated. Matching existing transactions…');
    }

    public function destroy(Request $request, PlannedTemplate $plannedTemplate): RedirectResponse
    {
        $this->ensureOwned($request, $plannedTemplate);

        $label = $plannedTemplate->classification === BankTransaction::CLASSIFICATION_BILL
            ? 'Bill plan'
            : 'Paycheck plan';

        $plannedTemplate->delete();

        return redirect()
            ->route('plans.index')
            ->with('success', $label.' deleted.');
    }

    public function updateAssignments(
        UpdatePlannedTemplateAssignmentsRequest $request,
        PlannedTemplate $plannedTemplate,
        PaycheckBillAssignmentService $assignments,
    ): RedirectResponse {
        $this->ensureOwned($request, $plannedTemplate);

        if ($plannedTemplate->classification !== BankTransaction::CLASSIFICATION_INCOME) {
            throw new NotFoundHttpException;
        }

        $assignments->sync($plannedTemplate, $request->billTemplateIds());

        return redirect()
            ->route('plans.index', array_filter(['month' => $request->string('month')->toString() ?: null]))
            ->with('success', 'Bills assigned.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function templatePayload(
        PlannedTemplate $template,
        PaycheckBillAssignmentService $assignments,
    ): array {
        $assignedBills = $template->relationLoaded('assignedBills')
            ? $template->assignedBills
            : collect();
        $assignedPaycheck = $template->relationLoaded('assignedPaycheck')
            ? $template->assignedPaycheck->first()
            : null;

        return [
            'id' => $template->id,
            'name' => $template->name,
            'classification' => $template->classification,
            'category_id' => $template->category_id,
            'category' => $template->category ? [
                'id' => $template->category->id,
                'name' => $template->category->name,
            ] : null,
            'merchant_id' => $template->merchant_id,
            'merchant' => $template->merchant ? [
                'id' => $template->merchant->id,
                'name' => $template->merchant->name,
            ] : null,
            'match_mode' => $template->match_mode,
            'normalized_pattern' => $template->normalized_pattern,
            'amount' => $template->amount !== null ? (float) $template->amount : null,
            'expected_day' => (int) $template->expected_day,
            'expected_amount' => (float) $template->expected_amount,
            'lookback_days' => (int) $template->lookback_days,
            'lookforward_days' => (int) $template->lookforward_days,
            'is_active' => (bool) $template->is_active,
            'assigned_bill_ids' => $assignedBills
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all(),
            'leftover' => $template->classification === BankTransaction::CLASSIFICATION_INCOME
                ? $assignments->leftover($template, $assignedBills)
                : null,
            'assigned_paycheck' => $assignedPaycheck ? [
                'id' => (int) $assignedPaycheck->id,
                'name' => $assignedPaycheck->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function occurrencePayload(PlannedOccurrence $occurrence): array
    {
        $actualAmount = $occurrence->isResolved() && $occurrence->bankTransaction !== null
            ? abs((float) $occurrence->bankTransaction->amount)
            : (float) $occurrence->expected_amount;

        return [
            'id' => $occurrence->id,
            'template_id' => $occurrence->template_id,
            'template_name' => $occurrence->template?->name,
            'classification' => $occurrence->classification,
            'category' => $occurrence->category ? [
                'id' => $occurrence->category->id,
                'name' => $occurrence->category->name,
            ] : null,
            'expected_date' => $occurrence->expected_date?->toDateString(),
            'expected_amount' => (float) $occurrence->expected_amount,
            'amount' => $actualAmount,
            'status' => $occurrence->status,
            'bank_transaction' => $occurrence->bankTransaction ? [
                'id' => $occurrence->bankTransaction->id,
                'posted_at' => $occurrence->bankTransaction->posted_at?->toDateString(),
                'amount' => (float) $occurrence->bankTransaction->amount,
                'description' => $occurrence->bankTransaction->description,
            ] : null,
        ];
    }

    /**
     * @param  list<int|string>  $linkedIds
     * @return Collection<int, array{id: int, posted_at: ?string, amount: float, description: ?string}>
     */
    protected function linkCandidates(
        int $userId,
        array $linkedIds,
        Carbon $monthStart,
        Carbon $monthEnd,
        bool $credits,
    ): Collection {
        $classification = $credits
            ? BankTransaction::CLASSIFICATION_INCOME
            : BankTransaction::CLASSIFICATION_BILL;

        return BankTransaction::query()
            ->where('user_id', $userId)
            ->where('amount', $credits ? '>' : '<', 0)
            ->where(function (Builder $query) use ($classification) {
                $query->whereNull('classification')
                    ->orWhere('classification', $classification);
            })
            ->when(
                $linkedIds !== [],
                fn (Builder $query) => $query->whereNotIn('id', $linkedIds),
            )
            ->where('posted_at', '>=', $monthStart->copy()->subMonth())
            ->where('posted_at', '<', $monthEnd)
            ->orderByDesc('posted_at')
            ->get(['id', 'posted_at', 'amount', 'description'])
            ->map(fn (BankTransaction $transaction) => [
                'id' => $transaction->id,
                'posted_at' => $transaction->posted_at?->toDateString(),
                'amount' => (float) $transaction->amount,
                'description' => $transaction->description,
            ]);
    }

    /**
     * Recent categorized income/bill transactions, grouped by category.
     * Categories with none are omitted so the create form can hide the picker.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    protected function sourceTransactions(int $userId): array
    {
        $categories = Category::query()
            ->where('user_id', $userId)
            ->whereIn('kind', [Category::KIND_INCOME, Category::KIND_BILL])
            ->get(['id', 'kind']);

        if ($categories->isEmpty()) {
            return [];
        }

        $categoryIds = $categories->pluck('id')->all();
        $kindByCategoryId = $categories->mapWithKeys(
            fn (Category $category) => [(int) $category->id => $category->kind],
        );

        $transactions = BankTransaction::query()
            ->where('user_id', $userId)
            ->whereIn('category_id', $categoryIds)
            ->where(function (Builder $query) {
                $query->where(function (Builder $credits) {
                    $credits->where('amount', '>', 0)
                        ->where('classification', BankTransaction::CLASSIFICATION_INCOME);
                })->orWhere(function (Builder $debits) {
                    $debits->where('amount', '<', 0)
                        ->where('classification', BankTransaction::CLASSIFICATION_BILL);
                });
            })
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit(400)
            ->get([
                'id',
                'category_id',
                'merchant_id',
                'posted_at',
                'amount',
                'description',
                'normalized_description',
                'classification',
            ]);

        if ($transactions->isEmpty()) {
            return [];
        }

        $rulesByCategory = TransactionCategorizationRule::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereIn('category_id', $categoryIds)
            ->whereIn('match_mode', TransactionCategorizationRule::persistableMatchModes())
            ->orderBy('id')
            ->get()
            ->groupBy(fn (TransactionCategorizationRule $rule) => (int) $rule->category_id);

        $evaluator = app(TransactionMatchEvaluator::class);
        $options = [];

        foreach ($transactions as $transaction) {
            $categoryId = (int) $transaction->category_id;

            if (count($options[$categoryId] ?? []) >= 12) {
                continue;
            }

            $kind = $kindByCategoryId->get($categoryId);
            $allowedModes = $kind === Category::KIND_BILL
                ? PlannedTemplate::billMatchModes()
                : PlannedTemplate::incomeMatchModes();

            $matchedRule = null;

            foreach ($rulesByCategory->get($categoryId, collect()) as $rule) {
                if (! in_array($rule->match_mode, $allowedModes, true)) {
                    continue;
                }

                if ($evaluator->matchesRule($transaction, $rule)) {
                    $matchedRule = $rule;
                    break;
                }
            }

            $postedAt = $transaction->posted_at;
            $description = $transaction->description;
            $normalized = $evaluator->normalizedDescription($transaction);

            $options[$categoryId][] = [
                'id' => $transaction->id,
                'posted_at' => $postedAt?->toDateString(),
                'description' => $description,
                'suggested_name' => $this->suggestedPlanName($description),
                'amount' => abs((float) $transaction->amount),
                'expected_day' => $postedAt !== null ? (int) $postedAt->day : 1,
                'merchant_id' => $transaction->merchant_id !== null
                    ? (int) $transaction->merchant_id
                    : null,
                'normalized_pattern' => $matchedRule?->normalized_pattern ?: ($normalized !== '' ? $normalized : null),
                'match_mode' => $matchedRule?->match_mode,
                'match_amount' => $matchedRule?->amount !== null
                    ? abs((float) $matchedRule->amount)
                    : abs((float) $transaction->amount),
            ];
        }

        return $options;
    }

    protected function suggestedPlanName(?string $description): ?string
    {
        $name = trim((string) $description);

        if ($name === '') {
            return null;
        }

        if (mb_strlen($name) > 40) {
            $name = rtrim(mb_substr($name, 0, 40)).'…';
        }

        return $name;
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    protected function categoriesForKind(int $userId, string $kind): Collection
    {
        return Category::query()
            ->where('user_id', $userId)
            ->where('kind', $kind)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
            ]);
    }

    protected function monthInBudgetYear(int $userId, Carbon $monthStart): bool
    {
        return BudgetYear::query()
            ->where('user_id', $userId)
            ->get()
            ->contains(fn (BudgetYear $year) => $year->containsMonth($monthStart));
    }

    private function dispatchMatchJob(PlannedTemplate $template): void
    {
        $run = PlannedOccurrenceMatchRun::query()->create([
            'user_id' => $template->user_id,
            'status' => 'pending',
            'metadata' => [
                'template_id' => $template->id,
                'template_name' => $template->name,
                'classification' => $template->classification,
            ],
        ]);

        MatchPlannedOccurrences::dispatch($template->user_id, $run->id);
    }

    /**
     * @return list<array{id: int, status: string, error_message: ?string, metadata: array<string, mixed>}>
     */
    protected function activeMatchRunsPayload(int $userId): array
    {
        return PlannedOccurrenceMatchRun::query()
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
            ->map(fn (PlannedOccurrenceMatchRun $run) => [
                'id' => $run->id,
                'status' => $run->status,
                'error_message' => $run->error_message,
                'metadata' => $run->metadata ?? [],
            ])
            ->all();
    }

    private function ensureOwned(Request $request, PlannedTemplate $plannedTemplate): void
    {
        if ($plannedTemplate->user_id !== $request->user()->id) {
            throw new NotFoundHttpException;
        }
    }
}
