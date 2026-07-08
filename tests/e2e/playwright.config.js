const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './',
  timeout: 120000,
  expect: { timeout: 15000 },
  retries: 0,
  use: {
    baseURL: process.env.BASE_URL || 'https://agentrocketman.com',
    headless: true,
    viewport: { width: 1280, height: 900 },
    actionTimeout: 15000,
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'desktop',
      use: { viewport: { width: 1280, height: 900 } },
    },
    {
      name: 'mobile',
      use: { viewport: { width: 390, height: 844 } },
    },
  ],
});