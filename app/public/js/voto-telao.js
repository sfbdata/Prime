/* BlueJus Voto: Telão (modo assembleia, desenho 18). Troca a pauta sem recarregar (para não sair da tela cheia),
   atualiza os números pelo JSON voto_assembleia_telao_dados a cada 5 s, conta o cronômetro no navegador, mostra a
   confirmação de "Encerrar votação" e alterna a tela cheia. Se a situação de uma pauta mudou (aberta ou encerrada
   por outra pessoa, ou pelo prazo), recarrega a tela na pauta que está em foco, que traz os botões certos. */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-telao]');
    if (!raiz) { return; }
    var urlDados = raiz.getAttribute('data-dados');
    var selecionada = null;

    function paineis() { return Array.prototype.slice.call(raiz.querySelectorAll('[data-telao-pauta]')); }

    function painelDe(id) { return raiz.querySelector('[data-telao-pauta="' + id + '"]'); }

    function atual() {
        var visivel = paineis().filter(function (p) { return !p.hidden; })[0];
        return visivel ? visivel.getAttribute('data-telao-pauta') : null;
    }

    function selecionar(id) {
        var alvo = painelDe(id);
        if (!alvo) { return; }
        paineis().forEach(function (p) { p.hidden = p !== alvo; });
        Array.prototype.forEach.call(raiz.querySelectorAll('.vAoI'), function (item) {
            item.classList.toggle('vAoOn', item.getAttribute('data-telao-ir') === String(id));
        });
        Array.prototype.forEach.call(raiz.querySelectorAll('[data-telao-pergunta]'), function (p) { p.hidden = true; });
        Array.prototype.forEach.call(raiz.querySelectorAll('[data-telao-pedir-encerrar]'), function (b) { b.hidden = false; });
        selecionada = String(id);
        if (window.history && history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.set('pauta', id);
            history.replaceState(null, '', url.toString());
        }
    }

    function formatar(segundos) {
        var m = Math.floor(segundos / 60), s = segundos % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    function pintarCronometros() {
        Array.prototype.forEach.call(raiz.querySelectorAll('[data-telao-cron][data-segundos]'), function (c) {
            var restante = parseInt(c.getAttribute('data-segundos'), 10);
            c.textContent = c.getAttribute('data-modelo').replace('{tempo}', formatar(Math.max(0, restante)));
        });
    }

    function contar() {
        var zerou = false;
        Array.prototype.forEach.call(raiz.querySelectorAll('[data-telao-cron][data-segundos]'), function (c) {
            var restante = Math.max(0, parseInt(c.getAttribute('data-segundos'), 10) - 1);
            c.setAttribute('data-segundos', restante);
            zerou = zerou || restante === 0;
        });
        pintarCronometros();
        var hora = raiz.querySelector('[data-telao-hora]');
        if (hora) { hora.textContent = new Date().toLocaleTimeString('pt-BR'); }
        if (zerou) { consultar(); }
    }

    function recarregar() {
        var url = new URL(window.location.href);
        url.searchParams.set('pauta', atual() || '');
        window.location.assign(url.toString());
    }

    function aplicar(dados) {
        var mudou = false;
        (dados.pautas || []).forEach(function (d) {
            var painel = painelDe(d.id);
            if (!painel) { return; }
            if (painel.getAttribute('data-situacao') !== d.situacao) { mudou = true; return; }
            painel.querySelector('[data-telao-votantes]').textContent = d.votantes;
            painel.querySelector('[data-telao-aptos]').textContent = d.aptos;
            painel.querySelector('[data-telao-percentual]').textContent = d.percentual;
            painel.querySelector('[data-telao-barra]').style.width = d.largura + '%';
            var marca = painel.querySelector('[data-telao-marca]');
            marca.hidden = d.quorumMarca === null;
            if (d.quorumMarca !== null) { marca.style.left = d.quorumMarca + '%'; }
            painel.querySelector('[data-telao-quorum]').textContent = d.quorumTexto;
            var cron = painel.querySelector('[data-telao-cron][data-segundos]');
            if (cron && d.segundos !== null) { cron.setAttribute('data-segundos', d.segundos); }
        });
        pintarCronometros();
        if (mudou) { recarregar(); }
    }

    function consultar() {
        fetch(urlDados, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (dados) { if (dados) { aplicar(dados); } })
            .catch(function () { /* sem rede: tenta de novo no próximo intervalo */ });
    }

    raiz.addEventListener('click', function (ev) {
        var ir = ev.target.closest('[data-telao-ir]');
        if (ir) {
            ev.preventDefault();
            selecionar(ir.getAttribute('data-telao-ir'));
            return;
        }
        var pedir = ev.target.closest('[data-telao-pedir-encerrar]');
        if (pedir) {
            var form = pedir.closest('form');
            pedir.hidden = true;
            form.querySelector('[data-telao-pergunta]').hidden = false;
            return;
        }
        if (ev.target.closest('[data-telao-continuar]')) {
            var f = ev.target.closest('form');
            f.querySelector('[data-telao-pergunta]').hidden = true;
            f.querySelector('[data-telao-pedir-encerrar]').hidden = false;
            return;
        }
        if (ev.target.closest('[data-telao-tela-cheia]')) {
            try {
                if (document.fullscreenElement) { document.exitFullscreen(); }
                else if (raiz.requestFullscreen) { raiz.requestFullscreen(); }
            } catch (e) { /* o navegador pode negar */ }
        }
    });

    var caixa = document.querySelector('[data-voto-toasts]');
    if (caixa) {
        setTimeout(function () {
            Array.prototype.forEach.call(caixa.querySelectorAll('.voto-toast'), function (t) { t.remove(); });
        }, 3600);
    }

    selecionada = atual();
    pintarCronometros();
    setInterval(contar, 1000);
    setInterval(consultar, 5000);
}());
