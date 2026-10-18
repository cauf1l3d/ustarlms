import { expect, Page } from "@playwright/test";

/** Audit rendered text, including dialogs, rather than selected CSS tokens. */
export async function expectReadable(page: Page) {
  const findings = await page.evaluate(() => {
    const rgb = (value: string) => (value.match(/[\d.]+/g) || []).map(Number);
    const over = (a: number[], b: number[]) => {
      const alpha = a[3] ?? 1;
      return a.slice(0, 3).map((v, i) => v * alpha + b[i] * (1 - alpha));
    };
    const luminance = (color: number[]) =>
      color
        .slice(0, 3)
        .map((v) => v / 255)
        .map((v) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4))
        .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
    const issues: string[] = [];
    const root = document.querySelector("dialog[open]") || document.body;
    for (const el of root.querySelectorAll("*")) {
      if (el.closest("script,style,.sr-only,.sr-svg,option")) continue;
      const texts = [...el.childNodes].filter(
        (n) => n.nodeType === 3 && n.textContent?.trim(),
      );
      if (!texts.length || !el.checkVisibility({ checkVisibilityCSS: true }))
        continue;
      const rect = el.getBoundingClientRect(),
        style = getComputedStyle(el);
      const label = el.textContent?.trim().slice(0, 65);
      const minimum = el.matches(".axis-text") ? 14 : 16;
      if (parseFloat(style.fontSize) < minimum)
        issues.push(`small: ${label} (${style.fontSize})`);
      if (rect.left < -1 || rect.right > innerWidth + 1)
        issues.push(`outside viewport: ${label}`);
      const ancestors: Element[] = [];
      for (let p: Element | null = el; p; p = p.parentElement)
        ancestors.unshift(p);
      let background = [255, 255, 255],
        opacity = 1;
      for (const p of ancestors) {
        const ps = getComputedStyle(p);
        background = over(rgb(ps.backgroundColor), background);
        opacity *= Number(ps.opacity);
      }
      const foreground = rgb(el.tagName === "text" ? style.fill : style.color);
      foreground[3] = (foreground[3] ?? 1) * opacity;
      const light = [
        luminance(background),
        luminance(over(foreground, background)),
      ].sort((a, b) => b - a);
      const contrast = (light[0] + 0.05) / (light[1] + 0.05);
      if (contrast < 4.5)
        issues.push(`contrast ${contrast.toFixed(2)}: ${label}`);
      // Detect a label intruding into the neighbouring field, even without page overflow.
      if (el.matches("dt,dd,.legend-value,.legend-header span")) {
        for (const text of texts) {
          const range = document.createRange();
          range.selectNodeContents(text);
          for (const box of range.getClientRects()) {
            if (box.left < rect.left - 1 || box.right > rect.right + 1)
              issues.push(`text crosses field: ${label}`);
          }
        }
      }
    }
    return issues;
  });
  expect(findings).toEqual([]);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBeTruthy();
}
