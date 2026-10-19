/* ============================================================================
   Link externo do Push Processual, lado do escritório (spec push-link-externo §5).
   Marcação: templates/pasta/_push_link_externo.html.twig. Desenho [EXP]:
   "02 - EXPEDIENTES 1.2.3.dc.html", modal L.506-552 (`plVals` L.5158-5226),
   painel de acessos L.476-503 (`plAcessosVals` L.4396-4407), menu da aba Push
   (`pushLinkItens`/`abaMenu` L.4378-4419).

   Contrato JSON (App\Pasta\Controller\PastaLinkExternoController):
     GET  <data-url-links>                     lista + podeGerar/motivoBloqueio
     POST <data-url-links>                     gera (exigir_codigo, mostrar_cliente, incluir_analises)
     POST <data-url-links>/<id>/revogar|renovar|recusar
   Escrita com o CSRF do `data-csrf` no campo `_token`.

   Segurança: todo dado do servidor entra na tela por `textContent` e nós criados
   com `createElement`. Nada de HTML montado em texto.
   ============================================================================ */
(function () {
    'use strict';

    var raiz = document.getElementById('modalLinkExternoPush');
    if (!raiz) { return; }

    var URL_LINKS = raiz.getAttribute('data-url-links');
    var CSRF = raiz.getAttribute('data-csrf');
    var NUP = raiz.getAttribute('data-pasta-nup') || '';
    var ERRO_GERAL = 'Não foi possível falar com o servidor. Tente de novo.';

    var menu = document.getElementById('pushLinkMenu');
    var painel = document.getElementById('pushLinkAcessos');

    var estado = {
        dados: null,          // último GET da lista
        pedido: null,         // Promise do GET em curso
        criado: null,         // link recém-criado (estado C.5)
        copiado: false,
        form: { exigir_codigo: true, mostrar_cliente: true, incluir_analises: false },
        abertos: {},          // id -> true: linhas com o popover de acessos aberto
        gatilhoModal: null,
        gatilhoPainel: null,
        gatilhoMenu: null,
        focoLinkId: null,     // linha que pediu Revogar/Renovar/Não renovar: o foco volta para ela
        menuAbertoEm: 0,
        gerando: false,
    };

    function q(seletor, base) { return (base || raiz).querySelector(seletor); }

    function el(tag, classe, texto) {
        var n = document.createElement(tag);
        if (classe) { n.className = classe; }
        if (texto !== undefined && texto !== null) { n.textContent = String(texto); }
        return n;
    }

    function icone(classe) {
        var i = document.createElement('i');
        i.className = classe;
        i.setAttribute('aria-hidden', 'true');
        return i;
    }

    function avisar(texto, tipo) {
        if (typeof window.avisarPs === 'function') { window.avisarPs(texto, tipo); }
    }

    function plural(n, um, varios) { return n === 1 ? um : varios; }

    function semEsquema(url) { return String(url || '').replace(/^https?:\/\//, ''); }

    function dataSo(dataHora) { return String(dataHora || '').split(' ')[0]; }

    /* ── Área de transferência: API do navegador e, sem ela, o textarea ── */
    function copiarTexto(texto) {
        function viaTextarea() {
            var area = document.createElement('textarea');
            area.value = texto;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            area.style.left = '-9999px';
            (raiz.hidden ? document.body : raiz).appendChild(area);
            area.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            area.remove();
            return ok;
        }
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(texto).then(function () { return true; }, function () { return viaTextarea(); });
        }
        return Promise.resolve(viaTextarea());
    }

    /* ── Servidor ── */
    function lerJson(resp) {
        return resp.json().then(function (corpo) { return { ok: resp.ok, status: resp.status, corpo: corpo }; }, function () {
            return { ok: false, status: resp.status, corpo: null };
        });
    }

    function carregar() {
        if (estado.pedido) { return estado.pedido; }
        estado.pedido = fetch(URL_LINKS, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        }).then(lerJson).then(function (r) {
            estado.pedido = null;
            if (!r.ok || !r.corpo || !Array.isArray(r.corpo.links)) {
                throw new Error((r.corpo && r.corpo.erro) || 'Não foi possível carregar os links desta pasta.');
            }
            estado.dados = r.corpo;
            desenharTudo();
            return r.corpo;
        }, function () {
            estado.pedido = null;
            throw new Error(ERRO_GERAL);
        });
        return estado.pedido;
    }

    function postar(url, campos) {
        var corpo = new FormData();
        corpo.append('_token', CSRF);
        Object.keys(campos || {}).forEach(function (k) { corpo.append(k, campos[k]); });
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: corpo,
        }).then(lerJson, function () { return { ok: false, status: 0, corpo: { erro: ERRO_GERAL } }; });
    }

    function links() { return (estado.dados && estado.dados.links) || []; }

    function ativoMaisRecente() {
        // A lista já vem do mais recente para o mais antigo.
        var lista = links();
        for (var i = 0; i < lista.length; i++) { if (lista[i].estado === 'ativo') { return lista[i]; } }
        return null;
    }

    function desenharTudo() {
        desenharSelo();
        if (!raiz.hidden) { desenharModal(); }
        if (painel && !painel.hidden) { desenharPainel(); }
        if (menu && !menu.hidden) { desenharMenu(); }
    }

    /* ── Selo de ativos no item do ⋮ ([EXP] L.1104 `pl.nAtivos`) ── */
    function desenharSelo() {
        var selo = document.querySelector('.js-ppl-badge-ativos');
        if (!selo || !estado.dados) { return; }
        var n = estado.dados.totalAtivos || 0;
        selo.textContent = String(n);
        selo.hidden = n === 0;
        selo.title = n + plural(n, ' link ativo', ' links ativos');
    }

    /* ── Modal ── */
    function mostrarErro(texto) {
        var caixa = q('.js-ppl-erro');
        if (!caixa) { avisar(texto, 'erro'); return; }
        caixa.textContent = texto || '';
        caixa.hidden = !texto;
    }

    function desenharForm() {
        Array.prototype.forEach.call(raiz.querySelectorAll('.js-ppl-op'), function (op) {
            var chave = op.getAttribute('data-op');
            var ligado = !!estado.form[chave] && !op.disabled;
            op.setAttribute('aria-checked', ligado ? 'true' : 'false');
        });
    }

    function desenharIa() {
        var op = q('.js-ppl-op[data-op="incluir_analises"]');
        if (!op || !estado.dados) { return; }
        var n = estado.dados.analisesCompartilhaveis || 0;
        op.disabled = n === 0;
        op.setAttribute('aria-disabled', n === 0 ? 'true' : 'false');
        if (n === 0) { estado.form.incluir_analises = false; }
        // Textos do desenho, `ops` ([EXP] L.5205).
        q('.js-ppl-op-ia-desc').textContent = n > 0
            ? n + ' análise(s) desta pasta. Marcadas como análise, não ato oficial.'
            : 'Esta pasta ainda não tem Análise IA.';
    }

    function desenharCriado() {
        var caixa = q('.js-ppl-criado');
        var form = q('.js-ppl-form');
        var gerar = q('.js-ppl-gerar');
        var c = estado.criado;
        if (caixa) { caixa.hidden = !c; }
        if (form) { form.hidden = !!c; }
        if (!c || !caixa) { return; }
        q('.js-ppl-criado-titulo').textContent = estado.copiado ? 'Link criado e copiado' : 'Link criado';
        q('.js-ppl-criado-url').value = c.url;
        q('.js-ppl-criado-copiar-txt').textContent = estado.copiado ? 'Copiado' : 'Copiar';
        q('.js-ppl-criado-copiar-ic').className = 'bi ' + (estado.copiado ? 'bi-check2' : 'bi-clipboard') + ' js-ppl-criado-copiar-ic';
        q('.js-ppl-criado-codigo-linha').hidden = !c.codigo;
        q('.js-ppl-criado-codigo').textContent = c.codigo || '';
        q('.js-ppl-criado-validade').textContent = 'Válido até ' + c.expiraEm;
        if (gerar) { gerar.hidden = true; }
    }

    function desenharBloqueio() {
        var caixa = q('.js-ppl-bloqueio');
        var gerar = q('.js-ppl-gerar');
        var d = estado.dados;
        var bloqueado = !!(d && !d.podeGerar);
        if (caixa) {
            caixa.textContent = bloqueado ? (d.motivoBloqueio || 'Link externo indisponível.') : '';
            caixa.hidden = !bloqueado || !!estado.criado;
        }
        var form = q('.js-ppl-form');
        if (form && !estado.criado) { form.hidden = bloqueado; }
        if (gerar) { gerar.hidden = bloqueado || !!estado.criado; }
    }

    function desenharModal() {
        var antes = document.activeElement;
        desenharIa();
        desenharForm();
        desenharCriado();
        desenharBloqueio();
        desenharLista();
        focoNaJanela(antes);
    }

    /* Depois de refazer a lista (Revogar, Renovar, Não renovar) ou de o bloqueio esconder o
       formulário, o elemento focado pode ter saído da tela: o foco iria para o <body> e o Esc
       do modal (escutado na janela) deixaria de funcionar. Se o foco estava no modal (ou já
       no <body>), ele volta para um elemento visível da .ppl-janela: a mesma linha, quando a
       ação veio dela, ou o primeiro focável. Foco na janela de confirmação ou no painel de
       acessos não é tocado. */
    function focoNaJanela(antes) {
        var linkId = estado.focoLinkId;
        estado.focoLinkId = null;
        if (raiz.hidden) { return; }
        var estavaAqui = !antes || antes === document.body || raiz.contains(antes);
        var agora = document.activeElement;
        var perdeu = !agora || agora === document.body || !raiz.contains(agora) || agora.getClientRects().length === 0;
        if (!estavaAqui || !perdeu) { return; }
        var alvo = null;
        if (linkId !== null) {
            alvo = raiz.querySelector('.ppl-linha[data-link-id="' + Number(linkId) + '"] .js-ppl-linha-ac');
        }
        if (!alvo || alvo.getClientRects().length === 0) { alvo = focaveis(q('.ppl-janela'))[0] || null; }
        if (alvo) { alvo.focus(); }
    }

    function abrirModal(gatilho) {
        fecharMenu(false);
        document.dispatchEvent(new CustomEvent('ps:fechar-popovers'));
        // `plAbrir` ([EXP] L.5164): toda abertura começa no formulário, com os padrões.
        estado.form = { exigir_codigo: true, mostrar_cliente: true, incluir_analises: false };
        estado.criado = null;
        estado.copiado = false;
        estado.gatilhoModal = gatilho || document.activeElement;
        mostrarErro('');
        raiz.hidden = false;
        desenharModal();
        var foco = q('.js-ppl-op') || q('.js-ppl-fechar');
        if (foco) { foco.focus(); }
        carregar().catch(function (e) { mostrarErro(e.message); });
    }

    function fecharModal() {
        if (raiz.hidden) { return; }
        raiz.hidden = true;
        var g = estado.gatilhoModal;
        estado.gatilhoModal = null;
        if (g && document.contains(g) && g.getClientRects().length > 0) { g.focus(); }
    }

    function gerar() {
        if (estado.gerando) { return; }
        estado.gerando = true;
        var botao = q('.js-ppl-gerar');
        if (botao) { botao.disabled = true; }
        mostrarErro('');
        postar(URL_LINKS, {
            exigir_codigo: estado.form.exigir_codigo ? '1' : '0',
            mostrar_cliente: estado.form.mostrar_cliente ? '1' : '0',
            incluir_analises: estado.form.incluir_analises ? '1' : '0',
        }).then(function (r) {
            if (!r.ok || !r.corpo || !r.corpo.link) {
                mostrarErro((r.corpo && r.corpo.erro) || ERRO_GERAL);
                return null;
            }
            estado.criado = r.corpo.link;
            estado.copiado = false;
            desenharModal();
            return copiarTexto(estado.criado.textoParaCopiar).then(function (ok) {
                estado.copiado = !!ok;
                desenharCriado();
                q('.js-ppl-criado-copiar').focus();
                return carregar();
            });
        }).catch(function (e) { mostrarErro(e.message || ERRO_GERAL); }).then(function () {
            estado.gerando = false;
            if (botao) { botao.disabled = false; }
        });
    }

    /* ── Lista "Links desta pasta" ([EXP] L.540-548, `lista` L.5212-5226) ── */
    function linhaDosAcessos(link) {
        var caixa = el('div', 'ppl-linha-acessos');
        caixa.id = 'pplAcessos' + link.id;
        preencherAcessos(caixa, link.acessos);
        return caixa;
    }

    /** Dias (até 7) e últimos acessos (até 5): o mesmo bloco da linha e do painel. */
    function preencherAcessos(caixa, acessos) {
        if (!acessos || !acessos.total) {
            caixa.appendChild(el('span', 'ppl-nenhum', 'Ninguém abriu este link ainda.'));
            return;
        }
        var dias = el('div', 'ppl-dias');
        (acessos.porDia || []).slice(0, 7).forEach(function (d) {
            dias.appendChild(el('span', 'ppl-dia', d.dia + ' · ' + d.total + plural(d.total, ' acesso', ' acessos')));
        });
        caixa.appendChild(dias);
        var ultimos = el('div', 'ppl-ultimos');
        ultimos.appendChild(el('strong', null, 'Últimos acessos'));
        (acessos.ultimos || []).slice(0, 5).forEach(function (h) { ultimos.appendChild(el('span', null, h)); });
        caixa.appendChild(ultimos);
    }

    /** "dd/mm/aaaa hh:mm" (formato do servidor) já passou? */
    function jaPassou(dataHora) {
        var m = /^(\d{2})\/(\d{2})\/(\d{4}) (\d{2}):(\d{2})$/.exec(String(dataHora || ''));
        if (!m) { return true; }
        return new Date(+m[3], +m[2] - 1, +m[1], +m[4], +m[5]).getTime() <= Date.now();
    }

    /* Sub-linha do desenho (`l.por · l.cod · l.val`, [EXP] L.5221): "até …" enquanto vale e
       "expirou em …" depois. Revogado e indisponível seguem a mesma regra, pela data. */
    function subDaLinha(link) {
        var validade = link.estado === 'ativo' || !jaPassou(link.expiraEm)
            ? 'até ' + link.expiraEm
            : 'expirou em ' + link.expiraEm;
        return 'Criado por ' + link.criadoPor + ' em ' + link.criadoEm + ' · ' + (link.comCodigo ? 'com código' : 'sem código') + ' · ' + validade;
    }

    function botao(classe, texto, ic) {
        var b = el('button', classe);
        b.type = 'button';
        if (ic) { b.appendChild(icone(ic)); }
        if (texto) { b.appendChild(document.createTextNode(texto)); }
        return b;
    }

    function linhaDaLista(link) {
        var li = el('li', 'ppl-linha');
        li.setAttribute('data-link-id', String(link.id));
        var topo = el('div', 'ppl-linha-topo');

        topo.appendChild(el('span', 'ppl-tag ppl-tag--' + link.estado, link.estadoRotulo));

        var textos = el('span', 'ppl-linha-textos');
        textos.appendChild(el('span', 'ppl-linha-nome', semEsquema(link.url)));
        textos.appendChild(el('span', 'ppl-linha-sub', subDaLinha(link)));
        var ren = link.renovacao;
        if (ren && ren.pendente) {
            var pedido = 'Renovação pedida por ' + (ren.nome || 'quem recebeu o link') + ' em ' + ren.pedidaEm + (ren.mensagem ? ': ' + ren.mensagem : '');
            textos.appendChild(el('span', 'ppl-linha-pedido', pedido));
        }
        topo.appendChild(textos);

        var total = (link.acessos && link.acessos.total) || 0;
        var aberto = !!estado.abertos[link.id];
        var ac = el('button', 'ppl-linha-ac js-ppl-linha-ac' + (total ? ' tem-acesso' : ''));
        ac.type = 'button';
        ac.title = total + plural(total, ' acesso', ' acessos') + (link.acessos && link.acessos.ultimo ? ' · último em ' + link.acessos.ultimo : '') + ' · clique para ver os detalhes';
        ac.setAttribute('aria-expanded', aberto ? 'true' : 'false');
        ac.setAttribute('aria-controls', 'pplAcessos' + link.id);
        ac.appendChild(icone('bi bi-eye'));
        ac.appendChild(el('b', null, String(total)));
        ac.appendChild(icone('bi ' + (aberto ? 'bi-chevron-up' : 'bi-chevron-down')));
        topo.appendChild(ac);

        // Copiar só no ativo ([EXP] L.5216 `l.ativo`). O indisponível (a página pública não o
        // abre mais) não tem o que copiar, mas continua revogável.
        if (link.estado === 'ativo') {
            var cp = botao('ppl-linha-copiar js-ppl-linha-copiar', null, 'bi bi-clipboard');
            cp.title = 'Copiar link';
            cp.setAttribute('aria-label', 'Copiar link');
            topo.appendChild(cp);
        }
        if ((link.estado === 'ativo' || link.estado === 'indisponivel') && link.podeRevogar) {
            topo.appendChild(botao('ppl-btn ppl-btn--perigo js-ppl-linha-revogar', 'Revogar'));
        }
        // Renovar e Não renovar empilhados na mesma coluna: lado a lado, o segundo quebrava sozinho
        // para uma linha nova (conferido na tela, 19/10).
        if (link.podeRenovar || link.podeRecusar) {
            var decisao = el('div', 'ppl-linha-decisao');
            if (link.podeRenovar) { decisao.appendChild(botao('ppl-btn ppl-btn--renovar js-ppl-linha-renovar', 'Renovar 30 dias', 'bi bi-arrow-clockwise')); }
            if (link.podeRecusar) { decisao.appendChild(botao('ppl-btn ppl-btn--recusar js-ppl-linha-recusar', 'Não renovar', 'bi bi-x-lg')); }
            topo.appendChild(decisao);
        }

        li.appendChild(topo);
        if (aberto) { li.appendChild(linhaDosAcessos(link)); }
        return li;
    }

    function desenharLista() {
        var bloco = q('.js-ppl-lista-bloco');
        if (!bloco) { return; }
        var lista = links();
        bloco.hidden = lista.length === 0;
        if (!lista.length) { return; }
        var total = estado.dados.totalAcessos || 0;
        q('.js-ppl-ac-total').textContent = String(total);
        q('.js-ppl-ac-total-txt').textContent = plural(total, ' acesso no total', ' acessos no total');
        q('.js-ppl-resumo').textContent = estado.dados.resumo || '';
        var ul = q('.js-ppl-lista');
        ul.replaceChildren.apply(ul, lista.map(linhaDaLista));
    }

    function linkPorId(id) {
        var lista = links();
        for (var i = 0; i < lista.length; i++) { if (String(lista[i].id) === String(id)) { return lista[i]; } }
        return null;
    }

    function acaoNoLink(link, acao, aviso) {
        estado.focoLinkId = link.id;
        return postar(URL_LINKS + '/' + encodeURIComponent(String(link.id)) + '/' + acao, {}).then(function (r) {
            if (!r.ok) {
                avisar((r.corpo && r.corpo.erro) || ERRO_GERAL, 'erro');
                return;
            }
            avisar(aviso);
            return carregar();
        }).catch(function (e) { avisar(e.message || ERRO_GERAL, 'erro'); });
    }

    function revogar(link, gatilho) {
        var pergunta = typeof window.confirmarPs === 'function'
            ? window.confirmarPs({
                titulo: 'Revogar link externo',
                texto: 'Revogar este link? Quem tiver o endereço perde o acesso na hora.',
                rotulo: 'Revogar',
                icone: 'bi-x-circle',
                foco: gatilho,
            })
            : Promise.resolve(window.confirm('Revogar este link? Quem tiver o endereço perde o acesso na hora.'));
        pergunta.then(function (sim) {
            if (sim) { acaoNoLink(link, 'revogar', 'Link revogado'); }
        });
    }

    function copiarLink(link, mensagem) {
        copiarTexto(link.textoParaCopiar).then(function (ok) {
            if (ok) { avisar(mensagem); } else { avisar('Não foi possível copiar. Copie o endereço pela lista do link externo.', 'erro'); }
        });
    }

    // Fundo: `onMouseDown` com target === currentTarget ([EXP] L.507, `pl.fundo` L.5226). Por
    // mousedown, um arrasto que começa num campo e solta no fundo não fecha a janela.
    raiz.addEventListener('mousedown', function (e) {
        if (e.target === e.currentTarget) { fecharModal(); }
    });

    raiz.addEventListener('click', function (e) {
        var alvo = e.target;
        if (alvo.closest('.js-ppl-fechar')) { fecharModal(); return; }

        var op = alvo.closest('.js-ppl-op');
        if (op) {
            if (op.disabled) { return; }
            var chave = op.getAttribute('data-op');
            estado.form[chave] = !estado.form[chave];
            desenharForm();
            return;
        }
        if (alvo.closest('.js-ppl-gerar')) { gerar(); return; }
        if (alvo.closest('.js-ppl-criado-copiar')) {
            if (!estado.criado) { return; }
            copiarTexto(estado.criado.textoParaCopiar).then(function (ok) {
                estado.copiado = !!ok;
                desenharCriado();
                if (!ok) { avisar('Não foi possível copiar. Selecione o endereço e copie.', 'erro'); }
            });
            return;
        }
        if (alvo.closest('.js-ppl-ver-terceiro')) {
            // O acesso conta, como no desenho (spec §5).
            if (estado.criado) { window.open(estado.criado.url, '_blank', 'noopener'); }
            return;
        }
        if (alvo.closest('.js-ppl-criar-outro')) {
            // `pl.novo`: volta ao formulário mantendo as escolhas.
            estado.criado = null;
            estado.copiado = false;
            desenharModal();
            var primeiro = q('.js-ppl-op');
            if (primeiro) { primeiro.focus(); }
            return;
        }
        if (alvo.closest('.js-ppl-abrir-acessos')) { abrirPainel(alvo.closest('.js-ppl-abrir-acessos')); return; }

        var li = alvo.closest('.ppl-linha');
        var link = li ? linkPorId(li.getAttribute('data-link-id')) : null;
        if (!link) { return; }
        if (alvo.closest('.js-ppl-linha-ac')) {
            estado.abertos[link.id] = !estado.abertos[link.id];
            desenharLista();
            var volta = raiz.querySelector('.ppl-linha[data-link-id="' + link.id + '"] .js-ppl-linha-ac');
            if (volta) { volta.focus(); }
            return;
        }
        if (alvo.closest('.js-ppl-linha-copiar')) { copiarLink(link, 'Link copiado'); return; }
        if (alvo.closest('.js-ppl-linha-revogar')) { revogar(link, alvo.closest('button')); return; }
        if (alvo.closest('.js-ppl-linha-renovar')) { acaoNoLink(link, 'renovar', 'Link renovado por 30 dias'); return; }
        if (alvo.closest('.js-ppl-linha-recusar')) { acaoNoLink(link, 'recusar', 'Renovação recusada'); }
    });

    /* Tab fica dentro da janela; Esc fecha (`pl.tecla`). Com o painel de acessos ou a
       janela de confirmação por cima, a tecla é deles. */
    function focaveis(caixa) {
        return Array.prototype.filter.call(
            caixa.querySelectorAll('button, [href], input, [tabindex]:not([tabindex="-1"])'),
            function (n) { return !n.disabled && n.getClientRects().length > 0; }
        );
    }

    function prenderTab(e, caixa) {
        var lista = focaveis(caixa);
        if (!lista.length) { return; }
        var primeiro = lista[0];
        var ultimo = lista[lista.length - 1];
        if (e.shiftKey && document.activeElement === primeiro) { e.preventDefault(); ultimo.focus(); }
        else if (!e.shiftKey && document.activeElement === ultimo) { e.preventDefault(); primeiro.focus(); }
    }

    function confirmacaoAberta() {
        var cf = document.getElementById('psConfirmar');
        return !!(cf && !cf.hidden);
    }

    raiz.addEventListener('keydown', function (e) {
        if (confirmacaoAberta()) { return; }
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); fecharModal(); return; }
        if (e.key === 'Tab') { prenderTab(e, q('.ppl-janela')); }
    });

    /* ── Painel "Acessos ao link do Push" ([EXP] L.476-503, `plAcessosVals`) ── */
    function desenharPainel() {
        if (!painel || !estado.dados) { return; }
        var lista = links();
        var total = estado.dados.totalAcessos || 0;
        q('.js-ppl-ac-sub', painel).textContent = 'Pasta ' + NUP + ' · ' + lista.length + plural(lista.length, ' link', ' links');
        q('.js-ppl-ac-n', painel).textContent = String(total);
        q('.js-ppl-ac-n-txt', painel).textContent = plural(total, 'acesso no total', 'acessos no total');
        q('.js-ppl-ac-ultimo', painel).textContent = estado.dados.ultimoAcesso ? 'Último acesso em ' + estado.dados.ultimoAcesso : 'Nenhum acesso ainda';

        var caixa = q('.js-ppl-ac-lista', painel);
        caixa.replaceChildren.apply(caixa, lista.map(function (link) {
            var n = (link.acessos && link.acessos.total) || 0;
            var cartao = el('div', 'ppl-ac-cartao');
            var topo = el('div', 'ppl-ac-cartao-topo');
            topo.appendChild(el('span', 'ppl-ac-estado ppl-ac-estado--' + link.estado, link.estadoRotulo));
            topo.appendChild(el('span', 'ppl-ac-cartao-nome', semEsquema(link.url)));
            topo.appendChild(el('span', 'ppl-ac-cartao-n', String(n)));
            topo.appendChild(el('span', 'ppl-ac-cartao-n-txt', plural(n, 'acesso', 'acessos')));
            cartao.appendChild(topo);
            cartao.appendChild(el('span', 'ppl-ac-cartao-por', 'Criado por ' + link.criadoPor + ' em ' + link.criadoEm + (link.comCodigo ? ' · com código' : '')));
            preencherAcessos(cartao, link.acessos);
            return cartao;
        }));
    }

    function abrirPainel(gatilho) {
        if (!painel) { return; }
        fecharMenu(false);
        estado.gatilhoPainel = gatilho || document.activeElement;
        painel.hidden = false;
        desenharPainel();
        q('.js-ppl-ac-fechar', painel).focus();
        carregar().catch(function (e) { avisar(e.message, 'erro'); });
    }

    function fecharPainel() {
        if (!painel || painel.hidden) { return; }
        painel.hidden = true;
        var g = estado.gatilhoPainel;
        estado.gatilhoPainel = null;
        if (g && document.contains(g) && g.getClientRects().length > 0) { g.focus(); }
    }

    if (painel) {
        painel.addEventListener('click', function (e) {
            if (e.target === painel || e.target.closest('.js-ppl-ac-fechar')) { fecharPainel(); }
        });
        painel.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); fecharPainel(); return; }
            if (e.key === 'Tab') { prenderTab(e, q('.ppl-ac-janela', painel)); }
        });
    }

    /* ── Menu da aba Push ([EXP] `pushLinkItens` L.4388-4395, `gm` L.289-297) ── */
    function itensVisiveis() {
        return Array.prototype.filter.call(menu.querySelectorAll('.ppl-menu-item'), function (b) { return !b.hidden; });
    }

    function desenharMenu() {
        if (!menu) { return; }
        var carregando = q('.js-ppl-menu-carregando', menu);
        var d = estado.dados;
        carregando.hidden = !!d;
        var copiar = q('.js-ppl-menu-copiar', menu);
        var acessos = q('.js-ppl-menu-acessos', menu);
        var gerarItem = q('.js-ppl-menu-gerar', menu);
        var indisponivel = q('.js-ppl-menu-indisponivel', menu);
        if (!d) {
            [copiar, acessos, gerarItem, indisponivel].forEach(function (b) { b.hidden = true; });
            return;
        }
        // Bloqueado: item único, mesmo com link ativo ([EXP] L.4389, `if (bloq) return [...]`).
        var bloqueado = !d.podeGerar;
        var ativo = ativoMaisRecente();
        var lista = links();
        copiar.hidden = bloqueado || !ativo;
        acessos.hidden = bloqueado || lista.length === 0;
        gerarItem.hidden = bloqueado;
        indisponivel.hidden = !bloqueado;
        var n = d.totalAcessos || 0;
        q('.js-ppl-menu-acessos-rotulo', menu).textContent = 'Ver acessos ao link · ' + n + plural(n, ' acesso', ' acessos');
        q('.js-ppl-menu-gerar-rotulo', menu).textContent = ativo ? 'Gerar novo link externo' : 'Gerar link externo do Push';
    }

    /** x = borda DIREITA do menu; y = topo. Sempre dentro da janela (`gmVals`, [EXP] L.4367). */
    function posicionarMenu(x, y) {
        menu.style.left = '0px';
        menu.style.top = '0px';
        var largura = menu.offsetWidth;
        var altura = menu.offsetHeight;
        menu.style.left = Math.max(8, Math.min(x - largura, window.innerWidth - largura - 8)) + 'px';
        menu.style.top = Math.max(8, Math.min(y, window.innerHeight - altura - 8)) + 'px';
    }

    function abrirMenu(aba, x, y, peloTeclado) {
        if (!menu) { return; }
        document.dispatchEvent(new CustomEvent('ps:fechar-popovers'));
        estado.gatilhoMenu = aba;
        estado.menuAbertoEm = Date.now();
        menu.hidden = false;
        desenharMenu();
        posicionarMenu(x, y);
        function focarPrimeiro() {
            var itens = itensVisiveis();
            if (peloTeclado || document.activeElement === aba) {
                (itens[0] || q('.js-ppl-menu-fechar', menu)).focus();
            }
        }
        focarPrimeiro();
        carregar().then(function () {
            if (menu.hidden) { return; }
            desenharMenu();
            posicionarMenu(x, y);
            if (peloTeclado && (document.activeElement === document.body || menu.contains(document.activeElement))) {
                var itens = itensVisiveis();
                if (itens[0]) { itens[0].focus(); }
            }
        }, function (e) {
            fecharMenu(false);
            avisar(e.message, 'erro');
        });
    }

    function fecharMenu(devolverFoco) {
        if (!menu || menu.hidden) { return; }
        menu.hidden = true;
        var aba = estado.gatilhoMenu;
        estado.gatilhoMenu = null;
        if (devolverFoco && aba) { aba.focus(); }
    }

    function abrirPelaAba(aba) {
        var r = aba.getBoundingClientRect();
        abrirMenu(aba, r.right, r.bottom + 4, true);
    }

    if (menu) {
        document.addEventListener('contextmenu', function (e) {
            var aba = e.target.closest('[data-ppl-menu]');
            if (!aba) { return; }
            e.preventDefault();
            // Shift+F10 e a tecla de menu já abriram pelo keydown; o navegador ainda dispara o
            // contextmenu logo depois. Sem coordenada (teclado), o menu nasce junto da aba.
            if (!menu.hidden && Date.now() - estado.menuAbertoEm < 400) { return; }
            if (e.clientX === 0 && e.clientY === 0) { abrirPelaAba(aba); return; }
            abrirMenu(aba, e.clientX, e.clientY, false);
        });

        document.addEventListener('keydown', function (e) {
            var aba = e.target.closest ? e.target.closest('[data-ppl-menu]') : null;
            if (aba && (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10'))) {
                e.preventDefault();
                abrirPelaAba(aba);
                return;
            }
            if (menu.hidden) { return; }
            if (e.key === 'Escape') { e.preventDefault(); fecharMenu(true); return; }
            if (!menu.contains(document.activeElement)) { return; }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                var itens = itensVisiveis();
                if (!itens.length) { return; }
                var i = itens.indexOf(document.activeElement);
                var prox = e.key === 'ArrowDown' ? (i + 1) % itens.length : (i <= 0 ? itens.length - 1 : i - 1);
                itens[prox].focus();
            } else if (e.key === 'Tab') {
                fecharMenu(false);
            }
        });

        document.addEventListener('mousedown', function (e) {
            if (!menu.hidden && !menu.contains(e.target)) { fecharMenu(false); }
        });
        window.addEventListener('resize', function () { fecharMenu(false); });
        window.addEventListener('scroll', function () { fecharMenu(false); }, true);

        menu.addEventListener('click', function (e) {
            var alvo = e.target;
            var aba = estado.gatilhoMenu;
            if (alvo.closest('.js-ppl-menu-fechar')) { fecharMenu(true); return; }
            if (alvo.closest('.js-ppl-menu-copiar')) {
                var ativo = ativoMaisRecente();
                fecharMenu(true);
                if (!ativo) { return; }
                // Texto do `gmAviso` do desenho ([EXP] L.4392): " · com código de acesso" só quando há código.
                copiarLink(ativo, 'Link copiado · válido até ' + dataSo(ativo.expiraEm) + (ativo.comCodigo ? ' · com código de acesso' : '') + '. Análises com cadeado fechado não aparecem.');
                return;
            }
            if (alvo.closest('.js-ppl-menu-acessos')) { fecharMenu(false); abrirPainel(aba); return; }
            if (alvo.closest('.js-ppl-menu-gerar')) { fecharMenu(false); abrirModal(aba); return; }
            if (alvo.closest('.js-ppl-menu-indisponivel')) {
                fecharMenu(true);
                var motivo = estado.dados && estado.dados.motivoBloqueio;
                avisar(motivo || 'Link externo indisponível.', 'erro');
            }
        });
    }

    /* Pré-carga: o mouse ou o foco chegam na aba Push antes do botão direito, e o menu já abre com
       os itens (o desenho não tem estado "Carregando"). Uma carga por minuto, no máximo. */
    var preCarregadoEm = 0;
    function preCarregar(e) {
        if (!e.target.closest || !e.target.closest('[data-ppl-menu]')) { return; }
        if (Date.now() - preCarregadoEm < 60000) { return; }
        preCarregadoEm = Date.now();
        carregar().catch(function () { preCarregadoEm = 0; });
    }
    document.addEventListener('pointerover', preCarregar);
    document.addEventListener('focusin', preCarregar);

    /* ── Menu ⋮ da pasta ([EXP] L.1104) ── */
    document.addEventListener('click', function (e) {
        var item = e.target.closest('.js-ppl-abrir');
        if (!item) { return; }
        var gatilhoDoMenu = document.querySelector('[data-ps-pop="psMenuAcoes"]');
        abrirModal(gatilhoDoMenu || item);
    });

    /* Abrir o ⋮ atualiza o selo de ativos do item. Na fase de CAPTURA: o pasta-show.js
       (`popovers()`) faz stopPropagation no clique do gatilho, e um ouvinte de bolha no
       document nunca seria chamado. */
    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('[data-ps-pop="psMenuAcoes"]')) { carregar().catch(function () {}); }
    }, true);
})();
