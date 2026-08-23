<?php

namespace LaravelExpoUpdates\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use LaravelExpoUpdates\Contracts\ProjectInterface;
use LaravelExpoUpdates\Tests\Factories\ProjectFactory;

/**
 * Represents an Expo project that can have multiple updates.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property array $config
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Project extends Model implements ProjectInterface
{
    use HasFactory;
    use HasUuids;

    protected $table = 'expo_projects';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'slug',
        'config',
        'server_headers',
        'asset_headers',
    ];

    protected $casts = [
        'config' => 'array',
        'server_headers' => 'array',
        'asset_headers' => 'array',
    ];

    /**
     * Get the manifests associated with this project.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function manifests(): HasMany
    {
        return $this->hasMany(Manifest::class);
    }

    /**
     * Get the assets associated with this project.
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

    public function getServerHeaders(): array
    {
        return $this->config['server_headers'] ?? [];
    }

    public function getAssetHeaders(): array
    {
        return $this->config['asset_headers'] ?? [];
    }

    protected static function newFactory()
    {
        return ProjectFactory::new();
    }
} 