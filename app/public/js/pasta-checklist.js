/* =============================================================================
   Checklist de documentação da aba Documentos da pasta
   (templates/pasta/_documentos_checklist.html.twig; desenho 02 - EXPEDIENTES 1.2.3,
   dc L2079-2179).

   Extraído do bloco inline da pasta/show, com o MESMO comportamento e os MESMOS ids:
     - marcar/desmarcar (POST /pasta/{id}/checklist/{item}/toggle);
     - modo de edição (✎): remover, renomear com duplo clique, reordenar arrastando a alça
       (SortableJS, carregado antes deste arquivo);
     - adicionar (+): faixa com campo; Enter confirma, Esc cancela;
     - modelos do escritório: listar, aplicar, salvar, renomear, excluir;
     - selo "N/M itens" e barra de progresso sempre juntos.

   O que mudou na extração: o id da pasta e o token vêm de `data-*` de #pexChecklist (o
   arquivo é estático); toda linha nasce por createElement/textContent (título de item e
   nome de modelo são dado do usuário — só textContent, nunca HTML cru); o estado "concluído" mora em
   `data-concluido` em vez de ser deduzido da cor do ícone.

   Selo "sem anexo": a regra é do SERVIDOR (ConferenciaDeAnexosDoChecklist) e chega em
   `data-tem-anexo`. Aqui só se mostra ou esconde o selo conforme o item é marcado. Item
   criado ou renomeado nesta tela não tem conferência até recarregar — não se duplica a
   regra no navegador (duas cópias da mesma regra divergem).

   Nenhum estado guardado no navegador: tudo é gravado no servidor.
   ============================================================================= */
(function () {
    'use strict';

    var raiz  = document.getElementById('pexChecklist');
    var lista = document.getElementById('checklistLista');
    if (!raiz || !lista) { return; }

    var PASTA_ID    = raiz.getAttribute('data-pasta-id');
    var CSRF_PASTA  = raiz.getAttribute('data-csrf-pasta');
    var URL_ADD     = '/pasta/' + PASTA_ID + '/checklist';
    var URL_REORDER = '/pasta/' + PASTA_ID + '/checklist/reordenar';
    function URL_TOGGLE(id) { return '/pasta/' + PASTA_ID + '/checklist/' + id + '/toggle'; }
    function URL_EDIT(id)   { return '/pasta/' + PASTA_ID + '/checklist/' + id + '/editar'; }
    function URL_DELETE(id) { return '/pasta/' + PASTA_ID + '/checklist/' + id + '/excluir'; }

    var badge         = document.getElementById('checklistBadge');
    var barra         = document.getElementById('checklistBarra');
    var btnEditar     = document.getElementById('btnChecklistEditar');
    var btnAdicionar  = document.getElementById('btnChecklistAdicionar');
    var formAdicionar = document.getElementById('checklistFormAdicionar');
    var inputNovo     = document.getElementById('checklistNovoTitulo');
    var btnSalvarNovo = document.getElementById('btnChecklistSalvarNovo');
    var erroNovo      = document.getElementById('checklistErroNovo');

    var modoEdicao = false;
    var sortable   = null;

    function el(tag, classe, texto) {
        var e = document.createElement(tag);
        if (classe) { e.className = classe; }
        if (texto !== undefined && texto !== null) { e.textContent = texto; }
        return e;
    }

    function icone(classe) {
        var i = el('i', 'bi ' + classe);
        i.setAttribute('aria-hidden', 'true');
        return i;
    }

    function itens() {
        return lista.querySelectorAll('.checklist-item');
    }

    // ── Selo "N/M itens" + barra ──────────────────────────────────────────────

    function atualizarBadge() {
        var todos      = itens();
        var total      = todos.length;
        var concluidos = [].filter.call(todos, function (li) { return li.dataset.concluido === '1'; }).length;
        // 0/0 NÃO é completo: é documentação não listada, não conferida.
        var completo = total > 0 && concluidos === total;

        if (badge) {
            badge.textContent = concluidos + '/' + total + ' itens';
            badge.className   = 'pex-ck-selo ' + (completo ? 'completo' : 'incompleto');
        }

        // A barra vive no mesmo lugar que a contagem; atualizar uma e esquecer a
        // outra deixaria as duas dizendo coisas diferentes na mesma linha.
        if (barra) {
            barra.className   = 'pex-ck-barra-fill ' + (completo ? 'completo' : 'incompleto');
            barra.style.width = (total > 0 ? Math.round(concluidos / total * 100) : 0) + '%';
            var trilho = barra.parentElement;
            if (trilho) {
                trilho.setAttribute('aria-valuenow', concluidos);
                trilho.setAttribute('aria-valuemax', total);
            }
        }
    }

    function aplicarEstado(li, concluido) {
        var toggle = li.querySelector('.js-checklist-toggle');
        li.dataset.concluido = concluido ? '1' : '0';
        li.classList.toggle('concluido', concluido);
        if (toggle) {
            toggle.setAttribute('aria-pressed', concluido ? 'true' : 'false');
            toggle.title = concluido ? 'Marcar como pendente' : 'Marcar como concluído';
        }
        // O selo só existe quando o servidor conferiu e não achou arquivo; aparece com o item marcado.
        var selo = li.querySelector('.pex-ck-sem-anexo');
        if (selo) { selo.hidden = !concluido; }
    }

    function mostrarVazio() {
        if (lista.querySelector('.checklist-item') || document.getElementById('checklistVazio')) { return; }
        var vazio = el('li', 'pex-ck-vazio');
        vazio.id = 'checklistVazio';
        vazio.appendChild(document.createTextNode('Nenhum item. Clique em '));
        vazio.appendChild(icone('bi-plus-lg'));
        vazio.appendChild(document.createTextNode(' para adicionar.'));
        lista.appendChild(vazio);
    }

    function removerVazio() {
        var vazio = document.getElementById('checklistVazio');
        if (vazio) { vazio.remove(); }
    }

    // ── Marcar / desmarcar ───────────────────────────────────────────────────

    lista.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-checklist-toggle');
        if (!btn || !lista.contains(btn)) { return; }
        // No modo de edição o clique é de remover/renomear/arrastar, não de marcar: o
        // duplo clique que abre o renomear seria, antes, dois toggles.
        if (modoEdicao) { return; }

        var li = btn.closest('.checklist-item');
        btn.disabled = true;

        var fd = new FormData();
        fd.append('_token', li.dataset.csrfItem);

        fetch(URL_TOGGLE(li.dataset.itemId), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok) { throw new Error(data.erro || 'Erro'); }
            aplicarEstado(li, !!data.concluido);
            atualizarBadge();
        })
        .catch(function (err) { console.error('Erro no toggle:', err); })
        .finally(function () { btn.disabled = false; });
    });

    // ── Modo edição ──────────────────────────────────────────────────────────

    function aplicarModoEdicaoNoItem(li) {
        li.querySelector('.checklist-drag-handle').classList.toggle('d-none', !modoEdicao);
        li.querySelector('.js-checklist-excluir').classList.toggle('d-none', !modoEdicao);
    }

    btnEditar.addEventListener('click', function () {
        modoEdicao = !modoEdicao;
        btnEditar.classList.toggle('active', modoEdicao);
        btnEditar.setAttribute('aria-pressed', modoEdicao ? 'true' : 'false');
        btnEditar.title = modoEdicao ? 'Concluir edição' : 'Editar checklist (remover, renomear e reordenar itens)';
        lista.classList.toggle('editando', modoEdicao);

        itens().forEach(aplicarModoEdicaoNoItem);

        if (modoEdicao && typeof window.Sortable === 'function') {
            sortable = new window.Sortable(lista, {
                handle: '.checklist-drag-handle',
                draggable: '.checklist-item',
                animation: 150,
                onEnd: function () {
                    var ids = [].map.call(itens(), function (li) {
                        return parseInt(li.dataset.itemId, 10);
                    });
                    fetch(URL_REORDER, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ ids: ids, _token: CSRF_PASTA }),
                    })
                    .catch(function (err) { console.error('Erro ao reordenar:', err); });
                },
            });
        } else if (sortable) {
            sortable.destroy();
            sortable = null;
        }
    });

    // ── Renomear (duplo clique no modo de edição) ────────────────────────────

    lista.addEventListener('dblclick', function (e) {
        if (!modoEdicao) { return; }
        var li = e.target.closest('.checklist-item');
        if (!li || e.target.closest('.js-checklist-excluir')) { return; }

        var toggle = li.querySelector('.js-checklist-toggle');
        var input  = li.querySelector('.checklist-item-input');
        toggle.classList.add('d-none');
        input.classList.remove('d-none');
        input.focus();
        input.select();
    });

    lista.addEventListener('keydown', function (e) {
        var input = e.target.closest ? e.target.closest('.checklist-item-input') : null;
        if (!input) { return; }
        if (e.key === 'Enter')  { e.preventDefault(); salvarEdicaoItem(input); }
        if (e.key === 'Escape') { cancelarEdicaoItem(input); }
    });

    lista.addEventListener('blur', function (e) {
        var input = e.target.closest ? e.target.closest('.checklist-item-input') : null;
        if (input && !input.classList.contains('d-none')) { salvarEdicaoItem(input); }
    }, true);

    function salvarEdicaoItem(input) {
        // Guarda de requisição em voo: Enter salva e, enquanto o POST não volta, o blur (clique
        // fora, troca de foco) chamaria de novo — dois POSTs do mesmo renomear.
        if (input.dataset.salvando === '1') { return; }

        var li     = input.closest('.checklist-item');
        var titulo = input.value.trim();

        if (titulo === '') { cancelarEdicaoItem(input); return; }

        input.dataset.salvando = '1';

        var fd = new FormData();
        fd.append('titulo', titulo);
        fd.append('_token', li.dataset.csrfItem);

        fetch(URL_EDIT(li.dataset.itemId), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok) { throw new Error(data.erro || 'Erro'); }
            li.querySelector('.checklist-item-titulo').textContent = data.titulo;
            input.value = data.titulo;
            cancelarEdicaoItem(input);
        })
        .catch(function (err) {
            console.error('Erro ao editar item:', err);
            cancelarEdicaoItem(input);
        })
        .finally(function () { delete input.dataset.salvando; });
    }

    function cancelarEdicaoItem(input) {
        var li = input.closest('.checklist-item');
        input.classList.add('d-none');
        li.querySelector('.js-checklist-toggle').classList.remove('d-none');
    }

    // ── Remover ──────────────────────────────────────────────────────────────

    lista.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-checklist-excluir');
        if (!btn || !lista.contains(btn)) { return; }

        if (!confirm('Excluir este item do checklist?')) { return; }

        var li = btn.closest('.checklist-item');
        var fd = new FormData();
        fd.append('_token', li.dataset.csrfItem);

        fetch(URL_DELETE(li.dataset.itemId), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok) { throw new Error(data.erro || 'Erro'); }
            li.remove();
            atualizarBadge();
            mostrarVazio();
        })
        .catch(function (err) { console.error('Erro ao excluir item:', err); });
    });

    // ── Adicionar ────────────────────────────────────────────────────────────

    function abrirAdicionar(abrir) {
        formAdicionar.classList.toggle('d-none', !abrir);
        btnAdicionar.setAttribute('aria-expanded', abrir ? 'true' : 'false');
        if (abrir) {
            inputNovo.focus();
            return;
        }
        inputNovo.value = '';
        erroNovo.classList.add('d-none');
    }

    btnAdicionar.addEventListener('click', function () {
        abrirAdicionar(formAdicionar.classList.contains('d-none'));
    });

    btnSalvarNovo.addEventListener('click', salvarNovoItem);

    inputNovo.addEventListener('keydown', function (e) {
        if (e.key === 'Enter')  { e.preventDefault(); salvarNovoItem(); }
        if (e.key === 'Escape') { abrirAdicionar(false); }
    });

    function salvarNovoItem() {
        var titulo = inputNovo.value.trim();
        if (titulo === '') {
            erroNovo.textContent = 'O título não pode ser vazio.';
            erroNovo.classList.remove('d-none');
            return;
        }

        btnSalvarNovo.disabled = true;
        erroNovo.classList.add('d-none');

        var fd = new FormData();
        fd.append('titulo', titulo);
        fd.append('_token', CSRF_PASTA);

        fetch(URL_ADD, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok) { throw new Error(data.erro || 'Erro'); }
            removerVazio();
            lista.appendChild(criarItemLi(data));
            abrirAdicionar(false);
            atualizarBadge();
        })
        .catch(function (err) {
            erroNovo.textContent = err.message || 'Erro ao adicionar item.';
            erroNovo.classList.remove('d-none');
        })
        .finally(function () { btnSalvarNovo.disabled = false; });
    }

    /** A mesma linha que o template desenha — sem `data-tem-anexo` (não conferida). */
    function criarItemLi(data) {
        var li = el('li', 'checklist-item pex-ck-item');
        li.dataset.itemId    = data.id;
        li.dataset.csrfItem  = data.csrfItem;
        li.dataset.concluido = '0';

        var alca = el('span', 'checklist-drag-handle pex-ck-alca d-none');
        alca.title = 'Arrastar para reordenar';
        alca.setAttribute('aria-hidden', 'true');
        alca.appendChild(icone('bi-grip-vertical'));

        var toggle = el('button', 'pex-ck-linha js-checklist-toggle');
        toggle.type = 'button';
        var caixa = el('span', 'pex-ck-caixa');
        caixa.setAttribute('aria-hidden', 'true');
        caixa.appendChild(icone('bi-check2'));
        toggle.appendChild(caixa);
        toggle.appendChild(el('span', 'checklist-item-titulo pex-ck-texto', data.titulo));

        var input = el('input', 'checklist-item-input pex-ck-renomear d-none');
        input.type      = 'text';
        input.value     = data.titulo;
        input.maxLength = 255;
        input.setAttribute('aria-label', 'Renomear item do checklist');

        var remover = el('button', 'pex-ck-remover js-checklist-excluir d-none');
        remover.type  = 'button';
        remover.title = 'Remover item';
        remover.setAttribute('aria-label', 'Remover item');
        remover.appendChild(icone('bi-trash3'));

        li.appendChild(alca);
        li.appendChild(toggle);
        li.appendChild(input);
        li.appendChild(remover);

        aplicarEstado(li, !!data.concluido);
        aplicarModoEdicaoNoItem(li);

        return li;
    }

    // ── Modelos de checklist ─────────────────────────────────────────────────
    //
    // No mesmo IIFE de propósito: aplicar um modelo insere itens na mesma lista e
    // precisa das mesmas peças (`criarItemLi`, `atualizarBadge`, `modoEdicao`).
    // Duplicá-las num bloco separado é como as duas metades de uma tela divergem.

    var painelModelos = document.getElementById('checklistModelosPainel');
    var btnModelos    = document.getElementById('btnChecklistModelos');

    if (!painelModelos || !btnModelos) { return; }

    var listaModelos      = document.getElementById('checklistModelosLista');
    var modelosVazio      = document.getElementById('checklistModelosVazio');
    var modelosCarregando = document.getElementById('checklistModelosCarregando');
    var modelosErro       = document.getElementById('checklistModelosErro');
    var modelosAviso      = document.getElementById('checklistModelosAviso');
    var inputNomeModelo   = document.getElementById('checklistModeloNome');
    var btnSalvarModelo   = document.getElementById('btnChecklistModeloSalvar');
    var btnFecharModelos  = document.getElementById('btnChecklistModelosFechar');

    var URL_MODELOS_LISTAR = painelModelos.dataset.urlListar;
    var URL_MODELOS_SALVAR = painelModelos.dataset.urlSalvar;
    var CSRF_MODELOS_PASTA = painelModelos.dataset.csrfPasta;

    function URL_MODELO_APLICAR(id)  { return URL_MODELOS_LISTAR + '/' + id + '/aplicar'; }
    function URL_MODELO_RENOMEAR(id) { return URL_MODELOS_LISTAR + '/' + id + '/renomear'; }
    function URL_MODELO_EXCLUIR(id)  { return URL_MODELOS_LISTAR + '/' + id + '/excluir'; }

    var modelosCarregados = false;

    function mostrarErroModelos(msg) {
        modelosAviso.classList.add('d-none');
        modelosErro.textContent = msg;
        modelosErro.classList.remove('d-none');
    }

    function mostrarAvisoModelos(msg) {
        modelosErro.classList.add('d-none');
        modelosAviso.textContent = msg;
        modelosAviso.classList.remove('d-none');
    }

    function limparRecados() {
        modelosErro.classList.add('d-none');
        modelosAviso.classList.add('d-none');
    }

    // Erro do servidor vem como JSON com `erro`; fora isso, a mensagem genérica.
    function lerResposta(r) {
        return r.json()
            .catch(function () { throw new Error('Erro de comunicação.'); })
            .then(function (data) {
                if (!r.ok) { throw new Error(data.erro || data.mensagem || 'Erro.'); }
                return data;
            });
    }

    function botaoModelo(classe, rotulo, titulo, classeIcone) {
        var b = el('button', classe);
        b.type = 'button';
        if (titulo) { b.title = titulo; }
        if (classeIcone) { b.appendChild(icone(classeIcone)); }
        if (rotulo) { b.appendChild(document.createTextNode(rotulo)); }
        return b;
    }

    function renderModelos(modelos) {
        listaModelos.textContent = '';
        modelosVazio.classList.toggle('d-none', modelos.length > 0);

        modelos.forEach(function (modelo) {
            var li = el('li', 'pex-modelo');
            li.dataset.modeloId = modelo.id;
            li.dataset.csrf     = modelo.csrf;

            var info = el('div', 'pex-modelo-info');
            var nome = el('span', 'pex-modelo-nome', modelo.nome);
            nome.title = (modelo.itens || []).join(' · ');
            var qtd = el('span', 'pex-modelo-qtd', modelo.totalItens + ' ' + (modelo.totalItens === 1 ? 'item' : 'itens'));
            var input = el('input', 'pex-modelo-input form-control form-control-sm d-none');
            input.type      = 'text';
            input.value     = modelo.nome;
            input.maxLength = 120;
            info.appendChild(nome);
            info.appendChild(qtd);
            info.appendChild(input);

            var acoes = el('div', 'pex-modelo-acoes');
            acoes.appendChild(botaoModelo('pex-modelo-aplicar js-modelo-aplicar', 'Aplicar', null, null));
            acoes.appendChild(botaoModelo('pex-modelo-icone js-modelo-renomear', null, 'Renomear modelo', 'bi-pencil'));
            acoes.appendChild(botaoModelo('pex-modelo-icone perigo js-modelo-excluir', null, 'Excluir modelo', 'bi-trash'));

            li.appendChild(info);
            li.appendChild(acoes);
            listaModelos.appendChild(li);
        });
    }

    function carregarModelos() {
        modelosCarregando.classList.remove('d-none');
        modelosVazio.classList.add('d-none');

        return fetch(URL_MODELOS_LISTAR, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(lerResposta)
            .then(function (data) {
                renderModelos(data.modelos || []);
                modelosCarregados = true;
            })
            .catch(function (err) { mostrarErroModelos(err.message || 'Erro ao carregar os modelos.'); })
            .finally(function () { modelosCarregando.classList.add('d-none'); });
    }

    btnModelos.addEventListener('click', function () {
        var abrindo = painelModelos.classList.contains('d-none');

        painelModelos.classList.toggle('d-none', !abrindo);
        btnModelos.classList.toggle('active', abrindo);
        btnModelos.setAttribute('aria-expanded', abrindo ? 'true' : 'false');

        if (abrindo && !modelosCarregados) { carregarModelos(); }
    });

    btnFecharModelos.addEventListener('click', function () {
        painelModelos.classList.add('d-none');
        btnModelos.classList.remove('active');
        btnModelos.setAttribute('aria-expanded', 'false');
    });

    // ── Salvar o checklist desta pasta como modelo ───────────────────────────

    function salvarModelo(substituir) {
        var nome = inputNomeModelo.value.trim();

        if (nome === '') {
            mostrarErroModelos('Dê um nome ao modelo.');
            return;
        }

        btnSalvarModelo.disabled = true;
        limparRecados();

        var fd = new FormData();
        fd.append('nome', nome);
        fd.append('_token', CSRF_MODELOS_PASTA);
        if (substituir) { fd.append('substituir', '1'); }

        fetch(URL_MODELOS_SALVAR, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        })
        .then(function (r) {
            // 409 = o escritório já tem um modelo com esse nome. É a única resposta de
            // erro que vira PERGUNTA em vez de recado: sem ela não haveria como corrigir
            // um modelo salvo errado, só excluir e refazer.
            if (r.status === 409) {
                return r.json().then(function (data) {
                    if (confirm('Já existe o modelo "' + data.nome + '". Substituir os itens dele pelo checklist desta pasta?')) {
                        salvarModelo(true);
                    }
                    return null;
                });
            }
            return lerResposta(r);
        })
        .then(function (data) {
            if (!data) { return; }
            inputNomeModelo.value = '';
            mostrarAvisoModelos('Modelo "' + data.modelo.nome + '" salvo com ' + data.modelo.totalItens + ' itens.');
            return carregarModelos();
        })
        .catch(function (err) { mostrarErroModelos(err.message || 'Erro ao salvar o modelo.'); })
        .finally(function () { btnSalvarModelo.disabled = false; });
    }

    btnSalvarModelo.addEventListener('click', function () { salvarModelo(false); });

    inputNomeModelo.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); salvarModelo(false); }
    });

    // ── Aplicar / renomear / excluir ─────────────────────────────────────────

    listaModelos.addEventListener('click', function (e) {
        var li = e.target.closest('.pex-modelo');
        if (!li) { return; }

        if (e.target.closest('.js-modelo-aplicar'))  { aplicarModelo(li); return; }
        if (e.target.closest('.js-modelo-renomear')) { abrirRenomear(li);  return; }
        if (e.target.closest('.js-modelo-excluir'))  { excluirModelo(li); }
    });

    function aplicarModelo(li) {
        var btn = li.querySelector('.js-modelo-aplicar');
        btn.disabled = true;
        limparRecados();

        var fd = new FormData();
        fd.append('_token', li.dataset.csrf);

        fetch(URL_MODELO_APLICAR(li.dataset.modeloId), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        })
        .then(lerResposta)
        .then(function (data) {
            if (data.criados.length > 0) { removerVazio(); }

            data.criados.forEach(function (item) { lista.appendChild(criarItemLi(item)); });
            atualizarBadge();

            mostrarAvisoModelos(recadoDoAplicar(data));
        })
        .catch(function (err) { mostrarErroModelos(err.message || 'Erro ao aplicar o modelo.'); })
        .finally(function () { btn.disabled = false; });
    }

    // O que já existia é CONTADO, não escondido: sem esse número, aplicar um modelo
    // cujos itens a pasta já tinha pareceria um botão que não fez nada.
    function recadoDoAplicar(data) {
        if (data.totalCriados === 0 && data.totalIgnorados > 0) {
            return 'Nada a adicionar: esta pasta já tinha todos os itens de "' + data.nome + '".';
        }

        var recado = data.totalCriados + (data.totalCriados === 1 ? ' item adicionado' : ' itens adicionados');

        if (data.totalIgnorados > 0) {
            recado += ', ' + data.totalIgnorados + (data.totalIgnorados === 1 ? ' já existia' : ' já existiam');
        }

        return recado + '.';
    }

    function abrirRenomear(li) {
        var input = li.querySelector('.pex-modelo-input');
        li.querySelector('.pex-modelo-nome').classList.add('d-none');
        li.querySelector('.pex-modelo-qtd').classList.add('d-none');
        input.classList.remove('d-none');
        input.focus();
        input.select();
    }

    function fecharRenomear(li) {
        li.querySelector('.pex-modelo-input').classList.add('d-none');
        li.querySelector('.pex-modelo-nome').classList.remove('d-none');
        li.querySelector('.pex-modelo-qtd').classList.remove('d-none');
    }

    listaModelos.addEventListener('keydown', function (e) {
        var input = e.target.closest('.pex-modelo-input');
        if (!input) { return; }

        var li = input.closest('.pex-modelo');
        if (e.key === 'Escape') { input.value = li.querySelector('.pex-modelo-nome').textContent.trim(); fecharRenomear(li); }
        if (e.key === 'Enter')  { e.preventDefault(); salvarRenomear(li); }
    });

    function salvarRenomear(li) {
        var input = li.querySelector('.pex-modelo-input');
        var nome  = input.value.trim();

        if (nome === '') { fecharRenomear(li); return; }

        limparRecados();

        var fd = new FormData();
        fd.append('nome', nome);
        fd.append('_token', li.dataset.csrf);

        fetch(URL_MODELO_RENOMEAR(li.dataset.modeloId), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        })
        .then(lerResposta)
        .then(function (data) {
            li.querySelector('.pex-modelo-nome').textContent = data.nome;
            input.value = data.nome;
            fecharRenomear(li);
        })
        .catch(function (err) {
            mostrarErroModelos(err.message || 'Erro ao renomear o modelo.');
            fecharRenomear(li);
        });
    }

    function excluirModelo(li) {
        var nome = li.querySelector('.pex-modelo-nome').textContent.trim();

        if (!confirm('Excluir o modelo "' + nome + '"? Os checklists já aplicados nas pastas não mudam.')) { return; }

        limparRecados();

        var fd = new FormData();
        fd.append('_token', li.dataset.csrf);

        fetch(URL_MODELO_EXCLUIR(li.dataset.modeloId), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        })
        .then(lerResposta)
        .then(function () {
            li.remove();
            modelosVazio.classList.toggle('d-none', listaModelos.children.length > 0);
        })
        .catch(function (err) { mostrarErroModelos(err.message || 'Erro ao excluir o modelo.'); });
    }
}());
