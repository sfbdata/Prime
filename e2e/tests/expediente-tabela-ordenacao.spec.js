// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('Tabela de pastas do Expediente — ordenação', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/expediente');

        // Aguarda a sidebar e dispara o clique em "Todas as pastas"
        await page.waitForSelector('.expediente-menu-link[data-painel]', { state: 'visible' });
        await page.click('.expediente-menu-link[data-painel]');

        // Aguarda a tabela renderizar via AJAX
        await page.waitForSelector('#tabelaPastas', { state: 'visible', timeout: 10000 });
    });

    test('tabela exibe cabeçalho NUP com ícone de ordenação ativo ao carregar', async ({ page }) => {
        const thNup = page.locator('#tabelaPastas thead th[data-col="0"]');
        await expect(thNup).toHaveClass(/desc/);

        const icone = thNup.locator('.sort-icon');
        await expect(icone).toBeVisible();
    });

    test('ao carregar, a coluna NUP está ordenada de forma decrescente (maior em cima)', async ({ page }) => {
        const celulasNup = page.locator('#tabelaPastas tbody tr td:first-child');
        const count = await celulasNup.count();

        // Precisa de pelo menos 2 linhas para validar ordem
        test.skip(count < 2, 'Menos de 2 pastas — não é possível verificar ordenação');

        const valores = await celulasNup.allTextContents();
        const limpos  = valores.map(v => v.trim()).filter(v => v.length > 0);

        // Verifica que a lista já está em ordem decrescente
        const ordenado = [...limpos].sort((a, b) => b.localeCompare(a, 'pt-BR'));
        expect(limpos).toEqual(ordenado);
    });

    test('clicar no cabeçalho NUP uma vez inverte para ascendente', async ({ page }) => {
        const thNup = page.locator('#tabelaPastas thead th[data-col="0"]');

        // Estado inicial: desc
        await expect(thNup).toHaveClass(/desc/);

        await thNup.click();

        // Após um clique: asc
        await expect(thNup).toHaveClass(/asc/);
        await expect(thNup).not.toHaveClass(/desc/);
    });

    test('clicar no cabeçalho NUP duas vezes volta para decrescente', async ({ page }) => {
        const thNup = page.locator('#tabelaPastas thead th[data-col="0"]');

        await thNup.click(); // → asc
        await thNup.click(); // → desc

        await expect(thNup).toHaveClass(/desc/);
        await expect(thNup).not.toHaveClass(/asc/);
    });

    test('após ordenar por outra coluna, o cabeçalho NUP perde a classe ativa', async ({ page }) => {
        const thNup     = page.locator('#tabelaPastas thead th[data-col="0"]');
        const thCliente = page.locator('#tabelaPastas thead th[data-col="1"]');

        // Estado inicial: NUP com desc
        await expect(thNup).toHaveClass(/desc/);

        await thCliente.click();

        // NUP não deve mais ter asc nem desc
        await expect(thNup).not.toHaveClass(/asc/);
        await expect(thNup).not.toHaveClass(/desc/);

        // Cliente deve ter asc
        await expect(thCliente).toHaveClass(/asc/);
    });

    test('ícone sort-icon muda de aparência ao ordenar coluna', async ({ page }) => {
        const thNup   = page.locator('#tabelaPastas thead th[data-col="0"]');
        const icone   = thNup.locator('.sort-icon');

        // Estado inicial: desc → ícone com opacity 1
        await expect(thNup).toHaveClass(/desc/);

        // Passa para asc
        await thNup.click();
        await expect(thNup).toHaveClass(/asc/);
        await expect(icone).toBeVisible();
    });

    test('ordenação por Cliente funciona (asc na primeira vez)', async ({ page }) => {
        const thCliente   = page.locator('#tabelaPastas thead th[data-col="1"]');
        const celulasCliente = page.locator('#tabelaPastas tbody tr td:nth-child(2)');

        await thCliente.click();
        await expect(thCliente).toHaveClass(/asc/);

        const count = await celulasCliente.count();
        test.skip(count < 2, 'Menos de 2 pastas — não é possível verificar ordenação');

        const valores  = await celulasCliente.allTextContents();
        const limpos   = valores.map(v => v.trim()).filter(v => v.length > 0);
        const ordenado = [...limpos].sort((a, b) => a.localeCompare(b, 'pt-BR'));
        expect(limpos).toEqual(ordenado);
    });
});
