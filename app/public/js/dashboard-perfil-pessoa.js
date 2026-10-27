/* Análise individual da pessoa no painel BlueJus Intelligence (desenho 01, botão da linha L720,
   "← Equipe" L787-789 e perfil de desempenho L1098-1190).

   O botão da linha ([data-pf-abrir]) busca o fragmento HTML do servidor (GET, mesmo período do painel),
   injeta no contêiner [data-ia-individual] do painel e abre o painel no modo individual: o corpo da
   leitura da equipe fica escondido (CSS, classe db-ia-painel--pessoa), "← Equipe" aparece e o título
   vira o nome da pessoa. Fechar o painel ou tocar em "← Equipe" volta à equipe. Os botões do perfil
   ("Marcar como analisado" e "Essa análise ajudou?") gravam a marca pessoal por POST e só então mudam a
   tela. "Criar acompanhamento", "Renovar" e "Encerrar acompanhamento" fazem POST próprio (o criar e o renovar
   só depois do clique de confirmação do <dialog>). Tudo é delegado no document: o painel e a tabela são trocados junto com o fragmento dos filtros.
   Textos vêm do HTML (data-*), traduzidos no servidor. Nada de HTML montado por string. */
(function () {
    'use strict';

    var CLASSE = 'db-ia-painel--pessoa';
    var pedido = 0;

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function painel() { return document.getElementById('dbIaPainel'); }

    function partes() {
        var p = painel();
        if (!p) { return null; }
        return {
            painel: p,
            lugar: p.querySelector('[data-ia-individual]'),
            voltar: p.querySelector('[data-ia-pessoa-voltar]'),
            titulo: p.querySelector('[data-ia-titulo]')
        };
    }

    function periodo() {
        var form = document.querySelector('[data-filtro-root] [data-filtro-form]');
        var todos = new URLSearchParams(form ? new FormData(form) : '');
        var params = new URLSearchParams();
        ['data_de', 'data_ate'].forEach(function (k) { if (todos.has(k)) { params.set(k, todos.get(k)); } });

        return params.toString();
    }

    function modoEquipe() {
        var x = partes();
        if (!x) { return; }
        pedido++;
        x.painel.classList.remove(CLASSE);
        x.lugar.hidden = true;
        x.lugar.replaceChildren();
        x.voltar.hidden = true;
        x.titulo.textContent = x.titulo.getAttribute('data-titulo-equipe');
    }

    function aviso(x, texto) {
        var d = document.createElement('div');
        d.className = 'db-pf-aviso';
        d.setAttribute('role', 'status');
        d.textContent = texto;
        x.lugar.replaceChildren(d);
    }

    function abrirPainel(p) {
        try {
            if (!p.matches(':popover-open')) { p.showPopover(); }
        } catch (e) { /* sem Popover API, o painel já está aberto em bloco */ }
    }

    function abrir(botao) {
        var x = partes();
        if (!x || botao.disabled) { return; }
        var meu = ++pedido;
        x.painel.classList.add(CLASSE);
        x.lugar.hidden = false;
        x.voltar.hidden = false;
        aviso(x, x.lugar.getAttribute('data-txt-carregando'));
        abrirPainel(x.painel);
        var consulta = periodo();
        fetch(botao.getAttribute('data-url') + (consulta ? '?' + consulta : ''), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.text();
        }).then(function (html) {
            if (meu !== pedido) { return; }
            var perfil = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-pf]');
            if (!perfil) { throw new Error('sem perfil'); }
            x.lugar.replaceChildren(perfil);
            x.titulo.textContent = perfil.getAttribute('data-titulo');
        }).catch(function () {
            if (meu === pedido) { aviso(x, x.lugar.getAttribute('data-txt-erro')); }
        });
    }

    function mostrarMarca(perfil, marca) {
        Array.prototype.forEach.call(perfil.querySelectorAll('[data-pf-marcar]'), function (b) {
            var acao = b.getAttribute('data-pf-marcar');
            var ligado = acao === 'analisado' ? marca.status === 'analisado' : marca.feedback === acao;
            b.setAttribute('aria-pressed', ligado ? 'true' : 'false');
        });
        var selo = perfil.querySelector('[data-pf-status]');
        if (selo) { selo.setAttribute('data-marca', marca.status === 'analisado' ? 'analisado' : ''); }
        mostrarEstado(perfil);
    }

    /* Chip do perfil. Precedência: Em acompanhamento (ou Revisar acompanhamento, se venceu), Analisado, Pendente. */
    function mostrarEstado(perfil) {
        var selo = perfil.querySelector('[data-pf-status]');
        if (!selo) { return; }
        var aberto = perfil.getAttribute('data-acomp-aberto') === '1';
        var vencido = perfil.getAttribute('data-acomp-vencido') === '1';
        var chave = aberto ? 'acomp' : (selo.getAttribute('data-marca') === 'analisado' ? 'analisado' : 'pendente');
        var rotulo = aberto && vencido ? 'acomp-vencido' : chave;
        selo.textContent = selo.getAttribute('data-rotulo-' + rotulo);
        selo.className = 'db-pf-status db-pf-status--' + chave;
    }

    function marcar(botao) {
        var perfil = botao.closest('[data-pf]');
        if (!perfil || botao.disabled) { return; }
        botao.disabled = true;
        fetch(perfil.getAttribute('data-url-marca'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token() },
            body: JSON.stringify({ acao: botao.getAttribute('data-pf-marcar') })
        }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.json();
        }).then(function (marca) {
            mostrarMarca(perfil, marca);
        }).catch(function () {
            /* sem gravar, a tela fica como estava */
        }).then(function () {
            botao.disabled = false;
        });
    }

    /* Acompanhamento da pessoa (spec painel-acompanhamento-redistribuir, fatia A). Nada é criado sem o clique
       de confirmação do <dialog>. Os textos vêm do HTML (data-*); o servidor devolve só o estado. */
    function aplicarAcompanhamento(perfil, estado, aviso) {
        perfil.setAttribute('data-acomp-aberto', estado.aberto ? '1' : '0');
        perfil.setAttribute('data-acomp-vencido', estado.vencido ? '1' : '0');
        var mostrar = function (seletor, visivel) {
            var el = perfil.querySelector(seletor);
            if (el) { el.hidden = !visivel; }
        };
        mostrar('[data-pf-acomp-abrir="criar"]', !estado.aberto);
        mostrar('[data-pf-acomp-abrir="renovar"]', estado.aberto);
        mostrar('[data-pf-acomp-encerrar]', estado.aberto);
        var revisao = perfil.querySelector('[data-pf-revisao]');
        if (revisao) {
            revisao.hidden = !estado.aberto;
            revisao.textContent = estado.aberto ? revisao.getAttribute('data-txt').replace('{data}', estado.revisar_ate) : '';
        }
        mostrarEstado(perfil);
        avisarAcompanhamento(perfil, aviso);
    }

    function avisarAcompanhamento(perfil, texto) {
        var quadro = perfil.querySelector('[data-pf-aviso-acomp]');
        if (quadro && texto) {
            quadro.textContent = texto;
            quadro.hidden = false;
        }
    }

    function enviarAcompanhamento(perfil, url, corpo) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token() },
            body: JSON.stringify(corpo)
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (dados) {
                if (!r.ok) { throw new Error(dados.erro || perfil.getAttribute('data-txt-erro-acomp')); }
                return dados;
            });
        });
    }

    function abrirDialogo(botao) {
        var perfil = botao.closest('[data-pf]');
        var dlg = perfil ? perfil.querySelector('[data-pf-dlg]') : null;
        if (!dlg) { return; }
        var modo = botao.getAttribute('data-pf-acomp-abrir');
        dlg.setAttribute('data-modo', modo);
        dlg.querySelector('[data-pf-dlg-titulo]').textContent = dlg.getAttribute('data-titulo-' + modo);
        dlg.querySelector('[data-pf-dlg-confirmar]').textContent = dlg.getAttribute('data-botao-' + modo);
        dlg.querySelector('[data-pf-dlg-erro]').hidden = true;
        var padrao = dlg.querySelector('input[name="prazo_dias"][value="7"]');
        if (padrao) { padrao.checked = true; }
        if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
    }

    function fecharDialogo(dlg) {
        if (typeof dlg.close === 'function') { dlg.close(); } else { dlg.removeAttribute('open'); }
    }

    function confirmarDialogo(botao) {
        var dlg = botao.closest('[data-pf-dlg]');
        var perfil = botao.closest('[data-pf]');
        var escolhido = dlg.querySelector('input[name="prazo_dias"]:checked');
        if (!perfil || !escolhido || botao.disabled) { return; }
        var modo = dlg.getAttribute('data-modo') === 'renovar' ? 'renovar' : 'criar';
        botao.disabled = true;
        enviarAcompanhamento(perfil, perfil.getAttribute('data-url-acomp-' + modo), { prazo_dias: parseInt(escolhido.value, 10) })
            .then(function (estado) {
                fecharDialogo(dlg);
                aplicarAcompanhamento(perfil, estado, perfil.getAttribute(modo === 'renovar' ? 'data-txt-renovado' : 'data-txt-criado'));
            }).catch(function (erro) {
                var quadro = dlg.querySelector('[data-pf-dlg-erro]');
                quadro.textContent = erro.message;
                quadro.hidden = false;
            }).then(function () {
                botao.disabled = false;
            });
    }

    function encerrarAcompanhamento(botao) {
        var perfil = botao.closest('[data-pf]');
        if (!perfil || botao.disabled) { return; }
        botao.disabled = true;
        enviarAcompanhamento(perfil, perfil.getAttribute('data-url-acomp-encerrar'), {})
            .then(function (estado) {
                aplicarAcompanhamento(perfil, estado, perfil.getAttribute('data-txt-encerrado'));
            }).catch(function (erro) {
                avisarAcompanhamento(perfil, erro.message);
            }).then(function () {
                botao.disabled = false;
            });
    }

    function alternarComparacao(botao) {
        var secao = botao.closest('[data-pf-comp]');
        if (!secao) { return; }
        var aberto = !secao.classList.contains('is-aberto');
        secao.classList.toggle('is-aberto', aberto);
        botao.setAttribute('aria-expanded', aberto ? 'true' : 'false');
        var legenda = secao.querySelector('[data-pf-legenda]');
        if (legenda) { legenda.hidden = !aberto; }
    }

    document.addEventListener('click', function (e) {
        var alvo = e.target.closest ? e.target.closest('[data-pf-abrir], [data-ia-pessoa-voltar], [data-pf-marcar], [data-pf-comparar], [data-pf-acomp-abrir], [data-pf-acomp-encerrar], [data-pf-dlg-fechar], [data-pf-dlg-confirmar]') : null;
        if (!alvo) { return; }
        if (alvo.hasAttribute('data-pf-abrir')) { abrir(alvo); }
        else if (alvo.hasAttribute('data-ia-pessoa-voltar')) { modoEquipe(); }
        else if (alvo.hasAttribute('data-pf-marcar')) { marcar(alvo); }
        else if (alvo.hasAttribute('data-pf-acomp-abrir')) { abrirDialogo(alvo); }
        else if (alvo.hasAttribute('data-pf-acomp-encerrar')) { encerrarAcompanhamento(alvo); }
        else if (alvo.hasAttribute('data-pf-dlg-fechar')) { fecharDialogo(alvo.closest('[data-pf-dlg]')); }
        else if (alvo.hasAttribute('data-pf-dlg-confirmar')) { confirmarDialogo(alvo); }
        else { alternarComparacao(alvo); }
    });

    /* Fechar o painel volta à equipe: a próxima abertura pelo botão "BlueJus Intelligence" mostra a leitura da equipe. */
    document.addEventListener('toggle', function (e) {
        if (e.target && e.target.id === 'dbIaPainel' && e.newState === 'closed') { modoEquipe(); }
    }, true);
})();
