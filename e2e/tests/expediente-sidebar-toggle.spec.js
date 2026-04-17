// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('Sidebar do Expediente — botão de retrair (desktop)', () => {
    test.beforeEach(async ({ page }) => {
        // Limpa o estado do localStorage antes de cada teste
        await page.goto('/expediente');
        await page.evaluate(() => localStorage.removeItem('expediente_sidebar_collapsed'));
        await page.reload();
        await page.waitForSelector('#expedienteSidebar', { state: 'visible' });
    });

    test('sidebar começa expandida por padrão', async ({ page }) => {
        const sidebar = page.locator('#expedienteSidebar');
        await expect(sidebar).not.toHaveClass(/sidebar-collapsed/);
    });

    test('clicando no botão retrair, a sidebar colapsa', async ({ page }) => {
        const sidebar = page.locator('#expedienteSidebar');
        const toggleBtn = page.locator('#expedienteSidebarToggleBtn');

        // Botão deve estar visível no desktop
        await expect(toggleBtn).toBeVisible();

        // Clica no botão de retrair
        await toggleBtn.click();

        // Sidebar deve ganhar a classe collapsed
        await expect(sidebar).toHaveClass(/sidebar-collapsed/);
    });

    test('após colapsar, o ícone muda para chevron-right', async ({ page }) => {
        const toggleBtn = page.locator('#expedienteSidebarToggleBtn');
        const icon = page.locator('#expedienteSidebarToggleIcon');

        await toggleBtn.click();

        await expect(icon).toHaveClass(/bi-chevron-right/);
    });

    test('estado colapsado é persistido no localStorage', async ({ page }) => {
        const toggleBtn = page.locator('#expedienteSidebarToggleBtn');
        await toggleBtn.click();

        const valor = await page.evaluate(() => localStorage.getItem('expediente_sidebar_collapsed'));
        expect(valor).toBe('1');
    });

    test('clicando novamente, a sidebar expande', async ({ page }) => {
        const sidebar = page.locator('#expedienteSidebar');
        const toggleBtn = page.locator('#expedienteSidebarToggleBtn');

        // Colapsa
        await toggleBtn.click();
        await expect(sidebar).toHaveClass(/sidebar-collapsed/);

        // Expande
        await toggleBtn.click();
        await expect(sidebar).not.toHaveClass(/sidebar-collapsed/);
    });

    test('após expandir, o localStorage é atualizado para 0', async ({ page }) => {
        const toggleBtn = page.locator('#expedienteSidebarToggleBtn');

        // Colapsa e expande
        await toggleBtn.click();
        await toggleBtn.click();

        const valor = await page.evaluate(() => localStorage.getItem('expediente_sidebar_collapsed'));
        expect(valor).toBe('0');
    });

    test('estado colapsado persiste após recarregar a página', async ({ page }) => {
        const sidebar = page.locator('#expedienteSidebar');
        const toggleBtn = page.locator('#expedienteSidebarToggleBtn');

        await toggleBtn.click();
        await expect(sidebar).toHaveClass(/sidebar-collapsed/);

        // Recarrega a página
        await page.reload();
        await page.waitForSelector('#expedienteSidebar', { state: 'visible' });

        // Sidebar deve continuar colapsada
        await expect(sidebar).toHaveClass(/sidebar-collapsed/);
    });

    test('título do botão muda conforme o estado', async ({ page }) => {
        const toggleBtn = page.locator('#expedienteSidebarToggleBtn');

        await expect(toggleBtn).toHaveAttribute('title', 'Retrair menu');

        await toggleBtn.click();
        await expect(toggleBtn).toHaveAttribute('title', 'Expandir menu');

        await toggleBtn.click();
        await expect(toggleBtn).toHaveAttribute('title', 'Retrair menu');
    });

    test('textos dos links ficam ocultos quando colapsada', async ({ page }) => {
        const toggleBtn = page.locator('#expedienteSidebarToggleBtn');

        // Expande: textos visíveis
        const textoLink = page.locator('.expediente-menu-link span').first();
        await expect(textoLink).toBeVisible();

        await toggleBtn.click();

        // Colapsa: textos ocultados por CSS
        await expect(textoLink).toBeHidden();
    });
});
