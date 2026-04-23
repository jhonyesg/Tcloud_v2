## Context

The platform currently uses gradient backgrounds (e.g., `from-slate-900 to-slate-800`, `from-blue-600 to-purple-600`) in several views. The user wants to replace these with solid colors using a defined palette, starting with `#03153C` as the primary dark color.

Currently affected views:
- `shares/public.blade.php`: Background gradient and header gradient
- `shares/public-password.blade.php`: Background gradient  
- `shares/public-not-found.blade.php`: Background gradient
- `shares/public-expired.blade.php`: Background gradient
- `files/index.blade.php`: Icon gradient backgrounds

## Goals / Non-Goals

**Goals:**
- Define a cohesive color palette with #03153C as primary
- Replace all gradient backgrounds with solid colors
- Apply palette consistently across all affected views

**Non-Goals:**
- Changing text colors that already have good contrast
- Modifying component logic or functionality (styling only)
- Creating CSS custom properties or separate stylesheet

## Decisions

1. **Color Palette Based on #03153C**

   | Token | Hex | Usage |
   |-------|-----|-------|
   | `brand-900` | #03153C | Primary dark (backgrounds) |
   | `brand-800` | #0A1F4D | Secondary dark |
   | `brand-700` | #0F2966 | Accent backgrounds |
   | `brand-600` | #1A3D8F | Interactive elements |
   | `brand-500` | #2451B8 | Hover states |
   | `brand-400` | #4A6FD9 | Active states |

2. **View-Specific Mappings**

   | View | Current | New |
   |------|---------|-----|
   | Public shares pages | `from-slate-900 to-slate-800` | `bg-[#03153C]` |
   | Public shares header | `from-blue-600 to-purple-600` | `bg-[#0A1F4D]` |
   | File icons | `from-blue-600 to-purple-600` | `bg-[#2451B8]` |

3. **Implementation Approach**

   Use inline Tailwind classes with hex values via `bg-[#XXXXXX]` syntax for custom colors. This avoids creating new CSS variables and works within the existing Tailwind setup.

## Risks / Trade-offs

- **Risk**: Tailwind's `bg-[#XXXXXX]` generates more CSS output → **Mitigation**: Acceptable for small number of custom colors
- **Risk**: Hardcoded hex values less maintainable than CSS variables → **Mitigation**: Simple change, can refactor to variables later if needed