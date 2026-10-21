/* "Destacar comentário" na pasta (spec pasta-destacar-comentario.md; dc02 L260-270, L4590-4607, L6305-6306).
   Os três menus de comentário (Dados, Detalhes e Financeiro) chamam PastaDestaque.pintarMenu(menu, cartao, aba)
   ao abrir: os itens "Destacar" e "Remover destaque" entram logo depois de "Encaminhar" (ou de "Copiar texto"), só
   para quem pode editar a pasta. A paleta grava no servidor; o último destaque da aba prevalece. Tudo por
   textContent e atributos: nada de HTML montado com dado do usuário. */
(function () {
    'use strict';

    var raiz = document.getElementById('psDestaques');
    if (!raiz) { return; }

    var CORES = [['#ffe94d', 'Amarelo'], ['#86e08a', 'Verde claro'], ['#7fdcef', 'Ciano'], ['#f59bcb', 'Rosa'],
        ['#6eaef2', 'Azul'], ['#e5534b', 'Vermelho'], ['#1f3a6e', 'Azul-marinho'], ['#0f7c7a', 'Verde-petróleo'],
        ['#2e7d32', 'Verde'], ['#6a3fa0', 'Roxo'], ['#8e2430', 'Vinho'], ['#8a8526', 'Oliva'], ['#8a959c', 'Cinza'],
        ['#cfd6db', 'Cinza claro'], ['#1b1f22', 'Preto']];
    var PREFIXO = { dados: 'pasta-msg-', detalhes: 'detalhes-obs-', financeiro: 'financeiro-obs-' };
    var ROTULO_ABA = { dados: 'Dados da pasta', detalhes: 'Detalhes', financeiro: 'Financeiro' };
    var pode = raiz.dataset.pode === '1';
    var mapa = {};
    try { mapa = JSON.parse(raiz.dataset.mapa || '{}') || {}; } catch (e) { mapa = {}; }
    if (Array.isArray(mapa)) { mapa = {}; }
    var paleta = null;

    function avisar(texto, tipo) {
        if (typeof window.avisarPs === 'function') { window.avisarPs(texto, tipo || 'ok'); }
    }

    function nomeDaCor(cor) {
        for (var i = 0; i < CORES.length; i++) { if (CORES[i][0] === cor) { return CORES[i][1]; } }
        return cor || '';
    }

    /* dc L4591: o texto vai claro sobre cor escura (contraste WCAG contra branco e preto). */
    function ehEscura(hex) {
        var h = String(hex).replace('#', '');
        var c = [0, 2, 4].map(function (i) { return parseInt(h.substr(i, 2), 16) / 255; })
            .map(function (v) { return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
        var l = 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];

        return (1.05 / (l + 0.05)) > ((l + 0.05) / 0.05);
    }

    function idDoCartao(cartao, aba) {
        return cartao && cartao.id ? parseInt(cartao.id.slice(PREFIXO[aba].length), 10) : NaN;
    }

    function corDe(aba, id) {
        return (mapa[aba] || {})[id] || null;
    }

    function pintarCartao(cartao, cor) {
        if (!cartao) { return; }
        if (cor) {
            cartao.dataset.destaque = cor;
            cartao.dataset.destaqueTom = ehEscura(cor) ? 'escuro' : 'claro';
            cartao.style.setProperty('--ps-destaque', cor);
        } else {
            delete cartao.dataset.destaque;
            delete cartao.dataset.destaqueTom;
            cartao.style.removeProperty('--ps-destaque');
        }
    }

    function pintarTodos() {
        Object.keys(PREFIXO).forEach(function (aba) {
            Object.keys(mapa[aba] || {}).forEach(function (id) {
                pintarCartao(document.getElementById(PREFIXO[aba] + id), mapa[aba][id]);
            });
        });
    }

    function item(menu, classe, icone, texto) {
        var modelo = menu.querySelector('.ps-processo-menu-item, .ps-fin-menu-item');
        var botao = document.createElement('button');
        botao.type = 'button';
        botao.className = (modelo && modelo.classList.contains('ps-fin-menu-item') ? 'ps-fin-menu-item ' : 'ps-processo-menu-item ') + classe;
        botao.setAttribute('role', 'menuitem');
        var i = document.createElement('i');
        i.className = 'bi ' + icone;
        i.setAttribute('aria-hidden', 'true');
        var span = document.createElement('span');
        span.textContent = texto;
        botao.appendChild(i);
        botao.appendChild(span);

        return botao;
    }

    function garantirItens(menu) {
        var destacar = menu.querySelector('.js-destacar');
        if (destacar) { return destacar; }
        destacar = item(menu, 'js-destacar', 'bi-highlighter', 'Destacar');
        var remover = item(menu, 'js-destacar-remover', 'bi-eraser', 'Remover destaque');
        var depois = menu.querySelector('[class*="-encaminhar"]') || menu.querySelector('[class*="-copiar"]');
        if (depois && depois.parentNode === menu) {
            depois.after(destacar, remover);
        } else {
            menu.appendChild(destacar);
            menu.appendChild(remover);
        }

        return destacar;
    }

    /* Chamado pelo menu de cada aba ao abrir, com o cartão clicado. */
    function pintarMenu(menu, cartao, aba) {
        if (!menu || !cartao || !PREFIXO[aba]) { return; }
        menu.dataset.destaqueAlvo = cartao.id;
        menu.dataset.destaqueAba = aba;
        var destacar = garantirItens(menu);
        var remover = menu.querySelector('.js-destacar-remover');
        var cor = corDe(aba, idDoCartao(cartao, aba));
        destacar.hidden = !pode;
        remover.hidden = !pode || !cor;
        destacar.querySelector('span').textContent = cor ? 'Destacar · ' + nomeDaCor(cor).toLowerCase() : 'Destacar';
    }

    function fecharMenus(menu) {
        document.dispatchEvent(new CustomEvent('ps:fechar-popovers'));
        if (menu) { menu.hidden = true; }
    }

    function fecharPaleta() {
        if (paleta) { paleta.remove(); paleta = null; }
    }

    function abrirPaleta(cartao, aba, x, y) {
        fecharPaleta();
        var atual = corDe(aba, idDoCartao(cartao, aba));
        paleta = document.createElement('div');
        paleta.className = 'ps-destaque-paleta';
        paleta.setAttribute('role', 'dialog');
        paleta.setAttribute('aria-label', 'Cor do destaque');
        paleta.tabIndex = -1;
        var titulo = document.createElement('div');
        titulo.className = 'ps-destaque-paleta-titulo';
        titulo.textContent = 'Destacar comentário';
        paleta.appendChild(titulo);
        var grade = document.createElement('div');
        grade.className = 'ps-destaque-paleta-grade';
        CORES.forEach(function (par) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'ps-destaque-cor' + (par[0] === atual ? ' is-atual' : '');
            b.style.background = par[0];
            b.title = par[1];
            b.setAttribute('aria-label', par[1]);
            b.addEventListener('click', function () { gravar(cartao, aba, par[0]); });
            grade.appendChild(b);
        });
        paleta.appendChild(grade);
        var semCor = document.createElement('button');
        semCor.type = 'button';
        semCor.className = 'ps-destaque-sem-cor';
        var amostra = document.createElement('span');
        amostra.setAttribute('aria-hidden', 'true');
        semCor.appendChild(amostra);
        semCor.appendChild(document.createTextNode('Sem cor'));
        semCor.addEventListener('click', function () { gravar(cartao, aba, ''); });
        paleta.appendChild(semCor);
        document.body.appendChild(paleta);
        var largura = paleta.offsetWidth;
        paleta.style.left = Math.max(8, Math.min(x, window.innerWidth - largura - 8)) + 'px';
        paleta.style.top = Math.max(8, Math.min(y, window.innerHeight - paleta.offsetHeight - 8)) + 'px';
        paleta.addEventListener('keydown', function (e) { if (e.key === 'Escape') { fecharPaleta(); } });
        paleta.focus({ preventScroll: true });
    }

    function gravar(cartao, aba, cor) {
        var id = idDoCartao(cartao, aba);
        var antes = corDe(aba, id);
        fecharPaleta();
        if ((antes || '') === cor) { return; }
        var dados = new URLSearchParams({ aba: aba, comentario: String(id), cor: cor, _token: raiz.dataset.token || '' });
        fetch(raiz.dataset.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: dados.toString(),
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; });
        }).then(function (res) {
            if (!res.ok) { avisar(res.j.erro || 'Não foi possível salvar o destaque.', 'erro'); return; }
            aplicar(aba, id, res.j);
        }).catch(function () { avisar('Não foi possível salvar o destaque.', 'erro'); });
    }

    function aplicar(aba, id, resposta) {
        var cor = resposta.cor || null;
        var cartao = document.getElementById(PREFIXO[aba] + id);
        Object.keys(mapa[aba] || {}).forEach(function (outro) {
            if (cor && String(outro) !== String(id)) { pintarCartao(document.getElementById(PREFIXO[aba] + outro), null); }
        });
        if (cor) {
            mapa[aba] = {};
            mapa[aba][id] = cor;
        } else if (mapa[aba]) {
            delete mapa[aba][id];
        }
        pintarCartao(cartao, cor);
        if (!cor) { avisar('Destaque removido'); return; }
        var nome = nomeDaCor(cor).toLowerCase();
        if (resposta.desfeito) {
            avisar('Comentário destacado em ' + nome + '. O destaque anterior' + (resposta.desfeito.autor ? ' (comentário de ' + resposta.desfeito.autor + ')' : '')
                + ' foi desfeito: só o último destaque de ' + ROTULO_ABA[aba] + ' prevalece.');
            return;
        }
        avisar('Comentário destacado em ' + nome);
    }

    /* Em captura: lê o cartão e a posição antes de o próprio menu tratar o clique e fechar. */
    document.addEventListener('click', function (e) {
        var botao = e.target.closest ? e.target.closest('.js-destacar, .js-destacar-remover') : null;
        if (!botao) {
            if (paleta && !paleta.contains(e.target)) { fecharPaleta(); }
            return;
        }
        var menu = botao.closest('[data-destaque-alvo]');
        var cartao = menu ? document.getElementById(menu.dataset.destaqueAlvo) : null;
        var aba = menu ? menu.dataset.destaqueAba : '';
        e.preventDefault();
        e.stopPropagation();
        if (!cartao || !pode) { fecharMenus(menu); return; }
        var r = botao.getBoundingClientRect();
        fecharMenus(menu);
        if (botao.classList.contains('js-destacar-remover')) { gravar(cartao, aba, ''); return; }
        abrirPaleta(cartao, aba, r.left, r.top);
    }, true);

    window.PastaDestaque = { pintarMenu: pintarMenu, pintarCartao: pintarCartao };
    pintarTodos();
    /* Cartões criados pelo JS depois de enviar (e recarregados) pegam a cor do mapa quando aparecem. */
    new MutationObserver(function () { pintarTodos(); }).observe(document.body, { childList: true, subtree: true });
}());
