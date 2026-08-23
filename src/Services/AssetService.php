<?php

namespace LaravelExpoUpdates\Services;

use LaravelExpoUpdates\Models\Asset;
use LaravelExpoUpdates\Models\Project;
use Illuminate\Support\Facades\Storage;

/**
 * Service for handling asset operations.
 */
class AssetService
{
    /**
     * Get an asset by its key for a specific project.
     *
     * @param Project|string $project Project instance or slug
     * @param string $key Asset key
     * @return Asset|null
     */
    public function getAsset($project, string $key): ?Asset
    {
        $project = $this->resolveProject($project);
        if (!$project) {
            return null;
        }

        return Asset::where('project_id', $project->id)
            ->where('key', $key)
            ->first();
    }

    /**
     * Get asset-specific headers for a project.
     *
     * @param Project|string $project Project instance or slug
     * @param string $key Asset key
     * @return array
     */
    public function getAssetHeaders($project, string $key): array
    {
        $project = $this->resolveProject($project);
        if (!$project) {
            return [];
        }

        return $project->config['asset_headers'][$key] ?? config('expo-updates.asset_headers.' . $key, []);
    }

    /**
     * Store an asset for a project.
     *
     * @param Project|string $project Project instance or slug
     * @param string $key Asset key
     * @param string $content Asset content
     * @param string $contentType Asset content type
     * @param string|null $fileExtension Optional file extension
     * @return Asset
     */
    public function storeAsset($project, string $key, string $content, string $contentType, ?string $fileExtension = null): Asset
    {
        // Accept Manifest instance (preferred) or manifest UUID string for backward compatibility
        $manifest_id = null;
        if ($project instanceof \LaravelExpoUpdates\Models\Manifest) {
            $manifest_id = $project->id;
            $project_id = $project->project_id;
        } elseif ($project instanceof \LaravelExpoUpdates\Models\Project) {
            throw new \InvalidArgumentException('storeAsset doit être appelé avec le Manifest, pas le Project');
        } elseif (is_string($project)) {
            $manifest_id = $project;
            $project_id = null;
        } else {
            throw new \InvalidArgumentException('Invalid $project parameter - must be Manifest instance or UUID string');
        }

        $manifest = \LaravelExpoUpdates\Models\Manifest::findOrFail($manifest_id);
        $project_id = $manifest->project_id;

        // Isolated path per manifest: updates/{manifest_uuid}/{key}
        $basePath = 'updates/' . $manifest_id;
        
        // CRITICAL: iOS requires FLAT keys (no slashes)
        // Extract basename first to remove all directory paths
        // Example: "_expo/static/js/ios/index-xxx.hbc" → "index-xxx.hbc" → "index-xxx"
        // Example: "assets/778ffc.png" → "778ffc.png" → "778ffc"
        $basename = basename($key);
        
        // Normalize key: remove extension from basename if present
        // The iOS client constructs path as: {key}.{fileExtension}
        // So we must ensure key doesn't have extension to avoid doubles
        $keyExtension = pathinfo($basename, PATHINFO_EXTENSION);
        
        if ($keyExtension && $keyExtension === $fileExtension) {
            // Remove extension from key (e.g., "index-xxx.hbc" → "index-xxx")
            $normalizedKey = substr($basename, 0, -(strlen($keyExtension) + 1));
        } else {
            $normalizedKey = pathinfo($basename, PATHINFO_FILENAME);
        }
        
        // Deduce extension from content_type if not provided
        if (!$fileExtension || empty($fileExtension)) {
            $fileExtension = $this->getExtensionFromContentType($contentType);
        }
        
        // Build storage path: keep original directory structure for filesystem
        // but use normalized key for database
        $path = $basePath . '/' . $key;
        if (!pathinfo($path, PATHINFO_EXTENSION)) {
            $path .= '.' . ltrim($fileExtension, '.');
        }

        // Store the file
        Storage::disk(config('expo-updates.assets.disk'))->put($path, $content);

        // Use updateOrCreate to avoid duplicate key errors if same file uploaded twice
        // or if multiple files normalize to same key (e.g., index.bundle + index.js)
        $asset = \LaravelExpoUpdates\Models\Asset::updateOrCreate(
            [
                'manifest_id' => $manifest_id,
                'key' => $normalizedKey,
            ],
            [
                'project_id' => $project_id,
                'content_type' => $contentType,
                'file_extension' => $fileExtension,
                'path' => $path,
                'hash' => base64_encode(hash('sha256', $content, true)),
                'url' => Storage::disk(config('expo-updates.assets.disk'))->url($path),
            ]
        );

        return $asset;
    }

    /**
     * Get file extension from content type.
     *
     * @param string $contentType
     * @return string
     */
    protected function getExtensionFromContentType(string $contentType): string
    {
        $mimeMap = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'application/javascript' => 'js',
            'text/javascript' => 'js',
            'application/json' => 'json',
            'text/html' => 'html',
            'text/css' => 'css',
            'font/ttf' => 'ttf',
            'font/otf' => 'otf',
            'font/woff' => 'woff',
            'font/woff2' => 'woff2',
            'font/sfnt' => 'ttf',
            'application/octet-stream' => 'bin',
            'image/vnd.microsoft.icon' => 'ico',
        ];

        return $mimeMap[$contentType] ?? 'bin';
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
}
