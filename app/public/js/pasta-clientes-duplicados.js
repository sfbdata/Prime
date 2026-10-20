/* =============================================================================
   pasta-clientes-duplicados.js: mantém o selo "CPF duplicado" de TODAS as linhas de Clientes da pasta depois
   de vincular ou desvincular sem recarregar (desenho 02 VS 18.10.26, L1509; o protótipo recalcula a cada
   render, L6527-6536).

   Quem decide na carga é o servidor (`ClientesDuplicadosDaPasta`, pelo `_cliente_linha.html.twig`). Mas o
   XHR de vincular devolve só a linha NOVA e o de desvincular só tira uma: o gêmeo que já estava na tela
   ficaria sem o selo (ou com um selo que não vale mais) até o F5. Aqui só se repete a mesma regra (mesmos
   dígitos em outra linha da lista) sobre o que o servidor pôs em cada linha (`data-doc-chave`, um HMAC dos dígitos,
   `data-doc-tipo`), com o MESMO texto, title e classe do partial. DOM só com createElement/textContent.
   ============================================================================= */
(function () {
    'use strict';

    var lista = document.getElementById('clientesList');
    if (!lista) { return; }

    function seloPara(tipo) {
        var rotulo = tipo === 'cnpj' ? 'CNPJ' : 'CPF';
        var selo = document.createElement('span');
        selo.className = 'ps-selo-duplicado';
        selo.title = 'Outro cliente vinculado tem o mesmo ' + rotulo;
        selo.textContent = rotulo + ' duplicado';

        return selo;
    }

    function recalcular() {
        var linhas = Array.prototype.slice.call(lista.querySelectorAll('.cliente-linha[data-cliente-id]'));
        var contagem = {};
        linhas.forEach(function (l) {
            var d = l.getAttribute('data-doc-chave') || '';
            if (d !== '') { contagem[d] = (contagem[d] || 0) + 1; }
        });

        linhas.forEach(function (l) {
            var nome = l.querySelector('.cliente-nome');
            if (!nome) { return; }
            var d = l.getAttribute('data-doc-chave') || '';
            var deve = d !== '' && contagem[d] > 1;
            var atual = nome.querySelector(':scope > .ps-selo-duplicado');
            if (!deve) {
                if (atual) { atual.remove(); }
                return;
            }
            if (atual) { return; }
            // Antes do "Principal" (L1509-1510), como no partial.
            var principal = nome.querySelector(':scope > .ps-selo-principal');
            nome.insertBefore(seloPara(l.getAttribute('data-doc-tipo')), principal);
        });
    }

    // Só mexe quando a lista muda; o recálculo é idempotente (a segunda volta não altera nada).
    var agendado = false;
    new MutationObserver(function () {
        if (agendado) { return; }
        agendado = true;
        window.requestAnimationFrame(function () { agendado = false; recalcular(); });
    }).observe(lista, { childList: true, subtree: true });
}());
