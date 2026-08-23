-- Fix for removing old unique constraint that blocks manifest isolation
-- Run this ONLY if you get "Duplicate entry" errors with expo_assets_project_id_key_unique

-- Step 1: Add a regular index on project_id for the foreign key if it doesn't exist
CREATE INDEX IF NOT EXISTS expo_assets_project_id_index ON expo_assets(project_id);

-- Step 2: Drop the old unique constraint
ALTER TABLE expo_assets DROP INDEX expo_assets_project_id_key_unique;

-- Step 3: Verify the new unique constraint exists (should be created by migration)
-- If not, create it:
-- CREATE UNIQUE INDEX expo_assets_manifest_id_key_unique ON expo_assets(manifest_id, `key`);

-- Verify the changes
SHOW INDEX FROM expo_assets;
