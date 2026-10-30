/* =============================================================================
   demandas-lista.js: a casca das Minhas Demandas no desenho 15 (`15 - Demandas - VS 30.10.26.dc.html`).

   A Lista é a do Expediente (expediente/_lista + expediente-lista.js: menus, colunas, Visualizar, dicas,
   cabeçalho fixo). O que a casca do Expediente (expediente/index) faz e esta tela também precisa mora aqui, sem
   o trilho e sem o painel de marcadores: filtrar, ordenar, paginar, abrir a linha, alternar a situação no duplo
   clique, voltar ao padrão pelo título e, depois da Nova demanda, recarregar a lista e avisar.
   ============================================================================= */
(function () {
    'use strict';

    var painel = document.getElementById('demandas-painel');
    if (!painel) { return; }
    var buscaTimer = 0;

    function form() { return document.getElementById('formFiltrosPasta'); }
    function avisar(texto) { if (window.ExpAviso) { window.ExpAviso(texto); } else { window.alert(texto); } }

    /* Os <script> do fragmento (tooltips, modo de visualização) só rodam se forem recriados. */
    function injetarHtmlComScripts(alvo, html) {
        alvo.innerHTML = html;
        alvo.querySelectorAll('script').forEach(function (velho) {
            var novo = document.createElement('script');
            Array.prototype.forEach.call(velho.attributes, function (a) { novo.setAttribute(a.name, a.value); });
            novo.textContent = velho.textContent;
            velho.parentNode.replaceChild(novo, velho);
        });
    }

    /* Só os resultados recarregam: a barra de filtro fica, e a busca não perde o foco. */
    function recarregarResultados(extra) {
        var f = form();
        if (!f) { return; }
        var params = new URLSearchParams(new FormData(f));
        Object.keys(extra || {}).forEach(function (k) { params.set(k, extra[k]); });
        var atual = document.getElementById('pastas-resultado');
        if (atual) { atual.classList.add('pastas-carregando'); }

        fetch(f.getAttribute('action') + '?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.text();
            })
            .then(function (html) {
                var novo = new DOMParser().parseFromString(html, 'text/html').getElementById('pastas-resultado');
                var alvo = document.getElementById('pastas-resultado');
                if (novo && alvo) {
                    injetarHtmlComScripts(alvo, novo.innerHTML);
                    alvo.classList.remove('pastas-carregando');
                }
            })
            .catch(function () {
                var alvo = document.getElementById('pastas-resultado');
                if (alvo) { alvo.classList.remove('pastas-carregando'); }
                avisar(BJ_T.t('expediente.js.lista.erro_carregar'));
            });
    }

    function paginaAtual() {
        var ativo = painel.querySelector('.pagination .page-item.active .btn-pagina');
        var n = ativo ? parseInt(ativo.getAttribute('data-page'), 10) : 1;
        return n > 0 ? n : 1;
    }

    function campo(nome) { var f = form(); return f ? f.elements.namedItem(nome) : null; }

    function ordenarPor(coluna, direcao) {
        var o = campo('ordenar');
        var d = campo('direcao');
        if (o) { o.value = coluna; }
        if (d) { d.value = direcao; }
    }

    /* Título do acervo (dc `irTitulo`/`irPadrao`): todas as demandas, sem filtros, na ordem padrão. */
    function irPadrao() {
        var f = form();
        if (!f) { return; }
        Array.prototype.forEach.call(f.elements, function (el) {
            if (el.matches('.js-pasta-busca, .js-pasta-filtro')) { el.value = ''; }
        });
        ordenarPor('', '');
        recarregarResultados({ page: 1 });
    }

    // Busca com espera (mantém o foco); Enter aplica na hora.
    painel.addEventListener('input', function (e) {
        if (!e.target.classList.contains('js-pasta-busca')) { return; }
        clearTimeout(buscaTimer);
        buscaTimer = setTimeout(function () { recarregarResultados({ page: 1 }); }, 350);
    });
    painel.addEventListener('submit', function (e) {
        if (!e.target || e.target.id !== 'formFiltrosPasta') { return; }
        e.preventDefault();
        clearTimeout(buscaTimer);
        recarregarResultados({ page: 1 });
    });

    // Filtros (o de situação mora na barra, fora do <form>, mas é dele) e "Ordenar por" dos modos sem cabeçalho.
    document.addEventListener('change', function (e) {
        var alvo = e.target;
        if (!alvo.closest || !alvo.closest('#demandas-painel')) { return; }
        if (alvo.classList.contains('js-pasta-ordenar')) {
            var partes = (alvo.value || '').split('|');
            if (!partes[0]) { return; }
            ordenarPor(partes[0], partes[1] === 'desc' ? 'desc' : 'asc');
            recarregarResultados({ page: 1 });
            return;
        }
        if (alvo.classList.contains('js-pasta-filtro')) {
            recarregarResultados({ page: 1 });
        }
    });

    painel.addEventListener('click', function (e) {
        var th = e.target.closest('#tabelaPastas th.sortable[data-ordenar]');
        if (th) {
            e.preventDefault();
            var o = campo('ordenar');
            var d = campo('direcao');
            var mesma = o && o.value === th.getAttribute('data-ordenar');
            ordenarPor(th.getAttribute('data-ordenar'), (mesma && d && d.value === 'asc') ? 'desc' : 'asc');
            recarregarResultados({ page: 1 });
            return;
        }
        var cal = e.target.closest('.js-abrir-calendario');
        if (cal) {
            e.preventDefault();
            var data = cal.parentNode.querySelector('input[type="date"]');
            if (data && typeof data.showPicker === 'function') {
                try { data.showPicker(); } catch (x) { data.focus(); }
            } else if (data) { data.focus(); }
            return;
        }
        // "Limpar todos os filtros" (dc `limpar`): busca, responsável, prioridade e datas; a Situação fica.
        if (e.target.closest('.js-pasta-limpar')) {
            e.preventDefault();
            var f = form();
            if (f) {
                Array.prototype.filter.call(f.elements, function (el) { return el.matches('.js-pasta-busca, .js-pasta-filtro') && el.name !== 'status'; })
                    .forEach(function (el) { el.value = ''; });
            }
            recarregarResultados({ page: 1 });
            return;
        }
        var pag = e.target.closest('.btn-pagina');
        if (pag) {
            e.preventDefault();
            recarregarResultados({ page: pag.getAttribute('data-page') });
        }
    });

    // Linha, item da lista ou cartão: abre a pasta (com um menu aberto, o clique só o fecha, dc `abrir`).
    document.addEventListener('click', function (e) {
        var alvo = e.target.closest('#demandas-painel .pasta-row-link[data-href]');
        if (!alvo || e.target.closest('button, a, form, input, select, .js-toggle-situacao')) { return; }
        if (window.ExpMenu && window.ExpMenu.abertoNoToque && window.ExpMenu.abertoNoToque()) { return; }
        window.location.href = alvo.getAttribute('data-href');
    });

    // Duplo clique na situação (Ativo/Arquivado) alterna, como no Expediente; manda o destino que a tela mostra.
    document.addEventListener('dblclick', function (e) {
        var selo = e.target.closest('#demandas-painel .js-toggle-situacao');
        if (!selo || selo.getAttribute('data-ocupado') === '1') { return; }
        e.stopPropagation();
        selo.setAttribute('data-ocupado', '1');
        var destino = selo.getAttribute('data-situacao') === 'ativo' ? 'arquivado' : 'ativo';
        fetch(selo.getAttribute('data-url'), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ _token: selo.getAttribute('data-csrf') || '', situacao: destino })
        })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (res) {
                selo.setAttribute('data-ocupado', '');
                if (!res.ok || !res.d.sucesso) { avisar(res.d.erro || BJ_T.t('expediente.js.lista.situacao.erro')); return; }
                var ativo = res.d.novaSituacao === 'ativo';
                selo.classList.remove('text-bg-success', 'text-bg-secondary');
                selo.classList.add(ativo ? 'text-bg-success' : 'text-bg-secondary');
                selo.textContent = ativo ? BJ_T.t('expediente.js.lista.situacao.ativo') : BJ_T.t('expediente.js.lista.situacao.arquivado');
                selo.setAttribute('data-situacao', res.d.novaSituacao);
            })
            .catch(function () {
                selo.setAttribute('data-ocupado', '');
                avisar(BJ_T.t('expediente.js.lista.situacao.falha_conexao'));
            });
    });

    /* Nova demanda salva (dc `salvarNovaFn` do desenho 15): a lista volta ao NUP do maior para o menor, página 1,
       e avisa "Demanda da pasta N aberta para CLIENTE com você como responsável". Os filtros ficam como estão. */
    document.addEventListener('exp:pasta-criada', function (e) {
        ordenarPor('nup', 'desc');
        recarregarResultados({ page: 1 });
        avisar(BJ_T.t('pasta_lista.js.demandas.criada', { nup: e.detail.nup, cliente: e.detail.cliente }));
    });

    window.ExpPainel = { recarregarResultados: recarregarResultados, paginaAtual: paginaAtual, irPadrao: irPadrao };

    /* "Pastas por página" escolhido no Visualizar vale para as duas telas (dc `bj-exp-pp`, o mesmo do Expediente). */
    var pp = null;
    try { pp = localStorage.getItem('expediente_por_pagina'); } catch (x) { pp = null; }
    var campoPp = campo('por_pagina');
    if (pp && campoPp && campoPp.value !== pp) {
        campoPp.value = pp;
        recarregarResultados({ page: 1 });
    }
}());
