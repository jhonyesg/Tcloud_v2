## Why

The OpenSpec workflow currently stores change artifacts in `openspec/changes/` subdirectory, but we want these to live at the project root level for better visibility and easier access. The cloud-storage-system change is currently operational and should be restructured to live at the project root.

## What Changes

- Move `openspec/changes/cloud-storage-system/` contents to project root
- Preserve all artifacts: app/, data/, docker/, specs/, design.md, proposal.md, tasks.md
- Update OpenSpec configuration to reference new locations
- Handle symbolic links or references in openspec/ directory

## Capabilities

### New Capabilities
- `openspec-restructuring`: Configuration changes to support root-level artifact storage

### Modified Capabilities
- None

## Impact

- OpenSpec config.yaml will need path updates
- Existing change workflows continue functioning
- Docker and app directories remain accessible