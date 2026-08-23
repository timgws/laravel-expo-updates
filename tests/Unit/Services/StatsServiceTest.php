<?php

namespace LaravelExpoUpdates\Tests\Unit\Services;

use LaravelExpoUpdates\Tests\TestCase;
use LaravelExpoUpdates\Services\StatsService;
use LaravelExpoUpdates\Models\Project;
use LaravelExpoUpdates\Models\UpdateStat;
use Illuminate\Support\Facades\DB;

class StatsServiceTest extends TestCase
{
    protected $statsService;
    protected $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statsService = new StatsService();
        $this->project = Project::factory()->create();
    }

    /** @test */
    public function it_records_update_requests()
    {
        $this->statsService->recordRequest($this->project, 'ios', '1.0.0');

        $this->assertDatabaseHas('expo_update_stats', [
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0',
            'type' => 'request',
            'count' => 1,
        ]);
    }

    /** @test */
    public function it_records_successful_upgrades()
    {
        $this->statsService->recordUpgrade($this->project, 'ios', '1.0.0');

        $this->assertDatabaseHas('expo_update_stats', [
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0',
            'type' => 'upgrade',
            'count' => 1,
        ]);
    }

    /** @test */
    public function it_increments_existing_stats()
    {
        // Create initial stat
        UpdateStat::create([
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0',
            'type' => 'request',
            'count' => 1,
            'date' => now()->toDateString()
        ]);

        // Record another request
        $this->statsService->recordRequest($this->project, 'ios', '1.0.0');

        $this->assertDatabaseHas('expo_update_stats', [
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0',
            'type' => 'request',
            'count' => 2,
        ]);
    }

    /** @test */
    public function it_gets_stats_for_project()
    {
        // Create some test stats
        UpdateStat::create([
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0',
            'type' => 'request',
            'count' => 5,
            'date' => now()->toDateString()
        ]);

        UpdateStat::create([
            'project_id' => $this->project->id,
            'platform' => 'android',
            'runtime_version' => '1.0.0',
            'type' => 'upgrade',
            'count' => 3,
            'date' => now()->toDateString()
        ]);

        $stats = $this->statsService->getStats($this->project);

        $this->assertCount(2, $stats);
        $counts = collect($stats)->pluck('count')->sort()->values()->all();
        $this->assertEquals([3, 5], $counts);
    }

    /** @test */
    public function it_filters_stats_by_platform()
    {
        // Create some test stats
        UpdateStat::create([
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0',
            'type' => 'request',
            'count' => 5,
            'date' => now()->toDateString()
        ]);

        UpdateStat::create([
            'project_id' => $this->project->id,
            'platform' => 'android',
            'runtime_version' => '1.0.0',
            'type' => 'request',
            'count' => 3,
            'date' => now()->toDateString()
        ]);

        $stats = $this->statsService->getStats($this->project, 'ios');

        $this->assertCount(1, $stats);
        $this->assertEquals('ios', $stats[0]['platform']);
        $this->assertEquals(5, $stats[0]['count']);
    }

    /** @test */
    public function it_gets_daily_stats()
    {
        // Create stats for different days
        UpdateStat::create([
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0',
            'type' => 'request',
            'count' => 5,
            'date' => now()->subDays(2)->toDateString()
        ]);

        UpdateStat::create([
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0',
            'type' => 'request',
            'count' => 3,
            'date' => now()->subDays(1)->toDateString()
        ]);

        UpdateStat::create([
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0',
            'type' => 'request',
            'count' => 2,
            'date' => now()->toDateString()
        ]);

        $stats = $this->statsService->getDailyStats($this->project, 'ios', 'request', 3);

        $this->assertCount(3, $stats);
        $this->assertEquals(5, $stats[0]['total']);
        $this->assertEquals(3, $stats[1]['total']);
        $this->assertEquals(2, $stats[2]['total']);
    }
} 