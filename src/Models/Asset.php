<?php

namespace LaravelExpoUpdates\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use LaravelExpoUpdates\Contracts\AssetInterface;
use LaravelExpoUpdates\Tests\Factories\AssetFactory;

/**
 * Represents an asset file for an Expo project update.
 *
 * @property string $id
 * @property string $project_id
 * @property string $manifest_id
 * @property string $key
 * @property string $content_type
 * @property string|null $file_extension
 * @property string $path
 * @property string|null $hash
 * @property string $url
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Project $project
 * @property-read Manifest $manifest
 */
class Asset extends Model implements AssetInterface
{
    use HasFactory;
    use HasUuids;

    protected $table = 'expo_assets';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'manifest_id',
        'project_id',
        'key',
        'content_type',
        'hash',
        'url',
        'file_extension',
        'path',
    ];

    /**
     * Get the project that owns this asset.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the manifest that owns this asset.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getContentType(): string
    {
        return $this->content_type;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getFileExtension(): string
    {
        return $this->file_extension;
    }

    protected static function newFactory()
    {
        return AssetFactory::new();
    }
} 