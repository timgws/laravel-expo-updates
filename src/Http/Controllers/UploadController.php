<?php

namespace LaravelExpoUpdates\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use LaravelExpoUpdates\Services\ManifestService;
use LaravelExpoUpdates\Services\AssetService;
use LaravelExpoUpdates\Models\Project;
use LaravelExpoUpdates\Models\Manifest;
use ZipArchive;

/**
 * Controller for handling OTA updates uploads.
 */
class UploadController extends Controller
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
     * Handle the upload of an OTA update.
     *
     * @param Request $request
     * @return Response
     */
    public function upload(Request $request, ?string $projectSlug = null)
    {
        $resolvedProjectSlug = $projectSlug ?: $request->input('projectSlug');

        $request->validate([
            'file' => 'required|file|mimes:zip|max:512000',
            'runtimeVersion' => 'required|string|max:255',
            'commitHash' => 'required|string|max:128',
            'commitMessage' => 'required|string|max:1000',
        ]);

        if (!$resolvedProjectSlug) {
            return response()->json(['error' => 'projectSlug is required'], 422);
        }

        $project = Project::where('slug', $resolvedProjectSlug)->first();
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $zipFile = $request->file('file');
        $tempPath = storage_path('app/temp/' . uniqid());
        mkdir($tempPath, 0755, true);

        try {
            // Extract the zip file
            $zip = new ZipArchive;
            if ($zip->open($zipFile->getPathname()) !== true) {
                throw new \Exception('Failed to open zip file');
            }
            $zip->extractTo($tempPath);
            $zip->close();

            // Read the expo config
            $expoConfig = json_decode(file_get_contents($tempPath . '/expoconfig.json'), true);
            if (!$expoConfig) {
                throw new \Exception('Failed to read expo config');
            }

            // Process iOS and Android assets
            $platforms = ['ios', 'android'];
            foreach ($platforms as $platform) {
                $platformPath = $tempPath . '/' . $platform;
                if (!is_dir($platformPath)) {
                    continue;
                }

                // Create manifest
                $manifest = $this->manifestService->createManifest(
                    $project,
                    $platform,
                    $request->runtimeVersion,
                    [
                        'commitHash' => $request->commitHash,
                        'commitMessage' => $request->commitMessage,
                    ],
                    $expoConfig
                );

                // Process assets
                $this->processAssets($project, $manifest, $platformPath);
            }

            return response()->json(['message' => 'Update uploaded successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        } finally {
            // Clean up
            if (is_dir($tempPath)) {
                $this->removeDirectory($tempPath);
            }
        }
    }

    /**
     * Process assets for a platform.
     *
     * @param Project $project
     * @param Manifest $manifest
     * @param string $platformPath
     * @return void
     */
    protected function processAssets(Project $project, Manifest $manifest, string $platformPath)
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($platformPath),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        $launchAssetFound = false;
        $platform = $manifest->platform; // Get the manifest platform
        
        // Files to exclude (metadata, not actual assets)
        $excludedFiles = ['metadata.json', 'expoconfig.json', '.expo-internal'];
        
        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($platformPath) + 1);
            $filename = $file->getFilename();
            
            // Skip metadata files
            if (in_array($filename, $excludedFiles) || str_starts_with($filename, '.')) {
                continue;
            }
            
            $content = file_get_contents($file->getPathname());
            $fileExtension = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
            
            // Detect platform-specific launch bundle
            // Modern Expo: _expo/static/js/{platform}/index-{hash}.hbc (must match manifest platform)
            // Legacy: index.bundle
            // Note: relativePath includes extension, but stored key won't
            $isLaunchAsset = ($relativePath === 'index.bundle') || 
                             preg_match("#^_expo/static/js/{$platform}/index-[a-f0-9]+\.(hbc|js)$#", $relativePath);
            
            // Determine correct Content-Type
            // Launch asset (.hbc or .js bundle) should be application/javascript
            // Other assets use mime type detection
            if ($isLaunchAsset) {
                $contentType = 'application/javascript';
            } elseif ($fileExtension === 'hbc') {
                // Non-launch .hbc files are octet-stream
                $contentType = 'application/octet-stream';
            } else {
                $contentType = mime_content_type($file->getPathname()) ?: 'application/octet-stream';
            }

            // Pass Manifest instance to storeAsset for manifest-isolated storage
            // storeAsset will normalize the key (remove extension) and store extension separately
            // It also saves the asset directly in the database with the correct manifest_id
            $asset = $this->assetService->storeAsset(
                $manifest,
                $relativePath,
                $content,
                $contentType,
                $fileExtension
            );

            // Set as launch asset if this is the main bundle
            if ($asset && $isLaunchAsset && !$launchAssetFound) {
                $manifest->launch_asset_id = $asset->id;
                $manifest->save();
                $launchAssetFound = true;
            }
            // Note: All assets (including launch) are already saved by storeAsset()
            // No need to call $manifest->assets()->save() as it would duplicate
        }
    }

    /**
     * Recursively remove a directory.
     *
     * @param string $dir
     * @return void
     */
    protected function removeDirectory(string $dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
