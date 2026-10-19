/* Contribua C3: "Cancelar assinatura" no bloco Assinatura do Meu Perfil. Confirma antes, porque cancela no
   gateway (a próxima cobrança não acontece); o espaço segue até o fim do mês pago. */
(function () {
    'use strict';

    const bloco = document.querySelector('[data-assinatura-perfil]');
    const botao = bloco && bloco.querySelector('[data-cancelar-assinatura]');
    if (!botao) {
        return;
    }

    botao.addEventListener('click', () => {
        if (!window.confirm('Cancelar a assinatura? A próxima cobrança não acontece, o espaço do plano vale até o fim do mês pago e nenhum arquivo é apagado.')) {
            return;
        }
        botao.disabled = true;
        const corpo = new FormData();
        corpo.append('_token', bloco.dataset.csrf);
        fetch(bloco.dataset.urlCancelar, { method: 'POST', body: corpo, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
            .then((r) => r.json().catch(() => ({ ok: false, erro: 'Não foi possível concluir. Tente de novo.' })))
            .then((d) => {
                const retorno = bloco.querySelector('[data-assinatura-retorno]');
                retorno.textContent = d.ok ? d.mensagem : d.erro;
                retorno.hidden = false;
                if (d.ok) {
                    botao.remove();
                } else {
                    botao.disabled = false;
                }
            });
    });
})();
