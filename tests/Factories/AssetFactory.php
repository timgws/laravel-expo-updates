<?php

namespace LaravelExpoUpdates\Tests\Factories;

use LaravelExpoUpdates\Models\Asset;
use LaravelExpoUpdates\Models\Manifest;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition()
    {
        $manifest = Manifest::factory();

        return [
            'manifest_id' => $manifest,
            'project_id' => function (array $attributes) {
                $manifest = \LaravelExpoUpdates\Models\Manifest::find($attributes['manifest_id']);
                return $manifest?->project_id;
            },
            'key' => $this->faker->unique()->word . '.js',
            'content_type' => 'application/javascript',
            'path' => 'updates/' . $this->faker->uuid() . '/test.js',
            'url' => $this->faker->url,
            'hash' => $this->faker->sha256,
            'file_extension' => 'js'
        ];
    }
} 