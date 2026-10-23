/* =============================================================================
   ponto-pendente.js: cartão diário de pendência de registro de ponto (spec docs/specs/ponto-esquecimento-nao-trava.md
   R9). Componente do lembrete do desenho (bj-lembretes.js): a pilha fixa #bjl-box (a mesma dos lembretes da Agenda),
   o cartão com a borda esquerda na cor do tipo, o ícone redondo, o rótulo, o título, os botões (ação azul, adiar e
   fechar) e a barra de tempo. Ícone e cor do "Regularizar ponto de ontem" (dc 03, L676-677).

   Uma vez por dia por pessoa e escritório (localStorage, sempre em try/catch): consultar marca o dia (e a resposta
   vazia grava `vazio`), então o endpoint sai no máximo uma vez por dia; "fechar" esconde
   até amanhã; "adiar" esconde por 10 min (a duração do desenho, "Lembrar em 10 min"). Textos e link vêm prontos do
   servidor; DOM só com textContent.
   ============================================================================= */
(function () {
    'use strict';

    var raiz = document.getElementById('bjPontoPendente');
    if (!raiz) { return; }
    var URL = raiz.getAttribute('data-url');
    var CHAVE = 'bj-ponto-pendente:' + (raiz.getAttribute('data-eu') || '');
    var ADIAR_MS = 10 * 60 * 1000;
    var COR = '#c76a12';
    var ICONE = 'bi-exclamation-triangle';

    function hoje() { return new Date().toLocaleDateString('sv-SE'); }
    function ler() {
        try { return JSON.parse(localStorage.getItem(CHAVE) || '{}') || {}; } catch (e) { return {}; }
    }
    function gravar(valor) {
        try { localStorage.setItem(CHAVE, JSON.stringify(valor)); } catch (e) { /* sem armazenamento: mostra de novo */ }
    }

    /* Adiado: volta depois do prazo. Sem adiamento: uma vez por dia. */
    function deveMostrar(guardado, agora) {
        if (guardado.adiarAte) { return agora >= guardado.adiarAte; }

        return guardado.dia !== hoje();
    }

    function el(tag, classe, texto) {
        var e = document.createElement(tag);
        if (classe) { e.className = classe; }
        if (texto) { e.textContent = texto; }

        return e;
    }

    function pilha(regiao) {
        var box = document.getElementById('bjl-box');
        if (!box) {
            box = el('div', 'bjl-box');
            box.id = 'bjl-box';
            box.setAttribute('role', 'region');
            box.setAttribute('aria-label', regiao);
            document.body.appendChild(box);
        }

        return box;
    }

    function desenhar(c) {
        var box = pilha(c.regiao);
        var cartao = el('div', 'bjl-cartao');
        cartao.id = 'bjl-ponto-pendente';
        cartao.setAttribute('data-bjl-dono', 'ponto');   // a pilha é da Agenda também: cada uma só tira os seus
        cartao.style.borderLeftColor = COR;

        var topo = el('div', 'bjl-topo');
        var icone = el('span', 'bjl-icone');
        icone.style.background = COR;
        icone.appendChild(el('i', 'bi ' + ICONE));
        var textos = el('div', 'bjl-textos');
        var rotulo = el('div', 'bjl-rotulo', c.rotulo);
        rotulo.style.color = COR;
        textos.appendChild(rotulo);
        textos.appendChild(el('b', '', c.titulo));
        textos.appendChild(el('div', 'bjl-dica', c.linha));
        if (c.orientacao) { textos.appendChild(el('div', 'bjl-dica', c.orientacao)); }
        var fechar = el('button', 'bjl-x', '×');
        fechar.type = 'button';
        fechar.title = c.dispensar;
        fechar.setAttribute('aria-label', c.dispensar);
        topo.appendChild(icone);
        topo.appendChild(textos);
        topo.appendChild(fechar);

        var botoes = el('div', 'bjl-botoes');
        var acao = el('button', 'bjl-abrir', c.acao);
        acao.type = 'button';
        var adiar = el('button', 'bjl-adiar', c.adiar);
        adiar.type = 'button';
        botoes.appendChild(acao);
        botoes.appendChild(adiar);

        var barra = el('div', 'bjl-barra');
        barra.style.background = COR;

        cartao.appendChild(topo);
        cartao.appendChild(botoes);
        cartao.appendChild(barra);
        box.appendChild(cartao);

        function tirar() {
            cartao.remove();
            if (!box.children.length) { box.remove(); }
        }
        fechar.addEventListener('click', function () { gravar({ dia: hoje() }); tirar(); });
        adiar.addEventListener('click', function () { gravar({ dia: hoje(), adiarAte: Date.now() + ADIAR_MS }); tirar(); });
        acao.addEventListener('click', function () {
            gravar({ dia: hoje() });
            // Só link interno, vindo do servidor (a tela do ponto com o modal, ou a troca de escritório).
            if (typeof c.link === 'string' && c.link.charAt(0) === '/' && c.link.charAt(1) !== '/') { window.location.href = c.link; }
            tirar();
        });
    }

    function checar() {
        if (document.getElementById('bjl-ponto-pendente') || !deveMostrar(ler(), Date.now())) { return; }
        gravar({ dia: hoje() });   // consultado: o endpoint sai no máximo uma vez por dia (ou quando o adiamento vence)
        fetch(URL, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok && (r.headers.get('Content-Type') || '').indexOf('application/json') !== -1 ? r.json() : null; })
            .then(function (dados) {
                if (!dados || !dados.cartao) { gravar({ dia: hoje(), vazio: true }); return; }
                if (!document.getElementById('bjl-ponto-pendente')) { desenhar(dados.cartao); }
            })
            .catch(function () { /* sem resposta: tenta na próxima página */ });
    }

    function iniciar() {
        checar();
        setInterval(checar, 60000);   // só para o adiamento de 10 min vencer com a página aberta: a guarda de checar() vale igual
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { setTimeout(iniciar, 1200); }); } else { setTimeout(iniciar, 1200); }
}());
