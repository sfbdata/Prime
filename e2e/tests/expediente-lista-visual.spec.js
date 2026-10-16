// @ts-check
/**
 * Regressão visual da Lista do Expediente contra o desenho 1.1 (`03 - EXPEDIENTE Lista 1.1.dc.html`).
 * E8d da frente `fidelidade-designer`; IDs AUD-nn da auditoria (docs/specs/fidelidade-designer-1310.md §16).
 *
 * Mede valores calculados no navegador: posição, cor, altura, estouro da página, estados e tema escuro. É o que o
 * PHPUnit não enxerga (ele lê HTML, não cascata nem caixa).
 *
 * Roda contra o servidor da WORKTREE (o :8080 serve o checkout principal), com login próprio, sem o `auth.setup`:
 *   E2E_VISUAL_BASE=http://127.0.0.1:8000 E2E_VISUAL_EMAIL=<email> E2E_VISUAL_SENHA=<senha> \
 *   [E2E_VISUAL_ESCRITORIO="Nome do escritório"] npx playwright test tests/expediente-lista-visual.spec.js \
 *   --project=chromium --no-deps
 * Só leitura: login, escolha do escritório na sessão, GET de filtros, menus abertos e fechados. Nada é gravado.
 * Sem as variáveis, o arquivo é pulado.
 */
const { test, expect } = require('@playwright/test');

const BASE = process.env.E2E_VISUAL_BASE || 'http://127.0.0.1:8000';
const EMAIL = process.env.E2E_VISUAL_EMAIL || '';
const SENHA = process.env.E2E_VISUAL_SENHA || '';
const ESCRITORIO = process.env.E2E_VISUAL_ESCRITORIO || '';

test.skip(!EMAIL || !SENHA, 'defina E2E_VISUAL_EMAIL e E2E_VISUAL_SENHA (ver o cabeçalho deste arquivo)');
// Sem o storage/auth.json do `auth.setup` (outro usuário, outro servidor): a sessão é a do login abaixo.
test.use({ baseURL: BASE, storageState: { cookies: [], origins: [] } });
test.describe.configure({ mode: 'serial' });

/** Sessão de um login só (o login é o passo lento; cada teste abre a Lista já entrando com ela). */
let sessao = null;
const abertos = [];

/** Entra e escolhe o escritório se for pedido. */
async function entrar(page) {
    await page.goto('/login');
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', SENHA);
    await Promise.all([page.waitForURL(u => !u.pathname.includes('/login'), { waitUntil: 'domcontentloaded' }), page.click('button[type="submit"]')]);
    if (page.url().includes('selecionar')) {
        const alvo = ESCRITORIO ? page.getByText(ESCRITORIO, { exact: false }).first() : page.locator('main a, main button').first();
        await Promise.all([page.waitForURL(u => !u.pathname.includes('selecionar'), { waitUntil: 'domcontentloaded' }), alvo.click()]);
    }
}

/** Abre a Lista na largura pedida com a sessão salva; tira o aviso do ponto do DOM sem registrar batida. */
async function abrirLista(browser, largura, altura = 900) {
    const ctx = await browser.newContext({ baseURL: BASE, storageState: sessao, viewport: { width: largura, height: altura } });
    abertos.push(ctx);
    const page = await ctx.newPage();
    await page.goto('/expediente', { waitUntil: 'networkidle' });
    await page.evaluate(() => {
        document.querySelectorAll('.modal.show, .modal-backdrop, .sf-toolbar').forEach(n => n.remove());
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
    });
    return page;
}

test.beforeAll(async ({ browser }, info) => {
    info.setTimeout(120000);
    const ctx = await browser.newContext({ baseURL: BASE });
    const page = await ctx.newPage();
    await entrar(page);
    sessao = await ctx.storageState();
    await ctx.close();
});

test.afterEach(async () => {
    while (abertos.length) { await abertos.pop().close(); }
});

/** Caixa e estilos calculados de um elemento. */
function medir(page, seletor) {
    return page.locator(seletor).first().evaluate(el => {
        const c = getComputedStyle(el);
        const r = el.getBoundingClientRect();
        return {
            x: r.x, y: r.y, w: r.width, h: r.height, direita: r.right, fundo: r.bottom,
            cor: c.color, bg: c.backgroundColor, peso: c.fontWeight, fonte: c.fontSize,
            bordaEsq: c.borderLeftColor, alinhar: c.textAlign, paddingEsq: c.paddingLeft, transform: c.textTransform,
        };
    });
}

function semEstouro(page) {
    return page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
}

test.describe('Lista do Expediente no desenho 1.1', () => {
    test('1440px: disposição, margens, cabeçalho, trilho e resumo do 1.1', async ({ browser }) => {
        const page = await abrirLista(browser, 1440);
        expect(await semEstouro(page)).toBe(true);

        // AUD-01/02: 28px nas laterais, 18px do título ao conteúdo, 20px entre trilho e acervo.
        const h1 = await medir(page, 'h1.exp-titulo');
        const trilho = await medir(page, '#expedienteSidebar');
        const acervo = await medir(page, '.exp-painel');
        expect(h1.x).toBe(28);
        expect(trilho.x).toBe(28);
        expect(Math.round(trilho.y - h1.fundo)).toBe(18);
        expect(Math.round(acervo.x - trilho.direita)).toBe(20);
        expect(Math.round(1440 - acervo.direita)).toBe(28);

        // AUD-08/11/12: situação na barra; filtros, Visualizar e paginação na mesma linha; resumo embaixo.
        await expect(page.locator('.exp-painel > .exp-barra > .exp-situacao > select[name="status"]')).toBeVisible();
        const busca = await medir(page, '.pasta-filtros-busca .form-control');
        const vis = await medir(page, '.js-visualizar');
        const pagina = await medir(page, '.exp-controles .page-item.active .page-link');
        const periodo = await medir(page, '.pasta-filtros-periodo');
        const resumo = await medir(page, '.exp-resumo');
        expect(Math.abs(busca.y - vis.y)).toBeLessThanOrEqual(2);
        expect(vis.x).toBeGreaterThan(periodo.direita);
        expect(pagina.x).toBeGreaterThan(vis.direita);
        expect(resumo.y).toBeGreaterThanOrEqual(Math.floor(busca.fundo));
        expect(vis.cor).toBe('rgb(10, 111, 160)');
        expect(vis.peso).toBe('700');

        // AUD-14: intervalo de pastas da página.
        await expect(page.locator('.exp-resumo')).toHaveText(/^\s*\d+ de \d+ · Total: [\d.]+ pastas?\s*$/);

        // AUD-17 a AUD-22: cabeçalho de 40px, NUP ordenando em azul, seta cinza, SITUAÇÃO centralizada, NUP a 20px.
        const th = await medir(page, '#tabelaPastas thead th[data-ordenar="nup"]');
        expect(Math.round(th.h)).toBe(40);
        expect(th.cor).toBe('rgb(10, 111, 160)');
        expect((await medir(page, '#tabelaPastas thead th[data-ordenar="cliente"] .sort-icon')).cor).toBe('rgb(167, 182, 192)');
        expect((await medir(page, '#tabelaPastas thead th:last-child')).alinhar).toBe('center');
        expect((await medir(page, '#tabelaPastas tbody tr.pasta-row-link td:first-child')).paddingEsq).toBe('20px');

        // E12 (1.1 online): o recolher fica no meio da altura do cabeçalho MARCADORES; itens de peso normal sem a borda.
        const recolher = await medir(page, '#expedienteSidebarToggleBtn');
        const cabMarc = await medir(page, '.exp-tri-cab--marcadores');
        expect(Math.abs(recolher.y - (cabMarc.y + cabMarc.h / 2))).toBeLessThanOrEqual(1);
        const item = await medir(page, '#lista-marcadores > .exp-tri-grupo > .exp-tri-marcador:not(.active)');
        expect(item.peso).toBe('400');
        expect(item.bordaEsq).toBe('rgba(0, 0, 0, 0)');
    });

    for (const largura of [1280, 1024]) {
        test(`${largura}px: nomes legíveis, rolagem só dentro da tabela (AUD-29)`, async ({ browser }) => {
            const page = await abrirLista(browser, largura);
            expect(await semEstouro(page)).toBe(true);
            expect((await medir(page, '#tabelaPastas thead th[data-ordenar="cliente"]')).w).toBeGreaterThanOrEqual(220);
            expect((await medir(page, '#tabelaPastas thead th[data-ordenar="acao"]')).w).toBeGreaterThanOrEqual(190);
            // Controles que descem de linha ficam à direita e os filtros guardam os 22px da borda.
            const painel = await medir(page, '.exp-painel');
            const periodo = await medir(page, '.pasta-filtros-periodo');
            expect(Math.round(painel.direita - periodo.direita)).toBeGreaterThanOrEqual(22);
        });
    }

    for (const largura of [768, 390]) {
        test(`${largura}px: sem estouro da página`, async ({ browser }) => {
            const page = await abrirLista(browser, largura);
            expect(await semEstouro(page)).toBe(true);
            if (largura < 768) {
                await expect(page.locator('#tabelaPastas')).toBeHidden();
                await expect(page.locator('#labelPainelAtivo')).toBeHidden();   // AUD-30
            }
        });
    }

    test('estados: filtro com valor, Visualizar, menu de botão direito e linha sob o mouse', async ({ browser }) => {
        const page = await abrirLista(browser, 1440);

        // AUD-10: filtro com valor fica azul-claro; situação com as cores dela.
        await page.fill('.pasta-filtros-busca .form-control', 'a');
        await expect(page.locator('.pasta-filtros-busca')).toHaveClass(/is-ativo/);
        await expect.poll(async () => (await medir(page, '.pasta-filtros-busca .form-control')).bg).toBe('rgb(227, 241, 248)');
        await page.fill('.pasta-filtros-busca .form-control', '');
        // O filtro recarrega a lista por AJAX: espera a resposta, senão a troca do HTML corre com os passos seguintes.
        const resposta = () => page.waitForResponse(r => r.url().includes('status=') && r.request().method() === 'GET');
        await Promise.all([resposta(), page.selectOption('.exp-situacao-select', 'ativo')]);
        await page.mouse.move(2, 600);
        await expect.poll(async () => (await medir(page, '.exp-situacao-select')).bg).toBe('rgb(228, 244, 234)');   // depois da transição de .18s
        await expect(page.locator('#pasta-chips')).toContainText('Situação');
        await Promise.all([resposta(), page.selectOption('.exp-situacao-select', '')]);
        await expect(page.locator('#pastas-resultado')).not.toHaveClass(/pastas-carregando/);

        // AUD-12: Visualizar abre o menu dos dois modos; Esc fecha e devolve o foco.
        await page.click('.js-visualizar');
        await expect(page.locator('#expVisualizarMenu')).toBeVisible();
        await expect(page.locator('.js-visualizar')).toHaveAttribute('aria-expanded', 'true');
        await page.keyboard.press('Escape');
        await expect(page.locator('#expVisualizarMenu')).toBeHidden();
        await expect(page.locator('.js-visualizar')).toBeFocused();

        // AUD-28: menu de contexto do dc.
        await page.click('#tabelaPastas tbody tr.pasta-row-link td:nth-child(3)', { button: 'right' });
        const menu = await medir(page, '.exp-ctx');
        const titulo = await medir(page, '.exp-ctx-titulo');
        expect(Math.round(menu.w)).toBe(250);
        expect(titulo.fonte).toBe('12px');
        expect(titulo.bg).toBe('rgba(0, 0, 0, 0)');
        expect(titulo.transform).toBe('none');
        await expect(page.locator('.exp-ctx')).toContainText('Copiar número da pasta');
        await page.keyboard.press('Escape');

        // Linha sob o mouse: azul do dc.
        await page.hover('#tabelaPastas tbody tr.pasta-row-link:not(.pasta-excluida) td:nth-child(3)');
        await expect.poll(async () => (await medir(page, '#tabelaPastas tbody tr.pasta-row-link:not(.pasta-excluida):hover td:nth-child(3)')).bg).toBe('rgb(59, 143, 194)');
    });

    test('comportamento: chips e "Limpar tudo" com a situação fora do form, voltar da pasta, modos, teclado e iniciais', async ({ browser }) => {
        const page = await abrirLista(browser, 1440);
        const resposta = () => page.waitForResponse(r => /\/expediente\//.test(r.url()) && r.request().method() === 'GET' && r.headers()['content-type']?.includes('html'));

        // Remover o chip "Situação" zera o select da barra (form.elements.namedItem).
        await Promise.all([resposta(), page.selectOption('.exp-situacao-select', 'ativo')]);
        await Promise.all([resposta(), page.click('.js-chip-remover[data-campo="status"]')]);
        await expect(page.locator('.exp-situacao-select')).toHaveValue('');
        await expect(page.locator('.exp-situacao')).not.toHaveClass(/is-ativo/);

        // "Limpar tudo" zera a situação também.
        await Promise.all([resposta(), page.selectOption('.exp-situacao-select', 'arquivado')]);
        await Promise.all([resposta(), page.click('.js-pasta-limpar')]);
        await expect(page.locator('.exp-situacao-select')).toHaveValue('');

        // Voltar de uma pasta: o filtro da situação (salvo pelo FormData) volta junto.
        await Promise.all([resposta(), page.selectOption('.exp-situacao-select', 'ativo')]);
        await expect(page.locator('#pastas-resultado')).not.toHaveClass(/pastas-carregando/);
        await Promise.all([page.waitForURL(/\/pasta\//, { waitUntil: 'domcontentloaded' }), page.click('#tabelaPastas tbody tr.pasta-row-link:not(.pasta-excluida) td:nth-child(3)')]);
        // A volta é reconhecida pelo referrer /pasta/{id} (index.html.twig `voltouDePastaShow`), como no link da pasta.
        await page.goto('/expediente', { referer: page.url(), waitUntil: 'networkidle' });
        await expect(page.locator('.exp-situacao-select')).toHaveValue('ativo');
        await page.evaluate(() => document.querySelectorAll('.modal.show, .modal-backdrop, .sf-toolbar').forEach(n => n.remove()));
        await Promise.all([resposta(), page.click('.js-pasta-limpar')]);

        // Visualizar pelo teclado: seta até "Cartões" (E12: Detalhes, Detalhes compactos, Lista, Cartões), Enter troca o modo.
        await page.click('.js-visualizar');
        await expect(page.locator('.exp-vis-item[data-view="tabela"]')).toBeFocused();
        for (let i = 0; i < 3; i++) { await page.keyboard.press('ArrowDown'); }
        await expect(page.locator('.exp-vis-item[data-view="lista"]')).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(page.locator('#pastasView')).toHaveClass(/pastas-view--lista/);
        await expect(page.locator('.js-visualizar')).toBeFocused();
        await page.click('.js-visualizar');
        await expect(page.locator('.exp-vis-item[data-view="lista"]')).toHaveAttribute('aria-checked', 'true');
        await page.click('.exp-vis-item[data-view="tabela"]');
        await expect(page.locator('#pastasView')).toHaveClass(/pastas-view--tabela/);

        // "Painel de marcadores" recolhe e abre o trilho pelo botão de sempre.
        await page.click('.js-visualizar');
        await page.click('.js-vis-painel');
        await expect(page.locator('#expedienteSidebar')).toHaveClass(/sidebar-collapsed/);
        await page.click('.js-visualizar');
        await expect(page.locator('.js-vis-painel')).toHaveAttribute('aria-checked', 'false');
        await page.click('.js-vis-painel');
        await expect(page.locator('#expedienteSidebar')).not.toHaveClass(/sidebar-collapsed/);

        // Visualizar aberto + botão direito numa linha: só um menu fica aberto.
        await page.click('.js-visualizar');
        await page.click('#tabelaPastas tbody tr.pasta-row-link td:nth-child(3)', { button: 'right' });
        await expect(page.locator('#expVisualizarMenu')).toBeHidden();
        await expect(page.locator('.exp-ctx')).toBeVisible();
        await page.keyboard.press('Escape');

        // Iniciais (duas primeiras palavras) e "?" na Lista, também depois de trocar de página por AJAX.
        const conferirIniciais = () => page.evaluate(() => [...document.querySelectorAll('#pastasView .pasta-resp-chip')].map(c => {
            const nome = (c.querySelector('.pasta-resp-nome') || {}).textContent || '';
            const ini = c.querySelector('.resp-avatar-ini');
            const vazio = c.querySelector('.resp-avatar-vazio');
            if (vazio) { return vazio.textContent.trim() === '?'; }
            if (!ini) { return true; }   // foto carregada
            const esperado = nome.trim().split(/\s+/).slice(0, 2).map(p => p.charAt(0)).join('').toUpperCase();
            return ini.textContent.trim() === esperado;
        }).every(Boolean));
        await expect.poll(conferirIniciais).toBe(true);
        const proxima = page.locator('.exp-controles .page-item:not(.disabled):not(.active) .btn-pagina[aria-label="Página 2"]');
        if (await proxima.count()) {
            await Promise.all([resposta(), proxima.click()]);
            await expect(page.locator('#pastas-resultado')).not.toHaveClass(/pastas-carregando/);
            await expect.poll(conferirIniciais).toBe(true);
        }
    });

    test('tema escuro: o chip do marcador usa a cor do marcador (E12) e nada estoura', async ({ browser }) => {
        const page = await abrirLista(browser, 1440);
        await page.evaluate(() => document.documentElement.setAttribute('data-bs-theme', 'dark'));
        const chip = page.locator('#tabelaPastas .exp-chip-marc').first();
        if (await chip.count()) {
            const [cor, mk] = await chip.evaluate(el => [getComputedStyle(el).color, getComputedStyle(el).getPropertyValue('--mk-cor').trim()]);
            expect(mk).not.toBe('');
            expect(cor).not.toBe('rgba(0, 0, 0, 0)');
        }
        expect(await semEstouro(page)).toBe(true);
    });
});
