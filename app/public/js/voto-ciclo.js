/* BlueJus Voto: ciclo de vida da votação (F1-2b e F1-3, desenho 18). Sem dependência.
   1) Faixas escondidas do desenho (arquivar, excluir rascunho): o item de menu com data-voto-revelar="#id" mostra o
      alvo (que tem data-voto-revelavel); o botão com data-voto-ocultar o esconde de novo.
   2) Motivo obrigatório de 5 ou mais caracteres no cliente (retPede/selArqSim do desenho): form com
      data-voto-exige-motivo="mensagem" não envia com o campo `motivo` curto e mostra a mensagem no toast do desenho.
      O servidor valida de novo.
   3) Seleção em lote da Gestão eleitoral (selV/selAc do desenho, A6): caixas .vSel[data-voto-lote-item], "Selecionar
      todas" / "N selecionada(s)", "Arquivar (n)" e "Excluir (n)" (contam só as que se encaixam), "Limpar seleção" e as
      duas faixas de confirmação. No envio, os ids marcados viram campos ids[].
   4) Ao abrir uma janela ou faixa pelo menu, o menu de três pontos se fecha. */
(function () {
    'use strict';

    function toast(texto, erro) {
        var caixa = document.querySelector('[data-voto-toasts]');
        if (!caixa) { return; }
        var t = document.createElement('div');
        t.className = 'voto-toast' + (erro ? ' voto-toast-erro' : '');
        t.setAttribute('role', 'status');
        t.textContent = texto;
        caixa.appendChild(t);
        setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 3600);
    }

    function fecharMenuDe(elemento) {
        var menu = elemento.closest('[data-voto-menu]');
        if (!menu) { return; }
        var caixa = menu.querySelector('.vKm');
        var botao = menu.querySelector('[data-voto-menu-botao]');
        if (caixa) { caixa.hidden = true; }
        if (botao) { botao.setAttribute('aria-expanded', 'false'); }
    }

    document.addEventListener('click', function (ev) {
        var revelar = ev.target.closest('[data-voto-revelar]');
        if (revelar) {
            var alvo = document.querySelector(revelar.getAttribute('data-voto-revelar'));
            fecharMenuDe(revelar);
            if (alvo) {
                alvo.hidden = false;
                var campo = alvo.querySelector('input:not([type=hidden]), button');
                if (campo) { campo.focus(); }
            }
            return;
        }
        var ocultar = ev.target.closest('[data-voto-ocultar]');
        if (ocultar) {
            var faixa = ocultar.closest('[data-voto-revelavel]');
            if (faixa) {
                faixa.hidden = true;
                // O foco volta ao botão ⋮ do menu que abriu a faixa (leitor de tela e teclado não se perdem).
                var origem = faixa.id ? document.querySelector('[data-voto-revelar="#' + faixa.id + '"]') : null;
                var menu = origem ? origem.closest('[data-voto-menu]') : null;
                var botao = menu ? menu.querySelector('[data-voto-menu-botao]') : null;
                if (botao) { botao.focus(); }
            }
            return;
        }
        var janela = ev.target.closest('[data-voto-janela]');
        if (janela) { fecharMenuDe(janela); }
    }, true);

    /* Capturando, para vir antes do envio por fetch do telão (voto-telao.js). */
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form.hasAttribute || !form.hasAttribute('data-voto-exige-motivo')) { return; }
        var campo = form.querySelector('[name="motivo"]');
        if (campo && campo.value.trim().length < 5) {
            ev.preventDefault();
            ev.stopImmediatePropagation();
            toast(form.getAttribute('data-voto-exige-motivo'), true);
            campo.focus();
        }
    }, true);

    /* ---- Seleção em lote ---- */
    var lote = document.querySelector('[data-voto-lote]');
    if (!lote) { return; }

    var itens = Array.prototype.slice.call(document.querySelectorAll('[data-voto-lote-item]'));
    var todas = lote.querySelector('[data-voto-lote-todas]');
    var texto = lote.querySelector('[data-voto-lote-tx]');
    var acoes = lote.querySelector('[data-voto-lote-acoes]');
    var formArquivar = lote.querySelector('[data-voto-lote-form="arquivar"]');
    var formExcluir = lote.querySelector('[data-voto-lote-form="excluir"]');

    /* Só conta o que está na tela: assembleia de grupo recolhido (F1-4) não entra na seleção nem no lote. */
    function visiveis() { return itens.filter(function (i) { return i.offsetParent !== null; }); }
    function marcados() { return visiveis().filter(function (i) { return i.checked; }); }
    function que(lista, atributo) { return lista.filter(function (i) { return i.getAttribute(atributo) === '1'; }); }
    function modelo(elemento, n) { return elemento.getAttribute('data-modelo').replace('{n}', n); }

    function recolherFaixas() { formArquivar.hidden = true; formExcluir.hidden = true; }

    function atualizarFaixas(s, arquivaveis, excluiveis) {
        formArquivar.querySelector('[data-voto-lote-titulo]').textContent = modelo(formArquivar.querySelector('[data-voto-lote-titulo]'), arquivaveis.length);
        var obsA = formArquivar.querySelector('[data-voto-lote-obs]');
        var foraA = s.length - arquivaveis.length;
        obsA.textContent = (foraA ? obsA.getAttribute('data-modelo-fora').replace('{n}', foraA) : '') +
            (que(s, 'data-aberta').length ? ' ' + obsA.getAttribute('data-modelo-aberta') : '');
        formExcluir.querySelector('[data-voto-lote-titulo]').textContent = modelo(formExcluir.querySelector('[data-voto-lote-titulo]'), excluiveis.length);
        var obsX = formExcluir.querySelector('[data-voto-lote-obs]');
        var foraX = s.length - excluiveis.length;
        obsX.textContent = foraX ? obsX.getAttribute('data-modelo-fora').replace('{n}', foraX) : '';
    }

    function atualizar() {
        var s = marcados();
        var arquivaveis = que(s, 'data-arquivar');
        var excluiveis = que(s, 'data-excluir');
        texto.textContent = s.length ? texto.getAttribute('data-modelo').replace('{n}', s.length) : texto.getAttribute('data-todas');
        todas.checked = visiveis().length > 0 && s.length === visiveis().length;
        acoes.hidden = s.length === 0;
        Array.prototype.forEach.call(acoes.querySelectorAll('[data-voto-lote-pedir]'), function (b) {
            var n = b.getAttribute('data-voto-lote-pedir') === 'arquivar' ? arquivaveis.length : excluiveis.length;
            b.querySelector('span').textContent = modelo(b, n);
        });
        atualizarFaixas(s, arquivaveis, excluiveis);
    }

    /* Abrir ou recolher um grupo (ou "Expandir/Recolher todas", que muda o mesmo <details>) muda o que está na tela:
       recontar para "N selecionada(s)", "Arquivar (n)" e "Excluir (n)" baterem com o que será enviado. O evento
       toggle não borbulha: escuta na captura. */
    document.addEventListener('toggle', function (ev) {
        if (ev.target.matches && ev.target.matches('[data-voto-grupo]')) {
            recolherFaixas();
            atualizar();
        }
    }, true);

    todas.addEventListener('change', function () {
        visiveis().forEach(function (i) { i.checked = todas.checked; });
        recolherFaixas();
        atualizar();
    });

    document.addEventListener('change', function (ev) {
        if (ev.target.matches && ev.target.matches('[data-voto-lote-item]')) {
            recolherFaixas();
            atualizar();
        }
    });

    lote.addEventListener('click', function (ev) {
        var pedir = ev.target.closest('[data-voto-lote-pedir]');
        if (pedir) {
            var tipo = pedir.getAttribute('data-voto-lote-pedir');
            var s = marcados();
            if (que(s, tipo === 'arquivar' ? 'data-arquivar' : 'data-excluir').length === 0) {
                toast(pedir.getAttribute('data-vazio'), true);
                return;
            }
            recolherFaixas();
            var form = tipo === 'arquivar' ? formArquivar : formExcluir;
            form.hidden = false;
            var campo = form.querySelector('input[name="motivo"]');
            if (campo) { campo.focus(); }
            return;
        }
        if (ev.target.closest('[data-voto-lote-limpar]')) {
            itens.forEach(function (i) { i.checked = false; });
            recolherFaixas();
            atualizar();
            return;
        }
        if (ev.target.closest('[data-voto-lote-cancelar]')) { recolherFaixas(); }
    });

    lote.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form.hasAttribute('data-voto-lote-form')) { return; }
        Array.prototype.forEach.call(form.querySelectorAll('input[name="ids[]"]'), function (c) { c.remove(); });
        marcados().forEach(function (i) {
            var h = document.createElement('input');
            h.type = 'hidden';
            h.name = 'ids[]';
            h.value = i.value;
            form.appendChild(h);
        });
    });

    atualizar();
})();
