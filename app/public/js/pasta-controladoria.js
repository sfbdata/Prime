/* =============================================================================
   pasta-controladoria.js: botão "Controladoria" e painel de alertas da pasta (desenho 02 VS 31.10.26).
   Busca os alertas ao abrir a pasta (para o contador do botão) e mostra o painel ao clicar.
   ============================================================================= */
(function () {
    'use strict';

    var botao = document.querySelector('.js-ctl-abrir');
    var fundo = document.querySelector('.js-ctl-fundo');
    if (!botao || !fundo) { return; }
    var lista = fundo.querySelector('.js-ctl-lista');
    var total = fundo.querySelector('.js-ctl-total');
    var contador = botao.querySelector('.ctl-btn-n');

    function criar(tag, classe, texto) {
        var el = document.createElement(tag);
        if (classe) { el.className = classe; }
        if (texto) { el.textContent = texto; }
        return el;
    }

    function item(a) {
        var caixa = criar('div', 'ctl-item');
        var topo = criar('div', 'ctl-item-topo');
        caixa.setAttribute('data-nivel', a.nivel);
        topo.append(criar('b', '', a.titulo), criar('span', 'ctl-item-nivel', lista.getAttribute('data-' + a.nivel)));
        caixa.append(topo, criar('div', 'ctl-item-detalhe', a.detalhe), criar('div', 'ctl-item-sugestao', '→ ' + a.sugestao));
        return caixa;
    }

    function mostrar(dados) {
        lista.replaceChildren();
        if (!dados.alertas.length) {
            var vazio = criar('div', 'ctl-vazio', lista.getAttribute('data-vazio'));
            vazio.prepend(criar('i', 'bi bi-check2-circle'));
            lista.append(vazio);
        } else {
            dados.alertas.forEach(function (a) { lista.append(item(a)); });
        }
        total.textContent = String(dados.alertas.length);
        total.hidden = !dados.alertas.length;
        contador.textContent = dados.urgentes ? String(dados.urgentes) : '';
        contador.hidden = !dados.urgentes;
    }

    function carregar() {
        fetch(botao.getAttribute('data-url'), { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.json(); })
            .then(mostrar)
            .catch(function () { lista.textContent = lista.getAttribute('data-erro'); });
    }

    function fechar() { fundo.hidden = true; }
    botao.addEventListener('click', function () { fundo.hidden = false; carregar(); });
    fundo.addEventListener('click', function (e) { if (e.target === fundo || e.target.closest('.js-ctl-fechar')) { fechar(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !fundo.hidden) { fechar(); } });
    carregar();
}());
