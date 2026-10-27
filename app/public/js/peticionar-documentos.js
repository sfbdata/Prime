/*
 * Peticionar (desenho 07): o que a lista de documentos ganhou no P1 e no P2.
 *
 *  - "Voltar à pasta" no cabeçalho (L38): o link nasce escondido no conteúdo e vem para o início
 *    do .navtop-right (o layout não tem bloco no cabeçalho).
 *  - Menu da linha, pelo botão de três pontos e pelo botão direito (L54, L101-105, L161): Abrir,
 *    Renomear, Baixar, Classificar e Excluir (vai para a lixeira).
 *  - Barra do documento selecionado (L77-85): nome (duplo clique renomeia), "tamanho · data"
 *    ("Texto · data" e o botão Editar na peça de texto, com a barra no topo do editor), Baixar e
 *    Excluir.
 *  - Soltar arquivos na lista para enviar (L47, L147), pela rota do "Adicionar peça", com o mesmo
 *    token, os mesmos formatos e a mesma categoria padrão do modal.
 *
 *  - Menu do "Adicionar peça" (L66-73): abre e fecha pelo botão, pelo fundo e pelo Esc; a seta
 *    troca entre caret-up e caret-down.
 *
 * Só rotas que já existem, chamadas como a aba Documentos chama: renomear e classificar pelo
 * pasta_documento_edit (form + X-Requested-With, token edit_documento_<id>), excluir pelo
 * excluir-lote (JSON, token pex_lote_<pasta>). O servidor confere permissão e posse; esconder o
 * que não se pode fazer é só conforto.
 *
 * DOM sempre por createElement/textContent: nome de arquivo nunca vira HTML.
 */
(function () {
    'use strict';

    const lista = document.getElementById('timelineList');
    const tl    = document.getElementById('tlInner');
    if (!lista || !tl) return;

    function lerJson(texto, padrao) {
        try { return JSON.parse(texto || ''); } catch (e) { return padrao; }
    }

    const cfg = {
        podeEditar:     lista.dataset.podeEditar === '1',
        urlExcluir:     lista.dataset.urlExcluir || '',
        csrfLote:       lista.dataset.csrfLote || '',
        urlUpload:      lista.dataset.urlUpload || '',
        csrfUpload:     lista.dataset.csrfUpload || '',
        urlEditarTpl:   lista.dataset.urlEditarTpl || '',
        categorias:     lerJson(lista.dataset.categorias, {}),
        classificaveis: lerJson(lista.dataset.classificaveis, []),
        corCategoria:   lerJson(lista.dataset.corCategoria, {}),
        icones:         lerJson(lista.dataset.icones, {}),
    };

    const MIME_TEXTO = 'text/html';
    const ICONE_PADRAO = { icone: 'bi-file-earmark-fill', cor: '#6b8494' };

    // ------------------------------------------------------------ utilidades ---

    function el(tag, classe, texto) {
        const n = document.createElement(tag);
        if (classe) n.className = classe;
        if (texto !== undefined && texto !== null) n.textContent = String(texto);
        return n;
    }

    function icone(classe) {
        const i = el('i', 'bi ' + classe);
        i.setAttribute('aria-hidden', 'true');
        return i;
    }

    function ehTexto(row) { return (row.dataset.mimeType || '') === MIME_TEXTO; }

    function linhas() { return Array.prototype.slice.call(tl.querySelectorAll('.petic-tl-row')); }

    function linhaAtiva() { return tl.querySelector('.petic-tl-row.ativo'); }

    function extensaoDe(nome) {
        const t = String(nome || '');
        const ponto = t.lastIndexOf('.');
        return ponto > 0 ? t.slice(ponto + 1).toLowerCase() : '';
    }

    // O formato do desenho (L136): "12 KB", "1,4 MB". O Twig monta igual na primeira carga.
    function formatarTamanho(bytes) {
        const n = Number(bytes) || 0;
        if (n > 1048576) return (n / 1048576).toFixed(1).replace('.', ',') + ' MB';
        return Math.max(1, Math.round(n / 1024)) + ' KB';
    }

    // Como a busca da lista: conta só as linhas visíveis (com a busca ativa, as que batem).
    function atualizarContagem() {
        const badge = document.getElementById('badgeCount');
        if (badge) badge.textContent = String(linhas().filter(function (r) { return r.style.display !== 'none'; }).length);
    }

    // Aviso do desenho (L166): faixa azul embaixo, some sozinha.
    let avisoEl = null;
    let avisoTimer = null;
    function avisar(texto, erro) {
        if (avisoEl) avisoEl.remove();
        clearTimeout(avisoTimer);
        avisoEl = el('div', 'petic-aviso' + (erro ? ' petic-aviso--erro' : ''), texto);
        avisoEl.setAttribute('role', erro ? 'alert' : 'status');
        document.body.appendChild(avisoEl);
        avisoTimer = setTimeout(function () { if (avisoEl) { avisoEl.remove(); avisoEl = null; } }, erro ? 6000 : 2600);
    }

    function lerResposta(r) {
        return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; });
    }

    function postForm(url, campos) {
        const fd = new FormData();
        Object.keys(campos).forEach(function (k) { fd.append(k, campos[k] == null ? '' : campos[k]); });
        return fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(lerResposta);
    }

    function postJson(url, corpo) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(corpo),
        }).then(lerResposta);
    }

    function confirmar(pergunta) {
        if (typeof window.confirmarUIPs === 'function') return window.confirmarUIPs(pergunta, true);
        return Promise.resolve(window.confirm(pergunta));
    }

    function selecionar(row) {
        if (window.PeticionarTela) window.PeticionarTela.selecionarItem(row);
    }

    // --------------------------------------------------- voltar à pasta (L38) ---

    const voltar = document.getElementById('peticVoltarPasta');
    if (voltar) {
        // Logo antes do nome do usuário, como no desenho.
        const direita = document.querySelector('.navtop-right');
        if (direita) {
            const usuario = direita.querySelector(':scope > .menu-usuario');
            direita.insertBefore(voltar, usuario || null);
        }
        voltar.hidden = false;
    }

    // --------------------------------------------- menu do "Adicionar peça" (L66-73) ---

    const addBtn   = document.getElementById('btnAbrirUpload');
    const addMenu  = document.getElementById('peticAddMenu');
    const addFundo = document.getElementById('peticAddFundo');
    const addSeta  = document.getElementById('peticAddSeta');

    function alternarAdd(abrir) {
        if (!addBtn || !addMenu || !addFundo) return;
        addMenu.hidden = !abrir;
        addFundo.hidden = !abrir;
        addBtn.setAttribute('aria-expanded', abrir ? 'true' : 'false');
        if (addSeta) {
            addSeta.classList.toggle('bi-caret-down-fill', abrir);
            addSeta.classList.toggle('bi-caret-up-fill', !abrir);
        }
    }

    if (addBtn && addMenu && addFundo) {
        addBtn.addEventListener('click', function () { alternarAdd(addMenu.hidden); });
        addFundo.addEventListener('click', function () { alternarAdd(false); });
        // Escolher Anexo ou Texto fecha o menu (a ação em si é do script da tela).
        addMenu.addEventListener('click', function (e) { if (e.target.closest('.petic-add-item')) alternarAdd(false); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !addMenu.hidden) alternarAdd(false);
        });
    }

    // ----------------------------------------------------- linha montada aqui ---

    function vazioDaLista() {
        const v = el('div', 'petic-tl-vazio');
        v.appendChild(icone('bi-folder2-open'));
        v.appendChild(document.createTextNode(BJ_T.t('pasta_peticionar.js.vazio')));
        v.appendChild(el('span', 'petic-tl-vazio-dica', BJ_T.t('pasta_peticionar.js.vazio_dica')));
        return v;
    }

    /*
     * A linha de um documento recém-enviado, na mesma forma que o Twig monta. `doc` é o
     * `documento` da resposta do upload (a forma do explorador mais titulo/uploadedAt/mimeType).
     */
    function inserirLinha(doc) {
        const vazio = tl.querySelector('.petic-tl-vazio');
        if (vazio) vazio.remove();

        const partes = String(doc.uploadedAt || '').split(' ');
        const dia  = partes[0] || '';
        const hora = partes[1] || '';
        const nome = String(doc.nome || doc.titulo || '');
        const mime = String(doc.mime || doc.mimeType || '');
        const categoria = String(doc.categoria || '');
        const rotulo = cfg.categorias[categoria] || categoria;
        const tamanhoTexto = formatarTamanho(doc.tamanho);

        const existe = Array.prototype.some.call(tl.querySelectorAll('.petic-tl-datesep'), function (s) { return s.dataset.date === dia; });
        if (!existe) {
            const sep = el('div', 'petic-tl-datesep');
            sep.dataset.date = dia;
            sep.appendChild(el('span', null, dia));
            tl.appendChild(sep);
        }

        const row = el('div', 'petic-tl-row');
        row.dataset.docId               = String(doc.id);
        row.dataset.viewUrl             = doc.viewUrl || '';
        row.dataset.downloadUrl         = doc.downloadUrl || '';
        row.dataset.mimeType            = mime;
        row.dataset.titulo              = nome;
        row.dataset.categoria           = categoria;
        row.dataset.categoriaLabel      = rotulo;
        row.dataset.tamanho             = String(doc.tamanho || 0);
        row.dataset.tamanhoTexto        = tamanhoTexto;
        row.dataset.carregadoEm         = String(doc.uploadedAt || '');
        row.dataset.descricao           = doc.descricao || '';
        row.dataset.numero              = doc.numero || '';
        row.dataset.editarDocumentoUrl  = cfg.urlEditarTpl.replace('__ID__', String(doc.id));
        row.dataset.csrfDocumento       = doc.csrfEditar || '';

        const ic = cfg.icones[extensaoDe(nome)] || ICONE_PADRAO;

        const card = el('div', 'petic-tl-card');
        const topo = el('div', 'petic-tl-card-top');
        const i = icone(ic.icone + ' petic-tl-icon petic-doc-icone');
        i.style.color = ic.cor;
        topo.appendChild(i);
        const titulo = el('span', 'petic-tl-titulo', nome);
        titulo.title = nome;
        topo.appendChild(titulo);
        const base = el('div', 'petic-tl-card-bottom');
        base.appendChild(el('span', 'badge ' + (cfg.corCategoria[categoria] || 'text-bg-secondary') + ' petic-item-cat', rotulo));
        base.appendChild(el('span', 'petic-doc-tamanho', tamanhoTexto));
        base.appendChild(el('span', 'petic-tl-time', hora));
        const mais = el('button', 'petic-doc-mais');
        mais.type = 'button';
        mais.title = BJ_T.t('pasta_peticionar.js.mais_opcoes');
        mais.setAttribute('aria-label', BJ_T.t('pasta_peticionar.js.mais_opcoes'));
        mais.setAttribute('aria-haspopup', 'menu');
        mais.appendChild(icone('bi-three-dots-vertical'));
        card.appendChild(topo);
        card.appendChild(base);
        card.appendChild(mais);
        row.appendChild(card);
        tl.appendChild(row);

        atualizarContagem();
        return row;
    }

    // ------------------------------------------------------- menu da linha ---

    let menu = null;   // { fundo, caixa }

    function fecharMenu() {
        if (!menu) return;
        menu.fundo.remove();
        menu.caixa.remove();
        document.removeEventListener('keydown', teclaDoMenu, true);
        menu = null;
    }

    function teclaDoMenu(e) {
        if (!menu) return;
        const itens = Array.prototype.slice.call(menu.caixa.querySelectorAll('.petic-ctx-item'));
        const atual = itens.indexOf(document.activeElement);
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); fecharMenu(); return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); itens[(atual + 1) % itens.length].focus(); }
        if (e.key === 'ArrowUp') { e.preventDefault(); itens[(atual - 1 + itens.length) % itens.length].focus(); }
    }

    function rotuloDaCategoria(row) {
        return cfg.categorias[row.dataset.categoria] || row.dataset.categoriaLabel || row.dataset.categoria || '';
    }

    function abrirMenu(row, x, y) {
        fecharMenu();
        const itens = [[BJ_T.t('pasta_peticionar.js.abrir'), 'bi-eye', function () { selecionar(row); }]];
        if (cfg.podeEditar) itens.push([BJ_T.t('pasta_peticionar.js.renomear'), 'bi-input-cursor-text', function () { renomear(row); }]);
        itens.push([BJ_T.t('pasta_peticionar.js.baixar'), 'bi-download', function () { baixar(row); }]);
        if (cfg.podeEditar) {
            itens.push([BJ_T.t('pasta_peticionar.js.classificar', { categoria: rotuloDaCategoria(row) }), 'bi-tag', function () { classificar(row); }]);
            itens.push([BJ_T.t('pasta_peticionar.js.excluir'), 'bi-trash3', function () { excluir(row); }, true]);
        }

        const fundo = el('div', 'petic-ctx-fundo');
        const fechar = function (e) { e.preventDefault(); fecharMenu(); };
        fundo.addEventListener('click', fechar);
        fundo.addEventListener('contextmenu', fechar);

        const caixa = el('div', 'petic-ctx');
        caixa.setAttribute('role', 'menu');
        itens.forEach(function (it) {
            const b = el('button', 'petic-ctx-item' + (it[3] ? ' petic-ctx-item--perigo' : ''));
            b.type = 'button';
            b.setAttribute('role', 'menuitem');
            b.appendChild(icone(it[1]));
            b.appendChild(document.createTextNode(it[0]));
            b.addEventListener('click', function () { fecharMenu(); it[2](); });
            caixa.appendChild(b);
        });
        caixa.style.left = Math.max(0, Math.min(x, window.innerWidth - 220)) + 'px';
        caixa.style.top  = Math.max(0, Math.min(y, window.innerHeight - 220)) + 'px';

        document.body.appendChild(fundo);
        document.body.appendChild(caixa);
        menu = { fundo: fundo, caixa: caixa };
        document.addEventListener('keydown', teclaDoMenu, true);
        const primeiro = caixa.querySelector('.petic-ctx-item');
        if (primeiro) primeiro.focus();
    }

    // Antes do listener da lista (que seleciona a linha): o clique nos três pontos não seleciona.
    tl.addEventListener('click', function (e) {
        const mais = e.target.closest('.petic-doc-mais');
        if (!mais) return;
        const row = mais.closest('.petic-tl-row');
        if (!row) return;
        e.preventDefault();
        e.stopPropagation();
        let x = e.clientX, y = e.clientY;
        if (!x && !y) { const r = mais.getBoundingClientRect(); x = r.left; y = r.bottom; }
        abrirMenu(row, x, y);
    });

    tl.addEventListener('contextmenu', function (e) {
        if (e.target.closest('input')) return;   // no campo de renomear, o menu do navegador
        const row = e.target.closest('.petic-tl-row');
        if (!row) return;
        e.preventDefault();
        abrirMenu(row, e.clientX, e.clientY);
    });

    // ------------------------------------------------------------- ações ---

    function baixar(row) {
        const url = row && row.dataset.downloadUrl;
        if (!url) return;
        const a = document.createElement('a');
        a.href = url;
        a.download = '';
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    // O POST do pasta_documento_edit leva sempre os quatro campos (como a aba Documentos): descrição
    // e número em branco virariam NULL no servidor.
    function editarDocumento(row, campos) {
        return postForm(row.dataset.editarDocumentoUrl, {
            _token:    row.dataset.csrfDocumento || '',
            nomeBase:  campos.nomeBase || '',
            categoria: campos.categoria || row.dataset.categoria || '',
            descricao: row.dataset.descricao || '',
            numero:    row.dataset.numero || '',
        }).then(function (res) {
            if (!res.ok || !res.j.ok || !res.j.documento) throw new Error((res.j && (res.j.erro || res.j.mensagem)) || BJ_T.t('pasta_peticionar.js.erro_salvar_nome'));
            return res.j.documento;
        });
    }

    function nomeExibido(row, nomeOriginal) {
        const n = String(nomeOriginal || '');
        return ehTexto(row) && /\.html$/i.test(n) ? n.slice(0, -5) : n;
    }

    function aplicarNome(row, nome) {
        row.dataset.titulo = nome;
        const t = row.querySelector('.petic-tl-titulo');
        if (t) { t.textContent = nome; t.title = nome; }
        // A peça de texto aberta no editor: o título dele é o que o "Salvar alterações" grava.
        if (ehTexto(row) && row.classList.contains('ativo')) {
            const tituloEditor = document.getElementById('editorTituloDisplay');
            if (tituloEditor) tituloEditor.textContent = nome;
        }
        atualizarBarra();
    }

    let renomeando = null;

    function renomear(row) {
        // A peça de texto abre no editor, que carrega o conteúdo antes de aparecer: o campo só
        // ganha foco depois disso (o mesmo caminho do selecionarItem da tela).
        if (ehTexto(row) && !row.classList.contains('ativo') && typeof window.abrirEditorParaEditar === 'function') {
            linhas().forEach(function (r) { r.classList.remove('ativo'); });
            row.classList.add('ativo');
            Promise.resolve(window.abrirEditorParaEditar(row)).then(function () {
                atualizarBarra();
                iniciarRenomear(row, barra.nome);
            });
            return;
        }
        if (!row.classList.contains('ativo')) selecionar(row);
        atualizarBarra();
        iniciarRenomear(row, barra.nome);
    }

    function iniciarRenomear(row, alvo) {
        if (!cfg.podeEditar || !alvo || renomeando) return;
        const original = row.dataset.titulo || '';
        const input = el('input', 'petic-renomear-input');
        input.type = 'text';
        input.maxLength = 255;
        input.value = original;
        input.setAttribute('aria-label', BJ_T.t('pasta_peticionar.js.novo_nome'));
        alvo.hidden = true;
        alvo.insertAdjacentElement('afterend', input);
        renomeando = { row: row, alvo: alvo };

        input.focus();
        const ext = ehTexto(row) ? '' : extensaoDe(original);
        input.setSelectionRange(0, ext ? original.length - ext.length - 1 : original.length);

        let terminou = false;
        function terminar(salvar) {
            if (terminou) return;
            terminou = true;
            const v = input.value.trim();
            input.remove();
            alvo.hidden = false;
            renomeando = null;
            if (salvar && v !== '' && v !== original) salvarNome(row, v);
        }
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); terminar(true); }
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); terminar(false); }
        });
        input.addEventListener('blur', function () { terminar(true); });
        // Clique no campo não seleciona a linha (nem abre o editor da peça de texto).
        input.addEventListener('click', function (e) { e.stopPropagation(); });
        input.addEventListener('dblclick', function (e) { e.stopPropagation(); });
    }

    function salvarNome(row, valor) {
        // O servidor devolve a extensão original sozinho: vai só a base do nome.
        const ext = ehTexto(row) ? '' : extensaoDe(row.dataset.titulo);
        let base = valor;
        if (ext && base.toLowerCase().endsWith('.' + ext)) base = base.slice(0, -(ext.length + 1)).trim();
        if (base === '') return;
        editarDocumento(row, { nomeBase: base }).then(function (doc) {
            aplicarNome(row, nomeExibido(row, doc.nome));
        }).catch(function (err) { avisar(err.message || BJ_T.t('pasta_peticionar.js.erro_comunicacao_curto'), true); });
    }

    // Classificar (L161): cada clique passa à próxima categoria, como no desenho; a lista é a que
    // o pasta_documento_edit aceita.
    function classificar(row) {
        const lista_ = cfg.classificaveis;
        if (!lista_.length) return;
        const proxima = lista_[(lista_.indexOf(row.dataset.categoria) + 1) % lista_.length];
        editarDocumento(row, { categoria: proxima }).then(function (doc) {
            aplicarCategoria(row, doc.categoria || proxima);
            avisar(BJ_T.t('pasta_peticionar.js.classificado', { categoria: rotuloDaCategoria(row) }));
        }).catch(function (err) { avisar(err.message || BJ_T.t('pasta_peticionar.js.erro_comunicacao_curto'), true); });
    }

    function trocarClasse(alvo, mapa, nova, padrao) {
        if (!alvo) return;
        Object.keys(mapa).forEach(function (k) { alvo.classList.remove(mapa[k]); });
        alvo.classList.remove(padrao);
        alvo.classList.add(nova || padrao);
    }

    function aplicarCategoria(row, categoria) {
        row.dataset.categoria = categoria;
        row.dataset.categoriaLabel = cfg.categorias[categoria] || categoria;
        const badge = row.querySelector('.petic-item-cat');
        if (badge) {
            badge.textContent = row.dataset.categoriaLabel;
            trocarClasse(badge, cfg.corCategoria, cfg.corCategoria[categoria], 'text-bg-secondary');
        }
        // A peça de texto aberta no editor: o selo de categoria dele acompanha.
        if (ehTexto(row) && row.classList.contains('ativo')) {
            const seloEditor = document.getElementById('editorCategoriaBadge');
            if (seloEditor) seloEditor.textContent = row.dataset.categoriaLabel;
        }
    }

    function excluir(row) {
        const nome = row.dataset.titulo || '';
        const pergunta = BJ_T.t('pasta_peticionar.js.excluir_pergunta', { nome: nome });
        confirmar(pergunta).then(function (sim) {
            if (!sim) return;
            postJson(cfg.urlExcluir, { _token: cfg.csrfLote, documentos: [Number(row.dataset.docId)], secoes: [] }).then(function (res) {
                if (!res.ok || !res.j.ok) throw new Error((res.j && (res.j.erro || res.j.mensagem)) || BJ_T.t('pasta_peticionar.js.erro_excluir'));
                removerLinha(row);
                avisar(BJ_T.t('pasta_peticionar.js.peca_excluida'));
            }).catch(function (err) { avisar(err.message || BJ_T.t('pasta_peticionar.js.erro_comunicacao_curto'), true); });
        });
    }

    function removerLinha(row) {
        if (row.classList.contains('ativo')) {
            if (ehTexto(row) && typeof window.fecharEditorPeticionar === 'function') window.fecharEditorPeticionar();
            if (window.PeticionarTela) window.PeticionarTela.limparSelecao();
        }
        // O separador do dia sai junto quando era o último documento daquele dia.
        let sep = row.previousElementSibling;
        while (sep && !sep.classList.contains('petic-tl-datesep')) sep = sep.previousElementSibling;
        row.remove();
        if (sep) {
            const prox = sep.nextElementSibling;
            if (!prox || !prox.classList.contains('petic-tl-row')) sep.remove();
        }
        if (!linhas().length) {
            tl.querySelectorAll('.petic-tl-vazio').forEach(function (v) { v.remove(); });
            tl.appendChild(vazioDaLista());
        }
        atualizarContagem();
        atualizarBarra();
    }

    // ------------------------------------------- barra do selecionado (L77-85) ---

    const previewPanel = document.getElementById('previewPanel');
    const editorPanel  = document.getElementById('editorPanel');
    const barraEl = document.getElementById('peticSelbar');
    const barra = barraEl ? {
        raiz:  barraEl,
        icone: barraEl.querySelector('.petic-selbar-icone'),
        nome:  barraEl.querySelector('.petic-selbar-nome'),
        sub:   barraEl.querySelector('.petic-selbar-sub'),
        editar: barraEl.querySelector('[data-acao="editar"]'),
    } : null;

    function atualizarBarra() {
        if (!barra) return;
        const row = linhaAtiva();
        const texto = !!row && ehTexto(row);
        // A peça de texto abre no editor (no lugar do preview): a barra vai junto, no topo dele.
        // Quem não pode editar vê a peça de texto no preview, então a barra fica nele.
        const noEditor = texto && cfg.podeEditar;
        const casa = noEditor ? editorPanel : previewPanel;
        if (casa && barra.raiz.parentElement !== casa) casa.insertBefore(barra.raiz, casa.firstChild);
        barra.raiz.hidden = !row;
        if (previewPanel) previewPanel.classList.toggle('petic-preview--com-barra', !!row && !noEditor);
        if (barra.editar) barra.editar.hidden = !texto;
        if (!row) return;

        const icRow = row.querySelector('.petic-doc-icone');
        barra.icone.className = 'bi petic-selbar-icone ' + (icRow ? Array.prototype.filter.call(icRow.classList, function (c) { return c.indexOf('bi-') === 0; }).join(' ') : '');
        barra.icone.style.color = icRow ? icRow.style.color : '';
        if (!(renomeando && renomeando.alvo === barra.nome)) barra.nome.textContent = row.dataset.titulo || '';
        barra.sub.textContent = (texto ? BJ_T.t('pasta_peticionar.js.texto') : (row.dataset.tamanhoTexto || '')) + ' · ' + (row.dataset.carregadoEm || '');
    }

    if (barra) {
        if (!cfg.podeEditar) barra.nome.removeAttribute('title');
        barra.nome.addEventListener('dblclick', function () {
            const row = linhaAtiva();
            if (row) iniciarRenomear(row, barra.nome);
        });
        barra.raiz.addEventListener('click', function (e) {
            const b = e.target.closest('[data-acao]');
            const row = linhaAtiva();
            if (!b || !row) return;
            if (b.dataset.acao === 'baixar') baixar(row);
            if (b.dataset.acao === 'excluir') excluir(row);
            if (b.dataset.acao === 'editar') editarTexto(row);
        });
        // A seleção é da tela (classe "ativo" na linha): a barra acompanha qualquer troca.
        new MutationObserver(atualizarBarra).observe(tl, { subtree: true, attributes: true, attributeFilter: ['class'] });
        atualizarBarra();
    }

    // "Editar" (L81): a peça já abre no editor ao ser selecionada; o botão leva o cursor para o
    // texto, ou reabre o editor se ele foi fechado.
    function editarTexto(row) {
        const aberto = editorPanel && editorPanel.style.display !== 'none';
        const ed = window.tinymce ? window.tinymce.get('editorConteudo') : null;
        if (aberto && ed) { ed.focus(); return; }
        if (typeof window.abrirEditorParaEditar === 'function') window.abrirEditorParaEditar(row);
    }

    // ------------------------------------------- busca sem resultado (L57) ---

    const pesquisa = document.getElementById('timelinePesquisa');
    if (pesquisa) {
        pesquisa.addEventListener('input', function () {
            const todas = linhas();
            const visiveis = todas.filter(function (r) { return r.style.display !== 'none'; });
            let aviso = tl.querySelector('.petic-tl-vazio--busca');
            if (todas.length && !visiveis.length) {
                if (!aviso) {
                    aviso = el('div', 'petic-tl-vazio petic-tl-vazio--busca');
                    aviso.appendChild(icone('bi-folder2-open'));
                    aviso.appendChild(document.createTextNode(BJ_T.t('pasta_peticionar.js.nenhum_encontrado')));
                    tl.appendChild(aviso);
                }
            } else if (aviso) {
                aviso.remove();
            }
        });
    }

    // ------------------------------------- soltar arquivos na lista (L47, L147) ---

    function temArquivos(e) {
        return !!e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1;
    }

    // As regras do modal "Adicionar peça": os formatos do accept e a categoria que ele traz marcada.
    function regrasDoModal() {
        const input = document.getElementById('uploadArquivo');
        const sel = document.getElementById('uploadCategoria');
        const aceitos = String(input ? input.accept : '').split(',').map(function (s) { return s.trim().replace(/^\./, '').toLowerCase(); }).filter(Boolean);
        let categoria = 'PECA';
        if (sel && sel.options.length) {
            const marcada = Array.prototype.find.call(sel.options, function (o) { return o.defaultSelected; }) || sel.options[0];
            categoria = marcada.value;
        }
        return { aceitos: aceitos, categoria: categoria };
    }

    let enviando = false;

    async function enviarArquivos(arquivos) {
        if (!arquivos.length) return;
        if (enviando) { avisar(BJ_T.t('pasta_peticionar.js.aguarde_envio')); return; }
        const regras = regrasDoModal();
        const recusados = arquivos.filter(function (f) { return regras.aceitos.length && regras.aceitos.indexOf(extensaoDe(f.name)) === -1; });
        const validos = arquivos.filter(function (f) { return recusados.indexOf(f) === -1; });
        const erros = recusados.map(function (f) { return BJ_T.t('pasta_peticionar.js.formato_nao_aceito', { arquivo: f.name }); });

        enviando = true;
        lista.classList.add('petic-enviando');
        const novos = [];
        if (validos.length) avisar(BJ_T.t('pasta_peticionar.js.enviando', { total: validos.length }));
        for (const f of validos) {
            const fd = new FormData();
            fd.append('arquivo', f, f.name);
            fd.append('categoria', regras.categoria);
            fd.append('descricao', '');
            fd.append('numero', '');
            fd.append('secao_id', '');
            fd.append('_token', cfg.csrfUpload);
            try {
                // Com o X-Requested-With a recusa da pasta excluída vem em JSON ({mensagem}), não em 302.
                const r = await fetch(cfg.urlUpload, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const j = await r.json().catch(function () { return {}; });
                if (!j.success || !j.documento) { erros.push(BJ_T.t('pasta_peticionar.js.arquivo_erro', { arquivo: f.name, erro: j.error || j.mensagem || BJ_T.t('pasta_peticionar.js.erro_ao_enviar') })); continue; }
                novos.push(inserirLinha(j.documento));
            } catch (err) {
                erros.push(BJ_T.t('pasta_peticionar.js.arquivo_sem_conexao', { arquivo: f.name }));
            }
        }
        enviando = false;
        lista.classList.remove('petic-enviando');

        if (novos.length) selecionar(novos[0]);
        if (erros.length) {
            avisar((novos.length ? BJ_T.t('pasta_peticionar.js.adicionadas', { total: novos.length }) + '. ' : '') + erros.join(' '), true);
        } else if (novos.length) {
            avisar(BJ_T.t('pasta_peticionar.js.adicionadas', { total: novos.length }));
        }
    }

    if (!cfg.podeEditar || !cfg.urlUpload) {
        // Quem não pode enviar: o arquivo solto não é aberto pelo navegador (sairia da página),
        // e a lista não se oferece como alvo.
        lista.addEventListener('dragover', function (e) {
            if (!temArquivos(e)) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'none';
        });
        lista.addEventListener('drop', function (e) {
            if (temArquivos(e)) e.preventDefault();
        });
    } else {
        lista.addEventListener('dragover', function (e) {
            if (!temArquivos(e)) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
            lista.classList.add('petic-drop-ativo');
        });
        lista.addEventListener('dragleave', function (e) {
            if (!lista.contains(e.relatedTarget)) lista.classList.remove('petic-drop-ativo');
        });
        lista.addEventListener('drop', function (e) {
            if (!temArquivos(e)) return;
            e.preventDefault();
            lista.classList.remove('petic-drop-ativo');
            enviarArquivos(Array.prototype.slice.call(e.dataTransfer.files || []));
        });
    }

    window.PeticionarDocumentos = { inserirLinha: inserirLinha };
}());
