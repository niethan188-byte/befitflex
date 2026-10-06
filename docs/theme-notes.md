# The liquid glass theme

## Where the colours come from

The palette is lifted straight off the banner: pure red `#E10600` on near-black
`#050506`, with a hotter `#FF2A20` for highlights and a deep `#8B0000` for the
lower half of button gradients. Everything else is white at low opacity.

## What makes it read as glass

Frosted panels only look like glass when something is moving behind them.
Four layers, in order:

**1. The light field.** Three radial blooms in red and dark crimson drift on
26–32 second loops behind everything, over a faint 56px grid that is masked to
fade at the edges. Without this the panels are just grey rectangles.

**2. Backdrop blur with saturation.** `backdrop-filter: saturate(180%) blur(22px)`.
The saturation boost is what stops the blurred red from going muddy grey.

**3. The specular edge.** A 1px gradient border drawn with a mask-composite
trick — bright white at the top-left corner, transparent through the middle,
red at the bottom-right. This is the single detail that sells the material:
real glass catches light on one edge and not the others.

**4. Inner shading.** `inset 0 1px 0 rgba(255,255,255,.20)` on top and a dark
inset at the bottom give each panel thickness rather than flatness.

## Interaction

Cards marked `.glass.hover` lift 3px, shift their border toward red, and deepen
their shadow. The pointer position is tracked into `--mx` / `--my` custom
properties so a sheen can follow the cursor.

## Accessibility and fallbacks

- `prefers-reduced-motion: reduce` stops the blobs entirely.
- Browsers without `backdrop-filter` (older Firefox) get flat translucent panels —
  legible, just not frosted. Nothing breaks.
- Body text is `#F3F4F6` on near-black, well past AA contrast. Muted text is
  reserved for secondary labels, never for anything you must read.
- A print stylesheet strips the glass, the blobs and the sidebar, so the reports
  and workout plans print as clean black-on-white.

## Reusing the primitives

```html
<div class="panel glass">…</div>              <!-- section container -->
<div class="stat glass hover">…</div>         <!-- KPI card -->
<span class="chip ok|warn|bad|mute">…</span>  <!-- status pill -->
<button class="btn red|danger|sm|icon">…</button>
<div class="bar"><span style="width:60%"></span></div>
```

The whole system is one 700-line stylesheet with no build step, no framework and
no dependencies beyond Font Awesome and Inter from a CDN.
