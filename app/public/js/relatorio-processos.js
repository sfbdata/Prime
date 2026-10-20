/* ============================================================================
   Relatório geral de processos e "Enviar à Controladoria agora", no ⋮ da aba
   Processo (desenho 02 - EXPEDIENTES 1.2.3: `procHdMenu` L6588, `procMenu`
   L2930, `spPDF` L4569, `impVals` L4621-4631, `spEnviar` L4566-4568).
   Marcação da prévia: expediente/_relatorio_processos_modal.html.twig.

   - "Relatório geral de processos" (`.js-relatorio-processos`): é um link para a
     página do relatório (sem JS, ou Ctrl+clique, abre em aba nova); o clique
     simples abre a prévia. A folha vem do SERVIDOR (`?fragmento=1`) e entra por
     DOMParser + importNode: nenhum innerHTML com dado, nenhum <script> sai dela.
   - Opções: "Cabeçalho do escritório" (classe `rp-sem-cabecalho` na folha) e
     "Tamanho do texto" (9 a 16 pt, passo 0,5; font-size do corpo), sem ir ao
     servidor a cada clique.
   - Imprimir: iframe OCULTO com `sandbox` SEM permissão de script e CSP fechada,
     como a impressão da pasta (pasta-impressao.js).
   - Baixar PDF: a rota do PDF com as mesmas opções (anexo; a tela fica onde está).
   - Copiar texto: o innerText da folha.
   - Enviar (`form.js-relatorio-enviar`): POST com o mesmo FormData (CSRF), por
     XHR; a resposta (`mensagem`) vira o aviso curto da tela. Sem fetch, o form
     segue sozinho (POST + volta para a aba Processo com a mensagem).
   ============================================================================ */
(function () {
    'use strict';

    if (window.__relatorioProcessos) { return; }
    window.__relatorioProcessos = true;

    var FONTE_MIN = 9;
    var FONTE_MAX = 16;
    var FONTE_PADRAO = 10.5;

    var SANDBOX_IMPRESSAO = 'allow-same-origin allow-modals';
    var CSP_IMPRESSAO = "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'";
    var CSS_IMPRESSAO = '@page{size:A4;margin:18mm 0}@page :first{margin-top:0}html,body{margin:0;background:#fff}'
        + 'body .rp-folha{width:auto;min-height:0;margin:0;box-shadow:none;padding:18mm 16mm 0}'
        + 'body .rp-faixa{margin:-18mm -16mm 30px}';

    var estado = null; // { cabecalho, fonte, folha, estilo, gatilho }
    var pedido = 0;

    function el(id) { return document.getElementById(id); }
    function raiz() { return el('rpcImpressao'); }

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
        avisoTimer = setTimeout(function () { aviso.classList.remove('is-visivel'); }, 2400);
    }

    function mensagemNoPalco(texto) {
        var msg = document.createElement('div');
        msg.className = 'psi-palco-aviso';
        msg.textContent = texto;
        el('rpcImpressaoPalco').replaceChildren(msg);
    }

    function rotuloFonte(n) { return String(n).replace('.', ',') + ' pt'; }

    function aplicar() {
        var r = raiz();
        var botao = r.querySelector('.js-rpc-opcao[data-opcao="cabecalho"]');
        botao.setAttribute('aria-pressed', estado.cabecalho ? 'true' : 'false');
        botao.querySelector('i').className = 'bi ' + (estado.cabecalho ? 'bi-check-square-fill' : 'bi-square');
        el('rpcImpressaoFonte').textContent = rotuloFonte(estado.fonte);
        if (!estado.folha) { return; }
        estado.folha.classList.toggle('rp-sem-cabecalho', !estado.cabecalho);
        var corpo = estado.folha.querySelector('[data-rp-parte="corpo"]');
        if (corpo) { corpo.style.fontSize = estado.fonte + 'pt'; }
    }

    function prontos(sim) {
        raiz().querySelectorAll('.js-rpc-imprimir, .js-rpc-baixar, .js-rpc-copiar').forEach(function (b) { b.disabled = !sim; });
    }

    /* ── Abrir / fechar ───────────────────────────────────────────────── */
    function fechar() {
        if (!estado) { return; }
        var gatilho = estado.gatilho;
        pedido++;
        estado = null;
        raiz().hidden = true;
        el('rpcImpressaoPalco').replaceChildren();
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

    function abrir(gatilho) {
        var r = raiz();
        if (!r) { return false; }
        document.dispatchEvent(new CustomEvent('ps:fechar-popovers'));

        var meu = ++pedido;
        estado = { cabecalho: true, fonte: FONTE_PADRAO, folha: null, estilo: null, gatilho: gatilho };
        el('rpcImpressaoTitulo').textContent = 'Imprimir';
        mensagemNoPalco('Montando o relatório…');
        prontos(false);
        aplicar();
        r.hidden = false;
        document.addEventListener('keydown', teclaEsc, true);
        r.querySelector('.js-rpc-fechar').focus();

        var u = new URL(r.getAttribute('data-url-folha'), window.location.href);
        u.searchParams.set('fragmento', '1');
        u.searchParams.set('cabecalho', '1');
        fetch(u.toString(), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
        }).then(function (resp) {
            if (!resp.ok) { throw new Error(String(resp.status)); }

            return resp.text();
        }).then(function (texto) {
            if (meu !== pedido || !estado) { return; }
            montar(texto);
        }).catch(function () {
            if (meu !== pedido || !estado) { return; }
            mensagemNoPalco('Não foi possível montar o relatório. Feche e tente de novo.');
        });

        return true;
    }

    /* A resposta do servidor (Twig autoescapado) vira nós por DOMParser, que não executa script. */
    function montar(texto) {
        var doc = new DOMParser().parseFromString(texto, 'text/html');
        var documento = doc.querySelector('.rp-documento');
        var folha = documento ? documento.querySelector('main.rp-folha') : null;
        var estilo = documento ? documento.querySelector('style') : null;
        if (!folha) {
            mensagemNoPalco('Não foi possível montar o relatório. Feche e tente de novo.');
            return;
        }
        doc.querySelectorAll('script').forEach(function (s) { s.remove(); });

        var palco = el('rpcImpressaoPalco');
        var novoEstilo = estilo ? document.importNode(estilo, true) : null;
        var novaFolha = document.importNode(folha, true);
        palco.replaceChildren();
        if (novoEstilo) { palco.appendChild(novoEstilo); }
        palco.appendChild(novaFolha);
        palco.scrollTop = 0;

        estado.folha = novaFolha;
        estado.estilo = novoEstilo;
        var titulo = novaFolha.getAttribute('data-rp-titulo') || '';
        el('rpcImpressaoTitulo').textContent = 'Imprimir' + (titulo ? ' · ' + titulo : '');
        aplicar();
        prontos(true);
    }

    /* ── Imprimir por iframe oculto ───────────────────────────────────── */
    function copiaParaImprimir() {
        var copia = estado.folha.cloneNode(true);
        if (!estado.cabecalho) {
            copia.querySelectorAll('[data-rp-parte="cabecalho"]').forEach(function (n) { n.remove(); });
        }

        return copia;
    }

    function esperarImagens(d) {
        var pendentes = Array.prototype.filter.call(d.images, function (i) { return !i.complete; });
        if (!pendentes.length) { return Promise.resolve(); }

        return Promise.race([
            Promise.all(pendentes.map(function (i) {
                return new Promise(function (ok) {
                    i.addEventListener('load', ok, { once: true });
                    i.addEventListener('error', ok, { once: true });
                });
            })),
            new Promise(function (ok) { setTimeout(ok, 1500); }),
        ]);
    }

    function imprimir() {
        if (!estado || !estado.folha) { return; }
        var copia = copiaParaImprimir();
        var estilo = estado.estilo ? estado.estilo.cloneNode(true) : null;
        var titulo = estado.folha.getAttribute('data-rp-titulo') || 'BlueJus';

        var fr = document.createElement('iframe');
        fr.className = 'psi-iframe-impressao';
        fr.setAttribute('aria-hidden', 'true');
        fr.setAttribute('sandbox', SANDBOX_IMPRESSAO);
        fr.tabIndex = -1;
        fr.title = 'Impressão';

        function remover() { if (fr.isConnected) { fr.remove(); } }

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

    function baixarPdf() {
        if (!estado) { return; }
        var u = new URL(raiz().getAttribute('data-url-pdf'), window.location.href);
        u.searchParams.set('cabecalho', estado.cabecalho ? '1' : '0');
        u.searchParams.set('fonte', String(estado.fonte));
        window.location.assign(u.toString());
        avisar('Gerando PDF…');
    }

    function copiarTexto() {
        if (!estado || !estado.folha) { return; }
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

    /* ── Eventos ──────────────────────────────────────────────────────── */
    document.addEventListener('click', function (e) {
        var alvo = e.target;
        if (!alvo || !alvo.closest) { return; }

        var link = alvo.closest('a.js-relatorio-processos');
        if (link && raiz() && e.button === 0 && !e.ctrlKey && !e.metaKey && !e.shiftKey && !e.altKey) {
            e.preventDefault();
            abrir(link);
            return;
        }

        var r = raiz();
        if (!estado || !r) { return; }
        if (alvo === r) { fechar(); return; }
        if (!r.contains(alvo)) { return; }
        if (alvo.closest('.js-rpc-fechar')) { fechar(); return; }
        if (alvo.closest('.js-rpc-opcao')) { estado.cabecalho = !estado.cabecalho; aplicar(); return; }
        if (alvo.closest('.js-rpc-menor')) { estado.fonte = Math.max(FONTE_MIN, estado.fonte - 0.5); aplicar(); return; }
        if (alvo.closest('.js-rpc-maior')) { estado.fonte = Math.min(FONTE_MAX, estado.fonte + 0.5); aplicar(); return; }
        if (alvo.closest('.js-rpc-imprimir')) { imprimir(); return; }
        if (alvo.closest('.js-rpc-baixar')) { baixarPdf(); return; }
        if (alvo.closest('.js-rpc-copiar')) { copiarTexto(); }
    });

    /* ── Enviar à Controladoria agora ─────────────────────────────────── */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('form.js-relatorio-enviar')) { return; }
        if (typeof window.fetch !== 'function' || typeof window.FormData !== 'function') { return; }

        e.preventDefault();
        if (form.dataset.enviando) { return; }
        form.dataset.enviando = '1';
        avisar('Enviando o relatório…');

        fetch(form.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: new FormData(form),
        }).then(function (resp) {
            return resp.json().catch(function () { return {}; });
        }).then(function (dados) {
            var texto = typeof dados.mensagem === 'string' ? dados.mensagem
                : (typeof dados.erro === 'string' ? dados.erro : 'Não foi possível enviar o relatório.');
            avisar(texto);
        }, function () {
            avisar('Erro de comunicação. O relatório pode não ter sido enviado.');
        }).then(function () {
            delete form.dataset.enviando;
        });
    });
}());
