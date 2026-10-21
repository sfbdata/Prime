/**
 * Dashboard — janela "Histórico" dos cards (DASH-017), sobre as janelas flutuantes (DASH-020,
 * public/js/dashboard-janelas.js).
 *
 * Botão direito num dos 4 cards (`.db-stat-card[data-historico-url]`) abre a janela do card,
 * junto ao cursor. O link "ver …" dentro do card continua com o menu do navegador (abrir em nova
 * aba etc.). O servidor devolve o modelo de exibição pronto — barras, melhor mês, os 3
 * indicadores e o rodapé (ObterHistoricoDoCardUseCase); aqui só se desenha.
 *
 * Recorte: a janela leva o responsável e o cargo que estão no filtro do painel NA HORA do clique
 * (o mesmo universo do número do card). Recorte diferente abre outra janela; o mesmo só traz a
 * já aberta para a frente. Cada janela guarda o próprio ano escolhido.
 *
 * O ouvinte é delegado no `[data-filtro-root]` persistente: o fragmento dos cards é trocado a
 * cada filtro (filtro-tabela.js), então nada aqui guarda referência a card.
 *
 * Nada de HTML montado por string: todo texto vindo do servidor entra por textContent.
 */
(function () {
    'use strict';

    // Título (chave do catálogo) e ícone enquanto a resposta não chega (a resposta traz os definitivos).
    var VISUAL = {
        pastas:   { titulo: 'dashboard.js.historico.pastas_criadas', icone: 'bi-folder-plus' },
        metas:    { titulo: 'dashboard.js.historico.metas_ativas', icone: 'bi-check2-square' },
        urgentes: { titulo: 'dashboard.js.historico.demandas_urgentes', icone: 'bi-flag-fill' },
        global:   { titulo: 'dashboard.js.historico.meta_global_batida', icone: 'bi-bullseye' }
    };

    function el(tag, classe, texto) {
        var n = document.createElement(tag);
        if (classe) { n.className = classe; }
        if (texto !== undefined && texto !== null) { n.textContent = String(texto); }

        return n;
    }

    function filtrosDoPainel(root) {
        var form = root.querySelector('[data-filtro-form]');
        var ler = function (nome) {
            var campo = form ? form.querySelector('[name="' + nome + '"]') : null;

            return campo ? String(campo.value || '').trim() : '';
        };

        return { responsavel: ler('responsavel'), cargo: ler('cargo') };
    }

    function aviso(corpo, texto) {
        corpo.replaceChildren(el('p', 'db-hist-aviso', texto));
    }

    function desenharOpcao(area, opcao, tituloCard) {
        var barras = el('div', 'db-hist-barras');
        barras.setAttribute('role', 'img');
        barras.setAttribute('aria-label', tituloCard + ': ' + opcao.barras.map(function (b) { return b.titulo; }).join('; '));
        var rotulos = el('div', 'db-hist-rotulos');
        rotulos.setAttribute('aria-hidden', 'true');

        opcao.barras.forEach(function (b, i) {
            var col = el('div', 'db-hist-col');
            col.title = b.titulo;
            if (b.valor === null) { col.classList.add('is-vazio'); }
            if (b.melhor) { col.classList.add('is-melhor'); }
            if (b.atual) { col.classList.add('is-atual'); }
            col.appendChild(el('span', 'db-hist-valor', b.mostrarValor ? b.valor : ''));
            var barra = el('span', 'db-hist-barra');
            barra.style.height = Number(b.altura) + 'px';
            barra.style.animationDelay = (i * 0.03).toFixed(2) + 's';
            col.appendChild(barra);
            barras.appendChild(col);

            var r = el('span', 'db-hist-rotulo', b.rotulo);
            if (b.valor === null) { r.classList.add('is-vazio'); }
            rotulos.appendChild(r);
        });

        var stats = el('div', 'db-hist-stats');
        opcao.stats.forEach(function (t) {
            var caixa = el('div', 'db-hist-stat');
            caixa.appendChild(el('span', 'db-hist-stat-rotulo', t.rotulo));
            var tom = ['ouro', 'bom', 'ruim', 'igual'].indexOf(t.tom) !== -1 ? ' db-hist-stat-valor--' + t.tom : '';
            caixa.appendChild(el('span', 'db-hist-stat-valor' + tom, t.valor));
            caixa.appendChild(el('span', 'db-hist-stat-sub', t.sub));
            stats.appendChild(caixa);
        });

        area.grafico.replaceChildren(barras, rotulos);
        area.stats.replaceWith(stats);
        area.stats = stats;
    }

    function desenhar(corpo, d) {
        if (!d.disponivel) {
            corpo.replaceChildren(
                el('p', 'db-hist-aviso', window.BJ_T.t('dashboard.js.historico.sem_historico')),
                el('div', 'db-hist-nota', d.nota)
            );

            return;
        }

        var graf = el('div', 'db-hist-graf');
        var anos = el('div', 'db-hist-anos');
        anos.setAttribute('role', 'group');
        anos.setAttribute('aria-label', window.BJ_T.t('dashboard.js.historico.ano'));
        var grafico = el('div', 'db-hist-grafico');
        graf.appendChild(anos);
        graf.appendChild(grafico);
        var area = { grafico: grafico, stats: el('div', 'db-hist-stats') };

        var botoes = [];
        function escolher(chave) {
            var opcao = null;
            d.opcoes.forEach(function (o) { if (o.chave === chave) { opcao = o; } });
            if (!opcao) { return; }
            botoes.forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.ano === chave ? 'true' : 'false'); });
            desenharOpcao(area, opcao, d.titulo);
        }

        d.opcoes.forEach(function (o) {
            var b = el('button', 'db-hist-ano', o.rotulo);
            b.type = 'button';
            b.dataset.ano = o.chave;
            b.addEventListener('click', function () { escolher(o.chave); });
            botoes.push(b);
            anos.appendChild(b);
        });

        corpo.replaceChildren(graf, area.stats, el('div', 'db-hist-nota', d.nota));
        escolher(d.inicial);
    }

    function carregar(url, janela) {
        aviso(janela.corpo, window.BJ_T.t('dashboard.js.historico.carregando'));
        window.fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }

            return r.json();
        }).then(function (d) {
            if (!janela.elemento.isConnected) { return; } // fechada antes da resposta
            janela.titulo.textContent = d.titulo;
            var sub = janela.elemento.querySelector('.db-janela-sub');
            if (sub) { sub.textContent = d.subtitulo; }
            desenhar(janela.corpo, d);
        }).catch(function () {
            if (janela.elemento.isConnected) {
                aviso(janela.corpo, window.BJ_T.t('dashboard.js.historico.erro'));
            }
        });
    }

    function ligar(root) {
        root.addEventListener('contextmenu', function (e) {
            var card = e.target.closest('.db-stat-card[data-historico-url]');
            if (!card || !root.contains(card) || !window.DbJanelas) { return; }
            // Link e campo dentro do card ficam com o menu do navegador.
            if (e.target.closest('a, button, input, select, textarea')) { return; }
            e.preventDefault();

            var tipo = card.getAttribute('data-historico') || 'pastas';
            var visual = VISUAL[tipo] || VISUAL.pastas;
            var f = filtrosDoPainel(root);
            var url = new URL(card.getAttribute('data-historico-url'), window.location.origin);
            if (f.responsavel !== '') { url.searchParams.set('responsavel', f.responsavel); }
            if (f.cargo !== '') { url.searchParams.set('cargo', f.cargo); }

            window.DbJanelas.abrir({
                chave: 'hist:' + tipo + '|' + f.responsavel + '|' + f.cargo,
                titulo: window.BJ_T.t(visual.titulo),
                subtitulo: window.BJ_T.t('dashboard.js.historico.subtitulo'),
                icone: visual.icone,
                classe: 'db-hist db-hist--' + tipo,
                largura: 330,
                altura: 420, // altura da janela pronta (desenho: H0): a posição já reserva o espaço
                x: e.clientX,
                y: e.clientY,
                origem: card,
                montar: function (corpo, janela) { carregar(url.toString(), janela); }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.db-page [data-filtro-root]').forEach(ligar);
    });
})();
