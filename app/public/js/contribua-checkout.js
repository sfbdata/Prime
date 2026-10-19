/* Contribua C3: checkout "Pagamento seguro" e modal "Pagamento por cartão · só Master" do desenho 08
   (coPagar, coFechar, gwLinks, gwPed). O cartão nunca vai ao BlueJus: no modo formulário o número segue para
   o tokenizador oficial do gateway (window.BlueJusTokenizadores[gateway], arquivo contribua-<gateway>.js) e só
   o token sobe; no modo hospedado o pagador digita o cartão na página do gateway. */
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
    let plano = null;
    let processando = false;

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

    /* Luhn e bandeira, como no desenho (luhn(), band) */
    function luhn(n) {
        if (n.length < 13) {
            return false;
        }
        let soma = 0;
        n.split('').reverse().forEach((d, i) => {
            let v = +d;
            if (i % 2) {
                v *= 2;
                if (v > 9) {
                    v -= 9;
                }
            }
            soma += v;
        });

        return soma % 10 === 0;
    }

    function bandeira(n) {
        if (/^4/.test(n)) return 'VISA';
        if (/^(5[1-5]|2[2-7])/.test(n)) return 'MASTERCARD';
        if (/^3[47]/.test(n)) return 'AMEX';
        if (/^(4011|4312|4389|4514|4576|5041|5066|5067|509|6277|6362|6363|650|6516|6550)/.test(n)) return 'ELO';
        if (/^(606282|3841)/.test(n)) return 'HIPERCARD';

        return '';
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
        plano = { codigo: botao.dataset.pedirPlano, nome: botao.dataset.planoNome, gb: +botao.dataset.planoGb, hoje: +botao.dataset.planoHoje, preco: botao.dataset.planoPreco };
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
        $$('[data-cartao-numero], [data-cartao-cvv], [data-cartao-validade]', modal).forEach((el) => { el.value = ''; });
        if (raiz.dataset.retorno === '1') {
            raiz.dataset.retorno = '0';
            history.replaceState(null, '', location.pathname + '#espaco');
        }
    }

    /* Validação do desenho (coPagar), na mesma ordem e com as mesmas frases */
    function validarCartao() {
        const num = soDigitos($('[data-cartao-numero]', modal).value);
        const [mm, aa] = String($('[data-cartao-validade]', modal).value).split('/');
        const venc = mm && aa ? new Date(2000 + +aa, +mm, 0) : null;
        if (!luhn(num)) return 'Número do cartão inválido.';
        if (String($('[data-cartao-nome]', modal).value).trim().split(/\s+/).length < 2) return 'Informe o nome como está no cartão.';
        if (!venc || +mm < 1 || +mm > 12 || venc < new Date()) return 'Validade inválida ou cartão vencido.';
        if (soDigitos($('[data-cartao-cvv]', modal).value).length < 3) return 'Informe o código de segurança (CVV).';

        return '';
    }

    function documento() {
        return soDigitos($('[data-cartao-documento]', modal).value);
    }

    function pagar() {
        const formulario = modal.dataset.modo === 'formulario';
        const recusa = (formulario ? validarCartao() : '') || ([11, 14].includes(documento().length) ? '' : 'Informe o CPF ou CNPJ do titular.');
        if (recusa) {
            erro(recusa);
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

    function tokenizarEAssinar(inicio) {
        const tokenizar = (window.BlueJusTokenizadores || {})[modal.dataset.gateway];
        if (typeof tokenizar !== 'function') {
            throw new Error('O pagamento por cartão deste gateway ainda não está disponível.');
        }
        const [mm, aa] = String($('[data-cartao-validade]', modal).value).split('/');
        const cartao = { numero: soDigitos($('[data-cartao-numero]', modal).value), nome: $('[data-cartao-nome]', modal).value.trim(), mes: mm, ano: '20' + aa, cvv: soDigitos($('[data-cartao-cvv]', modal).value), documento: documento() };

        return Promise.resolve(tokenizar(inicio.chavePublica, cartao)).then((token) => enviar(modal.dataset.urlToken.replace(/\/0\/token$/, '/' + inicio.pedido + '/token'), { token: token, documento: documento() }))
            .then((d) => {
                if (!d.ok) {
                    throw new Error(d.erro);
                }
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
    mascarar('[data-cartao-numero]', (v) => soDigitos(v).slice(0, 19).replace(/(\d{4})(?=\d)/g, '$1 '));
    mascarar('[data-cartao-validade]', (v) => soDigitos(v).slice(0, 4).replace(/^(\d{2})(\d)/, '$1/$2'));
    mascarar('[data-cartao-cvv]', (v) => soDigitos(v).slice(0, 4));
    mascarar('[data-cartao-documento]', (v) => soDigitos(v).slice(0, 14));
    mascarar('[data-cartao-nome]', (v) => v.toUpperCase());

    const numero = $('[data-cartao-numero]', modal);
    if (numero) {
        numero.addEventListener('input', () => {
            const n = soDigitos(numero.value);
            const b = bandeira(n);
            const selo = $('[data-cartao-bandeira]', modal);
            selo.hidden = !b;
            selo.textContent = b;
            selo.className = 'co-bandeira co-bandeira--' + b.toLowerCase();
            $('[data-co-borda-numero]', modal).classList.toggle('co-numero--invalido', n.length >= 13 && !luhn(n));
        });
    }

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
        const mostrarLinks = () => { $('[data-master-links]', master).hidden = semLink.includes(seletor.value); };
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
