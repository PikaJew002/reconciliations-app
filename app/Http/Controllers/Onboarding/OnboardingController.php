<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SkipOnboardingStepRequest;
use App\Http\Requests\Onboarding\UpdateOnboardingTourRequest;
use App\Services\Onboarding\OnboardingPayload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function hide(Request $request): RedirectResponse
    {
        $request->session()->forget(OnboardingPayload::FORCE_VISIBLE_SESSION_KEY);

        $request->user()->forceFill([
            'onboarding_hidden_at' => now(),
        ])->save();

        return back();
    }

    public function show(Request $request): RedirectResponse
    {
        $request->session()->put(OnboardingPayload::FORCE_VISIBLE_SESSION_KEY, true);

        $request->user()->forceFill([
            'onboarding_hidden_at' => null,
        ])->save();

        return back();
    }

    public function skip(SkipOnboardingStepRequest $request): RedirectResponse
    {
        $user = $request->user();
        $skipped = $user->onboarding_skipped_steps ?? [];
        $step = $request->validated('step');

        if (! in_array($step, $skipped, true)) {
            $skipped[] = $step;

            $user->forceFill([
                'onboarding_skipped_steps' => $skipped,
            ])->save();
        }

        return back();
    }

    public function updateTour(UpdateOnboardingTourRequest $request): RedirectResponse
    {
        $user = $request->user();
        $tours = $user->onboarding_tours ?? [];
        $tours[$request->validated('key')] = $request->validated('status');

        $user->forceFill([
            'onboarding_tours' => $tours,
        ])->save();

        return back();
    }
}
