/**
 * Busca da pasta no modal "Vincular uma pasta existente" da judicialização.
 *
 * Substituiu um <select> com TODAS as pastas do escritório (1.099 em produção), rotuladas só pelo
 * número. O que se digita vai ao servidor (`cobranca_pastas_buscar`); o id escolhido entra no campo
 * oculto que o formulário envia.
 *
 * Os nomes das pastas são dado do usuário: entram por `textContent`, NUNCA por innerHTML.
 *
 * Também alterna os dois blocos do modal conforme o rádio: criar mostra nome/ação, vincular mostra a
 * busca. É realce PROGRESSIVO — o HTML entregue não esconde nada, então sem este script o modal
 * continua funcionando com os dois blocos à vista, como antes. Esconder no servidor deixaria o
 * caminho de vincular dependendo de JavaScript para existir.
 */
(function () {
    'use strict';

    // ── Alternar os blocos conforme o modo escolhido ──────────────────────────
    var radios = document.querySelectorAll('input[name="judicializar_caso[modo]"]');
    var blocos = document.querySelectorAll('[data-modo-bloco]');
    var separador = document.querySelector('[data-modo-separador]');

    if (radios.length && blocos.length) {
        var aplicarModo = function () {
            var marcado = document.querySelector('input[name="judicializar_caso[modo]"]:checked');
            var modo = marcado ? marcado.value : 'criar';

            blocos.forEach(function (bloco) {
                bloco.hidden = bloco.dataset.modoBloco !== modo;
            });

            // A divisória separava os dois blocos; com um só à vista ela não separa nada.
            if (separador) separador.hidden = true;
        };

        radios.forEach(function (radio) { radio.addEventListener('change', aplicarModo); });
        aplicarModo();
    }

    // ── Busca da pasta ────────────────────────────────────────────────────────
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
        // `checked` por script NÃO dispara `change`, e sem o evento os blocos ficariam no modo velho.
        var radioVincular = document.querySelector('input[name="judicializar_caso[modo]"][value="vincular"]');
        if (radioVincular && !radioVincular.checked) {
            radioVincular.checked = true;
            radioVincular.dispatchEvent(new Event('change', { bubbles: true }));
        }
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
