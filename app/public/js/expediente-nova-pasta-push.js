/* Vem do Push Processual (desenho 11, "Abrir nova pasta" do modal Vincular): abre a Nova Pasta do Expediente e,
   depois de criada, vincula o processo à pasta nova. Contexto no hash: #nova-pasta=<processo>&token=<csrf> e
   &publicacao=<id> (aba Publicações) ou &acao=<url do POST> (aba Processos da OAB). */
(function () {
    'use strict';

    var partes = {};
    window.location.hash.replace(/^#/, '').split('&').forEach(function (par) {
        var i = par.indexOf('=');
        if (i > 0) { partes[par.slice(0, i)] = decodeURIComponent(par.slice(i + 1)); }
    });
    var acao = partes.acao || (partes.publicacao ? '/push-processual/publicacoes/' + encodeURIComponent(partes.publicacao) + '/vincular' : '');
    if (!partes['nova-pasta'] || !acao || !partes.token) { return; }

    function abrir() {
        var modal = document.getElementById('modalNovaPasta');
        if (!modal || !window.bootstrap) { return; }
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    document.addEventListener('exp:pasta-criada', function (e) {
        var nup = e.detail && e.detail.nup;
        if (!nup) { return; }
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = acao;
        [['_token', partes.token], ['nup', nup], ['numero', partes['nova-pasta']], ['voltar', '/push-processual']].forEach(function (c) {
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = c[0]; i.value = c[1];
            form.appendChild(i);
        });
        document.body.appendChild(form);
        form.submit();
    }, { once: true });

    if (document.readyState === 'complete') { abrir(); } else { window.addEventListener('load', abrir); }
})();
