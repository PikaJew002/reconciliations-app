<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Services\Review\ReviewReportService;
use App\Services\Review\ReviewSlideBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReviewController extends Controller
{
    public function show(Request $request, ReviewReportService $report): Response
    {
        $pass = $request->string('pass')->toString();

        if (! in_array($pass, [ReviewSlideBuilder::PASS_DEFAULT, ReviewSlideBuilder::PASS_ALL], true)) {
            $pass = ReviewSlideBuilder::PASS_DEFAULT;
        }

        $act = $request->string('act')->toString();

        if (! in_array($act, ['open', 'walk', 'close'], true)) {
            $act = 'open';
        }

        $payload = $report->build(
            $request->user()->id,
            $request->string('week')->toString() ?: null,
            $pass,
        );

        $item = $request->string('item')->toString();
        $slideIds = array_column($payload['slides'], 'id');

        if ($item === '' || ! in_array($item, $slideIds, true)) {
            $item = $slideIds[0] ?? null;
        }

        if ($act === 'walk' && $payload['slides'] === []) {
            $act = 'open';
        }

        return Inertia::render('Review/Show', [
            ...$payload,
            'act' => $act,
            'item' => $item,
        ]);
    }
}
