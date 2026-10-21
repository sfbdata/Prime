/*
 * Tela de entrada (desenho "10 - Login - VS 19.10.26"): todo o comportamento da página.
 *
 * Reproduz o componente do desenho (DCLogic) sobre o HTML que o Twig serve. Os dados chegam por
 * data-attributes no #lgRaiz; as escritas são POST JSON para os endpoints da spec
 * docs/specs/login-desenho-10.md. Nada aqui decide segurança: o servidor revalida tudo.
 */
(() => {
    'use strict';

    const raiz = document.getElementById('lgRaiz');
    if (!raiz) {
        return;
    }

    const $ = (id) => document.getElementById(id);
    const REGEX_EMAIL = /^\S+@\S+\.\S+$/;
    const ERRO_GENERICO = 'Não foi possível concluir. Tente novamente.';
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

    form.addEventListener('submit', (e) => {
        if (!REGEX_EMAIL.test(email.value.trim())) {
            e.preventDefault();
            mostrarErro('Informe um e-mail válido.');
            email.focus();

            return;
        }
        if (senha.value === '') {
            e.preventDefault();
            mostrarErro('Informe a senha.');
            senha.focus();
        }
    });

    olho.addEventListener('click', () => {
        const ver = senha.type === 'password';
        senha.type = ver ? 'text' : 'password';
        const titulo = ver ? 'Ocultar senha' : 'Mostrar senha';
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
    const FORCA = [['', '#c9d3da', '0%'], ['Fraca', '#c0392b', '25%'], ['Média', '#c76a12', '50%'], ['Boa', '#0a63c9', '75%'], ['Forte', '#1e7b45', '100%']];
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
        rs1: ['Recuperar senha', 'bi-key', 'Enviar código', 'bi-send'],
        rs2: ['Criar nova senha', 'bi-shield-lock', 'Salvar nova senha', 'bi-check2'],
        rs3: ['Pronto', 'bi-check2-circle', 'Voltar para o login', 'bi-box-arrow-in-right'],
        cad: ['Criar conta e escritório', 'bi-building-add', 'Criar minha conta grátis', 'bi-rocket-takeoff'],
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
    $('lgCriar').addEventListener('click', (e) => { e.preventDefault(); abrirCadastro(); });

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
            mdErroTexto('Informe um e-mail válido.');

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
        if (!/^\d{6}$/.test(cod)) { mdErroTexto('Código incorreto. Confira o e-mail ou reenvie.'); return; }
        if (!senhaAceita(nova)) { mdErroTexto('Use pelo menos 8 caracteres, com letras e números.'); return; }
        if (nova !== conf) { mdErroTexto('As senhas não conferem.'); return; }
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
        if ($('lgCdNome').value.trim().split(/\s+/).length < 2) { return 'Informe seu nome completo.'; }
        if (!$('lgCdEsc').value.trim()) { return 'Informe o nome do escritório.'; }
        if (!REGEX_EMAIL.test($('lgCdEmail').value.trim())) { return 'Informe um e-mail válido.'; }
        if (!senhaAceita($('lgCdSenha').value)) { return 'Use pelo menos 8 caracteres, com letras e números.'; }
        if (!$('lgCdAceite').checked) { return 'É preciso aceitar os Termos e a Política de privacidade.'; }

        return '';
    };

    /* Destino da conta criada: só caminho relativo do próprio site. */
    const destinoSeguro = (d) => (typeof d === 'string' && d.startsWith('/') && !d.startsWith('//') && !d.includes('\\') ? d : '/');

    /* "Bom dia, Nome!": o primeiro nome digitado no cadastro (sem Dr./Dra.), como o véu do /login/entrando;
       sem nome, a parte antes do @, como no desenho. */
    const saudacao = (em, nome) => {
        const h = new Date().getHours();
        const partes = (nome || '').trim().split(/\s+/).filter(Boolean);
        while (partes.length > 1 && /^(dr|dra|sr|sra)\.?$/i.test(partes[0])) { partes.shift(); }
        const nm = partes[0] || ((em.split('@')[0] || '').split(/[._-]/)[0]) || '';

        return (h < 12 ? 'Bom dia' : h < 18 ? 'Boa tarde' : 'Boa noite') + (nm ? ', ' + nm.charAt(0).toUpperCase() + nm.slice(1).toLowerCase() : '') + '!';
    };

    const criarConta = async () => {
        const falha = validarCadastro();
        if (falha !== '') {
            mdErroTexto(falha);

            return;
        }
        const em = $('lgCdEmail').value.trim();
        const r = await postar('/cadastro/criar', {
            nome: $('lgCdNome').value.trim(),
            escritorio: $('lgCdEsc').value.trim(),
            email: em,
            senha: $('lgCdSenha').value,
            aceite: $('lgCdAceite').checked,
            _token: tokens.cadastro,
        });
        if (r.status !== 200) {
            mdErroTexto(mensagemDeErro(r));

            return;
        }
        email.value = em;
        if (r.dados.confirmar === true) {
            mostrarConfirmacao(typeof r.dados.email === 'string' && r.dados.email !== '' ? r.dados.email : em);

            return;
        }
        mdFechar();
        $('lgVeuTit').textContent = saudacao(em, $('lgCdNome').value);
        mostrar($('lgVeu'), true);
        $('lgCartao').classList.add('lg-sai');
        const destino = destinoSeguro(r.dados.destino);
        setTimeout(() => { window.location.href = destino; }, 2100);
    };

    /* A conta nasceu sem login: o modal pede a confirmação do e-mail e oferece o reenvio do link. */
    const cfAviso = $('lgCfAviso');
    const mostrarConfirmacao = (em) => {
        $('lgCdSenha').value = '';
        repintarForca();
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
            a.title = l.rotulo + (ehMaster ? ' · botão direito para editar' : '');
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
            $('lgLkTit').textContent = 'Editar link · ' + link.rotulo;
            $('lgLkIc').className = 'bi ' + (link.icone || 'bi-link-45deg');
            lkVal.value = link.url || '';
            lkErroTexto('');
            lkOcultar.textContent = link.oculto ? 'Mostrar no rodapé' : 'Ocultar do rodapé';
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
                lkErroTexto('Informe um endereço começando com https://');

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
        assunto = chip.dataset.assunto;
        if (!supMsg.value) {
            supMsg.value = assunto === 'Outro assunto' ? '' : assunto + '. ';
        }
        pintarChips();
        supMsg.focus();
    }));

    supForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const em = supEmail.value.trim();
        if (!REGEX_EMAIL.test(em)) { supErroTexto('Informe um e-mail válido para a resposta.'); return; }
        if (supMsg.value.trim().length < 5) { supErroTexto('Escreva a sua mensagem.'); return; }
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
    } else if (raiz.dataset.abrir === 'cadastro') {
        abrirCadastro();
    }
})();
