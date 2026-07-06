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
    let pastaAtual = 'geral';   // 'geral' (raiz) ou id da seção (string)
    let termoBusca = '';
    let modoView   = localStorage.getItem('fmView') || 'lista';
    let ordem      = localStorage.getItem('fmOrdem') || 'nome';

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

    // ----------------------------------------------------------- navegação ---
    function entrar(secaoId) {
        pastaAtual = String(secaoId);
        termoBusca = '';
        if (elBusca) elBusca.value = '';
        sessionStorage.setItem('fmFolder_' + pastaId, pastaAtual);
        render();
    }
    function voltarRaiz() { entrar('geral'); }

    // ------------------------------------------------------------- render ----
    function render() {
        const buscando = termoBusca.trim() !== '';
        const naRaiz = pastaAtual === 'geral';

        if (buscando)      mostrarCrumb('Resultados da busca');
        else if (naRaiz)   esconderCrumb();
        else               mostrarCrumb(nomePasta(pastaAtual));

        toggle(elChecklist, naRaiz && !buscando);
        toggle(elGrupoPastas, naRaiz && !buscando && todasPastas().length > 0);

        if (elArquivosTitulo) {
            elArquivosTitulo.textContent = buscando ? 'Resultados' : (naRaiz ? 'Arquivos' : nomePasta(pastaAtual));
        }

        const termo = termoBusca.trim().toLowerCase();
        const visiveis = [];
        todosArquivos().forEach(function (el) {
            const mostra = buscando
                ? (el.dataset.nome || '').toLowerCase().indexOf(termo) !== -1
                : el.dataset.secao === pastaAtual;
            el.classList.toggle('fm-oculto', !mostra);
            if (mostra) visiveis.push(el);
        });

        ordenarNodes(visiveis);
        toggle(elArquivosVazio, visiveis.length === 0);
        toggle(elArquivos, visiveis.length > 0);

        elArquivos.classList.toggle('fm-grade', modoView === 'grade');
        elArquivos.classList.toggle('fm-lista', modoView !== 'grade');
    }

    function mostrarCrumb(txt) {
        if (elCrumbSep) elCrumbSep.classList.remove('fm-oculto');
        if (elCrumbAtual) { elCrumbAtual.classList.remove('fm-oculto'); elCrumbAtual.textContent = txt; }
    }
    function esconderCrumb() {
        if (elCrumbSep) elCrumbSep.classList.add('fm-oculto');
        if (elCrumbAtual) elCrumbAtual.classList.add('fm-oculto');
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
                    if (pastaAtual === card.dataset.secaoId) render();
                })
                .catch(function (err) { alert(err.message); });
        });
    }

    function excluirPasta(card) {
        if (!confirm('Excluir a pasta "' + card.dataset.nome + '" e TODOS os arquivos dentro dela? Esta ação não pode ser desfeita.')) return;
        const fd = new FormData();
        fd.append('_token', card.dataset.csrfExcluir);
        fetch(card.dataset.urlExcluir, { method: 'POST', body: fd })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (!res.ok || !res.j.ok) throw new Error((res.j && res.j.erro) || 'Falha ao excluir.');
                const secaoId = card.dataset.secaoId;
                todosArquivos().forEach(function (el) { if (el.dataset.secao === secaoId) el.remove(); });
                card.remove();
                if (pastaAtual === secaoId) voltarRaiz(); else render();
            })
            .catch(function (err) { alert(err.message); });
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
        const destino = pastaAtual === 'geral' ? null : pastaAtual;
        let i = 0, houveErro = false;
        uploadBar.classList.add('ativo');

        function proximo() {
            if (i >= arquivos.length) {
                uploadCont.textContent = houveErro ? 'Concluído com erros' : 'Concluído';
                // Recarrega para renderizar as linhas novas com todos os tokens/ações do servidor.
                sessionStorage.setItem('fmFolder_' + pastaId, pastaAtual);
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
                    const ids = todasPastas().map(function (el) { return Number(el.dataset.secaoId); });
                    persistir(cfg.urlReordenarSecoes, cfg.csrfReordenarSecoes, ids);
                },
            });
        }
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
        document.querySelectorAll('#pastaTabs [data-bs-toggle="tab"]').forEach(function (b) {
            if (b !== docTabBtn) b.addEventListener('shown.bs.tab', function () { sessionStorage.removeItem('fmTab_' + pastaId); });
        });
    }

    // Estado inicial (restaura aba/pasta após reloads de excluir/editar/upload)
    (function inicializar() {
        fm.querySelectorAll('.fm-view-btn').forEach(function (b) { b.classList.toggle('ativo', b.dataset.view === modoView); });
        const folderSalvo = sessionStorage.getItem('fmFolder_' + pastaId);
        if (folderSalvo && (folderSalvo === 'geral' || (elPastas && elPastas.querySelector('.fm-pasta[data-secao-id="' + folderSalvo + '"]')))) {
            pastaAtual = folderSalvo;
        }
        if (sessionStorage.getItem('fmTab_' + pastaId) === '1' && docTabBtn && window.bootstrap) {
            bootstrap.Tab.getOrCreateInstance(docTabBtn).show();
        }
        render();
    })();
})();
