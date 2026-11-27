/* Painel do Master: as configurações que ficavam na página Contribua (pedido do Farlei, lote 2, L2-M5, 27/11/2026).
   O modal "Pagamento por cartão · só Master" (antes no contribua-checkout.js) e o "Liberar" / "Recusar" dos pedidos de
   espaço (antes no contribua.js), com as mesmas rotas e o mesmo CSRF do Contribua. A integração bancária continua no
   contribua-cora.js e o modo campanha no contribua-campanha.js. Depois de gravar, a página recarrega na mesma seção
   (o hash #sec=... fica) e o aviso aparece no toast. */
(function () {
    'use strict';

    const raiz = document.querySelector('[data-mp-contribua]');
    if (!raiz) {
        return;
    }

    const $ = (sel, base) => (base || document).querySelector(sel);
    const $$ = (sel, base) => Array.from((base || document).querySelectorAll(sel));
    const ERRO = 'Não foi possível concluir. Tente de novo.';
    const CHAVE_DO_TOAST = 'mp-contribua-toast';

    function enviar(url, campos) {
        const corpo = new FormData();
        corpo.append('_token', raiz.dataset.csrf);
        Object.entries(campos || {}).forEach(([k, v]) => {
            if (v && typeof v === 'object') {
                Object.entries(v).forEach(([kk, vv]) => corpo.append(k + '[' + kk + ']', vv));
            } else {
                corpo.append(k, v);
            }
        });

        return fetch(url, { method: 'POST', body: corpo, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
            .then((r) => r.json().catch(() => ({ ok: false, erro: ERRO })));
    }

    let tempoToast = null;
    function avisar(texto) {
        const toast = $('[data-toast]');
        if (!toast) {
            return;
        }
        $('span', toast).textContent = texto;
        toast.hidden = false;
        clearTimeout(tempoToast);
        tempoToast = setTimeout(() => { toast.hidden = true; }, 3200);
    }

    function recarregarCom(mensagem) {
        sessionStorage.setItem(CHAVE_DO_TOAST, mensagem || '');
        location.reload();
    }

    /* Pagamento por cartão: gateway, chave pública, links dos pacotes, carência e "Confirmar pagamento" das últimas assinaturas. */
    function iniciarPagamento() {
        const master = $('[data-pagamento-master]');
        const abrir = $('[data-abrir-master]', raiz);
        if (!master || !abrir) {
            return;
        }
        const semLink = (master.dataset.semLink || '').split(',');
        const seletor = $('[data-master-gateway]', master);
        const mostrarLinks = () => {
            const hospedado = semLink.includes(seletor.value);
            $('[data-master-links]', master).hidden = hospedado;
            $('[data-intro-links]', master).hidden = hospedado;
            $('[data-intro-hospedado]', master).hidden = !hospedado;
        };
        const erro = (texto) => { const c = $('[data-master-erro]', master); $('span', c).textContent = texto || ''; c.hidden = !texto; };
        const fechar = () => { master.hidden = true; abrir.focus(); };

        abrir.addEventListener('click', () => { mostrarLinks(); erro(''); master.hidden = false; });
        $$('[data-fechar-master]', master).forEach((b) => b.addEventListener('click', fechar));
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !master.hidden) { fechar(); } });
        seletor.addEventListener('change', mostrarLinks);
        $('[data-master-copiar]', master).addEventListener('click', () => {
            const texto = $('[data-master-retorno]', master).value;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(texto);
            }
            avisar('Página de retorno copiada.');
        });
        $('[data-master-salvar]', master).addEventListener('click', () => {
            const links = {};
            $$('[data-master-link]', master).forEach((c) => { links[c.dataset.masterLink] = c.value.trim(); });
            enviar(master.dataset.urlSalvar, { gateway: seletor.value, chavePublica: $('[data-master-chave]', master).value.trim(), carencia: $('[data-master-carencia]', master).value, links: links })
                .then((d) => (d.ok ? recarregarCom(d.mensagem) : erro(d.erro)));
        });
        $$('[data-confirmar-pedido]', master).forEach((b) => b.addEventListener('click', () => {
            b.disabled = true;
            enviar(master.dataset.urlConfirmar.replace(/\/0\/confirmar$/, '/' + b.dataset.confirmarPedido + '/confirmar'), {})
                .then((d) => (d.ok ? recarregarCom(d.mensagem) : (b.disabled = false, erro(d.erro))));
        }));
    }

    /* Pedidos de espaço: "Liberar opções de compra" e "Recusar". */
    function iniciarPedidos() {
        const botoes = $$('[data-pedido-decidir]', raiz);
        botoes.forEach((b) => b.addEventListener('click', () => {
            botoes.forEach((x) => { x.disabled = true; });
            enviar(b.dataset.url).then((d) => {
                if (!d.ok) {
                    botoes.forEach((x) => { x.disabled = false; });
                    avisar(d.erro || ERRO);

                    return;
                }
                recarregarCom(d.mensagem);
            });
        }));
    }

    iniciarPagamento();
    iniciarPedidos();

    const pendente = sessionStorage.getItem(CHAVE_DO_TOAST);
    if (pendente) {
        sessionStorage.removeItem(CHAVE_DO_TOAST);
        avisar(pendente);
    }
})();
