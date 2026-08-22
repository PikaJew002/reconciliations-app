<?php

namespace App\Http\Controllers\Merchants;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchants\StoreMerchantMatchingRuleRequest;
use App\Http\Requests\Merchants\UpdateMerchantMatchingRuleRequest;
use App\Models\Merchant;
use App\Models\MerchantMatchingRule;
use App\Services\Merchants\MerchantBrowseService;
use App\Services\Reconciliation\MerchantMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MerchantMatchingRuleController extends Controller
{
    public function store(
        StoreMerchantMatchingRuleRequest $request,
        Merchant $merchant,
        MerchantMatcher $matcher,
    ): RedirectResponse {
        $validated = $request->validated();

        MerchantMatchingRule::query()->create([
            'user_id' => $request->user()->id,
            'merchant_id' => $merchant->id,
            'match_mode' => $validated['match_mode'],
            'pattern' => $validated['pattern'],
            'is_active' => true,
        ]);

        $matcher->matchForUser($request->user()->id);

        return redirect()
            ->route('merchants.show', $merchant)
            ->with('success', 'Matching rule added.');
    }

    public function update(
        UpdateMerchantMatchingRuleRequest $request,
        Merchant $merchant,
        MerchantMatchingRule $rule,
        MerchantMatcher $matcher,
    ): RedirectResponse {
        $rule->update($request->validated());

        $matcher->matchForUser($request->user()->id);

        return redirect()
            ->route('merchants.show', $merchant)
            ->with('success', 'Matching rule updated.');
    }

    public function destroy(
        Request $request,
        Merchant $merchant,
        MerchantMatchingRule $rule,
        MerchantBrowseService $browse,
    ): RedirectResponse {
        $userId = $request->user()->id;

        if ($rule->merchant_id !== $merchant->id
            || $rule->user_id !== $userId
            || ! $browse->isBrowsable($userId, $merchant)) {
            throw new NotFoundHttpException;
        }

        $rule->delete();

        return redirect()
            ->route('merchants.show', $merchant)
            ->with('success', 'Matching rule deleted.');
    }
}
