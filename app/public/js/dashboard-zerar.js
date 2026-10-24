/**
 * Dashboard: "Zerar" das colunas da tabela Desempenho (DASH-014; desenho "01 - Dashboard 1.2.2",
 * `comMenu`, `zerarCol`, `dzConf`, `dzNegado` e o toast).
 *
 * Botão direito no TÍTULO de uma coluna numérica abre o menu da equipe; no NÚMERO de uma pessoa, o
 * menu dela (com "Ver relatório", quando o número tem lista). Quem pode zerar (Master ou primário
 * ativo, `data-db-zerar-pode="1"` no `.db-page`) vê "Zerar"; os outros veem "Pedir para zerar", que
 * avisa o primário e a Controladoria. O servidor decide de novo em cada POST (403 para quem não pode).
 *
 * O cabeçalho do menu diz "Contando desde dd/mm hh:mm" quando a coluna tem corte (o mais recente
 * entre o da equipe e o da pessoa, o `dzCorte` do desenho), lido de `data-db-zeramentos` na tabela.
 * Zerar e desfazer recarregam o fragmento pelo mesmo XHR do filtro.
 *
 * Nada de HTML montado por string: todo texto entra por textContent. `window.DbZerar` expõe o zerar
 * para o cartão do colaborador (dashboard-campeao.js, "Zerar" dos Comentários).
 */
(function () {
    'use strict';

    var pagina = null;
    var menuAberto = null;
    var toastTimer = null;

    function el(tag, classe, texto) {
        var n = document.createElement(tag);
        if (classe) { n.className = classe; }
        if (texto !== undefined && texto !== null) { n.textContent = String(texto); }

        return n;
    }

    function icone(classe) {
        var i = el('i', 'bi ' + classe);
        i.setAttribute('aria-hidden', 'true');

        return i;
    }

    function pode() {
        return !!pagina && pagina.getAttribute('data-db-zerar-pode') === '1';
    }

    function rotuloDe(coluna) {
        var mapa = {};
        try { mapa = JSON.parse(pagina.getAttribute('data-db-zerar-rotulos') || '{}'); } catch (e) { mapa = {}; }

        return mapa[coluna] || coluna;
    }

    function primeiroNome(nome) {
        return String(nome || '').trim().split(' ')[0];
    }

    // ── Cortes ("Contando desde") ─────────────────────────────────────────

    function cortesDaTabela() {
        var tabela = document.querySelector('.db-page table.db-table[data-db-zeramentos]');
        try { return tabela ? JSON.parse(tabela.getAttribute('data-db-zeramentos') || '{}') : {}; } catch (e) { return {}; }
    }

    function corteDe(coluna, pessoa) {
        var z = cortesDaTabela()[coluna] || {};
        var datas = [z.todos, pessoa ? (z.pessoas || {})[pessoa] : null].filter(Boolean).map(function (d) { return new Date(d); });

        return datas.length ? new Date(Math.max.apply(null, datas)) : null;
    }

    function temZeramento(coluna) {
        var z = cortesDaTabela()[coluna];

        return !!z && (!!z.todos || Object.keys(z.pessoas || {}).length > 0);
    }

    function subtitulo(coluna, pessoa) {
        var corte = corteDe(coluna, pessoa);
        if (!corte) { return window.BJ_T.t('dashboard.js.zerar.contagem_completa'); }
        var quando = corte.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

        return window.BJ_T.t('dashboard.js.zerar.contando_desde', { quando: quando });
    }

    // ── Servidor ──────────────────────────────────────────────────────────

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : '';
    }

    function postar(atributo, corpo) {
        return window.fetch(pagina.getAttribute(atributo), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token() },
            body: JSON.stringify(corpo)
        }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }

            return r.json();
        });
    }

    function recarregarTabela() {
        var form = document.querySelector('[data-filtro-root] [data-filtro-form]');
        if (!form) { window.location.reload(); return; }
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }

    // ── Toast do desenho ──────────────────────────────────────────────────

    function toast(titulo, iconeClasse) {
        var velho = document.querySelector('.db-zerar-toast');
        if (velho) { velho.remove(); }
        var t = el('div', 'db-zerar-toast');
        t.setAttribute('role', 'status');
        var caixa = el('span', 'db-zerar-toast-ico');
        caixa.appendChild(icone(iconeClasse));
        var fechar = el('button', 'db-zerar-toast-fechar');
        fechar.type = 'button';
        fechar.setAttribute('aria-label', window.BJ_T.t('dashboard.js.zerar.fechar'));
        fechar.appendChild(icone('bi-x-lg'));
        fechar.addEventListener('click', function () { t.remove(); });
        t.append(caixa, el('div', 'db-zerar-toast-titulo', titulo), fechar);
        document.body.appendChild(t);
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { t.remove(); }, 3500);
    }

    // ── Diálogos do desenho (confirmar e sem permissão) ───────────────────

    /** Texto com {rotulo} e {nome} em negrito, como no desenho. */
    function comNegrito(modelo, vars) {
        var span = el('span');
        modelo.split(/(\{[a-z]+\})/).forEach(function (pedaco) {
            var chave = pedaco.replace(/[{}]/g, '');
            span.appendChild(pedaco.charAt(0) === '{' && vars[chave] !== undefined ? el('strong', null, vars[chave]) : document.createTextNode(pedaco));
        });

        return span;
    }

    function abrirDialogo(o) {
        var fundo = el('div', 'db-zerar-fundo' + (o.classe ? ' ' + o.classe : ''));
        var caixa = el('div', 'db-zerar-dialogo');
        caixa.setAttribute('role', 'alertdialog');
        caixa.setAttribute('aria-label', o.aria);
        var cab = el('div', 'db-zerar-dialogo-cab');
        cab.append(icone(o.icone), el('span', 'db-zerar-dialogo-titulo', o.titulo));
        var corpo = el('div', 'db-zerar-dialogo-corpo');
        o.corpo.forEach(function (n) { corpo.appendChild(n); });
        var rodape = el('div', 'db-zerar-dialogo-rodape');
        o.botoes.forEach(function (b) { rodape.appendChild(b); });
        caixa.append(cab, corpo, rodape);
        fundo.appendChild(caixa);
        var fechar = function () { fundo.remove(); document.removeEventListener('keydown', esc); };
        var esc = function (e) { if (e.key === 'Escape') { fechar(); } };
        fundo.addEventListener('click', function (e) { if (e.target === fundo) { fechar(); } });
        document.addEventListener('keydown', esc);
        document.body.appendChild(fundo);
        o.botoes[o.botoes.length - 1].focus();

        return fechar;
    }

    function botao(classe, texto, aoClicar) {
        var b = el('button', 'db-zerar-btn ' + classe, texto);
        b.type = 'button';
        b.addEventListener('click', aoClicar);

        return b;
    }

    function confirmarZerar(alvo) {
        var vars = { rotulo: alvo.rotulo, nome: alvo.nomeAlvo };
        var fechar = null;
        var sim = botao('db-zerar-btn--perigo', window.BJ_T.t('dashboard.js.zerar.sim_zerar'), function () { fechar(); aplicarZerar(alvo); });
        var nao = botao('db-zerar-btn--neutro', window.BJ_T.t('dashboard.js.zerar.cancelar'), function () { fechar(); });
        fechar = abrirDialogo({
            aria: window.BJ_T.t('dashboard.js.zerar.confirmar_aria'),
            icone: 'bi-exclamation-triangle-fill',
            titulo: window.BJ_T.t('dashboard.js.zerar.confirmar_titulo', { rotulo: alvo.rotulo }),
            corpo: [comNegrito(window.BJ_T.t('dashboard.js.zerar.confirmar_texto'), vars), el('span', 'db-zerar-dialogo-nota', window.BJ_T.t('dashboard.js.zerar.confirmar_aviso'))],
            botoes: [nao, sim]
        });
    }

    function textoDoPedido(r) {
        if (r.repetido) {
            return r.controladoria ? window.BJ_T.t('dashboard.js.zerar.negado_repetido') : window.BJ_T.t('dashboard.js.zerar.negado_repetido_primario');
        }

        return r.controladoria ? window.BJ_T.t('dashboard.js.zerar.negado_enviado') : window.BJ_T.t('dashboard.js.zerar.negado_enviado_primario');
    }

    function mostrarNegado(alvo, r) {
        var aviso = el('span', 'db-zerar-dialogo-enviado');
        aviso.append(icone('bi-send-check'), el('span', null, textoDoPedido(r)));
        var fechar = null;
        var ok = botao('db-zerar-btn--azul', window.BJ_T.t('dashboard.js.zerar.entendi'), function () { fechar(); });
        fechar = abrirDialogo({
            classe: 'db-zerar-fundo--negado',
            aria: window.BJ_T.t('dashboard.js.zerar.negado_aria'),
            icone: 'bi-shield-lock-fill',
            titulo: window.BJ_T.t('dashboard.js.zerar.negado_titulo'),
            corpo: [comNegrito(window.BJ_T.t('dashboard.js.zerar.negado_texto'), { rotulo: alvo.rotulo, nome: alvo.nomeAlvo }), aviso],
            botoes: [ok]
        });
    }

    // ── Ações ─────────────────────────────────────────────────────────────

    function aplicarZerar(alvo) {
        postar('data-db-zerar-url', { coluna: alvo.coluna, pessoa: alvo.pessoa }).then(function () {
            toast(window.BJ_T.t('dashboard.js.zerar.zerado', { rotulo: alvo.rotulo, nome: alvo.nomeAlvo }), 'bi-arrow-counterclockwise');
            recarregarTabela();
            if (alvo.depois) { alvo.depois(); }
        }).catch(function () { toast(window.BJ_T.t('dashboard.js.zerar.erro'), 'bi-exclamation-circle'); });
    }

    function pedirZerar(alvo) {
        postar('data-db-zerar-pedir-url', { coluna: alvo.coluna, pessoa: alvo.pessoa }).then(function (r) {
            mostrarNegado(alvo, r);
        }).catch(function () { toast(window.BJ_T.t('dashboard.js.zerar.erro'), 'bi-exclamation-circle'); });
    }

    /** O `zerarCol` do desenho: quem pode confirma e zera; quem não pode pede. */
    function zerar(alvo) {
        alvo.rotulo = alvo.rotulo || rotuloDe(alvo.coluna);
        alvo.nomeAlvo = alvo.pessoa ? alvo.nome : window.BJ_T.t('dashboard.js.zerar.toda_a_equipe');
        if (pode()) { confirmarZerar(alvo); } else { pedirZerar(alvo); }
    }

    function desfazer(coluna) {
        var rotulo = rotuloDe(coluna);
        if (!window.confirm(window.BJ_T.t('dashboard.js.zerar.desfazer_confirmar', { rotulo: rotulo }))) { return; }
        postar('data-db-zerar-desfazer-url', { coluna: coluna }).then(recarregarTabela)
            .catch(function () { toast(window.BJ_T.t('dashboard.js.zerar.erro'), 'bi-exclamation-circle'); });
    }

    // ── Menu do botão direito ─────────────────────────────────────────────

    function itemDoMenu(ic, rotulo, sub, perigo, aoClicar) {
        var b = el('button', 'db-zerar-item' + (perigo ? ' db-zerar-item--perigo' : ''));
        b.type = 'button';
        b.setAttribute('role', 'menuitem');
        var txt = el('span', 'db-zerar-item-txt');
        txt.append(el('span', 'db-zerar-item-rotulo', rotulo), el('span', 'db-zerar-item-sub', sub));
        b.append(icone(ic + ' db-zerar-item-ico'), txt);
        b.addEventListener('click', function () { fecharMenu(); aoClicar(); });

        return b;
    }

    function itensDoMenu(alvo) {
        var itens = [];
        var curto = primeiroNome(alvo.nome);
        if (alvo.pessoa && alvo.href) {
            itens.push(itemDoMenu('bi-list-ul', window.BJ_T.t('dashboard.js.zerar.ver_relatorio', { nome: curto }), window.BJ_T.t('dashboard.js.zerar.ver_relatorio_sub', { rotulo: alvo.rotulo.toLowerCase() }), false, function () { window.location.href = alvo.href; }));
        }
        if (alvo.pessoa) {
            itens.push(pode()
                ? itemDoMenu('bi-person-dash', window.BJ_T.t('dashboard.js.zerar.zerar_de', { nome: curto }), window.BJ_T.t('dashboard.js.zerar.zerar_de_sub'), true, function () { zerar(alvo); })
                : itemDoMenu('bi-send', window.BJ_T.t('dashboard.js.zerar.pedir_de', { nome: curto }), window.BJ_T.t('dashboard.js.zerar.pedir_sub'), false, function () { zerar(alvo); }));
        }
        var equipe = { coluna: alvo.coluna, pessoa: null, rotulo: alvo.rotulo };
        itens.push(pode()
            ? itemDoMenu('bi-people', window.BJ_T.t('dashboard.js.zerar.zerar_equipe'), window.BJ_T.t('dashboard.js.zerar.zerar_equipe_sub'), true, function () { zerar(equipe); })
            : itemDoMenu('bi-send', window.BJ_T.t('dashboard.js.zerar.pedir_equipe'), window.BJ_T.t('dashboard.js.zerar.pedir_sub'), false, function () { zerar(equipe); }));
        if (pode() && temZeramento(alvo.coluna)) {
            itens.push(itemDoMenu('bi-arrow-repeat', window.BJ_T.t('dashboard.js.zerar.desfazer'), window.BJ_T.t('dashboard.js.zerar.desfazer_sub'), false, function () { desfazer(alvo.coluna); }));
        }

        return itens;
    }

    function cabecalhoDoMenu(alvo) {
        var cab = el('div', 'db-zerar-menu-cab');
        var txt = el('span', 'db-zerar-menu-cab-txt');
        var quem = alvo.pessoa ? alvo.nome : window.BJ_T.t('dashboard.js.zerar.equipe');
        txt.append(
            el('span', 'db-zerar-menu-titulo', window.BJ_T.t('dashboard.js.zerar.menu_titulo', { quem: quem, rotulo: alvo.rotulo })),
            el('span', 'db-zerar-menu-sub', subtitulo(alvo.coluna, alvo.pessoa))
        );
        cab.append(icone('bi-chat-left-text-fill db-zerar-menu-cab-ico'), txt);

        return cab;
    }

    function abrirMenu(alvo, x, y) {
        fecharMenu();
        alvo.rotulo = rotuloDe(alvo.coluna);
        var menu = el('div', 'db-zerar-menu');
        menu.setAttribute('role', 'menu');
        menu.appendChild(cabecalhoDoMenu(alvo));
        itensDoMenu(alvo).forEach(function (i) { menu.appendChild(i); });
        if (!pode()) {
            var nota = el('div', 'db-zerar-menu-nota');
            nota.append(icone('bi-lock'), el('span', null, window.BJ_T.t('dashboard.js.zerar.sem_permissao')));
            menu.appendChild(nota);
        }
        menu.style.left = Math.max(8, Math.min(x, window.innerWidth - 270)) + 'px';
        menu.style.top = Math.max(8, Math.min(y, window.innerHeight - 260)) + 'px';
        document.body.appendChild(menu);
        menuAberto = menu;
        var primeiro = menu.querySelector('.db-zerar-item');
        if (primeiro) { primeiro.focus(); }
    }

    function fecharMenu() {
        if (menuAberto) { menuAberto.remove(); menuAberto = null; }
    }

    /** O alvo do botão direito: título de coluna (equipe) ou número de uma pessoa. */
    function alvoDoClique(e) {
        var cel = e.target.closest('.db-page table.db-table th[data-coluna], .db-page table.db-table tbody td[data-coluna]');
        if (!cel || cel.getAttribute('data-coluna') === 'cargo') { return null; }
        var coluna = cel.getAttribute('data-coluna');
        if (cel.tagName === 'TH') { return { coluna: coluna, pessoa: null }; }
        var tr = cel.closest('tr[data-db-pessoa]');
        var colab = tr ? tr.querySelector('[data-db-colab-nome]') : null;
        var link = cel.querySelector('a.db-num-link');

        return tr ? { coluna: coluna, pessoa: parseInt(tr.getAttribute('data-db-pessoa'), 10), nome: colab ? colab.getAttribute('data-db-colab-nome') : '', href: link ? link.href : null } : null;
    }

    function ligarMenu(root) {
        root.addEventListener('contextmenu', function (e) {
            var alvo = alvoDoClique(e);
            if (!alvo) { return; }
            e.preventDefault();
            abrirMenu(alvo, e.clientX, e.clientY);
        });
        document.addEventListener('mousedown', function (e) {
            if (menuAberto && !menuAberto.contains(e.target)) { fecharMenu(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { fecharMenu(); }
        });
    }

    // ── "Zerar relatório de todos" do menu ⋮ ──────────────────────────────

    /** O item só existe com a coluna Relatório na tabela: acompanha cada recarga do fragmento. */
    function sincronizarZerarTodos() {
        var grupo = document.querySelector('[data-db-zerar-todos-grupo]');
        if (!grupo) { return; }
        var naTabela = !!document.querySelector('.db-page table.db-table th[data-coluna="relatorio"]');
        grupo.hidden = !naTabela;
        grupo.querySelectorAll('button').forEach(function (b) { b.hidden = !naTabela; });
    }

    function ligarZerarTodos() {
        document.addEventListener('click', function (e) {
            var b = e.target.closest('[data-db-zerar-todos]');
            if (!b) { return; }
            zerar({ coluna: b.getAttribute('data-db-zerar-todos'), pessoa: null });
        });
        var resultado = document.querySelector('[data-filtro-resultado]');
        if (resultado && window.MutationObserver) {
            new MutationObserver(sincronizarZerarTodos).observe(resultado, { childList: true });
        }
        sincronizarZerarTodos();
    }

    window.DbZerar = {
        pode: pode,
        /** Cartão do colaborador: zerar (ou pedir) uma coluna de uma pessoa; `depois` recarrega o cartão. */
        zerar: function (coluna, pessoa, nome, depois) { zerar({ coluna: coluna, pessoa: pessoa, nome: nome, depois: depois }); }
    };

    document.addEventListener('DOMContentLoaded', function () {
        pagina = document.querySelector('.db-page[data-db-zerar-url]');
        if (!pagina) { return; }
        document.querySelectorAll('[data-filtro-root]').forEach(ligarMenu);
        ligarZerarTodos();
    });
})();
