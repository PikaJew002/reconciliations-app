<?php

namespace App\Http\Controllers\Plans;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plans\StorePlannedTemplateRequest;
use App\Http\Requests\Plans\UpdatePlannedTemplateRequest;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Services\Plans\PlannedOccurrenceGenerator;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlannedTemplateController extends Controller
{
    public function index(
        Request $request,
        PlannedOccurrenceGenerator $generator,
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
            ->with(['category:id,name,kind', 'merchant:id,name'])
            ->orderBy('expected_day')
            ->orderBy('name')
            ->get()
            ->map(fn (PlannedTemplate $template) => $this->templatePayload($template));

        $linkedIds = PlannedOccurrence::query()
            ->where('user_id', $userId)
            ->whereNotNull('bank_transaction_id')
            ->pluck('bank_transaction_id')
            ->all();

        $occurrences = PlannedOccurrence::query()
            ->where('user_id', $userId)
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->where('expected_date', '>=', $monthStart)
            ->where('expected_date', '<', $monthEnd)
            ->with([
                'template:id,name',
                'category:id,name',
                'bankTransaction:id,posted_at,amount,description',
            ])
            ->orderBy('expected_date')
            ->orderBy('id')
            ->get()
            ->map(fn (PlannedOccurrence $occurrence) => [
                'id' => $occurrence->id,
                'template_id' => $occurrence->template_id,
                'template_name' => $occurrence->template?->name,
                'category' => $occurrence->category ? [
                    'id' => $occurrence->category->id,
                    'name' => $occurrence->category->name,
                ] : null,
                'expected_date' => $occurrence->expected_date?->toDateString(),
                'expected_amount' => (float) $occurrence->expected_amount,
                'status' => $occurrence->status,
                'bank_transaction' => $occurrence->bankTransaction ? [
                    'id' => $occurrence->bankTransaction->id,
                    'posted_at' => $occurrence->bankTransaction->posted_at?->toDateString(),
                    'amount' => (float) $occurrence->bankTransaction->amount,
                    'description' => $occurrence->bankTransaction->description,
                ] : null,
            ]);

        $linkCandidates = BankTransaction::query()
            ->where('user_id', $userId)
            ->where('amount', '>', 0)
            ->where(function ($query) {
                $query->whereNull('classification')
                    ->orWhere('classification', BankTransaction::CLASSIFICATION_INCOME);
            })
            ->when(
                $linkedIds !== [],
                fn ($query) => $query->whereNotIn('id', $linkedIds),
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

        $categories = Category::query()
            ->where('user_id', $userId)
            ->where('kind', Category::KIND_INCOME)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
            ]);

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
            'templates' => $templates,
            'occurrences' => $occurrences,
            'link_candidates' => $linkCandidates,
            'categories' => $categories,
            'merchants' => $merchants,
            'match_modes' => PlannedTemplate::incomeMatchModes(),
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

        return redirect()
            ->route('plans.index')
            ->with('success', 'Paycheck plan created.');
    }

    public function update(
        UpdatePlannedTemplateRequest $request,
        PlannedTemplate $plannedTemplate,
        PlannedOccurrenceGenerator $generator,
    ): RedirectResponse {
        $this->ensureOwned($request, $plannedTemplate);

        $plannedTemplate->update($request->templateAttributes());
        $generator->syncTemplate($plannedTemplate->fresh());

        return redirect()
            ->route('plans.index', array_filter(['month' => $request->string('month')->toString() ?: null]))
            ->with('success', 'Paycheck plan updated.');
    }

    public function destroy(Request $request, PlannedTemplate $plannedTemplate): RedirectResponse
    {
        $this->ensureOwned($request, $plannedTemplate);

        $plannedTemplate->delete();

        return redirect()
            ->route('plans.index')
            ->with('success', 'Paycheck plan deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function templatePayload(PlannedTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
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
        ];
    }

    private function ensureOwned(Request $request, PlannedTemplate $plannedTemplate): void
    {
        if ($plannedTemplate->user_id !== $request->user()->id) {
            throw new NotFoundHttpException;
        }
    }
}
