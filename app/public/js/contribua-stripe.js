/*
 * Contribua: cartão pelo Stripe no formulário de assinatura de espaço (desenho 08).
 *
 * O número, a validade e o CVV ficam no Payment Element do Stripe (iframe do próprio Stripe) e nunca
 * passam pelo BlueJus: o servidor recebe só o id do PaymentMethod (pm_...), que vai para
 * GatewayStripe::assinarComToken. Doc: https://docs.stripe.com/payments/accept-a-payment-deferred
 * e https://docs.stripe.com/js/payment_methods/create_payment_method.
 *
 * Uso (o mesmo formato nos três gateways de cartão):
 *   await ContribuaStripe.montar(elemento, { chavePublica, valorCentavos, nome, email });
 *   const token = await ContribuaStripe.tokenizar();   // rejeita com Error de mensagem pronta para a tela
 *   ContribuaStripe.desmontar();
 */
(function () {
    'use strict';

    const SCRIPT = 'https://js.stripe.com/endive/stripe.js';
    let stripe = null;
    let elements = null;
    let pagamento = null;
    let pagador = {};

    function carregarScript() {
        if (window.Stripe) {
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
        stripe = window.Stripe(opcoes.chavePublica, { locale: 'pt-BR' });
        elements = stripe.elements({
            mode: 'subscription',
            amount: opcoes.valorCentavos,
            currency: 'brl',
            paymentMethodTypes: ['card'],
            paymentMethodCreation: 'manual',
        });
        pagamento = elements.create('payment', { layout: 'tabs', fields: { billingDetails: { name: 'auto', email: 'never' } } });
        pagamento.mount(elemento);
        pagador = { name: opcoes.nome || undefined, email: opcoes.email || undefined };
    }

    async function tokenizar() {
        if (!stripe || !elements) {
            throw new Error('O formulário de cartão ainda não está pronto.');
        }
        const conferido = await elements.submit();
        if (conferido.error) {
            throw new Error(conferido.error.message || 'Confira os dados do cartão.');
        }
        const criado = await stripe.createPaymentMethod({ elements, params: { billing_details: pagador } });
        if (criado.error) {
            throw new Error(criado.error.message || 'O cartão não foi aceito. Confira os dados ou use outro cartão.');
        }

        return criado.paymentMethod.id;
    }

    function desmontar() {
        if (pagamento) {
            pagamento.destroy();
        }
        stripe = null;
        elements = null;
        pagamento = null;
    }

    window.ContribuaStripe = { montar, tokenizar, desmontar };
})();
