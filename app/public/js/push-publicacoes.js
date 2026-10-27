/* Aba Publicações do Push Processual (desenho 11): seleção em lote, ações e menu de contexto. */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-pp-publicacoes]');
    var form = document.querySelector('[data-pp-lote]');
    if (!raiz || !form) { return; }

    var rotas = {
        leitura: '/push-processual/lote/leitura',
        excluir: '/push-processual/lote/excluir',
        limpar: '/push-processual/lote/limpar-lidas'
    };
    var linhas = Array.prototype.slice.call(raiz.querySelectorAll('[data-pp-linha]'));
    var selecionadas = {};
    var grupo = raiz.querySelector('[data-pp-sel-grupo]');
    var contador = raiz.querySelector('[data-pp-sel-n]');
    var botaoTodas = raiz.querySelector('[data-pp-selecionar-todas]');

    function texto(chave, trocas) {
        var t = raiz.getAttribute('data-t-' + chave) || '';
        Object.keys(trocas || {}).forEach(function (k) { t = t.split('%' + k + '%').join(trocas[k]); });
        return t;
    }

    function aviso(mensagem) {
        var alvo = document.querySelector('.content-wrapper') || document.body;
        var div = document.createElement('div');
        div.className = 'alert alert-info pp-aviso';
        div.setAttribute('role', 'status');
        div.textContent = mensagem;
        alvo.insertBefore(div, alvo.firstChild);
        window.setTimeout(function () { div.remove(); }, 3500);
    }

    function ids() {
        return linhas.filter(function (l) { return selecionadas[l.dataset.id]; }).map(function (l) { return l.dataset.id; });
    }

    function pintar() {
        var n = ids().length;
        linhas.forEach(function (l) {
            var marcada = !!selecionadas[l.dataset.id];
            var botao = l.querySelector('[data-pp-marca]');
            l.classList.toggle('pp-linha--sel', marcada);
            botao.setAttribute('aria-pressed', marcada ? 'true' : 'false');
            botao.querySelector('i').className = 'bi ' + (marcada ? 'bi-check-square-fill' : 'bi-square');
        });
        grupo.hidden = n === 0;
        contador.textContent = texto('n-selecionadas', { n: n });
        botaoTodas.querySelector('i').className = 'bi ' + (n && n === linhas.length ? 'bi-check-square-fill' : n ? 'bi-dash-square' : 'bi-square');
    }

    function enviar(rota, listaIds, lida) {
        form.action = rotas[rota];
        Array.prototype.slice.call(form.querySelectorAll('input[name="ids[]"]')).forEach(function (i) { i.remove(); });
        listaIds.forEach(function (id) {
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = 'ids[]'; i.value = id;
            form.appendChild(i);
        });
        if (lida !== undefined) { form.querySelector('[name="lida"]').value = lida; }
        form.submit();
    }

    function confirmarComPalavra(confirmacao, pergunta, cancelado) {
        if (confirmacao && !window.confirm(confirmacao)) { return false; }
        var digitado = window.prompt(pergunta, '');
        if (String(digitado || '').trim().toUpperCase() !== 'EXCLUIR') { aviso(cancelado); return false; }
        return true;
    }

    function excluirLote(lista) {
        var n = lista.length;
        if (confirmarComPalavra(texto('confirma-excluir-lote', { n: n }), texto('prompt-excluir-lote', { n: n }), texto('cancelada'))) {
            enviar('excluir', lista);
        }
    }

    function excluirUma(linha) {
        var c = texto('confirma-excluir-uma', { data: linha.dataset.data, processo: linha.dataset.numero });
        if (confirmarComPalavra(c, texto('prompt-excluir-uma'), texto('cancelada'))) {
            enviar('excluir', [linha.dataset.id]);
        }
    }

    function limpar() {
        var n = parseInt(raiz.getAttribute('data-total-lidas'), 10) || 0;
        if (!n) { aviso(texto('sem-lidas')); return; }
        if (confirmarComPalavra(texto('confirma-limpar', { n: n }), texto('prompt-limpar', { n: n }), texto('limpeza-cancelada'))) {
            enviar('limpar', []);
        }
    }

    var modal = document.querySelector('[data-pp-modal-pasta]');
    var formVinculo = document.querySelector('[data-pp-vinculo-form]');
    var alvo = null;
    var temporizador = null;

    function enviarVinculo(rota, linha, pastaId, trocarDe) {
        formVinculo.action = modal.dataset['url' + rota].replace('%id%', linha.dataset.id);
        formVinculo.querySelector('[name="numero"]').value = linha.dataset.numero;
        formVinculo.querySelector('[name="pasta_id"]').value = pastaId;
        formVinculo.querySelector('[name="trocar_da_pasta_id"]').value = trocarDe || '';
        formVinculo.submit();
    }

    function desvincular(linha) {
        var c = texto('confirma-desvincular', { processo: linha.dataset.numero, n: linha.dataset.pastaNup });
        if (window.confirm(c)) { enviarVinculo('Desvincular', linha, linha.dataset.pastaId, ''); }
    }

    function fecharModal() {
        modal.hidden = true;
        alvo = null;
    }

    function mostrarPastas(pastas) {
        var lista = modal.querySelector('[data-pp-modal-lista]');
        lista.textContent = '';
        if (!pastas.length) {
            var vazio = document.createElement('div');
            vazio.className = 'pp-modal__vazio';
            vazio.textContent = modal.dataset.tVazio;
            lista.appendChild(vazio);
            return;
        }
        pastas.forEach(function (p) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'pp-modal__pasta';
            b.innerHTML = '<i class="bi bi-folder2"></i><span></span><em></em>';
            b.querySelector('span').textContent = p.texto;
            b.querySelector('em').textContent = modal.dataset.tVincular;
            b.addEventListener('click', function () { enviarVinculo('Vincular', alvo.linha, p.id, alvo.trocarDe); });
            lista.appendChild(b);
        });
    }

    function buscarPastas() {
        var q = modal.querySelector('[data-pp-modal-q]').value;
        window.fetch(modal.dataset.urlBusca + '?q=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(mostrarPastas)
            .catch(function () { mostrarPastas([]); });
    }

    function abrirModal(linha, trocarDe) {
        alvo = { linha: linha, trocarDe: trocarDe || '' };
        modal.querySelector('[data-pp-modal-numero]').textContent = linha.dataset.numero;
        var q = modal.querySelector('[data-pp-modal-q]');
        q.value = '';
        modal.hidden = false;
        q.focus();
        buscarPastas();
    }

    function fecharMenu() {
        var m = document.querySelector('[data-pp-ctx]');
        if (m) { m.remove(); }
    }

    function itensDePasta(linha) {
        if (!linha.dataset.pastaId) {
            return [{ r: raiz.dataset.tMenuVincular, ic: 'bi-link-45deg', ir: function () { abrirModal(linha); } }];
        }
        return [
            { r: texto('menu-abrir-pasta', { n: linha.dataset.pastaNup }), ic: 'bi-folder2-open', ir: function () { window.location.href = linha.dataset.pastaUrl; } },
            { r: raiz.dataset.tMenuTrocar, ic: 'bi-arrow-left-right', ir: function () { abrirModal(linha, linha.dataset.pastaId); } },
            { r: raiz.dataset.tMenuDesvincular, ic: 'bi-scissors', cor: '#9a5b00', ir: function () { desvincular(linha); } }
        ];
    }

    function abrirMenu(evento, linha) {
        evento.preventDefault();
        fecharMenu();
        var lida = linha.dataset.lida === '1';
        var itens = [
            { r: raiz.dataset.tMenuAbrir, ic: 'bi-box-arrow-up-right', ir: function () { window.location.href = linha.dataset.url; } },
            { r: lida ? raiz.dataset.tMenuNaoLida : raiz.dataset.tMenuLida, ic: lida ? 'bi-envelope' : 'bi-envelope-open', ir: function () { enviar('leitura', [linha.dataset.id], lida ? '0' : '1'); } },
            { sep: true }
        ].concat(itensDePasta(linha)).concat([
            { sep: true },
            { r: raiz.dataset.tMenuCopiar, ic: 'bi-copy', ir: function () { if (navigator.clipboard) { navigator.clipboard.writeText(linha.dataset.numero); } aviso(texto('copiado')); } },
            { sep: true },
            { r: raiz.dataset.tMenuExcluir, ic: 'bi-trash3', cor: '#a3232b', ir: function () { excluirUma(linha); } }
        ]);
        var cobertura = document.createElement('div');
        cobertura.className = 'pp-ctx__fundo';
        cobertura.setAttribute('data-pp-ctx', '');
        var menu = document.createElement('div');
        menu.className = 'pp-ctx';
        menu.style.left = Math.min(evento.clientX, window.innerWidth - 270) + 'px';
        menu.style.top = Math.min(evento.clientY, window.innerHeight - 240) + 'px';
        var titulo = document.createElement('div');
        titulo.className = 'pp-ctx__titulo';
        titulo.textContent = linha.dataset.numero + ' · ' + linha.dataset.data;
        menu.appendChild(titulo);
        itens.forEach(function (i) {
            if (i.sep) { var s = document.createElement('div'); s.className = 'pp-ctx__sep'; menu.appendChild(s); return; }
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'pp-ctx__item';
            if (i.cor) { b.style.color = i.cor; }
            var ic = document.createElement('i');
            ic.className = 'bi ' + i.ic;
            b.appendChild(ic);
            b.appendChild(document.createTextNode(i.r));
            b.addEventListener('click', function () { fecharMenu(); i.ir(); });
            menu.appendChild(b);
        });
        cobertura.appendChild(menu);
        cobertura.addEventListener('click', fecharMenu);
        cobertura.addEventListener('contextmenu', function (e) { e.preventDefault(); fecharMenu(); });
        document.body.appendChild(cobertura);
    }

    linhas.forEach(function (linha) {
        linha.querySelector('[data-pp-marca]').addEventListener('click', function (e) {
            e.stopPropagation();
            selecionadas[linha.dataset.id] = !selecionadas[linha.dataset.id];
            pintar();
        });
        linha.addEventListener('click', function (e) {
            if (e.target.closest('a, button')) { return; }
            window.location.href = linha.dataset.url;
        });
        linha.addEventListener('contextmenu', function (e) { abrirMenu(e, linha); });
        var vincular = linha.querySelector('[data-pp-vincular]');
        var separar = linha.querySelector('[data-pp-desvincular]');
        if (vincular) { vincular.addEventListener('click', function (e) { e.stopPropagation(); abrirModal(linha); }); }
        if (separar) { separar.addEventListener('click', function (e) { e.stopPropagation(); desvincular(linha); }); }
    });

    modal.addEventListener('click', function (e) { if (e.target.closest('[data-pp-modal-fechar]')) { fecharModal(); } });
    modal.querySelector('[data-pp-modal-q]').addEventListener('input', function () {
        window.clearTimeout(temporizador);
        temporizador = window.setTimeout(buscarPastas, 250);
    });

    botaoTodas.addEventListener('click', function () {
        var todas = ids().length === linhas.length;
        linhas.forEach(function (l) { selecionadas[l.dataset.id] = !todas; });
        pintar();
    });

    raiz.querySelector('[data-pp-acao-lote="leitura"]').addEventListener('click', function () { enviar('leitura', ids(), '1'); });
    raiz.querySelector('[data-pp-acao-lote="excluir"]').addEventListener('click', function () { excluirLote(ids()); });
    raiz.querySelector('[data-pp-acao-lote="limpar"]').addEventListener('click', limpar);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { fecharMenu(); fecharModal(); } });
    pintar();
})();
