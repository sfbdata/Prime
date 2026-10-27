/* Análise individual da pessoa no painel BlueJus Intelligence (desenho 01, botão da linha L720,
   "← Equipe" L787-789 e perfil de desempenho L1098-1190).

   O botão da linha ([data-pf-abrir]) busca o fragmento HTML do servidor (GET, mesmo período do painel),
   injeta no contêiner [data-ia-individual] do painel e abre o painel no modo individual: o corpo da
   leitura da equipe fica escondido (CSS, classe db-ia-painel--pessoa), "← Equipe" aparece e o título
   vira o nome da pessoa. Fechar o painel ou tocar em "← Equipe" volta à equipe. Os botões do perfil
   ("Marcar como analisado" e "Essa análise ajudou?") gravam a marca pessoal por POST e só então mudam a
   tela. "Criar acompanhamento", "Renovar" e "Encerrar acompanhamento" fazem POST próprio (o criar e o renovar
   só depois do clique de confirmação do <dialog>). "Redistribuir" abre um <dialog> com as metas ativas da pessoa
   (GET, tudo desmarcado) e só o clique em "Confirmar redistribuição" faz o POST. Tudo é delegado no document: o painel e a tabela são trocados junto com o fragmento dos filtros.
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

    /* Redistribuir metas (spec painel-acompanhamento-redistribuir, fatia B). A lista chega do servidor TODA desmarcada;
       só o clique de confirmação grava. O resumo é montado a cada marca ou troca de destino. */
    function dialogoRedistribuir(el) {
        return el.closest('[data-pf-red-dlg]');
    }

    function linhasMarcadas(dlg) {
        return Array.prototype.filter.call(dlg.querySelectorAll('[data-pf-red-linha]'), function (l) {
            var m = l.querySelector('[data-pf-red-marca]');
            return m && m.checked && !m.disabled;
        });
    }

    function atualizarResumo(dlg) {
        var marcadas = linhasMarcadas(dlg);
        var lista = dlg.querySelector('[data-pf-red-lista]');
        var limite = lista ? parseInt(lista.getAttribute('data-limite'), 10) : 0;
        var resumo = dlg.querySelector('[data-pf-red-resumo]');
        var porDestino = {};
        var ordem = [];
        marcadas.forEach(function (l) {
            var opcao = l.querySelector('[data-pf-red-destino]').selectedOptions[0];
            var nome = opcao.textContent.replace(/ \(\d+\)$/, '');
            if (!porDestino[nome]) { porDestino[nome] = 0; ordem.push(nome); }
            porDestino[nome]++;
        });
        var n = marcadas.length;
        resumo.hidden = n === 0;
        resumo.textContent = n === 0 ? '' : dlg.getAttribute('data-txt-resumo')
            .replace('{metas}', n + ' ' + dlg.getAttribute(n === 1 ? 'data-txt-uma' : 'data-txt-varias'))
            .replace('{origem}', dlg.getAttribute('data-txt-origem'))
            .replace('{destinos}', ordem.map(function (nome) { return nome + ' (' + porDestino[nome] + ')'; }).join(', '));
        dlg.querySelector('[data-pf-red-confirmar]').disabled = n === 0 || n > limite;
    }

    function erroRedistribuir(dlg, texto) {
        var quadro = dlg.querySelector('[data-pf-red-erro]');
        quadro.textContent = texto;
        quadro.hidden = !texto;
    }

    function abrirRedistribuir(botao) {
        var perfil = botao.closest('[data-pf]');
        var dlg = perfil ? perfil.querySelector('[data-pf-red-dlg]') : null;
        if (!dlg) { return; }
        var corpo = dlg.querySelector('[data-pf-red-corpo]');
        corpo.textContent = dlg.getAttribute('data-txt-carregando');
        erroRedistribuir(dlg, '');
        dlg.querySelector('[data-pf-red-resumo]').hidden = true;
        dlg.querySelector('[data-pf-red-confirmar]').disabled = true;
        if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
        fetch(perfil.getAttribute('data-url-redistribuir'), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.text();
        }).then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            corpo.replaceChildren.apply(corpo, Array.prototype.slice.call(doc.body.childNodes));
            atualizarResumo(dlg);
        }).catch(function () {
            corpo.textContent = dlg.getAttribute('data-txt-erro-carregar');
        });
    }

    function confirmarRedistribuicao(botao) {
        var dlg = dialogoRedistribuir(botao);
        var perfil = botao.closest('[data-pf]');
        var movimentos = linhasMarcadas(dlg).map(function (l) {
            return {
                meta_id: parseInt(l.getAttribute('data-meta'), 10),
                destino_id: parseInt(l.querySelector('[data-pf-red-destino]').value, 10)
            };
        });
        if (!perfil || movimentos.length === 0 || botao.disabled) { return; }
        botao.disabled = true;
        enviarAcompanhamento(perfil, perfil.getAttribute('data-url-redistribuir'), { movimentos: movimentos })
            .then(function (r) {
                fecharDialogo(dlg);
                var chave = r.puladas > 0 ? 'data-txt-red-parcial' : 'data-txt-red-concluida';
                avisarAcompanhamento(perfil, perfil.getAttribute(chave)
                    .replace('{passadas}', r.passadas).replace('{total}', r.total).replace('{puladas}', r.puladas));
            }).catch(function (erro) {
                erroRedistribuir(dlg, erro.message || dlg.getAttribute('data-txt-erro'));
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
        var alvo = e.target.closest ? e.target.closest('[data-pf-abrir], [data-ia-pessoa-voltar], [data-pf-marcar], [data-pf-comparar], [data-pf-acomp-abrir], [data-pf-acomp-encerrar], [data-pf-dlg-fechar], [data-pf-dlg-confirmar], [data-pf-red-abrir], [data-pf-red-fechar], [data-pf-red-confirmar]') : null;
        if (!alvo) { return; }
        if (alvo.hasAttribute('data-pf-abrir')) { abrir(alvo); }
        else if (alvo.hasAttribute('data-ia-pessoa-voltar')) { modoEquipe(); }
        else if (alvo.hasAttribute('data-pf-marcar')) { marcar(alvo); }
        else if (alvo.hasAttribute('data-pf-acomp-abrir')) { abrirDialogo(alvo); }
        else if (alvo.hasAttribute('data-pf-acomp-encerrar')) { encerrarAcompanhamento(alvo); }
        else if (alvo.hasAttribute('data-pf-dlg-fechar')) { fecharDialogo(alvo.closest('[data-pf-dlg]')); }
        else if (alvo.hasAttribute('data-pf-dlg-confirmar')) { confirmarDialogo(alvo); }
        else if (alvo.hasAttribute('data-pf-red-abrir')) { abrirRedistribuir(alvo); }
        else if (alvo.hasAttribute('data-pf-red-fechar')) { fecharDialogo(dialogoRedistribuir(alvo)); }
        else if (alvo.hasAttribute('data-pf-red-confirmar')) { confirmarRedistribuicao(alvo); }
        else { alternarComparacao(alvo); }
    });

    document.addEventListener('change', function (e) {
        var alvo = e.target && e.target.closest ? e.target.closest('[data-pf-red-marca], [data-pf-red-destino]') : null;
        if (alvo) { atualizarResumo(dialogoRedistribuir(alvo)); }
    });

    /* Fechar o painel volta à equipe: a próxima abertura pelo botão "BlueJus Intelligence" mostra a leitura da equipe. */
    document.addEventListener('toggle', function (e) {
        if (e.target && e.target.id === 'dbIaPainel' && e.newState === 'closed') { modoEquipe(); }
    }, true);
})();
