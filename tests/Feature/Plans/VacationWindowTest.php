<?php

namespace Tests\Feature\Plans;

use App\Models\User;
use App\Models\VacationWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VacationWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_plans_page_includes_vacation_windows(): void
    {
        $user = User::factory()->create();
        VacationWindow::factory()->create([
            'user_id' => $user->id,
            'name' => 'Hawaii',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-10',
        ]);

        $this->actingAs($user)
            ->get(route('plans.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->has('vacation_windows', 1)
                ->where('vacation_windows.0.name', 'Hawaii')
                ->where('vacation_windows.0.starts_on', '2026-08-01')
                ->where('vacation_windows.0.ends_on', '2026-08-10'));
    }

    public function test_user_can_create_a_vacation_window(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('plans.index', ['month' => '2026-08']))
            ->post(route('plans.vacation-windows.store'), [
                'name' => 'Hawaii',
                'starts_on' => '2026-08-01',
                'ends_on' => '2026-08-10',
                'month' => '2026-08',
            ])
            ->assertRedirect(route('plans.index', ['month' => '2026-08']))
            ->assertSessionHas('success');

        $window = VacationWindow::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($window);
        $this->assertSame('Hawaii', $window->name);
        $this->assertSame('2026-08-01', $window->starts_on->toDateString());
        $this->assertSame('2026-08-10', $window->ends_on->toDateString());
    }

    public function test_user_can_create_an_unnamed_vacation_window(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('plans.vacation-windows.store'), [
                'starts_on' => '2026-08-01',
                'ends_on' => '2026-08-10',
            ])
            ->assertRedirect(route('plans.index'));

        $window = VacationWindow::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($window);
        $this->assertNull($window->name);
        $this->assertSame('2026-08-01', $window->starts_on->toDateString());
        $this->assertSame('2026-08-10', $window->ends_on->toDateString());
    }

    public function test_end_date_cannot_precede_start_date(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('plans.index'))
            ->post(route('plans.vacation-windows.store'), [
                'starts_on' => '2026-08-10',
                'ends_on' => '2026-08-01',
            ])
            ->assertRedirect(route('plans.index'))
            ->assertSessionHasErrors('ends_on');

        $this->assertDatabaseCount('vacation_windows', 0);
    }

    public function test_user_can_delete_own_vacation_window(): void
    {
        $user = User::factory()->create();
        $window = VacationWindow::factory()->create([
            'user_id' => $user->id,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-10',
        ]);

        $this->actingAs($user)
            ->from(route('plans.index', ['month' => '2026-08']))
            ->delete(route('plans.vacation-windows.destroy', $window), [
                'month' => '2026-08',
            ])
            ->assertRedirect(route('plans.index', ['month' => '2026-08']));

        $this->assertDatabaseMissing('vacation_windows', ['id' => $window->id]);
    }

    public function test_user_cannot_delete_another_users_vacation_window(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $window = VacationWindow::factory()->create([
            'user_id' => $other->id,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-10',
        ]);

        $this->actingAs($user)
            ->delete(route('plans.vacation-windows.destroy', $window))
            ->assertForbidden();

        $this->assertDatabaseHas('vacation_windows', ['id' => $window->id]);
    }
}
