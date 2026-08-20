/* ==========================================================================
   Gerenciador de Arquivos da Pasta (aba "Documentos") — comportamento
   Depende de: SortableJS (já carregado na página), Bootstrap 5 (Modal/Dropdown/Tab)
   e do helper de upload window.enviarArquivoComProgresso (definido no template).
   Reusa os modais existentes #previewDocModal e #editDocModal{id}.
   ========================================================================== */
(function () {
    'use strict';

    const fm = document.getElementById('fileManager');
    if (!fm) return;

    const pastaId = fm.dataset.pastaId;
    const cfg = {
        urlUpload:           fm.dataset.urlUpload,
        csrfUpload:          fm.dataset.csrfUpload,
        urlCriarSecao:       fm.dataset.urlCriarSecao,
        csrfCriarSecao:      fm.dataset.csrfCriarSecao,
        urlReordenarSecoes:  fm.dataset.urlReordenarSecoes,
        csrfReordenarSecoes: fm.dataset.csrfReordenarSecoes,
        urlReordenarDocs:    fm.dataset.urlReordenarDocs,
        csrfReordenarDocs:   fm.dataset.csrfReordenarDocs,
        urlRenomearTpl:      fm.dataset.urlRenomearTpl,
        urlExcluirTpl:       fm.dataset.urlExcluirTpl,
        urlMoverTpl:         fm.dataset.urlMoverTpl,
    };

    // Elementos
    const elBody        = document.getElementById('fmBody');
    const elPastas      = document.getElementById('fmPastas');
    const elGrupoPastas = document.getElementById('fmGrupoPastas');
    const elChecklist   = document.getElementById('fmChecklist');
    const elArquivos    = document.getElementById('fmArquivos');
    const elArquivosVazio = document.getElementById('fmArquivosVazio');
    const elArquivosTitulo = document.getElementById('fmArquivosTitulo');
    const elBusca       = document.getElementById('fmBusca');
    const elCrumbSep    = document.getElementById('fmCrumbSep');
    const elCrumbAtual  = document.getElementById('fmCrumbAtual');
    const elOrdenar     = document.getElementById('fmOrdenar');
    const fileInput     = document.getElementById('fmFileInput');
    const uploadBar     = document.getElementById('fmUploadBar');
    const uploadNome    = document.getElementById('fmUploadNome');
    const uploadProg    = document.getElementById('fmUploadProgresso');
    const uploadCont    = document.getElementById('fmUploadContador');

    // Estado
    let caminho = [];           // [] = raiz; senão a cadeia de ids até a pasta aberta
    const temArvore = fm.dataset.arvore === '1';
    let termoBusca = '';
    let modoView   = localStorage.getItem('fmView') || 'lista';
    let ordem      = localStorage.getItem('fmOrdem') || 'nome';
    let arrastando = null;      // cartão de pasta em arraste (mover para dentro de outra)
    // true quando o drop acabou de ser tratado como "mover para dentro" (bloco de mover-pasta,
    // mais abaixo). O Sortable das pastas usa o MESMO gesto (arrastar pela alça) para reordenar
    // entre irmãs — sem esta trava os dois caminhos disparam POST concorrentes (/mover e
    // /reordenar) ao soltar sobre outro cartão, e quem chega por último decide a ordem final.
    let pularReordenarPastas = false;

    // ---------------------------------------------------------------- utils --
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function todasPastas() { return Array.prototype.slice.call(elPastas ? elPastas.querySelectorAll('.fm-pasta') : []); }
    function todosArquivos() { return Array.prototype.slice.call(elArquivos.querySelectorAll('.fm-arquivo')); }
    function nomePasta(secaoId) {
        const c = elPastas ? elPastas.querySelector('.fm-pasta[data-secao-id="' + secaoId + '"]') : null;
        return c ? c.dataset.nome : '';
    }
    function toggle(el, mostrar) { if (el) el.classList.toggle('fm-oculto', !mostrar); }
    // Mesmo teste usado no upload por arraste (mais abaixo, no listener de #fmBody): identifica um
    // arraste vindo de FORA do navegador (arquivo do SO), que não dispara dragstart em nenhum
    // elemento da página — distinto do arraste de um cartão de pasta, que dispara.
    function arrasteContemArquivo(e) {
        return !!(e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1);
    }

    // ----------------------------------------------------------- navegação ---
    function paiDe(secaoId) {
        const c = elPastas ? elPastas.querySelector('.fm-pasta[data-secao-id="' + secaoId + '"]') : null;
        return c && c.dataset.paiId ? c.dataset.paiId : null;
    }

    function pastaAtualId() { return caminho.length ? caminho[caminho.length - 1] : 'geral'; }

    function caminhoLegivel(secaoId) {
        if (!secaoId || secaoId === 'geral') return 'Documentação Geral';
        const nomes = [];
        let atual = String(secaoId);
        let voltas = 0;
        while (atual && voltas < 100) { nomes.unshift(nomePasta(atual)); atual = paiDe(atual); voltas++; }
        return nomes.join(' › ');
    }

    function entrar(secaoId) {
        if (!temArvore) { caminho = [String(secaoId)]; }
        else {
            // remonta a cadeia inteira subindo pelos pais a partir do id recebido, sem depender
            // de por onde chegou aqui — garante o mesmo breadcrumb não importa qual `secaoId` entre.
            const cadeia = [];
            let atual = String(secaoId);
            let voltas = 0;
            while (atual && voltas < 100) { cadeia.unshift(atual); atual = paiDe(atual); voltas++; }
            caminho = cadeia;
        }
        termoBusca = '';
        if (elBusca) elBusca.value = '';
        sessionStorage.setItem('fmFolder_' + pastaId, JSON.stringify(caminho));
        render();
    }

    function voltarRaiz() { caminho = []; sessionStorage.setItem('fmFolder_' + pastaId, '[]'); render(); }

    // ------------------------------------------------------------- render ----
    function render() {
        const buscando = termoBusca.trim() !== '';
        const atual = pastaAtualId();
        const naRaiz = caminho.length === 0;

        renderCrumb();

        toggle(elChecklist, naRaiz && !buscando);

        // pastas visíveis = as filhas do nível aberto
        todasPastas().forEach(function (el) {
            const pai = el.dataset.paiId || '';
            const mostra = !buscando && (naRaiz ? pai === '' : pai === atual);
            el.classList.toggle('fm-oculto', !mostra);
        });
        toggle(elGrupoPastas, !buscando && todasPastas().some(function (el) {
            return !el.classList.contains('fm-oculto');
        }));

        if (elArquivosTitulo) {
            elArquivosTitulo.textContent = buscando ? 'Resultados' : (naRaiz ? 'Arquivos' : nomePasta(atual));
        }

        const termo = termoBusca.trim().toLowerCase();
        const visiveis = [];
        todosArquivos().forEach(function (el) {
            const mostra = buscando
                ? (el.dataset.nome || '').toLowerCase().indexOf(termo) !== -1
                : el.dataset.secao === atual;
            el.classList.toggle('fm-oculto', !mostra);
            if (mostra) visiveis.push(el);

            const badge = el.querySelector('.fm-arq-local');
            if (badge) {
                badge.textContent = buscando ? caminhoLegivel(el.dataset.secao) : '';
                badge.classList.toggle('fm-oculto', !buscando);
            }
        });

        ordenarNodes(visiveis);
        toggle(elArquivosVazio, visiveis.length === 0);
        toggle(elArquivos, visiveis.length > 0);

        elArquivos.classList.toggle('fm-grade', modoView === 'grade');
        elArquivos.classList.toggle('fm-lista', modoView !== 'grade');

        if (elPastas) {
            elPastas.classList.toggle('fm-grade', modoView === 'grade');
            elPastas.classList.toggle('fm-lista', modoView !== 'grade');
        }
    }

    function renderCrumb() {
        if (!elCrumbAtual) return;
        if (termoBusca.trim() !== '') {
            elCrumbSep && elCrumbSep.classList.remove('fm-oculto');
            elCrumbAtual.classList.remove('fm-oculto');
            elCrumbAtual.textContent = 'Resultados da busca';
            return;
        }
        if (caminho.length === 0) {
            elCrumbSep && elCrumbSep.classList.add('fm-oculto');
            elCrumbAtual.classList.add('fm-oculto');
            return;
        }
        elCrumbSep && elCrumbSep.classList.remove('fm-oculto');
        elCrumbAtual.classList.remove('fm-oculto');
        elCrumbAtual.innerHTML = caminho.map(function (id, i) {
            const nome = escapeHtml(nomePasta(id));
            return i === caminho.length - 1
                ? '<span>' + nome + '</span>'
                : '<button type="button" class="fm-crumb-nivel btn btn-link p-0 align-baseline" data-nivel="' + i + '">' + nome + '</button>';
        }).join(' <span class="fm-crumb-sep">›</span> ');
    }

    function ordenarNodes(nodes) {
        nodes.sort(function (a, b) {
            switch (ordem) {
                case 'manual':       return cmpNum(a, b, 'ordem');
                case 'nome':         return cmpTxt(a, b, 'nome');
                case 'nome_desc':    return -cmpTxt(a, b, 'nome');
                case 'data':         return cmpData(a, b);
                case 'data_desc':    return -cmpData(a, b);
                case 'tamanho':      return cmpNum(a, b, 'tamanho');
                case 'tamanho_desc': return -cmpNum(a, b, 'tamanho');
                default:             return 0;
            }
        });
        nodes.forEach(function (n) { elArquivos.appendChild(n); });
    }
    function cmpTxt(a, b, k) { return (a.dataset[k] || '').localeCompare(b.dataset[k] || '', 'pt-BR', { sensitivity: 'base' }); }
    function cmpNum(a, b, k) { return (Number(a.dataset[k]) || 0) - (Number(b.dataset[k]) || 0); }
    function cmpData(a, b) { return String(a.dataset.data || '').localeCompare(String(b.dataset.data || '')); }

    // ----------------------------------------------------- contadores --------
    function atualizarContagem(secaoId) {
        const n = todosArquivos().filter(function (el) { return el.dataset.secao === String(secaoId); }).length;
        const badge = fm.querySelector('[data-secao-contagem="' + secaoId + '"]');
        if (badge) badge.textContent = n + (n === 1 ? ' arquivo' : ' arquivos');
    }

    // ============================================================ EVENTOS ====

    // Navegação + menus da pasta
    if (elPastas) {
        elPastas.addEventListener('click', function (e) {
            const card = e.target.closest('.fm-pasta');
            if (!card) return;
            if (e.target.closest('.fm-pasta-abrir')) { entrar(card.dataset.secaoId); return; }
            if (e.target.closest('.fm-pasta-renomear')) { renomearPasta(card); return; }
            if (e.target.closest('.fm-pasta-mover')) { escolherDestino(card); return; }
            if (e.target.closest('.fm-pasta-excluir')) { excluirPasta(card); return; }
            if (e.target.closest('.dropdown') || e.target.closest('.fm-pasta-grip')) return;
            entrar(card.dataset.secaoId);
        });
        elPastas.addEventListener('keydown', function (e) {
            const card = e.target.closest('.fm-pasta');
            if (card && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); entrar(card.dataset.secaoId); }
        });
    }

    // Breadcrumb → raiz
    fm.querySelectorAll('.fm-crumb[data-nav="root"]').forEach(function (b) {
        b.addEventListener('click', voltarRaiz);
    });

    // Breadcrumb → nível intermediário da cadeia
    if (elCrumbAtual) {
        elCrumbAtual.addEventListener('click', function (e) {
            const b = e.target.closest('.fm-crumb-nivel');
            if (!b) return;
            caminho = caminho.slice(0, parseInt(b.dataset.nivel, 10) + 1);
            sessionStorage.setItem('fmFolder_' + pastaId, JSON.stringify(caminho));
            render();
        });
    }

    // Busca
    if (elBusca) elBusca.addEventListener('input', function () { termoBusca = elBusca.value; render(); });

    // Ordenar
    if (elOrdenar) {
        elOrdenar.value = ordem;
        elOrdenar.addEventListener('change', function () {
            ordem = elOrdenar.value;
            localStorage.setItem('fmOrdem', ordem);
            render();
        });
    }

    // Lista/grade
    fm.querySelectorAll('.fm-view-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            modoView = btn.dataset.view;
            localStorage.setItem('fmView', modoView);
            fm.querySelectorAll('.fm-view-btn').forEach(function (b) { b.classList.toggle('ativo', b === btn); });
            render();
        });
    });

    // Mantém a pasta acima das vizinhas enquanto o menu de ações está aberto.
    // Sem isso, o :hover aplica transform (cria stacking context) e o dropdown
    // do Bootstrap fica escondido atrás dos cartões de pasta seguintes.
    fm.addEventListener('show.bs.dropdown', function (e) {
        const card = e.target.closest('.fm-pasta');
        if (card) card.classList.add('menu-aberto');
    });
    fm.addEventListener('hide.bs.dropdown', function (e) {
        const card = e.target.closest('.fm-pasta');
        if (card) card.classList.remove('menu-aberto');
    });

    // Preview / Editar / Mover (delegado na lista de arquivos)
    elArquivos.addEventListener('click', function (e) {
        const prev = e.target.closest('.fm-arq-preview');
        if (prev) {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('previewDocModal')).show(prev);
            return;
        }
        const edit = e.target.closest('.fm-arq-editar');
        if (edit) {
            const alvo = document.querySelector(edit.dataset.target);
            if (alvo) bootstrap.Modal.getOrCreateInstance(alvo).show();
            return;
        }
        const mover = e.target.closest('.fm-arq-mover');
        if (mover) abrirMover(mover.closest('.fm-arquivo'));
    });

    // ------------------------------------------------------------- mover -----
    function abrirMover(arqEl) {
        const lista = document.getElementById('fmMoverLista');
        if (!lista || !arqEl) return;
        const atual = arqEl.dataset.secao;
        let html = '';
        if (atual !== 'geral') html += destinoBtn('geral', '<i class="bi bi-house-door me-2"></i>Documentos gerais');
        todasPastas().forEach(function (p) {
            if (p.dataset.secaoId !== atual) {
                html += destinoBtn(p.dataset.secaoId, '<i class="bi bi-folder-fill me-2 text-warning"></i>' + escapeHtml(p.dataset.nome));
            }
        });
        lista.innerHTML = html || '<div class="text-muted small p-2">Nenhum outro destino disponível.</div>';
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('fmMoverModal'));
        lista.querySelectorAll('[data-destino]').forEach(function (b) {
            b.addEventListener('click', function () { moverArquivo(arqEl, b.dataset.destino).then(function () { modal.hide(); }); });
        });
        modal.show();
    }
    function destinoBtn(id, label) {
        return '<button type="button" class="list-group-item list-group-item-action" data-destino="' + id + '">' + label + '</button>';
    }
    function moverArquivo(arqEl, destino) {
        const origem = arqEl.dataset.secao;
        const fd = new FormData();
        fd.append('_token', arqEl.dataset.csrfMover);
        if (destino !== 'geral') fd.append('secao_id', destino);
        return fetch(arqEl.dataset.urlMover, { method: 'POST', body: fd })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (!res.ok || !res.j.ok) throw new Error((res.j && res.j.erro) || 'Falha ao mover.');
                arqEl.dataset.secao = destino;
                atualizarContagem(origem);
                atualizarContagem(destino);
                render();
            })
            .catch(function (err) { alert(err.message); });
    }

    // -------------------------------------------------- CRUD de pastas -------
    const btnNovaPasta = document.getElementById('fmNovaPasta');
    if (btnNovaPasta) {
        btnNovaPasta.addEventListener('click', function () {
            pedirTexto('Nova pasta', '', 'Nome da pasta…').then(function (nome) {
                if (nome == null) return;
                const fd = new FormData();
                fd.append('_token', cfg.csrfCriarSecao);
                fd.append('nome', nome);
                if (temArvore && caminho.length) fd.append('paiId', pastaAtualId());
                fetch(cfg.urlCriarSecao, { method: 'POST', body: fd })
                    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                    .then(function (res) {
                        if (!res.ok) throw new Error(res.j.erro || 'Falha ao criar a pasta.');
                        adicionarCartaoPasta(res.j);
                    })
                    .catch(function (err) { alert(err.message); });
            });
        });
    }

    function adicionarCartaoPasta(secao) {
        if (!elPastas) return;
        const div = document.createElement('div');
        div.className = 'fm-pasta';
        div.dataset.secaoId = secao.id;
        div.dataset.nome = secao.nome;
        div.dataset.urlRenomear = cfg.urlRenomearTpl.replace('__ID__', secao.id);
        div.dataset.csrfRenomear = secao.csrfRenomear || '';
        div.dataset.urlExcluir = cfg.urlExcluirTpl.replace('__ID__', secao.id);
        div.dataset.csrfExcluir = secao.csrfExcluir || '';
        div.dataset.paiId = secao.paiId ? String(secao.paiId) : '';
        // cfg.urlMoverTpl só existe quando o template declara data-url-mover-tpl (árvore). Na
        // Cobrança (fm.dataset.arvore !== '1') o atributo não existe — sem a guarda, o .replace
        // em undefined quebraria a criação de pasta nessa página.
        div.dataset.urlMover = cfg.urlMoverTpl ? cfg.urlMoverTpl.replace('__ID__', secao.id) : '';
        div.dataset.csrfMover = secao.csrfMover || '';
        // Pasta recém-criada nasce vazia: sem isso o aviso de excluirPasta() leria undefined.
        div.dataset.subpastas = '0';
        div.dataset.arquivos = '0';
        div.setAttribute('tabindex', '0');
        div.setAttribute('role', 'button');
        div.innerHTML =
            '<span class="fm-pasta-grip" title="Arrastar para reordenar"><i class="bi bi-grip-vertical"></i></span>' +
            '<span class="fm-pasta-icone"><i class="bi bi-folder-fill"></i></span>' +
            '<span class="fm-pasta-info"><span class="fm-pasta-nome">' + escapeHtml(secao.nome) + '</span>' +
            '<span class="fm-pasta-contagem" data-secao-contagem="' + secao.id + '">0 arquivos</span></span>' +
            menuPastaHtml();
        elPastas.appendChild(div);
        render();
    }
    function menuPastaHtml() {
        return '<div class="dropdown">' +
            '<button class="fm-pasta-menu" type="button" data-bs-toggle="dropdown" aria-expanded="false" onclick="event.stopPropagation()"><i class="bi bi-three-dots-vertical"></i></button>' +
            '<ul class="dropdown-menu dropdown-menu-end">' +
            '<li><button class="dropdown-item fm-pasta-abrir" type="button"><i class="bi bi-folder2-open me-2"></i>Abrir</button></li>' +
            '<li><button class="dropdown-item fm-pasta-renomear" type="button"><i class="bi bi-pencil me-2"></i>Renomear</button></li>' +
            // "Mover para..." só existe com árvore — na Cobrança (nível único) não há para onde mover.
            (temArvore ? '<li><button class="dropdown-item fm-pasta-mover" type="button"><i class="bi bi-folder-symlink me-2"></i>Mover para...</button></li>' : '') +
            '<li><hr class="dropdown-divider"></li>' +
            '<li><button class="dropdown-item text-danger fm-pasta-excluir" type="button"><i class="bi bi-trash me-2"></i>Excluir</button></li>' +
            '</ul></div>';
    }

    function renomearPasta(card) {
        pedirTexto('Renomear pasta', card.dataset.nome, 'Nome da pasta…').then(function (nome) {
            if (nome == null) return;
            const fd = new FormData();
            fd.append('_token', card.dataset.csrfRenomear);
            fd.append('nome', nome);
            fetch(card.dataset.urlRenomear, { method: 'POST', body: fd })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (res) {
                    if (!res.ok || !res.j.ok) throw new Error((res.j && res.j.erro) || 'Falha ao renomear.');
                    card.dataset.nome = res.j.nome;
                    card.querySelector('.fm-pasta-nome').textContent = res.j.nome;
                    if (pastaAtualId() === card.dataset.secaoId) render();
                })
                .catch(function (err) { alert(err.message); });
        });
    }

    // Monta o aviso a partir de data-subpastas/data-arquivos, gravados no HTML pelo servidor (e
    // zerados na criação, em adicionarCartaoPasta) — o número tem de estar disponível ANTES do
    // clique de excluir, não só na resposta dele (D3).
    function pluralizar(n, singular, plural) { return n + ' ' + (n === 1 ? singular : plural); }

    function avisoExclusao(card) {
        const subpastas = Number(card.dataset.subpastas) || 0;
        const arquivos  = Number(card.dataset.arquivos) || 0;
        const nome      = card.dataset.nome;

        if (subpastas === 0 && arquivos === 0) {
            return 'A pasta "' + nome + '" está vazia. Excluir mesmo assim? Esta ação não pode ser desfeita.';
        }

        const partes = [];
        if (subpastas > 0) partes.push(pluralizar(subpastas, 'subpasta', 'subpastas'));
        if (arquivos > 0) partes.push(pluralizar(arquivos, 'arquivo', 'arquivos'));

        return 'Excluir a pasta "' + nome + '"? Ela contém ' + partes.join(' e ') + ', que serão excluídos junto. Esta ação não pode ser desfeita.';
    }

    function excluirPasta(card) {
        if (!confirm(avisoExclusao(card))) return;
        const fd = new FormData();
        fd.append('_token', card.dataset.csrfExcluir);
        fetch(card.dataset.urlExcluir, { method: 'POST', body: fd })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (!res.ok || !res.j.ok) throw new Error((res.j && res.j.erro) || 'Falha ao excluir.');
                const secaoId = card.dataset.secaoId;
                // O back apaga a ÁRVORE inteira (cascade). Sem remover as filhas/netas do DOM aqui,
                // os cartões e arquivos delas ficam apontando para linhas já apagadas até um reload.
                const subarvore = [secaoId].concat(descendentes(secaoId));
                todosArquivos().forEach(function (el) { if (subarvore.indexOf(el.dataset.secao) !== -1) el.remove(); });
                todasPastas().forEach(function (el) { if (subarvore.indexOf(el.dataset.secaoId) !== -1) el.remove(); });
                if (subarvore.indexOf(pastaAtualId()) !== -1) voltarRaiz(); else render();
            })
            .catch(function (err) { alert(err.message); });
    }

    // --------------------------------------------------- mover pasta ---------
    function moverPasta(card, destinoId) {
        const fd = new FormData();
        fd.append('_token', card.dataset.csrfMover);
        fd.append('destinoId', destinoId === null ? '' : String(destinoId));
        return fetch(card.dataset.urlMover, { method: 'POST', body: fd })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (!res.ok || !res.j.ok) throw new Error((res.j && res.j.erro) || 'Falha ao mover.');
                card.dataset.paiId = res.j.paiId ? String(res.j.paiId) : '';
                render();
            })
            .catch(function (err) { alert(err.message); });
    }

    function descendentes(secaoId, profundidade) {
        profundidade = profundidade || 0;
        // Disjuntor equivalente ao `voltas < 100` das funções que sobem a árvore (paiDe/entrar/
        // caminhoLegivel). Hoje o back impede ciclo, então isto nunca deveria disparar — mas é
        // justamente aqui, numa recursão que desce, que estouraria a pilha se o guard falhasse.
        if (profundidade >= 100) return [];
        const filhos = todasPastas().filter(function (el) { return el.dataset.paiId === String(secaoId); });
        return filhos.reduce(function (acc, el) {
            return acc.concat([el.dataset.secaoId], descendentes(el.dataset.secaoId, profundidade + 1));
        }, []);
    }

    function escolherDestino(card) {
        const proibidos = [card.dataset.secaoId].concat(descendentes(card.dataset.secaoId));
        const opcoes = todasPastas()
            .filter(function (el) { return proibidos.indexOf(el.dataset.secaoId) === -1; })
            // Nome cru repete entre galhos (em prod, 51% das seções têm prefixo numérico manual,
            // "01 - ", "01.4 - "): caminhoLegivel() mostra os ancestrais, igual ao Peticionar.
            .map(function (el) { return { id: el.dataset.secaoId, nome: caminhoLegivel(el.dataset.secaoId) }; });

        pedirDestino('Mover "' + card.dataset.nome + '" para', opcoes).then(function (destinoId) {
            if (destinoId === undefined) return;             // cancelou
            moverPasta(card, destinoId);                      // null = raiz da pasta
        });
    }

    // --------------------------------------------------- modal de texto ------
    let promptResolve = null;
    const inputModalEl = document.getElementById('fmInputModal');
    const inputModal   = inputModalEl ? new bootstrap.Modal(inputModalEl) : null;
    const inputCampo   = document.getElementById('fmInputCampo');
    const inputTitulo  = document.getElementById('fmInputTitulo');
    const inputErro    = document.getElementById('fmInputErro');

    function pedirTexto(titulo, valor, placeholder) {
        return new Promise(function (resolve) {
            if (!inputModal) { const v = prompt(titulo, valor || ''); resolve(v == null ? null : v.trim()); return; }
            promptResolve = resolve;
            inputTitulo.textContent = titulo;
            inputCampo.value = valor || '';
            inputCampo.placeholder = placeholder || '';
            inputErro.classList.add('d-none');
            inputModal.show();
            setTimeout(function () { inputCampo.focus(); inputCampo.select(); }, 250);
        });
    }
    if (inputModalEl) {
        const confirmar = function () {
            const v = (inputCampo.value || '').trim();
            if (v === '') { inputErro.textContent = 'Informe um nome.'; inputErro.classList.remove('d-none'); return; }
            const r = promptResolve; promptResolve = null;
            inputModal.hide();
            if (r) r(v);
        };
        document.getElementById('fmInputConfirmar').addEventListener('click', confirmar);
        inputCampo.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); confirmar(); } });
        inputModalEl.addEventListener('hidden.bs.modal', function () {
            if (promptResolve) { const r = promptResolve; promptResolve = null; r(null); }
        });
    }

    // --------------------------------------------------- modal de destino ----
    // Mesmo padrão de Promise do modal de texto acima, com <select> no lugar do campo de texto.
    let destinoResolve = null;
    const destinoModalEl = document.getElementById('fmDestinoModal');
    const destinoModal   = destinoModalEl ? new bootstrap.Modal(destinoModalEl) : null;
    const destinoCampo   = document.getElementById('fmDestinoCampo');
    const destinoTitulo  = document.getElementById('fmDestinoTitulo');

    function pedirDestino(titulo, opcoes) {
        return new Promise(function (resolve) {
            if (!destinoModal) { resolve(undefined); return; }   // sem o modal, a ação não acontece
            destinoResolve = resolve;
            destinoTitulo.textContent = titulo;
            destinoCampo.innerHTML = '<option value="">Raiz da pasta</option>' +
                opcoes.map(function (o) {
                    return '<option value="' + o.id + '">' + escapeHtml(o.nome) + '</option>';
                }).join('');
            destinoModal.show();
        });
    }
    if (destinoModalEl) {
        document.getElementById('fmDestinoConfirmar').addEventListener('click', function () {
            const v = destinoCampo.value || null;
            const r = destinoResolve; destinoResolve = null;
            destinoModal.hide();
            if (r) r(v);
        });
        destinoModalEl.addEventListener('hidden.bs.modal', function () {
            if (destinoResolve) { const r = destinoResolve; destinoResolve = null; r(undefined); }
        });
    }

    // ------------------------------------------------------------ upload -----
    const btnUpload = document.getElementById('fmUpload');
    if (btnUpload && fileInput) {
        btnUpload.addEventListener('click', function () { fileInput.click(); });
        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files.length) enviarArquivos(Array.prototype.slice.call(fileInput.files));
            fileInput.value = '';
        });
    }

    if (elBody) {
        ['dragenter', 'dragover'].forEach(function (ev) {
            elBody.addEventListener(ev, function (e) {
                if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1) {
                    e.preventDefault();
                    elBody.classList.add('arrastando-so');
                }
            });
        });
        elBody.addEventListener('dragleave', function (e) {
            if (!elBody.contains(e.relatedTarget)) elBody.classList.remove('arrastando-so');
        });
        elBody.addEventListener('drop', function (e) {
            elBody.classList.remove('arrastando-so');
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                e.preventDefault();
                enviarArquivos(Array.prototype.slice.call(e.dataTransfer.files));
            }
        });
    }

    function enviarArquivos(arquivos) {
        if (typeof window.enviarArquivoComProgresso !== 'function') { alert('Upload indisponível.'); return; }
        const destino = pastaAtualId() === 'geral' ? null : pastaAtualId();
        let i = 0, houveErro = false;
        uploadBar.classList.add('ativo');

        function proximo() {
            if (i >= arquivos.length) {
                uploadCont.textContent = houveErro ? 'Concluído com erros' : 'Concluído';
                // Recarrega para renderizar as linhas novas com todos os tokens/ações do servidor.
                sessionStorage.setItem('fmFolder_' + pastaId, JSON.stringify(caminho));
                sessionStorage.setItem('fmTab_' + pastaId, '1');
                setTimeout(function () { window.location.reload(); }, 700);
                return;
            }
            const file = arquivos[i];
            uploadNome.textContent = file.name;
            uploadCont.textContent = (i + 1) + '/' + arquivos.length;
            uploadProg.style.width = '0%';
            uploadProg.className = 'progress-bar';

            window.enviarArquivoComProgresso(file, {
                url: cfg.urlUpload,
                csrf: cfg.csrfUpload,
                categoria: 'DEMAIS',
                descricao: '',
                numero: '',
                secaoId: destino,
                reduzir: false,
                onProgress: function (pct) { uploadProg.style.width = pct + '%'; },
                onComprimindo: function () { uploadProg.style.width = '100%'; },
            }).catch(function (err) {
                houveErro = true;
                uploadProg.classList.add('bg-danger');
                uploadNome.textContent = '✗ ' + file.name + ' — ' + err.message;
            }).then(function () { i++; proximo(); });
        }
        proximo();
    }

    // ------------------------------------------------------ drag & drop ------
    if (window.Sortable) {
        if (elArquivos) {
            new Sortable(elArquivos, {
                handle: '.fm-arq-grip', draggable: '.fm-arquivo', animation: 150,
                filter: '.fm-lista-head', ghostClass: 'arrastando',
                onEnd: function () {
                    const visiveis = todosArquivos().filter(function (el) { return !el.classList.contains('fm-oculto'); });
                    // Fixa a nova ordem manual no próprio DOM e ativa o modo "Manual",
                    // senão o próximo render() reordenaria por nome/data e o arraste sumiria.
                    visiveis.forEach(function (el, i) { el.dataset.ordem = i + 1; });
                    if (ordem !== 'manual') {
                        ordem = 'manual';
                        if (elOrdenar) elOrdenar.value = 'manual';
                        localStorage.setItem('fmOrdem', 'manual');
                    }
                    persistir(cfg.urlReordenarDocs, cfg.csrfReordenarDocs, visiveis.map(function (el) { return Number(el.dataset.docId); }));
                },
            });
        }
        if (elPastas) {
            new Sortable(elPastas, {
                handle: '.fm-pasta-grip', draggable: '.fm-pasta', animation: 150, ghostClass: 'arrastando',
                onEnd: function () {
                    // O drop já foi tratado como "mover para dentro" (ver bloco de mover-pasta,
                    // mais abaixo) — não reordena por cima, senão os dois POSTs correm juntos.
                    if (pularReordenarPastas) { pularReordenarPastas = false; return; }
                    const ids = todasPastas().map(function (el) { return Number(el.dataset.secaoId); });
                    persistir(cfg.urlReordenarSecoes, cfg.csrfReordenarSecoes, ids);
                },
            });
        }
    }

    // Mover pasta arrastando o cartão inteiro sobre outro (aninhar). O Sortable acima cuida do
    // REORDENAR entre irmãs pelo grip (handle: '.fm-pasta-grip', intocado); este bloco cuida do
    // SOLTAR sobre outro cartão = mover para dentro dele. Os dois dependem do MESMO gesto porque
    // o Sortable só inicia o arraste (torna o cartão draggable) a partir do grip — arrastar pelo
    // corpo do cartão não inicia nada sozinho — então o dragstart abaixo só marca `arrastando`
    // quando o arraste do Sortable já está em curso.
    if (elPastas && temArvore) {
        elPastas.addEventListener('dragstart', function (e) {
            const card = e.target.closest('.fm-pasta');
            if (card) arrastando = card;
        });
        elPastas.addEventListener('dragover', function (e) {
            // #fmPastas mora dentro de #fmBody, que TAMBÉM aceita arraste de arquivo do SO para
            // upload (listener de #fmBody, mais abaixo). Sem esta guarda, um `arrastando` deixado
            // por um arraste de PASTA anterior (nunca limpo, porque arraste de arquivo externo não
            // dispara dragstart em elemento nenhum da página) seria reaproveitado por engano aqui.
            if (arrasteContemArquivo(e)) return;
            const alvo = e.target.closest('.fm-pasta');
            if (alvo && arrastando && alvo !== arrastando) { e.preventDefault(); alvo.classList.add('fm-pasta-alvo'); }
        });
        elPastas.addEventListener('dragleave', function (e) {
            const alvo = e.target.closest('.fm-pasta');
            if (alvo) alvo.classList.remove('fm-pasta-alvo');
        });
        elPastas.addEventListener('drop', function (e) {
            if (arrasteContemArquivo(e)) return;              // arquivo do SO: não é mover pasta
            const alvo = e.target.closest('.fm-pasta');
            if (!alvo || !arrastando || alvo === arrastando) return;
            e.preventDefault();
            alvo.classList.remove('fm-pasta-alvo');
            if (descendentes(arrastando.dataset.secaoId).indexOf(alvo.dataset.secaoId) !== -1) {
                alert('Não é possível mover uma pasta para dentro dela mesma.');
                return;
            }
            pularReordenarPastas = true;                       // ver onEnd do Sortable acima
            moverPasta(arrastando, alvo.dataset.secaoId);
            arrastando = null;                                 // não confia só no dragend abaixo
        });
        // dragend dispara sempre que o arraste termina — mesmo solto fora de um alvo válido ou
        // cancelado com Esc — e é o único ponto confiável para isto: sem ele, `arrastando` fica
        // apontando para o último cartão arrastado para sempre (não existe outro reset no arquivo).
        elPastas.addEventListener('dragend', function () {
            arrastando = null;
            elPastas.querySelectorAll('.fm-pasta-alvo').forEach(function (el) { el.classList.remove('fm-pasta-alvo'); });
        });
    }

    function persistir(url, csrf, ids) {
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ _token: csrf, ids: ids }),
        }).catch(function () { /* silencioso; um reload corrige a ordem */ });
    }

    // ------------------------------------------- persistência da aba/pasta ---
    const docTabBtn = document.getElementById('documentos-tab');
    if (docTabBtn) {
        docTabBtn.addEventListener('shown.bs.tab', function () { sessionStorage.setItem('fmTab_' + pastaId, '1'); });
        /* O container das abas é resolvido a partir do PRÓPRIO botão, não por id fixo: este script é
           COMPARTILHADO e o `<ul>` tem id diferente em cada página (`#pastaTabs` em pasta/show,
           `#objetoTabs` no objeto de cobrança). Com `#pastaTabs` fixo o clear só achava o alvo em Pastas;
           na Cobrança a flag entrava e NUNCA saía, e a aba Documentos grudava a cada reload até fechar o
           navegador (spec §2.1). O `closest` pode não achar o container — sem ele, não há irmão para
           escutar e o mecanismo simplesmente não registra nada. */
        const abas = docTabBtn.closest('.nav-tabs');
        if (abas) {
            abas.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (b) {
                if (b !== docTabBtn) b.addEventListener('shown.bs.tab', function () { sessionStorage.removeItem('fmTab_' + pastaId); });
            });
        }
    }

    // Estado inicial (restaura aba/pasta após reloads de excluir/editar/upload)
    (function inicializar() {
        fm.querySelectorAll('.fm-view-btn').forEach(function (b) { b.classList.toggle('ativo', b.dataset.view === modoView); });
        const folderSalvo = sessionStorage.getItem('fmFolder_' + pastaId);
        if (folderSalvo) {
            try {
                const c = JSON.parse(folderSalvo);
                // Só restaura se TODOS os níveis salvos ainda existirem como cartão — pasta salva que
                // foi excluída (ou sessionStorage de um formato antigo, pré-árvore) cai de volta na raiz.
                if (Array.isArray(c) && c.every(function (id) {
                    return elPastas && elPastas.querySelector('.fm-pasta[data-secao-id="' + id + '"]');
                })) {
                    caminho = c.map(String);
                }
            } catch (e) { /* formato inválido: mantém a raiz */ }
        }
        if (sessionStorage.getItem('fmTab_' + pastaId) === '1' && docTabBtn && window.bootstrap) {
            bootstrap.Tab.getOrCreateInstance(docTabBtn).show();
        }
        render();
    })();
})();
