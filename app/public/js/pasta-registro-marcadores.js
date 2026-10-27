/*
 * Registro dos expedientes (desenho 02, 27.10): "Marcadores do sistema".
 * Ligado, o marcador do sistema (andamento, contrato, pró-bono...) é o cartão inteiro. Desligado (o padrão do desenho),
 * vira uma bolinha na linha do tempo; marcadores seguidos do mesmo mês dividem a bolinha, com a contagem, e o clique
 * abre o cartão. A escolha é do navegador (chave bj-reg-marc), como no desenho.
 */
(function () {
    'use strict';
    var lista = document.getElementById('timelineList');
    var chave = document.getElementById('psMarcadoresTg');
    if (!lista || !chave) return;

    var CHAVE = 'bj-reg-marc';
    var abertos = {};   // ids dos cartões abertos pelo clique na bolinha (vale até recarregar)

    function ligado() {
        try { return window.localStorage.getItem(CHAVE) === '1'; } catch (e) { return false; }
    }

    function gravar(valor) {
        try { window.localStorage.setItem(CHAVE, valor ? '1' : '0'); } catch (e) { /* sem armazenamento: vale só nesta tela */ }
    }

    function limpar() {
        lista.querySelectorAll('.ps-marcador-ponto').forEach(function (p) { p.remove(); });
        lista.querySelectorAll('.ps-anotacao--marcador-oculto').forEach(function (a) { a.classList.remove('ps-anotacao--marcador-oculto'); });
        lista.querySelectorAll('.ps-dia--marcador-oculto').forEach(function (d) { d.classList.remove('ps-dia--marcador-oculto'); });
    }

    function ponto(grupo) {
        var primeiro = grupo[0];
        var el = document.createElement('div');
        el.className = 'ps-marcador-ponto';
        el.setAttribute('role', 'button');
        el.tabIndex = 0;
        var bola = document.createElement('span');
        bola.className = 'ps-marcador-ponto-bola';
        el.appendChild(bola);
        var titulos = [];
        grupo.forEach(function (a) {
            var t = a.dataset.marcadorTitulo || '';
            if (t && titulos.indexOf(t) < 0) titulos.push(t);
        });
        var dica;
        if (grupo.length > 1) {
            var n = document.createElement('span');
            n.className = 'ps-marcador-ponto-n';
            n.textContent = grupo.length > 5 ? '+' + grupo.length : String(grupo.length);
            el.appendChild(n);
            dica = chave.dataset.dicaGrupo.replace('__N__', String(grupo.length)).replace('__T__', titulos.join(', '));
        } else {
            var rot = primeiro.dataset.marcadorRot ? ' · ' + primeiro.dataset.marcadorRot.replace(/\s+/g, ' ') : '';
            dica = chave.dataset.dicaPonto.replace('__T__', (primeiro.dataset.marcadorTitulo || '') + rot);
        }
        el.title = dica;
        var abrir = function () {
            grupo.forEach(function (a) { abertos[a.id] = true; });
            aplicar();
        };
        el.addEventListener('click', abrir);
        el.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); abrir(); } });
        return el;
    }

    function aplicar() {
        var on = ligado();
        chave.setAttribute('aria-checked', on ? 'true' : 'false');
        chave.title = on ? chave.dataset.dicaDesligar : chave.dataset.dicaLigar;
        limpar();
        if (on) return;

        var grupo = [];
        var fechar = function () {
            if (!grupo.length) return;
            grupo[0].parentNode.insertBefore(ponto(grupo), grupo[0]);
            grupo = [];
        };
        var filhos = Array.prototype.slice.call(lista.children);
        filhos.forEach(function (el, i) {
            if (el.classList.contains('ps-dia')) return;
            var marcador = el.classList.contains('ps-anotacao--marcador') && !abertos[el.id] && !el.classList.contains('d-none');
            if (!marcador) { fechar(); return; }
            if (grupo.length && grupo[0].dataset.marcadorMes !== el.dataset.marcadorMes) fechar();
            el.classList.add('ps-anotacao--marcador-oculto');
            grupo.push(el);
            // O marcador some do dia: a pílula do dia fica só se sobrar cartão visível naquele dia.
            void i;
        });
        fechar();

        lista.querySelectorAll('.ps-dia').forEach(function (dia) {
            var visivel = false;
            for (var n = dia.nextElementSibling; n && !n.classList.contains('ps-dia'); n = n.nextElementSibling) {
                if (n.classList.contains('ps-marcador-ponto')) continue;
                if (!n.classList.contains('ps-anotacao--marcador-oculto') && !n.classList.contains('d-none')) { visivel = true; break; }
            }
            if (!visivel) dia.classList.add('ps-dia--marcador-oculto');
        });
    }

    chave.addEventListener('click', function () {
        gravar(!ligado());
        abertos = {};
        aplicar();
    });

    aplicar();
}());
