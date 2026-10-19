/*
 * Contribua: cartão pelo Pagar.me no formulário de assinatura de espaço (desenho 08).
 *
 * O Pagar.me não tem campo em iframe: os campos do cartão ficam nesta página e o navegador os manda direto
 * ao Pagar.me (POST /core/v5/tokens?appId=<chave pública>, só com Content-Type, sem a chave secreta). O
 * número, a validade e o CVV nunca passam pelo BlueJus: o servidor recebe só o token_..., válido por 60 s,
 * que vira o card_token de GatewayPagarme::assinarComToken. O domínio do BlueJus precisa estar cadastrado
 * no painel do Pagar.me. Doc: https://docs.pagar.me/reference/criar-token-cartão-1
 *
 * Uso (o mesmo formato nos três gateways de cartão):
 *   await ContribuaPagarme.montar(elemento, { chavePublica, valorCentavos, nome, email });
 *   const token = await ContribuaPagarme.tokenizar();   // rejeita com Error de mensagem pronta para a tela
 *   await ContribuaPagarme.concluirAcao(segredo);        // nada a fazer no Pagar.me
 *   ContribuaPagarme.desmontar();
 */
(function () {
    'use strict';

    const ENDERECO = 'https://api.pagar.me/core/v5/tokens';
    let raiz = null;
    let chavePublica = '';

    function campo(nome, rotulo, atributos) {
        const caixa = document.createElement('div');
        const etiqueta = document.createElement('label');
        const entrada = document.createElement('input');
        caixa.className = 'contribua-cartao-campo';
        entrada.id = 'contribua-pagarme-' + nome;
        entrada.dataset.cartao = nome;
        Object.entries(atributos).forEach(([k, v]) => entrada.setAttribute(k, v));
        etiqueta.htmlFor = entrada.id;
        etiqueta.textContent = rotulo;
        caixa.append(etiqueta, entrada);

        return caixa;
    }

    function montar(elemento, opcoes) {
        desmontar();
        chavePublica = opcoes.chavePublica;
        raiz = elemento;
        raiz.replaceChildren(
            campo('numero', 'Número do cartão', { inputmode: 'numeric', autocomplete: 'cc-number', maxlength: '23', placeholder: '0000 0000 0000 0000' }),
            campo('nome', 'Nome impresso no cartão', { autocomplete: 'cc-name', maxlength: '64', value: opcoes.nome || '' }),
            campo('validade', 'Validade', { inputmode: 'numeric', autocomplete: 'cc-exp', maxlength: '7', placeholder: 'MM/AA' }),
            campo('cvv', 'CVV', { inputmode: 'numeric', autocomplete: 'cc-csc', maxlength: '4', placeholder: '123' }),
        );

        return Promise.resolve();
    }

    function lerCartao() {
        const valor = (nome) => raiz.querySelector('[data-cartao="' + nome + '"]').value.trim();
        const numero = valor('numero').replace(/\D/g, '');
        const validade = valor('validade').replace(/\s/g, '').split('/');
        const mes = parseInt(validade[0], 10);
        const ano = parseInt(validade[1], 10);
        if (numero.length < 13 || !valor('nome') || !(mes >= 1 && mes <= 12) || !(ano >= 0) || !/^\d{3,4}$/.test(valor('cvv'))) {
            throw new Error('Confira os dados do cartão.');
        }

        return { number: numero, holder_name: valor('nome'), exp_month: mes, exp_year: ano % 100, cvv: valor('cvv') };
    }

    async function tokenizar() {
        if (!raiz) {
            throw new Error('O formulário de cartão ainda não está pronto.');
        }
        const cartao = lerCartao();
        let resposta = null;
        try {
            resposta = await fetch(ENDERECO + '?appId=' + encodeURIComponent(chavePublica), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type: 'card', card: cartao }),
            });
        } catch (erro) {
            throw new Error('Não foi possível falar com o Pagar.me. Tente de novo em instantes.');
        }
        const dados = await resposta.json().catch(() => ({}));
        if (!resposta.ok || !dados.id) {
            throw new Error('O cartão não foi aceito. Confira os dados ou use outro cartão.');
        }

        return dados.id;
    }

    /* O Pagar.me não pede ação no navegador depois do token. */
    function concluirAcao() {
        return Promise.resolve();
    }

    function desmontar() {
        if (raiz) {
            raiz.replaceChildren();
        }
        raiz = null;
        chavePublica = '';
    }

    window.ContribuaPagarme = { montar, tokenizar, concluirAcao, desmontar };
})();
