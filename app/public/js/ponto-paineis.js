/**
 * Painéis das abas de gestão da tela do Ponto (docs/specs/ponto-desenho-0811.md, E5 e E6): "Equipe" e
 * "Configurações" vêm vazios do servidor, com o endereço em `data-carregar`, e são buscados na primeira vez que a aba
 * abre. A Equipe calcula o saldo de cada pessoa; buscar só quando abre poupa a /ponto, que é buscada de novo depois
 * de cada batida.
 *
 * Ouve `ponto:aba-aberta` (js/ponto-abas.js) e, ao iniciar, carrega o painel que já veio aberto (`?aba=equipe`).
 * A busca tem prazo próprio, como toda busca da tela (PontoPaineisJsTest). O comportamento de cada
 * painel mora no arquivo dele (js/ponto-equipe.js, js/ponto-configuracoes.js), por delegação no documento, e ouve
 * `ponto:painel-carregado` quando precisa preparar algo depois que o painel chega.
 *
 * Exposto em `window.PontoPaineis` (`recarregar` busca o painel de novo, por exemplo depois de uma gravação;
 * `recarregarAbertos` busca de novo o painel `data-recarregar-ao-abrir` aberto, depois da batida).
 */
(function () {
    'use strict';

    // Sem resposta até aqui, o painel avisa e deixa tentar de novo.
    const CARREGAR_PRAZO_MS = 20000;
    const TEXTO_CARREGANDO = 'Carregando...';
    const TEXTO_FALHA = 'Não foi possível carregar esta aba.';
    const TEXTO_TENTAR = 'Tentar de novo';

    function aviso(classe, papel, texto) {
        const caixa = document.createElement('div');
        caixa.className = classe;
        caixa.setAttribute('role', papel);
        const frase = document.createElement('span');
        frase.textContent = texto;
        caixa.appendChild(frase);

        return caixa;
    }

    async function carregar(painel, deNovo) {
        if (!painel || !painel.dataset.carregar) {
            return;
        }
        const estado = painel.dataset.estado;
        // Um "buscar de novo" no meio de outra busca (a batida, a troca de página da Auditoria) não se perde: fica
        // guardado e roda quando esta terminar, com o `data-carregar` de agora (vale o pedido mais novo).
        if (estado === 'carregando') {
            if (deNovo) {
                painel.dataset.deNovo = '1';
            }
            return;
        }
        if (estado === 'pronto' && !deNovo) {
            return;
        }

        painel.dataset.estado = 'carregando';
        painel.setAttribute('aria-busy', 'true');
        if (estado !== 'pronto') {
            painel.replaceChildren(aviso('pe-painel-carregando', 'status', TEXTO_CARREGANDO));
        }

        const aborto = new AbortController();
        const prazo = setTimeout(function () { aborto.abort(); }, CARREGAR_PRAZO_MS);
        try {
            const resposta = await fetch(painel.dataset.carregar, {
                headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: aborto.signal,
            });
            // Redirecionamento (sessão sem escritório, vínculo suspenso) traz a página inteira: é falha, como na batida.
            if (!resposta.ok || resposta.redirected) {
                throw new Error('HTTP ' + resposta.status);
            }
            const html = await resposta.text();
            painel.innerHTML = html;
            painel.dataset.estado = 'pronto';
            painel.dispatchEvent(new CustomEvent('ponto:painel-carregado', { bubbles: true, detail: { painel: painel } }));
        } catch (erro) {
            painel.dataset.estado = 'erro';
            const falha = aviso('pe-painel-falha', 'alert', TEXTO_FALHA);
            const tentar = document.createElement('button');
            tentar.type = 'button';
            tentar.className = 'pe-painel-tentar';
            tentar.setAttribute('data-painel-tentar', '');
            tentar.textContent = TEXTO_TENTAR;
            falha.appendChild(tentar);
            painel.replaceChildren(falha);
        } finally {
            clearTimeout(prazo);
            painel.removeAttribute('aria-busy');
            if (painel.dataset.deNovo === '1') {
                delete painel.dataset.deNovo;
                carregar(painel, true);
            }
        }
    }

    // O painel com `data-recarregar-ao-abrir` (a Auditoria, E9) é buscado de novo cada vez que a aba abre: o evento de
    // uma ação feita em outra aba (escala, chave, sede, justificativa excluída) aparece, como o `reg` do desenho (L452).
    // Com a busca em andamento (a da entrada com `?aba=auditoria`), abrir não guarda outra: ela já traz o de agora.
    document.addEventListener('ponto:aba-aberta', function (evento) {
        const painel = evento.detail && evento.detail.painel;
        const deNovo = Boolean(painel && painel.hasAttribute('data-recarregar-ao-abrir') && painel.dataset.estado !== 'carregando');
        carregar(painel, deNovo);
    });

    // Depois de uma ação já gravada (index.html.twig, `atualizarTelaDoPonto`, quando a busca da tela termina):
    // o painel desses que está aberto é buscado de novo, sem o "Carregando..." (o conteúdo fica até a resposta chegar).
    // No meio de uma busca, a nova fica guardada para depois dela. Com falha, fica o "Tentar de novo". Fechado, a próxima
    // abertura busca.
    function recarregarAbertos() {
        document.querySelectorAll('section.pe-abas [data-recarregar-ao-abrir]:not([hidden])').forEach(function (painel) {
            if (painel.dataset.estado === 'pronto' || painel.dataset.estado === 'carregando') {
                carregar(painel, true);
            }
        });
    }

    document.addEventListener('click', function (evento) {
        const botao = evento.target instanceof Element ? evento.target.closest('[data-painel-tentar]') : null;
        if (botao) {
            carregar(botao.closest('[data-carregar]'), false);
        }
    });

    // Entrou com a aba de gestão aberta (`?aba=equipe`): busca o painel que já está visível.
    function iniciar() {
        document.querySelectorAll('section.pe-abas [data-carregar]:not([hidden])').forEach(function (painel) {
            carregar(painel, false);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }

    window.PontoPaineis = {
        CARREGAR_PRAZO_MS,
        recarregar: function (painel) { return carregar(painel, true); },
        recarregarAbertos,
    };
})();
