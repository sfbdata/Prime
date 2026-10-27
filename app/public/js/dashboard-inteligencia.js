/* Marcas dos alertas da Central de Inteligência (painel BlueJus Intelligence).
   Botões Resolvido, Ignorar, Aplicar e Não se aplica (desenho 01, L966-975). Cada clique grava
   POST /dashboard/inteligencia/alerta no servidor (marca pessoal do usuário) e só então muda a tela
   com o que o servidor devolveu. O painel é trocado junto com o fragmento dos filtros, então o
   clique é delegado no document (sobrevive à troca). Os rótulos vêm do HTML (data-rotulo-*),
   traduzidos no servidor. */
(function () {
    'use strict';

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function pressionar(alerta, acao, ligado) {
        var botoes = alerta.querySelectorAll('[data-ia-marcar="' + acao + '"]');
        Array.prototype.forEach.call(botoes, function (b) {
            b.setAttribute('aria-pressed', ligado ? 'true' : 'false');
        });
    }

    /* Marcado desce para o fim; desmarcado volta à posição do servidor (data-ordem), entre os não marcados. */
    function reposicionar(alerta, lista, marcado) {
        var itens = Array.prototype.slice.call(lista.querySelectorAll('[data-alerta]')).filter(function (a) { return a !== alerta; });
        var ordem = parseInt(alerta.getAttribute('data-ordem'), 10);
        var ref = null;
        if (marcado) {
            ref = null;
        } else {
            ref = itens.filter(function (a) {
                return !a.classList.contains('is-marcado') && parseInt(a.getAttribute('data-ordem'), 10) > ordem;
            })[0] || itens.filter(function (a) { return a.classList.contains('is-marcado'); })[0] || null;
        }
        lista.insertBefore(alerta, ref);
    }

    function mostrar(alerta, lista, marca) {
        var status = alerta.querySelector('[data-ia-status]');
        var rotulo = marca.status ? lista.getAttribute('data-rotulo-' + marca.status) : '';
        alerta.classList.toggle('is-marcado', !!marca.status);
        if (status) {
            status.textContent = rotulo || '';
            status.hidden = !marca.status;
        }
        pressionar(alerta, 'resolvido', marca.status === 'resolvido');
        pressionar(alerta, 'ignorado', marca.status === 'ignorado');
        pressionar(alerta, 'sim', marca.feedback === 'sim');
        pressionar(alerta, 'nao', marca.feedback === 'nao');
        reposicionar(alerta, lista, !!marca.status);
    }

    function marcar(botao) {
        var alerta = botao.closest('[data-alerta]');
        var lista = botao.closest('[data-ia-alertas]');
        if (!alerta || !lista || botao.disabled) { return; }
        botao.disabled = true;
        fetch(lista.getAttribute('data-url'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token() },
            body: JSON.stringify({ alerta: alerta.getAttribute('data-alerta'), acao: botao.getAttribute('data-ia-marcar') })
        }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.json();
        }).then(function (marca) {
            mostrar(alerta, lista, marca);
        }).catch(function () {
            /* sem gravar, a tela fica como estava */
        }).then(function () {
            botao.disabled = false;
        });
    }

    document.addEventListener('click', function (e) {
        var botao = e.target.closest ? e.target.closest('[data-ia-marcar]') : null;
        if (botao) { marcar(botao); }
    });
}());
