// @ts-check
//
// O X de fechar em toda janelinha (protótipo do Designer, bluejus-central.js; public/js/fechar-janelas.js): entra só
// onde não há fechar e fecha a janela; o modal do Bootstrap continua com um fechar só. Nada é gravado.
//
//   FECHAR_E2E_URL=http://127.0.0.1:8101 FECHAR_E2E_EMAIL=... FECHAR_E2E_SENHA=... npx playwright test --project=fechar-janelas

const { test, expect } = require('@playwright/test');

const BASE = process.env.FECHAR_E2E_URL || 'http://127.0.0.1:8101';

let cookies = [];

/** Entra UMA vez e guarda os cookies: o limitador do login é de 5 tentativas por 15 minutos. */
test.beforeAll(async ({ browser }) => {
    const contexto = await browser.newContext();
    const pagina = await contexto.newPage();
    await pagina.goto(BASE + '/login');
    await pagina.fill('#inputEmail', process.env.FECHAR_E2E_EMAIL || '');
    await pagina.fill('#senha', process.env.FECHAR_E2E_SENHA || '');
    await Promise.all([pagina.waitForNavigation(), pagina.click('#entrar')]);
    if (pagina.url().includes('escritorio/selecionar')) {
        await pagina.locator('form button, a.escritorio-opcao, [data-escritorio]').first().click();
        await pagina.waitForLoadState('networkidle');
    }
    cookies = await contexto.cookies();
    await contexto.close();
});

async function entrar(page, url) {
    await page.context().addCookies(cookies);
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

    test('Select2 (lista com role=listbox): a lista aberta não ganha X, que escolheria a primeira opção', async ({ page }) => {
        await entrar(page, '/agenda/novo');
        await page.locator('.select2-participantes + .select2 .select2-selection').first().click();
        await expect(page.locator('.select2-container--open .select2-results__options')).toBeVisible();
        await page.waitForTimeout(800);
        await expect(page.locator('.select2-container--open [data-bj-x]')).toHaveCount(0);
    });
});
