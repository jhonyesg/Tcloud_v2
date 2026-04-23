## Context

OpenSpec is currently configured with `openspec/` as the base directory containing:
- `openspec/changes/` - stores all change artifacts
- `openspec/specs/` - stores spec files
- `openspec/config.yaml` - configuration

The cloud-storage-system change exists at `openspec/changes/cloud-storage-system/` with full application code (app/, docker/, data/, specs/) and needs to be moved to project root.

## Goals / Non-Goals

**Goals:**
- Move cloud-storage-system contents from `openspec/changes/cloud-storage-system/` to project root
- Preserve all files and directory structure
- Maintain OpenSpec functionality after move
- Update or reconfigure OpenSpec to work with new structure

**Non-Goals:**
- Not moving archived changes from `openspec/changes/archive/`
- Not changing the openspec CLI configuration location
- Not modifying application source code

## Decisions

1. **Use mv command with sudo for permission issues**
   - Some data directories have permission issues
   - sudo required to preserve ownership during move

2. **Maintain openspec/ directory for CLI configuration**
   - Keep `openspec/config.yaml` in place
   - This allows `openspec` CLI to function properly
   - Changes can be configured to use different artifact paths

3. **Preserve archive structure**
   - Archived changes remain in `openspec/changes/archive/`
   - Only active cloud-storage-system moves to root

## Risks / Trade-offs

[Permission errors] → Use sudo for mv operations on data directories

[Path references in code] → May need to update any hardcoded paths referencing openspec/changes/

## Migration Plan

1. Create backup of cloud-storage-system directory
2. Move contents to project root using `sudo mv`
3. Verify all files transferred correctly
4. Test OpenSpec commands still function
5. Remove empty source directory
6. Update any broken references

## Open Questions

- Should we update `openspec/config.yaml` to set a new base path for artifacts?
- Do we need to update `.kilocode/` configuration after move?