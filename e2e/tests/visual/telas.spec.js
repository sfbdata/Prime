// @ts-check
// Regressão visual: cada tela é comparada com a foto de referência aprovada pelo dono.
//
// O que muda de um dia para o outro (datas, contadores, listas que dependem dos dados do banco
// de DEV) é COBERTO por uma máscara, para a comparação olhar só o que é desenho: arranjo, cores,
// tipografia, menus, cabeçalhos. A data do navegador é fixa pelo mesmo motivo.
const { test, expect } = require('@playwright/test');

const DATA_FIXA = new Date('2026-10-20T10:00:00-03:00');

test.beforeEach(async ({ page }) => {
    await page.clock.setFixedTime(DATA_FIXA);
});

/** Espera a página assentar: rede parada e fontes carregadas. */
async function assentar(page) {
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => document.fonts.ready);
}

test.describe('sem login', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('login', async ({ page }) => {
        await page.goto('/login');
        await assentar(page);
        await expect(page).toHaveScreenshot('login.png', { fullPage: true });
    });

    test('política de privacidade (início)', async ({ page }) => {
        await page.goto('/politica-de-privacidade');
        await assentar(page);
        await expect(page).toHaveScreenshot('politica-de-privacidade.png');
    });
});

test.describe('logado', () => {
    test('cabeçalho e menu lateral', async ({ page }) => {
        await page.goto('/');
        await assentar(page);
        // Contadores do sino, da Central e do usuário mudam com os dados: ficam mascarados.
        const mascara = [page.locator('#mainHeader .badge'), page.locator('#mainHeader .notif-dropdown')];
        await expect(page.locator('#mainHeader')).toHaveScreenshot('cabecalho.png', { mask: mascara });
        const sidebar = page.locator('#pjeSidebar');
        if (await sidebar.isVisible()) {
            await expect(sidebar).toHaveScreenshot('menu-lateral.png', { mask: [sidebar.locator('.badge')] });
        }
    });

    test('dashboard (arranjo)', async ({ page }) => {
        await page.goto('/');
        await assentar(page);
        // O conteúdo de cada cartão depende dos dados: só o arranjo dos blocos é comparado.
        await expect(page).toHaveScreenshot('dashboard.png', {
            fullPage: true,
            mask: [page.locator('#main .card-body'), page.locator('#main table tbody'), page.locator('#mainHeader .badge')],
        });
    });

    test('expediente (arranjo)', async ({ page }) => {
        await page.goto('/expediente');
        await assentar(page);
        await expect(page).toHaveScreenshot('expediente.png', {
            mask: [page.locator('#main table tbody'), page.locator('#main .badge'), page.locator('#mainHeader .badge')],
        });
    });

    test('novidades', async ({ page }) => {
        const resposta = await page.goto('/novidades');
        test.skip(resposta?.status() === 404, 'a página Novidades ainda não existe nesta versão');
        await assentar(page);
        await expect(page.locator('#main')).toHaveScreenshot('novidades.png', {
            mask: [page.locator('#main .text-body-secondary').first()],
        });
    });
});
