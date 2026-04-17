// @ts-check
const { test: setup, expect } = require('@playwright/test');
const path = require('path');

const STORAGE_FILE = path.join(__dirname, '../storage/auth.json');

setup('autenticar usuário de teste', async ({ page }) => {
    await page.goto('/login');

    await page.fill('input[name="email"], input[name="_username"]', 'advogado1@escritorio.com.br');
    await page.fill('input[name="password"], input[name="_password"]', 'senha123');
    await page.click('button[type="submit"]');

    // Aguarda redirecionar para fora do login
    await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 10000 });

    await page.context().storageState({ path: STORAGE_FILE });
});
