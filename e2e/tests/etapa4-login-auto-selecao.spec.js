// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('Etapa 4 — auto-seleção de tenant no login', () => {
    // Anula o storageState do projeto chromium: inicia sem nenhuma sessão
    test.use({ storageState: { cookies: [], origins: [] } });

    test('login com 1 tenant ativo auto-seleciona e redireciona sem passar por /tenant/selecionar', async ({ page }) => {
        await page.goto('/login');

        // Verifica rate limiter (mesmo guard do auth.setup.js)
        const rateLimitMsg = page.locator('text=Muitas tentativas');
        const temRateLimit = await rateLimitMsg.isVisible().catch(() => false);
        if (temRateLimit) {
            throw new Error('Rate limiter ativo — limpe com: docker exec jusprime_php_dev bash -c "cd app && php bin/console cache:pool:clear cache.rate_limiter"');
        }

        await page.fill('input[name="email"], input[name="_username"]', 'e2e@jusprime.local');
        await page.fill('input[name="password"], input[name="_password"]',
            process.env.E2E_USER_PASSWORD ?? 'e2e_local_placeholder');
        await page.click('button[type="submit"]');

        // Aguarda sair da tela de login
        await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 20000 });

        // NÃO deve ter parado em /tenant/selecionar — UserAuthenticator auto-selecionou
        await expect(page).not.toHaveURL(/\/tenant\/selecionar/);

        // Deve estar no expediente (rota de destino configurada em UserAuthenticator)
        await expect(page).toHaveURL(/\/expediente/);
    });
});
