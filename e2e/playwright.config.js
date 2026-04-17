// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests',
    timeout: 30000,
    retries: 0,
    reporter: 'list',
    use: {
        baseURL: 'http://localhost:8080',
        headless: true,
        locale: 'pt-BR',
    },
    projects: [
        {
            name: 'setup',
            testMatch: '**/auth.setup.js',
        },
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'storage/auth.json',
            },
            dependencies: ['setup'],
        },
    ],
});
