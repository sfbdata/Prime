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

    function abrir(titulo, lista, x, y, quem) {
        fechar(false);
        origem = quem || document.activeElement;
        menu = document.createElement('div');
        menu.className = 'exp-ctx';
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
                it.acao();
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
            aviso.setAttribute('role', 'status');
            aviso.setAttribute('aria-live', 'polite');
            var i = document.createElement('i');
            i.className = 'bi bi-check-circle-fill';
            i.setAttribute('aria-hidden', 'true');
            aviso.appendChild(i);
            aviso.appendChild(document.createElement('span'));
            document.body.appendChild(aviso);
        }
        aviso.querySelector('span').textContent = texto;
        aviso.hidden = false;
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
        var nome = textoLimpo(linha.cells[1]);
        var excluida = linha.classList.contains('pasta-excluida');
        var chip = linha.querySelector('.pasta-resp-chip');
        var marcar = linha.querySelector('.js-mover-para');

        var lista = [
            { rotulo: 'Abrir pasta', icone: 'bi-folder2-open', acao: function () { window.location.href = href; } },
            { rotulo: 'Abrir em nova aba', icone: 'bi-box-arrow-up-right', acao: function () { window.open(href, '_blank', 'noopener'); } }
        ];
        if (!excluida && (chip || marcar)) {
            lista.push('-');
            if (chip) { lista.push({ rotulo: 'Alterar responsável', icone: 'bi-person-gear', acao: function () { chip.click(); } }); }
            if (marcar) { lista.push({ rotulo: 'Alterar marcador', icone: 'bi-tags', acao: function () { marcar.click(); } }); }
        }
        lista.push('-');
        if (nup) { lista.push({ rotulo: 'Copiar número', icone: 'bi-hash', acao: function () { copiar(nup, 'Número ' + nup + ' copiado'); } }); }
        if (nome && nome !== '—') { lista.push({ rotulo: 'Copiar nome', icone: 'bi-clipboard', acao: function () { copiar(nome, 'Nome copiado'); } }); }

        abrir('Pasta ' + nup, lista, x, y, linha);
    }

    /* ── Marcador do trilho ─────────────────────────────────────────────── */
    function menuDoMarcador(link, x, y) {
        var nome = textoLimpo(link.querySelector('.expediente-item-nome'));
        var editar = link.querySelector('.js-editar-marcador');
        var addSub = link.querySelector('.expediente-btn-add-sub');
        var novo = document.querySelector('.js-abrir-modal-marcador[data-pai-id=""]');

        var lista = [
            { rotulo: 'Ver pastas do marcador', icone: 'bi-folder2', acao: function () { link.click(); } }
        ];
        if (novo) { lista.push({ rotulo: 'Novo marcador', icone: 'bi-folder-plus', acao: function () { novo.click(); } }); }
        if (addSub) { lista.push({ rotulo: 'Novo sub-marcador', icone: 'bi-node-plus', acao: function () { addSub.click(); } }); }
        if (editar) {
            lista.push({ rotulo: 'Editar', icone: 'bi-pencil', acao: function () { editar.click(); } });
            lista.push('-');
            lista.push({ rotulo: 'Excluir marcador', icone: 'bi-trash3', perigo: true, acao: function () { excluirPeloModal(editar); } });
        }

        abrir('Marcador ' + nome, lista, x, y, link);
    }

    /* Excluir passa pelo modal Editar de sempre (mesmo token, mesma rota) e pergunta na janela da tela. */
    function excluirPeloModal(editar) {
        var modal = document.getElementById('modalEditarMarcador');
        var botao = document.getElementById('btnExcluirMarcadorModal');
        if (!modal || !botao) { editar.click(); return; }
        modal.addEventListener('shown.bs.modal', function () { botao.click(); }, { once: true });
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
        if (e.key === 'Tab') { fechar(false); return; }
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
