/*
 * Busca rápida da pasta (Ctrl + K). Desenho: docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS 18.10.26.dc.html,
 * janela L405-421, teclado onBuscaG L6446-6454, lógica bgVals L6455-6482.
 *
 * Ctrl/Cmd + K abre e fecha (em qualquer foco, como no desenho), Esc fecha, ↑ ↓ navegam, Enter abre, o
 * mouse seleciona, clique no fundo fecha. Os resultados vêm de GET expediente_busca_rapida?q= (até 8),
 * com espera de 200 ms depois da última tecla e a requisição anterior cancelada (AbortController).
 * O DOM é montado com createElement + textContent: nenhum dado passa por innerHTML.
 *
 * Pasta trancada, para quem não é administrador do escritório (`abreTrancada: false`), não abre: aparece a
 * dica de sigilo, a mesma regra da Lista do Expediente (expediente-lista.js, abrirOuAvisar).
 */
(function () {
    'use strict';

    var raiz = document.getElementById('pastaBuscaRapida');
    if (!raiz) { return; }

    var campo = raiz.querySelector('.pbr-campo');
    var lista = raiz.querySelector('.pbr-lista');
    var vazio = raiz.querySelector('.pbr-vazio');
    var aviso = raiz.querySelector('.pbr-aviso');
    var url = raiz.getAttribute('data-url');

    var ICONES = { Pasta: 'bi bi-folder2', Cliente: 'bi bi-person', Processo: 'bi bi-bank' };
    var ESPERA_MS = 200;

    var resultados = [];
    var selecionado = 0;
    var abreTrancada = false;
    var espera = null;
    var controle = null;

    function aberta() { return !raiz.hidden; }

    function abrir() {
        raiz.hidden = false;
        campo.value = '';
        aviso.hidden = true;
        selecionado = 0;
        campo.focus();
        buscar('');
    }

    function fechar() {
        raiz.hidden = true;
        clearTimeout(espera);
        if (controle) { controle.abort(); controle = null; }
    }

    function agendarBusca() {
        clearTimeout(espera);
        aviso.hidden = true;
        espera = setTimeout(function () { buscar(campo.value.trim()); }, ESPERA_MS);
    }

    function buscar(termo) {
        if (controle) { controle.abort(); }
        controle = new AbortController();
        fetch(url + '?q=' + encodeURIComponent(termo), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            signal: controle.signal
        }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.json();
        }).then(function (j) {
            resultados = Array.isArray(j.resultados) ? j.resultados.slice(0, 8) : [];
            abreTrancada = j.abreTrancada === true;
            selecionado = 0;
            desenhar();
        }).catch(function (e) {
            if (e && e.name === 'AbortError') { return; }
            resultados = [];
            desenhar();
        });
    }

    function desenhar() {
        lista.querySelectorAll('.pbr-item').forEach(function (b) { b.remove(); });
        resultados.forEach(function (r, k) { lista.insertBefore(item(r, k), vazio); });
        vazio.hidden = resultados.length > 0;
        marcarSelecionado();
    }

    function item(r, k) {
        var botao = document.createElement('button');
        botao.type = 'button';
        botao.className = 'pbr-item';

        var icone = document.createElement('span');
        icone.className = 'pbr-icone';
        var i = document.createElement('i');
        i.className = ICONES[r.grupo] || ICONES.Pasta;
        i.setAttribute('aria-hidden', 'true');
        icone.appendChild(i);

        var textos = document.createElement('span');
        textos.className = 'pbr-textos';
        var titulo = document.createElement('span');
        titulo.className = 'pbr-titulo';
        titulo.textContent = r.titulo || '';
        if (r.trancada) {
            var cadeado = document.createElement('i');
            cadeado.className = 'bi bi-lock pbr-cadeado';
            cadeado.setAttribute('aria-label', 'Pasta trancada');
            titulo.appendChild(cadeado);
        }
        var sub = document.createElement('span');
        sub.className = 'pbr-subtitulo';
        sub.textContent = r.subtitulo || '';
        textos.appendChild(titulo);
        textos.appendChild(sub);

        var grupo = document.createElement('span');
        grupo.className = 'pbr-grupo';
        grupo.textContent = r.grupo || '';

        botao.appendChild(icone);
        botao.appendChild(textos);
        botao.appendChild(grupo);
        botao.addEventListener('mouseenter', function () { selecionado = k; marcarSelecionado(); });
        botao.addEventListener('click', function () { ir(k); });

        return botao;
    }

    function marcarSelecionado() {
        lista.querySelectorAll('.pbr-item').forEach(function (b, k) {
            b.classList.toggle('is-selecionado', k === selecionado);
            if (k === selecionado) { b.scrollIntoView({ block: 'nearest' }); }
        });
    }

    function ir(k) {
        var r = resultados[k];
        if (!r || !r.url) { return; }
        if (r.trancada && !abreTrancada) { aviso.hidden = false; return; }
        fechar();
        window.location.href = r.url;
    }

    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            if (aberta()) { fechar(); } else { abrir(); }
            return;
        }
        if (!aberta()) { return; }
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopImmediatePropagation();
            fechar();
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            selecionado = Math.max(0, Math.min(resultados.length - 1, selecionado + 1));
            marcarSelecionado();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selecionado = Math.max(0, selecionado - 1);
            marcarSelecionado();
        } else if (e.key === 'Enter' && resultados[selecionado]) {
            e.preventDefault();
            ir(selecionado);
        }
    }, true);

    campo.addEventListener('input', agendarBusca);
    raiz.querySelector('.pbr-fundo').addEventListener('click', fechar);
})();
