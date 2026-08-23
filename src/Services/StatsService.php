<?php

namespace LaravelExpoUpdates\Services;

use LaravelExpoUpdates\Models\Project;
use LaravelExpoUpdates\Models\UpdateStat;
use Illuminate\Support\Facades\DB;

/**
 * Service for handling update statistics.
 */
class StatsService
{
    /**
     * Record an update request.
     *
     * @param Project $project
     * @param string $platform
     * @param string $runtimeVersion
     * @param string|null $manifestId
     * @return void
     */
    public function recordRequest(Project $project, string $platform, string $runtimeVersion, ?string $manifestId = null): void
    {
        $this->incrementStat($project, $platform, $runtimeVersion, 'request', $manifestId);
    }

    /**
     * Record a successful upgrade.
     *
     * @param Project $project
     * @param string $platform
     * @param string $runtimeVersion
     * @param string|null $manifestId
     * @return void
     */
    public function recordUpgrade(Project $project, string $platform, string $runtimeVersion, ?string $manifestId = null): void
    {
        $this->incrementStat($project, $platform, $runtimeVersion, 'upgrade', $manifestId);
    }

    /**
     * Get stats for a project.
     *
     * @param Project $project
     * @param string|null $platform
     * @param string|null $type
     * @param string|null $startDate
     * @param string|null $endDate
     * @return array
     */
    public function getStats(
        Project $project,
        ?string $platform = null,
        ?string $type = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $query = UpdateStat::where('project_id', $project->id);

        if ($platform) {
            $query->where('platform', $platform);
        }

        if ($type) {
            $query->where('type', $type);
        }

        if ($startDate) {
            $query->where('date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('date', '<=', $endDate);
        }

        return $query->get()->toArray();
    }

    /**
     * Get daily stats for a project.
     *
     * @param Project $project
     * @param string|null $platform
     * @param string|null $type
     * @param int $days
     * @return array
     */
    public function getDailyStats(
        Project $project,
        ?string $platform = null,
        ?string $type = null,
        int $days = 30
    ): array {
        $query = UpdateStat::where('project_id', $project->id)
            ->where('date', '>=', now()->subDays($days));

        if ($platform) {
            $query->where('platform', $platform);
        }

        if ($type) {
            $query->where('type', $type);
        }

        return $query->select(
            'date',
            'platform',
            'type',
            DB::raw('SUM(count) as total')
        )
            ->groupBy('date', 'platform', 'type')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    /**
     * Increment a stat counter.
     *
     * @param Project $project
     * @param string $platform
     * @param string $runtimeVersion
     * @param string $type
     * @param string|null $manifestId
     * @return void
     */
    protected function incrementStat(Project $project, string $platform, string $runtimeVersion, string $type, ?string $manifestId = null): void
    {
        $date = now()->startOfDay();

        $attributes = [
            'project_id' => $project->id,
            'platform' => $platform,
            'runtime_version' => $runtimeVersion,
            'type' => $type,
            'date' => $date,
        ];

        // Add manifest_id if provided
        if ($manifestId) {
            $attributes['manifest_id'] = $manifestId;
        }

        // Use DB::table()->upsert() for atomic operation to avoid race conditions
        DB::table('expo_update_stats')->upsert(
            array_merge($attributes, [
                'count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            // Unique columns (matching expo_stats_unique_index)
            array_keys($attributes),
            // Update these columns on duplicate
            ['count' => DB::raw('count + 1'), 'updated_at' => now()]
        );
    }
} 