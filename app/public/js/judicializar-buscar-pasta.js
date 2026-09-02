/**
 * Busca da pasta no modal "Vincular uma pasta existente" da judicialização.
 *
 * Substituiu um <select> com TODAS as pastas do escritório (1.099 em produção), rotuladas só pelo
 * número. O que se digita vai ao servidor (`cobranca_pastas_buscar`); o id escolhido entra no campo
 * oculto que o formulário envia.
 *
 * Os nomes das pastas são dado do usuário: entram por `textContent`, NUNCA por innerHTML.
 */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-buscar-pasta]');
    if (!raiz) return;

    var campo      = raiz.querySelector('[data-buscar-pasta-campo]');
    var lista      = raiz.querySelector('[data-buscar-pasta-lista]');
    var escolhida  = raiz.querySelector('[data-buscar-pasta-escolhida]');
    var alvo       = document.querySelector('[data-buscar-pasta-alvo]');
    var url        = raiz.dataset.url;
    if (!campo || !lista || !alvo || !url) return;

    var timer = null;
    var requisicaoAtual = 0;

    function limparLista() {
        lista.textContent = '';
        lista.classList.add('d-none');
    }

    function escolher(id, texto) {
        alvo.value = id;
        campo.value = '';
        limparLista();
        if (escolhida) {
            escolhida.textContent = 'Pasta escolhida: ' + texto;
            escolhida.hidden = false;
        }
        // Escolher a pasta implica querer vincular: marca o rádio para quem clicou aqui direto.
        var radioVincular = document.querySelector('input[name="judicializar_caso[modo]"][value="vincular"]');
        if (radioVincular) radioVincular.checked = true;
    }

    function desenhar(resultados) {
        lista.textContent = '';

        if (!resultados.length) {
            var vazio = document.createElement('div');
            vazio.className = 'list-group-item text-secondary small';
            vazio.textContent = 'Nenhuma pasta encontrada.';
            lista.appendChild(vazio);
            lista.classList.remove('d-none');
            return;
        }

        resultados.forEach(function (item) {
            var botao = document.createElement('button');
            botao.type = 'button';
            botao.className = 'list-group-item list-group-item-action';
            botao.textContent = item.text;
            botao.addEventListener('click', function () { escolher(item.id, item.text); });
            lista.appendChild(botao);
        });

        lista.classList.remove('d-none');
    }

    function buscar(termo) {
        var minha = ++requisicaoAtual;

        fetch(url + '?q=' + encodeURIComponent(termo), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(function (r) { return r.ok ? r.json() : { results: [] }; })
            // Respostas fora de ordem: só a ÚLTIMA busca desenha. Sem isto, digitar rápido faz uma
            // resposta antiga sobrescrever a nova.
            .then(function (dados) { if (minha === requisicaoAtual) desenhar(dados.results || []); })
            .catch(function () { if (minha === requisicaoAtual) limparLista(); });
    }

    campo.addEventListener('input', function () {
        var termo = campo.value.trim();
        window.clearTimeout(timer);

        if (termo.length < 2) {
            limparLista();
            return;
        }

        timer = window.setTimeout(function () { buscar(termo); }, 250);
    });

    // Enter no campo de busca não pode enviar o formulário: a pessoa ainda não escolheu a pasta.
    campo.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') e.preventDefault();
    });
})();
