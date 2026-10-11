import { chromium } from "playwright";

const [baseUrl, ...routes] = process.argv.slice(2);

if (!baseUrl || routes.length === 0) {
  console.error("Kullanim: node check.mjs <base-url> <route...>");
  process.exit(2);
}

const browser = await chromium.launch({ headless: true });

let failed = false;

for (const route of routes) {
  const page = await browser.newPage();

  const errors = [];

  page.on("pageerror", error => {
    errors.push(`PAGE ERROR: ${error.message}`);
  });

  page.on("console", message => {
    if (message.type() === "error") {
      errors.push(`CONSOLE ERROR: ${message.text()}`);
    }
  });

  const cleanBase = baseUrl.replace(/\/$/, "");
  const url =
    route === "/"
      ? `${cleanBase}/`
      : `${cleanBase}${route}`;

  try {
    const response = await page.goto(url, {
      waitUntil: "networkidle",
      timeout: 30000,
    });

    await page.waitForTimeout(1200);

    if (!response || response.status() >= 400) {
      console.error(`✗ ${route} HTTP ${response?.status() ?? "NO RESPONSE"}`);
      failed = true;
    }

    if (errors.length) {
      console.error(`✗ ${route}`);

      for (const error of errors) {
        console.error(`  ${error}`);
      }

      failed = true;
    }

    if (!errors.length && response && response.status() < 400) {
      console.log(`✓ ${route}`);
    }
  } catch (error) {
    console.error(`✗ ${route}: ${error.message}`);
    failed = true;
  }

  await page.close();
}

await browser.close();

process.exit(failed ? 1 : 0);
