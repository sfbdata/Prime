/**
 * Esteira da pasta em Minhas Metas (desenho 04 de 22/10, dc L61 e L133).
 *
 * Só carrega para quem configura as caixas. Abre o modal "Configurar caixa" pelo ⋮, pelo clique direito ou
 * pelo duplo clique no "CAIXA N"; o clique simples no rótulo continua filtrando a etapa, como o
 * resto da caixa. O modal é um POST comum (sem fetch): o servidor grava e volta com o aviso.
 * A janela é a do desenho (dc L133), não o modal do Bootstrap: abre tirando o `hidden` e fecha no fundo, no X, no
 * Cancelar e no Esc.
 *
 * Nome igual ao padrão sai vazio, para a caixa voltar a ser a padrão em vez de gravar o texto do catálogo.
 * Os avisados vão como `avisados[]`; o @ sugere membros ativos da equipe (no máximo 6, como o protótipo).
 */
(function () {
    'use strict';

    var modalEl = document.querySelector('[data-mm-cx-modal]');
    if (!modalEl) { return; }

    var form      = modalEl.querySelector('[data-mm-cx-form]');
    var campoCx   = modalEl.querySelector('[data-mm-cx-caixa]');
    var campoEt   = modalEl.querySelector('[data-mm-cx-etapa]');
    var chips     = modalEl.querySelector('[data-mm-cx-chips]');
    var busca     = modalEl.querySelector('[data-mm-cx-busca]');
    var lista     = modalEl.querySelector('[data-mm-cx-sugestoes]');
    var restaurar = modalEl.querySelector('[data-mm-cx-restaurar]');
    var membros   = lerJson(modalEl.getAttribute('data-membros'));
    var rotuloRemover = modalEl.getAttribute('data-rotulo-remover') || '';
    var caixaAtual = null;
    var marcados  = [];

    function lerJson(texto) {
        try { return JSON.parse(texto || '[]') || []; } catch (e) { return []; }
    }

    function membro(id) {
        for (var i = 0; i < membros.length; i++) {
            if (membros[i].id === id) { return membros[i]; }
        }

        return null;
    }

    function desenharChips() {
        chips.querySelectorAll('.mm-cx-chip').forEach(function (c) { c.remove(); });
        marcados.forEach(function (id) {
            var m = membro(id);
            if (!m) { return; }
            var chip = document.createElement('span');
            chip.className = 'mm-cx-chip';
            chip.textContent = '@' + m.nome;
            var tirar = document.createElement('button');
            tirar.type = 'button';
            tirar.setAttribute('aria-label', rotuloRemover.replace('__nome__', m.nome));
            tirar.innerHTML = '<i class="bi bi-x" aria-hidden="true"></i>';
            tirar.addEventListener('click', function () {
                marcados = marcados.filter(function (x) { return x !== id; });
                desenharChips();
                busca.focus();
            });
            chip.appendChild(tirar);
            var oculto = document.createElement('input');
            oculto.type = 'hidden';
            oculto.name = 'avisados[]';
            oculto.value = String(id);
            chip.appendChild(oculto);
            chips.insertBefore(chip, busca);
        });
    }

    function desenharSugestoes() {
        var termo = busca.value.replace(/^@/, '').trim().toLowerCase();
        var mostrar = busca.value.trim() !== '';
        var achados = membros.filter(function (m) {
            return marcados.indexOf(m.id) === -1 && (!termo || m.nome.toLowerCase().indexOf(termo) !== -1);
        }).slice(0, 6);
        lista.innerHTML = '';
        lista.hidden = !mostrar || achados.length === 0;
        achados.forEach(function (m) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'mm-cx-sugestao';
            var nome = document.createElement('b');
            nome.textContent = m.nome;
            var funcao = document.createElement('span');
            funcao.textContent = m.funcao || '';
            item.appendChild(nome);
            item.appendChild(funcao);
            item.addEventListener('click', function () {
                marcados.push(m.id);
                busca.value = '';
                desenharChips();
                desenharSugestoes();
                busca.focus();
            });
            lista.appendChild(item);
        });
    }

    function abrir(caixa) {
        caixaAtual = caixa;
        form.action = caixa.getAttribute('data-salvar');
        restaurar.setAttribute('formaction', caixa.getAttribute('data-restaurar'));
        form.querySelector('input[name="_token"]').value = caixa.getAttribute('data-token');
        campoCx.value = caixa.getAttribute('data-caixa');
        campoEt.value = caixa.getAttribute('data-nome');
        marcados = lerJson(caixa.getAttribute('data-avisados'));
        busca.value = '';
        desenharChips();
        desenharSugestoes();
        modalEl.hidden = false;
        campoCx.focus();
    }

    function fechar() {
        modalEl.hidden = true;
        caixaAtual = null;
    }

    modalEl.addEventListener('click', function (e) {
        if (e.target.closest('[data-mm-cx-fechar]')) { fechar(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modalEl.hidden) { fechar(); }
    });

    // Nome igual ao padrão vai vazio: a caixa continua a padrão.
    form.addEventListener('submit', function () {
        if (!caixaAtual) { return; }
        if (campoCx.value.trim() === caixaAtual.getAttribute('data-caixa-padrao')) { campoCx.value = ''; }
        if (campoEt.value.trim() === caixaAtual.getAttribute('data-nome-padrao')) { campoEt.value = ''; }
    });

    busca.addEventListener('input', desenharSugestoes);
    busca.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            var primeira = lista.querySelector('.mm-cx-sugestao');
            if (primeira && !lista.hidden) { primeira.click(); }
        }
        if (e.key === 'Backspace' && busca.value === '' && marcados.length) {
            marcados.pop();
            desenharChips();
        }
    });

    document.querySelectorAll('[data-mm-caixa]').forEach(function (caixa) {
        var menu   = caixa.querySelector('[data-mm-caixa-abrir]');
        var rotulo = caixa.querySelector('[data-mm-caixa-rotulo]');
        var link   = caixa.querySelector('.mm-caixa-link');
        var espera = null;

        if (menu) { menu.addEventListener('click', function () { abrir(caixa); }); }
        if (!rotulo || !link) { return; }
        // Clique direito só no "CAIXA N" (dc L61): na caixa, o menu do navegador (abrir em nova aba) continua.
        rotulo.addEventListener('contextmenu', function (e) { e.preventDefault(); abrir(caixa); });
        // Clique simples no rótulo filtra (depois de uma pausa curta); o duplo abre o modal.
        rotulo.addEventListener('click', function () {
            clearTimeout(espera);
            espera = setTimeout(function () { window.location.href = link.href; }, 250);
        });
        rotulo.addEventListener('dblclick', function () {
            clearTimeout(espera);
            abrir(caixa);
        });
    });
}());
