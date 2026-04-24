// @ts-check
const { test: setup, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const STORAGE_FILE = path.join(__dirname, '../storage/auth.json');

setup('autenticar usuário de teste', async ({ page }) => {
    await page.goto('/login');

    // Verifica se há mensagem de rate limit antes de tentar
    const rateLimitMsg = page.locator('text=Muitas tentativas');
    const temRateLimit = await rateLimitMsg.isVisible().catch(() => false);
    if (temRateLimit) {
        throw new Error('Rate limiter ativo — limpe o cache antes de rodar: docker exec jusprime_php_dev bash -c "cd /var/www/app && php bin/console cache:pool:clear cache.rate_limiter"');
    }

    await page.fill('input[name="email"], input[name="_username"]', 'advogado1@escritorio.com.br');
    await page.fill('input[name="password"], input[name="_password"]', 'senha123');
    await page.click('button[type="submit"]');

    // Aguarda redirecionar para fora do login (aumentado para 20s)
    await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 20000 });

    await page.context().storageState({ path: STORAGE_FILE });
});
