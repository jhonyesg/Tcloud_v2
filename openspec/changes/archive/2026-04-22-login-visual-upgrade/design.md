## Context

The login page (`app/resources/views/auth/login.blade.php`) currently uses a simple design:
- Solid brand-900 background
- White solid card with shadow
- Basic form inputs
- Hardcoded test credentials displayed

The user wants to apply visual effects inspired by a reference design: animated particles, gradient orbs, glassmorphism card, and feature badges.

## Goals / Non-Goals

**Goals:**
- Add animated particle background with 250 particles
- Add 5 animated gradient orbs using brand colors
- Add subtle grid pattern overlay
- Transform card to glassmorphism style
- Update inputs to glass style with focus glow
- Add feature badges strip
- Remove hardcoded credentials

**Non-Goals:**
- No backend/auth logic changes
- No new API endpoints
- No database changes
- Not applying to other pages (register, forgot password)

## Decisions

### 1. Implementation approach: Inline styles in blade vs separate CSS file

**Decision:** Use `<style>` block embedded in the blade template

**Rationale:**
- Keeps changes self-contained in `login.blade.php`
- No need to modify build pipeline or add new CSS files
- Easier to deploy - single file change
- Reference design uses inline styles

**Alternative:** Extract to separate CSS file
- Would require modifying build process
- More maintainable for reusable components
- Postponed for future iteration

### 2. Particle count: 250 (increased from reference's 168)

**Decision:** 250 particles for richer visual density

**Rationale:**
- Fills larger viewport better
- More impressive on first impression
- Minimal performance impact with modern hardware

### 3. Color palette: Brand colors only

**Decision:** Use existing brand-* colors instead of adding new colors

Brand mapping:
- `brand-500` (#2d5aa0) → Orb 1 primary, input glow
- `brand-400` (#4e75b6) → Orb 2 secondary
- `brand-300` (#7a97c9) → Orb 3 tertiary, particles
- `brand-800` (#081d4a) → Card glass background @ 90%
- `brand-900` (#03153C) → Page background, input glass @ 55%

### 4. Glass effects using backdrop-filter

**Decision:** CSS `backdrop-filter: blur()` with fallbacks

- Card: `backdrop-blur: 24px`, `background: rgba(8, 29, 74, 0.9)`
- Inputs: `backdrop-blur: 8px`, `background: rgba(3, 21, 60, 0.55)`
- Focus glow: `box-shadow: 0 0 0 4px rgba(45, 90, 160, 0.2), 0 0 30px rgba(45, 90, 160, 0.12)`

## Risks / Trade-offs

| Risk | Mitigation |
|------|------------|
| `backdrop-filter` not supported in older browsers | Graceful degradation - effects are decorative only, core functionality unaffected |
| Performance on low-end devices | Particles capped at 250, canvas uses requestAnimationFrame |
| Form inputs hard to read on glass background | High contrast text (white on dark glass), proper placeholder contrast |

## Open Questions

- Should register/forgot-password pages get the same treatment?
- Will mobile need simplified effects (fewer particles, no orbs)?
