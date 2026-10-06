/**
 * Menu ⋮ "Opções da tabela" do Dashboard (desenho "01 - Dashboard 1.2.2", "Opções da tabela" e
 * "Personalização por usuário").
 *
 * A fonte de verdade é o SERVIDOR, nunca o navegador (sem localStorage): o estilo do usuário
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
 *   db-page--confortavel · db-page--sem-anim · db-page--sem-setas · db-oculta--<coluna>
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
    var OCULTAVEIS = ['cargo', 'metas', 'metas_ativas', 'metas_vencidas', 'prazos', 'demandas', 'demandas_ativas', 'pastas_criadas'];
    var NUMERICAS = OCULTAVEIS.slice(1);
    var PADRAO = {};
    PADRAO[CH_DENSIDADE] = 'compacta';
    PADRAO[CH_ANIMACOES] = 'ligadas';
    PADRAO[CH_SETAS] = 'ligadas';
    PADRAO[CH_COLUNAS] = [];

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

    // Só a resposta da última gravação vale (duas escolhas rápidas não se atropelam).
    var ultimaGravacao = 0;

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

    function classesDe(e) {
        var lista = [];
        if (e[CH_DENSIDADE] === 'confortavel') { lista.push('db-page--confortavel'); }
        if (e[CH_ANIMACOES] === 'reduzidas') { lista.push('db-page--sem-anim'); }
        if (e[CH_SETAS] === 'desligadas') { lista.push('db-page--sem-setas'); }
        ocultas(e).forEach(function (c) { lista.push('db-oculta--' + c); });
        return lista;
    }

    function aplicarClasses(lista) {
        Array.prototype.slice.call(pagina.classList).forEach(function (c) {
            if (/^db-page--(confortavel|sem-anim|sem-setas)$/.test(c) || c.indexOf('db-oculta--') === 0) {
                pagina.classList.remove(c);
            }
        });
        lista.forEach(function (c) { pagina.classList.add(c); });
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
            if (minha === ultimaGravacao && dados && dados.preferencias) {
                aplicar(dados.preferencias);
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
        if (alvo.hasAttribute('data-pref-mostrar-todas')) {
            mudar(CH_COLUNAS, []);
            return;
        }
        if (alvo.hasAttribute('data-pref-restaurar')) {
            var anterior = copiar(estado);
            aplicar(PADRAO);
            gravar({ restaurar: true }, anterior);
        }
    });

    // ── lugar do menu: cabeçalho da tabela, ao lado da lupa ─────────────────
    function encaixar() {
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
