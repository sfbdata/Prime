/* =============================================================================
   expediente-trilho.js: o trilho da Lista do Expediente no desenho 1.1 (dc L.74-95, E12).

   - "Todas as pastas" e o ↻: voltam ao acervo geral sem filtros, com o efeito de busca e o aviso.
   - EXPEDIENTES: filtros do sistema; clique liga e desliga, botão direito abre a nota de cada um.
     "Audiência" e "Protocolar" ficam desativados (em breve, sem dado no sistema).
   - MARCADORES: clique abre as pastas do marcador (o de topo traz também as dos submarcadores); a seta
     abre os submarcadores; ⋮ no hover e botão direito abrem o menu do desenho; arrastar e soltar reordena
     (mesmo nível); o ⋮ do cabeçalho tem Novo marcador, Ver todas as pastas e Recolher painel.
   - Modal do marcador (_modais_lista): nome, descrição, "Dentro de", 10 cores e prévia.

   O trilho é HTML do servidor (_trilho.html.twig). Depois de criar, editar, excluir ou reordenar, ele é
   pedido de novo (`expediente_trilho`) e o estado da tela é reaplicado: item aberto, grupos abertos,
   recolhido. Nada aqui monta HTML com dado: só textContent e atributos.
   ============================================================================= */
(function () {
    'use strict';

    var CHAVE_RECOLHIDO = 'expediente_sidebar_collapsed';
    var CHAVE_GRUPOS = 'expediente_mk_abertos';
    var CORES = ['#0a6fa0', '#5b4fb5', '#c0392b', '#d18a12', '#3a7bb8', '#4a6373', '#1f8a4c', '#c2457a', '#0f8b8d', '#8a5a12'];

    var ativo = { tipo: 'todas', valor: '' };   // item aberto no painel
    var arrastando = null;

    function ler(chave, padrao) {
        try { var v = localStorage.getItem(chave); return v === null ? padrao : v; } catch (e) { return padrao; }
    }
    function gravar(chave, valor) {
        try { localStorage.setItem(chave, valor); } catch (e) { /* sem armazenamento: só não lembra */ }
    }
    function gruposAbertos() {
        try { return JSON.parse(ler(CHAVE_GRUPOS, '{}')) || {}; } catch (e) { return {}; }
    }

    function trilho() { return document.getElementById('expTrilho'); }
    function lateral() { return document.getElementById('expedienteSidebar'); }
    function aviso(t) { if (window.ExpAviso) { window.ExpAviso(t); } }
    function painel() { return window.ExpPainel; }

    /* ── Estado reaplicado a cada desenho ─────────────────────────────── */
    function aplicarRecolhido() {
        var lado = lateral();
        var t = trilho();
        if (!lado || !t) { return; }
        var recolhido = ler(CHAVE_RECOLHIDO, '0') === '1';
        lado.classList.toggle('sidebar-collapsed', recolhido);
        t.classList.toggle('is-recolhido', recolhido);
        var botao = t.querySelector('.js-tri-recolher');
        if (botao) {
            botao.setAttribute('data-tip', recolhido ? 'Expandir painel' : 'Recolher painel');
            botao.setAttribute('aria-expanded', recolhido ? 'false' : 'true');
            var i = botao.querySelector('i');
            if (i) { i.className = 'bi ' + (recolhido ? 'bi-chevron-right' : 'bi-chevron-left'); }
        }
        // Recolhido, a dica dos filtros do sistema mostra o nome (dc `tipFech`).
        t.querySelectorAll('.exp-tri-sistema:not(.is-em-breve)').forEach(function (el) {
            if (recolhido) { el.setAttribute('data-tip', el.getAttribute('data-tip-fechado')); } else { el.removeAttribute('data-tip'); }
        });
    }

    function alternarRecolhido() {
        gravar(CHAVE_RECOLHIDO, ler(CHAVE_RECOLHIDO, '0') === '1' ? '0' : '1');
        aplicarRecolhido();
    }

    function aplicarGrupos() {
        var abertos = gruposAbertos();
        var t = trilho();
        if (!t) { return; }
        t.querySelectorAll('.exp-tri-grupo').forEach(function (g) {
            var item = g.querySelector('.exp-tri-marcador');
            var subs = g.querySelector('.exp-tri-subs');
            if (!item || !subs) { return; }
            var aberto = !!abertos[item.dataset.marcadorId] || !!subs.querySelector('.exp-tri-item.active');
            subs.hidden = !aberto;
            var seta = g.querySelector('.js-tri-abrir');
            if (seta) {
                seta.setAttribute('aria-expanded', aberto ? 'true' : 'false');
                seta.setAttribute('data-tip', aberto ? 'Recolher submarcadores' : 'Ver ' + item.dataset.filhos + ' submarcador(es)');
                var i = seta.querySelector('i');
                if (i) { i.className = 'bi ' + (aberto ? 'bi-chevron-down' : 'bi-chevron-right'); }
            }
        });
    }

    function itemDoAtivo() {
        var t = trilho();
        if (!t) { return null; }
        if (ativo.tipo === 'exp') { return t.querySelector('.exp-tri-sistema[data-expediente="' + ativo.valor + '"]'); }
        if (ativo.tipo === 'mk') { return t.querySelector('.exp-tri-marcador[data-marcador-id="' + ativo.valor + '"]'); }
        return t.querySelector('.exp-tri-todas');
    }

    function aplicarAtivo() {
        var el = itemDoAtivo();
        if (painel()) { painel().marcar(el); }
        aplicarGrupos();
    }

    function aplicarTudo() {
        aplicarRecolhido();
        aplicarAtivo();
        atualizarMoverPara();
    }

    /* A lista do modal "Mover para" (compartilhado com a Pasta) acompanha o trilho. */
    function atualizarMoverPara() {
        if (typeof window.atualizarMarcadoresDoMoverPara !== 'function') { return; }
        var lista = [];
        document.querySelectorAll('#expTrilho .exp-tri-marcador').forEach(function (el) {
            lista.push({ id: +el.dataset.marcadorId, nome: el.dataset.nome, cor: el.dataset.cor || null, paiId: el.dataset.paiId ? +el.dataset.paiId : null });
        });
        window.atualizarMarcadoresDoMoverPara(lista);
    }

    function recarregar() {
        var t = trilho();
        if (!t) { return Promise.resolve(); }
        return fetch(t.dataset.urlTrilho, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var novo = doc.getElementById('expTrilho');
                var atual = trilho();
                if (novo && atual) { atual.replaceWith(document.importNode(novo, true)); }
                aplicarTudo();
            })
            .catch(function () { /* o trilho antigo continua; a próxima ação tenta de novo */ });
    }

    /* ── Abrir painéis ────────────────────────────────────────────────── */
    function abrirTodas(comAviso) {
        var t = trilho();
        ativo = { tipo: 'todas', valor: '' };
        aplicarAtivo();
        painel().carregar(t.dataset.urlAcervo, 'Todas as pastas', { efeito: true });
        if (comAviso) { aviso('Lista atualizada · todas as pastas visíveis'); }
    }

    function abrirSistema(el) {
        if (el.classList.contains('is-em-breve')) { abrirNota(el); return; }
        if (ativo.tipo === 'exp' && ativo.valor === el.dataset.expediente) { abrirTodas(false); return; }
        ativo = { tipo: 'exp', valor: el.dataset.expediente };
        aplicarAtivo();
        painel().carregar(trilho().dataset.urlAcervo + '?expediente=' + encodeURIComponent(el.dataset.expediente), el.dataset.nome, { efeito: true });
    }

    function abrirMarcador(el) {
        if (ativo.tipo === 'mk' && ativo.valor === el.dataset.marcadorId) { abrirTodas(false); return; }
        ativo = { tipo: 'mk', valor: el.dataset.marcadorId };
        aplicarAtivo();
        painel().carregar(painel().urlMarcador(el.dataset.marcadorId), el.dataset.nome, { efeito: true });
    }

    /* ── Nota dos filtros do sistema (botão direito, dc L.96-99) ───────── */
    var nota = null;
    function fecharNota() { if (nota) { nota.remove(); nota = null; } }
    function abrirNota(el, x, y) {
        fecharNota();
        var r = el.getBoundingClientRect();
        x = x || r.left + 24;
        y = y || r.bottom;
        nota = document.createElement('div');
        nota.className = 'exp-nota';
        nota.setAttribute('role', 'note');
        var cab = document.createElement('div');
        cab.className = 'exp-nota-cab';
        var i = document.createElement('i');
        i.className = 'bi bi-info-circle';
        i.setAttribute('aria-hidden', 'true');
        var nome = document.createElement('span');
        nome.textContent = el.dataset.nome;
        var x0 = document.createElement('button');
        x0.type = 'button';
        x0.setAttribute('aria-label', 'Fechar');
        var xi = document.createElement('i');
        xi.className = 'bi bi-x-lg';
        xi.setAttribute('aria-hidden', 'true');
        x0.appendChild(xi);
        x0.addEventListener('click', fecharNota);
        cab.appendChild(i); cab.appendChild(nome); cab.appendChild(x0);
        var txt = document.createElement('div');
        txt.textContent = el.dataset.nota;
        nota.appendChild(cab); nota.appendChild(txt);
        document.body.appendChild(nota);
        nota.style.left = Math.max(8, Math.min(x, window.innerWidth - 330)) + 'px';
        nota.style.top = Math.max(8, Math.min(y + 6, window.innerHeight - nota.offsetHeight - 8)) + 'px';
    }

    /* ── Menus ────────────────────────────────────────────────────────── */
    function irmaos(el) {
        var seletor = el.dataset.paiId
            ? '.exp-tri-marcador[data-pai-id="' + el.dataset.paiId + '"]'
            : '.exp-tri-marcador[data-pai-id=""]';
        return Array.prototype.slice.call(trilho().querySelectorAll(seletor));
    }

    function menuDoMarcador(el, x, y) {
        var lista = [
            { rotulo: 'Ver pastas do marcador', icone: 'bi-funnel', acao: function () { if (!(ativo.tipo === 'mk' && ativo.valor === el.dataset.marcadorId)) { abrirMarcador(el); } } },
            { rotulo: 'Novo marcador', icone: 'bi-plus-lg', acao: function () { abrirModal({}); } },
            { rotulo: 'Novo submarcador', icone: 'bi-diagram-2', acao: function () {
                var pai = el.dataset.paiId ? trilho().querySelector('.exp-tri-marcador[data-marcador-id="' + el.dataset.paiId + '"]') : el;
                abrirModal({ pai: pai.dataset.marcadorId, paiNome: pai.dataset.nome, cor: pai.dataset.corTela });
            } },
            { rotulo: 'Mover para cima', icone: 'bi-arrow-up', acao: function () { moverVizinho(el, -1); } },
            { rotulo: 'Mover para baixo', icone: 'bi-arrow-down', acao: function () { moverVizinho(el, 1); } },
            { rotulo: 'Editar', icone: 'bi-pencil', acao: function () { abrirModal({ el: el }); } },
            { rotulo: 'Duplicar', icone: 'bi-files', acao: function () { duplicar(el); } },
            '-',
            { rotulo: 'Excluir marcador', icone: 'bi-trash3', perigo: true, acao: function () { excluir(el); } }
        ];
        window.ExpMenu.abrir(el.dataset.nome + ' · ' + (el.dataset.n || '0') + ' pasta(s)', lista, x, y, el, 'marcador');
    }

    function menuDoCabecalho(botao) {
        var r = botao.getBoundingClientRect();
        var n = trilho().querySelectorAll('.exp-tri-marcador').length;
        var recolhido = ler(CHAVE_RECOLHIDO, '0') === '1';
        window.ExpMenu.abrir('Marcadores · ' + n + ' marcador(es)', [
            { rotulo: 'Novo marcador', icone: 'bi-plus-lg', acao: function () { abrirModal({}); } },
            { rotulo: 'Ver todas as pastas', icone: 'bi-folder2', acao: function () { abrirTodas(true); } },
            { rotulo: recolhido ? 'Expandir painel' : 'Recolher painel', icone: 'bi-chevron-bar-left', acao: alternarRecolhido }
        ], r.left, r.bottom + 4, botao, 'marcador');
    }

    /* ── Reordenar ────────────────────────────────────────────────────── */
    function reordenar(id, alvoId, depois) {
        var t = trilho();
        var corpo = new URLSearchParams({ _token: t.dataset.csrfLista, alvo_id: alvoId, depois: depois ? '1' : '0' });
        return fetch(t.dataset.urlReordenar.replace('/0/', '/' + id + '/'), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: corpo.toString()
        })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (res) {
                if (!res.ok) { aviso(res.d.erro || 'Não foi possível reordenar'); return; }
                aviso('Ordem dos marcadores atualizada');
                return recarregar();
            })
            .catch(function () { aviso('Falha de conexão ao reordenar'); });
    }

    function moverVizinho(el, passo) {
        var lista = irmaos(el);
        var i = lista.indexOf(el);
        var alvo = lista[i + passo];
        if (!alvo) { return; }
        reordenar(el.dataset.marcadorId, alvo.dataset.marcadorId, passo > 0);
    }

    /* ── Modal do marcador ────────────────────────────────────────────── */
    var modal = { dados: null, cor: CORES[0] };

    function elModal() { return document.getElementById('expMkModal'); }

    function pintarCor(cor) {
        modal.cor = cor;
        elModal().querySelectorAll('.exp-mk-cor').forEach(function (b) {
            var sim = b.dataset.cor === cor;
            b.setAttribute('aria-checked', sim ? 'true' : 'false');
            b.classList.toggle('is-escolhida', sim);
            b.querySelector('i').className = 'bi' + (sim ? ' bi-check-lg' : '');
        });
        pintarPrevia();
    }

    function pintarPrevia() {
        var prev = document.getElementById('expMkPrevia');
        var nome = document.getElementById('expMkNome').value.trim();
        prev.querySelector('span').textContent = nome || 'Nome do marcador';
        prev.style.color = modal.cor;
        prev.style.background = modal.cor + '1a';
    }

    /* `o`: { el } edita · { pai, paiNome, cor } novo submarcador · { nome, cor, descricao } duplicar · {} novo */
    function abrirModal(o) {
        var m = elModal();
        if (!m) { return; }
        var el = o.el || null;
        var n = trilho().querySelectorAll('.exp-tri-marcador').length;
        modal.dados = { id: el ? el.dataset.marcadorId : null, token: el ? el.dataset.token : '', temFilhos: el ? +el.dataset.filhos > 0 : false };
        document.getElementById('expMkTitulo').textContent = el ? 'Editar marcador' : (o.pai ? 'Novo submarcador em ' + o.paiNome : 'Novo marcador');
        document.getElementById('expMkNome').value = el ? el.dataset.nome : (o.nome || '');
        document.getElementById('expMkDescricao').value = el ? (el.dataset.descricao || '') : (o.descricao || '');
        document.getElementById('expMkErro').textContent = '';

        // "Dentro de": só marcadores de topo, nunca o próprio; quem tem submarcadores não vira submarcador.
        var sel = document.getElementById('expMkPai');
        while (sel.options.length > 1) { sel.remove(1); }
        if (!modal.dados.temFilhos) {
            trilho().querySelectorAll('.exp-tri-marcador[data-pai-id=""]').forEach(function (r) {
                if (el && r.dataset.marcadorId === el.dataset.marcadorId) { return; }
                var op = document.createElement('option');
                op.value = r.dataset.marcadorId;
                op.textContent = r.dataset.nome;
                sel.appendChild(op);
            });
        }
        sel.value = el ? (el.dataset.paiId || '') : (o.pai || '');

        var cor = el ? el.dataset.corTela : (o.cor || CORES[n % CORES.length]);
        pintarCor(CORES.indexOf(cor) >= 0 ? cor : CORES[n % CORES.length]);
        m.hidden = false;
        setTimeout(function () { var nome = document.getElementById('expMkNome'); nome.focus(); nome.select(); }, 30);
    }

    function fecharModal() { var m = elModal(); if (m) { m.hidden = true; } modal.dados = null; }

    function salvarModal() {
        var t = trilho();
        var d = modal.dados;
        var nome = document.getElementById('expMkNome').value.trim();
        var erro = document.getElementById('expMkErro');
        if (!nome) { erro.textContent = 'Informe o nome do marcador'; return; }
        var corpo = new URLSearchParams({
            nome: nome,
            cor: modal.cor,
            descricao: document.getElementById('expMkDescricao').value.trim(),
            pai_id: document.getElementById('expMkPai').value
        });
        corpo.append('_token', d.id ? d.token : t.dataset.csrfCriar);
        var url = d.id ? t.dataset.urlEditar.replace(/\/0$/, '/' + d.id) : t.dataset.urlCriar;
        var botao = document.getElementById('expMkSalvar');
        botao.disabled = true;
        fetch(url, {
            method: d.id ? 'PATCH' : 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: corpo.toString()
        })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                botao.disabled = false;
                if (!res.ok) { erro.textContent = res.j.erro || 'Não foi possível salvar'; return; }
                var pai = corpo.get('pai_id');
                if (pai) { var g = gruposAbertos(); g[pai] = true; gravar(CHAVE_GRUPOS, JSON.stringify(g)); }
                fecharModal();
                aviso(d.id ? 'Marcador atualizado' : 'Marcador "' + nome + '" criado');
                recarregar();
            })
            .catch(function () { botao.disabled = false; erro.textContent = 'Falha de conexão. Tente de novo.'; });
    }

    function duplicar(el) {
        var nomes = Array.prototype.map.call(trilho().querySelectorAll('.exp-tri-marcador'), function (m) { return m.dataset.nome; });
        var nome = el.dataset.nome + ' (cópia)';
        var i = 2;
        while (nomes.indexOf(nome) >= 0) { nome = el.dataset.nome + ' (cópia ' + (i++) + ')'; }
        abrirModal({ nome: nome, cor: el.dataset.corTela, descricao: el.dataset.descricao || '' });
    }

    /* Excluir: a janela da tela (E7/E8b, moldura de alerta do dc L.333-345), com os submarcadores que saem junto. */
    function excluir(el) {
        var n = +el.dataset.n || 0;
        var subs = el.dataset.paiId ? [] : Array.prototype.map.call(
            trilho().querySelectorAll('.exp-tri-marcador[data-pai-id="' + el.dataset.marcadorId + '"]'),
            function (s) { return s.dataset.nome; });
        var texto = 'Esta ação é irreversível. ' + (n > 0
            ? 'As ' + n + ' pasta(s) com este marcador continuam no sistema, porém ficam sem este marcador.'
            : 'Nenhuma pasta está com este marcador.');
        if (subs.length) {
            texto += ' Os ' + subs.length + ' submarcador(es) (' + subs.join(', ') + ') também são excluídos, e as pastas marcadas com eles deixam de tê-los.';
        }
        var pergunta = { titulo: 'Excluir o marcador "' + el.dataset.nome + '"?', texto: texto, rotulo: 'Excluir mesmo assim', icone: 'bi-trash3', moldura: 'alerta' };
        var resposta = window.confirmarPs ? window.confirmarPs(pergunta) : Promise.resolve(window.confirm(pergunta.titulo));
        resposta.then(function (sim) {
            if (!sim) { return; }
            fetch(trilho().dataset.urlExcluir.replace(/\/0$/, '/' + el.dataset.marcadorId), {
                method: 'DELETE',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: '_token=' + encodeURIComponent(el.dataset.tokenExcluir)
            })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (res) {
                    if (!res.ok) { aviso(res.j.erro || 'Não foi possível excluir'); return; }
                    aviso('Marcador excluído · ' + n + ' pasta(s) ficaram sem marcador');
                    var eraAberto = ativo.tipo === 'mk' && (ativo.valor === el.dataset.marcadorId
                        || !!trilho().querySelector('.exp-tri-marcador[data-pai-id="' + el.dataset.marcadorId + '"][data-marcador-id="' + ativo.valor + '"]'));
                    recarregar().then(function () { if (eraAberto) { abrirTodas(false); } });
                })
                .catch(function () { aviso('Falha de conexão ao excluir'); });
        });
    }

    /* ── Ligações (delegadas: o trilho é trocado inteiro) ─────────────── */
    document.addEventListener('click', function (e) {
        if (nota && !nota.contains(e.target)) { fecharNota(); }
        var t = e.target.closest('#expTrilho');
        if (!t) { return; }
        if (e.target.closest('.js-tri-recolher')) { e.preventDefault(); alternarRecolhido(); return; }
        if (e.target.closest('.js-tri-cab-mais')) { e.preventDefault(); menuDoCabecalho(e.target.closest('.js-tri-cab-mais')); return; }
        var opcoes = e.target.closest('.js-tri-opcoes');
        if (opcoes) {
            e.preventDefault(); e.stopPropagation();
            var alvo = opcoes.closest('.exp-tri-marcador');
            var r = opcoes.getBoundingClientRect();
            menuDoMarcador(alvo, r.left, r.bottom + 4);
            return;
        }
        var seta = e.target.closest('.js-tri-abrir:not(.is-vazio)');
        if (seta) {
            e.preventDefault(); e.stopPropagation();
            var id = seta.closest('.exp-tri-marcador').dataset.marcadorId;
            var g = gruposAbertos();
            g[id] = !g[id];
            gravar(CHAVE_GRUPOS, JSON.stringify(g));
            aplicarGrupos();
            return;
        }
        if (e.target.closest('.js-tri-padrao, .exp-tri-todas')) { e.preventDefault(); abrirTodas(true); return; }
        var sistema = e.target.closest('.exp-tri-sistema');
        if (sistema) { e.preventDefault(); abrirSistema(sistema); return; }
        var mk = e.target.closest('.exp-tri-marcador');
        if (mk) { e.preventDefault(); abrirMarcador(mk); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            fecharNota();
            var m = elModal();
            if (m && !m.hidden) { e.preventDefault(); fecharModal(); }
            return;
        }
        var t = e.target.closest && e.target.closest('#expTrilho');
        if (!t) { return; }
        var item = e.target.closest('.exp-tri-item, .js-tri-padrao');
        if (item && (e.key === 'Enter' || e.key === ' ') && e.target === item) { e.preventDefault(); item.click(); }
        // Tecla de menu ou Shift+F10 num marcador ou filtro do sistema: o mesmo do botão direito.
        if (item && (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10'))) {
            e.preventDefault();
            var r = item.getBoundingClientRect();
            if (item.classList.contains('exp-tri-marcador')) { menuDoMarcador(item, r.left + 24, r.bottom); } else if (item.classList.contains('exp-tri-sistema')) { abrirNota(item); }
        }
    });

    document.addEventListener('contextmenu', function (e) {
        var t = e.target.closest('#expTrilho');
        if (!t) { return; }
        var cab = e.target.closest('.js-tri-cab-mais');
        if (cab) { e.preventDefault(); menuDoCabecalho(cab); return; }
        var sistema = e.target.closest('.exp-tri-sistema');
        if (sistema) { e.preventDefault(); abrirNota(sistema, e.clientX, e.clientY); return; }
        var mk = e.target.closest('.exp-tri-marcador');
        if (mk) { e.preventDefault(); menuDoMarcador(mk, e.clientX, e.clientY); }
    });

    /* Arrastar e soltar (dc `dStart/dOver/dDrop`): só no mesmo nível, com a linha azul de onde vai entrar. */
    function limparArrasto() {
        document.querySelectorAll('#expTrilho .is-solta-antes, #expTrilho .is-solta-depois, #expTrilho .is-arrastando')
            .forEach(function (el) { el.classList.remove('is-solta-antes', 'is-solta-depois', 'is-arrastando'); });
    }
    document.addEventListener('dragstart', function (e) {
        var mk = e.target.closest && e.target.closest('#expTrilho .exp-tri-marcador');
        if (!mk || ler(CHAVE_RECOLHIDO, '0') === '1') { if (mk) { e.preventDefault(); } return; }
        arrastando = mk;
        mk.classList.add('is-arrastando');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', mk.dataset.nome); } catch (x) { /* navegador antigo */ }
    });
    document.addEventListener('dragover', function (e) {
        if (!arrastando) { return; }
        var mk = e.target.closest && e.target.closest('#expTrilho .exp-tri-marcador');
        if (!mk || mk === arrastando) { return; }
        e.preventDefault();
        var r = mk.getBoundingClientRect();
        var depois = e.clientY > r.top + r.height / 2;
        document.querySelectorAll('#expTrilho .is-solta-antes, #expTrilho .is-solta-depois').forEach(function (el) { el.classList.remove('is-solta-antes', 'is-solta-depois'); });
        mk.classList.add(depois ? 'is-solta-depois' : 'is-solta-antes');
    });
    document.addEventListener('drop', function (e) {
        if (!arrastando) { return; }
        var mk = e.target.closest && e.target.closest('#expTrilho .exp-tri-marcador');
        if (!mk || mk === arrastando) { limparArrasto(); arrastando = null; return; }
        e.preventDefault();
        var depois = mk.classList.contains('is-solta-depois');
        var de = arrastando;
        limparArrasto();
        arrastando = null;
        if ((de.dataset.paiId || '') !== (mk.dataset.paiId || '')) {
            aviso(de.dataset.paiId ? 'Submarcadores só podem ser reordenados dentro do mesmo marcador' : 'Arraste para cima ou para baixo de outro marcador principal');
            return;
        }
        reordenar(de.dataset.marcadorId, mk.dataset.marcadorId, depois);
    });
    document.addEventListener('dragend', function () { limparArrasto(); arrastando = null; });

    /* Modal do marcador */
    document.addEventListener('click', function (e) {
        var m = elModal();
        if (!m || m.hidden) { return; }
        if (e.target === m || e.target.closest('.js-mk-fechar')) { fecharModal(); return; }
        var cor = e.target.closest('.exp-mk-cor');
        if (cor) { pintarCor(cor.dataset.cor); return; }
        if (e.target.closest('#expMkSalvar')) { salvarModal(); }
    });
    document.addEventListener('input', function (e) {
        if (e.target.id === 'expMkNome') { document.getElementById('expMkErro').textContent = ''; pintarPrevia(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && (e.target.id === 'expMkNome' || e.target.id === 'expMkDescricao')) { e.preventDefault(); salvarModal(); }
    });

    /* API para o painel (index.html.twig) */
    window.ExpTrilho = {
        recarregar: recarregar,
        abrirTodas: abrirTodas,
        /* Marca o item do painel restaurado ou do deep-link e devolve o nome dele. */
        marcarPorPainel: function (url, expediente) {
            var m = url ? url.match(/\/marcador\/(\d+)\/pastas/) : null;
            if (m) { ativo = { tipo: 'mk', valor: m[1] }; } else if (expediente) { ativo = { tipo: 'exp', valor: expediente }; } else { ativo = { tipo: 'todas', valor: '' }; }
            aplicarAtivo();
            var el = itemDoAtivo();

            return el ? (el.dataset.nome || 'Todas as pastas') : 'Todas as pastas';
        }
    };

    aplicarRecolhido();
    aplicarGrupos();
}());
