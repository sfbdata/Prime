/* =============================================================================
   expediente-lista.js — menus de botão direito da Lista do Expediente (E8 da frente
   `fidelidade-designer`; desenho `03 - EXPEDIENTE Lista 1.1.dc.html`, dc L.233-243 e L.272-293).

   SÓ ações que já existem (decisão do dono, 14/10). Cada item aciona o MESMO controle que a tela
   já tem — nada de rota nova, nada de permissão nova:
   - linha da pasta: Abrir · Abrir em nova aba · Alterar responsável (o chip da linha) · Alterar
     marcador (o botão de marcadores da linha) · Copiar número · Copiar nome. Na pasta excluída
     (lápide, somente leitura) os dois "Alterar" não aparecem.
   - marcador do trilho: Ver pastas · Novo marcador · Novo sub-marcador (só no de topo) · Editar ·
     Excluir (pelo modal Editar, que pergunta na janela da tela — E7).
   Fora, por falta de backend ou por serem inferência do protótipo: Editar nome rápido (F2), Trancar,
   Destacar linha, Duplicar marcador.

   Teclado: o menu abre também pela tecla de menu (ou Shift+F10) num marcador; setas, Home/End
   navegam, Esc fecha e devolve o foco, Tab fecha. Nada aqui monta HTML com dado: só textContent.
   ============================================================================= */
(function () {
    'use strict';

    var menu = null;
    var origem = null;   // quem tinha o foco ao abrir
    var aviso = null;
    var avisoTimer = 0;

    /* Região viva criada na carga, vazia (lição da PAS-034c): leitor de tela só anuncia mudança de uma
       região que já existia. O aviso visual fica `aria-hidden`, para não ser lido duas vezes. */
    var vivo = document.createElement('div');
    vivo.className = 'visually-hidden';
    vivo.setAttribute('role', 'status');
    vivo.setAttribute('aria-live', 'polite');
    vivo.setAttribute('aria-atomic', 'true');
    document.body.appendChild(vivo);

    function fechar(devolverFoco) {
        if (!menu) { return; }
        menu.remove();
        menu = null;
        if (devolverFoco && origem && document.contains(origem) && origem.focus) { origem.focus(); }
        origem = null;
    }

    function itens() {
        return menu ? Array.prototype.slice.call(menu.querySelectorAll('.exp-ctx-item')) : [];
    }

    function abrir(titulo, lista, x, y, quem, variante) {
        fechar(false);
        origem = quem || document.activeElement;
        menu = document.createElement('div');
        menu.className = 'exp-ctx' + (variante ? ' exp-ctx--' + variante : '');
        menu.setAttribute('role', 'menu');
        menu.setAttribute('aria-label', titulo);

        var cab = document.createElement('div');
        cab.className = 'exp-ctx-titulo';
        cab.textContent = titulo;
        menu.appendChild(cab);

        lista.forEach(function (it) {
            if (it === '-') {
                var sep = document.createElement('div');
                sep.className = 'exp-ctx-sep';
                sep.setAttribute('role', 'separator');
                menu.appendChild(sep);
                return;
            }
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'exp-ctx-item' + (it.perigo ? ' exp-ctx-item--perigo' : '');
            b.setAttribute('role', 'menuitem');
            var i = document.createElement('i');
            i.className = 'bi ' + it.icone;
            i.setAttribute('aria-hidden', 'true');
            var t = document.createElement('span');
            t.textContent = it.rotulo;
            b.appendChild(i);
            b.appendChild(t);
            b.addEventListener('click', function () {
                fechar(false);
                // Depois que ESTE clique terminar de subir: o menu de responsável (e outros) fecham no
                // clique do documento — acionado aqui dentro, abriria e fecharia no mesmo clique.
                setTimeout(it.acao, 0);
            });
            menu.appendChild(b);
        });

        document.body.appendChild(menu);
        var r = menu.getBoundingClientRect();
        var esq = Math.max(8, Math.min(x, window.innerWidth - r.width - 8));
        var topo = Math.max(8, Math.min(y, window.innerHeight - r.height - 8));
        menu.style.left = esq + 'px';
        menu.style.top = topo + 'px';

        var primeiro = itens()[0];
        if (primeiro) { primeiro.focus(); }
    }

    function avisar(texto) {
        if (!aviso) {
            aviso = document.createElement('div');
            aviso.className = 'exp-aviso';
            aviso.setAttribute('aria-hidden', 'true');
            var i = document.createElement('i');
            i.className = 'bi bi-check-circle-fill';
            i.setAttribute('aria-hidden', 'true');
            aviso.appendChild(i);
            aviso.appendChild(document.createElement('span'));
            document.body.appendChild(aviso);
        }
        aviso.querySelector('span').textContent = texto;
        aviso.hidden = false;
        vivo.textContent = '';
        setTimeout(function () { vivo.textContent = texto; }, 50);
        clearTimeout(avisoTimer);
        avisoTimer = setTimeout(function () { aviso.hidden = true; }, 2800);
    }

    /* Copiar: a API do navegador só existe em contexto seguro (https); fora dele, o caminho antigo. */
    function copiar(texto, confirmacao) {
        var feito = function () { avisar(confirmacao); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(texto).then(feito, function () { copiarAntigo(texto) && feito(); });
            return;
        }
        if (copiarAntigo(texto)) { feito(); }
    }

    function copiarAntigo(texto) {
        var area = document.createElement('textarea');
        area.value = texto;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        area.remove();
        return ok;
    }

    function textoLimpo(el) {
        return el ? el.textContent.replace(/\s+/g, ' ').trim() : '';
    }

    /* ── Linha da pasta ─────────────────────────────────────────────────── */
    function menuDaLinha(linha, x, y) {
        var href = linha.getAttribute('data-href');
        if (!href) { return; }
        var nup = textoLimpo(linha.cells[0]);
        // O nome é o identificador da pasta ou o do cliente; com vários clientes, todos (sem o "+N").
        var celula = linha.cells[1];
        var resumo = celula ? celula.querySelector('[data-clientes]') : null;
        var nome = resumo
            ? resumo.getAttribute('data-clientes').split('||').join('; ')
            : textoLimpo(celula ? (celula.querySelector('.text-truncate') || celula) : null);
        var excluida = linha.classList.contains('pasta-excluida');
        var chip = linha.querySelector('.pasta-resp-chip');
        var marcar = linha.querySelector('.js-mover-para');

        // Rótulos, ícones e ordem do dc L.276-282, sem separadores (no dc o primeiro separador vem antes de
        // Trancar, que não existe aqui). Título como o dc L.274: "Pasta 1365 · NOME".
        var temNome = nome && nome !== '—';
        var lista = [
            { rotulo: 'Abrir pasta', icone: 'bi-folder2-open', acao: function () { window.location.href = href; } },
            { rotulo: 'Abrir em nova aba', icone: 'bi-box-arrow-up-right', acao: function () { window.open(href, '_blank', 'noopener'); } }
        ];
        if (!excluida && chip) { lista.push({ rotulo: 'Alterar responsável', icone: 'bi-person-badge', acao: function () { chip.click(); } }); }
        if (!excluida && marcar) { lista.push({ rotulo: 'Alterar marcador', icone: 'bi-tags', acao: function () { marcar.click(); } }); }
        if (nup) { lista.push({ rotulo: 'Copiar número da pasta', icone: 'bi-hash', acao: function () { copiar(nup, 'Número ' + nup + ' copiado'); } }); }
        if (temNome) { lista.push({ rotulo: 'Copiar nome do cliente', icone: 'bi-clipboard', acao: function () { copiar(nome, 'Nome do cliente copiado'); } }); }

        abrir('Pasta ' + nup + (temNome ? ' · ' + nome : ''), lista, x, y, linha);
    }

    /* ── Marcador do trilho ─────────────────────────────────────────────── */
    function menuDoMarcador(link, x, y) {
        var nome = textoLimpo(link.querySelector('.expediente-item-nome'));
        var editar = link.querySelector('.js-editar-marcador');
        var addSub = link.querySelector('.expediente-btn-add-sub');
        var novo = document.querySelector('.js-abrir-modal-marcador[data-pai-id=""]');

        // Ícones do dc L.236-241 (bi-funnel, bi-plus-lg); "Novo sub-marcador" é só do BlueJus (hierarquia).
        var lista = [
            { rotulo: 'Ver pastas do marcador', icone: 'bi-funnel', acao: function () { link.click(); } }
        ];
        if (novo) { lista.push({ rotulo: 'Novo marcador', icone: 'bi-plus-lg', acao: function () { novo.click(); } }); }
        if (addSub) { lista.push({ rotulo: 'Novo sub-marcador', icone: 'bi-node-plus', acao: function () { addSub.click(); } }); }
        if (editar) {
            lista.push({ rotulo: 'Editar', icone: 'bi-pencil', acao: function () { editar.click(); } });
            lista.push('-');
            lista.push({ rotulo: 'Excluir marcador', icone: 'bi-trash3', perigo: true, acao: function () { excluirPeloModal(editar); } });
        }

        // Título como o dc L.235: "1. Despachar · 122 pasta(s)" (a contagem é a do trilho; sem ela, nenhuma).
        var contagem = textoLimpo(link.querySelector('.js-contagem-marcador')) || '0';
        abrir(nome + ' · ' + contagem + ' pasta(s)', lista, x, y, link, 'marcador');
    }

    /* Excluir passa pelo modal Editar de sempre (mesmo token, mesma rota) e pergunta na janela da tela. */
    function excluirPeloModal(editar) {
        var modal = document.getElementById('modalEditarMarcador');
        var botao = document.getElementById('btnExcluirMarcadorModal');
        if (!modal || !botao) { editar.click(); return; }
        // O modal põe o foco no nome 300 ms depois de aberto (index.html.twig); a pergunta vem depois
        // disso, senão o foco sai da janela de confirmação (com "reduzir movimento" o modal abre na hora).
        modal.addEventListener('shown.bs.modal', function () { setTimeout(function () { botao.click(); }, 360); }, { once: true });
        editar.click();
    }

    /* ── Ligações ───────────────────────────────────────────────────────── */
    document.addEventListener('contextmenu', function (e) {
        var linha = e.target.closest('#tabelaPastas tbody tr.pasta-row-link');
        var marcador = e.target.closest('.expediente-menu-link[data-marcador-id], .expediente-submenu-link[data-marcador-id]');
        if (!linha && !marcador) { return; }
        // Dentro de campo de texto, o menu do navegador (colar, corretor) continua.
        if (e.target.closest('input, textarea, [contenteditable="true"]')) { return; }
        e.preventDefault();
        var x = e.clientX;
        var y = e.clientY;
        if (!x && !y) {   // pela tecla de menu: abre junto do elemento
            var r = (linha || marcador).getBoundingClientRect();
            x = r.left + 24;
            y = r.top + r.height;
        }
        if (linha) { menuDaLinha(linha, x, y); } else { menuDoMarcador(marcador, x, y); }
    });

    document.addEventListener('keydown', function (e) {
        if (!menu) { return; }
        var lista = itens();
        var i = lista.indexOf(document.activeElement);
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); fechar(true); return; }
        if (e.key === 'Tab') { e.preventDefault(); fechar(true); return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); lista[(i + 1) % lista.length].focus(); return; }
        if (e.key === 'ArrowUp') { e.preventDefault(); lista[(i - 1 + lista.length) % lista.length].focus(); return; }
        if (e.key === 'Home') { e.preventDefault(); lista[0].focus(); return; }
        if (e.key === 'End') { e.preventDefault(); lista[lista.length - 1].focus(); }
    }, true);

    document.addEventListener('mousedown', function (e) {
        if (menu && !menu.contains(e.target)) { fechar(false); }
    }, true);
    window.addEventListener('scroll', function () { fechar(false); }, true);
    window.addEventListener('resize', function () { fechar(false); });
}());

/* =============================================================================
   Cabeçalho fixo da tabela (LX-TAB-02; dc L.145 `sticky top:124px` + `syncCab` L.475).

   O `.table-responsive` rola nos dois eixos e prende qualquer `position: sticky` do thead. Em vez de
   clonar o cabeçalho, como o dc, o próprio thead desce (translateY) enquanto a tabela passa sob o topo fixo
   do sistema. Um cabeçalho só: a ordenação, os estilos de #tabelaPastas e o leitor de tela continuam
   valendo, e na horizontal ele rola junto com as linhas por estar no mesmo contêiner.
   ============================================================================= */
(function () {
    'use strict';

    var agendado = 0;

    // Fundo do topo do sistema (cabeçalho azul + abas), que é fixo; se um dia deixar de ser, o topo é 0.
    function topoFixo() {
        var topo = document.querySelector('.app-header');
        if (!topo) { return 0; }
        var pos = window.getComputedStyle(topo).position;
        return (pos === 'fixed' || pos === 'sticky') ? Math.max(0, topo.getBoundingClientRect().bottom) : 0;
    }

    function posicionar() {
        agendado = 0;
        var t = document.getElementById('tabelaPastas');
        var cab = t && t.tHead;
        if (!cab) { return; }
        // Tabela escondida (cartões, celular): nada a fixar.
        if (!t.offsetParent) { cab.style.transform = ''; return; }
        var r = t.getBoundingClientRect();
        var desce = Math.min(Math.max(0, topoFixo() - r.top), Math.max(0, r.height - cab.offsetHeight));
        cab.style.transform = desce > 0 ? 'translateY(' + desce + 'px)' : '';
    }

    function agendar() {
        if (!agendado) { agendado = window.requestAnimationFrame(posicionar); }
    }

    window.addEventListener('scroll', agendar, { passive: true, capture: true });
    window.addEventListener('resize', agendar);
    // A tabela chega e é trocada por AJAX (filtro, página, marcador, modo de visualização).
    new MutationObserver(agendar).observe(document.body, { childList: true, subtree: true });
    // Alternar tabela/cartões só troca classes; observar atributos entraria em laço com o próprio transform.
    document.addEventListener('click', agendar, true);
    agendar();
}());

/* =============================================================================
   E8d. Faixa de filtros, "Visualizar" e responsável no desenho 1.1 (dc L.98-128, L.159-160).

   - "Visualizar" (AUD-12): abre o menu com os dois modos que existem e o "Painel de marcadores". Quem troca
     o modo é o handler de sempre (`.js-view-toggle`, pasta/_tabela); o painel é o botão de recolher do trilho.
   - Filtro com valor (AUD-10): a caixa fica azul-clara com o texto azul (dc `onBg`/`onCor`), também o da
     situação, que mora na barra do acervo fora do <form> (por isso `form.elements`).
   - Responsável (AUD-25/26), SÓ na Lista, por decisão do dono: iniciais pelas duas primeiras palavras (dc
     `ini2`: THÂMARA THAYS → TT) e "?" no lugar do ícone quando não há responsável. O chip e o avatar são
     parciais compartilhados com a Pasta (`pasta/_resp_chip`, `_partials/_avatar_usuario`): ficam como estão,
     e o ajuste é feito aqui, nos chips do #pastasView, depois de cada desenho (servidor, AJAX ou troca).
   ============================================================================= */
(function () {
    'use strict';

    /* ── Visualizar ─────────────────────────────────────────────────────── */
    function menuVis() { return document.getElementById('expVisualizarMenu'); }
    function botaoVis() { return document.querySelector('.js-visualizar'); }
    function itensVis() {
        var m = menuVis();
        return m ? Array.prototype.slice.call(m.querySelectorAll('.exp-vis-item')) : [];
    }
    function painelAberto() {
        var s = document.getElementById('expedienteSidebar');
        return !!s && !s.classList.contains('sidebar-collapsed');
    }
    function fecharVis(devolverFoco) {
        var m = menuVis();
        var b = botaoVis();
        if (!m || m.hidden) { return; }
        m.hidden = true;
        if (b) {
            b.setAttribute('aria-expanded', 'false');
            if (devolverFoco) { b.focus(); }
        }
    }
    function abrirVis() {
        var m = menuVis();
        var b = botaoVis();
        if (!m || !b) { return; }
        var painel = m.querySelector('.js-vis-painel');
        if (painel) { painel.setAttribute('aria-checked', painelAberto() ? 'true' : 'false'); }
        m.hidden = false;
        b.setAttribute('aria-expanded', 'true');
        var marcado = m.querySelector('.js-view-toggle[aria-checked="true"]') || itensVis()[0];
        if (marcado) { marcado.focus(); }
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('.js-visualizar')) {
            var m = menuVis();
            if (m && m.hidden) { abrirVis(); } else { fecharVis(false); }
            return;
        }
        if (e.target.closest('.js-vis-painel')) {
            var recolher = document.getElementById('expedienteSidebarToggleBtn');
            if (recolher) { recolher.click(); }
            fecharVis(true);
            return;
        }
        if (e.target.closest('.exp-vis-item')) { fecharVis(true); return; }
        if (!e.target.closest('.exp-visualizar')) { fecharVis(false); }
    });
    // Botão direito (ou qualquer pressão) fora dele também fecha: senão o menu de contexto abria com o
    // Visualizar aberto e os dois disputavam as setas do teclado.
    document.addEventListener('mousedown', function (e) {
        if (!e.target.closest('.exp-visualizar')) { fecharVis(false); }
    }, true);

    document.addEventListener('keydown', function (e) {
        var m = menuVis();
        if (!m || m.hidden) { return; }
        var lista = itensVis();
        var i = lista.indexOf(document.activeElement);
        if (e.key === 'Escape') { e.preventDefault(); fecharVis(true); return; }
        if (e.key === 'Tab') { fecharVis(false); return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); lista[(i + 1) % lista.length].focus(); return; }
        if (e.key === 'ArrowUp') { e.preventDefault(); lista[(i - 1 + lista.length) % lista.length].focus(); return; }
        if (e.key === 'Home') { e.preventDefault(); lista[0].focus(); return; }
        if (e.key === 'End') { e.preventDefault(); lista[lista.length - 1].focus(); }
    });

    /* ── Filtro com valor ───────────────────────────────────────────────── */
    function marcarFiltros() {
        var form = document.getElementById('formFiltrosPasta');
        if (!form) { return; }
        Array.prototype.forEach.call(form.elements, function (el) {
            if (el.matches('.js-pasta-busca')) {
                var caixa = el.closest('.pasta-filtros-busca');
                if (caixa) { caixa.classList.toggle('is-ativo', el.value.trim() !== ''); }
            } else if (el.matches('select.js-pasta-filtro')) {
                var envolto = el.closest('.exp-filtro, .exp-situacao');
                if (envolto) {
                    envolto.classList.toggle('is-ativo', el.value !== '');
                    envolto.setAttribute('data-valor', el.value);
                }
            }
        });
        var periodo = form.querySelector('.pasta-filtros-periodo');
        if (periodo) {
            periodo.classList.toggle('is-ativo', Array.prototype.some.call(
                periodo.querySelectorAll('input[type="date"]'), function (d) { return d.value !== ''; }));
        }
    }
    document.addEventListener('input', marcarFiltros, true);
    document.addEventListener('change', marcarFiltros, true);

    /* ── Responsável na Lista ───────────────────────────────────────────── */
    function iniciaisDoDesenho(nome) {
        return nome.trim().split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0); }).join('').toUpperCase();
    }
    function ajustarResponsaveis() {
        document.querySelectorAll('#pastasView .pasta-resp-chip').forEach(function (chip) {
            var nomeEl = chip.querySelector('.pasta-resp-nome');
            var nome = chip.dataset.respId && nomeEl ? nomeEl.textContent : '';
            if (nome) {
                var ini = iniciaisDoDesenho(nome);
                var comIniciais = chip.querySelector('.resp-avatar-ini');
                if (comIniciais && comIniciais.textContent !== ini) { comIniciais.textContent = ini; }
                var comFoto = chip.querySelector('.resp-avatar-img');
                if (comFoto) { comFoto.setAttribute('data-ini', ini); }   // se a foto falhar, cai nestas iniciais
            }
            var vazio = chip.querySelector('.resp-avatar-vazio:not(.exp-resp-vazio)');
            if (vazio) {
                vazio.classList.add('exp-resp-vazio');
                vazio.textContent = '?';
            }
        });
    }

    /* Depois de cada desenho: servidor, troca por AJAX (filtro, página, marcador) e o redesenho do chip
       ao escolher um responsável (_resp_script). Só lista de filhos: as mudanças daqui não realimentam. */
    var agendado = 0;
    function aplicar() {
        agendado = 0;
        marcarFiltros();
        ajustarResponsaveis();
    }
    function agendar() {
        if (!agendado) { agendado = window.requestAnimationFrame(aplicar); }
    }
    new MutationObserver(agendar).observe(document.body, { childList: true, subtree: true });
    agendar();
}());
