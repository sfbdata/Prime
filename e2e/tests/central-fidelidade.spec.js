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

test.describe.configure({ mode: 'serial', timeout: 120000 });   // servidor embutido de dev: a 1ª página de cada contexto é lenta
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
function cenario(naoLidas = 0, mencoes = [], extra = {}) {
    return { naoLidas, mencoes, escritas: [], conversas: [], ...extra };
}

// Um grupo fictício (id 77) em que eu sou administrador, com uma colega: chega às confirmações de Remover e Sair.
const GRUPO = { id: 77, tipo: 'grupo', nome: 'Grupo fictício de teste', participantes: 2, naoLidas: 0, marcadaNaoLida: false,
    fixada: false, silenciada: false, arquivada: false, ultima: null, outro: null, outroAtivo: true };
const PARTICIPANTES = { conversaId: 77, nome: GRUPO.nome, souAdministrador: true, participantes: [
    { id: 1, nome: 'Pessoa Teste', eu: true, administrador: true, ativoNoEscritorio: true },
    { id: 2, nome: 'Colega Fictícia', eu: false, administrador: false, ativoNoEscritorio: true },
] };

// Resposta fictícia de /central/* (e do lembrete do ponto) para o cenário; escritas são só anotadas.
function respostaSimulada(c, metodo, caminho) {
    if (caminho === '/ponto/alerta-horario') { return { status: 200, json: { alertar: false } }; }
    if (metodo !== 'GET') {
        c.escritas.push(metodo + ' ' + caminho);
        // Escrita "recusada" pelo servidor: prova que o erro vira aviso na janela (não window.alert).
        if (c.recusar) { return { status: 422, json: { erro: c.recusar } }; }
        return { status: 200, json: {} };
    }
    if (caminho === '/central/novidades') {
        return { status: 200, json: { mensagens: [], cursor: 1000 + c.naoLidas, naoLidas: c.naoLidas, completo: true, conversasComMencao: c.mencoes } };
    }
    if (caminho === '/central/conversas') { return { status: 200, json: { conversas: c.conversas, conversasComMencao: c.mencoes } }; }
    if (/^\/central\/conversas\/\d+\/participantes$/.test(caminho)) { return { status: 200, json: PARTICIPANTES }; }
    if (/^\/central\/conversas\/\d+\/mensagens$/.test(caminho)) { return { status: 200, json: { mensagens: [], temMais: false, mencionaveis: [] } }; }
    if (caminho === '/central/contatos') { return { status: 200, json: { contatos: [] } }; }
    return { status: 200, json: {} };
}

async function isolar(contexto, c) {
    await contexto.route((url) => url.pathname === '/ponto/alerta-horario' || url.pathname.startsWith('/central/'), (r) => {
        const req = r.request();
        const resp = respostaSimulada(c, req.method(), new URL(req.url()).pathname);
        return r.fulfill({ status: resp.status, json: resp.json });
    });
}

// B2: com a rede interceptada o Playwright pausa também os pedidos da janela Picture-in-Picture (que ele não conhece)
// e nunca os solta: as folhas de estilo dela não carregam. Aqui o isolamento é pelo fetch da página (o central.js é
// dela, inclusive destacado): /central/* responde o cenário pelo exposeFunction, sem tocar a rede. `vazou` anota
// qualquer pedido a /central/* que chegue à rede mesmo assim (o teste exige zero).
async function isolarPorScript(page, c) {
    c.vazou = [];
    page.on('request', (r) => {
        const u = new URL(r.url());
        if (u.pathname.startsWith('/central/') || u.pathname === '/ponto/alerta-horario') { c.vazou.push(r.method() + ' ' + u.pathname); }
    });
    await page.exposeFunction('__bjTesteCentral', (metodo, caminho) => { c.leituras = (c.leituras || 0) + (caminho === '/central/novidades' ? 1 : 0); return respostaSimulada(c, metodo, caminho); });
    await page.addInitScript(() => {
        const original = window.fetch.bind(window);
        window.fetch = (url, init) => {
            const u = new URL(typeof url === 'string' ? url : url.url, location.href);
            if (u.origin === location.origin && (u.pathname.startsWith('/central/') || u.pathname === '/ponto/alerta-horario')) {
                return window.__bjTesteCentral((init && init.method) || 'GET', u.pathname).then((r) =>
                    new Response(JSON.stringify(r.json), { status: r.status, headers: { 'Content-Type': 'application/json' } }));
            }
            return original(url, init);
        };
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
    if (!opcoes.porScript) { await isolar(contexto, c); }
    const page = await contexto.newPage();
    if (opcoes.porScript) { await isolarPorScript(page, c); }
    // Estado inicial lido: o lançador só se redesenha depois da 1ª resposta de /central/novidades.
    const primeiraLeitura = opcoes.porScript ? null : page.waitForResponse((r) => new URL(r.url()).pathname === '/central/novidades');
    await page.goto(APP + PAGINA_APP);
    await page.waitForSelector(S.lancador);
    if (primeiraLeitura) { await primeiraLeitura; }
    await expect(page.locator(S.lancador)).toHaveAttribute('aria-label', c.naoLidas ? /não lida/ : /tudo em dia/);
    return page;
}

// ── protótipo: estado calmo (tudo lido) e menção nova pela API dele ─────────────────────────────────────────────
async function abrirPrototipo(browser, opcoes = {}) {
    const contexto = await browser.newContext({ viewport: opcoes.viewport || { width: 1366, height: 800 }, reducedMotion: 'no-preference' });
    const page = await contexto.newPage();
    await page.goto(PROTO);
    await page.waitForFunction((sel) => !!(window.BJCentral && document.querySelector(sel)), P.lancador);
    // Tudo lido: o protótipo relê o armazenamento no evento storage (L263). Ele também GERA avisos sozinho logo depois
    // de montar (centralSync, E:4314; resumos da IA): o que chegar depois da limpeza é limpo de novo, até firmar.
    await expect.poll(async () => {
        await page.evaluate(() => {
            // Só o banco que o próprio protótipo gravou: um banco inventado ({notifs, msgs}) quebra o render dele.
            const salvo = localStorage.getItem('bj-central-v1');
            if (!salvo) { return; }
            const db = JSON.parse(salvo);
            (db.notifs || []).forEach((n) => { n.lida = true; });
            (db.msgs || []).forEach((m) => { m.lida = true; m.lidaPor = ['farlei', 'samuel', 'jessica', 'mariana', 'rafael', 'thamara', 'rodrigo', 'helena']; });
            localStorage.setItem('bj-central-v1', JSON.stringify(db));
            window.dispatchEvent(new StorageEvent('storage', { key: 'bj-central-v1' }));
        });
        await page.waitForTimeout(500);
        return page.evaluate((sel) => { const b = document.querySelector(sel); return b ? b.getAttribute('aria-label') : 'sem lançador'; }, P.lancador);
    }, { timeout: 20000, intervals: [100] }).toMatch(/tudo em dia/);
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
        // O protótipo às vezes se redesenha no meio (aviso gerado por ele, L6321) e soma um bjcIn: compara o VOO.
        const voo = (l) => l.filter((a) => a.keyframes.some((k) => /scale\(0\.08\)/.test(k.transform || '')));
        expect(voo(saidaP)).toHaveLength(1);
        expect(saidaS).toHaveLength(1);
        expect(saidaS).toEqual(voo(saidaP));
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
    // ── B1 Moldura (16/10): restaurar, cabeçalho, menu ⋮, confirmação e avisos ──────────────────────────────────
    test('B1 restaurar depois de maximizar: volta na hora, sem quadro intermediário', async ({ browser }, testInfo) => {
        const c = cenario();
        const [p, s] = await Promise.all([abrirPrototipo(browser), abrirSistema(browser, c)]);
        await p.locator(P.lancador).click();
        await s.locator(S.lancador).click();
        await p.waitForTimeout(300);
        await s.waitForTimeout(300);
        const normal = await caixa(p, P.janela);
        expect(await caixa(s, S.janela)).toEqual(normal);

        // Clica e mede no MESMO passo (o quadro logo depois do clique) e de novo 100 ms depois.
        const clicarEMedirCaixa = (pg, botao, janela) => pg.evaluate(({ b, j }) => {
            document.querySelector(b).click();
            const r = document.querySelector(j).getBoundingClientRect();
            return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) };
        }, { b: botao, j: janela });
        const maxP = await clicarEMedirCaixa(p, 'button[aria-label="Maximizar"]', P.janela);
        const maxS = await clicarEMedirCaixa(s, '[data-flutuante-maximizar]', S.janela);
        expect(maxS).toEqual(maxP);
        expect(maxP).toEqual({ x: 12, y: 12, w: 1342, h: 776 });
        await p.waitForTimeout(300);
        await s.waitForTimeout(300);

        const jaP = await clicarEMedirCaixa(p, 'button[aria-label="Restaurar tamanho"]', P.janela);
        const jaS = await clicarEMedirCaixa(s, '[data-flutuante-maximizar]', S.janela);
        expect(jaP).toEqual(normal);
        expect(jaS).toEqual(normal);   // antes: 1342×776 a partir de x = 968 por ~1 s
        await s.waitForTimeout(100);
        expect(await caixa(s, S.janela)).toEqual(normal);
        await evidencia(testInfo, 'restaurar-sistema', s, { x: 940, y: 120, width: 426, height: 680 });
        expect(c.escritas).toEqual([]);
        await Promise.all([p.context().close(), s.context().close()]);
    });

    test('B1 cabeçalho da lista: avatar com anel, sem ponto de status nem "on-line"; subtítulo do desenho', async ({ browser }, testInfo) => {
        const c = cenario();
        const [p, s] = await Promise.all([abrirPrototipo(browser), abrirSistema(browser, c)]);
        await p.locator(P.lancador).click();
        await s.locator(S.lancador).click();
        await p.waitForTimeout(300);
        await s.waitForTimeout(300);
        const AV_P = '[data-bjc-cab] > span:first-child > span:first-child';
        const AV_S = '[data-flutuante-janela] [data-central-cab-eu]';
        expect(await caixa(s, AV_S)).toEqual(await caixa(p, AV_P));
        const props = ['width', 'height', 'border-radius', 'box-shadow', 'color', 'font-size', 'font-weight'];
        expect(await estilos(s, AV_S, props)).toEqual(await estilos(p, AV_P, props));
        // Presença não autorizada: nada de ponto de status ao lado do avatar nem de pílula "N on-line".
        expect(await s.locator('[data-flutuante-janela] .bj-central-cab').textContent()).not.toMatch(/on-line/);
        expect(await s.locator(AV_S).evaluate((n) => n.parentElement.classList.contains('bj-central-cab'))).toBe(true);
        // Subtítulo: "nome · em dia" (L6338), mesma fonte e opacidade.
        const SUB_P = '[data-bjc-cab] > div > div:last-child';
        const SUB_S = '[data-flutuante-janela] [data-central-cab-sub]';
        await expect(s.locator(SUB_S)).toHaveText(/ · em dia$/);
        // O protótipo gera avisos ao abrir (resumos da IA, L6321): a regra é a mesma, "nome · N não lidas | em dia".
        await expect(p.locator(SUB_P)).toHaveText(/ · (em dia|1 não lida|\d+ não lidas)$/);
        const sub = ['font-size', 'opacity', 'white-space', 'color'];
        expect(await estilos(s, SUB_S, sub)).toEqual(await estilos(p, SUB_P, sub));
        await evidencia(testInfo, 'cabecalho-prototipo', p, { x: 960, y: 130, width: 400, height: 70 });
        await evidencia(testInfo, 'cabecalho-sistema', s, { x: 960, y: 130, width: 400, height: 70 });
        expect(c.escritas).toEqual([]);
        await Promise.all([p.context().close(), s.context().close()]);
    });

    test('B1 menu ⋮: painel do desenho, seções, chaves e valores; só comandos reais', async ({ browser }, testInfo) => {
        const c = cenario();
        const [p, s] = await Promise.all([abrirPrototipo(browser), abrirSistema(browser, c)]);
        await p.locator(P.lancador).click();
        await s.locator(S.lancador).click();
        await p.waitForTimeout(300);
        await s.waitForTimeout(300);
        const MENU_P = P.janela + ' [role="menu"]', MENU_S = S.janela + ' .bj-central-menu';
        const entradaP = await clicarEMedir(p, P.janela + ' button[aria-label="Mais opções"]', MENU_P);
        const entradaS = await clicarEMedir(s, S.janela + ' [data-central-mais]', MENU_S);
        expect(entradaP).toHaveLength(1);
        expect(entradaS).toEqual(entradaP);   // bjcIn .14s
        await p.waitForTimeout(250);
        await s.waitForTimeout(250);

        const painel = ['position', 'top', 'right', 'width', 'max-height', 'overflow-y', 'padding-top', 'padding-left',
            'border-radius', 'background-color', 'box-shadow', 'color', 'z-index'];
        expect(await estilos(s, MENU_S, painel)).toEqual(await estilos(p, MENU_P, painel));
        // Caixa de LAYOUT, relativa à janela (sem transform): o protótipo recria o menu a cada redesenho, com a entrada
        // de novo, e a caixa visual pode sair no meio da animação (scale .98).
        const layout = (pg, sel) => medir(pg, sel, `return { x: el.offsetLeft, y: el.offsetTop, w: el.offsetWidth, direita: el.offsetParent.clientWidth - el.offsetLeft - el.offsetWidth };`);
        expect(await layout(s, MENU_S)).toEqual(await layout(p, MENU_P));

        // Seção, item, ícone, separador, chave e valor: mesmo estilo computado.
        const SEC_P = { sel: MENU_P + ' > div', texto: /^Conversas$/ }, SEC_S = { sel: MENU_S + ' > .bj-central-menu-secao', texto: /^Conversas$/ };
        const sec = ['padding-top', 'padding-left', 'padding-bottom', 'font-size', 'font-weight', 'letter-spacing', 'text-transform', 'color'];
        expect(await estilos(s, SEC_S, sec)).toEqual(await estilos(p, SEC_P, sec));
        const IT_P = { sel: MENU_P + ' > button', texto: /^Novo grupo$/ }, IT_S = { sel: MENU_S + ' > button', texto: /^Novo grupo$/ };
        const item = ['display', 'gap', 'padding-top', 'padding-left', 'border-radius', 'font-size', 'color', 'text-align', 'cursor', 'background-color'];
        expect(await estilos(s, IT_S, item)).toEqual(await estilos(p, IT_P, item));
        expect((await caixa(s, IT_S)).h).toBe((await caixa(p, IT_P)).h);
        const ico = ['width', 'color', 'text-align'];
        const icoDe = (pg, alvo) => medir(pg, alvo, `const cs = getComputedStyle(el.querySelector('i')), o = {}; arg.forEach((k) => { o[k] = cs.getPropertyValue(k); }); return o;`, ico);
        expect(await icoDe(s, IT_S)).toEqual(await icoDe(p, IT_P));
        const sep = ['height', 'margin-top', 'margin-left', 'background-color'];
        const SEP_P = MENU_P + ' > div[style*="height: 1px"]';
        expect(await estilos(s, MENU_S + ' > .bj-central-menu-sep', sep)).toEqual(await estilos(p, SEP_P, sep));

        // Chave desligada e ligada ("Não perturbe", desligado nos dois ao abrir); o painel continua aberto ao mudar.
        const chaveDe = (pg, sel, rot) => medir(pg, { sel: sel + ' > button', texto: rot }, `
            const k = [...el.children].find((n) => n.tagName === 'SPAN' && getComputedStyle(n).width === '30px');
            if (!k) { return 'sem chave'; }
            const b = k.firstElementChild, a = getComputedStyle(k), z = getComputedStyle(b);
            return { w: a.width, h: a.height, raio: a.borderRadius, fundo: a.backgroundColor, bola: [z.width, z.height, z.top, z.left, z.borderRadius, z.backgroundColor, z.boxShadow] };`);
        expect(await chaveDe(s, MENU_S, /^Não perturbe por 1 hora/)).toEqual(await chaveDe(p, MENU_P, /^Não perturbe por 1 hora/));
        await p.locator(MENU_P + ' > button', { hasText: 'Não perturbe por 1 hora' }).click();
        await s.locator(MENU_S + ' > button', { hasText: 'Não perturbe por 1 hora' }).click();
        await expect(s.locator(MENU_S)).toBeVisible();
        await expect(p.locator(MENU_P)).toBeVisible();
        const ligadaS = await chaveDe(s, MENU_S, /^Não perturbe até \d\d:\d\d/);
        expect(ligadaS).toEqual(await chaveDe(p, MENU_P, /^Não perturbe até \d\d:\d\d/));
        expect(ligadaS.fundo).toBe('rgb(10, 122, 173)');
        await expect(s.locator(S.janela + ' [data-central-cab-sub]')).toHaveText(/ · não perturbe$/);
        await expect(s.locator(MENU_S + ' > button', { hasText: /^Não perturbe até/ })).toHaveAttribute('aria-checked', 'true');

        const VAL = ['font-size', 'font-weight', 'color', 'white-space'];
        const valorDe = (pg, sel) => medir(pg, { sel: sel + ' > button', texto: /^Tamanho do texto/ }, `
            const v = el.lastElementChild, cs = getComputedStyle(v), o = { texto: v.textContent };
            arg.forEach((k) => { o[k] = cs.getPropertyValue(k); }); return o;`, VAL);
        expect(await valorDe(s, MENU_S)).toEqual(await valorDe(p, MENU_P));
        await evidencia(testInfo, 'menu-prototipo', p, { x: 940, y: 120, width: 426, height: 680 });
        await evidencia(testInfo, 'menu-sistema', s, { x: 940, y: 120, width: 426, height: 680 });

        // Só comandos com comportamento real, e todos existem no desenho.
        const rotulos = async (pg, sel) => (await pg.locator(sel + ' > button').allTextContents()).map((t) => t.replace(/(Normal|Grande)$/, '').trim());
        const doSistema = await rotulos(s, MENU_S), doDesenho = await rotulos(p, MENU_P);
        expect(doSistema).toEqual(['Novo grupo', 'Ver conversas arquivadas', expect.stringMatching(/^Não perturbe até \d\d:\d\d$/),
            'Som de notificações', 'Alertas visuais', 'Tamanho do texto', 'Abrir em janela separada']);
        doSistema.forEach((r) => expect(doDesenho.some((d) => d === r || (/^Não perturbe/.test(r) && /^Não perturbe/.test(d)))).toBe(true));
        // Seções: as do desenho que têm comando real ("Tamanho do texto" vai para Mensagens, ver o HANDOFF).
        expect(await s.locator(MENU_S + ' > .bj-central-menu-secao').allTextContents()).toEqual(['Conversas', 'Meu status', 'Notificações', 'Mensagens']);

        // Esc fecha e devolve o foco ao ⋮; nada foi gravado no servidor.
        await s.keyboard.press('Escape');
        await expect(s.locator(MENU_S)).toHaveCount(0);
        await expect(s.locator(S.janela + ' [data-central-mais]')).toBeFocused();
        expect(c.escritas).toEqual([]);
        await Promise.all([p.context().close(), s.context().close()]);
    });

    test('B1 confirmação dentro da janela e avisos no lugar de window.confirm/alert', async ({ browser }, testInfo) => {
        const c = cenario(0, [], { conversas: [GRUPO] });
        const [p, s] = await Promise.all([abrirPrototipo(browser), abrirSistema(browser, c)]);
        const dialogos = [];
        s.on('dialog', (d) => { dialogos.push(d.type()); d.dismiss(); });

        // Protótipo: botão direito no grupo de demonstração dele → "Excluir conversa" pede confirmação (ação
        // "Excluir", vermelha; L5771).
        await p.locator(P.lancador).click();
        await p.waitForTimeout(300);
        await p.locator(P.janela + ' button', { hasText: 'Equipe Pasta' }).first().click({ button: 'right' });
        await p.locator('button', { hasText: 'Excluir conversa' }).click();
        const CF_P = P.janela + ' [role="alertdialog"]';
        await expect(p.locator(CF_P)).toBeVisible();

        // Sistema: Informações do grupo fictício → Remover a colega.
        await s.locator(S.lancador).click();
        await s.locator(S.janela + ' .bj-central-linha', { hasText: GRUPO.nome }).click();
        await s.locator(S.janela + ' [data-central-info]').click();
        await s.locator(S.janela + ' .bj-central-info-pessoa', { hasText: 'Colega Fictícia' }).getByRole('button', { name: 'Remover' }).click();
        const CF_S = S.janela + ' [role="alertdialog"]';
        await expect(s.locator(CF_S)).toBeVisible();
        await expect(s.locator(CF_S)).toContainText('Remover Colega Fictícia do grupo');

        const fundo = ['position', 'z-index', 'display', 'align-items', 'justify-content', 'padding-top', 'background-color'];
        const pai = (pg, sel) => medir(pg, sel, `const cs = getComputedStyle(el.parentElement), o = {}; arg.forEach((k) => { o[k] = cs.getPropertyValue(k); }); return o;`, fundo);
        expect(await pai(s, CF_S)).toEqual(await pai(p, CF_P));
        const caixaCf = ['max-width', 'padding-top', 'padding-left', 'border-radius', 'background-color', 'box-shadow', 'display', 'flex-direction', 'row-gap'];
        expect(await estilos(s, CF_S, caixaCf)).toEqual(await estilos(p, CF_P, caixaCf));
        expect((await caixa(s, CF_S)).w).toBe((await caixa(p, CF_P)).w);
        const txt = ['font-size', 'line-height', 'color'];
        expect(await estilos(s, CF_S + ' > span', txt)).toEqual(await estilos(p, CF_P + ' > span', txt));
        const btn = ['height', 'padding-left', 'border-top-width', 'border-radius', 'background-color', 'color', 'font-size', 'font-weight'];
        const botaoDe = (pg, sel, rot) => estilos(pg, { sel: sel + ' button', texto: rot }, btn);
        expect(await botaoDe(s, CF_S, /^Cancelar$/)).toEqual(await botaoDe(p, CF_P, /^Cancelar$/));
        expect(await botaoDe(s, CF_S, /^Remover$/)).toEqual(await botaoDe(p, CF_P, /^Excluir$/));   // vermelha nos dois
        await evidencia(testInfo, 'confirmar-prototipo', p, { x: 940, y: 120, width: 426, height: 680 });
        await evidencia(testInfo, 'confirmar-sistema', s, { x: 940, y: 120, width: 426, height: 680 });

        // Cancelar não grava; Esc também fecha; a ação confirma e chama o servidor (simulado).
        await s.locator(CF_S).getByRole('button', { name: 'Cancelar' }).click();
        await expect(s.locator(CF_S)).toHaveCount(0);
        expect(c.escritas).toEqual([]);
        await s.locator(S.janela + ' .bj-central-info-pessoa', { hasText: 'Colega Fictícia' }).getByRole('button', { name: 'Remover' }).click();
        await s.keyboard.press('Escape');
        await expect(s.locator(CF_S)).toHaveCount(0);
        expect(c.escritas).toEqual([]);
        await s.getByRole('button', { name: 'Sair do grupo' }).click();
        await expect(s.locator(CF_S)).toContainText('Sair do grupo "' + GRUPO.nome + '"?');
        c.recusar = 'Recusado pelo teste.';
        await s.locator(CF_S).getByRole('button', { name: 'Sair' }).click();
        expect(c.escritas).toEqual(['POST /central/conversas/77/sair']);
        // O erro do servidor sai como o aviso escuro do desenho, acima do lançador (não window.alert).
        await expect(s.locator('.bj-flutuante-aviso')).toHaveText('Recusado pelo teste.');
        expect(dialogos).toEqual([]);
        await Promise.all([p.context().close(), s.context().close()]);
    });
    // ── B2 Destacar (16/10): Document Picture-in-Picture, pop-up, mesmo nó, voltar ─────────────────────────────────
    // Guarda a janela que o navegador devolve (PiP ou pop-up) e os tamanhos pedidos, para o teste ler de fora.
    const ESPIAO_DESTACAR = () => {
        const api = window.documentPictureInPicture;
        if (api && api.requestWindow) {
            const original = api.requestWindow.bind(api);
            api.requestWindow = (o) => { window.__pedidoPip = o; return original(o).then((w) => { window.__destacada = w; return w; }); };
        }
    };

    test('B2 botão Destacar: mesma posição, tamanho, ícone e dica do desenho, antes de Minimizar', async ({ browser }, testInfo) => {
        const c = cenario();
        const [p, s] = await Promise.all([abrirPrototipo(browser), abrirSistema(browser, c)]);
        await p.locator(P.lancador).click();
        await s.locator(S.lancador).click();
        await p.waitForTimeout(300);
        await s.waitForTimeout(300);
        const DP = P.janela + ' button[aria-label^="Destacar"]', DS = S.janela + ' [data-flutuante-destacar]';
        expect(await caixa(s, DS)).toEqual(await caixa(p, DP));
        const props = ['width', 'height', 'font-size', 'border-radius', 'color', 'background-color'];
        expect(await estilos(s, DS, props)).toEqual(await estilos(p, DP, props));
        const icone = (pg, sel) => medir(pg, sel, `return el.querySelector('i').className.split(' ').filter((k) => k.startsWith('bi-'));`);
        expect(await icone(s, DS)).toEqual(['bi-box-arrow-up-right']);
        expect(await icone(p, DP)).toEqual(['bi-box-arrow-up-right']);
        // Ordem do desenho: Destacar, Minimizar, Maximizar, Fechar.
        const ordem = await s.locator(S.janela + ' .bj-central-janela-acoes > button').evaluateAll((bs) => bs.map((b) => b.getAttribute('aria-label')));
        expect(ordem).toEqual(['Destacar', 'Minimizar', 'Maximizar', 'Fechar']);
        await expect(s.locator(DS)).toHaveAttribute('title', 'Destacar: levar o chat para qualquer lugar da tela');
        expect(c.escritas).toEqual([]);
        await Promise.all([p.context().close(), s.context().close()]);
    });

    test('B2 destacar (Picture-in-Picture): o MESMO nó vai, conversa e rascunho ficam, polling não duplica, volta ao fechar', async ({ browser }, testInfo) => {
        test.setTimeout(180000);
        const c = cenario(0, [], { conversas: [GRUPO] });
        // Protótipo: pede a mesma janela (tamanho) que o sistema.
        const p = await abrirPrototipo(browser);
        await p.evaluate(ESPIAO_DESTACAR);
        await p.locator(P.lancador).click();
        await p.waitForTimeout(300);
        await p.locator(P.janela + ' button[aria-label^="Destacar"]').click();
        await expect.poll(() => p.evaluate(() => !!window.__destacada)).toBe(true);
        const pedidoP = await p.evaluate(() => window.__pedidoPip);

        const s = await abrirSistema(browser, c, { porScript: true });
        await s.evaluate(ESPIAO_DESTACAR);
        const novidades = { get length() { return c.leituras || 0; } };   // consultas a /central/novidades
        await s.locator(S.lancador).click();
        await s.locator(S.janela + ' .bj-central-linha', { hasText: GRUPO.nome }).click();
        await s.locator(S.janela + ' [data-central-campo]').fill('rascunho que não pode se perder');
        await s.evaluate((sel) => { document.querySelector(sel).__marcaDoTeste = 'mesmo nó'; }, S.janela);

        await s.locator(S.janela + ' [data-flutuante-destacar]').click();
        await expect.poll(() => s.evaluate(() => !!window.__destacada)).toBe(true);
        expect(await s.evaluate(() => window.__pedidoPip)).toEqual(pedidoP);   // L6299: max(420, 440) × max(560, 660)
        expect(pedidoP).toEqual({ width: 440, height: 660 });

        const fora = await s.evaluate((sel) => {
            const w = window.__destacada, j = w.document.querySelector(sel), cab = j.querySelector('.bj-central-cab');
            const r = j.getBoundingClientRect(), vis = (q) => { const b = j.querySelector(q); return !!b && !b.hidden && b.getBoundingClientRect().width > 0; };
            return {
                naPagina: !!document.querySelector(sel), mesmoNo: j.__marcaDoTeste, modo: j.dataset.modo,
                caixa: [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height)], viewport: [w.innerWidth, w.innerHeight],
                titulo: j.querySelector('[data-central-cab-titulo]').textContent, rascunho: j.querySelector('[data-central-campo]').value,
                minimizar: vis('[data-flutuante-minimizar]'), maximizar: vis('[data-flutuante-maximizar]'),
                destacar: j.querySelector('[data-flutuante-destacar]').getAttribute('aria-label'),
                icone: j.querySelector('[data-flutuante-destacar] i').className,
                cabFundo: w.getComputedStyle(cab).backgroundColor, folhas: w.document.styleSheets.length,
                aviso: (w.document.querySelector('.bj-flutuante-aviso') || {}).textContent || null,
                lancador: document.querySelector('[data-flutuante-lancador]').dataset.estado,
            };
        }, S.janela);
        expect(fora).toMatchObject({
            naPagina: false, mesmoNo: 'mesmo nó', modo: 'separada', titulo: GRUPO.nome, rascunho: 'rascunho que não pode se perder',
            minimizar: false, maximizar: false, destacar: 'Voltar para dentro do BlueJus', icone: 'bi bi-box-arrow-in-down-left',
            lancador: 'aberto',
            aviso: 'Chat em janela flutuante: arraste para qualquer lugar da tela. Ela fica por cima mesmo com o BlueJus minimizado.',
        });
        expect(fora.caixa).toEqual([0, 0, fora.viewport[0], fora.viewport[1]]);
        // As folhas copiadas carregam na janela nova (L6303, como no protótipo): o estilo chega logo depois.
        await expect.poll(() => s.evaluate(() => window.__destacada.getComputedStyle(window.__destacada.document.querySelector('.bj-central-cab')).backgroundColor), { timeout: 5000 })
            .toBe('rgb(10, 122, 173)');
        expect(fora.folhas).toBeGreaterThan(3);

        // Polling: um só, no ritmo da janela aberta (5 s). Em 11 s: 2 ou 3 consultas (duplicado daria 4 ou mais).
        const antes = novidades.length;
        await s.waitForTimeout(11000);
        expect(novidades.length - antes).toBeGreaterThanOrEqual(2);
        expect(novidades.length - antes).toBeLessThanOrEqual(3);

        // Fechar a janela de fora devolve o nó à página, aberto, com a conversa e o rascunho.
        await s.evaluate(() => window.__destacada.close());
        await expect(s.locator(S.janela)).toBeVisible();
        const volta = await s.evaluate((sel) => {
            const j = document.querySelector(sel);
            return { mesmoNo: j.__marcaDoTeste, modo: j.dataset.modo, irmao: j.nextElementSibling && j.nextElementSibling.matches('[data-central-flutuante]'),
                rascunho: j.querySelector('[data-central-campo]').value, titulo: j.querySelector('[data-central-cab-titulo]').textContent,
                minimizar: !j.querySelector('[data-flutuante-minimizar]').hidden, destacar: j.querySelector('[data-flutuante-destacar]').getAttribute('aria-label') };
        }, S.janela);
        expect(volta).toEqual({ mesmoNo: 'mesmo nó', modo: 'normal', irmao: true, rascunho: 'rascunho que não pode se perder', titulo: GRUPO.nome, minimizar: true, destacar: 'Destacar' });
        expect(await caixa(s, S.janela)).toEqual({ x: 968, y: 136, w: 380, h: 580 });
        const depois = novidades.length;
        await s.waitForTimeout(6000);
        expect(novidades.length - depois).toBeGreaterThanOrEqual(1);   // o polling seguiu na página
        expect(novidades.length - depois).toBeLessThanOrEqual(2);
        await evidencia(testInfo, 'destacar-voltou-sistema', s, { x: 940, y: 120, width: 426, height: 680 });
        expect(c.escritas).toEqual([]);
        expect(c.vazou).toEqual([]);   // nada de /central/* chegou ao servidor de verdade
        await Promise.all([p.context().close(), s.context().close()]);
    });

    test('B2 Fechar dentro da janela destacada: fecha a de fora e devolve a Central ao lançador, fechada', async ({ browser }) => {
        const c = cenario();
        const s = await abrirSistema(browser, c);
        await s.evaluate(ESPIAO_DESTACAR);
        await s.locator(S.lancador).click();
        await s.locator(S.janela + ' [data-flutuante-destacar]').click();
        await expect.poll(() => s.evaluate(() => !!window.__destacada)).toBe(true);
        await s.evaluate((sel) => window.__destacada.document.querySelector(sel + ' [data-flutuante-fechar]').click(), S.janela);
        expect(await s.evaluate(() => window.__destacada.closed)).toBe(true);
        await expect(s.locator(S.janela)).toBeHidden();
        await expect(s.locator(S.lancador)).toHaveAttribute('data-estado', 'calmo');
        // Reabrir pelo ícone: a janela volta para dentro do BlueJus, no modo normal.
        await s.locator(S.lancador).click();
        await expect(s.locator(S.janela)).toHaveAttribute('data-modo', 'normal');
        expect(c.escritas).toEqual([]);
        await s.context().close();
    });

    test('B2 sem Picture-in-Picture: pop-up próprio; bloqueado, o aviso do desenho', async ({ browser }) => {
        const c = cenario(0, [], { conversas: [GRUPO] });
        const s = await abrirSistema(browser, c);
        await s.evaluate(() => { Object.defineProperty(window, 'documentPictureInPicture', { value: undefined, configurable: true }); });
        await s.locator(S.lancador).click();
        await s.locator(S.janela + ' .bj-central-linha', { hasText: GRUPO.nome }).click();
        await s.locator(S.janela + ' [data-central-campo]').fill('rascunho no pop-up');

        // Pelo item do ⋮ ("Abrir em janela separada"), o mesmo comando do botão.
        await s.locator(S.janela + ' [data-central-mais]').click();
        const [popup] = await Promise.all([
            s.waitForEvent('popup'),
            s.locator(S.janela + ' .bj-central-menu > button', { hasText: 'Abrir em janela separada' }).click(),
        ]);
        await expect(popup.locator(S.janela)).toBeVisible();
        await expect(popup).toHaveTitle('BlueJus · Central de Comunicação');
        await expect(popup.locator(S.janela + ' [data-central-campo]')).toHaveValue('rascunho no pop-up');
        await expect(popup.locator('.bj-flutuante-aviso')).toHaveText('Chat em janela própria: arraste para qualquer lugar ou para outro monitor.');
        await expect(s.locator(S.janela)).toHaveCount(0);
        // "Voltar para dentro do BlueJus" fecha o pop-up e traz a Central de volta.
        await Promise.all([popup.waitForEvent('close'), popup.locator(S.janela + ' [data-flutuante-destacar]').click()]);
        await expect(s.locator(S.janela)).toBeVisible();
        await expect(s.locator(S.janela + ' [data-central-campo]')).toHaveValue('rascunho no pop-up');

        // Pop-up bloqueado: aviso, nada sai do lugar.
        await s.evaluate(() => { window.open = () => null; });
        await s.locator(S.janela + ' [data-flutuante-destacar]').click();
        await expect(s.locator('.bj-flutuante-aviso')).toHaveText('O navegador bloqueou a janela. Permita pop-ups para este site e tente de novo.');
        await expect(s.locator(S.janela)).toBeVisible();
        expect(c.escritas).toEqual([]);
        await s.context().close();
    });
});
