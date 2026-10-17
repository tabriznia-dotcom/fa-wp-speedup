import { chromium } from 'playwright-core';
import fs from 'node:fs';
const state = process.env.FAWS_DEV_STATE || '/workspace/.faws-dev';
const fixtures = JSON.parse(fs.readFileSync(`${state}/woo-fixtures.json`, 'utf8').trim().split('\n').at(-1));
if (!fixtures.cart.startsWith('http://localhost:8080')) throw new Error('Disposable site required');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
const errors = [], blocked = [], measurements = [];
let page;
try {
  const context = await browser.newContext();
  await context.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.hostname !== 'localhost' && url.hostname !== '127.0.0.1') {
      blocked.push(url.hostname);
      await route.abort();
    } else await route.continue();
  });
  page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  for (const [label, url] of Object.entries({home:'http://localhost:8080/', shop:fixtures.shop, variable:fixtures.variable_url})) {
    const response = await page.goto(url, {waitUntil:'networkidle'});
    if (response.status() !== 200) throw new Error(`${label} HTTP ${response.status()}`);
    measurements.push({label, ...await page.evaluate(() => {
      const nav = performance.getEntriesByType('navigation')[0];
      const resources = performance.getEntriesByType('resource');
      return {ttfb_ms: nav.responseStart-nav.requestStart, dom_ms:nav.domContentLoadedEventEnd,
        resources:resources.map(r=>({type:r.initiatorType, bytes:r.transferSize, ms:r.duration})),
        note:'Synthetic local Docker test, fresh anonymous browser, external requests blocked; not an Iran speed claim'};
    })});
  }
  await page.locator('select[name="attribute_size"]').selectOption('Small');
  await page.waitForFunction(() => Number(document.querySelector('input.variation_id')?.value) > 0);
  await page.waitForFunction(() => !document.querySelector('.single_add_to_cart_button').classList.contains('disabled'));
  await Promise.all([page.waitForNavigation({waitUntil:'networkidle'}), page.locator('.single_add_to_cart_button').click()]);
  await page.goto(fixtures.cart, {waitUntil:'networkidle'});
  if (!await page.locator('.woocommerce-cart-form').count()) throw new Error('Variable product not in cart');
  await page.goto(fixtures.checkout, {waitUntil:'networkidle'});
  await page.locator('#billing_first_name').fill('FAWS');
  await page.locator('#billing_last_name').fill('Browser');
  await page.locator('#billing_address_1').fill('Test street 1');
  await page.locator('#billing_city').fill('Tabriz');
  await page.locator('#billing_postcode').fill('5136811111');
  await page.locator('#billing_phone').fill('09140000000');
  await page.locator('#billing_email').fill('browser@example.invalid');
  await page.locator('#billing_state').selectOption('EAZ');
  if (await page.locator('#terms').count()) await page.locator('#terms').check();
  await page.waitForTimeout(1500);
  await page.locator('#place_order').click();
  await page.waitForURL(/order-received/, {timeout:30000});
  await page.waitForLoadState('networkidle');
  if (!await page.locator('.woocommerce-order-overview, .wp-block-woocommerce-order-confirmation-summary').count()) throw new Error('Order confirmation absent');
  if (errors.length) throw new Error(`Browser errors: ${JSON.stringify(errors)}`);
  const label = process.env.FAWS_TEST_LABEL === 'baseline' ? 'baseline' : 'active';
  fs.writeFileSync(`${state}/browser-results-${label}.json`, JSON.stringify({errors,blocked,measurements,checkout:'variable product + COD passed'},null,2));
  console.log('PASS Chromium: variable selection, cart, checkout AJAX, COD confirmation; no uncaught JS errors. External network blocked.');
} catch (error) {
  try { if (page) fs.writeFileSync(`${state}/browser-failure.html`, await page.content()); } catch {}
  console.error('Uncaught page errors', errors, 'blocked hosts', blocked);
  throw error;
} finally { await browser.close(); }
