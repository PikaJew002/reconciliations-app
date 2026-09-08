<?php

namespace App\Http\Controllers\Plans;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plans\StoreVacationWindowRequest;
use App\Models\VacationWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VacationWindowController extends Controller
{
    public function store(StoreVacationWindowRequest $request): RedirectResponse
    {
        VacationWindow::query()->create([
            'user_id' => $request->user()->id,
            'name' => $request->name(),
            'starts_on' => $request->startsOn(),
            'ends_on' => $request->endsOn(),
        ]);

        return redirect()
            ->route('plans.index', array_filter(['month' => $request->viewMonth()]))
            ->with('success', 'Vacation window added.');
    }

    public function destroy(Request $request, VacationWindow $vacationWindow): RedirectResponse
    {
        abort_unless($vacationWindow->user_id === $request->user()->id, 403);

        $vacationWindow->delete();

        return redirect()
            ->route('plans.index', array_filter([
                'month' => $request->string('month')->toString() ?: null,
            ]))
            ->with('success', 'Vacation window removed.');
    }
}
