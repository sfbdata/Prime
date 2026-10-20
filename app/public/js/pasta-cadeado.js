/* Cadeado da pasta (spec pasta-cadeado §6; desenho 02 L507-550 e `cdVals`, L6086-6116).
   Abre o modal pelo ⋮ ("Cadeado") ou pelo selo grande, alterna o modo e a grade e aplica os estilos que o
   desenho troca com a escolha (`modoSt` e `NIV`). "Trancar" envia o formulário; quem grava e confere tudo
   é o servidor (pasta_cadeado_salvar). */
(function () {
    'use strict';

    var NIV = {
        oculto: ['#a3232b', '#fdeceb'],
        visualizar: ['#0b5f86', '#e3f0f8'],
        editar: ['#1e7b45', '#e4f4ea']
    };
    var RESUMO_TODOS = 'O usuário primário continua vendo e editando tudo. A regra fica registrada no histórico da pasta.';

    function modal() { return document.getElementById('psCadeadoModal'); }

    function modoSt(on) {
        return 'display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:none;border-radius:4px;cursor:pointer;font-family:inherit;transition:background .15s;'
            + (on ? 'background:#e3f0f8;color:#0b5f86;box-shadow:inset 0 0 0 2px #0a7aad' : 'background:#f5f8fa;color:#34505f');
    }

    function opcaoSt(nivel, on) {
        var c = NIV[nivel];
        return 'width:56px;height:28px;border:none;border-radius:4px;cursor:pointer;font-size:14px;transition:background .15s;'
            + (on ? 'background:' + c[1] + ';color:' + c[0] + ';box-shadow:inset 0 0 0 1.5px ' + c[0] : 'background:transparent;color:#a9b8c2');
    }

    function pintar(m) {
        var modo = m.querySelector('.js-cadeado-modo').value;
        m.querySelectorAll('.js-cadeado-modo-opcao').forEach(function (b) {
            b.setAttribute('style', modoSt(b.getAttribute('data-modo') === modo));
            b.setAttribute('aria-pressed', b.getAttribute('data-modo') === modo ? 'true' : 'false');
        });
        m.querySelector('.js-cadeado-por-pessoa').style.display = modo === 'por_pessoa' ? 'flex' : 'none';
        m.querySelector('.js-cadeado-todos-ocultos').style.display = modo === 'todos' ? 'flex' : 'none';

        var cont = { oculto: 0, visualizar: 0, editar: 0 };
        m.querySelectorAll('.js-cadeado-pessoa').forEach(function (linha) {
            var nivel = linha.querySelector('.js-cadeado-nivel').value;
            cont[nivel] = (cont[nivel] || 0) + 1;
            linha.querySelectorAll('.js-cadeado-opcao').forEach(function (b) {
                var on = b.getAttribute('data-nivel') === nivel;
                b.setAttribute('style', opcaoSt(b.getAttribute('data-nivel'), on));
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        });
        m.querySelector('.js-cadeado-resumo').textContent = modo === 'todos'
            ? RESUMO_TODOS
            : cont.editar + ' editam, ' + cont.visualizar + ' só visualizam, ' + cont.oculto + ' não veem. O usuário primário sempre vê e edita tudo.';
    }

    function abrir() {
        var m = modal();
        if (!m) { return; }
        // Cancelar desfaz: a grade volta ao que está gravado (o `data-nivel` de cada linha vem do servidor).
        m.querySelectorAll('.js-cadeado-pessoa').forEach(function (linha) {
            linha.querySelector('.js-cadeado-nivel').value = linha.getAttribute('data-nivel');
        });
        m.querySelector('.js-cadeado-modo').value = m.getAttribute('data-modo-inicial') || 'por_pessoa';
        m.hidden = false;
        pintar(m);
        var fechar = m.querySelector('.js-cadeado-fechar');
        if (fechar) { fechar.focus(); }
    }

    function fechar() {
        var m = modal();
        if (m) { m.hidden = true; }
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('.js-cadeado-abrir')) { abrir(); return; }
        var m = modal();
        if (!m || m.hidden || !m.contains(e.target)) { return; }
        if (e.target.classList.contains('js-cadeado-fundo') || e.target.closest('.js-cadeado-fechar')) { fechar(); return; }

        var modo = e.target.closest('.js-cadeado-modo-opcao');
        if (modo) { m.querySelector('.js-cadeado-modo').value = modo.getAttribute('data-modo'); pintar(m); return; }

        var todos = e.target.closest('.js-cadeado-todos');
        if (todos) {
            m.querySelectorAll('.js-cadeado-nivel').forEach(function (i) { i.value = todos.getAttribute('data-nivel'); });
            pintar(m);
            return;
        }

        var opcao = e.target.closest('.js-cadeado-opcao');
        if (opcao) {
            opcao.closest('.js-cadeado-pessoa').querySelector('.js-cadeado-nivel').value = opcao.getAttribute('data-nivel');
            pintar(m);
            return;
        }

        if (e.target.closest('.js-cadeado-trancar')) {
            var form = m.querySelector('.js-cadeado-form');
            var porPessoa = m.querySelector('.js-cadeado-modo').value === 'por_pessoa';
            form.querySelectorAll('.js-cadeado-nivel').forEach(function (i) { i.disabled = !porPessoa; });
            form.submit();
        }
    });

    document.addEventListener('keydown', function (e) {
        var m = modal();
        if (!m || m.hidden) {
            if ((e.key === 'Enter' || e.key === ' ') && e.target.closest && e.target.closest('div.js-cadeado-abrir')) { e.preventDefault(); abrir(); }
            return;
        }
        if (e.key === 'Escape') { e.preventDefault(); fechar(); }
    });

    // Chegou pelo aviso do pedido (`#cadeado-pedidos`): a faixa de decisão já está na tela; só rola até ela.
    if (window.location.hash === '#cadeado-pedidos') {
        var faixa = document.getElementById('cadeado-pedidos');
        if (faixa) { faixa.scrollIntoView({ block: 'center' }); }
    }
}());
