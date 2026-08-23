<?php

namespace LaravelExpoUpdates\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixUniqueConstraint extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'expo:fix-unique-constraint';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix the unique constraint issue on expo_assets table for manifest isolation';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Fixing unique constraint on expo_assets table...');
        
        $driver = DB::getDriverName();
        
        if ($driver !== 'mysql') {
            $this->warn('This command is designed for MySQL databases.');
            $this->warn('For other databases, please manually adjust the constraints.');
            return 1;
        }

        try {
            // Step 1: Add regular index on project_id for FK
            $this->info('Step 1: Adding regular index on project_id...');
            try {
                DB::statement('ALTER TABLE expo_assets ADD INDEX expo_assets_project_id_index (project_id)');
                $this->info('✓ Regular index created');
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), 'Duplicate key name')) {
                    $this->info('✓ Regular index already exists');
                } else {
                    throw $e;
                }
            }

            // Step 2: Drop old unique constraint
            $this->info('Step 2: Dropping old unique constraint expo_assets_project_id_key_unique...');
            try {
                DB::statement('ALTER TABLE expo_assets DROP INDEX expo_assets_project_id_key_unique');
                $this->info('✓ Old unique constraint dropped');
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), "Can't DROP")) {
                    $this->warn('⚠ Old unique constraint does not exist (already removed)');
                } else {
                    throw $e;
                }
            }

            // Step 3: Ensure new unique constraint exists
            $this->info('Step 3: Ensuring new unique constraint expo_assets_manifest_id_key_unique exists...');
            try {
                DB::statement('ALTER TABLE expo_assets ADD UNIQUE INDEX expo_assets_manifest_id_key_unique (manifest_id, `key`)');
                $this->info('✓ New unique constraint created');
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), 'Duplicate key name')) {
                    $this->info('✓ New unique constraint already exists');
                } else {
                    throw $e;
                }
            }

            $this->newLine();
            $this->info('✅ Unique constraint fix completed successfully!');
            $this->info('You can now deploy OTA updates without duplicate entry errors.');
            
            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Error fixing unique constraint: ' . $e->getMessage());
            $this->newLine();
            $this->warn('You may need to fix this manually using SQL:');
            $this->line('  1. CREATE INDEX expo_assets_project_id_index ON expo_assets(project_id);');
            $this->line('  2. ALTER TABLE expo_assets DROP INDEX expo_assets_project_id_key_unique;');
            $this->line('  3. CREATE UNIQUE INDEX expo_assets_manifest_id_key_unique ON expo_assets(manifest_id, `key`);');
            
            return 1;
        }
    }
}
