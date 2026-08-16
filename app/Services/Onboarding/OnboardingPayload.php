<?php

namespace App\Services\Onboarding;

use App\Models\User;
use Illuminate\Http\Request;

class OnboardingPayload
{
    public const FORCE_VISIBLE_SESSION_KEY = 'onboarding_force_visible';

    public function __construct(
        protected OnboardingSteps $steps,
        protected Request $request,
    ) {}

    /**
     * @return array{
     *     visible: bool,
     *     finished: bool,
     *     percentage: int,
     *     steps: list<array<string, mixed>>
     * }
     */
    public function for(User $user): array
    {
        $payload = $this->visiblePayload($user);

        if ($this->request->session()->get(self::FORCE_VISIBLE_SESSION_KEY)) {
            return $payload;
        }

        if ($user->onboarding_hidden_at !== null) {
            $payload['visible'] = false;

            return $payload;
        }

        if ($payload['finished']) {
            $user->forceFill(['onboarding_hidden_at' => now()])->save();
            $payload['visible'] = false;

            return $payload;
        }

        return $payload;
    }

    /**
     * @return array{
     *     visible: true,
     *     finished: bool,
     *     percentage: int,
     *     steps: list<array<string, mixed>>
     * }
     */
    protected function visiblePayload(User $user): array
    {
        $snapshot = OnboardingSnapshot::for($user);
        $skipped = $user->onboarding_skipped_steps ?? [];
        $tours = $user->onboarding_tours ?? [];

        $steps = [];

        foreach ($this->steps->all() as $definition) {
            if ($definition['skippable'] && in_array($definition['key'], $skipped, true)) {
                continue;
            }

            $complete = (bool) $definition['completeIf']($snapshot);
            $tourKey = $definition['tour'];
            $tourState = $tours[$tourKey] ?? null;

            $steps[] = [
                'key' => $definition['key'],
                'title' => $definition['title'],
                'description' => $definition['description'],
                'cta' => $definition['cta'],
                'href' => $definition['href']($snapshot),
                'complete' => $complete,
                'skippable' => $definition['skippable'],
                'tour' => in_array($tourState, ['completed', 'dismissed'], true) ? null : $tourKey,
            ];
        }

        $total = count($steps);
        $completedCount = count(array_filter($steps, fn (array $step): bool => $step['complete']));
        $finished = $total === 0 || $completedCount === $total;

        return [
            'visible' => true,
            'finished' => $finished,
            'percentage' => $total === 0 ? 100 : (int) round(($completedCount / $total) * 100),
            'steps' => $steps,
        ];
    }
}
