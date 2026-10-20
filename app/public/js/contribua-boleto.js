/* Contribua, boleto de apoio (chave "Boleto" do desenho 08, só com a Cora). Usa o valor escolhido na seção
   Apoie, o CSRF e o toast da página (data-contribua / data-toast); não mexe no contribua.js. */
(function () {
    'use strict';
    if (window.__bjContribuaBoleto) { return; }
    window.__bjContribuaBoleto = true;

    const $ = (s, el) => (el || document).querySelector(s);
    const MINIMO = 'O boleto tem valor mínimo de R$ 5,00.';
    const FALHA = 'Não foi possível gerar o boleto agora. Tente de novo em instantes.';

    let tempoToast = null;
    function avisar(texto) {
        const toast = $('[data-toast]');
        if (!toast) { return; }
        $('span', toast).textContent = texto;
        toast.hidden = false;
        clearTimeout(tempoToast);
        tempoToast = setTimeout(() => { toast.hidden = true; }, 3200);
    }

    /* O valor escolhido nos botões da seção Apoie (R$ 20, 50, 100 ou o "Outro valor"), em reais. */
    function valorEscolhido() {
        const ativo = $('.ct-valor--ativo[data-valor]');
        const v = ativo ? parseInt(ativo.dataset.valor, 10) : 20;
        if (v !== -1) { return v; }
        const outro = $('[data-outro-valor]');

        return parseFloat(String(outro ? outro.value : '').replace(/\./g, '').replace(',', '.')) || 0;
    }
    const reais = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

    /* Mesmo padrão dos botões de copiar do PIX: o rótulo muda e o aviso aparece. */
    function copiar(texto, botao, rotuloCopiado, aviso) {
        const span = $('span', botao);
        botao.dataset.rotulo = botao.dataset.rotulo || span.textContent;
        const feito = () => { span.textContent = rotuloCopiado; avisar(aviso); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(texto).then(feito, feito);
            return;
        }
        const area = document.createElement('textarea');
        area.value = texto;
        area.style.cssText = 'position:fixed;opacity:0;';
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); } catch (e) { /* o aviso sai mesmo assim, como no PIX */ }
        area.remove();
        feito();
    }

    function mostrarErro(caixa, texto) {
        const erro = $('[data-cb-erro]', caixa);
        $('span', erro).textContent = texto;
        erro.hidden = !texto;
    }

    function mostrarBoleto(caixa, b) {
        $('[data-cb-linha]', caixa).textContent = b.linhaDigitavel;
        $('[data-cb-vencimento]', caixa).textContent = b.vencimento;
        $('[data-cb-valor-boleto]', caixa).textContent = b.valor;
        $('[data-cb-pdf]', caixa).href = b.url;
        $('[data-cb-pix]', caixa).hidden = !b.pixCopiaECola;
        $('[data-cb-pix-codigo]', caixa).textContent = b.pixCopiaECola || '';
        caixa.querySelectorAll('[data-rotulo]').forEach((x) => { $('span', x).textContent = x.dataset.rotulo; });
        $('[data-cb-pedido]', caixa).hidden = true;
        $('[data-cb-resultado]', caixa).hidden = false;
    }

    async function gerar(caixa, botao) {
        const valor = valorEscolhido();
        if (valor < 5) { mostrarErro(caixa, MINIMO); return; }
        const raiz = $('[data-contribua]');
        const corpo = new FormData();
        corpo.append('_token', raiz ? raiz.dataset.csrf : '');
        corpo.append('centavos', String(Math.round(valor * 100)));
        corpo.append('documento', $('[data-cb-documento]', caixa).value);
        botao.disabled = true;
        try {
            const r = await fetch(caixa.dataset.url, { method: 'POST', body: corpo, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
            const j = await r.json().catch(() => ({ ok: false, erro: FALHA }));
            if (j.ok) { mostrarBoleto(caixa, j.boleto); } else { mostrarErro(caixa, j.erro || FALHA); }
        } catch (e) {
            mostrarErro(caixa, FALHA);
        }
        botao.disabled = false;
    }

    function abrirPedido(caixa) {
        $('[data-cb-valor]', caixa).textContent = reais(valorEscolhido());
        mostrarErro(caixa, '');
        $('[data-cb-resultado]', caixa).hidden = true;
        $('[data-cb-pedido]', caixa).hidden = false;
        $('[data-cb-documento]', caixa).focus();
    }

    function iniciar() {
        const caixa = $('[data-cb-boleto]');
        if (!caixa) { return; }
        caixa.addEventListener('click', (ev) => {
            const t = ev.target;
            if (t.closest('[data-cb-abrir]')) { abrirPedido(caixa); return; }
            const botao = t.closest('[data-cb-gerar]');
            if (botao) { gerar(caixa, botao); return; }
            const linha = t.closest('[data-cb-copiar-linha]');
            if (linha) { copiar($('[data-cb-linha]', caixa).textContent, linha, 'Linha copiada', 'Linha digitável copiada'); return; }
            const pix = t.closest('[data-cb-copiar-pix]');
            if (pix) { copiar($('[data-cb-pix-codigo]', caixa).textContent, pix, 'Código copiado', 'Pix copia e cola do boleto copiado'); }
        });
        /* Trocar o valor vale para o próximo boleto: o pedido aberto mostra o valor novo. */
        document.querySelectorAll('.ct-valor[data-valor], [data-outro-valor]').forEach((el) => el.addEventListener(el.matches('input') ? 'input' : 'click', () => {
            if (!$('[data-cb-pedido]', caixa).hidden) { $('[data-cb-valor]', caixa).textContent = reais(valorEscolhido()); }
        }));
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', iniciar); } else { iniciar(); }
})();
