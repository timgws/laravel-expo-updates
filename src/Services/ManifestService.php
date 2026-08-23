<?php

namespace LaravelExpoUpdates\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use LaravelExpoUpdates\Models\Manifest;
use LaravelExpoUpdates\Models\Asset;
use LaravelExpoUpdates\Models\Project;
use  \Illuminate\Support\Facades\Log;

/**
 * Service for handling manifest operations.
 */
class ManifestService
{
    /**
     * Get the latest manifest for a project, platform, and runtime version.
     *
     * @param Project|string $project Project instance or slug
     * @param string $platform Platform (ios|android)
     * @param string $runtimeVersion Runtime version
     * @param array|null $filters Optional metadata filters
     * @return array|null
     */
    public function getLatestManifest($project, string $platform, string $runtimeVersion, ?array $filters = null): ?array
    {
        \Illuminate\Support\Facades\Log::info('OTA request received', [
            'platform' => $platform,
            'runtime_version' => $runtimeVersion
        ]);
        
        $project = $this->resolveProject($project);
        if (!$project) {
            \Illuminate\Support\Facades\Log::error('Project not found');
            return null;
        }

        $query = Manifest::query()
            ->where('project_id', $project->id)
            ->where('platform', $platform)
            ->where('runtime_version', $runtimeVersion)
            ->orderBy('created_at', 'desc');

        if ($filters) {
            foreach ($filters as $key => $value) {
                $query->where("metadata->$key", $value);
            }
        }

        $manifest = $query->first();

        if (!$manifest) {
            \Illuminate\Support\Facades\Log::warning('No manifest found for platform/version');
            return null;
        }

        \Illuminate\Support\Facades\Log::info('Manifest found', ['id' => $manifest->id]);

        $extra = is_array($manifest->extra) ? $manifest->extra : [];

        if (!isset($extra['expoClient'])) {
            $extra['scopeKey'] = $project->slug ?? \Illuminate\Support\Str::slug(config('app.name', 'expo-app'));
            $extra['expoClient'] = [
                'name' => $project->name ?? config('app.name', 'Expo App'),
                'slug' => $project->slug ?? \Illuminate\Support\Str::slug(config('app.name', 'expo-app')),
                'version' => $project->version ?? '0.0.0',
                'runtimeVersion' => $runtimeVersion
            ];
        }
        return [
            'id' => $manifest->id,
            'createdAt' => $manifest->created_at->toISOString(),
            'runtimeVersion' => $manifest->runtime_version,
            'launchAsset' => $this->formatAsset($manifest->launchAsset),
            'assets' => $manifest->assets
                ->filter(fn ($asset) => $asset->id !== $manifest->launch_asset_id) // Exclude launch asset from assets array
                ->map(fn ($asset) => $this->formatAsset($asset))
                ->values() // Re-index array after filter
                ->toArray(),
            'metadata' => $manifest->metadata,
            'extra' => $extra,
        ];
    }

    /**
     * Get server-defined headers for a project.
     *
     * @param Project|string $project Project instance or slug
     * @return array|null
     */
    public function getServerDefinedHeaders($project): ?array
    {
        $project = $this->resolveProject($project);
        if (!$project) {
            return null;
        }

        return $project->config['server_headers'] ?? config('expo-updates.server_headers');
    }

    /**
     * Sign a manifest with the project's private key.
     *
     * @param Project|string $project Project instance or slug
     * @param string $manifestJson Manifest JSON string (already encoded)
     * @return string|null
     */
    public function signManifest($project, string $manifestJson): ?string
    {
        $project = $this->resolveProject($project);
        if (!$project) {
            return null;
        }

        if (!config('expo-updates.code_signing.enabled')) {
            return null;
        }

        // Try to get cached signature
        $cacheKey = "manifest_signature:{$project->id}:" . md5($manifestJson);
        if ($signature = Cache::get($cacheKey)) {
            return $signature;
        }
        $privateKeyPath = config('expo-updates.code_signing.private_key_path');
        if (!file_exists($privateKeyPath)) {
            Log::error('Private key not found', ['path' => $privateKeyPath]);
            return null;
        }
        $privateKey = file_get_contents(config('expo-updates.code_signing.private_key_path'));
        if (!$privateKey) {
            return null;
        }

        $signature = '';

        if (openssl_sign($manifestJson, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            $signature = 'sig="' . base64_encode($signature) . '", keyid="main"';
            // Cache the signature for 1 hour
            Cache::put($cacheKey, $signature, now()->addHour());
            return $signature;
        }

        return null;
    }

    /**
     * Format an asset for the manifest response.
     *
     * @param Asset $asset
     * @return array
     */
    public function formatAsset(?Asset $asset): array
    {
        if (!$asset) {
            return [];
        }

        $formatted = [
            'key' => $asset->key,
            'contentType' => $asset->content_type,
            'url' => $asset->url,
        ];

        if ($asset->hash) {
            $base64UrlHash = strtr($asset->hash, '+/', '-_');
            $formatted['hash'] = rtrim($base64UrlHash, '=');
        }

        // ALWAYS include fileExtension (iOS client requires it, crashes if null)
        // Use empty string if extension is already in the key
        $formatted['fileExtension'] = $asset->file_extension ?? '';

        return $formatted;
    }

    /**
     * Resolve a project from a slug or instance.
     *
     * @param Project|string $project
     * @return Project|null
     */
    protected function resolveProject($project): ?Project
    {
        if ($project instanceof Project) {
            return $project;
        }

        return Project::where('slug', $project)->first();
    }

    /**
     * Create a new manifest for a project.
     *
     * @param Project $project
     * @param string $platform
     * @param string $runtimeVersion
     * @param array $metadata
     * @param array $expoConfig
     * @return Manifest
     */
    public function createManifest(Project $project, string $platform, string $runtimeVersion, array $metadata, array $expoConfig): Manifest
    {
        return DB::transaction(function () use ($project, $platform, $runtimeVersion, $metadata, $expoConfig) {
            // Generate new UUID for each manifest (immutable - insert only)
            $uuid = (string) \Illuminate\Support\Str::uuid();
            $manifest = new \LaravelExpoUpdates\Models\Manifest();
            $manifest->id = $uuid;
            $manifest->project_id = $project->id;
            $manifest->platform = $platform;
            $manifest->runtime_version = $runtimeVersion;
            $manifest->metadata = $metadata;
            $manifest->extra = [
                'expoClientVersion' => $expoConfig['version'] ?? null,
                'expoClientVersionExtra' => $expoConfig['extra'] ?? null,
            ];
            $manifest->save();
            return $manifest;
        });
    }
}
