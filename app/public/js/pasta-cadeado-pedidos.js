/* Pedidos de acesso pendentes da pasta com cadeado (spec pasta-cadeado K5), para quem configura.
   PROVISÓRIO, a confirmar pelo dono: no desenho a decisão é na conversa com a BlueJus IA. Por isso este
   módulo é isolado: não depende do pasta-cadeado.js e só chama a rota `pasta_acesso_decidir` (JSON em XHR),
   a mesma que outro front (o chat) chamaria. Sem JS, cada botão é um formulário comum. */
(function () {
    'use strict';

    function avisar(texto) {
        if (window.avisarPs) { window.avisarPs(texto); return; }
        window.alert(texto);
    }

    document.addEventListener('submit', function (e) {
        var form = e.target.closest && e.target.closest('[data-cadeado-pedidos] form.js-cadeado-decidir');
        if (!form) { return; }
        e.preventDefault();

        var faixa = form.closest('[data-cadeado-pedidos]');
        var pedido = form.closest('.js-cadeado-pedido');
        fetch(form.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (dados) { return { ok: r.ok, dados: dados }; });
        }).then(function (r) {
            avisar(r.dados.mensagem || (r.ok ? 'Pedido decidido' : 'Não foi possível decidir o pedido'));
            if (!r.ok) { return; }
            if (pedido) { pedido.remove(); }
            if (faixa && !faixa.querySelector('.js-cadeado-pedido')) { faixa.remove(); }
        }, function () { avisar('Falha de conexão ao decidir o pedido'); });
    });

    // Chegou pelo aviso do pedido (`#cadeado-pedidos`): a faixa já está na tela; só rola até ela.
    if (window.location.hash === '#cadeado-pedidos') {
        var faixa = document.getElementById('cadeado-pedidos');
        if (faixa) { faixa.scrollIntoView({ block: 'center' }); }
    }
}());
