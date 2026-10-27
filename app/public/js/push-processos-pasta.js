/* Aba "Processos da OAB" do Push: botão Pasta, modal "Vincular a uma pasta" e menu do botão direito (desenho 11). */
(function () {
    'use strict';
    var raiz = document.querySelector('[data-pp-processos]');
    var modal = document.querySelector('[data-pp-modal-pasta]');
    var formVinculo = document.querySelector('[data-pp-vinculo-form]');
    if (!raiz || !modal || !formVinculo) { return; }

    var alvo = null;
    var temporizador = null;

    function texto(chave, trocas) {
        var t = raiz.getAttribute('data-t-' + chave) || '';
        Object.keys(trocas || {}).forEach(function (k) { t = t.split('%' + k + '%').join(trocas[k]); });
        return t;
    }

    function enviar(rota, linha, pastaId, trocarDe, nup) {
        formVinculo.action = modal.dataset['url' + rota];
        formVinculo.querySelector('[name="numero"]').value = linha.dataset.numero;
        formVinculo.querySelector('[name="pasta_id"]').value = pastaId;
        formVinculo.querySelector('[name="nup"]').value = nup || '';
        formVinculo.querySelector('[name="trocar_da_pasta_id"]').value = trocarDe || '';
        formVinculo.submit();
    }

    function desvincular(linha) {
        var c = texto('confirma-desvincular', { processo: linha.dataset.numeroExibicao, n: linha.dataset.pastaNup });
        if (window.confirm(c)) { enviar('Desvincular', linha, linha.dataset.pastaId, ''); }
    }

    function fecharModal() {
        modal.hidden = true;
        alvo = null;
    }

    function mostrarAtalhoDoNumero(pastas) {
        var botao = modal.querySelector('[data-pp-modal-usar]');
        var n = modal.querySelector('[data-pp-modal-q]').value.replace(/\D/g, '');
        var existe = pastas.some(function (p) { return String(p.nup) === String(parseInt(n, 10)) || String(p.nup) === n; });
        botao.hidden = !(parseInt(n, 10) > 0 && !existe);
        botao.dataset.nup = n;
        botao.textContent = modal.dataset.tVincularN.split('%n%').join(n);
    }

    function mostrarPastas(pastas) {
        mostrarAtalhoDoNumero(pastas);
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
            b.addEventListener('click', function () { enviar('Vincular', alvo.linha, p.id, alvo.trocarDe); });
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
        modal.querySelector('[data-pp-modal-numero]').textContent = linha.dataset.numeroExibicao;
        modal.querySelector('[data-pp-modal-partes]').textContent = linha.dataset.partes || modal.dataset.tSemPartes;
        var q = modal.querySelector('[data-pp-modal-q]');
        q.value = '';
        modal.hidden = false;
        q.focus();
        buscarPastas();
    }

    modal.addEventListener('click', function (e) {
        if (e.target.closest('[data-pp-modal-fechar]')) { fecharModal(); return; }
        var usar = e.target.closest('[data-pp-modal-usar]');
        if (usar && alvo) { enviar('Vincular', alvo.linha, '', alvo.trocarDe, usar.dataset.nup); return; }
        if (e.target.closest('[data-pp-modal-nova]') && alvo) {
            window.location.href = modal.dataset.urlNovaPasta + '#nova-pasta=' + encodeURIComponent(alvo.linha.dataset.numero) +
                '&acao=' + encodeURIComponent(modal.dataset.urlVincular) + '&token=' + encodeURIComponent(modal.dataset.token);
        }
    });
    modal.querySelector('[data-pp-modal-q]').addEventListener('input', function () {
        window.clearTimeout(temporizador);
        temporizador = window.setTimeout(buscarPastas, 250);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) { fecharModal(); } });

    function fecharMenu() {
        var m = document.querySelector('[data-pp-ctx]');
        if (m) { m.remove(); }
    }

    function itensDePasta(linha) {
        if (!linha.dataset.pastaId) {
            return [{ r: texto('vincular'), ic: 'bi-link-45deg', ir: function () { abrirModal(linha); } }];
        }
        return [
            { r: texto('abrir-pasta', { n: linha.dataset.pastaNup }), ic: 'bi-folder2-open', ir: function () { window.location.href = linha.dataset.pastaUrl; } },
            { r: texto('trocar'), ic: 'bi-arrow-left-right', ir: function () { abrirModal(linha, linha.dataset.pastaId); } },
            { r: texto('desvincular'), ic: 'bi-scissors', cor: '#9a5b00', ir: function () { desvincular(linha); } }
        ];
    }

    function abrirMenu(evento, linha) {
        evento.preventDefault();
        fecharMenu();
        var itens = [{ r: texto('ver-publicacoes'), ic: 'bi-broadcast', ir: function () { window.location.href = linha.dataset.publicacoesUrl; } }]
            .concat(itensDePasta(linha));
        var editar = linha.querySelector('[data-pp-editar]');
        itens.push({ sep: true });
        if (editar) { itens.push({ r: texto('editar-obs'), ic: 'bi-pencil', ir: function () { editar.click(); } }); }
        itens.push({
            r: texto('copiar'), ic: 'bi-copy',
            ir: function () { if (navigator.clipboard) { navigator.clipboard.writeText(linha.dataset.numeroExibicao); } window.ppToast(texto('copiado')); }
        });

        var cobertura = document.createElement('div');
        cobertura.className = 'pp-ctx__fundo';
        cobertura.setAttribute('data-pp-ctx', '');
        var menu = document.createElement('div');
        menu.className = 'pp-ctx';
        menu.style.left = Math.min(evento.clientX, window.innerWidth - 270) + 'px';
        menu.style.top = Math.min(evento.clientY, window.innerHeight - 240) + 'px';
        var titulo = document.createElement('div');
        titulo.className = 'pp-ctx__titulo';
        titulo.textContent = linha.dataset.numeroExibicao;
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

    raiz.querySelectorAll('[data-pp-linha-proc]').forEach(function (linha) {
        linha.addEventListener('contextmenu', function (e) { abrirMenu(e, linha); });
        var vincular = linha.querySelector('[data-pp-vincular-proc]');
        if (vincular) { vincular.addEventListener('click', function (e) { e.stopPropagation(); abrirModal(linha); }); }
    });
}());
