/* ============================================================================
   Janela "Imprimir" da pasta — desenho 02 - EXPEDIENTES 1.2.3 (`imp`, `impVals`,
   `impMontar`) e `BJImprimir` (bj-visualizar.js). Marcação: _impressao_modal.

   - A folha vem do SERVIDOR: `pasta_resumo_imprimir?fragmento=1&secao=…` (os
     guardas de escritório e de permissão são os da tela). Ela entra na prévia por
     DOMParser + importNode — nenhum innerHTML com dado, nenhum <script> sai dela.
   - Registro e processo: o servidor dá a moldura (`secao=moldura`) e a tela põe o
     título, a linha autor · data · tipo (textContent) e o corpo (nós clonados do
     que já está na tela, sanitizado pelo servidor).
   - "Incluir na folha" e "Tamanho do texto" mudam a prévia na hora (classes
     `rp-sem-<parte>` e o font-size do corpo), sem ir ao servidor.
   - Imprimir: iframe OCULTO com `sandbox` SEM permissão de script (nada roda lá
     dentro) e CSP fechada; a folha entra por DOM. Funciona com pop-up bloqueado.
   - Copiar texto: o texto da folha como se lê (innerText).

   API para os outros scripts da pasta: window.PastaImpressao.
   ============================================================================ */
(function () {
    'use strict';

    if (window.PastaImpressao) { return; }

    var FONTE_MIN = 9;
    var FONTE_MAX = 16;
    var FONTE_PADRAO = 11.5;

    /* O iframe da impressão: sem permissão de script. allow-same-origin deixa a tela
       preenchê-lo por DOM e chamar print(); allow-modals impede o navegador de
       ignorar o print() de um documento sandbox (mesma escolha do visor). */
    var SANDBOX_IMPRESSAO = 'allow-same-origin allow-modals';
    var CSP_IMPRESSAO = "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'";
    var CSS_IMPRESSAO = '@page{size:A4;margin:0}html,body{margin:0;background:#fff}'
        + '.rp-folha{width:auto;min-height:0;margin:0;box-shadow:none}';

    var PARTES = ['cabecalho', 'dados', 'autor', 'financeiro'];

    var raiz = null;
    var estado = null; // { secao, titulo, opcoes, fonte, folha, estilo, gatilho }
    var pedido = 0;    // descarta resposta atrasada de uma abertura anterior
    var imprimindo = false;

    function el(id) { return document.getElementById(id); }

    function iniciar() {
        raiz = el('psImpressao');
        return !!raiz;
    }

    /* ── Aviso curto (`gmAviso`): a caixa `.ps-l7-aviso` que a tela já usa ── */
    var avisoTimer = null;
    function avisar(texto) {
        var aviso = document.querySelector('.ps-l7-aviso');
        if (!aviso) {
            aviso = document.createElement('div');
            aviso.className = 'ps-l7-aviso';
            aviso.setAttribute('role', 'status');
            aviso.setAttribute('aria-live', 'polite');
            document.body.appendChild(aviso);
        }
        aviso.textContent = texto;
        aviso.classList.add('is-visivel');
        clearTimeout(avisoTimer);
        avisoTimer = setTimeout(function () { aviso.classList.remove('is-visivel'); }, 1800);
    }

    function palco() { return el('psImpressaoPalco'); }

    function mensagemNoPalco(texto) {
        var p = palco();
        var msg = document.createElement('div');
        msg.className = 'psi-palco-aviso';
        msg.textContent = texto;
        p.replaceChildren(msg);
    }

    function rotuloFonte(n) { return String(n).replace('.', ',') + ' pt'; }

    /* ── Estado da janela → prévia ─────────────────────────────────────── */
    function aplicar() {
        if (!estado) { return; }
        PARTES.forEach(function (parte) {
            var botao = raiz.querySelector('.js-psi-opcao[data-opcao="' + parte + '"]');
            var ligado = !!estado.opcoes[parte];
            if (botao) {
                botao.setAttribute('aria-pressed', ligado ? 'true' : 'false');
                var icone = botao.querySelector('i');
                if (icone) { icone.className = 'bi ' + (ligado ? 'bi-check-square-fill' : 'bi-square'); }
            }
            if (estado.folha) { estado.folha.classList.toggle('rp-sem-' + parte, !ligado); }
        });
        el('psImpressaoFonte').textContent = rotuloFonte(estado.fonte);
        var corpo = estado.folha ? estado.folha.querySelector('[data-rp-parte="corpo"]') : null;
        if (corpo) { corpo.style.fontSize = estado.fonte + 'pt'; }
    }

    function prontos(sim) {
        raiz.querySelectorAll('.js-psi-imprimir, .js-psi-copiar').forEach(function (b) {
            b.disabled = !sim;
        });
    }

    /* ── Abrir / fechar ───────────────────────────────────────────────── */
    function urlDaFolha(secao) {
        var u = new URL(raiz.getAttribute('data-url-folha'), window.location.href);
        u.searchParams.set('fragmento', '1');
        u.searchParams.set('secao', secao);
        // A prévia pede TODAS as partes; a janela esconde as desligadas por classe.
        PARTES.forEach(function (parte) { u.searchParams.set(parte, '1'); });

        return u.toString();
    }

    function fechar() {
        if (!estado) { return; }
        var gatilho = estado.gatilho;
        pedido++;
        estado = null;
        raiz.hidden = true;
        palco().replaceChildren();
        document.removeEventListener('keydown', teclaEsc, true);
        if (gatilho && typeof gatilho.focus === 'function' && document.contains(gatilho)) { gatilho.focus(); }
    }

    function teclaEsc(e) {
        if (e.key === 'Escape' && estado) {
            e.preventDefault();
            e.stopPropagation();
            fechar();
        }
    }

    /**
     * @param {{secao: string, titulo?: string, autor?: string, corpo?: Node}} cfg
     *        `secao`: resumo | moldura. Na moldura, `titulo`, `autor`
     *        (texto) e `corpo` (nó já clonado) vêm da tela.
     */
    function abrir(cfg) {
        if (!raiz && !iniciar()) { return false; }
        document.dispatchEvent(new CustomEvent('ps:fechar-popovers'));

        var meu = ++pedido;
        estado = {
            secao: cfg.secao,
            titulo: cfg.titulo || '',
            // Padrões do desenho (`gmImprimir`): cabeçalho, dados e autor ligados; financeiro não.
            opcoes: { cabecalho: true, dados: true, autor: true, financeiro: false },
            fonte: FONTE_PADRAO,
            folha: null,
            estilo: null,
            gatilho: document.activeElement,
        };

        el('psImpressaoTitulo').textContent = 'Imprimir' + (estado.titulo ? ' · ' + estado.titulo : '');
        raiz.querySelector('.js-psi-opcao[data-opcao="financeiro"]').hidden = true;
        mensagemNoPalco('Montando a folha…');
        prontos(false);
        aplicar();
        raiz.hidden = false;
        document.addEventListener('keydown', teclaEsc, true);
        raiz.querySelector('.js-psi-fechar').focus();

        fetch(urlDaFolha(cfg.secao), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
        }).then(function (resp) {
            if (!resp.ok) { throw new Error(String(resp.status)); }

            return resp.text();
        }).then(function (texto) {
            if (meu !== pedido || !estado) { return; }
            montar(texto, cfg);
        }).catch(function () {
            if (meu !== pedido || !estado) { return; }
            mensagemNoPalco('Não foi possível montar a folha. Feche e tente de novo.');
        });

        return true;
    }

    /* A resposta do servidor (Twig autoescapado) vira nós por DOMParser — que não
       executa script — e entra na prévia por importNode. */
    function montar(texto, cfg) {
        var doc = new DOMParser().parseFromString(texto, 'text/html');
        var documento = doc.querySelector('.rp-documento');
        var folha = documento ? documento.querySelector('main.rp-folha') : null;
        var estilo = documento ? documento.querySelector('style') : null;
        if (!folha) {
            mensagemNoPalco('Não foi possível montar a folha. Feche e tente de novo.');
            return;
        }
        doc.querySelectorAll('script').forEach(function (s) { s.remove(); });

        var novoEstilo = estilo ? document.importNode(estilo, true) : null;
        var novaFolha = document.importNode(folha, true);

        if (cfg.secao === 'moldura') {
            var titulo = novaFolha.querySelector('[data-rp-parte="titulo"]');
            if (titulo) { titulo.textContent = cfg.titulo || ''; }
            var autor = novaFolha.querySelector('[data-rp-parte="autor"]');
            if (autor) { autor.textContent = cfg.autor || ''; }
            var corpo = novaFolha.querySelector('[data-rp-parte="corpo"]');
            if (corpo && cfg.corpo) { corpo.replaceChildren(cfg.corpo); }
        } else if (!estado.titulo) {
            estado.titulo = novaFolha.getAttribute('data-rp-titulo') || '';
            el('psImpressaoTitulo').textContent = 'Imprimir' + (estado.titulo ? ' · ' + estado.titulo : '');
        }

        var p = palco();
        p.replaceChildren();
        if (novoEstilo) { p.appendChild(novoEstilo); }
        p.appendChild(novaFolha);
        p.scrollTop = 0;

        estado.folha = novaFolha;
        estado.estilo = novoEstilo;
        // O Financeiro só vem para quem o vê; sem ele, a opção nem aparece.
        raiz.querySelector('.js-psi-opcao[data-opcao="financeiro"]').hidden = !novaFolha.querySelector('[data-rp-parte="financeiro"]');
        aplicar();
        prontos(true);
    }

    /* ── Imprimir por iframe oculto ───────────────────────────────────── */
    /* Cópia da folha só com o que está ligado: a parte desligada sai do nó. */
    function copiaParaImprimir() {
        var copia = estado.folha.cloneNode(true);
        PARTES.forEach(function (parte) {
            if (!estado.opcoes[parte]) {
                copia.querySelectorAll('[data-rp-parte="' + parte + '"]').forEach(function (n) { n.remove(); });
            }
        });
        var autor = copia.querySelector('[data-rp-parte="autor"]');
        if (autor && autor.textContent.trim() === '') { autor.remove(); }

        return copia;
    }

    function imprimir() {
        if (!estado || !estado.folha) { return; }
        if (imprimindo) { avisar('Preparando a impressão…'); return; }
        imprimindo = true;
        setTimeout(function () { imprimindo = false; }, 3000);

        var copia = copiaParaImprimir();
        var estilo = estado.estilo ? estado.estilo.cloneNode(true) : null;
        var titulo = estado.titulo || 'BlueJus';

        var fr = document.createElement('iframe');
        fr.className = 'psi-iframe-impressao';
        fr.setAttribute('aria-hidden', 'true');
        fr.setAttribute('sandbox', SANDBOX_IMPRESSAO);
        fr.tabIndex = -1;
        fr.title = 'Impressão';

        var removido = false;
        function remover() {
            if (removido) { return; }
            removido = true;
            fr.remove();
        }

        fr.addEventListener('load', function () {
            var d = fr.contentDocument;
            if (!d || !d.body) { remover(); avisar('Não foi possível imprimir.'); return; }
            d.title = titulo;
            if (estilo) { d.head.appendChild(d.importNode(estilo, true)); }
            d.body.appendChild(d.importNode(copia, true));
            esperarImagens(d).then(function () {
                setTimeout(function () {
                    try {
                        var w = fr.contentWindow;
                        w.addEventListener('afterprint', function () { setTimeout(remover, 100); });
                        w.focus();
                        w.print();
                    } catch (e) {
                        avisar('Não foi possível imprimir.');
                        remover();
                        return;
                    }
                    setTimeout(remover, 60000);
                }, 150);
            });
        }, { once: true });

        // Só constantes nesta string: a CSP, o charset e o CSS da página de papel.
        fr.srcdoc = '<!doctype html><html lang="pt-BR"><head>'
            + '<meta http-equiv="Content-Security-Policy" content="' + CSP_IMPRESSAO + '">'
            + '<meta charset="utf-8"><style>' + CSS_IMPRESSAO + '</style></head><body></body></html>';
        document.body.appendChild(fr);
    }

    /* O logo do cabeçalho: espera carregar, com teto — sem script no documento. */
    function esperarImagens(d) {
        var pendentes = Array.prototype.filter.call(d.images, function (i) { return !i.complete; });
        if (!pendentes.length) { return Promise.resolve(); }

        return Promise.race([
            Promise.all(pendentes.map(function (i) {
                return new Promise(function (r) {
                    i.addEventListener('load', r, { once: true });
                    i.addEventListener('error', r, { once: true });
                });
            })),
            new Promise(function (r) { setTimeout(r, 1500); }),
        ]);
    }

    /* ── Copiar texto ─────────────────────────────────────────────────── */
    function copiarTexto() {
        if (!estado || !estado.folha) { return; }
        // innerText da prévia: as partes desligadas (display:none) já ficam de fora.
        var texto = estado.folha.innerText.trim();
        if (texto && navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(texto).then(
                function () { avisar('Texto copiado'); },
                function () { avisar('Não foi possível copiar'); }
            );
        } else {
            avisar('Não foi possível copiar');
        }
    }

    /* ── Eventos da janela ────────────────────────────────────────────── */
    document.addEventListener('click', function (e) {
        if (!raiz && !iniciar()) { return; }
        var alvo = e.target;
        if (!alvo || !alvo.closest) { return; }

        // "Imprimir resumo" do ⋮ do cabeçalho: o link continua apontando para a página
        // própria (caminho sem JS, Ctrl+clique abre em aba nova); o clique simples abre a janela.
        var link = alvo.closest('a[href]');
        if (link && !raiz.contains(link) && e.button === 0 && !e.ctrlKey && !e.metaKey && !e.shiftKey && !e.altKey) {
            var destino = new URL(link.getAttribute('href'), window.location.href);
            var folha = new URL(raiz.getAttribute('data-url-folha'), window.location.href);
            if (destino.pathname === folha.pathname && destino.search === '') {
                e.preventDefault();
                abrir({ secao: 'resumo' });
                return;
            }
        }

        if (!estado) { return; }
        if (alvo === raiz) { fechar(); return; } // clique no fundo escurecido
        if (!raiz.contains(alvo)) { return; }

        if (alvo.closest('.js-psi-fechar')) { fechar(); return; }
        var opcao = alvo.closest('.js-psi-opcao');
        if (opcao) {
            var parte = opcao.getAttribute('data-opcao');
            estado.opcoes[parte] = !estado.opcoes[parte];
            aplicar();
            return;
        }
        if (alvo.closest('.js-psi-menor')) { estado.fonte = Math.max(FONTE_MIN, estado.fonte - 0.5); aplicar(); return; }
        if (alvo.closest('.js-psi-maior')) { estado.fonte = Math.min(FONTE_MAX, estado.fonte + 0.5); aplicar(); return; }
        if (alvo.closest('.js-psi-imprimir')) { imprimir(); return; }
        if (alvo.closest('.js-psi-copiar')) { copiarTexto(); }
    });

    window.PastaImpressao = {
        /** Imprimir resumo da pasta (⋮ do cabeçalho). */
        resumo: function () { return abrir({ secao: 'resumo' }); },
        /**
         * Folha com corpo montado pela tela (registro, processo).
         * @param {{titulo: string, autor?: string, corpo: Node}} cfg
         */
        conteudo: function (cfg) {
            return abrir({ secao: 'moldura', titulo: cfg.titulo, autor: cfg.autor || '', corpo: cfg.corpo });
        },
    };
}());
