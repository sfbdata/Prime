/* Aba Modelos da pasta: banco de modelos de petições (desenho 02 de 27/10, `bj-modelos.js`).
   O servidor monta a coluna esquerda e as linhas (GET pasta_modelos_listar); aqui só se troca o
   HTML, abre o modelo, edita no TinyMCE do sistema e grava. O painel direito alterna LISTA,
   VISUALIZAÇÃO e EDITOR. O corpo é sanitizado no servidor ao gravar. */
(function () {
    'use strict';

    var raiz = document.getElementById('mdlRaiz');
    if (!raiz) { return; }

    var urlBase = raiz.dataset.urlBase;
    var token = raiz.dataset.token;
    var podeEditar = raiz.dataset.podeEditar === '1';
    var CHAVE_RECOLHIDOS = 'bj-modelos-rec';

    var el = function (id) { return document.getElementById(id); };
    var coluna = el('mdlColuna');
    var lista = el('mdlLista');
    var painelLista = el('mdlPainelLista');
    var painelVer = el('mdlPainelVer');
    var painelEditor = el('mdlPainelEditor');
    var menu = el('mdlMenu');

    var estado = { materia: '', tipo: '', q: '', ord: 'uso', aberto: null, editandoId: null, usar: false, dados: null, vals: {} };
    var LIMITE_DO_VALOR = 500;
    var avisoTemporizador = null;
    var temporizador = null;
    var carregando = 0;

    function recolhidos() {
        try { return JSON.parse(localStorage.getItem(CHAVE_RECOLHIDOS) || '{}') || {}; } catch (e) { return {}; }
    }

    function guardarRecolhidos(mapa) {
        try { localStorage.setItem(CHAVE_RECOLHIDOS, JSON.stringify(mapa)); } catch (e) { /* sem armazenamento: só não lembra */ }
    }

    function aplicarRecolhidos() {
        var mapa = recolhidos();
        coluna.querySelectorAll('.mdl-bloco').forEach(function (bloco) {
            var recolhido = mapa[bloco.dataset.bloco] === true;
            bloco.querySelector('.mdl-bloco-chips').hidden = recolhido;
            var seta = bloco.querySelector('.mdl-bloco-tit > i');
            seta.className = 'bi ' + (recolhido ? 'bi-chevron-right' : 'bi-chevron-down');
        });
    }

    function aviso(mensagem) {
        if (typeof window.avisarPs === 'function') { window.avisarPs(mensagem, 'erro'); } else { window.alert(mensagem); }
    }

    function enviar(url, campos) {
        var dados = new URLSearchParams();
        dados.set('_token', token);
        Object.keys(campos || {}).forEach(function (k) { dados.set(k, campos[k]); });

        return fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: dados.toString()
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (corpo) { return { ok: r.ok, corpo: corpo }; });
        });
    }

    function carregar() {
        var minha = ++carregando;
        var params = new URLSearchParams({ materia: estado.materia, tipo: estado.tipo, q: estado.q, ord: estado.ord });

        return fetch(urlBase + '?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (dados) {
                if (minha !== carregando) { return; }
                coluna.innerHTML = dados.coluna;
                lista.innerHTML = dados.lista;
                el('mdlContador').textContent = dados.contador;
                aplicarRecolhidos();
            })
            .catch(function () { aviso(raiz.dataset.erroGenerico); });
    }

    function mostrar(qual) {
        painelLista.hidden = qual !== 'lista';
        painelVer.hidden = qual !== 'ver';
        if (painelEditor) { painelEditor.hidden = qual !== 'editor'; }
        fecharMenu();
    }

    function voltarParaLista() {
        estado.aberto = null;
        estado.editandoId = null;
        mostrar('lista');
    }

    function abrir(id) {
        return fetch(urlBase + '/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
            .then(function (dados) {
                var m = dados.modelo;
                estado.aberto = m;
                el('mdlVerTitulo').textContent = m.titulo;
                el('mdlVerSub').textContent = m.materiaRotulo + ' · ' + m.tipoRotulo + (m.usos > 0 ? ' · ' + raiz.dataset.usado.replace('{n}', m.usos) : '');
                estado.dados = dados.dados || { chaves: [], valores: {} };
                estado.vals = Object.assign({}, estado.dados.valores);
                montarDados();
                definirModo(false);
                mostrar('ver');
            })
            .catch(function () { aviso(raiz.dataset.erroGenerico); });
    }

    /* ── Variáveis da peça: a prévia repete a regra do servidor (PreenchedorDeModelo) ── */
    function esc(texto) {
        return String(texto).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function valorLimpo(v) {
        return String(v == null ? '' : v).trim().slice(0, LIMITE_DO_VALOR);
    }

    function preencher(corpo, valores) {
        var lacuna = raiz.dataset.lacuna;
        var html = corpo.replace(/\{(\w+)\}/g, function (_, chave) {
            var v = Object.prototype.hasOwnProperty.call(valores, chave) ? valorLimpo(valores[chave]) : '';
            return v === '' ? lacuna : esc(v);
        });
        var cliente = valorLimpo(valores.cliente);
        if (cliente === '' || cliente === lacuna) { return html; }

        return html.split(esc(cliente)).join('<b>' + esc(cliente.toUpperCase()) + '</b>');
    }

    function montarDados() {
        var grade = el('mdlDadosGrade');
        grade.innerHTML = '';
        estado.dados.chaves.forEach(function (chave) {
            var rotulo = document.createElement('label');
            rotulo.className = 'mdl-dado';
            rotulo.appendChild(document.createTextNode(chave.toUpperCase()));
            var campo = document.createElement('input');
            campo.type = 'text';
            campo.dataset.v = chave;
            campo.value = estado.vals[chave] || '';
            campo.placeholder = raiz.dataset.placeholderDado;
            campo.maxLength = LIMITE_DO_VALOR;
            rotulo.appendChild(campo);
            grade.appendChild(rotulo);
        });
    }

    function atualizarPrevia() {
        var m = estado.aberto;
        if (!m) { return; }
        el('mdlVerCorpo').innerHTML = estado.usar ? preencher(m.corpo, estado.vals) : m.corpo;
    }

    function definirModo(usar) {
        estado.usar = usar;
        painelVer.querySelectorAll('[data-modo]').forEach(function (b) {
            b.classList.toggle('mdl-seletor-btn--ativo', (b.dataset.modo === '1') === usar);
        });
        el('mdlDados').hidden = !usar;
        atualizarPrevia();
    }

    function toast(texto) {
        var caixa = el('mdlToast');
        caixa.textContent = texto;
        caixa.hidden = false;
        clearTimeout(avisoTemporizador);
        avisoTemporizador = setTimeout(function () { caixa.hidden = true; }, 4000);
    }

    function camposDosValores() {
        var campos = {};
        Object.keys(estado.vals).forEach(function (k) { campos['valores[' + k + ']'] = estado.vals[k]; });

        return campos;
    }

    function copiar() {
        var texto = el('mdlVerCorpo').innerText;
        var pronto = function () { toast(raiz.dataset.copiado); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(texto).then(pronto).catch(function () { aviso(raiz.dataset.erroGenerico); });
            return;
        }
        var area = document.createElement('textarea');
        area.value = texto;
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); pronto(); } catch (e) { aviso(raiz.dataset.erroGenerico); }
        document.body.removeChild(area);
    }

    function baixarWord() {
        var m = estado.aberto;
        if (!m) { return; }
        var campos = camposDosValores();
        campos.preenchido = estado.usar ? '1' : '0';

        var dados = new URLSearchParams();
        dados.set('_token', token);
        Object.keys(campos).forEach(function (k) { dados.set(k, campos[k]); });

        fetch(urlBase + '/' + m.id + '/word', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: dados.toString()
        }).then(function (r) {
            if (!r.ok) { return Promise.reject(); }
            var disposicao = r.headers.get('Content-Disposition') || '';
            var achado = /filename\*=UTF-8''([^;]+)/i.exec(disposicao) || /filename="?([^";]+)"?/i.exec(disposicao);
            var nome = achado ? decodeURIComponent(achado[1]) : 'modelo.docx';

            return r.blob().then(function (blob) { return { blob: blob, nome: nome }; });
        }).then(function (arquivo) {
            var a = document.createElement('a');
            a.href = URL.createObjectURL(arquivo.blob);
            a.download = arquivo.nome;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
        }).catch(function () { aviso(raiz.dataset.erroGenerico); });
    }

    function usarNaPasta() {
        var m = estado.aberto;
        if (!m) { return; }
        enviar(urlBase + '/' + m.id + '/usar', camposDosValores()).then(function (r) {
            if (!r.ok) { aviso(r.corpo.erro || raiz.dataset.erroGenerico); return; }
            toast(raiz.dataset.minutaGerada.replace('{titulo}', m.titulo).replace('{pasta}', raiz.dataset.nup));
            m.usos = r.corpo.usos;
            el('mdlVerSub').textContent = m.materiaRotulo + ' · ' + m.tipoRotulo + ' · ' + raiz.dataset.usado.replace('{n}', m.usos);
            carregar();
        });
    }

    /* Corpo padrão do "Novo modelo" (CAB + {cliente}... + ASS do desenho), em parágrafos para o editor */
    function corpoNovo() {
        return raiz.dataset.corpoNovo.split('\n\n').map(function (bloco) {
            return '<p>' + esc(bloco).replace(/\n/g, '<br>') + '</p>';
        }).join('');
    }

    function alternarFavoritoDoModelo(id) {
        enviar(urlBase + '/' + id + '/favorito').then(function (r) {
            if (r.ok) { carregar(); } else { aviso(r.corpo.erro || raiz.dataset.erroGenerico); }
        });
    }

    function alternarFavoritaDaMateria(materia) {
        enviar(urlBase + '/materias/' + encodeURIComponent(materia) + '/favorita').then(function (r) {
            if (r.ok) { carregar(); } else { aviso(r.corpo.erro || raiz.dataset.erroGenerico); }
        });
    }

    /* ── Menu ⋮ da visualização ─────────────────────────────────────────── */
    function fecharMenu() {
        if (!menu) { return; }
        menu.hidden = true;
        el('mdlMais').setAttribute('aria-expanded', 'false');
    }

    function alternarMenu() {
        var abrirAgora = menu.hidden;
        menu.hidden = !abrirAgora;
        el('mdlMais').setAttribute('aria-expanded', abrirAgora ? 'true' : 'false');
    }

    /* ── Editor (TinyMCE do sistema, carregado só quando se abre o primeiro) ── */
    function carregarTinymce() {
        if (window.tinymce) { return Promise.resolve(); }

        return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = '/js/tinymce/tinymce.min.js';
            s.onload = resolve;
            s.onerror = reject;
            document.head.appendChild(s);
        });
    }

    function iniciarEditor() {
        if (window.tinymce.get('mdlCampoCorpo')) { return Promise.resolve(window.tinymce.get('mdlCampoCorpo')); }

        return window.tinymce.init({
            selector: '#mdlCampoCorpo',
            base_url: '/js/tinymce',
            suffix: '.min',
            language: 'pt_BR',
            promotion: false,
            branding: false,
            menubar: false,
            statusbar: false,
            resize: false,
            height: 620,
            plugins: 'lists',
            toolbar: 'fontfamily fontsize | bold italic underline | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | h3 removeformat | undo redo',
            toolbar_mode: 'wrap',
            font_family_formats: 'Arial=arial,helvetica,sans-serif;Times New Roman=times new roman,times,serif;Calibri=calibri,sans-serif;Georgia=georgia,serif',
            font_size_formats: '10pt 12pt 14pt 18pt',
            browser_spellcheck: true,
            contextmenu: false,
            content_style: 'html{background:#e6eaee;padding:22px 12px}body{width:min(210mm,100%);min-height:297mm;margin:0 auto;box-sizing:border-box;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.18);padding:30mm 20mm 20mm 30mm;font:12pt/1.5 Arial,Helvetica,sans-serif;color:#111;text-align:justify}'
        }).then(function (editores) { return editores[0]; });
    }

    function abrirEditor(modelo, comoCopia) {
        estado.editandoId = modelo && !comoCopia ? modelo.id : null;
        el('mdlEditorTitulo').textContent = estado.editandoId ? raiz.dataset.tituloEditar : raiz.dataset.tituloNovo;
        el('mdlCampoTitulo').value = modelo ? modelo.titulo + (comoCopia ? ' ' + raiz.dataset.sufixoCopia : '') : '';
        el('mdlCampoMateria').value = modelo ? modelo.materia : (estado.materia && estado.materia !== '★' ? estado.materia : 'civel');
        el('mdlCampoTipo').value = modelo ? modelo.tipo : 'peticao_inicial';
        erro('');
        mostrar('editor');

        return carregarTinymce().then(iniciarEditor).then(function (editor) {
            editor.setContent(modelo ? modelo.corpo : corpoNovo());
            editor.undoManager.clear();
        }).catch(function () { aviso(raiz.dataset.erroGenerico); });
    }

    function erro(texto) {
        var caixa = el('mdlErro');
        caixa.textContent = texto;
        caixa.hidden = texto === '';
    }

    function salvar() {
        var editor = window.tinymce && window.tinymce.get('mdlCampoCorpo');
        var titulo = el('mdlCampoTitulo').value.trim();
        if (titulo === '') { erro(raiz.dataset.msgTitulo); return; }

        var url = estado.editandoId ? urlBase + '/' + estado.editandoId : urlBase;
        enviar(url, {
            titulo: titulo,
            materia: el('mdlCampoMateria').value,
            tipo: el('mdlCampoTipo').value,
            corpo: editor ? editor.getContent() : ''
        }).then(function (r) {
            if (!r.ok) { erro(r.corpo.erro || raiz.dataset.erroGenerico); return; }
            // Já gravado: um novo Salvar edita este modelo, mesmo que abrir() falhe.
            estado.editandoId = r.corpo.modelo.id;
            carregar();
            abrir(r.corpo.modelo.id);
        });
    }

    function excluir() {
        var m = estado.aberto;
        if (!m) { return; }
        var pergunta = raiz.dataset.confirmarExcluir.replace('{titulo}', m.titulo);
        var confirmacao = typeof window.confirmarPs === 'function'
            ? window.confirmarPs({ titulo: pergunta, variante: 'perigo' })
            : Promise.resolve(window.confirm(pergunta));

        confirmacao.then(function (confirmou) {
            if (!confirmou) { return; }
            enviar(urlBase + '/' + m.id + '/excluir').then(function (r) {
                if (!r.ok) { aviso(r.corpo.erro || raiz.dataset.erroGenerico); return; }
                voltarParaLista();
                carregar();
            });
        });
    }

    /* ── Eventos ────────────────────────────────────────────────────────── */
    coluna.addEventListener('click', function (e) {
        var estrela = e.target.closest('[data-fav-materia]');
        if (estrela) {
            e.stopPropagation();
            if (podeEditar) { alternarFavoritaDaMateria(estrela.dataset.favMateria); }
            return;
        }
        var titulo = e.target.closest('[data-bloco-tit]');
        if (titulo) {
            var mapa = recolhidos();
            var nome = titulo.dataset.blocoTit;
            mapa[nome] = !(mapa[nome] === true);
            guardarRecolhidos(mapa);
            aplicarRecolhidos();
            return;
        }
        var chip = e.target.closest('[data-materia]');
        if (chip) {
            estado.materia = chip.dataset.materia;
            voltarParaLista();
            carregar();
        }
    });

    lista.addEventListener('click', function (e) {
        var estrela = e.target.closest('[data-fav]');
        if (estrela) {
            e.stopPropagation();
            if (podeEditar) { alternarFavoritoDoModelo(estrela.dataset.fav); }
            return;
        }
        var linha = e.target.closest('[data-abrir]');
        if (linha) { abrir(linha.dataset.abrir); }
    });

    el('mdlBusca').addEventListener('input', function (e) {
        estado.q = e.target.value;
        clearTimeout(temporizador);
        temporizador = setTimeout(carregar, 250);
    });
    el('mdlTipo').addEventListener('change', function (e) { estado.tipo = e.target.value; carregar(); });
    el('mdlOrdem').addEventListener('change', function (e) { estado.ord = e.target.value; carregar(); });
    el('mdlVoltar').addEventListener('click', voltarParaLista);
    painelVer.querySelectorAll('[data-modo]').forEach(function (b) {
        b.addEventListener('click', function () { definirModo(b.dataset.modo === '1'); });
    });
    el('mdlDadosGrade').addEventListener('input', function (e) {
        var campo = e.target.closest('[data-v]');
        if (!campo) { return; }
        estado.vals[campo.dataset.v] = campo.value;
        atualizarPrevia();
    });
    el('mdlCopiar').addEventListener('click', copiar);
    el('mdlWord').addEventListener('click', baixarWord);

    if (podeEditar) {
        el('mdlNovo').addEventListener('click', function () { abrirEditor(null, false); });
        el('mdlMais').addEventListener('click', function (e) { e.stopPropagation(); alternarMenu(); });
        menu.addEventListener('click', function (e) {
            var item = e.target.closest('[data-acao]');
            if (!item) { return; }
            var acao = item.dataset.acao;
            fecharMenu();
            if (acao === 'editar') { abrirEditor(estado.aberto, false); }
            if (acao === 'duplicar') { abrirEditor(estado.aberto, true); }
            if (acao === 'excluir') { excluir(); }
        });
        el('mdlCancelar').addEventListener('click', function () {
            estado.editandoId = null;
            if (estado.aberto) { mostrar('ver'); } else { mostrar('lista'); }
        });
        el('mdlSalvar').addEventListener('click', salvar);
        el('mdlUsar').addEventListener('click', usarNaPasta);
        document.addEventListener('click', function (e) {
            if (!menu.hidden && !menu.contains(e.target)) { fecharMenu(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { fecharMenu(); }
        });
    }

    carregar();
}());
