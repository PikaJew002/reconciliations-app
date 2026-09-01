<?php

namespace Tests\Feature\Review;

use App\Services\Review\ReviewWeek;
use Carbon\Carbon;
use Tests\TestCase;

class ReviewWeekTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sunday_afternoon_opens_the_week_that_ended_yesterday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-30 15:00:00'));

        $resolved = app(ReviewWeek::class)->resolve(null);

        $this->assertSame('2026-08-23', $resolved['week']);
        $this->assertSame('2026-08-23', $resolved['from']->toDateString());
        $this->assertSame('2026-08-30', $resolved['to']->toDateString());
        $this->assertSame('Aug 23 – 29', $resolved['label']);
        $this->assertTrue($resolved['is_complete']);
        $this->assertSame('2026-08-16', $resolved['previous_week']);
        $this->assertSame('2026-08-30', $resolved['next_week']);
    }

    public function test_midweek_still_opens_the_last_complete_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 10:00:00'));

        $resolved = app(ReviewWeek::class)->resolve(null);

        $this->assertSame('2026-08-23', $resolved['week']);
        $this->assertTrue($resolved['is_complete']);
        $this->assertSame('2026-08-30', $resolved['next_week']);
    }

    public function test_explicit_week_snaps_to_sunday_and_cannot_go_past_the_current_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-30 15:00:00'));
        $resolver = app(ReviewWeek::class);

        $monday = $resolver->resolve('2026-08-24');
        $this->assertSame('2026-08-23', $monday['week']);

        $future = $resolver->resolve('2026-09-06');
        $this->assertSame('2026-08-30', $future['week']);
        $this->assertFalse($future['is_complete']);
        $this->assertNull($future['next_week']);
    }

    public function test_month_for_week_uses_the_month_with_more_days(): void
    {
        $resolver = app(ReviewWeek::class);
        $from = Carbon::parse('2026-08-30');
        $to = Carbon::parse('2026-09-06');

        $this->assertSame('2026-09', $resolver->monthForWeek($from, $to));
        $this->assertSame('2026-08', $resolver->monthForWeek(
            Carbon::parse('2026-08-23'),
            Carbon::parse('2026-08-30'),
        ));
    }
}
