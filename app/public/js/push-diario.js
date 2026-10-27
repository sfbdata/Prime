/* Aba "Diário da OAB" do Push: modal de registro, texto expansível, seleção em lote e leitura (desenho 11). */
(function () {
    'use strict';
    var raiz = document.querySelector('[data-pp-diario]');
    var modal = document.querySelector('[data-pp-dr]');
    var abrir = document.querySelector('[data-pp-dr-abrir]');

    if (modal && abrir) {
        var fechar = function () { modal.hidden = true; };
        abrir.addEventListener('click', function () {
            modal.hidden = false;
            modal.querySelector('input[name="data"]').focus();
        });
        modal.querySelectorAll('[data-pp-dr-fechar]').forEach(function (b) { b.addEventListener('click', fechar); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) { fechar(); } });
    }
    if (!raiz) { return; }

    var form = raiz.querySelector('[data-pp-diario-lote]');
    var itens = Array.prototype.slice.call(raiz.querySelectorAll('[data-pp-diario-item]'));
    var selecionadas = {};
    var grupo = raiz.querySelector('[data-pp-sel-grupo]');
    var contador = raiz.querySelector('[data-pp-sel-n]');
    var botaoTodas = raiz.querySelector('[data-pp-selecionar-todas]');

    function texto(chave, trocas) {
        var t = raiz.getAttribute('data-t-' + chave) || '';
        Object.keys(trocas || {}).forEach(function (k) { t = t.split('%' + k + '%').join(trocas[k]); });
        return t;
    }

    function ids() {
        return itens.filter(function (i) { return selecionadas[i.dataset.id]; }).map(function (i) { return i.dataset.id; });
    }

    function pintar() {
        var n = ids().length;
        itens.forEach(function (i) {
            var marcada = !!selecionadas[i.dataset.id];
            var botao = i.querySelector('[data-pp-marca]');
            i.classList.toggle('is-sel', marcada);
            botao.setAttribute('aria-pressed', marcada ? 'true' : 'false');
            botao.querySelector('i').className = 'bi ' + (marcada ? 'bi-check-square-fill' : 'bi-square');
        });
        grupo.hidden = n === 0;
        contador.textContent = texto('n-selecionadas', { n: n });
        botaoTodas.querySelector('i').className = 'bi ' + (n && n === itens.length ? 'bi-check-square-fill' : n ? 'bi-dash-square' : 'bi-square');
    }

    function enviar(rota, lista, lida) {
        form.action = raiz.getAttribute('data-url-leitura').replace(/leitura$/, rota);
        lista.forEach(function (id) {
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = 'ids[]'; i.value = id;
            form.appendChild(i);
        });
        if (lida !== undefined) { form.querySelector('[name="lida"]').value = lida; }
        form.submit();
    }

    function confirmarComPalavra(confirmacao, pergunta) {
        if (!window.confirm(confirmacao)) { return false; }
        var digitado = window.prompt(pergunta, '');
        if (String(digitado || '').trim().toUpperCase() !== 'EXCLUIR') { window.ppToast(texto('cancelada')); return false; }
        return true;
    }

    function limpar() {
        var n = parseInt(raiz.getAttribute('data-total-lidas'), 10) || 0;
        if (!n) { window.ppToast(texto('sem-lidas')); return; }
        if (confirmarComPalavra(texto('confirma-limpar', { n: n }), texto('prompt-limpar', { n: n }))) { enviar('limpar-lidas', []); }
    }

    function excluir(lista) {
        var n = lista.length;
        if (confirmarComPalavra(texto('confirma-excluir-lote', { n: n }), texto('prompt-excluir-lote', { n: n }))) { enviar('excluir', lista); }
    }

    function marcarLidaNaTela(item) {
        item.querySelector('[data-pp-diario-linha]').classList.remove('is-nova');
    }

    function lerAoAbrir(item) {
        var linha = item.querySelector('[data-pp-diario-linha]');
        if (!linha.classList.contains('is-nova')) { return; }
        var dados = new window.FormData(form);
        dados.delete('voltar');
        dados.append('ids[]', item.dataset.id);
        window.fetch(raiz.getAttribute('data-url-leitura'), { method: 'POST', body: dados, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j) { return; }
                marcarLidaNaTela(item);
                raiz.setAttribute('data-total-lidas', (parseInt(raiz.getAttribute('data-total-lidas'), 10) || 0) + 1);
                var emblema = document.querySelector('[data-pp-aba="diario"] .pp-aba__n');
                if (emblema) { emblema.textContent = emblema.textContent.replace(/\d+/, j.naoLidas); }
            })
            .catch(function () {});
    }

    itens.forEach(function (item) {
        var linha = item.querySelector('[data-pp-diario-linha]');
        linha.addEventListener('click', function (e) {
            if (e.target.closest('input, a, button')) { return; }
            var t = item.querySelector('[data-pp-diario-texto]');
            t.hidden = !t.hidden;
            if (!t.hidden) { lerAoAbrir(item); }
        });
        item.querySelector('[data-pp-marca]').addEventListener('click', function () {
            selecionadas[item.dataset.id] = !selecionadas[item.dataset.id];
            pintar();
        });
    });

    botaoTodas.addEventListener('click', function () {
        var todas = ids().length === itens.length;
        itens.forEach(function (i) { selecionadas[i.dataset.id] = !todas; });
        pintar();
    });

    raiz.querySelectorAll('[data-pp-acao]').forEach(function (b) {
        b.addEventListener('click', function () {
            var acao = b.getAttribute('data-pp-acao');
            if (acao === 'limpar') { limpar(); return; }
            var lista = ids();
            if (!lista.length) { return; }
            if (acao === 'excluir') { excluir(lista); } else { enviar('leitura', lista, '1'); }
        });
    });
    pintar();
}());
