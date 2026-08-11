<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Console\Commands\SchedulerHeartbeatCommand;
use App\Filament\Widgets\PipelineHealthWidget;
use App\Models\Submission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The dashboard widget
|--------------------------------------------------------------------------
|
| Rendered rather than merely constructed. A widget that is registered on the
| panel and never exercised is the same shape of gap as a pipeline stage with no
| caller: it looks present, it is covered by nothing, and it breaks the first
| time a real person opens the page.
|
*/

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create());

    Cache::put(SchedulerHeartbeatCommand::CACHE_KEY, CarbonImmutable::now()->toIso8601String(), 3600);
});

it('renders every check as a stat', function (): void {
    Livewire::test(PipelineHealthWidget::class)->assertOk();
});

it('shows the counts the public endpoint withholds', function (): void {
    Submission::factory()->count(3)->create([
        'status' => Submission::STATUS_NEEDS_REVIEW,
        'observed_at' => CarbonImmutable::now(),
    ]);

    // The whole point of the split: the operator sees the number, the public
    // sees only whether the platform is keeping up.
    Livewire::test(PipelineHealthWidget::class)
        ->assertOk()
        ->assertSee('Awaiting review')
        ->assertSee('3');
});

it('says plainly when the clock has stopped', function (): void {
    Cache::forget(SchedulerHeartbeatCommand::CACHE_KEY);

    Livewire::test(PipelineHealthWidget::class)
        ->assertOk()
        ->assertSee('Scheduler');
});

it('is registered on the panel, not merely written', function (): void {
    // The gap this whole phase exists to close, in miniature.
    expect(Filament::getPanel('admin')->getWidgets())->toContain(PipelineHealthWidget::class);
});

it('is not reachable by a guest', function (): void {
    auth()->logout();

    $this->get('/admin')->assertRedirect('/admin/login');
});
