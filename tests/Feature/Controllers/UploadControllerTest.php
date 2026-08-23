<?php

namespace LaravelExpoUpdates\Tests\Feature\Controllers;

use LaravelExpoUpdates\Tests\TestCase;
use LaravelExpoUpdates\Models\Project;
use LaravelExpoUpdates\Models\Manifest;
use LaravelExpoUpdates\Models\Asset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class UploadControllerTest extends TestCase
{
    protected $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->project = Project::factory()->create(['slug' => 'test-project']);
    }

    /** @test */
    public function it_validates_required_fields()
    {
        $response = $this->withToken('test-upload-token-123')
            ->postJson('/expo-updates/test-project/upload', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file', 'runtimeVersion', 'commitHash', 'commitMessage']);
    }

    /** @test */
    public function it_validates_file_type()
    {
        $response = $this->withToken('test-upload-token-123')
            ->postJson('/expo-updates/test-project/upload', [
                'file' => UploadedFile::fake()->create('test.txt', 100),
                'runtimeVersion' => '1.0.0',
                'commitHash' => 'abc123',
                'commitMessage' => 'Test commit'
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /** @test */
    public function it_returns_404_for_nonexistent_project()
    {
        $response = $this->withToken('test-upload-token-123')
            ->postJson('/expo-updates/nonexistent/upload', [
                'file' => UploadedFile::fake()->create('test.zip', 100, 'application/zip'),
                'runtimeVersion' => '1.0.0',
                'commitHash' => 'abc123',
                'commitMessage' => 'Test commit'
            ]);

        $response->assertStatus(404);
    }

    /** @test */
    public function it_processes_valid_upload()
    {
        // Create a test zip file with required structure
        $tempBaseDir = storage_path('app/temp');
        if (!is_dir($tempBaseDir)) {
            mkdir($tempBaseDir, 0755, true);
        }
        
        $zipPath = $tempBaseDir . '/test.zip';
        $tempDir = $tempBaseDir . '/extract';
        
        // Clean up any existing directory first
        if (is_dir($tempDir)) {
            $this->removeDirectory($tempDir);
        }
        mkdir($tempDir, 0755, true);

        // Create test files
        file_put_contents($tempDir . '/expoconfig.json', json_encode([
            'version' => '1.0.0',
            'extra' => ['test' => 'value']
        ]));

        mkdir($tempDir . '/ios', 0755, true);
        file_put_contents($tempDir . '/ios/test.js', 'console.log("test");');

        mkdir($tempDir . '/android', 0755, true);
        file_put_contents($tempDir . '/android/test.js', 'console.log("test");');

        // Create zip file
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->addDirToZip($zip, $tempDir, '');
        $zip->close();

        // Clean up temp directory
        $this->removeDirectory($tempDir);

        $response = $this->withToken('test-upload-token-123')
            ->postJson('/expo-updates/test-project/upload', [
                'file' => new UploadedFile($zipPath, 'test.zip', 'application/zip', null, true),
                'runtimeVersion' => '1.0.0',
                'commitHash' => 'abc123',
                'commitMessage' => 'Test commit'
            ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Update uploaded successfully']);

        // Verify manifest was created
        $this->assertDatabaseHas('expo_manifests', [
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0',
            'metadata->commitHash' => 'abc123',
            'metadata->commitMessage' => 'Test commit'
        ]);

        $this->assertDatabaseHas('expo_manifests', [
            'project_id' => $this->project->id,
            'platform' => 'android',
            'runtime_version' => '1.0.0',
            'metadata->commitHash' => 'abc123',
            'metadata->commitMessage' => 'Test commit'
        ]);

        // Verify assets were created
        $this->assertDatabaseHas('expo_assets', [
            'key' => 'test.js',
        ]);

        // Clean up
        unlink($zipPath);
    }

    /** @test */
    public function it_handles_invalid_zip_file()
    {
        $response = $this->withToken('test-upload-token-123')
            ->postJson('/expo-updates/test-project/upload', [
                'file' => UploadedFile::fake()->create('test.zip', 100, 'application/zip'),
                'runtimeVersion' => '1.0.0',
                'commitHash' => 'abc123',
                'commitMessage' => 'Test commit'
            ]);

        $response->assertStatus(500)
            ->assertJsonStructure(['error']);
    }

    /** @test */
    public function it_handles_missing_expo_config()
    {
        // Create a test zip file without expoconfig.json  
        $tempDir = storage_path('app/temp/extract_' . uniqid());
        mkdir($tempDir, 0755, true);
        
        // Create an empty file just so zip has something
        file_put_contents($tempDir . '/dummy.txt', 'dummy');

        // Create zip file
        $zipPath = storage_path('app/temp/test_' . uniqid() . '.zip');
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->fail('Failed to create test zip file');
        }
        $this->addDirToZip($zip, $tempDir, '');
        $zip->close();

        $response = $this->withToken('test-upload-token-123')
            ->postJson('/expo-updates/test-project/upload', [
                'file' => new UploadedFile($zipPath, 'test.zip', 'application/zip', null, true),
                'runtimeVersion' => '1.0.0',
                'commitHash' => 'abc123',
                'commitMessage' => 'Test commit'
            ]);

        $response->assertStatus(500)
            ->assertJsonStructure(['error']);

        // Clean up
        unlink($zipPath);
        $this->removeDirectory($tempDir);
    }

    protected function addDirToZip(ZipArchive $zip, string $dir, string $basePath): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }

            $filePath = $file->getRealPath();
            $relativePath = $basePath . substr($filePath, strlen($dir) + 1);
            $zip->addFile($filePath, $relativePath);
        }
    }

    protected function removeDirectory(string $dir): void
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