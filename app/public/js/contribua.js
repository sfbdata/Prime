/* Contribua, desenho "08 - Contribua - VS 18.10.26": comportamento da página (renderVals do desenho). */
(function () {
    'use strict';

    const raiz = document.querySelector('[data-contribua]');
    if (!raiz) {
        return;
    }

    const $ = (sel, base) => (base || document).querySelector(sel);
    const $$ = (sel, base) => Array.from((base || document).querySelectorAll(sel));
    const CHAVE = '66.803.163/0001-10';
    const DADOS_BANCARIOS = 'BlueJus Gestão e Tecnologia LTDA · CNPJ 66.803.163/0001-10 · Cora SCFI (403) · Ag 0001 · C/C 7202371-7 · PIX 66.803.163/0001-10';

    /* Toast do desenho: some sozinho em 3,2 s. */
    let tempoToast = null;
    function avisar(texto) {
        const toast = $('[data-toast]');
        $('span', toast).textContent = texto;
        toast.hidden = false;
        clearTimeout(tempoToast);
        tempoToast = setTimeout(() => { toast.hidden = true; }, 3200);
    }

    function enviar(url, campos) {
        const corpo = new FormData();
        corpo.append('_token', raiz.dataset.csrf);
        Object.entries(campos || {}).forEach(([k, v]) => corpo.append(k, v));

        return fetch(url, { method: 'POST', body: corpo, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
            .then((r) => r.json().catch(() => ({ ok: false, erro: 'Não foi possível concluir. Tente de novo.' })));
    }

    function copiar(texto, botao, rotuloCopiado, aviso) {
        const feito = () => {
            $$('[data-copiar-codigo], [data-copiar-chave], [data-copiar-conta]').forEach((b) => { $('span', b).textContent = b.dataset.rotulo || $('span', b).textContent; });
            $('span', botao).textContent = rotuloCopiado;
            avisar(aviso);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(texto).then(feito, feito);

            return;
        }
        const area = document.createElement('textarea');
        area.value = texto;
        area.style.cssText = 'position:fixed;opacity:0;';
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); } catch (e) { /* o aviso sai mesmo assim, como no desenho */ }
        area.remove();
        feito();
    }

    /* QR + "copia e cola" */
    const qr = $('[data-qr]');
    function desenharQr() {
        if (!window.qrcode) {
            return false;
        }
        const q = window.qrcode(0, 'M');
        q.addData(qr.dataset.codigo);
        q.make();
        qr.innerHTML = q.createSvgTag({ cellSize: 5, margin: 0, scalable: true });

        return true;
    }
    const esperaQr = setInterval(() => { if (desenharQr()) { clearInterval(esperaQr); } }, 400);

    function atualizarPix(valor) {
        fetch(raiz.dataset.urlPix + '?valor=' + encodeURIComponent(valor), { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d) => {
                qr.dataset.codigo = d.codigo;
                $('[data-pix-valido]').hidden = !d.valido;
                desenharQr();
            });
    }

    let valorAtual = 20;
    $$('[data-valor]').forEach((botao) => botao.addEventListener('click', () => {
        $$('[data-valor]').forEach((b) => b.classList.toggle('ct-valor--ativo', b === botao));
        const v = parseInt(botao.dataset.valor, 10);
        $('[data-outro]').hidden = v !== -1;
        resetarCopia();
        if (v === -1) {
            $('[data-outro-valor]').focus();
            valorAtual = paraNumero($('[data-outro-valor]').value);
        } else {
            valorAtual = v;
        }
        atualizarPix(valorAtual);
    }));
    const paraNumero = (t) => parseFloat(String(t).replace(/\./g, '').replace(',', '.')) || 0;
    let esperaOutro = null;
    $('[data-outro-valor]').addEventListener('input', (e) => {
        e.target.value = e.target.value.replace(/[^\d,.]/g, '');
        resetarCopia();
        clearTimeout(esperaOutro);
        esperaOutro = setTimeout(() => { valorAtual = paraNumero(e.target.value); atualizarPix(valorAtual); }, 300);
    });

    function resetarCopia() {
        $$('[data-copiar-codigo], [data-copiar-chave], [data-copiar-conta]').forEach((b) => { if (b.dataset.rotulo) { $('span', b).textContent = b.dataset.rotulo; } });
    }
    $$('[data-copiar-codigo], [data-copiar-chave], [data-copiar-conta]').forEach((b) => { b.dataset.rotulo = $('span', b).textContent; });
    $('[data-copiar-codigo]').addEventListener('click', (e) => copiar(qr.dataset.codigo, e.currentTarget, 'Código copiado', 'Código PIX copia e cola copiado'));
    $('[data-copiar-chave]').addEventListener('click', (e) => copiar(CHAVE, e.currentTarget, 'Chave copiada', 'Chave PIX copiada'));
    $('[data-copiar-conta]').addEventListener('click', (e) => copiar(DADOS_BANCARIOS, e.currentTarget, 'Dados copiados', 'Dados bancários copiados'));

    /* "Copiar cupom" do Indique e ganhe: o rótulo vira "Copiado" por 2 s, sem toast (desenho, `copiarCod`). */
    const botaoCupom = $('[data-copiar-cupom]');
    let tempoCupom = null;
    botaoCupom?.addEventListener('click', () => {
        const feito = () => {
            $('span', botaoCupom).textContent = 'Copiado';
            clearTimeout(tempoCupom);
            tempoCupom = setTimeout(() => { $('span', botaoCupom).textContent = 'Copiar cupom'; }, 2000);
        };
        const texto = $('[data-cupom]').textContent.trim();
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(texto).then(feito, feito);

            return;
        }
        const area = document.createElement('textarea');
        area.value = texto;
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); } catch (erro) { /* sem área de transferência: o cupom continua à vista */ }
        area.remove();
        feito();
    });

    /* "Já contribuí, registrar meu apoio" */
    $('[data-registrar-apoio]').addEventListener('click', (e) => {
        const botao = e.currentTarget;
        if (raiz.dataset.apoiou === '1') {
            avisar('Seu apoio deste mês já está registrado. Obrigado!');

            return;
        }
        enviar(raiz.dataset.urlApoio, { centavos: Math.round(valorAtual * 100) }).then((d) => {
            avisar(d.mensagem || d.erro);
            if (d.ok) {
                raiz.dataset.apoiou = '1';
                $('span', botao).textContent = 'Obrigado! Apoio registrado este mês';
                const motivo = $('[data-motivo]');
                if (motivo) { motivo.remove(); }
            }
        });
    });

    /* "Ainda não é o momento de contribuir?" */
    $$('[data-motivo-opcao]').forEach((botao) => botao.addEventListener('click', () => {
        let motivo = botao.dataset.motivoOpcao;
        if (motivo === 'Outro motivo') {
            motivo = (window.prompt('Conte em poucas palavras:', '') || '').trim();
            if (!motivo) {
                return;
            }
        }
        enviar(raiz.dataset.urlMotivo, { motivo }).then((d) => {
            if (d.ok) {
                $('[data-motivo]').remove();
                $('[data-motivo-feito]').hidden = false;
            } else {
                avisar(d.erro);
            }
        });
    }));

    /* Quem somos (recolhível) */
    $('[data-quem]').addEventListener('click', (e) => {
        const aberto = e.currentTarget.getAttribute('aria-expanded') === 'true';
        e.currentTarget.setAttribute('aria-expanded', aberto ? 'false' : 'true');
        $('[data-quem-corpo]').hidden = aberto;
    });

    /* Sugestões: filtro Em aberto / Atendidas / Todas */
    let filtro = 'aberto';
    function filtrar() {
        let visiveis = 0;
        $$('[data-sug]').forEach((s) => {
            const ok = s.dataset.atendida === '1';
            const mostra = filtro === 'todas' || (filtro === 'ok' ? ok : !ok);
            s.hidden = !mostra;
            visiveis += mostra ? 1 : 0;
        });
        $('[data-sug-vazio]').hidden = visiveis > 0;
    }
    $$('[data-filtro]').forEach((b) => b.addEventListener('click', () => {
        filtro = b.dataset.filtro;
        $$('[data-filtro]').forEach((x) => x.classList.toggle('ct-filtro--ativo', x === b));
        filtrar();
    }));
    filtrar();

    /* Modal "Enviar sugestão" */
    const modal = $('[data-modal-sugestao]');
    let tipo = 'sug';
    function abrirModal() {
        tipo = 'sug';
        $$('[data-tipo]', modal).forEach((b) => b.classList.toggle('ct-tipo--ativo', b.dataset.tipo === tipo));
        $('[data-texto-sugestao]', modal).value = '';
        $('[data-texto-sugestao]', modal).placeholder = $('[data-tipo="sug"]', modal).dataset.dica;
        $('[data-erro-sugestao]', modal).hidden = true;
        modal.hidden = false;
        $('[data-texto-sugestao]', modal).focus();
    }
    $('[data-nova-sugestao]').addEventListener('click', abrirModal);
    $$('[data-fechar-sugestao]', modal).forEach((b) => b.addEventListener('click', () => { modal.hidden = true; }));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.hidden) { modal.hidden = true; } });
    $$('[data-tipo]', modal).forEach((b) => b.addEventListener('click', () => {
        tipo = b.dataset.tipo;
        $$('[data-tipo]', modal).forEach((x) => x.classList.toggle('ct-tipo--ativo', x === b));
        $('[data-texto-sugestao]', modal).placeholder = b.dataset.dica;
    }));
    $('[data-texto-sugestao]', modal).addEventListener('input', () => { $('[data-erro-sugestao]', modal).hidden = true; });
    function mostrarErro(texto) {
        const erro = $('[data-erro-sugestao]', modal);
        $('span', erro).textContent = texto;
        erro.hidden = false;
    }
    $('[data-enviar-sugestao]', modal).addEventListener('click', () => {
        const texto = $('[data-texto-sugestao]', modal).value;
        if (texto.trim().length < 8) {
            mostrarErro('Escreva um pouco mais para entendermos a sua sugestão.');

            return;
        }
        enviar(raiz.dataset.urlSugestao, { tipo, texto }).then((d) => {
            if (!d.ok) {
                mostrarErro(d.erro);

                return;
            }
            sessionStorage.setItem('ct-toast', d.mensagem);
            location.hash = 'sug';
            location.reload();
        });
    });

    /* Master: "Marcar atendida" (com resposta opcional) / "Reabrir" */
    $$('[data-atender]').forEach((b) => b.addEventListener('click', () => {
        const resposta = b.dataset.atendida === '1' ? '' : (window.prompt('Resposta para quem enviou (opcional):', '') || '');
        enviar(raiz.dataset.urlAtender.replace(/\/0\/atender$/, '/' + b.dataset.atender + '/atender'), { resposta }).then((d) => {
            if (d.ok) {
                location.hash = 'sug';
                location.reload();
            } else {
                avisar(d.erro || 'Não foi possível concluir. Tente de novo.');
            }
        });
    }));

    /* "Quero +N GB": sem gateway escolhido pelo Master, lista de espera (coPedir do desenho). Com gateway,
       quem abre o checkout é o contribua-checkout.js. */
    $$('[data-pedir-plano]').forEach((b) => b.addEventListener('click', () => {
        if (b.disabled || raiz.dataset.cartaoLiberado === '1') {
            return;
        }
        enviar(raiz.dataset.urlPedido, { plano: b.dataset.pedirPlano }).then((d) => avisar(d.mensagem || d.erro));
    }));

    /* Modal do convênio OAB: abre pelo selo do plano gratuito; fecha pelo fundo, pelo X, por "Vamos crescer juntos" e por ESC */
    const modalOab = $('[data-modal-oab]');
    if (modalOab) {
        let seloQueAbriu = null;
        const fecharOab = () => {
            modalOab.hidden = true;
            if (seloQueAbriu) {
                seloQueAbriu.focus();
            }
        };
        $$('[data-abrir-oab]').forEach((b) => b.addEventListener('click', () => {
            seloQueAbriu = b;
            modalOab.hidden = false;
            $('.ct-oab-fechar', modalOab).focus();
        }));
        $$('[data-fechar-oab]', modalOab).forEach((b) => b.addEventListener('click', fecharOab));
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modalOab.hidden) { fecharOab(); } });
    }

    /* Toast guardado antes de recarregar (sugestão enviada) */
    const pendente = sessionStorage.getItem('ct-toast');
    if (pendente) {
        sessionStorage.removeItem('ct-toast');
        avisar(pendente);
    }
})();
