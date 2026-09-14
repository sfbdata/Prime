// @ts-check
//
// Smoke automatizado da frente `ponto-batida-nao-trava`.
//
// POR QUE ELE EXISTE. Tudo que a frente entrega vive numa tela que o PHPUnit não alcança: estado do
// botão, posição de GPS, aviso que fica, bloqueio por raio. A suíte prova ARRANJO no fonte — que a
// função existe, que a comparação tem a forma certa. Aqui se prova COMPORTAMENTO, com o navegador
// de verdade, GPS falseado e rede cortada.
//
// 🔑 NADA É GRAVADO NO BANCO. Todo teste que aperta "Bater Ponto" intercepta `POST /ponto/batida`
// antes de sair do navegador. A batida nunca chega ao servidor, então o dev não ganha lixo e o
// resultado não depende de estado acumulado.
//
// Referências da tela (`app/templates/ponto/index.html.twig`):
//   #btn-bater-ponto · #tipo-registro · #gps-status · #batida-aviso · #btn-atualizar-pagina
//
// Spec: `docs/specs/ponto-batida-nao-se-perde-no-navegador.md`.

const { test, expect } = require('@playwright/test');
const path = require('path');

// Sede real do escritório ativo no banco da tela (QND 14). A cerca é de 100 m.
const SEDE = { latitude: -15.81100086, longitude: -48.06479935 };
const LONGE = { latitude: -15.79500000, longitude: -48.04000000 }; // ~3 km da sede

const ESTADO_SESSAO = path.join(__dirname, '../storage/sessao-ponto.json');

/** Loga UMA vez e guarda a sessão: o limitador do login é de 5 tentativas por 15 minutos. */
test.beforeAll(async ({ browser, baseURL }) => {
    const contexto = await browser.newContext();
    const pagina = await contexto.newPage();

    await pagina.goto('/login');
    if (await pagina.locator('text=Muitas tentativas').isVisible().catch(() => false)) {
        throw new Error(
            'Limitador de login ativo. Limpe com: docker exec jusprime_php_dev bash -c '
            + '"cd /var/www/app && php bin/console cache:pool:clear cache.rate_limiter"'
        );
    }

    await pagina.fill('input[name="email"]', process.env.SMOKE_EMAIL ?? '');
    await pagina.fill('input[name="password"]', process.env.SMOKE_SENHA ?? '');

    // 🪤 `click()` no botão NÃO envia este formulário (o `<svg>` dentro do botão e o
    // `aria-busy`/`pointer-events` do próprio script da tela de login atrapalham). `requestSubmit`
    // dispara o envio pelo formulário, respeitando validação nativa e o handler existente.
    await pagina.evaluate(() => document.getElementById('loginForm').requestSubmit());
    await pagina.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 20000 });

    // Quem tem vínculo com mais de um escritório cai na escolha antes de chegar ao sistema.
    if (pagina.url().includes('/escritorio/selecionar')) {
        await pagina.locator('form[action*="selecionar"] button[type="submit"]').first().click();
        await pagina.waitForURL((url) => !url.pathname.includes('/escritorio/selecionar'), { timeout: 20000 });
    }

    await contexto.storageState({ path: ESTADO_SESSAO });
    await contexto.close();
});

/**
 * Abre a tela do ponto com uma posição de GPS falseada.
 *
 * `aoIniciar` roda ANTES de qualquer script da página: é onde se stuba a API de geolocalização.
 */
async function abrirPonto(browser, posicao, { aoIniciar = null, offline = false } = {}) {
    const contexto = await browser.newContext({
        storageState: ESTADO_SESSAO,
        permissions: ['geolocation'],
        geolocation: posicao,
        locale: 'pt-BR',
    });

    const pagina = await contexto.newPage();
    if (aoIniciar) {
        await pagina.addInitScript(aoIniciar);
    }

    // 🪤 O lembrete "hora de bater o ponto" abre como modal `static` POR CIMA do botão e
    // intercepta o clique. Ele é disparado por este endpoint, num laço — esconder o modal uma vez
    // não resolve, porque ele volta. Silenciar a fonte é o que dá teste estável, e é controle de
    // ambiente, não mudança de comportamento: o que se está testando é a batida, não o lembrete.
    await pagina.route('**/ponto/alerta-horario', (rota) => rota.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ alertar: false }),
    }));

    // A batida NUNCA chega ao servidor. Cada teste decide o que responder.
    const batidas = [];
    await pagina.route('**/ponto/batida', async (rota) => {
        batidas.push(rota.request().postDataJSON());
        if (offline) {
            await rota.abort('internetdisconnected');
            return;
        }
        await rota.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ success: true, message: 'Ponto registrado com sucesso!', data: {} }),
        });
    });

    await pagina.goto('/ponto/');
    await expect(pagina.locator('#btn-bater-ponto')).toBeVisible();

    // Fecha o que por acaso já tenha aberto antes de a rota acima entrar em vigor.
    await pagina.evaluate(() => {
        const alerta = document.getElementById('modalAlertaPonto');
        if (alerta && window.bootstrap) {
            window.bootstrap.Modal.getOrCreateInstance(alerta).hide();
        }
        document.querySelectorAll('.modal-backdrop').forEach((f) => f.remove());
    });
    await expect(pagina.locator('#modalAlertaPonto')).toBeHidden();

    return { contexto, pagina, batidas };
}

test.describe('Cerca na tela: o botão bloqueia fora do raio, mas nunca por imprecisão', () => {
    test('dentro do raio, o botão libera depois de escolher o tipo', async ({ browser }) => {
        const { contexto, pagina } = await abrirPonto(browser, { ...SEDE, accuracy: 10 });

        await expect(pagina.locator('#btn-bater-ponto')).toBeDisabled();
        await pagina.selectOption('#tipo-registro', 'entrada');
        await expect(pagina.locator('#btn-bater-ponto')).toBeEnabled();
        await expect(pagina.locator('#gps-status')).toContainText('precisão');

        await contexto.close();
    });

    test('fora do raio, o botão fica bloqueado, a tela diz a distância e oferece atualizar', async ({ browser }) => {
        const { contexto, pagina } = await abrirPonto(browser, { ...LONGE, accuracy: 10 });

        await pagina.selectOption('#tipo-registro', 'entrada');
        await expect(pagina.locator('#btn-bater-ponto')).toBeDisabled();

        const faixa = pagina.locator('#gps-status');
        await expect(faixa).toContainText('fora da área permitida');
        await expect(faixa).toContainText(/\d+m de/);
        await expect(faixa).toContainText('peça ao gestor a liberação do dia');
        // A liberação é decidida no servidor e só chega na carga: sem esta saída, quem consegue a
        // liberação com a página aberta continuaria bloqueado.
        await expect(pagina.locator('#btn-atualizar-pagina')).toBeVisible();

        await contexto.close();
    });

    test('🔑 GPS impreciso NÃO bloqueia: fora do raio mas dentro da margem de erro libera', async ({ browser }) => {
        // 150 m da sede com 200 m de incerteza: o aparelho não sabe dizer se está dentro. Bloquear
        // aqui transformaria imprecisão de GPS em falta. Medido em prod: p90 da precisão = 99 m,
        // contra raio de 100 m.
        const quaseNaSede = { latitude: SEDE.latitude + 0.00135, longitude: SEDE.longitude };
        const { contexto, pagina } = await abrirPonto(browser, { ...quaseNaSede, accuracy: 200 });

        await pagina.selectOption('#tipo-registro', 'entrada');
        await expect(pagina.locator('#btn-bater-ponto')).toBeEnabled();
        await expect(pagina.locator('#gps-status')).not.toContainText('fora da área permitida');

        await contexto.close();
    });

    test('🔑 a posição se atualiza sozinha: quem chega ao escritório destrava sem recarregar', async ({ browser }) => {
        // Foi o achado bloqueante da revisão: a versão anterior decidia com a leitura da CARGA, e
        // quem abrisse a tela no caminho ficava travado mesmo depois de chegar.
        const { contexto, pagina } = await abrirPonto(browser, { ...LONGE, accuracy: 10 });

        await pagina.selectOption('#tipo-registro', 'entrada');
        await expect(pagina.locator('#btn-bater-ponto')).toBeDisabled();

        await contexto.setGeolocation({ ...SEDE, accuracy: 10 });

        // Sem recarregar: o `watchPosition` reavalia e o botão libera.
        await expect(pagina.locator('#btn-bater-ponto')).toBeEnabled({ timeout: 20000 });
        await expect(pagina.locator('#gps-status')).not.toContainText('fora da área permitida');

        await contexto.close();
    });
});

test.describe('A batida não se perde e a falha nunca passa calada', () => {
    test('🔑 GPS que nunca responde não impede o envio', async ({ browser }) => {
        // O defeito que originou a frente. `getCurrentPosition` é stubado para NUNCA chamar
        // callback — é o que o Android faz com o documento oculto. `watchPosition` segue entregando,
        // então a posição existe; o que se prova é que o envio acontece mesmo assim.
        const travarLeituraPontual = () => {
            const original = navigator.geolocation.watchPosition.bind(navigator.geolocation);
            navigator.geolocation.getCurrentPosition = () => {};
            navigator.geolocation.watchPosition = original;
        };

        const { contexto, pagina, batidas } = await abrirPonto(
            browser,
            { ...SEDE, accuracy: 10 },
            { aoIniciar: travarLeituraPontual }
        );

        await pagina.selectOption('#tipo-registro', 'entrada');
        await expect(pagina.locator('#btn-bater-ponto')).toBeEnabled({ timeout: 20000 });
        await pagina.click('#btn-bater-ponto');

        // O prazo próprio do leitor é de 5 s. Sem ele, o envio nunca sairia.
        await expect.poll(() => batidas.length, { timeout: 20000 }).toBeGreaterThan(0);
        expect(batidas[0].tipo).toBe('entrada');
        expect(typeof batidas[0].latitude).toBe('number');

        await contexto.close();
    });

    test('🔑 sem internet: o aviso fica, diz que não dá para saber, e NÃO oferece atualizar', async ({ browser }) => {
        const fingirSemRede = () => {
            Object.defineProperty(navigator, 'onLine', { get: () => false, configurable: true });
        };

        const { contexto, pagina } = await abrirPonto(
            browser,
            { ...SEDE, accuracy: 10 },
            { aoIniciar: fingirSemRede, offline: true }
        );

        await pagina.selectOption('#tipo-registro', 'entrada');
        await pagina.click('#btn-bater-ponto');

        const aviso = pagina.locator('#batida-aviso');
        await expect(aviso).toBeVisible({ timeout: 20000 });
        await expect(aviso).toContainText('sem internet');
        await expect(aviso).toContainText('NÃO feche esta tela');

        // 🔴 Recarregar sem rede entrega a página de erro do navegador e apaga o aviso e a lista de
        // batidas de hoje, que é a única prova de que a batida pode ter entrado.
        await expect(pagina.locator('#btn-atualizar-pagina')).toBeHidden();
        // O botão volta ao normal: nunca fica preso em "Registrando…".
        await expect(pagina.locator('#btn-bater-ponto')).toContainText('Bater Ponto');

        await contexto.close();
    });

    test('🔑 falha com rede: o aviso não afirma que a batida não entrou', async ({ browser }) => {
        const { contexto, pagina } = await abrirPonto(
            browser,
            { ...SEDE, accuracy: 10 },
            { offline: true }
        );

        await pagina.selectOption('#tipo-registro', 'entrada');
        await pagina.click('#btn-bater-ponto');

        const aviso = pagina.locator('#batida-aviso');
        await expect(aviso).toBeVisible({ timeout: 20000 });
        // A requisição pode ter chegado e gravado antes de a resposta se perder. Afirmar o contrário
        // e mandar repetir é o que produz batida duplicada.
        await expect(aviso).toContainText('PODE ter sido registrada');
        await expect(aviso).toContainText('atualize a página');
        await expect(pagina.locator('#btn-atualizar-pagina')).toBeVisible();
        await expect(pagina.locator('#btn-bater-ponto')).toContainText('Bater Ponto');

        await contexto.close();
    });

    test('sucesso mostra a confirmação e mantém o botão travado até recarregar', async ({ browser }) => {
        const { contexto, pagina } = await abrirPonto(browser, { ...SEDE, accuracy: 10 });

        await pagina.selectOption('#tipo-registro', 'entrada');
        await pagina.click('#btn-bater-ponto');

        await expect(pagina.locator('#batida-aviso')).toContainText('sucesso', { timeout: 20000 });
        // Trocar o tipo durante o 1,5 s até o recarregamento não pode reabilitar o botão: era o
        // caminho de batida duplicada que a revisão achou.
        await pagina.selectOption('#tipo-registro', 'saida');
        await expect(pagina.locator('#btn-bater-ponto')).toBeDisabled();

        await contexto.close();
    });
});

test.describe('A tela mostra as batidas do dia', () => {
    test('os quatro tipos aparecem no card do botão, com estado de cada um', async ({ browser }) => {
        const { contexto, pagina } = await abrirPonto(browser, { ...SEDE, accuracy: 10 });

        const bloco = pagina.locator('#batidas-de-hoje');
        await expect(bloco).toBeVisible();
        await expect(bloco).toContainText('Suas batidas de hoje');

        for (const tipo of ['entrada', 'repouso', 'retorno', 'saida']) {
            await expect(bloco.locator(`.batida-de-hoje[data-tipo="${tipo}"]`)).toHaveCount(1);
        }

        // Cada linha diz o horário ou "ainda não registrada" — nunca um espaço em branco que a
        // pessoa tenha de interpretar.
        const linhas = bloco.locator('.batida-de-hoje');
        for (let i = 0; i < 4; i++) {
            await expect(linhas.nth(i)).toContainText(/(\d{2}:\d{2}:\d{2}|ainda não registrada)/);
        }

        await contexto.close();
    });
});
