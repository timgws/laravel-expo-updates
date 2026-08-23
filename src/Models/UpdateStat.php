<?php

namespace LaravelExpoUpdates\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaravelExpoUpdates\Contracts\UpdateStatInterface;
use LaravelExpoUpdates\Tests\Factories\UpdateStatFactory;

/**
 * Model for tracking update statistics.
 */
class UpdateStat extends Model implements UpdateStatInterface
{
    use HasFactory;

    protected $table = 'expo_update_stats';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'project_id',
        'manifest_id',
        'platform',
        'runtime_version',
        'type',
        'count',
        'date'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date' => 'date',
        'count' => 'integer'
    ];

    /**
     * Get the project that owns the stat.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the manifest that owns the stat.
     */
    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function getRuntimeVersion(): string
    {
        return $this->runtime_version;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function getDate(): \DateTimeInterface
    {
        return $this->date;
    }

    protected static function newFactory()
    {
        return UpdateStatFactory::new();
    }
} 