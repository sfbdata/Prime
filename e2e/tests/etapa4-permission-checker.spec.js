// @ts-check
const { test, expect } = require('@playwright/test');

// Confirma que rotas que chamam PermissionChecker retornam 200 sem TypeError.
// O bypass de ROLE_SUPER_ADMIN deve resolver antes do null-check de Tenant,
// evitando o TypeError que existia antes da Etapa 4.

const rotas = [
    { url: '/clientes',       descricao: 'lista de clientes' },
    { url: '/kanban',         descricao: 'boards kanban' },
    { url: '/tenant/1/users', descricao: 'usuários do tenant' },
    { url: '/auditoria',      descricao: 'log de auditoria' },
];

test.describe('Etapa 4 — PermissionChecker não lança TypeError com SUPER_ADMIN', () => {
    for (const { url, descricao } of rotas) {
        test(`GET ${url} (${descricao}) retorna 200 sem TypeError`, async ({ page }) => {
            const response = await page.goto(url);

            expect(response?.status()).toBe(200);
            await expect(page).not.toHaveURL(/\/login/);
            await expect(page.locator('body')).not.toContainText('TypeError');
            await expect(page.locator('body')).not.toContainText('Internal Server Error');
        });
    }
});
