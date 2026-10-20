/*
 * Contribua: cartão pelo Pagar.me no formulário de assinatura de espaço (desenho 08, checkout "Pagamento
 * seguro", linhas 165-167 do markup e 260-273 do script).
 *
 * O Pagar.me não tem campo em iframe: os campos do cartão ficam nesta página e o navegador os manda direto
 * ao Pagar.me (POST /core/v5/tokens?appId=<chave pública>, só com Content-Type, sem a chave secreta). O
 * número, a validade e o CVV nunca passam pelo BlueJus: o servidor recebe só o token_..., válido por 60 s,
 * que vira o card_token de GatewayPagarme::assinarComToken. O domínio do BlueJus precisa estar cadastrado
 * no painel do Pagar.me. Doc: https://docs.pagar.me/reference/criar-token-cartão-1
 *
 * Como os campos são nossos, seguem o desenho: máscara do número, selo da bandeira, borda vermelha quando o
 * Luhn falha, nome em caixa alta, validade MM/AA e a grade Validade/CVV/CPF. O Luhn e a bandeira são só
 * conferência visual no navegador; quem aceita ou recusa o cartão é o Pagar.me. Nada do cartão vai para
 * log, console, dataset, armazenamento do navegador ou atributo do DOM: o valor mora só no próprio input.
 *
 * O CPF/CNPJ do titular é do checkout (contribua-checkout.js, [data-cartao-documento]): o template o deixa
 * dentro do elemento de montagem e este módulo só o leva para a terceira coluna da grade, devolvendo-o ao
 * desmontar. O valor e a máscara dele continuam com o checkout.
 *
 * Uso (o mesmo formato nos três gateways de cartão):
 *   await ContribuaPagarme.montar(elemento, { chavePublica, valorCentavos, nome, email });
 *   const token = await ContribuaPagarme.tokenizar();   // rejeita com Error de mensagem pronta para a tela
 *   await ContribuaPagarme.concluirAcao(segredo);        // nada a fazer no Pagar.me
 *   ContribuaPagarme.desmontar();
 *   ContribuaPagarme.validar();   // opcional no contrato: 1ª mensagem de erro do cartão ou null
 *
 * validar() é extensão compatível: o checkout o chama, se existir, antes de abrir o pedido no servidor,
 * para o pedido não nascer com cartão digitado errado. A tokenizar() confere de novo.
 */
(function () {
    'use strict';

    const ENDERECO = 'https://api.pagar.me/core/v5/tokens';

    /* Mensagens do desenho (coPagar, linha 273), literais, uma por campo. */
    const ERROS = {
        numero: 'Número do cartão inválido.',
        nome: 'Informe o nome como está no cartão.',
        validade: 'Validade inválida ou cartão vencido.',
        cvv: 'Informe o código de segurança (CVV).',
    };

    /*
     * Bandeiras do desenho (linhas 260 e 269) com o rótulo do selo. Elo e Hipercard vêm antes de Visa e Mastercard
     * porque parte das faixas da Elo começa com 4 (438935, 451416, 4576, 4011) e cairia como Visa.
     */
    const BANDEIRAS = [
        ['elo', 'ELO', /^(636368|438935|504175|451416|636297|5067|4576|4011)/],
        ['hipercard', 'HIPERCARD', /^(606282|3841)/],
        ['visa', 'VISA', /^4/],
        ['mastercard', 'MASTERCARD', /^(5[1-5]|2[2-7])/],
        ['amex', 'AMEX', /^3[47]/],
    ];

    const soDigitos = (texto) => String(texto || '').replace(/\D/g, '');

    function bandeira(digitos) {
        const achada = BANDEIRAS.find(([, , faixa]) => faixa.test(digitos));

        return achada ? { codigo: achada[0], rotulo: achada[1] } : null;
    }

    /* Luhn do desenho (linha 261): soma com os dígitos alternados dobrados, a partir do último. */
    function luhn(digitos) {
        let soma = 0;
        let alterna = false;
        for (let i = digitos.length - 1; i >= 0; i--) {
            let d = +digitos[i];
            if (alterna) {
                d *= 2;
                if (d > 9) {
                    d -= 9;
                }
            }
            soma += d;
            alterna = !alterna;
        }

        return digitos.length >= 13 && soma % 10 === 0;
    }

    /* Número em grupos de 4 para todas as bandeiras, até 19 dígitos (linha 269; o Amex de 15 fica 4-4-4-3). */
    const mascararNumero = (valor) => soDigitos(valor).slice(0, 19).replace(/(\d{4})(?=\d)/g, '$1 ');

    /* Validade MM/AA (linha 270): a barra entra depois do segundo dígito. */
    const mascararValidade = (valor) => soDigitos(valor).slice(0, 4).replace(/^(\d{2})(\d)/, '$1/$2');

    /* CVV só com dígitos, até 4 (linha 270). */
    const mascararCvv = (valor) => soDigitos(valor).slice(0, 4);

    let raiz = null;
    let chavePublica = '';
    let documento = null;

    function entrada(nome, atributos) {
        const campo = document.createElement('input');
        campo.id = 'contribua-pagarme-' + nome;
        campo.dataset.cartao = nome;
        Object.entries(atributos).forEach(([k, v]) => campo.setAttribute(k, v));

        return campo;
    }

    /* Rótulo do desenho: o texto em maiúscula com o campo dentro (label.co-campo). */
    function rotulo(texto, conteudo) {
        const caixa = document.createElement('label');
        caixa.className = 'co-campo';
        caixa.append(texto, conteudo);

        return caixa;
    }

    function ao(campo, mascara, depois) {
        campo.addEventListener('input', () => {
            if (mascara) {
                campo.value = mascara(campo.value);
            }
            campo.removeAttribute('aria-invalid');
            if (depois) {
                depois();
            }
        });
    }

    function montar(elemento, opcoes) {
        desmontar();
        chavePublica = opcoes.chavePublica;
        raiz = elemento;
        const doTemplate = raiz.querySelector('[data-cartao-documento]');
        documento = doTemplate ? doTemplate.closest('label') : null;

        const numero = entrada('numero', { inputmode: 'numeric', autocomplete: 'cc-number', maxlength: '23', placeholder: '0000 0000 0000 0000' });
        const selo = document.createElement('span');
        selo.className = 'co-bandeira';
        selo.hidden = true;
        const caixaNumero = document.createElement('span');
        caixaNumero.className = 'co-cartao-numero';
        caixaNumero.append(numero, selo);
        ao(numero, mascararNumero, () => {
            const digitos = soDigitos(numero.value);
            const achada = bandeira(digitos);
            selo.hidden = !achada;
            selo.className = 'co-bandeira' + (achada ? ' co-bandeira--' + achada.codigo : '');
            selo.textContent = achada ? achada.rotulo : '';
            caixaNumero.classList.toggle('co-cartao-numero--invalido', digitos.length >= 13 && !luhn(digitos));
        });

        // Começa vazio, como no desenho: o nome do escritório não é o nome impresso no cartão.
        const nome = entrada('nome', { autocomplete: 'cc-name', maxlength: '64' });
        ao(nome, (v) => v.toUpperCase());

        const validade = entrada('validade', { inputmode: 'numeric', autocomplete: 'cc-exp', maxlength: '5', placeholder: 'MM/AA' });
        ao(validade, mascararValidade);

        const cvv = entrada('cvv', { type: 'password', inputmode: 'numeric', autocomplete: 'cc-csc', maxlength: '4', placeholder: '•••' });
        ao(cvv, mascararCvv);

        const grade = document.createElement('div');
        grade.className = 'co-cartao-grade';
        grade.append(rotulo('VALIDADE', validade), rotulo('CVV', cvv));
        if (documento) {
            grade.append(documento);
        }

        raiz.replaceChildren(
            rotulo('NÚMERO DO CARTÃO', caixaNumero),
            rotulo('NOME IMPRESSO NO CARTÃO', nome),
            grade,
        );

        return Promise.resolve();
    }

    /* Recusa no campo: marca, põe o foco nele e devolve a mensagem do desenho para o checkout mostrar. */
    function recusar(nome) {
        const campo = raiz.querySelector('[data-cartao="' + nome + '"]');
        campo.setAttribute('aria-invalid', 'true');
        campo.focus();

        return new Error(ERROS[nome]);
    }

    /* As conferências do desenho (coPagar), na ordem dele: número, nome, validade e CVV. */
    function lerCartao() {
        const valor = (nome) => raiz.querySelector('[data-cartao="' + nome + '"]').value.trim();
        const numero = soDigitos(valor('numero'));
        if (!luhn(numero)) {
            throw recusar('numero');
        }
        const nome = valor('nome').toUpperCase();
        if (nome.split(/\s+/).length < 2) {
            throw recusar('nome');
        }
        const [mm, aa] = valor('validade').split('/');
        const mes = parseInt(mm, 10);
        const ano = parseInt(aa, 10);
        // Vale até o último dia do mês impresso: vencido a partir do 1º dia do mês seguinte.
        if (!/^\d{2}$/.test(mm || '') || !/^\d{2}$/.test(aa || '') || mes < 1 || mes > 12 || new Date(2000 + ano, mes, 1) <= new Date()) {
            throw recusar('validade');
        }
        const cvv = valor('cvv');
        if (!/^\d{3,4}$/.test(cvv)) {
            throw recusar('cvv');
        }

        return { number: numero, holder_name: nome, exp_month: mes, exp_year: ano, cvv: cvv };
    }

    /* Confere o cartão sem tokenizar: a primeira mensagem do desenho (com foco no campo) ou null. */
    function validar() {
        if (!raiz) {
            return null;
        }
        try {
            lerCartao();
        } catch (erro) {
            return erro.message;
        }

        return null;
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

    /* Apaga os campos do cartão; o CPF/CNPJ do checkout volta para o elemento de montagem. */
    function desmontar() {
        if (raiz) {
            raiz.replaceChildren(...(documento ? [documento] : []));
        }
        raiz = null;
        chavePublica = '';
        documento = null;
    }

    window.ContribuaPagarme = { montar, tokenizar, concluirAcao, desmontar, validar };
})();
