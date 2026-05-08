// @ts-check
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');

function runSQL(sql) {
    return execSync(
        'docker exec -i jusprime_db_dev psql -U symfony -d saas -t -A',
        { encoding: 'utf-8', input: sql }
    ).trim();
}

const SQL_RESTAURAR = `
    UPDATE user_tenant SET is_active = true
    WHERE user_id = (SELECT id FROM "user" WHERE email = 'e2e@jusprime.local')
`;

const SQL_INATIVAR = `
    UPDATE user_tenant SET is_active = false
    WHERE user_id = (SELECT id FROM "user" WHERE email = 'e2e@jusprime.local')
`;

// Sessão isolada para não corromper a sessão compartilhada dos outros testes:
// ao inativar o vínculo, o listener limpa current_tenant_id da sessão do servidor.
// Com sessão própria, os outros testes permanecem inalterados.
test.describe('Etapa 4 — TenantContextValidatorListener detecta UserTenant inativo', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    // Cinto-e-suspensório: garante estado limpo antes e depois de cada teste
    test.beforeAll(() => {
        runSQL(SQL_RESTAURAR);
    });

    test.afterEach(() => {
        runSQL(SQL_RESTAURAR);
    });

    test('UserTenant inativado via SQL redireciona para seleção com flash de aviso', async ({ page }) => {
        // Login fresh (sessão própria, não compartilhada)
        await page.goto('/login');
        await page.fill('input[name="email"], input[name="_username"]', 'e2e@jusprime.local');
        await page.fill('input[name="password"], input[name="_password"]',
            process.env.E2E_USER_PASSWORD ?? 'e2e_local_placeholder');
        await page.click('button[type="submit"]');
        await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 20000 });

        // Confirma que navegação normal funciona com vínculo ativo
        await page.goto('/clientes');
        await expect(page).toHaveURL(/\/clientes/);

        // Invalida o vínculo (simula remoção do funcionário do tenant)
        runSQL(SQL_INATIVAR);

        // Navega para qualquer rota protegida
        await page.goto('/clientes');

        // Deve redirecionar para seleção de tenant (rota real: /escritorio/selecionar)
        await expect(page).toHaveURL(/\/escritorio\/selecionar/, { timeout: 10000 });

        // Flash de aviso deve estar visível com o texto correto
        await expect(page.locator('.alert-warning, .flash-warning, [class*="alert"]'))
            .toContainText('vínculo', { timeout: 5000 });
    });
});
