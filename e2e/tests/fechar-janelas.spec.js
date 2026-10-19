// @ts-check
//
// O X de fechar em toda janelinha (protótipo do Designer, bluejus-central.js; public/js/fechar-janelas.js): entra só
// onde não há fechar e fecha a janela; o modal do Bootstrap continua com um fechar só. Nada é gravado.
//
//   FECHAR_E2E_URL=http://127.0.0.1:8101 FECHAR_E2E_EMAIL=... FECHAR_E2E_SENHA=... npx playwright test --project=fechar-janelas

const { test, expect } = require('@playwright/test');

const BASE = process.env.FECHAR_E2E_URL || 'http://127.0.0.1:8101';

async function entrar(page, url) {
    await page.goto(BASE + '/login');
    await page.fill('#inputEmail', process.env.FECHAR_E2E_EMAIL || '');
    await page.fill('#senha', process.env.FECHAR_E2E_SENHA || '');
    await Promise.all([page.waitForNavigation(), page.click('#entrar')]);
    if (page.url().includes('escritorio/selecionar')) {
        await page.locator('form button, a.escritorio-opcao, [data-escritorio]').first().click();
        await page.waitForLoadState('networkidle');
    }
    await page.goto(BASE + url, { waitUntil: 'networkidle' });
}

test.describe('X de fechar em toda janelinha', () => {
    test.use({ viewport: { width: 1580, height: 902 } });

    test('menu de botão direito do Expediente ganha o X, visível sobre fundo claro, e o X fecha o menu', async ({ page }) => {
        await entrar(page, '/expediente');
        const linha = page.locator('#tabelaPastas tbody tr').first();
        const caixa = await linha.boundingBox();
        await page.mouse.click(caixa.x + 300, caixa.y + 20, { button: 'right' });
        const x = page.locator('.exp-ctx [data-bj-x]');
        await expect(x).toBeVisible();
        await expect(x).not.toHaveClass(/bj-fechar-x--escuro/);
        await x.click();
        await expect(page.locator('.exp-ctx')).toHaveCount(0);
    });

    test('modal do Bootstrap com .btn-close não ganha um segundo X', async ({ page }) => {
        await entrar(page, '/ponto/');
        await page.locator('[data-bs-toggle="modal"]:visible').first().click();
        const modal = page.locator('.modal.show');
        await expect(modal).toBeVisible();
        await page.waitForTimeout(800);
        await expect(modal.locator('[data-bj-x]')).toHaveCount(0);
    });
});
