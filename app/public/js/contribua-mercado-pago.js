/*
 * Contribua: cartão pelo Mercado Pago no formulário de assinatura de espaço (desenho 08).
 *
 * O número, a validade e o CVV ficam no Card Payment Brick (campos seguros do próprio Mercado Pago) e nunca
 * passam pelo BlueJus: o servidor recebe só o token do cartão, que vira o card_token_id da assinatura em
 * GatewayMercadoPago::assinarComToken. O botão do Brick fica escondido: quem envia é o botão do BlueJus.
 * Doc: https://www.mercadopago.com.br/developers/pt/docs/checkout-bricks/card-payment-brick/default-rendering
 *
 * Uso (o mesmo formato nos três gateways de cartão):
 *   await ContribuaMercadoPago.montar(elemento, { chavePublica, valorCentavos, nome, email });
 *   const token = await ContribuaMercadoPago.tokenizar();   // rejeita com Error de mensagem pronta para a tela
 *   await ContribuaMercadoPago.concluirAcao(segredo);        // nada a fazer no Mercado Pago
 *   ContribuaMercadoPago.desmontar();
 */
(function () {
    'use strict';

    const SCRIPT = 'https://sdk.mercadopago.com/js/v2';
    let controle = null;

    function carregarScript() {
        if (window.MercadoPago) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            const tag = document.createElement('script');
            tag.src = SCRIPT;
            tag.onload = () => resolve();
            tag.onerror = () => reject(new Error('Não foi possível carregar o formulário de cartão. Tente de novo em instantes.'));
            document.head.appendChild(tag);
        });
    }

    async function montar(elemento, opcoes) {
        desmontar();
        await carregarScript();
        if (!elemento.id) {
            elemento.id = 'contribua-mercado-pago-' + Date.now();
        }
        const mp = new window.MercadoPago(opcoes.chavePublica, { locale: 'pt-BR' });

        await new Promise((resolve, reject) => {
            mp.bricks().create('cardPayment', elemento.id, {
                initialization: { amount: opcoes.valorCentavos / 100, payer: { email: opcoes.email || '' } },
                customization: {
                    visual: { hidePaymentButton: true },
                    paymentMethods: { maxInstallments: 1, types: { included: ['credit_card'] } },
                },
                callbacks: {
                    onReady: () => resolve(),
                    onSubmit: () => Promise.resolve(),
                    onError: (erro) => {
                        if (erro && erro.type === 'critical') {
                            reject(new Error('Não foi possível abrir o formulário de cartão. Tente de novo em instantes.'));
                        }
                    },
                },
            }).then((criado) => { controle = criado; }, () => reject(new Error('Não foi possível abrir o formulário de cartão. Tente de novo em instantes.')));
        });
    }

    async function tokenizar() {
        if (!controle) {
            throw new Error('O formulário de cartão ainda não está pronto.');
        }
        let dados = null;
        try {
            dados = await controle.getFormData();
        } catch (erro) {
            throw new Error('Confira os dados do cartão.');
        }
        if (!dados || !dados.token) {
            throw new Error('Confira os dados do cartão.');
        }

        return dados.token;
    }

    /* O Mercado Pago não pede ação no navegador depois do token: a autenticação, se houver, é no Brick. */
    function concluirAcao() {
        return Promise.resolve();
    }

    function desmontar() {
        if (controle) {
            controle.unmount();
        }
        controle = null;
    }

    window.ContribuaMercadoPago = { montar, tokenizar, concluirAcao, desmontar };
})();
