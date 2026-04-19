/**
 * @file layout.spec.js
 *
 * Gap 2: Browser-level layout test asserting the Scolta results container
 * fills ≥90 % of the viewport width at a desktop breakpoint (1440 px).
 *
 * Pre-fix: no browser-level layout test existed. A regression that added a
 * constraining max-width or accidentally wrapped the layout in a narrow
 * container would go undetected.
 * Post-fix: this test catches any CSS change that narrows the results area
 * below 90 % of viewport width on a 1440 px screen.
 *
 * Design notes:
 * - Uses page.setContent() to load a static HTML fixture with the real
 *   scolta.css inlined. No running Drupal site required.
 * - The fixture sets #scolta-layout to display:block (normally hidden until
 *   search runs) so we can measure layout width without mocking Pagefind.
 * - The 90% threshold accommodates natural browser body margin (8 px/side)
 *   and any page-level padding a host theme might add.
 */

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

// css/scolta.css is populated by `composer install` (post-install-cmd copies
// it from vendor/tag1/scolta-php/assets/css/scolta.css).
const CSS_PATH = path.resolve(__dirname, '../../css/scolta.css');

let scoltaCss = '';
if (fs.existsSync(CSS_PATH)) {
    scoltaCss = fs.readFileSync(CSS_PATH, 'utf-8');
} else {
    // Fallback: read directly from vendor if copy-assets hasn't run yet.
    const fallback = path.resolve(__dirname, '../../vendor/tag1/scolta-php/assets/css/scolta.css');
    if (fs.existsSync(fallback)) {
        scoltaCss = fs.readFileSync(fallback, 'utf-8');
    }
}

test.describe('Scolta layout width at 1440 px viewport', () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    test('results container fills ≥90% of viewport width', async ({ page }) => {
        await page.setContent(`<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <style>
    body { margin: 0; padding: 0; }
    ${scoltaCss}
  </style>
</head>
<body>
  <div id="scolta-search">
    <div class="scolta-search-box">
      <input id="scolta-query" type="text" placeholder="Search" />
      <button id="scolta-search-btn">Search</button>
    </div>
    <div id="scolta-layout" class="scolta-layout" style="display: block;">
      <div id="scolta-results">
        <div class="scolta-result">
          <h3><a href="https://example.com">Example result</a></h3>
          <p>A sample excerpt to populate the result card.</p>
        </div>
      </div>
    </div>
  </div>
</body>
</html>`);

        const layout = page.locator('#scolta-layout');
        await expect(layout).toBeVisible();

        const viewportWidth = 1440;
        const box = await layout.boundingBox();
        expect(box).not.toBeNull();

        const widthRatio = box.width / viewportWidth;
        expect(widthRatio).toBeGreaterThanOrEqual(0.9);
    });

    test('results container is not wider than viewport', async ({ page }) => {
        await page.setContent(`<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <style>
    body { margin: 0; padding: 0; }
    ${scoltaCss}
  </style>
</head>
<body>
  <div id="scolta-search">
    <div id="scolta-layout" class="scolta-layout" style="display: block;">
      <div id="scolta-results"></div>
    </div>
  </div>
</body>
</html>`);

        const layout = page.locator('#scolta-layout');
        const box = await layout.boundingBox();
        expect(box).not.toBeNull();

        expect(box.width).toBeLessThanOrEqual(1440);
    });

    test('has-filters layout (two-column) still fills ≥90% of viewport', async ({ page }) => {
        await page.setContent(`<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <style>
    body { margin: 0; padding: 0; }
    ${scoltaCss}
  </style>
</head>
<body>
  <div id="scolta-search">
    <div id="scolta-layout" class="scolta-layout has-filters" style="display: block;">
      <aside id="scolta-filters">
        <h3>Type</h3>
        <label><input type="checkbox" /> Page (10)</label>
      </aside>
      <div id="scolta-results">
        <div class="scolta-result">
          <h3><a href="https://example.com">Result</a></h3>
        </div>
      </div>
    </div>
  </div>
</body>
</html>`);

        const layout = page.locator('#scolta-layout');
        const box = await layout.boundingBox();
        expect(box).not.toBeNull();

        const widthRatio = box.width / 1440;
        expect(widthRatio).toBeGreaterThanOrEqual(0.9);
    });
});
