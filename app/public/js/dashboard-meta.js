/**
 * Dashboard: meta de pastas do mês (DASH-016; desenho "01 - Dashboard - VS 25.10.26": `metaEd`,
 * `abrirMetaEd`, `salvarMeta` e o menu `ctxC` do card Pastas criadas).
 *
 * Quem edita é só o primário (o servidor decide de novo em cada POST, 403 para os outros); a página
 * avisa em `data-db-meta-pode="1"` no `.db-page`. O card traz o estado em `data-db-meta-*`
 * (valor atual, mês de vigência, mês de hoje, dias úteis do mês). O lápis e o "Definir meta do mês"
 * (`[data-db-meta-editar]`) abrem o editor junto ao botão; o botão direito no card abre o menu
 * "Alterar meta do mês / Ver histórico" (o dashboard-historico.js chama `DbMeta.menuDoCard`).
 *
 * Salvar e remover gravam e recarregam o fragmento pelo mesmo XHR do filtro (o card volta com a barra
 * nova). Sem toast: o desenho só fecha o editor.
 *
 * Nada de HTML montado por string: todo texto entra por textContent.
 */
(function () {
    'use strict';

    var pagina = null;
    var aberto = null; // { elemento, fechar }
    var MAXIMO = 9999;

    function el(tag, classe, texto) {
        var n = document.createElement(tag);
        if (classe) { n.className = classe; }
        if (texto !== undefined && texto !== null) { n.textContent = String(texto); }

        return n;
    }

    function icone(classe, extra) {
        var i = el('i', 'bi ' + classe + (extra ? ' ' + extra : ''));
        i.setAttribute('aria-hidden', 'true');

        return i;
    }

    function pode() {
        return !!pagina && pagina.getAttribute('data-db-meta-pode') === '1';
    }

    function fmt(n) {
        return n.toLocaleString('pt-BR', { maximumFractionDigits: 1 });
    }

    /** 'YYYY-MM' => "agosto de 2026" (o `nomeMes` do desenho). */
    function nomeMes(ym) {
        var p = String(ym || '').split('-').map(Number);
        if (p.length < 2 || !p[0] || !p[1]) { return ''; }

        return new Date(p[0], p[1] - 1, 1).toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
    }

    function cartao() {
        return document.querySelector('.db-page .db-stat-card[data-db-meta-mes]');
    }

    function estado() {
        var c = cartao();
        if (!c) { return null; }

        return {
            valor: parseInt(c.getAttribute('data-db-meta-valor') || '0', 10) || 0,
            desde: c.getAttribute('data-db-meta-desde') || '',
            mes: c.getAttribute('data-db-meta-mes') || '',
            uteis: parseInt(c.getAttribute('data-db-meta-uteis') || '0', 10) || 0
        };
    }

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : '';
    }

    function postar(atributo, corpo) {
        return window.fetch(pagina.getAttribute(atributo), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token() },
            body: corpo === null ? '{}' : JSON.stringify(corpo)
        }).then(function (r) {
            if (!r.ok) {
                var erro = new Error('HTTP ' + r.status);
                erro.status = r.status;
                throw erro;
            }

            return r.json();
        });
    }

    function recarregar() {
        var form = document.querySelector('[data-filtro-root] [data-filtro-form]');
        if (!form) { window.location.reload(); return; }
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }

    function fechar() {
        if (aberto) {
            aberto.elemento.remove();
            aberto = null;
        }
    }

    function dentroDaTela(x, y, largura, altura) {
        return {
            esquerda: Math.max(12, Math.min(x, window.innerWidth - largura - 12)),
            topo: Math.max(12, Math.min(y, window.innerHeight - altura))
        };
    }

    // ── Editor (metaEd) ───────────────────────────────────────────────────

    function abrirEditor(x, y) {
        var est = estado();
        if (!pode() || !est) { return; }
        fechar();

        var caixa = el('div', 'db-meta-ed');
        caixa.setAttribute('role', 'dialog');
        caixa.setAttribute('aria-label', window.BJ_T.t('dashboard.js.meta.aria'));
        var pos = dentroDaTela(x, y, 292, 250);
        caixa.style.left = pos.esquerda + 'px';
        caixa.style.top = pos.topo + 'px';

        var cab = el('div', 'db-meta-ed-cab');
        var emblema = el('span', 'db-meta-ed-emblema');
        emblema.appendChild(icone('bi-bullseye'));
        var titulos = el('div', 'db-meta-ed-titulos');
        titulos.append(el('div', 'db-meta-ed-titulo', window.BJ_T.t('dashboard.js.meta.titulo')),
            el('div', 'db-meta-ed-mes', window.BJ_T.t('dashboard.js.meta.a_partir_de', { mes: nomeMes(est.mes) })));
        cab.append(emblema, titulos);

        var entrada = el('input', 'db-meta-ed-entrada');
        entrada.type = 'number';
        entrada.min = '1';
        entrada.max = String(MAXIMO);
        entrada.step = '1';
        entrada.setAttribute('inputmode', 'numeric');
        entrada.placeholder = window.BJ_T.t('dashboard.js.meta.placeholder');
        entrada.value = est.valor > 0 ? String(est.valor) : '';

        var previa = el('div', 'db-meta-ed-previa');
        var herdada = est.valor > 0 && est.desde !== '' && est.desde < est.mes;
        var vigencia = el('div', 'db-meta-ed-vigencia');
        vigencia.append(icone('bi-people'), el('span', null, herdada
            ? window.BJ_T.t('dashboard.js.meta.vigencia_herdada', { desde: nomeMes(est.desde), mes: nomeMes(est.mes) })
            : window.BJ_T.t('dashboard.js.meta.vigencia_nova')));

        var rodape = el('div', 'db-meta-ed-rodape');
        var remover = null;
        if (est.valor > 0) {
            remover = el('button', 'db-meta-ed-remover', window.BJ_T.t('dashboard.js.meta.remover'));
            remover.type = 'button';
            rodape.appendChild(remover);
        }
        var cancelar = el('button', 'db-meta-ed-cancelar', window.BJ_T.t('dashboard.js.meta.cancelar'));
        cancelar.type = 'button';
        var salvar = el('button', 'db-meta-ed-salvar', window.BJ_T.t('dashboard.js.meta.salvar'));
        salvar.type = 'button';
        rodape.append(el('span', 'db-meta-ed-folga'), cancelar, salvar);

        caixa.append(cab, el('div', 'db-meta-ed-pergunta', window.BJ_T.t('dashboard.js.meta.pergunta')), entrada, previa, vigencia, rodape);

        var ocupado = false;

        function valor() {
            var v = parseInt(entrada.value, 10);

            return v > 0 && v <= MAXIMO ? v : 0;
        }

        function atualizar() {
            var v = valor();
            salvar.disabled = v === 0 || ocupado;
            previa.textContent = v > 0 && est.uteis > 0
                ? window.BJ_T.t('dashboard.js.meta.previa', { dia: fmt(v / est.uteis), semana: fmt(v / est.uteis * 5) })
                : '';
        }

        function falhou(erro) {
            ocupado = false;
            atualizar();
            previa.textContent = erro && erro.status === 403 ? window.BJ_T.t('dashboard.js.meta.sem_permissao') : window.BJ_T.t('dashboard.js.meta.erro');
        }

        function gravar(promessa) {
            ocupado = true;
            atualizar();
            promessa.then(function () {
                fechar();
                recarregar();
            }).catch(falhou);
        }

        entrada.addEventListener('input', function () {
            var limpo = entrada.value.replace(/\D/g, '').slice(0, String(MAXIMO).length);
            if (limpo !== entrada.value) { entrada.value = limpo; }
            atualizar();
        });
        entrada.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (valor() > 0 && !ocupado) { gravar(postar('data-db-meta-url', { valor: valor() })); }
            }
        });
        salvar.addEventListener('click', function () {
            if (valor() > 0 && !ocupado) { gravar(postar('data-db-meta-url', { valor: valor() })); }
        });
        cancelar.addEventListener('click', fechar);
        if (remover) {
            remover.addEventListener('click', function () {
                if (!ocupado) { gravar(postar('data-db-meta-remover-url', null)); }
            });
        }

        document.body.appendChild(caixa);
        aberto = { elemento: caixa };
        atualizar();
        setTimeout(function () { entrada.focus(); entrada.select(); }, 30);
    }

    // ── Menu do botão direito no card (ctxC) ──────────────────────────────

    function abrirMenu(x, y, abrirHistorico) {
        var est = estado();
        if (!est) { return; }
        fechar();

        var menu = el('div', 'db-meta-menu');
        menu.setAttribute('role', 'menu');
        var pos = dentroDaTela(x, y, 228, 110);
        menu.style.left = pos.esquerda + 'px';
        menu.style.top = pos.topo + 'px';

        function item(rotulo, iconeClasse, acao) {
            var b = el('button', 'db-meta-menu-item');
            b.type = 'button';
            b.setAttribute('role', 'menuitem');
            b.append(icone(iconeClasse), document.createTextNode(rotulo));
            b.addEventListener('click', function () { acao(); });
            menu.appendChild(b);
        }

        item(est.valor > 0 ? window.BJ_T.t('dashboard.js.meta.menu_alterar') : window.BJ_T.t('dashboard.js.meta.menu_definir'), 'bi-bullseye', function () {
            fechar();
            abrirEditor(x, y);
        });
        item(window.BJ_T.t('dashboard.js.meta.menu_historico'), 'bi-clock-history', function () {
            fechar();
            abrirHistorico();
        });

        document.body.appendChild(menu);
        aberto = { elemento: menu };
    }

    /**
     * Chamado pelo histórico no botão direito do card. Devolve true quando o menu assumiu o clique
     * (só o primário, e só no card Pastas criadas); false deixa o histórico abrir direto.
     */
    function menuDoCard(card, x, y, abrirHistorico) {
        if (!pode() || !card.hasAttribute('data-db-meta-mes')) { return false; }
        abrirMenu(x, y, abrirHistorico);

        return true;
    }

    window.DbMeta = { menuDoCard: menuDoCard };

    document.addEventListener('DOMContentLoaded', function () {
        pagina = document.querySelector('.db-page');
        if (!pagina) { return; }

        document.addEventListener('click', function (e) {
            var botao = e.target.closest ? e.target.closest('[data-db-meta-editar]') : null;
            if (!botao || !pagina.contains(botao)) { return; }
            var r = botao.getBoundingClientRect();
            abrirEditor(r.left, r.bottom + 8);
        });
        document.addEventListener('mousedown', function (e) {
            if (aberto && !aberto.elemento.contains(e.target)) { fechar(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { fechar(); }
        });
    });
})();
