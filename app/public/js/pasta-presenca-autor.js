/* =============================================================================
   pasta-presenca-autor.js: presença do autor nos cartões da pasta (Registro da aba Dados, observações do
   Financeiro e de Detalhes). Desenho 02 VS 18.10.26, dc L1398-1399, L1929-1930 e L2456-2457; lógica L6294 e
   L6302; rótulos e cores de bluejus-central.js L45 (STATUS) e `presencaDe` L283-291.

   Não há presença nova: quem está on-line chega pelo batimento da Central (evento `bj-central:presenca` no
   `document`, a cada 20 s, o mesmo que o selo do cabeçalho escuta). Nomes, "desde" e "visto por último" vêm de
   `central_equipe`, pedida UMA vez por página e guardada aqui; depois disso, mudança de status e última vez
   on-line são anotadas pelos próprios batimentos. Sem a Central na página o evento nunca vem e nada é desenhado.

   Cada cartão diz quem é o autor por `data-autor-id`. DOM só com textContent/createElement (regra 5 da Central).
   ============================================================================= */
(function () {
    'use strict';

    var central = document.querySelector('[data-central]');
    if (!central) { return; }
    var euId = Number(central.getAttribute('data-eu-id')) || 0;
    var urlEquipe = central.getAttribute('data-url-equipe');

    // bluejus-central.js L45 (STATUS) e L288 (off-line): rótulo de cada status.
    var ROTULO = { disponivel: 'Disponível', ocupado: 'Ocupado', ausente: 'Ausente', offline: 'Off-line' };
    var CLASSES = ['ppa--disponivel', 'ppa--ocupado', 'ppa--ausente', 'ppa--offline'];

    var online = null;       // Map id -> status efetivo, do último batimento (null = nenhum batimento ainda)
    var equipe = null;       // Map id -> {nome, status, desde, vistoEm}, de central_equipe
    var pedindo = null;
    var desde = {};          // id -> {st, t}: desde quando está neste status (anotado nos batimentos)
    var vistoAqui = {};      // id -> ms: último batimento em que a pessoa estava on-line

    /* O servidor manda o status gravado: `inativo` (sem atividade) aparece como "Ausente", como no cabeçalho. */
    function statusDe(bruto) {
        if (bruto === 'ocupado') { return 'ocupado'; }
        if (bruto === 'ausente' || bruto === 'inativo') { return 'ausente'; }

        return 'disponivel';
    }
    function doisDigitos(n) { return String(n).padStart(2, '0'); }
    function hora(d) { return doisDigitos(d.getHours()) + ':' + doisDigitos(d.getMinutes()); }
    function hm(ms) {   // bluejus-central.js L289 `hm`: "hoje às HH:MM" ou "dd/mm às HH:MM"
        var d = new Date(ms);
        var hoje = new Date();
        var dia = d.toDateString() === hoje.toDateString() ? 'hoje' : doisDigitos(d.getDate()) + '/' + doisDigitos(d.getMonth() + 1);

        return dia + ' às ' + hora(d);
    }
    function instante(iso) {
        var t = iso ? Date.parse(iso) : NaN;

        return isNaN(t) ? null : t;
    }

    function carregarEquipe() {
        if (equipe || pedindo || !urlEquipe) { return pedindo || Promise.resolve(); }
        pedindo = fetch(urlEquipe, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok && (r.headers.get('Content-Type') || '').indexOf('application/json') !== -1 ? r.json() : null; })
            .then(function (d) {
                if (!d || !Array.isArray(d.equipe)) { return; }
                equipe = new Map();
                d.equipe.forEach(function (m) {
                    equipe.set(Number(m.id), { nome: String(m.nome || ''), status: m.status, desde: instante(m.desde), vistoEm: instante(m.vistoEm) });
                });
            })
            .catch(function () { /* sem a equipe, só os on-line ganham presença */ })
            .finally(function () { pedindo = null; });

        return pedindo;
    }

    /* Anota, a cada batimento, quem mudou de status (o "desde") e quem foi visto on-line (o "visto por último"). */
    function anotar(lista) {
        var agora = Date.now();
        var novo = new Map();
        lista.forEach(function (o) {
            var id = Number(o && o.id);
            if (!id) { return; }
            var st = statusDe(o.status);
            novo.set(id, st);
            vistoAqui[id] = agora;
            if (!desde[id] || desde[id].st !== st) {
                // Primeiro batimento da página: o "desde" da equipe, se ela estiver no mesmo status. Depois, a
                // mudança (ou a volta) é de agora: a equipe foi lida uma vez só e o "desde" dela envelhece.
                var m = equipe && equipe.get(id);
                var doServidor = online === null && m && m.desde !== null && statusDe(m.status) === st ? m.desde : null;
                desde[id] = { st: st, t: doServidor !== null ? doServidor : (online ? agora : null) };
            }
        });
        // Quem não veio neste batimento saiu: perde o "desde" (bluejus-central.js L283 `batimento`), e o
        // "desde HH:MM" recomeça quando voltar.
        Object.keys(desde).forEach(function (k) { if (!novo.has(Number(k))) { delete desde[k]; } });
        online = novo;
    }

    /* bluejus-central.js `presencaDe` (L283-291): status, rótulo e o detalhe da dica. */
    function presencaDe(id, nomeDoCartao) {
        var m = equipe ? equipe.get(id) : null;
        var st = online.has(id) ? online.get(id) : 'offline';
        // Pessoa fora da equipe (ex-colaborador): o desenho não mostra presença de quem não conhece.
        if (st === 'offline' && !m) { return null; }
        if (equipe && !m && id !== euId) { return null; }
        var det;
        if (st === 'offline') {
            var visto = Math.max(vistoAqui[id] || 0, (m && m.vistoEm) || 0);
            det = visto ? 'visto por último ' + hm(visto) : 'sem atividade recente';
        } else {
            var d = desde[id];
            det = d && d.t ? 'desde ' + hora(new Date(d.t)) : 'on-line agora';
        }
        var nome = (m && m.nome) || nomeDoCartao;
        var dica = nome + ' · ' + ROTULO[st] + ' · ' + det + (id === euId ? ' · clique para mudar no Chat I.A' : ' · definido no Chat I.A');

        return { st: st, rot: ROTULO[st], dica: dica };
    }

    /* Abre a Central flutuante (dc L6302 `presIr`: só para o próprio autor). Aberta, fica como está. */
    function abrirCentral() {
        var lancador = document.querySelector('[data-central-flutuante] [data-flutuante-lancador]');
        if (lancador && lancador.getAttribute('aria-expanded') !== 'true') { lancador.click(); }
    }

    function aplicarClasse(no, st) {
        CLASSES.forEach(function (c) { no.classList.remove(c); });
        no.classList.add('ppa--' + st);
    }

    function desenharCartao(cartao) {
        var id = Number(cartao.getAttribute('data-autor-id'));
        var topo = cartao.querySelector(':scope > .ps-anotacao-topo');
        if (!id || !topo) { return; }
        var autor = topo.querySelector(':scope > .ps-anotacao-autor');
        var p = presencaDe(id, autor ? autor.textContent.trim() : '');
        var envolto = topo.querySelector(':scope > .ppa-avatar');
        var rotulo = topo.querySelector(':scope > .ppa-rotulo');
        if (!p) {
            if (envolto) {
                var pt = envolto.querySelector(':scope > .ppa-ponto');
                if (pt) { pt.remove(); }
                envolto.removeAttribute('title');
            }
            if (rotulo) { rotulo.remove(); }
            return;
        }

        if (!envolto) {
            var avatar = topo.querySelector(':scope > .ps-avatar');
            if (!avatar) { return; }
            envolto = document.createElement('span');
            envolto.className = 'ppa-avatar';
            avatar.before(envolto);
            envolto.appendChild(avatar);
        }
        var ponto = envolto.querySelector(':scope > .ppa-ponto');
        if (!ponto) {
            ponto = document.createElement('span');
            ponto.className = 'ppa-ponto';
            ponto.setAttribute('aria-hidden', 'true');
            envolto.appendChild(ponto);
        }
        aplicarClasse(envolto, p.st);
        envolto.title = p.dica;

        if (!rotulo) {
            rotulo = document.createElement('span');
            rotulo.className = 'ppa-rotulo';
            rotulo.addEventListener('click', function (e) {
                e.stopPropagation();
                if (Number(cartao.getAttribute('data-autor-id')) === euId) { abrirCentral(); }
            });
            rotulo.addEventListener('keydown', function (e) {
                if (rotulo.getAttribute('role') === 'button' && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); e.stopPropagation(); abrirCentral(); }
            });
        }
        // Logo depois do nome (dc: nome, presença, etiqueta, "editado"): o "editado" que o JS da aba põe depois
        // de uma edição não fica entre os dois.
        if ((autor || envolto).nextElementSibling !== rotulo) { (autor || envolto).after(rotulo); }
        if (id === euId) {
            rotulo.setAttribute('role', 'button');
            rotulo.tabIndex = 0;
        }
        aplicarClasse(rotulo, p.st);
        rotulo.textContent = p.rot;
        rotulo.title = p.dica;
    }

    function desenhar() {
        if (online === null) { return; }
        document.querySelectorAll('.ps-anotacao[data-autor-id]').forEach(desenharCartao);
    }

    document.addEventListener('bj-central:presenca', function (ev) {
        var lista = (ev.detail && Array.isArray(ev.detail.online)) ? ev.detail.online : [];
        if (!document.querySelector('.ps-anotacao[data-autor-id]')) { anotar(lista); return; }
        carregarEquipe().then(function () { anotar(lista); desenhar(); });
    });

    /* Cartão novo (enviar, responder) entra sem esperar o próximo batimento; e o "editado" que o JS da aba
       põe depois do nome ao salvar uma edição não fica entre o nome e a presença (dc: nome, presença,
       etiqueta, "editado"). Só as três listas de cartões são observadas. */
    var listas = ['timelineList', 'financeiroObsLista', 'detalhesObsLista']
        .map(function (id) { return document.getElementById(id); })
        .filter(Boolean);
    var agendado = false;
    function conferirCartoes() {
        agendado = false;
        listas.forEach(function (lista) {
            lista.querySelectorAll('.ps-anotacao[data-autor-id]').forEach(function (c) {
                var topo = c.querySelector(':scope > .ps-anotacao-topo');
                if (!topo) { return; }
                var rotulo = topo.querySelector(':scope > .ppa-rotulo');
                if (!rotulo) { desenharCartao(c); return; }
                var autor = topo.querySelector(':scope > .ps-anotacao-autor');
                if (autor && autor.nextElementSibling !== rotulo) { autor.after(rotulo); }
            });
        });
    }
    var observador = new MutationObserver(function () {
        if (agendado || online === null) { return; }
        agendado = true;
        window.requestAnimationFrame(conferirCartoes);
    });
    listas.forEach(function (lista) { observador.observe(lista, { childList: true, subtree: true }); });
}());
