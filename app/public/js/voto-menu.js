/* BlueJus Voto: menu de três pontos (desenho 18): abre no botão, fecha ao clicar fora, com Esc e com Tab; setas,
   Home e End movem o foco entre os itens. Item de formulário com data-voto-confirmar pede confirmação numa janela
   do desenho (.vMod/.vModC, public/css/voto-janela.css) antes de enviar. */
(function () {
    'use strict';

    function menus() { return document.querySelectorAll('[data-voto-menu]'); }
    function itens(menu) { return Array.prototype.slice.call(menu.querySelectorAll('.vKm a, .vKm button')); }

    function fechar(menu, devolverFoco) {
        var caixa = menu.querySelector('.vKm');
        var botao = menu.querySelector('[data-voto-menu-botao]');
        if (!caixa || caixa.hidden) { return; }
        caixa.hidden = true;
        botao.setAttribute('aria-expanded', 'false');
        if (devolverFoco) { botao.focus(); }
    }

    function fecharTodos(menuAberto) {
        menus().forEach(function (m) { if (m !== menuAberto) { fechar(m, false); } });
    }

    function abrir(menu) {
        fecharTodos(menu);
        menu.querySelector('.vKm').hidden = false;
        menu.querySelector('[data-voto-menu-botao]').setAttribute('aria-expanded', 'true');
        var lista = itens(menu);
        if (lista.length) { lista[0].focus(); }
    }

    function confirmar(form) {
        var raiz = form.closest('.voto-tela') || document.body;
        var fundo = document.createElement('div');
        fundo.className = 'vModConfirma';
        var menuDeOrigem = form.closest('[data-voto-menu]');
        var gatilho = menuDeOrigem ? menuDeOrigem.querySelector('[data-voto-menu-botao]') : null;
        var destrutivo = form.querySelector('.vKd') !== null;
        fundo.innerHTML = '<div class="vModC" role="dialog" aria-modal="true"><div class="vH"></div>' +
            '<div class="vModCorpo"><div data-texto></div><div class="vModRodape">' +
            '<button type="button" class="vB" data-nao></button><button type="button" class="vB" data-sim></button></div></div></div>';
        var titulo = fundo.querySelector('.vH');
        titulo.textContent = form.getAttribute('data-voto-confirmar-titulo') || form.querySelector('button').textContent.trim();
        fundo.querySelector('[data-texto]').textContent = form.getAttribute('data-voto-confirmar');
        var sim = fundo.querySelector('[data-sim]');
        var nao = fundo.querySelector('[data-nao]');
        sim.textContent = form.getAttribute('data-voto-confirmar-sim') || 'Confirmar';
        nao.textContent = form.getAttribute('data-voto-confirmar-nao') || 'Cancelar';
        sim.className = 'vB ' + (destrutivo ? 'vD' : 'vP');
        function sair(devolver) { document.removeEventListener('keydown', tecla, true); fundo.remove(); if (devolver !== false && gatilho) { gatilho.focus(); } }
        function tecla(ev) {
            if (ev.key === 'Escape') { ev.stopPropagation(); sair(); return; }
            if (ev.key === 'Tab') {
                /* Prende o Tab entre Cancelar e Confirmar. */
                var alvo = ev.shiftKey ? (document.activeElement === nao ? sim : nao) : (document.activeElement === sim ? nao : sim);
                ev.preventDefault();
                alvo.focus();
            }
        }
        document.addEventListener('keydown', tecla, true);
        nao.addEventListener('click', sair);
        fundo.addEventListener('click', function (ev) { if (ev.target === fundo) { sair(); } });
        sim.addEventListener('click', function () { sair(false); form.submit(); });
        raiz.appendChild(fundo);
        nao.focus();
    }

    document.addEventListener('click', function (ev) {
        var botao = ev.target.closest('[data-voto-menu-botao]');
        if (botao) {
            var menu = botao.closest('[data-voto-menu]');
            if (menu.querySelector('.vKm').hidden) { abrir(menu); } else { fechar(menu, true); }
            return;
        }
        fecharTodos(ev.target.closest('[data-voto-menu]'));
    });

    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (form.hasAttribute && form.hasAttribute('data-voto-confirmar')) {
            ev.preventDefault();
            confirmar(form);
        }
    });

    document.addEventListener('keydown', function (ev) {
        var menu = ev.target.closest ? ev.target.closest('[data-voto-menu]') : null;
        if (ev.key === 'Escape') {
            if (menu) { fechar(menu, true); } else { fecharTodos(null); }
            return;
        }
        if (!menu) { return; }
        var botao = menu.querySelector('[data-voto-menu-botao]');
        var aberto = !menu.querySelector('.vKm').hidden;
        if (ev.target === botao && !aberto && (ev.key === 'ArrowDown' || ev.key === 'ArrowUp')) {
            ev.preventDefault();
            abrir(menu);
            return;
        }
        if (!aberto) { return; }
        var lista = itens(menu);
        var i = lista.indexOf(document.activeElement);
        if (ev.key === 'ArrowDown') { ev.preventDefault(); lista[(i + 1) % lista.length].focus(); }
        else if (ev.key === 'ArrowUp') { ev.preventDefault(); lista[(i <= 0 ? lista.length : i) - 1].focus(); }
        else if (ev.key === 'Home') { ev.preventDefault(); lista[0].focus(); }
        else if (ev.key === 'End') { ev.preventDefault(); lista[lista.length - 1].focus(); }
        else if (ev.key === 'Tab') { fechar(menu, false); }
    });
})();
