/* =============================================================================
   Painel "Documentos sugeridos" da aba Documentos da pasta
   (templates/pasta/_documentos_sugeridos.html.twig), dentro do cartão do checklist.

   - "Sugerir documentos" (no cabeçalho do checklist) abre e fecha o painel; o X fecha.
     Nasce fechado, como no desenho.
   - "Atualizar" recarrega a página na aba Documentos: as regras rodam no servidor a cada
     abertura, então recarregar É recalcular. Nada de cache no navegador.
   - "Organização sugerida para esta fase" abre e fecha a lista de pastas (só mostra).
   - "+ Checklist" e "Adicionar N faltante(s)" gravam pelo endpoint que o checklist
     já usa (POST /pasta/{id}/checklist, campos `titulo` e `_token`), UM item por
     requisição, em série. Ao fim, recarrega a página na aba Documentos: o checklist
     e as sugestões voltam do servidor, sem duplicar aqui a montagem da lista.
   - Erro de um item para a fila e mostra a mensagem do servidor; o que já foi
     gravado fica gravado (e aparece no recarregamento).
   - "Origem: …" de um item exigido pelo juízo abre o teor da publicação na aba Push.
   ============================================================================= */
(function () {
    'use strict';

    var painel = document.getElementById('documentosSugeridos');
    if (!painel) { return; }

    var botao     = document.getElementById('btnDocumentosSugeridos');
    var fechar    = document.getElementById('btnDocumentosSugeridosFechar');
    var atualizar = document.getElementById('btnDocumentosSugeridosAtualizar');
    var orgBotao  = document.getElementById('btnDocumentosSugeridosOrganizacao');
    var orgLista  = document.getElementById('documentosSugeridosPastas');
    var erro      = document.getElementById('documentosSugeridosErro');
    var url       = painel.getAttribute('data-url-adicionar');
    var token     = painel.getAttribute('data-csrf');

    function abrirPainel(abrir) {
        painel.classList.toggle('d-none', !abrir);
        if (botao) {
            botao.setAttribute('aria-expanded', abrir ? 'true' : 'false');
            botao.classList.toggle('active', abrir);
        }
    }

    if (botao) {
        botao.addEventListener('click', function () {
            abrirPainel(painel.classList.contains('d-none'));
        });
    }

    if (fechar) {
        fechar.addEventListener('click', function () {
            abrirPainel(false);
            if (botao) { botao.focus(); }
        });
    }

    if (atualizar) {
        atualizar.addEventListener('click', function () {
            atualizar.disabled = true;
            recarregarNaAbaDocumentos();
        });
    }

    if (orgBotao && orgLista) {
        orgBotao.addEventListener('click', function () {
            var abrir = orgLista.hidden;
            orgLista.hidden = !abrir;
            orgBotao.setAttribute('aria-expanded', abrir ? 'true' : 'false');
            var seta = orgBotao.querySelector('.bi');
            if (seta) { seta.className = 'bi ' + (abrir ? 'bi-chevron-down' : 'bi-chevron-right'); }
        });
    }

    function mostrarErro(texto) {
        if (!erro) { return; }
        erro.textContent = texto;
        erro.classList.remove('d-none');
    }

    function adicionar(titulo) {
        var fd = new FormData();
        fd.append('titulo', titulo);
        fd.append('_token', token);

        return fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (dados) {
                if (!r.ok || !dados.ok) {
                    throw new Error(dados.erro || 'Não foi possível adicionar "' + titulo + '" ao checklist.');
                }
                return dados;
            });
        });
    }

    function recarregarNaAbaDocumentos() {
        if (window.location.hash !== '#documentos') {
            window.location.hash = 'documentos';
        }
        window.location.reload();
    }

    function adicionarEmSerie(titulos, gatilho) {
        if (!titulos.length) { return; }
        if (gatilho) { gatilho.disabled = true; }
        if (erro) { erro.classList.add('d-none'); }

        var gravados = 0;
        var fila = titulos.reduce(function (anterior, titulo) {
            return anterior.then(function () {
                return adicionar(titulo).then(function () { gravados++; });
            });
        }, Promise.resolve());

        fila.then(recarregarNaAbaDocumentos).catch(function (e) {
            mostrarErro(e.message);
            if (gravados > 0) {
                // O que já entrou no checklist tem de aparecer: recarrega depois de dar tempo de ler.
                setTimeout(recarregarNaAbaDocumentos, 2500);
                return;
            }
            if (gatilho) { gatilho.disabled = false; }
        });
    }

    // "Origem: Decisão de dd/mm/aaaa" (dc L2149, `abrirOrigem`): vai para a aba Push e abre o teor
    // daquela publicação no acordeão que já existe (o cabeçalho `.ps-push-cab` carrega o
    // `_push_teor`). Sem o acordeão na página, segue o link (aba Push da pasta).
    function abrirOrigem(link, ev) {
        var id        = link.getAttribute('data-push-id');
        var gatilho   = document.getElementById('push-tab');
        var cabecalho = id ? document.querySelector('.ps-push-cab[aria-controls="push-teor-' + id + '"]') : null;
        if (!gatilho || !cabecalho || !window.bootstrap) { return; }

        ev.preventDefault();

        function abrirTeor() {
            if (cabecalho.getAttribute('aria-expanded') !== 'true') { cabecalho.click(); }
            cabecalho.scrollIntoView({ block: 'start', behavior: 'smooth' });
            cabecalho.focus({ preventScroll: true });
        }

        if (gatilho.classList.contains('active')) {
            abrirTeor();
            return;
        }
        gatilho.addEventListener('shown.bs.tab', abrirTeor, { once: true });
        bootstrap.Tab.getOrCreateInstance(gatilho).show();
    }

    painel.addEventListener('click', function (ev) {
        var origem = ev.target.closest('.ds-sug-origem');
        if (origem && painel.contains(origem)) {
            abrirOrigem(origem, ev);
            return;
        }

        var um = ev.target.closest('.ds-sug-add-um');
        if (um && painel.contains(um)) {
            adicionarEmSerie([um.getAttribute('data-titulo')], um);
            return;
        }

        var todos = ev.target.closest('.ds-sug-add-todos');
        if (todos && painel.contains(todos)) {
            var titulos = [];
            try { titulos = JSON.parse(todos.getAttribute('data-titulos') || '[]'); } catch (e) { titulos = []; }
            adicionarEmSerie(titulos, todos);
        }
    });
}());
