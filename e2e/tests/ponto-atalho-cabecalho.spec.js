// @ts-check
//
// Atalho de bater ponto na sua linha do painel de presença (spec docs/specs/presenca-e-ponto-no-cabecalho.md §3,
// risco ALTO). O PHPUnit só lê o texto do script; aqui se prova o COMPORTAMENTO das corridas que a revisão apontou,
// com o navegador de verdade.
//
// NADA É GRAVADO NO BANCO: `GET /ponto/estado-do-dia` e `POST /ponto/batida` são interceptados no navegador e
// respondidos pelo teste. O aviso (`window.bjAviso`) é trocado por um gravador antes de a página carregar.
//
// Uso (servidor da worktree e um usuário com o módulo ponto e jornada):
//   PONTO_ATALHO_E2E_URL=http://127.0.0.1:8101 PONTO_ATALHO_E2E_EMAIL=... PONTO_ATALHO_E2E_SENHA=... \
//     npx playwright test --project=ponto-atalho

const { test, expect } = require('@playwright/test');

const BASE = process.env.PONTO_ATALHO_E2E_URL || 'http://127.0.0.1:8101';
const SEDE = { nome: 'Sede de teste', latitude: -15.81100086, longitude: -48.06479935, raio: 100 };
const LONGE = { latitude: -15.79500000, longitude: -48.04000000 };   // ~3 km da sede

function estado(nome, extra) {
    const hoje = { entrada: null, repouso: null, retorno: null, saida: null };
    if (nome === 'em_jornada') { hoje.entrada = '08:00'; }
    return Object.assign({ dia: new Date().toLocaleDateString('sv-SE'), estado: nome, hoje, homeOffice: false, sedes: [SEDE], interjornadaLigada: true }, extra || {});
}

let cookies = [];

/** Entra UMA vez e guarda os cookies: o limitador do login é de 5 tentativas por 15 minutos. */
test.beforeAll(async ({ browser }) => {
    const contexto = await browser.newContext();
    const pagina = await contexto.newPage();
    await pagina.goto(BASE + '/login');
    await pagina.fill('#inputEmail', process.env.PONTO_ATALHO_E2E_EMAIL || '');
    await pagina.fill('#senha', process.env.PONTO_ATALHO_E2E_SENHA || '');
    await Promise.all([pagina.waitForNavigation(), pagina.click('#entrar')]);
    if (pagina.url().includes('escritorio/selecionar')) {
        await pagina.locator('form button, a.escritorio-opcao, [data-escritorio]').first().click();
        await pagina.waitForLoadState('networkidle');
    }
    cookies = await contexto.cookies();
    await contexto.close();
});

/** Grava os avisos, atrasa a posição (para caber a corrida) e abre o painel, com a sessão do beforeAll. */
async function abrirPainel(page, { atrasoPosicaoMs = 0 } = {}) {
    await page.addInitScript((atraso) => {
        window.__avisos = [];
        window.bjAviso = (t) => window.__avisos.push(String(t));
        const original = navigator.geolocation.getCurrentPosition.bind(navigator.geolocation);
        navigator.geolocation.getCurrentPosition = (ok, erro, op) => setTimeout(() => original(ok, erro, op), atraso);
    }, atrasoPosicaoMs);
    await page.context().addCookies(cookies);
    await page.goto(BASE + '/expediente', { waitUntil: 'networkidle' });
    await page.locator('[data-presenca-selo]').click();
}

const avisos = (page) => page.evaluate(() => window.__avisos);
const batimento = (page) => page.evaluate(() => document.dispatchEvent(new CustomEvent('bj-central:presenca', { detail: { online: [] } })));

test.describe('atalho de ponto no cabeçalho', () => {
    test.use({ viewport: { width: 1580, height: 902 }, permissions: ['geolocation'], geolocation: { latitude: SEDE.latitude, longitude: SEDE.longitude, accuracy: 15 } });

    test('ponto mudou em outra tela no meio do clique: a releitura compara com o retrato do clique e nada é enviado', async ({ page }) => {
        let leituras = 0;
        let envios = 0;
        await page.route('**/ponto/estado-do-dia', (r) => { leituras++; r.fulfill({ json: estado(leituras === 1 ? 'bater_entrada' : 'em_jornada') }); });
        await page.route('**/ponto/batida', (r) => { envios++; r.fulfill({ json: { success: true, message: 'ok', data: { hora: '09:00:00' } } }); });
        await abrirPainel(page, { atrasoPosicaoMs: 1500 });
        await expect(page.locator('.presenca-sub--ponto.is-acao')).toHaveText(/Bater ponto/);

        await page.locator('.presenca-sub--ponto.is-acao').click();
        await page.waitForTimeout(300);
        await batimento(page);   // o relógio de fundo relê o estado (agora em jornada) durante a espera da posição
        await page.waitForTimeout(2500);

        expect(envios).toBe(0);
        expect(await avisos(page)).toContain('O ponto mudou em outra tela; confira antes de bater.');
    });

    test('sucesso: a trava só sai com o estado novo; o segundo clique antes disso não envia de novo', async ({ page }) => {
        let leituras = 0;
        let envios = 0;
        await page.route('**/ponto/estado-do-dia', async (r) => {
            leituras++;
            if (leituras >= 3) { await new Promise((ok) => setTimeout(ok, 1500)); }   // a releitura pós-sucesso demora
            r.fulfill({ json: estado(leituras >= 3 ? 'em_jornada' : 'bater_entrada') });
        });
        await page.route('**/ponto/batida', (r) => { envios++; r.fulfill({ json: { success: true, message: 'ok', data: { hora: '09:00:00' } } }); });
        await abrirPainel(page);

        const item = page.locator('.presenca-sub--ponto.is-acao');
        await item.click();
        await expect.poll(() => envios).toBe(1);
        await item.click({ force: true });   // ainda "Bater ponto" na tela, mas a trava segura
        await page.waitForTimeout(2500);

        expect(envios).toBe(1);
        expect(await avisos(page)).toContain('Ponto de entrada registrado às 09:00');
        await expect(page.locator('.presenca-sub--ponto')).toHaveText(/Em jornada desde 08:00/);
    });

    test('sem localização ao fim do prazo: nada é enviado', async ({ page, context }) => {
        let envios = 0;
        await context.clearPermissions();
        await page.route('**/ponto/estado-do-dia', (r) => r.fulfill({ json: estado('bater_entrada') }));
        await page.route('**/ponto/batida', (r) => { envios++; r.fulfill({ json: { success: true } }); });
        await abrirPainel(page);

        await page.locator('.presenca-sub--ponto.is-acao').click();
        await expect.poll(() => avisos(page), { timeout: 15000 }).toContain('Não foi possível obter a sua localização; permita a localização e tente de novo.');
        expect(envios).toBe(0);
    });

    test('certamente fora da sede: a tela recusa como a do ponto e nada é enviado', async ({ page, context }) => {
        let envios = 0;
        await context.setGeolocation({ latitude: LONGE.latitude, longitude: LONGE.longitude, accuracy: 15 });
        await page.route('**/ponto/estado-do-dia', (r) => r.fulfill({ json: estado('bater_entrada') }));
        await page.route('**/ponto/batida', (r) => { envios++; r.fulfill({ json: { success: true } }); });
        await abrirPainel(page);

        await page.locator('.presenca-sub--ponto.is-acao').click();
        await expect.poll(() => avisos(page)).toEqual([expect.stringMatching(/^Não registrou: você está a \d+ m da sede Sede de teste, fora da área permitida/)]);
        expect(envios).toBe(0);
    });

    // Precisa, no banco da demo, de uma entrada sem saída num dia útil (PONTO_ATALHO_E2E_DIA_SEM_SAIDA, AAAA-MM-DD).
    test('regularizar ontem: leva à folha e abre o MESMO "+" da saída daquele dia, preenchido; nenhuma batida sai', async ({ page }) => {
        const dia = process.env.PONTO_ATALHO_E2E_DIA_SEM_SAIDA || '2026-10-16';
        let envios = 0;
        await page.route('**/ponto/estado-do-dia', (r) => r.fulfill({ json: estado('regularizar_ontem', { ontemSemSaida: dia }) }));
        await page.route('**/ponto/batida', (r) => { envios++; r.fulfill({ json: { success: true } }); });
        await abrirPainel(page);

        await expect(page.locator('.presenca-sub--ponto.is-acao')).toHaveText(/Regularizar ponto de ontem/);
        await Promise.all([page.waitForURL(new RegExp('/ponto/\\?competencia=' + dia.slice(0, 7))), page.locator('.presenca-sub--ponto.is-acao').click()]);
        const modal = page.locator('#modalEsquecimentoRegistro');
        await expect(modal).toBeVisible();
        await expect(page.locator('#esquecimentoData')).toHaveValue(dia);
        await expect(page.locator('#esquecimentoCampo')).toHaveValue('saida');
        const [a, m, d] = dia.split('-');
        await expect(page.locator('#esquecimentoResumo')).toContainText(d + '/' + m + '/' + a);
        expect(new URL(page.url()).searchParams.get('esquecimento')).toBeNull();
        expect(envios).toBe(0);
    });
});
