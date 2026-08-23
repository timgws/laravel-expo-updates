<?php

namespace LaravelExpoUpdates\Tests\Unit\Services;

use LaravelExpoUpdates\Tests\TestCase;
use LaravelExpoUpdates\Services\ManifestService;
use LaravelExpoUpdates\Models\Project;
use LaravelExpoUpdates\Models\Manifest;
use LaravelExpoUpdates\Models\Asset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

class ManifestServiceTest extends TestCase
{
    protected $manifestService;
    protected $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifestService = new ManifestService();
        $this->project = Project::factory()->create([
            'slug' => 'test-project',
            'config' => [
                'server_headers' => ['test-header' => 'test-value']
            ]
        ]);
    }

    /** @test */
    public function it_can_get_latest_manifest()
    {
        $manifest = Manifest::factory()->create([
            'project_id' => $this->project->id,
            'platform' => 'ios',
            'runtime_version' => '1.0.0',
            'metadata' => ['test' => 'value']
        ]);

        $result = $this->manifestService->getLatestManifest(
            $this->project,
            'ios',
            '1.0.0',
            ['test' => 'value']
        );

        $this->assertNotNull($result);
        $this->assertEquals($manifest->id, $result['id']);
        $this->assertEquals('1.0.0', $result['runtimeVersion']);
        $this->assertEquals(['test' => 'value'], $result['metadata']);
    }

    /** @test */
    public function it_returns_null_for_nonexistent_manifest()
    {
        $result = $this->manifestService->getLatestManifest(
            $this->project,
            'ios',
            '1.0.0'
        );

        $this->assertNull($result);
    }

    /** @test */
    public function it_can_get_server_defined_headers()
    {
        $headers = $this->manifestService->getServerDefinedHeaders($this->project);

        $this->assertEquals(['test-header' => 'test-value'], $headers);
    }

    /** @test */
    public function it_returns_default_headers_when_project_has_none()
    {
        Config::set('expo-updates.server_headers', ['default' => 'value']);
        
        $project = Project::factory()->create([
            'config' => []
        ]);

        $headers = $this->manifestService->getServerDefinedHeaders($project);

        $this->assertEquals(['default' => 'value'], $headers);
    }

    /** @test */
    public function it_can_sign_manifest()
    {
        Config::set('expo-updates.code_signing.enabled', true);
        Config::set('expo-updates.code_signing.private_key_path', __DIR__ . '/../../test-keys/private.key');

        $manifest = ['test' => 'value'];
        $manifestJson = json_encode($manifest);
        $signature = $this->manifestService->signManifest($this->project, $manifestJson);

        $this->assertNotNull($signature);
        $this->assertStringStartsWith('sig="', $signature);
    }

    /** @test */
    public function it_caches_manifest_signatures()
    {
        Config::set('expo-updates.code_signing.enabled', true);
        Config::set('expo-updates.code_signing.private_key_path', __DIR__ . '/../../test-keys/private.key');

        $manifest = ['test' => 'value'];
        $manifestJson = json_encode($manifest);
        
        // First call should generate signature
        $signature1 = $this->manifestService->signManifest($this->project, $manifestJson);
        
        // Second call should use cached signature
        $signature2 = $this->manifestService->signManifest($this->project, $manifestJson);

        $this->assertEquals($signature1, $signature2);
    }

    /** @test */
    public function it_can_create_manifest()
    {
        $manifest = $this->manifestService->createManifest(
            $this->project,
            'ios',
            '1.0.0',
            ['commitHash' => 'abc123'],
            ['version' => '1.0.0', 'extra' => ['test' => 'value']]
        );

        $this->assertInstanceOf(Manifest::class, $manifest);
        $this->assertEquals($this->project->id, $manifest->project_id);
        $this->assertEquals('ios', $manifest->platform);
        $this->assertEquals('1.0.0', $manifest->runtime_version);
        $this->assertEquals(['commitHash' => 'abc123'], $manifest->metadata);
        $this->assertEquals([
            'expoClientVersion' => '1.0.0',
            'expoClientVersionExtra' => ['test' => 'value']
        ], $manifest->extra);
    }

    /** @test */
    public function it_formats_assets_correctly()
    {
        $asset = Asset::factory()->create([
            'key' => 'test-asset',
            'content_type' => 'application/json',
            'url' => 'https://example.com/test.json',
            'hash' => 'abc123',
            'file_extension' => 'json'
        ]);

        $formatted = $this->manifestService->formatAsset($asset);

        $this->assertEquals([
            'key' => 'test-asset',
            'contentType' => 'application/json',
            'url' => 'https://example.com/test.json',
            'hash' => 'abc123',
            'fileExtension' => 'json'
        ], $formatted);
    }
} 