// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('Etapa 4 — acesso ao painel /admin/platform (ROLE_SUPER_ADMIN)', () => {
    test('ROLE_SUPER_ADMIN acessa /admin/platform sem 403 nem 500', async ({ page }) => {
        const response = await page.goto('/admin/platform');

        expect(response?.status()).toBe(200);
        await expect(page).toHaveURL(/\/admin\/platform/);
        await expect(page.locator('body')).toContainText('Painel Platform Admin');
    });
});
