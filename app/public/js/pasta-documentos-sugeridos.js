/* =============================================================================
   Painel "Documentos sugeridos" da aba Documentos da pasta
   (templates/pasta/_documentos_sugeridos.html.twig).

   - "Sugerir documentos" abre e fecha o painel (nasce fechado, como no desenho).
   - "+ Checklist" e "Adicionar N faltante(s)" gravam pelo endpoint que o checklist
     já usa (POST /pasta/{id}/checklist, campos `titulo` e `_token`), UM item por
     requisição, em série. Ao fim, recarrega a página na aba Documentos: o checklist
     e as sugestões voltam do servidor, sem duplicar aqui a montagem da lista.
   - Erro de um item para a fila e mostra a mensagem do servidor; o que já foi
     gravado fica gravado (e aparece no recarregamento).
   ============================================================================= */
(function () {
    'use strict';

    var painel = document.getElementById('documentosSugeridos');
    if (!painel) { return; }

    var botao = document.getElementById('btnDocumentosSugeridos');
    var corpo = document.getElementById('documentosSugeridosCorpo');
    var erro  = document.getElementById('documentosSugeridosErro');
    var url   = painel.getAttribute('data-url-adicionar');
    var token = painel.getAttribute('data-csrf');

    if (botao && corpo) {
        botao.addEventListener('click', function () {
            var aberto = !corpo.classList.contains('d-none');
            corpo.classList.toggle('d-none', aberto);
            botao.setAttribute('aria-expanded', aberto ? 'false' : 'true');
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

    painel.addEventListener('click', function (ev) {
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
