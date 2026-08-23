<?php

namespace LaravelExpoUpdates\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use LaravelExpoUpdates\Contracts\ManifestInterface;
use LaravelExpoUpdates\Tests\Factories\ManifestFactory;

/**
 * Represents an update manifest for an Expo project.
 *
 * @property string $id
 * @property string $project_id
 * @property string $platform
 * @property string $runtime_version
 * @property array $metadata
 * @property array $extra
 * @property string $launch_asset_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Project $project
 * @property-read Asset $launchAsset
 * @property-read \Illuminate\Database\Eloquent\Collection<Asset> $assets
 */
class Manifest extends Model implements ManifestInterface
{
    use HasFactory;
    use HasUuids;

    protected $table = 'expo_manifests';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'project_id',
        'platform',
        'runtime_version',
        'metadata',
        'extra',
    ];

    protected $casts = [
        'metadata' => 'array',
        'extra' => 'array',
    ];

    /**
     * Get the project that owns this manifest.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the launch asset for this manifest.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function launchAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'launch_asset_id');
    }

    /**
     * Get the assets associated with this manifest.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function updateStats(): HasMany
    {
        return $this->hasMany(UpdateStat::class);
    }

    public function getMetadata(): array
    {
        return $this->metadata ?? [];
    }

    public function getExtra(): array
    {
        return $this->extra ?? [];
    }

    protected static function newFactory()
    {
        return ManifestFactory::new();
    }
} 