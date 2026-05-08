// @ts-check
const { test, expect } = require('@playwright/test');

// Etapa 5a — Segurança de /tenant/new
//
// Cenário 1: acesso anônimo → redirect para /login (302)
// Cenário 2: SuperAdmin (e2e@jusprime.local) → 200
// Cenário 3: user normal (sem ROLE_SUPER_ADMIN) → 403
//   NOTA: cenário 3 requer fixture de user_tenant para advogado1 (pendente Etapa 5c).
//   Sem user_tenant, o TenantContextValidatorListener redireciona antes do check de permissão.
//   Marcado como skip até as fixtures serem criadas.

test.describe('Etapa 5a — /tenant/new: acesso anônimo', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('acesso sem login redireciona para /login', async ({ page }) => {
        const response = await page.goto('/tenant/new');

        // Symfony redireciona 302 → /login; Playwright segue o redirect
        await expect(page).toHaveURL(/\/login/);
        // Garantia extra: não chegou na página de criação
        await expect(page.locator('body')).not.toContainText('Novo Escritório');
    });
});

test.describe('Etapa 5a — /tenant/new: SuperAdmin', () => {
    // Usa a storageState padrão do projeto (e2e@jusprime.local com ROLE_SUPER_ADMIN)

    test('SuperAdmin acessa /tenant/new e recebe 200', async ({ page }) => {
        const response = await page.goto('/tenant/new');

        expect(response?.status()).toBe(200);
        await expect(page).not.toHaveURL(/\/login/);
        await expect(page.locator('body')).not.toContainText('Access Denied');
        await expect(page.locator('body')).not.toContainText('Internal Server Error');
    });
});

test.describe('Etapa 5a — /tenant/new: user normal', () => {
    test.skip('pendente: requer fixture de user_tenant para advogado1 (ver Etapa 5c)', async ({ page }) => {
        // Quando implementado: fazer login como advogado1@escritorio.com.br (sem ROLE_SUPER_ADMIN)
        // garantindo que tenha user_tenant ativo (para passar pelo TenantContextValidatorListener)
        // e então GET /tenant/new deve retornar 403.
        const response = await page.goto('/tenant/new');
        expect(response?.status()).toBe(403);
    });
});
