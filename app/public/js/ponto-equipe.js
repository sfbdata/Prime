/**
 * Comportamento do painel da aba "Equipe" da tela do Ponto (E5 do desenho, docs/specs/ponto-desenho-0811.md). O painel
 * chega depois (js/ponto-paineis.js) e scripts dentro dele não rodam: tudo por delegação no documento.
 *
 * - Escala (P56 a): trocar a escolha grava na hora (POST com o token da página: o base.html.twig põe o X-CSRF-Token),
 *   com prazo próprio (a tela não tem `fetch` sem prazo, BatidaNaoTravaNoGpsTest). Deu certo: o aviso só para leitor
 *   de tela e a escolha passa a ser a atual. Recusa ou falta de resposta: a frase no topo do painel e a escolha volta.
 * - "Abonar selecionadas" (P57): só liga com alguma marcada; o envio é o formulário (volta na aba Equipe).
 */
(function () {
    'use strict';

    // Prazo da troca de escala: sem resposta até aqui, a escolha volta e a frase avisa.
    const ESCALA_PRAZO_MS = 15000;
    const TEXTO_SEM_RESPOSTA = 'Sem resposta do servidor. Confira a conexão e tente de novo.';
    const TEXTO_RECUSA = 'Não foi possível trocar a escala. Atualize a página e tente de novo.';

    function painelDe(elemento) {
        return elemento.closest('.pe-equipe');
    }

    function mostrarErro(painel, texto) {
        const caixa = painel ? painel.querySelector('[data-equipe-erro]') : null;
        if (caixa) {
            caixa.textContent = texto;
            caixa.hidden = texto === '';
        }
    }

    function anunciar(painel, texto) {
        const voz = painel ? painel.querySelector('[data-equipe-aviso]') : null;
        if (voz) {
            voz.textContent = texto;
        }
    }

    // A escolha atual é a opção marcada pelo servidor (defaultSelected): é para ela que a escolha volta.
    function opcaoAtual(select) {
        return Array.from(select.options).find(function (opcao) { return opcao.defaultSelected; }) || select.options[0];
    }

    function fixarAtual(select) {
        Array.from(select.options).forEach(function (opcao) {
            opcao.defaultSelected = opcao.selected;
        });
    }

    async function trocarEscala(select) {
        const painel = painelDe(select);
        const anterior = opcaoAtual(select);
        const aborto = new AbortController();
        const prazo = setTimeout(function () { aborto.abort(); }, ESCALA_PRAZO_MS);
        select.disabled = true;
        mostrarErro(painel, '');
        let falha = TEXTO_SEM_RESPOSTA;
        try {
            const resposta = await fetch(select.dataset.url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ escala: select.value }),
                signal: aborto.signal,
            });
            const dados = await resposta.json().catch(function () { return {}; });
            if (resposta.ok && dados.ok === true) {
                fixarAtual(select);
                anunciar(painel, 'Escala de ' + select.dataset.nome + ' alterada.');
                return;
            }
            falha = dados.erro || TEXTO_RECUSA;
        } catch (erro) {
            // sem rede ou prazo estourado: a frase padrão acima
        } finally {
            clearTimeout(prazo);
            select.disabled = false;
        }
        anterior.selected = true;
        anunciar(painel, '');
        mostrarErro(painel, falha);
    }

    function ligarAbonar(form) {
        const botao = form.querySelector('[data-equipe-abonar-botao]');
        if (botao) {
            botao.disabled = form.querySelector('input[name="ids[]"]:checked') === null;
        }
    }

    document.addEventListener('change', function (evento) {
        const alvo = evento.target instanceof Element ? evento.target : null;
        if (!alvo) {
            return;
        }
        if (alvo.matches('select.pe-eq-escala')) {
            trocarEscala(alvo);
            return;
        }
        const form = alvo.closest('form[data-equipe-abonar]');
        if (form && alvo.matches('input[name="ids[]"]')) {
            ligarAbonar(form);
        }
    });

    // Enviar sem nada marcado (o botão desligado não deixa; o Enter num campo, sim): não sai da tela.
    document.addEventListener('submit', function (evento) {
        const form = evento.target instanceof Element ? evento.target.closest('form[data-equipe-abonar]') : null;
        if (form && form.querySelector('input[name="ids[]"]:checked') === null) {
            evento.preventDefault();
        }
    });

    // O painel chegou (ou chegou de novo): o botão segue as marcas que vieram.
    document.addEventListener('ponto:painel-carregado', function (evento) {
        const painel = evento.detail && evento.detail.painel;
        const form = painel ? painel.querySelector('form[data-equipe-abonar]') : null;
        if (form) {
            ligarAbonar(form);
        }
    });
})();
