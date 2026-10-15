// @ts-check
/**
 * Central de Comunicação: fidelidade do LANÇADOR ao protótipo do Claude Designer (Fase A, 15/10/2026).
 *
 * Abre o protótipo (pacote de 13/10, servido aqui mesmo por um servidor estático) e o sistema, põe os dois no MESMO
 * estado e compara geometria, estilo computado, animações (keyframes e tempos lidos do navegador) e comportamento.
 * O protótipo é a referência: quem diverge é o sistema.
 *
 * Isolamento: no sistema, TODA chamada a /central/* (a API inteira da Central) é interceptada no navegador e
 * respondida com dados fictícios: nenhuma conversa real é lida, marcada ou alterada, e cada teste exige que nenhuma
 * escrita tenha sido tentada. O lembrete do ponto responde "sem alerta". Vão ao servidor: o login e a escolha do
 * escritório (gravam a sessão), as páginas HTML e as leituras do sino de notificações do layout (fora da Central).
 *
 * Como rodar (fora do container, com a demo da worktree de pé: scripts/central-demo.sh designer-central-ia 8002):
 *   CENTRAL_E2E_EMAIL=... CENTRAL_E2E_SENHA=... CENTRAL_E2E_ESCRITORIO="Nome do escritório" \
 *     npx playwright test --project=central-fidelidade
 * O projeto só existe na config quando CENTRAL_E2E_EMAIL vem no comando (não ponha no e2e/.env): o `npm test` comum
 * não depende da demo de pé nem mostra a suíte como pulada.
 * Variáveis opcionais: CENTRAL_BASE_URL (padrão http://127.0.0.1:8002), CENTRAL_PROTOTIPO_DIR (pasta do protótipo),
 * CENTRAL_E2E_PAGINA (padrão /expediente). Sem credenciais ou sem a pasta do protótipo, a suíte é pulada.
 * Com --headed dá para ver os estados (calmo, com não lidas, com menção) acontecendo.
 */
const { test, expect } = require('@playwright/test');
const http = require('http');
const fs = require('fs');
const path = require('path');

const PROTOTIPO_DIR = process.env.CENTRAL_PROTOTIPO_DIR
    || '/home/prime/projetos/bluejus-designer/REPOSITORIO_BLUEJUS/prototipo';
const PAGINA_PROTOTIPO = '02 - EXPEDIENTES 1.2.3.dc.html';
const APP = process.env.CENTRAL_BASE_URL || 'http://127.0.0.1:8002';
const PAGINA_APP = process.env.CENTRAL_E2E_PAGINA || '/expediente';
const EMAIL = process.env.CENTRAL_E2E_EMAIL || '';
const SENHA = process.env.CENTRAL_E2E_SENHA || '';
const ESCRITORIO = process.env.CENTRAL_E2E_ESCRITORIO || '';
const SESSAO = path.join(__dirname, '../test-results/central-fidelidade-sessao.json');

// Pares de seletores: protótipo × sistema.
const P = {
    lancador: 'button[aria-label^="Central de Comunicação"]',
    janela: '[role="dialog"][aria-label="Central de Comunicação"]',
    minimizar: 'button[aria-label^="Minimizar"]',
    dica: 'body > div[role="tooltip"]',
};
const S = {
    lancador: '[data-flutuante-lancador]',
    janela: '[data-flutuante-janela]',
    minimizar: '[data-flutuante-minimizar]',
    badge: '[data-flutuante-badge]',
    mencoes: '[data-flutuante-mencoes]',
    dica: '.bj-central-dica',
};

test.describe.configure({ mode: 'serial' });
test.use({ viewport: { width: 1366, height: 800 }, reducedMotion: 'no-preference', locale: 'pt-BR' });

const pular = !fs.existsSync(path.join(PROTOTIPO_DIR, PAGINA_PROTOTIPO)) || !EMAIL || !SENHA;

// ── servidor estático do protótipo ─────────────────────────────────────────────────────────────────────────────
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json',
    '.geojson': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };
let servidor = null;
let PROTO = '';

function servirPrototipo() {
    return new Promise((ok) => {
        servidor = http.createServer((req, res) => {
            const rel = decodeURIComponent(new URL(req.url || '/', 'http://x').pathname);
            const arquivo = path.normalize(path.join(PROTOTIPO_DIR, rel));
            if (!arquivo.startsWith(PROTOTIPO_DIR) || !fs.existsSync(arquivo) || !fs.statSync(arquivo).isFile()) {
                res.writeHead(404); res.end(); return;
            }
            res.writeHead(200, { 'Content-Type': MIME[path.extname(arquivo)] || 'application/octet-stream' });
            fs.createReadStream(arquivo).pipe(res);
        }).listen(0, '127.0.0.1', () => {
            const end = servidor.address();
            PROTO = 'http://127.0.0.1:' + (typeof end === 'object' && end ? end.port : 0) + '/' + encodeURIComponent(PAGINA_PROTOTIPO);
            ok();
        });
    });
}

// ── sistema: dados fictícios no lugar de /central/* ────────────────────────────────────────────────────────────
function cenario(naoLidas = 0, mencoes = []) {
    return { naoLidas, mencoes, escritas: [] };
}

async function isolar(contexto, c) {
    await contexto.route((url) => url.pathname === '/ponto/alerta-horario', (r) => r.fulfill({ json: { alertar: false } }));
    await contexto.route((url) => url.pathname.startsWith('/central/'), (r) => {
        const req = r.request();
        const caminho = new URL(req.url()).pathname;
        if (req.method() !== 'GET') { c.escritas.push(req.method() + ' ' + caminho); return r.fulfill({ json: {} }); }
        if (caminho === '/central/novidades') {
            return r.fulfill({ json: { mensagens: [], cursor: 1000 + c.naoLidas, naoLidas: c.naoLidas, completo: true, conversasComMencao: c.mencoes } });
        }
        if (caminho === '/central/conversas') { return r.fulfill({ json: { conversas: [], conversasComMencao: c.mencoes } }); }
        if (caminho === '/central/contatos') { return r.fulfill({ json: { contatos: [] } }); }
        return r.fulfill({ json: {} });
    });
}

async function entrar(browser) {
    const contexto = await browser.newContext({ viewport: { width: 1366, height: 800 } });
    await isolar(contexto, cenario());
    const page = await contexto.newPage();
    // O servidor embutido acabado de subir às vezes perde o 1º envio do login (cache frio): tenta de novo.
    for (let tentativa = 1; ; tentativa++) {
        await page.goto(APP + '/login', { timeout: 120000 });
        await page.fill('input[name="email"], input[name="_username"]', EMAIL);
        await page.fill('input[name="password"], input[name="_password"]', SENHA);
        await page.click('button[type="submit"]');
        try {
            await page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 45000 });
            break;
        } catch (e) {
            if (tentativa >= 3) { throw e; }
        }
    }
    if (page.url().includes('/escritorio/selecionar')) {
        const botoes = page.locator('main button[type="submit"]');
        await (ESCRITORIO ? botoes.filter({ hasText: ESCRITORIO }).first() : botoes.first()).click();
        await page.waitForURL((u) => !u.pathname.includes('/selecionar'), { timeout: 60000 });
    }
    await contexto.storageState({ path: SESSAO });
    await contexto.close();
}

async function abrirSistema(browser, c, opcoes = {}) {
    const contexto = await browser.newContext({ storageState: SESSAO, viewport: opcoes.viewport || { width: 1366, height: 800 }, reducedMotion: 'no-preference' });
    await isolar(contexto, c);
    const page = await contexto.newPage();
    // Estado inicial lido: o lançador só se redesenha depois da 1ª resposta de /central/novidades.
    const primeiraLeitura = page.waitForResponse((r) => new URL(r.url()).pathname === '/central/novidades');
    await page.goto(APP + PAGINA_APP);
    await page.waitForSelector(S.lancador);
    await primeiraLeitura;
    await expect(page.locator(S.lancador)).toHaveAttribute('aria-label', c.naoLidas ? /não lida/ : /tudo em dia/);
    return page;
}

// ── protótipo: estado calmo (tudo lido) e menção nova pela API dele ─────────────────────────────────────────────
async function abrirPrototipo(browser, opcoes = {}) {
    const contexto = await browser.newContext({ viewport: opcoes.viewport || { width: 1366, height: 800 }, reducedMotion: 'no-preference' });
    const page = await contexto.newPage();
    await page.goto(PROTO);
    await page.waitForFunction((sel) => !!(window.BJCentral && document.querySelector(sel)), P.lancador);
    await page.evaluate(() => {   // tudo lido: o protótipo relê o armazenamento no evento storage (L263)
        const db = JSON.parse(localStorage.getItem('bj-central-v1') || '{"notifs":[],"msgs":[]}');
        (db.notifs || []).forEach((n) => { n.lida = true; });
        (db.msgs || []).forEach((m) => { m.lida = true; m.lidaPor = ['farlei', 'samuel', 'jessica', 'mariana', 'rafael', 'thamara', 'rodrigo', 'helena']; });
        localStorage.setItem('bj-central-v1', JSON.stringify(db));
        window.dispatchEvent(new StorageEvent('storage', { key: 'bj-central-v1' }));
    });
    await expect(page.locator(P.lancador)).toHaveAttribute('aria-label', /tudo em dia/);
    return page;
}

async function mencaoNoPrototipo(page) {   // 1 não lida, do tipo menção (nível 2: contador laranja, salto, "@1")
    await page.evaluate(() => window.BJCentral.notificar({ tipo: 'mencao', de: 'jessica', titulo: 'Jéssica mencionou você', texto: 'Teste de fidelidade' }));
}

// ── medidas ─────────────────────────────────────────────────────────────────────────────────────────────────────
// Sempre por SELETOR e numa única ida à página: o protótipo redesenha o lançador inteiro de tempos em tempos
// (raiz.innerHTML = '', L6322), e um elemento achado antes e medido depois já estaria fora da página.
// `alvo` = seletor, ou { sel, texto } para escolher pelo texto (os contadores do protótipo não têm classe).
function resolver(alvo) {
    return typeof alvo === 'string' ? { sel: alvo, texto: null } : { sel: alvo.sel, texto: alvo.texto.source };
}

async function medir(page, alvo, fn, arg) {
    return page.evaluate(({ a, corpo, x }) => {
        const el = [...document.querySelectorAll(a.sel)].find((n) => !a.texto || new RegExp(a.texto).test(n.textContent || ''));
        if (!el) { return { semElemento: a.sel }; }
        return new Function('el', 'arg', corpo)(el, x);
    }, { a: resolver(alvo), corpo: fn, x: arg === undefined ? null : arg });
}

const caixa = (page, alvo) => medir(page, alvo, `
    const r = el.getBoundingClientRect();
    return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) };`);

const estilos = (page, alvo, props) => medir(page, alvo, `
    const cs = getComputedStyle(el), o = {};
    arg.forEach((p) => { o[p] = cs.getPropertyValue(p); });
    return o;`, props);

// Animações em curso no elemento (e, se pedido, nos descendentes): keyframes e tempos, sem o nome (o sistema
// prefixa os seus para não colidir com o resto do sistema; o que importa é o movimento).
const animacoes = (page, alvo, subarvore = false) => medir(page, alvo, `
    return el.getAnimations({ subtree: !!arg }).map((a) => {
        const t = a.effect.getTiming();
        const kf = a.effect.getKeyframes().map((k) => ({ offset: k.offset, easing: k.easing, transform: k.transform || null, opacity: k.opacity || null }));
        return { duracao: t.duration, iteracoes: t.iterations, atraso: t.delay, easing: t.easing, keyframes: kf };
    }).sort((x, y) => x.atraso - y.atraso);`, subarvore);

// Formas do SVG visível do lançador, atributo a atributo (sem class/style/data-*).
const formas = (page, alvo) => medir(page, alvo, `
    const svg = [...el.querySelectorAll('svg')].find((s) => s.getBoundingClientRect().width > 0);
    if (!svg) { return ['sem SVG visível']; }
    return [...svg.querySelectorAll('rect,polygon,path,circle')].map((n) => {
        const a = {};
        [...n.attributes].forEach((at) => { if (!/^(class|style|data-)/.test(at.name)) { a[at.name] = at.value; } });
        return n.tagName + JSON.stringify(Object.keys(a).sort().map((k) => [k, a[k]]));
    });`);

// Clica e lê as animações do alvo no MESMO passo: a de abrir dura 0,2 s e a de minimizar 0,26 s; com dois passos a
// leitura pode chegar depois do fim (máquina carregada) e o teste passaria a medir nada.
const clicarEMedir = (page, botao, alvo) => page.evaluate(({ b, a }) => {
    document.querySelector(b).click();
    const el = document.querySelector(a);
    return el ? el.getAnimations().map((x) => {
        const t = x.effect.getTiming();
        const kf = x.effect.getKeyframes().map((k) => ({ offset: k.offset, easing: k.easing, transform: k.transform || null, opacity: k.opacity || null }));
        return { duracao: t.duration, iteracoes: t.iterations, atraso: t.delay, easing: t.easing, keyframes: kf };
    }) : null;
}, { b: botao, a: alvo });

async function evidencia(testInfo, nome, page, clip) {
    const arquivo = testInfo.outputPath(nome + '.png');
    await page.screenshot({ path: arquivo, clip });
    await testInfo.attach(nome, { path: arquivo, contentType: 'image/png' });
}

const CLIP_LANCADOR = { x: 1146, y: 600, width: 220, height: 200 };
const ESTILO_LANCADOR = ['width', 'height', 'filter', 'transition', 'cursor', 'background-color', 'border-top-width', 'padding-top'];
const ESTILO_BADGE = ['min-width', 'height', 'padding-left', 'padding-right', 'border-radius', 'background-color', 'color',
    'font-size', 'font-weight', 'box-shadow', 'top', 'right'];
const ESTILO_MENCOES = ['height', 'padding-left', 'padding-right', 'border-radius', 'background-color', 'color', 'font-size',
    'font-weight', 'box-shadow', 'bottom', 'left'];
const ESTILO_DICA = ['position', 'z-index', 'max-width', 'padding-top', 'padding-left', 'border-top-width', 'border-top-color',
    'border-top-style', 'border-radius', 'background-color', 'color', 'font-family', 'font-size', 'font-weight', 'line-height',
    'box-shadow', 'white-space', 'pointer-events', 'opacity', 'transform', 'transition'];

test.describe('Central: fidelidade do lançador ao Designer', () => {
    test.skip(pular, 'sem credenciais (CENTRAL_E2E_*) ou sem a pasta do protótipo');

    test.beforeAll(async ({ browser }) => {
        test.setTimeout(240000);
        await servirPrototipo();
        await entrar(browser);
    });

    test.afterAll(async () => {
        if (servidor) { servidor.close(); }
        try { fs.unlinkSync(SESSAO); } catch (e) { /* já não existe */ }
    });

    test('calmo: mesmo desenho, posição, sombra e transição', async ({ browser }, testInfo) => {
        const c = cenario();
        const [p, s] = await Promise.all([abrirPrototipo(browser), abrirSistema(browser, c)]);

        expect(await caixa(s, S.lancador)).toEqual(await caixa(p, P.lancador));
        expect(await formas(s, S.lancador)).toEqual(await formas(p, P.lancador));
        expect(await estilos(s, S.lancador, ESTILO_LANCADOR)).toEqual(await estilos(p, P.lancador, ESTILO_LANCADOR));
        await expect(s.locator(S.badge)).toBeHidden();
        await expect(s.locator(S.mencoes)).toBeHidden();
        expect(await animacoes(s, S.lancador, true)).toEqual([]);   // calmo não anima (L6400: anim só com não lidas)
        expect(await animacoes(p, P.lancador, true)).toEqual([]);

        await evidencia(testInfo, 'calmo-prototipo', p, CLIP_LANCADOR);
        await evidencia(testInfo, 'calmo-sistema', s, CLIP_LANCADOR);
        expect(c.escritas).toEqual([]);
        await Promise.all([p.context().close(), s.context().close()]);
    });

    test('não lida com menção: dois balões, pontos animados, contador laranja e "@n"', async ({ browser }, testInfo) => {
        const p = await abrirPrototipo(browser);
        await mencaoNoPrototipo(p);
        await expect(p.locator(P.lancador)).toHaveAttribute('aria-label', /não lida/);
        // O protótipo também gera avisos sozinho (IA, resumos): o sistema recebe o MESMO número que ele mostra.
        const BADGE_P = { sel: P.lancador + ' > span', texto: /^\d+$/ };
        const MENCOES_P = { sel: P.lancador + ' > span', texto: /^@/ };
        const numeros = () => p.evaluate((sel) => {
            const spans = [...document.querySelectorAll(sel + ' > span')].map((n) => n.textContent || '');
            return { n: Number(spans.find((t) => /^\d+$/.test(t))), m: Number((spans.find((t) => /^@/.test(t)) || '@0').slice(1)) };
        }, P.lancador);
        let ant = '', iguais = 0, leitura = await numeros();   // espera 3 s sem mudança (máx. 20 s)
        for (let i = 0; i < 40 && iguais < 6; i++) {
            await p.waitForTimeout(500);
            leitura = await numeros();
            iguais = JSON.stringify(leitura) === ant ? iguais + 1 : 0;
            ant = JSON.stringify(leitura);
        }
        const { n, m } = leitura;
        const c = cenario(n, Array.from({ length: m }, (_, i) => 9001 + i));
        const s = await abrirSistema(browser, c);
        expect(await numeros()).toEqual({ n, m });   // o protótipo não mudou no meio

        expect(await formas(s, S.lancador)).toEqual(await formas(p, P.lancador));
        await expect(s.locator(S.badge)).toHaveText(String(n));
        await expect(s.locator(S.mencoes)).toHaveText('@' + m);
        expect(await estilos(s, S.badge, ESTILO_BADGE)).toEqual(await estilos(p, BADGE_P, ESTILO_BADGE));
        expect(await estilos(s, S.mencoes, ESTILO_MENCOES)).toEqual(await estilos(p, MENCOES_P, ESTILO_MENCOES));
        expect(await caixa(s, S.badge)).toEqual(await caixa(p, BADGE_P));
        expect(await caixa(s, S.mencoes)).toEqual(await caixa(p, MENCOES_P));

        // Os três pontos: bjcPonto 1,5 s, atrasos 0/0,2/0,4 s, infinito (L6402).
        const pontos = async (pg, alvo) => (await animacoes(pg, alvo, true)).filter((a) => a.iteracoes === Infinity);
        const pp = await pontos(p, P.lancador), ps = await pontos(s, S.lancador);
        expect(pp).toHaveLength(3);
        expect(ps).toEqual(pp);

        await evidencia(testInfo, 'mencao-prototipo', p, CLIP_LANCADOR);
        await evidencia(testInfo, 'mencao-sistema', s, CLIP_LANCADOR);
        expect(c.escritas).toEqual([]);
        await Promise.all([p.context().close(), s.context().close()]);
    });

    test('chegada de mensagem: o mesmo salto (bjcSalto 0,9 s × 2)', async ({ browser }, testInfo) => {
        const p = await abrirPrototipo(browser);
        // notificar() liga o salto (L141) e redesenha na hora: lê a animação no mesmo passo (ela dura só 1,8 s).
        const saltoP = await p.evaluate((sel) => {
            window.BJCentral.notificar({ tipo: 'mencao', de: 'jessica', titulo: 'Jéssica mencionou você', texto: 'Teste de fidelidade' });
            return document.querySelector(sel).getAnimations().map((a) => {
                const t = a.effect.getTiming();
                const kf = a.effect.getKeyframes().map((k) => ({ offset: k.offset, easing: k.easing, transform: k.transform || null, opacity: k.opacity || null }));
                return { duracao: t.duration, iteracoes: t.iterations, atraso: t.delay, easing: t.easing, keyframes: kf };
            }).filter((a) => a.iteracoes === 2);
        }, P.lancador);
        expect(saltoP).toHaveLength(1);

        // Sistema: começa calmo e o polling seguinte traz 1 não lida. O relógio falso adianta os 30 s do polling.
        const c = cenario();
        const contexto = await browser.newContext({ storageState: SESSAO, viewport: { width: 1366, height: 800 }, reducedMotion: 'no-preference' });
        await isolar(contexto, c);
        const s = await contexto.newPage();
        await s.clock.install();
        await s.goto(APP + PAGINA_APP);
        await s.waitForSelector(S.lancador);
        await s.clock.runFor(2000);
        await expect(s.locator(S.lancador)).toHaveAttribute('aria-label', /tudo em dia/);
        c.naoLidas = 1; c.mencoes = [9001];
        await s.clock.runFor(31000);
        await expect(s.locator(S.lancador)).toHaveAttribute('aria-label', /1 não lida/);
        const saltoS = (await animacoes(s, S.lancador)).filter((a) => a.iteracoes === 2);
        expect(saltoS).toEqual(saltoP);

        await evidencia(testInfo, 'salto-prototipo', p, CLIP_LANCADOR);
        await evidencia(testInfo, 'salto-sistema', s, CLIP_LANCADOR);
        expect(c.escritas).toEqual([]);
        await Promise.all([p.context().close(), contexto.close()]);
    });

    test('hover: sobe 3 px, cresce 6% e a sombra aumenta', async ({ browser }) => {
        const c = cenario();
        const [p, s] = await Promise.all([abrirPrototipo(browser), abrirSistema(browser, c)]);
        await p.locator(P.lancador).hover();
        await s.locator(S.lancador).hover();
        await p.waitForTimeout(450);
        await s.waitForTimeout(450);
        const props = ['transform', 'filter'];
        const sistema = await estilos(s, S.lancador, props);
        expect(sistema.transform).toBe('matrix(1.06, 0, 0, 1.06, 0, -3)');
        // O protótipo redesenha o botão de tempos em tempos (o hover reinicia a transição): espera o valor final.
        await expect.poll(async () => {
            const r = await caixa(p, P.lancador);
            await p.mouse.move(r.x + r.w / 2 + 1, r.y + r.h / 2);
            return estilos(p, P.lancador, props);
        }, { timeout: 8000, intervals: [400] }).toEqual(sistema);
        await Promise.all([p.context().close(), s.context().close()]);
    });

    test('balão de dica do Designer no lançador (bj-dica.js), restrito à Central', async ({ browser }, testInfo) => {
        const c = cenario();
        const [p, s] = await Promise.all([abrirPrototipo(browser), abrirSistema(browser, c)]);
        await p.locator(P.lancador).hover();
        await s.locator(S.lancador).hover();
        const dp = p.locator(P.dica), ds = s.locator(S.dica);
        await expect(dp).toHaveCSS('opacity', '1');
        await expect(ds).toHaveCSS('opacity', '1');
        await expect(ds).toHaveText(/^Central de Comunicação · /);
        expect(await estilos(s, S.dica, ESTILO_DICA)).toEqual(await estilos(p, P.dica, ESTILO_DICA));

        // Mesmo posicionamento: acima do lançador a 8 px (não cabe embaixo) e a 8 px da borda direita.
        const pos = async (pg, dica, lanc) => {
            const d = await caixa(pg, dica), l = await caixa(pg, lanc);
            return { vao: l.y - (d.y + d.h), direita: 1366 - (d.x + d.w), altura: d.h };
        };
        expect(await pos(s, S.dica, S.lancador)).toEqual(await pos(p, P.dica, P.lancador));
        // O title virou data-bj-tip: a dica preta do navegador não aparece por cima.
        await expect(s.locator(S.lancador)).not.toHaveAttribute('title', /.*/);
        await expect(s.locator(S.lancador)).toHaveAttribute('data-bj-tip', /^Central de Comunicação · /);

        await evidencia(testInfo, 'dica-prototipo', p, CLIP_LANCADOR);
        await evidencia(testInfo, 'dica-sistema', s, CLIP_LANCADOR);

        // Some ao sair.
        await s.mouse.move(10, 400);
        await expect(ds).toHaveCSS('opacity', '0');

        // Fora da Central nada muda: o title continua com o navegador e o balão não aparece.
        const achou = await s.evaluate(() => {   // 1º elemento com title, à vista e fora da Central
            const el = [...document.querySelectorAll('[title]')].find((n) => {
                const r = n.getBoundingClientRect();
                return !n.closest('[data-central], [data-central-flutuante], [data-flutuante-janela]')
                    && r.width > 0 && r.height > 0 && r.top >= 0 && r.left >= 0 && r.bottom <= innerHeight && r.right <= innerWidth;
            });
            if (el) { el.setAttribute('data-teste-fora-da-central', '1'); }
            return !!el;
        });
        expect(achou).toBe(true);
        const fora = s.locator('[data-teste-fora-da-central]');
        await fora.hover();
        await s.waitForTimeout(450);
        await expect(fora).toHaveAttribute('title', /.+/);
        await expect(ds).toHaveCSS('opacity', '0');
        expect(c.escritas).toEqual([]);
        await Promise.all([p.context().close(), s.context().close()]);
    });

    test('abrir: mesma janela, mesmo bjcIn; minimizar voa até o ícone igual', async ({ browser }, testInfo) => {
        const c = cenario();
        const [p, s] = await Promise.all([abrirPrototipo(browser), abrirSistema(browser, c)]);
        const jp = p.locator(P.janela), js = s.locator(S.janela);
        const entradaP = await clicarEMedir(p, P.lancador, P.janela), entradaS = await clicarEMedir(s, S.lancador, S.janela);
        expect(entradaP).toHaveLength(1);
        expect(entradaS).toEqual(entradaP);
        await p.waitForTimeout(300);
        await s.waitForTimeout(300);
        expect(await caixa(s, S.janela)).toEqual(await caixa(p, P.janela));
        expect(await formas(s, S.lancador)).toEqual(await formas(p, P.lancador));   // chevron
        const props = ['border-radius', 'box-shadow', 'background-color'];
        expect(await estilos(s, S.janela, props)).toEqual(await estilos(p, P.janela, props));
        await evidencia(testInfo, 'aberto-prototipo', p, { x: 940, y: 120, width: 426, height: 680 });
        await evidencia(testInfo, 'aberto-sistema', s, { x: 940, y: 120, width: 426, height: 680 });

        const saidaP = await clicarEMedir(p, P.minimizar, P.janela), saidaS = await clicarEMedir(s, S.minimizar, S.janela);
        expect(saidaP).toHaveLength(1);
        expect(saidaS).toEqual(saidaP);
        await expect(js).toBeHidden();
        await expect(jp).toHaveCount(0);
        expect(c.escritas).toEqual([]);   // fechar/minimizar não marca nada como lido
        await Promise.all([p.context().close(), s.context().close()]);
    });

    test('celular 375 px: mesma posição do ícone; janela a 8 px da borda (desvio aprovado: o desenho dá x = −2)', async ({ browser }, testInfo) => {
        const viewport = { width: 375, height: 740 };
        const c = cenario();
        const [p, s] = await Promise.all([abrirPrototipo(browser, { viewport }), abrirSistema(browser, c, { viewport })]);
        expect(await caixa(s, S.lancador)).toEqual(await caixa(p, P.lancador));
        await p.locator(P.lancador).click();
        await s.locator(S.lancador).click();
        await p.waitForTimeout(300);
        await s.waitForTimeout(300);
        const jp = await caixa(p, P.janela), js = await caixa(s, S.janela);
        expect({ ...js, x: undefined }).toEqual({ ...jp, x: undefined });
        expect(jp.x).toBe(-2);
        expect(js.x).toBe(8);
        expect(await s.evaluate(() => document.documentElement.scrollWidth)).toBe(375);
        await evidencia(testInfo, 'celular-prototipo', p, { x: 0, y: 0, width: 375, height: 740 });
        await evidencia(testInfo, 'celular-sistema', s, { x: 0, y: 0, width: 375, height: 740 });
        await Promise.all([p.context().close(), s.context().close()]);
    });
});
