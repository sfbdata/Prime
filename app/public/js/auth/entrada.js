/*
 * Tela de entrada (desenho "10 - Login - VS 25.10.26"): todo o comportamento da página.
 *
 * Reproduz o componente do desenho (DCLogic) sobre o HTML que o Twig serve. Os dados chegam por
 * data-attributes no #lgRaiz; as escritas são POST JSON para os endpoints da spec
 * docs/specs/login-desenho-10.md. Nada aqui decide segurança: o servidor revalida tudo.
 *
 * Os textos vêm do catálogo do idioma (entrada.js.*; a régua de força, auth.js.forca.*), entregues pelo
 * login.html.twig com o bj-traducao.js antes deste arquivo.
 */
(() => {
    'use strict';

    const raiz = document.getElementById('lgRaiz');
    if (!raiz) {
        return;
    }

    const $ = (id) => document.getElementById(id);
    const REGEX_EMAIL = /^\S+@\S+\.\S+$/;
    const ERRO_GENERICO = window.BJ_T.t('entrada.js.erro_generico');
    const ehMaster = raiz.dataset.master === '1';
    const tokens = {
        senha: raiz.dataset.tokenSenha || '',
        cadastro: raiz.dataset.tokenCadastro || '',
        reenvio: raiz.dataset.tokenReenvio || '',
        suporte: raiz.dataset.tokenSuporte || '',
        ajuste: raiz.dataset.tokenAjuste || '',
    };

    const mostrar = (el, sim) => { if (el) { el.hidden = !sim; } };

    /** POST JSON; devolve {status, dados}. Erro de rede vira status 0. */
    const postar = async (url, corpo) => {
        try {
            const resposta = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(corpo),
            });
            let dados = {};
            try { dados = await resposta.json(); } catch (e) { dados = {}; }

            return { status: resposta.status, dados: dados || {} };
        } catch (e) {
            return { status: 0, dados: {} };
        }
    };
    const mensagemDeErro = (r) => (r.dados && typeof r.dados.erro === 'string' && r.dados.erro !== '' ? r.dados.erro : ERRO_GENERICO);

    /* ---------------------------------------------------------- entrar ----- */
    const form = $('lgForm');
    const email = $('lgEmail');
    const senha = $('lgSenha');
    const erro = $('lgErro');
    const caps = $('lgCaps');
    const olho = $('lgOlho');

    const mostrarErro = (texto) => { erro.textContent = texto; mostrar(erro, texto !== ''); };
    email.addEventListener('input', () => mostrarErro(''));
    senha.addEventListener('input', () => mostrarErro(''));

    // Entrar com o Google: leva o "Manter conectado" marcado (o servidor só aceita com o token do próprio botão).
    const google = $('lgGoogle');
    if (google) {
        google.addEventListener('click', () => {
            const manter = $('lgManter');
            const url = new URL(google.href, location.href);
            url.searchParams.delete('manter');
            if (manter && manter.checked) { url.searchParams.set('manter', '1'); }
            google.href = url.pathname + url.search;
        });
    }

    let saindo = false;
    form.addEventListener('submit', (e) => {
        if (!REGEX_EMAIL.test(email.value.trim())) {
            e.preventDefault();
            mostrarErro(window.BJ_T.t('entrada.js.email_invalido'));
            email.focus();

            return;
        }
        if (senha.value === '') {
            e.preventDefault();
            mostrarErro(window.BJ_T.t('entrada.js.informe_a_senha'));
            senha.focus();

            return;
        }
        // Desenho: o cartão sai (lgSai, .35s) antes de a página do véu entrar. O envio segue o mesmo formulário.
        if (saindo) { e.preventDefault(); return; }
        saindo = true;
        e.preventDefault();
        $('lgCartao').classList.add('lg-sai');
        setTimeout(() => { form.submit(); }, 350);
    });
    // Voltar do navegador devolve a página do cache com o cartão já "saído": reabre.
    window.addEventListener('pageshow', (ev) => {
        if (ev.persisted) { saindo = false; $('lgCartao').classList.remove('lg-sai'); }
    });

    olho.addEventListener('click', () => {
        const ver = senha.type === 'password';
        senha.type = ver ? 'text' : 'password';
        const titulo = ver ? window.BJ_T.t('entrada.js.ocultar_senha') : window.BJ_T.t('entrada.js.mostrar_senha');
        olho.title = titulo;
        olho.setAttribute('aria-label', titulo);
        olho.setAttribute('aria-pressed', String(ver));
        olho.querySelector('i').className = 'bi ' + (ver ? 'bi-eye-slash' : 'bi-eye');
    });

    /* Caps Lock em keydown E keyup (no keyup só, o aviso chegava uma tecla atrasado). */
    const verCaps = (e) => { if (e.getModifierState) { mostrar(caps, e.getModifierState('CapsLock')); } };
    senha.addEventListener('keydown', verCaps);
    senha.addEventListener('keyup', verCaps);
    senha.addEventListener('blur', () => mostrar(caps, false));

    /* Voltar pelo histórico restaura o DOM do cache: limpa a senha visível. */
    window.addEventListener('pageshow', (e) => { if (e.persisted && senha.type === 'text') { olho.click(); } });

    /* ------------------------------------------ segurança e privacidade ---- */
    const seg = $('lgSeg');
    const segAbrir = () => { mostrar(seg, true); const x = seg.querySelector('.lg-seg__x'); if (x) { x.focus(); } };
    const segFechar = () => mostrar(seg, false);
    document.querySelectorAll('[data-seg-abrir]').forEach((b) => b.addEventListener('click', segAbrir));
    seg.querySelectorAll('[data-seg-fechar]').forEach((b) => b.addEventListener('click', segFechar));

    /* ------------------------------------------------------- força ---------- */
    /* Régua da spec (igual ao ForcaDaSenha do servidor). */
    const FORCA = [
        ['', '#c9d3da', '0%'],
        [window.BJ_T.t('auth.js.forca.fraca'), '#c0392b', '25%'],
        [window.BJ_T.t('auth.js.forca.media'), '#c76a12', '50%'],
        [window.BJ_T.t('auth.js.forca.boa'), '#0a63c9', '75%'],
        [window.BJ_T.t('auth.js.forca.forte'), '#1e7b45', '100%'],
    ];
    const tamanho = (p) => [...p].length; // em caracteres, como o mb_strlen do servidor (emoji conta 1)
    const pontos = (p) => (!p ? 0 : (tamanho(p) >= 8) + (/[A-Z]/.test(p) && /[a-z]/.test(p)) + /\d/.test(p) + /[^A-Za-z0-9_]/.test(p));
    const senhaAceita = (p) => tamanho(p) >= 8 && pontos(p) >= 2;
    document.querySelectorAll('[data-forca]').forEach((caixa) => {
        const campo = $(caixa.dataset.forca);
        const pintar = () => {
            const [txt, cor, largura] = FORCA[pontos(campo.value)];
            caixa.style.color = cor;
            const barra = caixa.querySelector('.lg-forca__barra');
            barra.style.width = largura;
            barra.style.background = cor;
            caixa.querySelector('.lg-forca__txt').textContent = txt;
        };
        campo.addEventListener('input', pintar);
        caixa.pintar = pintar;
        pintar();
    });
    const repintarForca = () => document.querySelectorAll('[data-forca]').forEach((c) => c.pintar && c.pintar());

    /* ----------------------------------------- modal: senha e cadastro ----- */
    const md = $('lgMd');
    const mdForm = $('lgMdForm');
    const mdErro = $('lgMdErro');
    const mdAcao = $('lgMdAcao');
    const PASSOS = {
        rs1: [window.BJ_T.t('entrada.js.passo.recuperar'), 'bi-key', window.BJ_T.t('entrada.js.passo.enviar_codigo'), 'bi-send'],
        rs2: [window.BJ_T.t('entrada.js.passo.criar_nova'), 'bi-shield-lock', window.BJ_T.t('entrada.js.passo.salvar_nova'), 'bi-check2'],
        rs3: [window.BJ_T.t('entrada.js.passo.pronto'), 'bi-check2-circle', window.BJ_T.t('entrada.js.passo.voltar'), 'bi-box-arrow-in-right'],
        cad: [window.BJ_T.t('entrada.js.passo.cadastro'), 'bi-building-add', window.BJ_T.t('entrada.js.passo.criar_gratis'), 'bi-rocket-takeoff'],
    };
    /* "Confirme seu e-mail" depois do cadastro: os textos vêm do servidor (ContaAConfirmar), num lugar só. */
    const passoConf = md.querySelector('.lg-md__passo[data-passo="conf"]');
    if (passoConf) {
        PASSOS.conf = [passoConf.dataset.titulo || '', 'bi-envelope-check', passoConf.dataset.botao || '', 'bi-send'];
    }
    let passo = null;
    let ocupado = false;

    const mdErroTexto = (texto) => { $('lgMdErroTxt').textContent = texto; mostrar(mdErro, texto !== ''); };
    const irPara = (novo) => {
        passo = novo;
        const [titulo, icone, botao, iconeBotao] = PASSOS[novo];
        $('lgMdTitTxt').textContent = titulo;
        $('lgMdIc').className = 'bi ' + icone;
        /* Desenho 10 VS 25.10.26: o título do "Criar conta e escritório" vai sem ícone (mdTemIc). */
        mostrar($('lgMdIc'), novo !== 'cad');
        $('lgMdBtnTxt').textContent = botao;
        $('lgMdBtnIc').className = 'bi ' + iconeBotao;
        md.querySelectorAll('[data-passo]').forEach((el) => mostrar(el, el.dataset.passo === novo));
        mdErroTexto('');
        mostrar(md, true);
        const primeiro = md.querySelector('[data-passo="' + novo + '"] input');
        (primeiro || mdAcao).focus();
    };
    const mdFechar = (e) => {
        if (e && e.preventDefault) { e.preventDefault(); }
        passo = null;
        mostrar(md, false);
        mdErroTexto('');
    };
    md.querySelectorAll('[data-md-fechar]').forEach((el) => el.addEventListener('click', mdFechar));
    md.querySelectorAll('input').forEach((el) => el.addEventListener('input', () => mdErroTexto('')));
    $('lgCdAceite').addEventListener('change', () => mdErroTexto(''));
    $('lgCdGenero').addEventListener('change', () => mdErroTexto(''));

    /* Criar conta conectado pelo Google (desenho 10 VS 24.10.26, spec entrar-com-google, entrega 2) ou pelo Facebook
       (spec login-cupom-e-facebook, PR B). A identidade mora na sessão do servidor; aqui só se troca a tela: o cartão da
       conta no lugar dos botões. Pelo Google o E-mail some (o Google o provou); pelo Facebook o campo continua visível,
       porque o Facebook não prova e-mail e o que vale é o digitado. */
    const socOk = $('lgCdSocOk');
    const conectado = () => !socOk.hidden;
    const provedor = () => (socOk.dataset.provedor === 'facebook' ? 'facebook' : 'google');
    const peloGoogleConectado = () => conectado() && provedor() === 'google';
    const iniciais = (n) => String(n || '?').trim().split(/\s+/).filter(Boolean).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
    $('lgCdSocIni').textContent = iniciais($('lgCdSocNome').textContent);
    const desconectar = () => {
        mostrar(socOk, false);
        mostrar($('lgCdSoc'), true);
        mostrar($('lgCdEmailCampo'), true);
        $('lgCdEmail').value = '';
        mdErroTexto('');
    };
    $('lgCdSocTrocar').addEventListener('click', () => {
        postar('/cadastro/' + provedor() + '/esquecer', { _token: tokens.cadastro });
        desconectar();
        $('lgCdEmail').focus();
    });

    /* Cupom de indicação (desenho 10 VS 25.10.26). Desligar a chave apaga o campo. Validado só no servidor, ao enviar
       (spec login-cupom-e-facebook, D5). O sessionStorage só leva o cupom na ida e volta do "Continuar com Google" ou
       "Continuar com Facebook". */
    const CUPOM_GUARDADO = 'bj-cupom';
    const DESLIGADO = '-';
    const indTg = $('lgCdIndTg');
    const indCampo = $('lgCdIndCampo');
    const ind = $('lgCdInd');
    const cupomLigado = () => indTg.getAttribute('aria-checked') === 'true';
    const ligarCupom = (sim, valor) => {
        indTg.setAttribute('aria-checked', sim ? 'true' : 'false');
        mostrar(indCampo, sim);
        ind.value = sim ? (valor || '') : '';
        conferirCupomAoVivo();
    };
    indTg.addEventListener('click', () => {
        const ligar = !cupomLigado();
        ligarCupom(ligar, '');
        if (ligar) { ind.focus(); }
        mdErroTexto('');
    });
    ind.addEventListener('input', () => {
        ind.value = ind.value.toUpperCase().replace(/[^A-Z0-9-]/g, '').slice(0, 11);
        conferirCupomAoVivo();
    });
    /* Conferência ao vivo (spec login-cupom-ao-vivo): com o formato de cupom e 400 ms parado, uma consulta por valor;
       resposta de valor já trocado (ou chave desligada) é ignorada. Limite (429), falha de rede ou resposta estranha:
       silêncio, o cupom é conferido de novo ao enviar. */
    const indStatus = $('lgCdIndStatus');
    let indTemporizador = null;
    let indValorConferido = '';
    const mostrarStatusDoCupom = (valido) => {
        indStatus.textContent = '';
        const icone = document.createElement('i');
        icone.className = valido ? 'bi bi-check-circle-fill' : 'bi bi-exclamation-circle';
        icone.setAttribute('aria-hidden', 'true');
        indStatus.appendChild(icone);
        indStatus.appendChild(document.createTextNode(' ' + (valido ? indStatus.dataset.valido : indStatus.dataset.naoEncontrado)));
        indStatus.dataset.estado = valido ? 'valido' : 'ruim';
        mostrar(indStatus, true);
    };
    const limparStatusDoCupom = () => { indStatus.textContent = ''; delete indStatus.dataset.estado; mostrar(indStatus, false); };
    function conferirCupomAoVivo() {
        clearTimeout(indTemporizador);
        const valor = ind.value.trim();
        if (valor !== indValorConferido) { indValorConferido = ''; limparStatusDoCupom(); }
        /* Como no desenho 10: o prefixo "BJ-" é opcional e a conferência começa em 6 caracteres do código. O servidor
           normaliza o prefixo (CodigoDeIndicacao::doCupom), então o valor vai como foi digitado. */
        if (!cupomLigado() || valor === indValorConferido || !/^[A-Z0-9]{6,12}$/.test(valor.replace(/^BJ-/, ''))) { return; }
        indTemporizador = setTimeout(() => {
            fetch(indStatus.dataset.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ codigo: valor }),
                credentials: 'same-origin',
            }).then((r) => (r.ok ? r.json() : null)).then((dados) => {
                if (!dados || typeof dados.valido !== 'boolean') { return; }
                if (!cupomLigado() || ind.value.trim() !== valor) { return; }
                indValorConferido = valor;
                mostrarStatusDoCupom(dados.valido);
            }).catch(() => { /* sem rede ou limite: silêncio */ });
        }, 400);
    }
    [$('lgCdGoogle'), $('lgCdFacebook')].forEach((botao) => {
        if (!botao) { return; }
        botao.addEventListener('click', () => {
            // DESLIGADO marca o cupom da URL que a pessoa desligou: sem ele, a volta perde o "desliguei" e o link curto vale de novo.
            try {
                if (cupomLigado() && ind.value) { sessionStorage.setItem(CUPOM_GUARDADO, ind.value); } else if (cupomDaUrl) { sessionStorage.setItem(CUPOM_GUARDADO, DESLIGADO); }
            } catch (e) { /* sem storage, segue */ }
        });
    });
    /* O "indicacao" só vai quando há cupom ligado, ou quando a pessoa desligou o cupom que veio na URL: assim quem chegou
       pelo link curto e cria a conta em outra aba não perde a indicação da sessão. */
    const cupomDaUrl = raiz.dataset.ind || '';
    let cupomVoltaDoGoogle = ''; /* vale também para a volta do Facebook */
    try {
        cupomVoltaDoGoogle = !$('lgCdSocOk').hidden ? (sessionStorage.getItem(CUPOM_GUARDADO) || '') : '';
        sessionStorage.removeItem(CUPOM_GUARDADO);
    } catch (e) { cupomVoltaDoGoogle = ''; }
    const desligouAntesDoGoogle = cupomVoltaDoGoogle === DESLIGADO;
    if (desligouAntesDoGoogle) { cupomVoltaDoGoogle = ''; }
    if (cupomDaUrl || cupomVoltaDoGoogle) { ligarCupom(true, cupomDaUrl || cupomVoltaDoGoogle); }

    const abrirSenha = () => {
        $('lgRsEmail').value = email.value;
        irPara('rs1');
    };
    const abrirCadastro = () => {
        $('lgCdEmail').value = email.value;
        irPara('cad');
        repintarForca();
    };
    $('lgEsqueci').addEventListener('click', (e) => { e.preventDefault(); abrirSenha(); });
    /* Cadastro fechado ao público (política do Master): o link "Criar conta" nem vem na página e nada abre o cadastro. */
    const cadastroBloqueado = raiz.dataset.cadastroBloqueado === '1';
    const linkCriar = $('lgCriar');
    if (linkCriar) { linkCriar.addEventListener('click', (e) => { e.preventDefault(); abrirCadastro(); }); }

    /* Código: só dígitos, no máximo 6. */
    $('lgRsCod').addEventListener('input', (e) => { e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6); });

    const comBotaoOcupado = async (botao, tarefa) => {
        if (ocupado) {
            return;
        }
        ocupado = true;
        botao.setAttribute('aria-busy', 'true');
        try {
            await tarefa();
        } finally {
            ocupado = false;
            botao.removeAttribute('aria-busy');
        }
    };

    const enviarCodigo = async () => {
        const em = $('lgRsEmail').value.trim().toLowerCase();
        if (!REGEX_EMAIL.test(em)) {
            irPara('rs1');
            mdErroTexto(window.BJ_T.t('entrada.js.email_invalido'));

            return;
        }
        const r = await postar('/senha/codigo', { email: em, _token: tokens.senha });
        if (r.status !== 200) {
            mdErroTexto(mensagemDeErro(r));

            return;
        }
        $('lgRsEmail').value = em;
        $('lgRsEmailMostra').textContent = em;
        $('lgRsCod').value = '';
        irPara('rs2');
    };

    const salvarNovaSenha = async () => {
        const cod = $('lgRsCod').value.trim();
        const nova = $('lgRsNova').value;
        const conf = $('lgRsConf').value;
        if (!/^\d{6}$/.test(cod)) { mdErroTexto(window.BJ_T.t('entrada.js.codigo_incorreto')); return; }
        if (!senhaAceita(nova)) { mdErroTexto(window.BJ_T.t('entrada.js.senha_curta')); return; }
        if (nova !== conf) { mdErroTexto(window.BJ_T.t('entrada.js.senhas_diferentes')); return; }
        const r = await postar('/senha/codigo/confirmar', { email: $('lgRsEmail').value.trim(), codigo: cod, senha: nova, confirmacao: conf, _token: tokens.senha });
        if (r.status !== 200) {
            mdErroTexto(mensagemDeErro(r));

            return;
        }
        $('lgRsNova').value = '';
        $('lgRsConf').value = '';
        $('lgRsCod').value = '';
        repintarForca();
        irPara('rs3');
    };

    const voltarAoLogin = () => {
        email.value = $('lgRsEmail').value.trim();
        senha.value = '';
        mdFechar();
        senha.focus();
    };

    const validarCadastro = () => {
        if ($('lgCdNome').value.trim().split(/\s+/).length < 2) { return window.BJ_T.t('entrada.js.informe_nome'); }
        if (!$('lgCdEsc').value.trim()) { return window.BJ_T.t('entrada.js.informe_escritorio'); }
        if (!peloGoogleConectado() && !REGEX_EMAIL.test($('lgCdEmail').value.trim())) { return window.BJ_T.t('entrada.js.email_invalido'); }
        if (!['feminino', 'masculino'].includes($('lgCdGenero').value)) { return window.BJ_T.t('entrada.js.informe_genero'); }
        if (!$('lgCdAceite').checked) { return window.BJ_T.t('entrada.js.aceite'); }

        return '';
    };

    /* Destino da conta criada: só caminho relativo do próprio site. */
    const destinoSeguro = (d) => (typeof d === 'string' && d.startsWith('/') && !d.startsWith('//') && !d.includes('\\') ? d : '/');

    /* "Bom dia, Ana!": como no desenho, o começo do e-mail (antes do @ e do primeiro . _ -), com a inicial maiúscula;
       o /login/entrando faz o mesmo no servidor. */
    const saudacao = (em) => {
        const h = new Date().getHours();
        const nm = (em.split('@')[0] || '').split(/[._-]/)[0] || '';

        const sd = h < 12 ? window.BJ_T.t('entrada.js.saudacao.bom_dia') : h < 18 ? window.BJ_T.t('entrada.js.saudacao.boa_tarde') : window.BJ_T.t('entrada.js.saudacao.boa_noite');

        return nm
            ? window.BJ_T.t('entrada.js.saudacao.com_nome', { saudacao: sd, nome: nm.charAt(0).toUpperCase() + nm.slice(1) })
            : window.BJ_T.t('entrada.js.saudacao.sem_nome', { saudacao: sd });
    };

    const criarConta = async () => {
        const falha = validarCadastro();
        if (falha !== '') {
            mdErroTexto(falha);

            return;
        }
        const peloGoogle = peloGoogleConectado();
        const peloFacebook = conectado() && provedor() === 'facebook';
        const em = peloGoogle ? (socOk.dataset.email || '') : $('lgCdEmail').value.trim();
        const campos = {
            nome: $('lgCdNome').value.trim(),
            escritorio: $('lgCdEsc').value.trim(),
            genero: $('lgCdGenero').value,
            aceite: $('lgCdAceite').checked,
            _token: tokens.cadastro,
        };
        if (cupomLigado()) { campos.indicacao = ind.value.trim(); } else if (cupomDaUrl || desligouAntesDoGoogle) { campos.indicacao = ''; }
        /* Pelo Google o e-mail não vai: o servidor usa o que o Google provou, guardado na sessão. Pelo Facebook vai o do
           campo (o Facebook só sugere); o `id` do Facebook o servidor lê da sessão. */
        const destinoDoPost = peloGoogle ? '/cadastro/google/criar' : (peloFacebook ? '/cadastro/facebook/criar' : '/cadastro/criar');
        const r = peloGoogle ? await postar(destinoDoPost, campos) : await postar(destinoDoPost, { ...campos, email: em });
        if (r.status !== 200) {
            if (r.dados && (r.dados.googleExpirou === true || r.dados.facebookExpirou === true)) { desconectar(); }
            mdErroTexto(mensagemDeErro(r));

            return;
        }
        email.value = em;
        if (r.dados.confirmar === true) {
            if (peloFacebook) { desconectar(); }
            mostrarConfirmacao(typeof r.dados.email === 'string' && r.dados.email !== '' ? r.dados.email : em);

            return;
        }
        mdFechar();
        $('lgVeuTit').textContent = saudacao(em);
        mostrar($('lgVeu'), true);
        $('lgCartao').classList.add('lg-sai');
        const destino = destinoSeguro(r.dados.destino);
        setTimeout(() => { window.location.href = destino; }, 2100);
    };

    /* A conta nasceu sem login: o modal pede a confirmação do e-mail e oferece o reenvio do link. */
    const cfAviso = $('lgCfAviso');
    const mostrarConfirmacao = (em) => {
        $('lgCfEmail').textContent = em;
        cfAviso.textContent = '';
        mostrar(cfAviso, false);
        irPara('conf');
    };

    /* O reenvio público lê a conta da sessão (gravada pelo cadastro); aqui só vai o CSRF. */
    const reenviarConfirmacao = async () => {
        cfAviso.textContent = '';
        mostrar(cfAviso, false);
        const r = await postar('/login/reenviar-confirmacao', { _token: tokens.reenvio });
        const texto = r.dados && typeof r.dados.mensagem === 'string' && r.dados.mensagem !== '' ? r.dados.mensagem : ERRO_GENERICO;
        if (r.status !== 200 || r.dados.ok !== true) {
            mdErroTexto(texto);

            return;
        }
        cfAviso.textContent = texto;
        mostrar(cfAviso, true);
    };

    mdForm.addEventListener('submit', (e) => {
        e.preventDefault();
        if (passo === 'rs3') { voltarAoLogin(); return; }
        const acoes = { rs1: enviarCodigo, rs2: salvarNovaSenha, cad: criarConta, conf: reenviarConfirmacao };
        if (acoes[passo]) {
            comBotaoOcupado(mdAcao, acoes[passo]);
        }
    });
    /* Reenviar: refaz o envio do passo 1 e continua no passo 2. */
    $('lgRsReenviar').addEventListener('click', (e) => { e.preventDefault(); comBotaoOcupado(mdAcao, enviarCodigo); });

    /* --------------------------------------------------- rodapé (links) ---- */
    const linksCaixa = $('lgLinks');
    const hrefSeguro = (u) => (/^https?:\/\//i.test(u || '') ? u : '#');
    const desenharLinks = (links) => {
        linksCaixa.textContent = '';
        links.forEach((l, i) => {
            if (i > 0 && !(l.icone && links[i - 1].icone)) {
                const sep = document.createElement('span');
                sep.textContent = '·';
                linksCaixa.appendChild(sep);
            }
            const a = document.createElement('a');
            a.href = hrefSeguro(l.url);
            a.target = '_blank';
            a.rel = 'noopener';
            a.dataset.chave = l.chave;
            a.className = l.icone ? 'lg-link--icone' : 'lg-link--texto';
            a.title = ehMaster ? window.BJ_T.t('entrada.js.link_do_master', { rotulo: l.rotulo }) : l.rotulo;
            if (l.icone) {
                const ic = document.createElement('i');
                ic.className = 'bi ' + l.icone;
                ic.setAttribute('aria-hidden', 'true');
                a.appendChild(ic);
                a.setAttribute('aria-label', l.rotulo);
            } else {
                a.textContent = l.rotulo;
            }
            linksCaixa.appendChild(a);
        });
    };

    /* ----------------------------------------- editar link (SÓ MASTER) ----- */
    if (ehMaster) {
        let links = [];
        try { links = JSON.parse(raiz.dataset.links || '[]'); } catch (e) { links = []; }
        const lk = $('lgLk');
        const lkCaixa = $('lgLkCaixa');
        const lkVal = $('lgLkVal');
        const lkErro = $('lgLkErro');
        const lkOcultar = $('lgLkOcultar');
        let atual = null;

        const lkErroTexto = (t) => { lkErro.textContent = t; mostrar(lkErro, t !== ''); };
        const lkFechar = (e) => { if (e && e.preventDefault) { e.preventDefault(); } atual = null; mostrar(lk, false); };
        const lkAbrir = (link, x, y) => {
            atual = link;
            $('lgLkTit').textContent = window.BJ_T.t('entrada.js.editar_link', { rotulo: link.rotulo });
            $('lgLkIc').className = 'bi ' + (link.icone || 'bi-link-45deg');
            lkVal.value = link.url || '';
            lkErroTexto('');
            lkOcultar.textContent = link.oculto ? window.BJ_T.t('entrada.js.mostrar_no_rodape') : window.BJ_T.t('entrada.js.ocultar_do_rodape');
            lkOcultar.classList.toggle('lg-mostrar', link.oculto);
            lkCaixa.style.left = Math.max(8, Math.min(x, window.innerWidth - 330)) + 'px';
            lkCaixa.style.top = Math.max(8, y - 150) + 'px';
            mostrar(lk, true);
            lkVal.focus();
        };

        linksCaixa.addEventListener('contextmenu', (e) => {
            const a = e.target.closest('a[data-chave]');
            const link = a ? links.find((l) => l.chave === a.dataset.chave) : null;
            if (!link) {
                return;
            }
            e.preventDefault();
            lkAbrir(link, e.clientX, e.clientY);
        });
        lk.querySelector('[data-lk-fechar]').addEventListener('click', lkFechar);
        lk.querySelector('[data-lk-fechar]').addEventListener('contextmenu', lkFechar);
        lkVal.addEventListener('input', () => lkErroTexto(''));

        const gravar = async (corpo) => {
            const r = await postar('/login/links/' + encodeURIComponent(atual.chave), Object.assign(corpo, { _token: tokens.ajuste }));
            if (r.status !== 200 || !Array.isArray(r.dados.links)) {
                lkErroTexto(mensagemDeErro(r));

                return;
            }
            links = r.dados.links;
            desenharLinks(links);
            lkFechar();
        };

        $('lgLkForm').addEventListener('submit', (e) => {
            e.preventDefault();
            const v = lkVal.value.trim();
            if (!/^https?:\/\/[^\s.]+\.[^\s]+$/i.test(v)) {
                lkErroTexto(window.BJ_T.t('entrada.js.endereco_invalido'));

                return;
            }
            gravar({ acao: 'salvar', url: v });
        });
        lk.querySelector('[data-lk="abrir"]').addEventListener('click', () => {
            const v = lkVal.value.trim();
            if (/^https?:\/\//i.test(v)) { window.open(v, '_blank', 'noopener'); }
        });
        lk.querySelector('[data-lk="restaurar"]').addEventListener('click', () => gravar({ acao: 'restaurar' }));
        lkOcultar.addEventListener('click', () => gravar({ acao: atual.oculto ? 'mostrar' : 'ocultar' }));
    }

    /* ------------------------------------------------------------ suporte ---- */
    const sup = $('lgSup');
    const supBtn = $('lgSupBtn');
    const supForm = $('lgSupForm');
    const supErro = $('lgSupErro');
    const supEmail = $('lgSupEmail');
    const supMsg = $('lgSupMsg');
    let assunto = '';

    const supErroTexto = (t) => { supErro.textContent = t; mostrar(supErro, t !== ''); };
    const supMostrarForm = () => { mostrar($('lgSupOk'), false); mostrar(supForm, true); };
    const supFechar = () => { mostrar(sup, false); supBtn.setAttribute('aria-expanded', 'false'); };

    supBtn.addEventListener('click', () => {
        if (!sup.hidden) {
            supFechar();

            return;
        }
        supMostrarForm();
        if (!supEmail.value) { supEmail.value = email.value; }
        mostrar(sup, true);
        supBtn.setAttribute('aria-expanded', 'true');
    });
    $('lgSupFechar').addEventListener('click', supFechar);
    [$('lgSupNome'), supEmail, supMsg].forEach((el) => el.addEventListener('input', () => supErroTexto('')));

    const pintarChips = () => sup.querySelectorAll('[data-assunto]').forEach((c) => {
        const on = c.dataset.assunto === assunto;
        c.classList.toggle('lg-on', on);
        c.setAttribute('aria-pressed', String(on));
    });
    sup.querySelectorAll('[data-assunto]').forEach((chip) => chip.addEventListener('click', () => {
        assunto = chip.dataset.assunto; /* o valor (dado) que o servidor compara; o texto escrito é o rótulo do chip */
        if (!supMsg.value) {
            supMsg.value = assunto === 'Outro assunto' ? '' : chip.textContent + '. ';
        }
        pintarChips();
        supMsg.focus();
    }));

    supForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const em = supEmail.value.trim();
        if (!REGEX_EMAIL.test(em)) { supErroTexto(window.BJ_T.t('entrada.js.suporte.email_invalido')); return; }
        if (supMsg.value.trim().length < 5) { supErroTexto(window.BJ_T.t('entrada.js.suporte.escreva')); return; }
        comBotaoOcupado($('lgSupEnviar'), async () => {
            const r = await postar('/login/suporte', {
                nome: $('lgSupNome').value.trim(),
                email: em,
                assunto: assunto,
                mensagem: supMsg.value.trim(),
                site: $('lgSupSite').value,
                _token: tokens.suporte,
            });
            if (r.status !== 200) {
                supErroTexto(mensagemDeErro(r));

                return;
            }
            $('lgSupOkEmail').textContent = typeof r.dados.email === 'string' ? r.dados.email : em;
            supMsg.value = '';
            assunto = '';
            pintarChips();
            mostrar(supForm, false);
            mostrar($('lgSupOk'), true);
        });
    });

    /* Quem recebe as mensagens (SÓ MASTER). */
    if (ehMaster && $('lgDlgTg')) {
        const dlg = $('lgDlg');
        $('lgDlgTg').addEventListener('click', () => {
            mostrar(dlg, dlg.hidden);
            $('lgDlgTg').setAttribute('aria-expanded', String(!dlg.hidden));
        });
        dlg.querySelectorAll('[data-user-id]').forEach((op) => op.addEventListener('click', async () => {
            const r = await postar('/login/suporte/destinatario', { userId: Number(op.dataset.userId), _token: tokens.ajuste });
            if (r.status !== 200) {
                $('lgDlgTxt').textContent = mensagemDeErro(r);

                return;
            }
            dlg.querySelectorAll('[data-user-id]').forEach((o) => {
                const on = o === op;
                o.classList.toggle('lg-on', on);
                o.setAttribute('aria-pressed', String(on));
                o.querySelector('i').className = 'bi ' + (on ? 'bi-check-circle-fill' : 'bi-circle');
            });
            if (typeof r.dados.texto === 'string') { $('lgDlgTxt').textContent = r.dados.texto; }
        }));
    }

    /* ----------------------------------------------------- tecla Esc ------- */
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') {
            return;
        }
        const lk = $('lgLk');
        if (lk && !lk.hidden) { mostrar(lk, false); return; }
        if (!md.hidden) { mdFechar(); return; }
        if (!seg.hidden) { segFechar(); return; }
        if (!sup.hidden) { supFechar(); }
    });

    /* ---------------------------------------- ?abrir=senha|cadastro -------- */
    if (raiz.dataset.abrir === 'senha') {
        abrirSenha();
    } else if (cadastroBloqueado) {
        /* O aviso da política já veio no cartão de login (servidor); ?abrir=cadastro, cupom e #cadastro não abrem o modal. */
    } else if (raiz.dataset.abrir === 'cadastro') {
        abrirCadastro();
        /* "Continuar com Google" sem o cliente OAuth no servidor: o aviso volta da ida, no erro do modal. */
        if (raiz.dataset.avisoCadastro) { mdErroTexto(raiz.dataset.avisoCadastro); }
    } else if (raiz.dataset.ind || /cadastro/.test(window.location.hash)) {
        /* Desenho 10 VS 25.10.26: cupom na URL (?ind=, ?codigo=, ?ref=) ou #cadastro abrem o "Criar conta". */
        abrirCadastro();
    }
    /* No desenho o campo do cupom tem autoFocus: aberto pela URL, o foco vai para ele. */
    if (cupomDaUrl && passo === 'cad') { ind.focus(); }
})();
