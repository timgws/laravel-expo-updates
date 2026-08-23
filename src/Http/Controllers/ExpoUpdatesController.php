<?php

namespace LaravelExpoUpdates\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use LaravelExpoUpdates\Services\ManifestService;
use LaravelExpoUpdates\Services\AssetService;
use LaravelExpoUpdates\Models\Project;

/**
 * Controller for handling Expo Updates protocol requests.
 */
class ExpoUpdatesController extends Controller
{
    protected $manifestService;
    protected $assetService;

    /**
     * Create a new controller instance.
     *
     * @param ManifestService $manifestService
     * @param AssetService $assetService
     */
    public function __construct(ManifestService $manifestService, AssetService $assetService)
    {
        $this->manifestService = $manifestService;
        $this->assetService = $assetService;
    }

    /**
     * Get the latest manifest for a project.
     *
     * @param Request $request
     * @param string|null $projectSlug
     * @return Response
     */
    public function manifest(Request $request, ?string $projectSlug = null)
    {
        \Illuminate\Support\Facades\Log::debug('Manifest request headers', $request->headers->all());

        $project = $this->resolveProject($request, $projectSlug);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $acceptHeader = $request->header('accept');
        $acceptsMultipart = $acceptHeader && str_contains($acceptHeader, 'multipart/mixed');

        $contentType = $request->prefers([
            'application/expo+json',
            'application/json',
            'multipart/mixed',
        ]);

        if (!$contentType) {
            return response()->json(['error' => 'Unsupported response content type'], 406);
        }

        $platform = $request->header('expo-platform');
        $runtimeVersion = $request->header('expo-runtime-version');
        $manifestFilters = $this->parseManifestFilters($request->header('expo-manifest-filters'));

        $manifest = $this->manifestService->getLatestManifest($project, $platform, $runtimeVersion, $manifestFilters);

        if (!$manifest) {
            // No update available, return 204 No Content with appropriate headers
            return response('', 204)
                ->header('expo-protocol-version', '1')
                ->header('expo-sfv-version', '0')
                ->header('cache-control', 'private, max-age=0');
        }

        // Multipart/mixed response format for Expo OTA protocol
        if ($acceptsMultipart) {
            $boundary = 'ExpoBoundary' . uniqid();
            $parts = [];

            // CRITICAL: Encode JSON once to ensure byte-matching with signature
            $manifestJson = json_encode($manifest);

            // Required headers per Expo Updates v1 protocol
            $manifestPartHeaders = [
                "Content-Type: application/json",
                "Content-Disposition: form-data; name=\"manifest\""
            ];

            // Inject signature directly into manifest part (not global HTTP headers)
            if (config('expo-updates.code_signing.enabled')) {
                $signature = $this->manifestService->signManifest($project, $manifestJson);
                if ($signature) {
                    $manifestPartHeaders[] = "expo-signature: $signature";
                }
            }

            // Part 1: Manifest with headers
            $parts[] =
                "--$boundary\r\n" .
                implode("\r\n", $manifestPartHeaders) . "\r\n\r\n" .
                $manifestJson . "\r\n";

            // Parts 2+: Assets (launchAsset + other assets)
            $assets = [];
            if (isset($manifest['launchAsset'])) {
                $assets[] = $manifest['launchAsset'];
            }
            if (!empty($manifest['assets'])) {
                $assets = array_merge($assets, $manifest['assets']);
            }

            // Filter out platform-specific bundles that don't match current platform
            // We need to check the actual file path, not the flat key
            $manifestId = $manifest['id'] ?? null;
            $assets = array_filter($assets, function($asset) use ($platform, $manifestId) {
                // Fetch asset model to get original path
                $assetModel = \LaravelExpoUpdates\Models\Asset::where('manifest_id', $manifestId)
                    ->where('key', $asset['key'] ?? null)
                    ->first();
                
                if (!$assetModel) {
                    return false; // Asset not found, exclude
                }
                
                // Check if it's a platform-specific JS bundle using the stored path
                if (preg_match('#_expo/static/js/(ios|android|web)/#', $assetModel->path, $matches)) {
                    $assetPlatform = $matches[1];
                    // Only include if it matches the requested platform
                    return $assetPlatform === $platform;
                }
                
                // Include all other assets (shared resources like images, fonts)
                return true;
            });

            \Illuminate\Support\Facades\Log::info('Serving assets in multipart response', [
                'manifest_id' => $manifest['id'] ?? 'unknown',
                'platform' => $platform,
                'total_assets' => count($assets),
                'asset_keys' => array_map(fn($a) => $a['key'] ?? 'unknown', $assets)
            ]);

            foreach ($assets as $asset) {
                // Fetch full asset model to get storage path
                $assetModel = \LaravelExpoUpdates\Models\Asset::where('key', $asset['key'] ?? null)
                    ->where('url', $asset['url'] ?? null)
                    ->first();
                $assetPath = $assetModel ? \Illuminate\Support\Facades\Storage::disk(config('expo-updates.assets.disk'))->path($assetModel->path) : null;
                $assetContent = ($assetPath && file_exists($assetPath)) ? file_get_contents($assetPath) : null;
                
                \Illuminate\Support\Facades\Log::info('Asset download', [
                    'key' => $asset['key'] ?? 'unknown',
                    'url' => $asset['url'] ?? 'unknown',
                    'path' => $assetPath,
                    'file_exists' => $assetPath ? file_exists($assetPath) : false,
                    'content_size' => $assetContent ? strlen($assetContent) : 0,
                    'content_type' => $asset['contentType'] ?? 'application/octet-stream'
                ]);
                
                if ($assetContent === null) continue;
                $contentType = $asset['contentType'] ?? 'application/octet-stream';
                
                // Use only the basename for Content-Disposition filename
                // to avoid client-side path issues
                $key = $asset['key'] ?? 'asset';
                $filename = basename($key);
                
                $parts[] =
                    "--$boundary\r\n" .
                    "Content-Type: $contentType\r\n" .
                    "Content-Disposition: inline; filename=\"$filename\"\r\n" .
                    "Content-Location: $key\r\n\r\n" .
                    $assetContent . "\r\n";
            }

            $parts[] = "--$boundary--\r\n";
            $body = implode('', $parts);

            $response = response($body, 200)
                ->header('Content-Type', "multipart/mixed; boundary=$boundary")
                ->header('expo-protocol-version', '1')
                ->header('expo-sfv-version', '0')
                ->header('cache-control', 'private, max-age=0');

            if ($manifestFilters) {
                $response->header('expo-manifest-filters', $this->formatManifestFilters($manifestFilters));
            }
            $serverHeaders = $this->manifestService->getServerDefinedHeaders($project);
            if ($serverHeaders) {
                $response->header('expo-server-defined-headers', $this->formatServerHeaders($serverHeaders));
            }
            return $response;
        }

        // Standard JSON response
        $response = response()->json($manifest, 200, [
            'content-type' => $contentType,
        ]);

        // Add required headers
        $response->header('expo-protocol-version', '1');
        $response->header('expo-sfv-version', '0');
        $response->header('cache-control', 'private, max-age=0');

        // Add manifest filters if provided
        if ($manifestFilters) {
            $response->header('expo-manifest-filters', $this->formatManifestFilters($manifestFilters));
        }

        // Add server-defined headers if any
        $serverHeaders = $this->manifestService->getServerDefinedHeaders($project);
        if ($serverHeaders) {
            $response->header('expo-server-defined-headers', $this->formatServerHeaders($serverHeaders));
        }

        // Handle code signing if enabled
        // CRITICAL: Encode manifest here to guarantee byte-matching with signature
        if (config('expo-updates.code_signing.enabled')) {
            $manifestJson = json_encode($manifest);
            $signature = $this->manifestService->signManifest($project, $manifestJson);
            if ($signature) {
                $response->header('expo-signature', $signature);
            }
        }

        return $response;
    }

    /**
     * Get an asset file.
     *
     * @param Request $request
     * @param string $key
     * @param string|null $projectSlug
     * @return Response
     */
    public function asset(Request $request, string $key, ?string $projectSlug = null)
    {
        $project = $this->resolveProject($request, $projectSlug);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $asset = $this->assetService->getAsset($project, $key);

        if (!$asset) {
            return response()->json(['error' => 'Asset not found'], 404);
        }

        $response = response()->file(
            Storage::disk(config('expo-updates.assets.disk'))->path($asset->path),
            [
                'Content-Type' => $asset->content_type,
                'Cache-Control' => 'public, max-age=' . config('expo-updates.cache.asset_ttl') . ', immutable',
            ]
        );

        // Add any asset-specific headers from extensions
        $assetHeaders = $this->assetService->getAssetHeaders($project, $key);
        foreach ($assetHeaders as $header => $value) {
            $response->header($header, $value);
        }

        return $response;
    }

    /**
     * Parse manifest filters from SFV dictionary format.
     *
     * @param string|null $filters
     * @return array|null
     */
    protected function parseManifestFilters(?string $filters): ?array
    {
        if (!$filters) {
            return null;
        }

        // Parse SFV dictionary format
        $result = [];
        $pairs = explode(',', $filters);
        foreach ($pairs as $pair) {
            $parts = explode('=', trim($pair));
            if (count($parts) === 2) {
                $result[trim($parts[0])] = trim($parts[1], '"');
            }
        }

        return $result;
    }

    /**
     * Format manifest filters to SFV dictionary format.
     *
     * @param array $filters
     * @return string
     */
    protected function formatManifestFilters(array $filters): string
    {
        $pairs = [];
        foreach ($filters as $key => $value) {
            $pairs[] = $key . '="' . $value . '"';
        }
        return implode(', ', $pairs);
    }

    /**
     * Format server headers to SFV dictionary format.
     *
     * @param array $headers
     * @return string
     */
    protected function formatServerHeaders(array $headers): string
    {
        $pairs = [];
        foreach ($headers as $key => $value) {
            $pairs[] = $key . '="' . $value . '"';
        }
        return implode(', ', $pairs);
    }

    /**
     * Resolve a project from the request or slug.
     *
     * @param Request $request
     * @param string|null $projectSlug
     * @return Project|null
     */
    protected function resolveProject(Request $request, ?string $projectSlug = null): ?Project
    {
        // First try to get project from slug in URL
        if ($projectSlug) {
            return Project::where('slug', $projectSlug)->first();
        }

        // Then try to get project from header
        $projectId = $request->header('expo-project-id');
        if ($projectId) {
            return Project::find($projectId);
        }

        // Finally, try to get default project from config
        $defaultProject = config('expo-updates.default_project');
        if ($defaultProject) {
            return Project::where('slug', $defaultProject)->first();
        }

        return null;
    }
} 