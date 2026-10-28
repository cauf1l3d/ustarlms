# Studio canvas zoom and independent panels

Owner request28Oct: zoom the material page with a slider and hide/restore each side panel.

Use the existing Academy shell and controls. The material alone scales from50% to150%
in5% steps, with a visible percentage/reset100% button. Text, images, tables and page
geometry scale together; toolbar, Academy navigation and published/exported courses do
not change. Preserve the DOM, selection and the visible position while scaling. The
scaled paper must occupy its actual scroll footprint and stay reachable at both edges.

The existing «Экраны» and «Свойства» controls are always available and independently
hide/restore the inline panels. On narrow layouts retain the existing temporary drawers,
Escape and focus return. Focus mode temporarily hides both panels and restores the
previous individual choices. Explicit properties/image settings reveal the right panel.
Store zoom and inline panel choices in the existing user/site UI key; no course changes,
new dependencies, new API or schema. Keep the compact header independent.

Approach: use browser layout zoom on a measured fixed natural paper width, preserving
normal text hit testing and scroll size. A transform-only approach needs separate height
measurement and can leave unreachable overflow; changing font size would alter authored
content. Keep all panel visibility decisions in the existing editor controller.

Implementation choices:50–150%,5% steps,100% initial/reset; native range input with
keyboard support and aria-valuetext; footer placement avoids a new toolbar row. These
are routine defaults within the owner's specified request. Continue inline; AGENTS
forbids subagents and production SSH. Push remains authorized, PR107 merge is not.
