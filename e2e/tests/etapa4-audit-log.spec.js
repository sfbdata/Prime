// @ts-check
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');

function runSQL(sql) {
    return execSync(
        'docker exec -i jusprime_db_dev psql -U symfony -d saas -t -A',
        { encoding: 'utf-8', input: sql }
    ).trim();
}

test.describe('Etapa 4 — AuditLogSubscriber grava tenant_id via TenantContext', () => {
    test('ação de escrita grava audit_log com tenant_id=1 (não null)', async ({ page }) => {
        // Estado antes
        const idAntes = runSQL('SELECT COALESCE(MAX(id), 0) FROM audit_log');

        // Ação de escrita: atualizar status no perfil (endpoint XHR que faz flush via Doctrine)
        await page.goto('/perfil');
        await page.waitForSelector('#statusInput', { state: 'visible', timeout: 15000 });

        const novoStatus = `Teste E2E audit ${Date.now()}`;
        await page.locator('#statusInput').fill(novoStatus);
        await page.click('#btnSalvarStatus');
        await page.waitForSelector('#btnSalvarStatus', { state: 'hidden', timeout: 8000 });

        // Verificar audit_log: entrada mais recente após a ação deve ter tenant_id=1
        const tenantId = runSQL(
            `SELECT tenant_id FROM audit_log WHERE id > ${idAntes} ORDER BY id DESC LIMIT 1`
        );

        expect(tenantId).toBe('1');
    });
});
