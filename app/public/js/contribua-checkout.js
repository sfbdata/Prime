/* Contribua C3: checkout "Pagamento seguro" e modal "Pagamento por cartão · só Master" do desenho 08
   (coPagar, coFechar, gwLinks, gwPed). O cartão nunca vai ao BlueJus: no modo formulário o campo seguro do
   gateway é montado pelo módulo dele (contribua-<gateway>.js, contrato com a sessão 4b:
   montar(elemento, {chavePublica, valorCentavos, nome, email}), tokenizar(), concluirAcao(segredo),
   desmontar()) e só o token sobe; no modo hospedado o pagador digita o cartão na página do gateway. */
(function () {
    'use strict';

    const raiz = document.querySelector('[data-contribua]');
    const modal = document.querySelector('[data-checkout]');
    if (!raiz || !modal) {
        return;
    }

    const $ = (sel, base) => (base || document).querySelector(sel);
    const $$ = (sel, base) => Array.from((base || document).querySelectorAll(sel));
    const soDigitos = (t) => String(t || '').replace(/\D/g, '');
    const MODULOS = { stripe: 'ContribuaStripe', mercado_pago: 'ContribuaMercadoPago', pagarme: 'ContribuaPagarme' };
    let plano = null;
    let processando = false;
    let montado = null;

    const modulo = () => window[MODULOS[modal.dataset.gateway]] || null;

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
            .then((r) => r.json().catch(() => ({ ok: false, erro: 'Não foi possível concluir. Tente de novo.' })));
    }

    function erro(texto) {
        const caixa = $('[data-co-erro]', modal);
        $('span', caixa).textContent = texto || '';
        caixa.hidden = !texto;
    }

    function botaoProcessando(ligado) {
        processando = ligado;
        const botao = $('[data-co-pagar]', modal);
        botao.disabled = ligado;
        botao.style.opacity = ligado ? '.7' : '1';
        $('[data-co-botao-texto]', modal).textContent = ligado ? 'Processando com segurança…' : 'Assinar por ' + plano.preco + '/mês';
    }

    function abrir(botao) {
        plano = { codigo: botao.dataset.pedirPlano, nome: botao.dataset.planoNome, gb: +botao.dataset.planoGb, hoje: +botao.dataset.planoHoje, preco: botao.dataset.planoPreco, centavos: +botao.dataset.planoCentavos };
        $('[data-co-add]', modal).textContent = String(plano.gb - plano.hoje);
        $('[data-co-de]', modal).textContent = String(plano.hoje);
        $('[data-co-gb]', modal).textContent = plano.gb + ' GB';
        $$('[data-co-preco]', modal).forEach((el) => { el.textContent = plano.preco; });
        const largura = Math.round(plano.hoje / plano.gb * 100) + '%';
        $('[data-co-largura]', modal).style.width = largura;
        $('[data-co-largura-novo]', modal).style.left = largura;
        mostrarSucesso(false);
        erro('');
        botaoProcessando(false);
        modal.hidden = false;
        montarCampoSeguro();
    }

    /* O campo do cartão é do gateway (iframe/SDK oficial): montado ao abrir, desmontado ao fechar */
    function montarCampoSeguro() {
        const elemento = $('[data-co-elemento]', modal);
        if (!elemento) {
            return;
        }
        const m = modulo();
        if (!m || !modal.dataset.chavePublica) {
            erro('O pagamento por cartão deste gateway ainda não está disponível.');
            $('[data-co-pagar]', modal).disabled = true;
            return;
        }
        montado = m;
        Promise.resolve(m.montar(elemento, { chavePublica: modal.dataset.chavePublica, valorCentavos: plano.centavos, nome: modal.dataset.pagadorNome, email: modal.dataset.pagadorEmail }))
            .catch((e) => erro(e.message || 'Não foi possível abrir o formulário do cartão.'));
    }

    function mostrarSucesso(ligado) {
        $('[data-co-ok]', modal).hidden = !ligado;
        $('[data-co-form]', modal).hidden = ligado;
    }

    function fechar() {
        if (processando) {
            return;
        }
        modal.hidden = true;
        if (montado) {
            montado.desmontar();
            montado = null;
        }
        if (raiz.dataset.retorno === '1') {
            raiz.dataset.retorno = '0';
            history.replaceState(null, '', location.pathname + '#espaco');
        }
    }

    function documento() {
        return soDigitos($('[data-cartao-documento]', modal).value);
    }

    function pagar() {
        if (![11, 14].includes(documento().length)) {
            erro('Informe o CPF ou CNPJ do titular.');
            return;
        }
        erro('');
        botaoProcessando(true);
        enviar(modal.dataset.urlIniciar, { plano: plano.codigo, documento: documento() })
            .then((d) => {
                if (!d.ok) {
                    throw new Error(d.erro);
                }
                if (d.modo === 'redirecionar') {
                    processando = false;
                    location.href = d.url;
                    return null;
                }

                return tokenizarEAssinar(d);
            })
            .catch((e) => {
                botaoProcessando(false);
                erro(e.message || 'Não foi possível concluir. Tente de novo.');
            });
    }

    /* Token do campo seguro → assinatura no servidor → 3-D Secure no navegador, se o banco pedir */
    function tokenizarEAssinar(inicio) {
        if (!montado) {
            throw new Error('O pagamento por cartão deste gateway ainda não está disponível.');
        }

        return Promise.resolve(montado.tokenizar())
            .then((token) => enviar(modal.dataset.urlToken.replace(/\/0\/token$/, '/' + inicio.pedido + '/token'), { token: token, documento: documento() }))
            .then((d) => {
                if (!d.ok) {
                    throw new Error(d.erro);
                }

                return d.acaoNoNavegador ? Promise.resolve(montado.concluirAcao(d.acaoNoNavegador)).then(() => d) : d;
            })
            .then((d) => {
                botaoProcessando(false);
                $('[data-co-ok-gb]', modal).textContent = plano.gb + ' GB';
                $('[data-co-ok-proxima]', modal).textContent = d.proxima;
                mostrarSucesso(true);
            });
    }

    /* Máscaras do desenho (msk): número em blocos de 4, validade MM/AA, CVV e documento só dígitos */
    function mascarar(sel, fn) {
        const campo = $(sel, modal);
        if (campo) {
            campo.addEventListener('input', () => { campo.value = fn(campo.value); erro(''); });
        }
    }
    mascarar('[data-cartao-documento]', (v) => soDigitos(v).slice(0, 14));

    $$('[data-pedir-plano]').forEach((b) => b.addEventListener('click', () => {
        if (!b.disabled && raiz.dataset.cartaoLiberado === '1') {
            abrir(b);
        }
    }));
    $$('[data-fechar-checkout]', modal).forEach((b) => b.addEventListener('click', fechar));
    $('[data-co-pagar]', modal).addEventListener('click', pagar);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.hidden) {
            fechar();
        }
    });

    /* Voltou do gateway (?assinatura=ok): abre direto no "Pagamento enviado" */
    if (raiz.dataset.retorno === '1') {
        plano = { preco: '' };
        mostrarSucesso(true);
        modal.hidden = false;
    }

    iniciarModalDoMaster();

    function iniciarModalDoMaster() {
        const master = document.querySelector('[data-pagamento-master]');
        const abrirMaster = document.querySelector('[data-abrir-master]');
        if (!master || !abrirMaster) {
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
        const erroMaster = (texto) => { const c = $('[data-master-erro]', master); $('span', c).textContent = texto || ''; c.hidden = !texto; };

        abrirMaster.addEventListener('click', () => { mostrarLinks(); erroMaster(''); master.hidden = false; });
        $$('[data-fechar-master]', master).forEach((b) => b.addEventListener('click', () => { master.hidden = true; }));
        seletor.addEventListener('change', mostrarLinks);
        $('[data-master-copiar]', master).addEventListener('click', () => {
            const texto = $('[data-master-retorno]', master).value;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(texto);
            }
            avisarNaPagina('Página de retorno copiada.');
        });
        $('[data-master-salvar]', master).addEventListener('click', () => {
            const links = {};
            $$('[data-master-link]', master).forEach((c) => { links[c.dataset.masterLink] = c.value.trim(); });
            enviar(master.dataset.urlSalvar, { gateway: seletor.value, chavePublica: $('[data-master-chave]', master).value.trim(), carencia: $('[data-master-carencia]', master).value, links: links })
                .then((d) => (d.ok ? recarregarCom(d.mensagem) : erroMaster(d.erro)));
        });
        $$('[data-confirmar-pedido]', master).forEach((b) => b.addEventListener('click', () => {
            b.disabled = true;
            enviar(master.dataset.urlConfirmar.replace(/\/0\/confirmar$/, '/' + b.dataset.confirmarPedido + '/confirmar'), {})
                .then((d) => (d.ok ? recarregarCom(d.mensagem) : (b.disabled = false, erroMaster(d.erro))));
        }));
    }

    function recarregarCom(mensagem) {
        sessionStorage.setItem('ct-toast', mensagem);
        location.reload();
    }

    function avisarNaPagina(texto) {
        const toast = document.querySelector('[data-toast]');
        if (!toast) {
            return;
        }
        $('span', toast).textContent = texto;
        toast.hidden = false;
        setTimeout(() => { toast.hidden = true; }, 3200);
    }
})();
