/**
 * Menu ⋮ "Opções da tabela" do Dashboard (desenho "01 - Dashboard 1.2.2", "Opções da tabela" e
 * "Personalização por usuário").
 *
 * A fonte de verdade é o SERVIDOR, nunca o navegador (nada guardado no navegador): o estilo do usuário
 * logado vem em `data-preferencias` do `.db-page`, já aplicado como classe pelo Twig; cada
 * escolha é gravada pelo POST de `data-preferencias-endpoint` (CSRF no header `X-CSRF-Token`,
 * token `ajax`) e a resposta do servidor é o estado que a tela adota. Se a gravação falhar, a tela
 * volta ao estado anterior.
 *
 * O menu nasce fora do fragmento que o filtro-tabela.js troca por innerHTML; aqui ele é movido
 * para o `.db-table-header` (ao lado da lupa) e recolocado a cada recarga — o MESMO nó, então o
 * estado e os ouvintes sobrevivem. As classes moram no `.db-page`, que não é trocado.
 *
 * Classes (as mesmas de PreferenciasDoDashboardOutput::classesCss()):
 *   db-page--confortavel · db-page--sem-anim · db-page--sem-setas · db-page--sem-som ·
 *   db-oculta--<coluna>
 *
 * `db-page--sem-som` é lido pelo dashboard-filtros.js na hora de tocar o som do calendário: a
 * escolha vale na hora, sem recarregar.
 *
 * Colunas extras ("Adicionar coluna", `dashboard.colunas_extras`): não viram classe — o SERVIDOR
 * calcula a coluna. Depois que o servidor confirma a gravação e a lista mudou em relação ao que
 * está na tabela, o fragmento é recarregado pelo mesmo XHR do filtro (submit do form da casca).
 * "+" acrescenta no fim; desmarcar a "(extra)" em Colunas a remove.
 *
 * Modo avançado do painel BlueJus Intelligence (`dashboard.ia_avancado`, dc L872): o interruptor
 * `[data-ia-avancado-switch]` mora no painel, DENTRO do fragmento que o XHR troca — por isso o
 * clique é ouvido no `.db-page` (delegação) e o aria-checked é reposto a cada recarga. Grava pelo
 * mesmo endpoint/CSRF; a classe `db-page--ia-avancado` mostra o bloco avançado e leva o painel a
 * 600px. "Restaurar padrão" da tabela não mexe nele (no desenho é outra chave).
 *
 * Ligar na PRIMEIRA vez (sem escolha gravada: `data-ia-avancado-escolhido="nao"` no `.db-page`)
 * abre a confirmação "Ativar Modo Avançado?" (`[data-ia-confirma]`, dc L939-959); depois de
 * qualquer gravação dessa chave, liga direto. Desligar é sempre imediato. O cartão "Conhecer o
 * Modo avançado" (`[data-ia-teaser]`) aparece por 6s cada vez que o painel abre com o modo
 * desligado e sai com fade (dc L2586-2595: 6000ms marca a saída, 6600ms some).
 */
(function () {
    'use strict';

    var pagina = document.querySelector('.db-page[data-preferencias]');
    var pref = document.querySelector('[data-db-pref]');
    if (!pagina || !pref) {
        return;
    }

    var CH_DENSIDADE = 'dashboard.densidade';
    var CH_ANIMACOES = 'dashboard.animacoes';
    var CH_SETAS = 'dashboard.setas';
    var CH_COLUNAS = 'dashboard.colunas_ocultas';
    var CH_SONS = 'dashboard.sons';
    var CH_EXTRAS = 'dashboard.colunas_extras';
    var CH_IA = 'dashboard.ia_avancado';
    var OCULTAVEIS = ['cargo', 'metas', 'metas_ativas', 'metas_vencidas', 'prazos', 'demandas', 'demandas_ativas', 'pastas_criadas'];
    var NUMERICAS = OCULTAVEIS.slice(1);
    var PADRAO = {};
    PADRAO[CH_DENSIDADE] = 'compacta';
    PADRAO[CH_ANIMACOES] = 'ligadas';
    PADRAO[CH_SETAS] = 'ligadas';
    PADRAO[CH_COLUNAS] = [];
    PADRAO[CH_SONS] = 'ligados';
    PADRAO[CH_EXTRAS] = [];
    PADRAO[CH_IA] = 'desligado';

    var endpoint = pagina.getAttribute('data-preferencias-endpoint');
    var botao = pref.querySelector('.db-pref-btn');
    var menu = pref.querySelector('.db-pref-menu');
    var estado = copiar(PADRAO);
    try {
        var lido = JSON.parse(pagina.getAttribute('data-preferencias') || '{}');
        if (lido && typeof lido === 'object') {
            Object.keys(PADRAO).forEach(function (k) {
                if (Object.prototype.hasOwnProperty.call(lido, k)) {
                    estado[k] = lido[k];
                }
            });
        }
    } catch (e) { /* sem estado legível: fica o padrão */ }

    // O usuário já gravou o Modo avançado? Sem escolha, ligar pede a confirmação.
    var iaEscolhido = pagina.getAttribute('data-ia-avancado-escolhido') === 'sim';

    // Só a resposta da última gravação vale (duas escolhas rápidas não se atropelam).
    var ultimaGravacao = 0;

    // Extras que a tabela na tela já traz (as do HTML do servidor). Quando o servidor confirma
    // uma lista diferente, o fragmento é recarregado para a coluna aparecer/sumir.
    var extrasNaTela = extras(estado).slice();

    function copiar(e) {
        var c = {};
        Object.keys(e).forEach(function (k) {
            c[k] = Array.isArray(e[k]) ? e[k].slice() : e[k];
        });
        return c;
    }

    function ocultas(e) {
        return Array.isArray(e[CH_COLUNAS]) ? e[CH_COLUNAS] : [];
    }

    function extras(e) {
        return Array.isArray(e[CH_EXTRAS]) ? e[CH_EXTRAS] : [];
    }

    function mesmaLista(a, b) {
        return a.length === b.length && a.every(function (v, i) { return v === b[i]; });
    }

    // Recarrega o fragmento com o filtro atual (o filtro-tabela.js intercepta o submit e troca
    // [data-filtro-resultado] por XHR). Sem o form, recarrega a página.
    function recarregarTabela() {
        var form = document.querySelector('[data-filtro-root] [data-filtro-form]');
        if (!form) {
            window.location.reload();
            return;
        }
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }

    function classesDe(e) {
        var lista = [];
        if (e[CH_DENSIDADE] === 'confortavel') { lista.push('db-page--confortavel'); }
        if (e[CH_ANIMACOES] === 'reduzidas') { lista.push('db-page--sem-anim'); }
        if (e[CH_SETAS] === 'desligadas') { lista.push('db-page--sem-setas'); }
        if (e[CH_SONS] === 'desligados') { lista.push('db-page--sem-som'); }
        if (e[CH_IA] === 'ligado') { lista.push('db-page--ia-avancado'); }
        ocultas(e).forEach(function (c) { lista.push('db-oculta--' + c); });
        return lista;
    }

    function aplicarClasses(lista) {
        Array.prototype.slice.call(pagina.classList).forEach(function (c) {
            if (/^db-page--(confortavel|sem-anim|sem-setas|sem-som|ia-avancado)$/.test(c) || c.indexOf('db-oculta--') === 0) {
                pagina.classList.remove(c);
            }
        });
        lista.forEach(function (c) { pagina.classList.add(c); });
        // "Animações" vale para o sistema inteiro (app.css, `html.jp-sem-anim`): aplica já, sem recarregar.
        document.documentElement.classList.toggle('jp-sem-anim', lista.indexOf('db-page--sem-anim') !== -1);
    }

    function sincronizarMenu() {
        menu.querySelectorAll('[data-pref-valor]').forEach(function (b) {
            var chave = b.getAttribute('data-pref-chave');
            b.setAttribute('aria-checked', estado[chave] === b.getAttribute('data-pref-valor') ? 'true' : 'false');
        });
        menu.querySelectorAll('[data-pref-liga]').forEach(function (b) {
            var chave = b.getAttribute('data-pref-chave');
            b.setAttribute('aria-checked', estado[chave] === b.getAttribute('data-pref-liga') ? 'true' : 'false');
        });
        var oc = ocultas(estado);
        var numVisiveis = NUMERICAS.filter(function (c) { return oc.indexOf(c) === -1; }).length;
        menu.querySelectorAll('[data-pref-coluna]').forEach(function (b) {
            var col = b.getAttribute('data-pref-coluna');
            var visivel = oc.indexOf(col) === -1;
            b.setAttribute('aria-checked', visivel ? 'true' : 'false');
            if (visivel && col !== 'cargo' && numVisiveis === 1) {
                b.setAttribute('aria-disabled', 'true');
            } else {
                b.removeAttribute('aria-disabled');
            }
        });
        var mostrar = menu.querySelector('[data-pref-mostrar-todas]');
        if (mostrar) {
            mostrar.hidden = oc.length === 0;
        }

        // Extras: as ligadas aparecem em Colunas como "(extra)", na ordem da tabela; as outras
        // ficam em "Adicionar coluna".
        var ex = extras(estado);
        var caixaAtivas = menu.querySelector('[data-pref-extras-ativas]');
        if (caixaAtivas) {
            ex.forEach(function (k) {
                var b = caixaAtivas.querySelector('[data-pref-extra-remover="' + k + '"]');
                if (b) { caixaAtivas.appendChild(b); }
            });
            caixaAtivas.querySelectorAll('[data-pref-extra-remover]').forEach(function (b) {
                b.hidden = ex.indexOf(b.getAttribute('data-pref-extra-remover')) === -1;
            });
        }
        var disponiveis = 0;
        menu.querySelectorAll('[data-pref-extra-adicionar]').forEach(function (b) {
            b.hidden = ex.indexOf(b.getAttribute('data-pref-extra-adicionar')) !== -1;
            if (!b.hidden) { disponiveis++; }
        });
        var todas = menu.querySelector('[data-pref-extras-todas]');
        if (todas) {
            todas.hidden = disponiveis > 0;
        }

        sincronizarIa();
    }

    // Interruptor do Modo avançado: pode ter sido recriado pelo XHR, então é procurado de novo.
    function sincronizarIa() {
        var ligado = estado[CH_IA] === 'ligado';
        pagina.querySelectorAll('[data-ia-avancado-switch]').forEach(function (b) {
            b.setAttribute('aria-checked', ligado ? 'true' : 'false');
        });
        if (ligado) {
            esconderTeaser();
        }
    }

    function aplicar(e) {
        estado = copiar(e);
        aplicarClasses(classesDe(estado));
        sincronizarMenu();
    }

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function gravar(corpo, anterior) {
        var minha = ++ultimaGravacao;
        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token() },
            body: JSON.stringify(corpo)
        }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.json();
        }).then(function (dados) {
            if (corpo.chave === CH_IA) {
                iaEscolhido = true;
            }
            if (minha === ultimaGravacao && dados && dados.preferencias) {
                aplicar(dados.preferencias);
                var confirmadas = extras(estado);
                if (!mesmaLista(confirmadas, extrasNaTela)) {
                    extrasNaTela = confirmadas.slice();
                    recarregarTabela();
                }
            }
        }).catch(function () {
            if (minha === ultimaGravacao) {
                aplicar(anterior);
            }
        });
    }

    function mudar(chave, valor) {
        var anterior = copiar(estado);
        var novo = copiar(estado);
        novo[chave] = valor;
        aplicar(novo);
        gravar({ chave: chave, valor: valor }, anterior);
    }

    // ── abrir / fechar ───────────────────────────────────────────────────────
    function itens() {
        return Array.prototype.slice.call(menu.querySelectorAll('button:not([hidden])'));
    }

    function aberto() {
        return !menu.hidden;
    }

    function abrir() {
        menu.hidden = false;
        botao.setAttribute('aria-expanded', 'true');
        pref.classList.add('is-aberto');
        var primeiro = itens()[0];
        if (primeiro) { primeiro.focus(); }
    }

    function fechar(devolverFoco) {
        if (!aberto()) { return; }
        menu.hidden = true;
        botao.setAttribute('aria-expanded', 'false');
        pref.classList.remove('is-aberto');
        if (devolverFoco) { botao.focus(); }
    }

    botao.addEventListener('click', function () {
        if (aberto()) { fechar(false); } else { abrir(); }
    });

    document.addEventListener('mousedown', function (ev) {
        if (aberto() && !pref.contains(ev.target)) { fechar(false); }
    });

    document.addEventListener('keydown', function (ev) {
        if (!aberto()) { return; }
        if (ev.key === 'Escape') {
            ev.preventDefault();
            fechar(true);
            return;
        }
        if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
            var lista = itens();
            var i = lista.indexOf(document.activeElement);
            var prox = ev.key === 'ArrowDown' ? (i + 1) % lista.length : (i - 1 + lista.length) % lista.length;
            if (lista[prox]) {
                ev.preventDefault();
                lista[prox].focus();
            }
        }
    });

    // ── escolhas ─────────────────────────────────────────────────────────────
    menu.addEventListener('click', function (ev) {
        var alvo = ev.target.closest('button');
        if (!alvo || !menu.contains(alvo)) { return; }

        if (alvo.hasAttribute('data-pref-valor')) {
            var chaveSeg = alvo.getAttribute('data-pref-chave');
            var valorSeg = alvo.getAttribute('data-pref-valor');
            if (estado[chaveSeg] !== valorSeg) { mudar(chaveSeg, valorSeg); }
            return;
        }
        if (alvo.hasAttribute('data-pref-liga')) {
            var chaveSw = alvo.getAttribute('data-pref-chave');
            var liga = alvo.getAttribute('data-pref-liga');
            mudar(chaveSw, estado[chaveSw] === liga ? alvo.getAttribute('data-pref-desliga') : liga);
            return;
        }
        if (alvo.hasAttribute('data-pref-coluna')) {
            if (alvo.getAttribute('aria-disabled') === 'true') { return; }
            var col = alvo.getAttribute('data-pref-coluna');
            var oc = ocultas(estado);
            var nova = oc.indexOf(col) === -1 ? oc.concat([col]) : oc.filter(function (c) { return c !== col; });
            // mesma ordem que o servidor grava (a da tabela)
            mudar(CH_COLUNAS, OCULTAVEIS.filter(function (c) { return nova.indexOf(c) !== -1; }));
            return;
        }
        if (alvo.hasAttribute('data-pref-extra-adicionar')) {
            var novaExtra = alvo.getAttribute('data-pref-extra-adicionar');
            var atuais = extras(estado);
            // "+" acrescenta no fim da tabela (desenho: extras.concat(k)).
            if (atuais.indexOf(novaExtra) === -1) { mudar(CH_EXTRAS, atuais.concat([novaExtra])); }
            return;
        }
        if (alvo.hasAttribute('data-pref-extra-remover')) {
            var sai = alvo.getAttribute('data-pref-extra-remover');
            mudar(CH_EXTRAS, extras(estado).filter(function (k) { return k !== sai; }));
            return;
        }
        if (alvo.hasAttribute('data-pref-mostrar-todas')) {
            mudar(CH_COLUNAS, []);
            return;
        }
        if (alvo.hasAttribute('data-pref-restaurar')) {
            var anterior = copiar(estado);
            // o Modo avançado não é opção da tabela: o servidor não o apaga, a tela também não
            var padrao = copiar(PADRAO);
            padrao[CH_IA] = estado[CH_IA];
            aplicar(padrao);
            gravar({ restaurar: true }, anterior);
        }
    });

    // ── Modo avançado do painel Intelligence ────────────────────────────────
    var TEASER_SAI = 6000;
    var TEASER_SOME = 6600;
    var teaserTimers = [];

    function confirmaAberta() {
        var c = pagina.querySelector('[data-ia-confirma]');
        return c && !c.hidden ? c : null;
    }

    function abrirConfirma() {
        var c = pagina.querySelector('[data-ia-confirma]');
        if (!c) {
            mudar(CH_IA, 'ligado');
            return;
        }
        c.hidden = false;
        var ativar = c.querySelector('[data-ia-confirma-ativar]');
        if (ativar) { ativar.focus(); }
    }

    function fecharConfirma(devolverFoco) {
        var c = confirmaAberta();
        if (!c) { return; }
        c.hidden = true;
        if (devolverFoco) {
            var sw = pagina.querySelector('[data-ia-avancado-switch]');
            if (sw) { sw.focus(); }
        }
    }

    function limparTeaser() {
        teaserTimers.forEach(clearTimeout);
        teaserTimers = [];
    }

    function esconderTeaser() {
        limparTeaser();
        pagina.querySelectorAll('[data-ia-teaser]').forEach(function (t) {
            t.hidden = true;
            t.classList.remove('is-saindo');
        });
    }

    // Cada abertura do painel com o modo desligado: 6s visível, depois fade e some.
    function mostrarTeaser() {
        esconderTeaser();
        if (estado[CH_IA] === 'ligado') { return; }
        var t = pagina.querySelector('[data-ia-teaser]');
        if (!t) { return; }
        t.hidden = false;
        teaserTimers.push(setTimeout(function () { t.classList.add('is-saindo'); }, TEASER_SAI));
        teaserTimers.push(setTimeout(function () {
            t.hidden = true;
            t.classList.remove('is-saindo');
        }, TEASER_SOME));
    }

    function ligarIa() {
        if (!iaEscolhido) {
            abrirConfirma();
            return;
        }
        mudar(CH_IA, 'ligado');
    }

    // Desligar é imediato (dc L2581); ligar passa por ligarIa().
    function alternarIa() {
        if (estado[CH_IA] === 'ligado') {
            mudar(CH_IA, 'desligado');
            return;
        }
        ligarIa();
    }

    pagina.addEventListener('click', function (ev) {
        var alvo = ev.target.closest('[data-ia-avancado-switch], [data-ia-teaser], [data-ia-confirma-ativar], [data-ia-confirma-cancelar]');
        if (!alvo || !pagina.contains(alvo)) { return; }

        if (alvo.hasAttribute('data-ia-confirma-ativar')) {
            fecharConfirma(true);
            mudar(CH_IA, 'ligado');
            return;
        }
        if (alvo.hasAttribute('data-ia-confirma-cancelar')) {
            fecharConfirma(true);
            return;
        }
        // interruptor do topo ou cartão do rodapé: o mesmo comando (dc: av.alternar)
        alternarIa();
    });

    // O painel é um [popover] dentro do fragmento trocado: o `toggle` não borbulha, então é
    // ouvido na captura. Abrir mostra o cartão; fechar limpa cartão e confirmação.
    document.addEventListener('toggle', function (ev) {
        if (!ev.target || ev.target.id !== 'dbIaPainel') { return; }
        if (ev.newState === 'open') {
            mostrarTeaser();
        } else {
            esconderTeaser();
            fecharConfirma(false);
        }
    }, true);

    // Esc com a confirmação aberta fecha só a confirmação; Tab fica entre os dois botões.
    document.addEventListener('keydown', function (ev) {
        var c = confirmaAberta();
        if (!c) { return; }
        if (ev.key === 'Escape') {
            ev.preventDefault();
            ev.stopPropagation();
            fecharConfirma(true);
            return;
        }
        if (ev.key === 'Tab') {
            var botoes = Array.prototype.slice.call(c.querySelectorAll('button'));
            if (!botoes.length) { return; }
            var i = botoes.indexOf(document.activeElement);
            var prox = ev.shiftKey ? (i <= 0 ? botoes.length - 1 : i - 1) : (i === -1 || i === botoes.length - 1 ? 0 : i + 1);
            ev.preventDefault();
            botoes[prox].focus();
        }
    }, true);

    // ── lugar do menu: cabeçalho da tabela, ao lado da lupa ─────────────────
    function encaixar() {
        sincronizarIa();
        var cabecalho = document.querySelector('[data-filtro-resultado] .db-table-header');
        if (!cabecalho) { return; }
        if (pref.parentNode !== cabecalho) {
            cabecalho.appendChild(pref);
        }
        pref.hidden = false;
    }

    var regiao = document.querySelector('[data-filtro-resultado]');
    if (regiao && window.MutationObserver) {
        new MutationObserver(encaixar).observe(regiao, { childList: true });
    }

    sincronizarMenu();
    encaixar();
})();
