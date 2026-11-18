/* =============================================================================
   expediente-compartilhar.js: compartilhar a pasta com a equipe, na Lista do Expediente (spec
   acesso-restrito-e-compartilhar §3.2 L1, L3 a L6; desenho "03 - EXPEDIENTE - VS 30.10.26", janela "Compartilhar
   pasta N" L.488-506, menu da linha L.420-432). Só a aba Equipe do escritório; "Outra conta (código)" é da fatia 8.

   Fala com o servidor pelas rotas JSON `pasta_compartilhamento_ver|criar|encerrar` (o GET devolve o token dos POSTs).
   Entrega ao menu de contexto (expediente-lista.js) os itens da seção COMPARTILHAR: `ExpCompartilhar.itensDoMenu`.
   ============================================================================= */
(function () {
    'use strict';

    function aviso(t) { if (window.ExpAviso) { window.ExpAviso(t); } }
    function el(tag, classe, texto) {
        var e = document.createElement(tag);
        if (classe) { e.className = classe; }
        if (texto !== undefined && texto !== null) { e.textContent = texto; }
        return e;
    }
    function icone(classe) { var i = el('i', 'bi ' + classe); i.setAttribute('aria-hidden', 'true'); return i; }
    function tabela() { return document.getElementById('tabelaPastas'); }
    function urlBase(pastaId) {
        var t = tabela();
        var modelo = t ? t.getAttribute('data-url-compartilhamento') : '';
        return modelo ? modelo.replace('/0/', '/' + pastaId + '/') : '';
    }
    function dataBr(iso) { return iso ? iso.split('-').reverse().join('/') : ''; }
    function iniciais(nome) { return String(nome || '').trim().split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0); }).join('').toUpperCase(); }

    /* ── Servidor ──────────────────────────────────────────────────────── */
    var cache = {};   // pastaId -> dados do GET (o menu e a janela usam os mesmos)

    function pedir(url, opcoes) {
        return fetch(url, opcoes).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; });
        });
    }
    function carregar(pastaId, forcar) {
        if (!forcar && cache[pastaId]) { return Promise.resolve(cache[pastaId]); }
        return pedir(urlBase(pastaId), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }).then(function (r) {
            if (!r.ok || !r.j || !r.j.token) { return null; }
            cache[pastaId] = r.j;
            atualizarIcone(pastaId, r.j);
            return r.j;
        }, function () { return null; });
    }
    function postar(url, token, corpo) {
        return pedir(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: JSON.stringify(corpo || {})
        });
    }
    function ativos(dados) { return dados.colaboradores.filter(function (c) { return c.ativo; }); }

    /* O ícone da linha (L1): cor, contagem e dica acompanham quem colabora agora. */
    function atualizarIcone(pastaId, dados) {
        var t = tabela();
        var botao = t ? t.querySelector('.js-compartilhar[data-pasta-id="' + pastaId + '"]') : null;
        if (!botao) { return; }
        var n = ativos(dados).length;
        botao.classList.toggle('exp-rp--tem', n > 0);
        var selo = botao.querySelector('.exp-rp-n');
        if (selo) { selo.remove(); }
        if (n > 0) { botao.appendChild(el('span', 'exp-rp-n', String(n))); }
        var dica = n > 0 ? t.getAttribute('data-dica-compartilhada') : t.getAttribute('data-dica-compartilhar');
        botao.setAttribute('data-tip', n > 0 && dica ? dica.replace('__N__', String(n)) : (dica || ''));
    }

    /* ── Texto das permissões e do estado (o `PERM` do desenho, L.747) ──── */
    function permissoes(c) {
        return [BJ_T.t('expediente.js.compartilhar.perm_ver')].concat(c.movimentar ? [BJ_T.t('expediente.js.compartilhar.perm_mov')] : [BJ_T.t('expediente.js.compartilhar.perm_metas')], c.enviarDocumentos ? [BJ_T.t('expediente.js.compartilhar.perm_enviar')] : [], c.baixarDocumentos ? [BJ_T.t('expediente.js.compartilhar.perm_baixar')] : []).join(', ');
    }
    var ROTULO_ESTADO = { colaborando: BJ_T.t('expediente.js.compartilhar.estado_colaborando'), aguardando: BJ_T.t('expediente.js.compartilhar.estado_aguardando'), expirada: BJ_T.t('expediente.js.compartilhar.estado_expirada'), encerrada: BJ_T.t('expediente.js.compartilhar.estado_encerrada') };
    var ESTADOS = { colaborando: 'var(--cp-verde)', aguardando: 'var(--cp-aviso)', expirada: 'var(--cp-estado-fim)', encerrada: 'var(--cp-estado-fim)' };

    /* ── Janela "Compartilhar pasta N" ─────────────────────────────────── */
    var j = null;   // { fundo, pastaId, nup, nome, dados, estado, origem, secoes }

    function fechar() {
        if (!j) { return; }
        var origem = j.origem;
        j.fundo.remove();
        j = null;
        if (origem && document.contains(origem)) { origem.focus(); }
    }
    function rotulo(texto) { return el('span', 'exp-cp-rotulo', texto); }
    function caixa(texto, chave) {
        var l = el('label', 'exp-cp-caixa');
        var c = el('input');
        c.type = 'checkbox';
        c.checked = !!j.estado[chave];
        c.addEventListener('change', function () { j.estado[chave] = c.checked; });
        l.appendChild(c);
        l.appendChild(document.createTextNode(texto));
        return l;
    }

    function montarFormulario() {
        var f = el('div', 'exp-cp-form');
        var seg = el('div', 'exp-cp-seg');
        var eq = el('button', 'exp-cp-seg-btn is-ativo');
        eq.type = 'button';
        eq.appendChild(icone('bi-people'));
        eq.appendChild(document.createTextNode(' ' + BJ_T.t('expediente.js.compartilhar.destino_equipe')));
        var ext = el('button', 'exp-cp-seg-btn');
        ext.type = 'button';
        ext.disabled = true;
        ext.title = BJ_T.t('expediente.js.compartilhar.em_breve');
        ext.appendChild(icone('bi-arrow-repeat'));
        ext.appendChild(document.createTextNode(' ' + BJ_T.t('expediente.js.compartilhar.destino_outra')));
        seg.appendChild(eq);
        seg.appendChild(ext);
        var g1 = el('div', 'exp-cp-grupo');
        g1.appendChild(rotulo(BJ_T.t('expediente.js.compartilhar.compartilhar_com')));
        g1.appendChild(seg);
        f.appendChild(g1);

        var gUsr = el('label', 'exp-cp-grupo');
        gUsr.appendChild(rotulo(BJ_T.t('expediente.js.compartilhar.usuario')));
        var sel = el('select', 'exp-cp-campo exp-cp-campo--alto');
        sel.setAttribute('aria-label', BJ_T.t('expediente.js.compartilhar.usuario'));
        var vazia = el('option', null, BJ_T.t('expediente.js.compartilhar.escolha'));
        vazia.value = '';
        sel.appendChild(vazia);
        j.dados.equipe.forEach(function (u) {
            var o = el('option', null, u.nome);
            o.value = String(u.id);
            sel.appendChild(o);
        });
        sel.value = j.estado.usuario;
        sel.addEventListener('change', function () { j.estado.usuario = sel.value; limparMensagens(); });
        gUsr.appendChild(sel);
        gUsr.appendChild(el('span', 'exp-cp-dica', BJ_T.t('expediente.js.compartilhar.dica_sem_aceite')));
        f.appendChild(gUsr);

        var gPerm = el('div', 'exp-cp-grupo exp-cp-grupo--perm');
        gPerm.appendChild(rotulo(BJ_T.t('expediente.js.compartilhar.pode_fazer')));
        var fixo = el('div', 'exp-cp-fixo');
        fixo.appendChild(icone('bi-check-square-fill'));
        fixo.appendChild(document.createTextNode(BJ_T.t('expediente.js.compartilhar.sempre')));
        gPerm.appendChild(fixo);
        gPerm.appendChild(caixa(BJ_T.t('expediente.js.compartilhar.movimentar'), 'movimentar'));
        gPerm.appendChild(caixa(BJ_T.t('expediente.js.compartilhar.enviar_docs'), 'enviarDocumentos'));
        gPerm.appendChild(caixa(BJ_T.t('expediente.js.compartilhar.baixar_docs'), 'baixarDocumentos'));
        f.appendChild(gPerm);

        var linha = el('div', 'exp-cp-linha');
        var gVal = el('label', 'exp-cp-grupo exp-cp-val');
        gVal.appendChild(rotulo(BJ_T.t('expediente.js.compartilhar.validade')));
        var ate = el('select', 'exp-cp-campo');
        ate.setAttribute('aria-label', BJ_T.t('expediente.js.compartilhar.validade'));
        [['', BJ_T.t('expediente.js.compartilhar.sem_prazo')], ['7', BJ_T.t('expediente.js.compartilhar.dias', { n: 7 })], ['30', BJ_T.t('expediente.js.compartilhar.dias', { n: 30 })], ['90', BJ_T.t('expediente.js.compartilhar.dias', { n: 90 })]].forEach(function (p) {
            var o = el('option', null, p[1]);
            o.value = p[0];
            ate.appendChild(o);
        });
        ate.value = j.estado.dias;
        ate.addEventListener('change', function () { j.estado.dias = ate.value; });
        gVal.appendChild(ate);
        var gMsg = el('label', 'exp-cp-grupo exp-cp-msg');
        gMsg.appendChild(rotulo(BJ_T.t('expediente.js.compartilhar.mensagem')));
        var msg = el('input', 'exp-cp-campo');
        msg.type = 'text';
        msg.maxLength = 300;
        msg.placeholder = BJ_T.t('expediente.js.compartilhar.mensagem_exemplo');
        msg.value = j.estado.mensagem;
        msg.addEventListener('input', function () { j.estado.mensagem = msg.value; });
        gMsg.appendChild(msg);
        linha.appendChild(gVal);
        linha.appendChild(gMsg);
        f.appendChild(linha);
        return f;
    }

    function limparMensagens() {
        j.estado.erro = '';
        j.estado.ok = '';
        pintarMensagens();
    }
    function pintarMensagens() {
        j.secoes.erro.hidden = !j.estado.erro;
        j.secoes.erro.textContent = j.estado.erro;
        j.secoes.ok.hidden = !j.estado.ok;
        j.secoes.ok.textContent = '';
        if (j.estado.ok) {
            j.secoes.ok.appendChild(icone('bi-check-circle-fill'));
            j.secoes.ok.appendChild(document.createTextNode(' ' + j.estado.ok));
        }
    }

    /* "Quem já colabora nesta pasta" (L4): o histórico que o servidor devolve, do mais novo ao mais velho. */
    function pintarColaboradores() {
        var alvo = j.secoes.colabora;
        alvo.textContent = '';
        alvo.hidden = j.dados.colaboradores.length === 0;
        if (alvo.hidden) { return; }
        alvo.appendChild(el('b', 'exp-cp-colabora-tit', BJ_T.t('expediente.js.compartilhar.quem_colabora_titulo')));
        j.dados.colaboradores.forEach(function (c) {
            var linha = el('div', 'exp-cp-colab');
            var info = el('div', 'exp-cp-colab-info');
            info.appendChild(el('b', null, c.interno ? BJ_T.t('expediente.js.compartilhar.equipe_do_escritorio', { nome: c.nome }) : c.nome));
            info.appendChild(el('div', null, permissoes(c)));
            info.appendChild(el('div', 'exp-cp-colab-quando', c.ate ? BJ_T.t('expediente.js.compartilhar.quando_ate', { desde: dataBr(c.desde), ate: dataBr(c.ate) }) : BJ_T.t('expediente.js.compartilhar.quando', { desde: dataBr(c.desde) })));
            linha.appendChild(info);
            var st = el('span', 'exp-cp-estado', ROTULO_ESTADO[c.estado]);
            st.style.background = ESTADOS[c.estado] || 'var(--cp-estado-fim)';
            linha.appendChild(st);
            if (c.podeEncerrar) {
                var b = el('button', 'exp-cp-encerrar', BJ_T.t('expediente.js.compartilhar.encerrar'));
                b.type = 'button';
                b.addEventListener('click', function () { encerrar(c.id, b); });
                linha.appendChild(b);
            }
            alvo.appendChild(linha);
        });
    }

    /* Depois de compartilhar ou encerrar: a janela e o ícone da linha recarregam do servidor. */
    function recarregar() {
        var pastaId = j.pastaId;
        return carregar(pastaId, true).then(function (dados) {
            if (!dados || !j || j.pastaId !== pastaId) { return; }
            j.dados = dados;
            pintarColaboradores();
        });
    }

    function validoAte(dias) {
        if (!dias) { return null; }
        var d = new Date();
        d.setDate(d.getDate() + parseInt(dias, 10));
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }
    function enviar(botao) {
        var e = j.estado;
        if (!e.usuario) { e.ok = ''; e.erro = BJ_T.t('expediente.js.compartilhar.escolha_usuario'); pintarMensagens(); return; }
        botao.disabled = true;
        var corpo = { destinoId: parseInt(e.usuario, 10), movimentar: e.movimentar, enviarDocumentos: e.enviarDocumentos, baixarDocumentos: e.baixarDocumentos, validoAte: validoAte(e.dias), mensagem: e.mensagem };
        postar(urlBase(j.pastaId), j.dados.token, corpo).then(function (r) {
            if (!j) { return; }
            botao.disabled = false;
            if (!r.ok) { e.ok = ''; e.erro = r.j.erro || BJ_T.t('expediente.js.compartilhar.falha_conexao'); pintarMensagens(); return; }
            e.erro = '';
            e.ok = r.j.mensagem || '';
            e.usuario = '';
            e.mensagem = '';
            recarregar().then(function () { if (j) { montarCorpo(); pintarMensagens(); } });
        }, function () { if (j) { botao.disabled = false; e.ok = ''; e.erro = BJ_T.t('expediente.js.compartilhar.falha_conexao'); pintarMensagens(); } });
    }
    function encerrar(compartilhamentoId, botao) {
        botao.disabled = true;
        postar(urlBase(j.pastaId) + '/' + compartilhamentoId + '/encerrar', j.dados.token).then(function (r) {
            if (!j) { return; }
            if (!r.ok) { botao.disabled = false; j.estado.ok = ''; j.estado.erro = r.j.erro || BJ_T.t('expediente.js.compartilhar.falha_conexao'); pintarMensagens(); return; }
            j.estado.erro = '';
            j.estado.ok = '';
            recarregar().then(function () { if (j) { montarCorpo(); pintarMensagens(); } });
        }, function () { if (j) { botao.disabled = false; j.estado.erro = BJ_T.t('expediente.js.compartilhar.falha_conexao'); pintarMensagens(); } });
    }

    /* Corpo da janela: o formulário só aparece para quem pode compartilhar (o servidor decide: `podeCompartilhar`). */
    function montarCorpo() {
        var corpo = j.secoes.corpo;
        corpo.textContent = '';
        var topo = el('div');
        topo.appendChild(el('b', 'exp-cp-cliente', j.nome));
        topo.appendChild(el('div', 'exp-cp-intro', BJ_T.t('expediente.js.compartilhar.intro')));
        corpo.appendChild(topo);
        j.secoes.erro = el('div', 'exp-cp-erro');
        j.secoes.ok = el('div', 'exp-cp-ok');
        if (j.dados.podeCompartilhar) {
            corpo.appendChild(montarFormulario());
            corpo.appendChild(j.secoes.erro);
            corpo.appendChild(j.secoes.ok);
            var acoes = el('div', 'exp-cp-acoes');
            var fechaB = el('button', 'exp-cp-btn exp-cp-btn--cinza', BJ_T.t('expediente.js.compartilhar.fechar'));
            fechaB.type = 'button';
            fechaB.addEventListener('click', fechar);
            var envB = el('button', 'exp-cp-btn exp-cp-btn--azul');
            envB.type = 'button';
            envB.appendChild(icone('bi-arrow-repeat'));
            envB.appendChild(document.createTextNode(BJ_T.t('expediente.js.compartilhar.compartilhar')));
            envB.addEventListener('click', function () { enviar(envB); });
            acoes.appendChild(fechaB);
            acoes.appendChild(envB);
            corpo.appendChild(acoes);
        } else {
            corpo.appendChild(j.secoes.erro);
            corpo.appendChild(j.secoes.ok);
        }
        j.secoes.colabora = el('div', 'exp-cp-colabora');
        corpo.appendChild(j.secoes.colabora);
        pintarColaboradores();
        pintarMensagens();
    }

    function abrirJanela(pastaId, nup, nome, origem) {
        fechar();
        carregar(pastaId, true).then(function (dados) {
            if (!dados) { aviso(BJ_T.t('expediente.js.compartilhar.erro_carregar')); return; }
            var fundo = el('div', 'exp-cp-fundo');
            var caixaEl = el('div', 'exp-cp');
            caixaEl.setAttribute('role', 'dialog');
            caixaEl.setAttribute('aria-modal', 'true');
            caixaEl.setAttribute('aria-label', BJ_T.t('expediente.js.compartilhar.titulo', { nup: nup }));
            var cab = el('div', 'exp-cp-cab');
            cab.appendChild(icone('bi-arrow-repeat'));
            cab.appendChild(el('b', null, BJ_T.t('expediente.js.compartilhar.titulo', { nup: nup })));
            var x = el('button', 'exp-cp-x');
            x.type = 'button';
            x.setAttribute('aria-label', BJ_T.t('expediente.js.compartilhar.fechar'));
            x.appendChild(icone('bi-x-lg'));
            x.addEventListener('click', fechar);
            cab.appendChild(x);
            var corpo = el('div', 'exp-cp-corpo');
            caixaEl.appendChild(cab);
            caixaEl.appendChild(corpo);
            fundo.appendChild(caixaEl);
            fundo.addEventListener('mousedown', function (e) { if (e.target === fundo) { fechar(); } });
            document.body.appendChild(fundo);
            j = { fundo: fundo, pastaId: pastaId, nup: nup, nome: nome, dados: dados, origem: origem || null, secoes: { corpo: corpo },
                estado: { usuario: '', movimentar: true, enviarDocumentos: true, baixarDocumentos: false, dias: '', mensagem: '', erro: '', ok: '' } };
            montarCorpo();
            var primeiro = corpo.querySelector('select, button');
            if (primeiro) { primeiro.focus(); }
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && j) { e.preventDefault(); e.stopPropagation(); fechar(); }
    }, true);

    /* O ícone da linha (L1): abre a janela da pasta. */
    document.addEventListener('click', function (e) {
        var botao = e.target.closest && e.target.closest('.js-compartilhar');
        if (!botao) { return; }
        e.preventDefault();
        e.stopPropagation();
        var linha = botao.closest('tr');
        abrirJanela(botao.getAttribute('data-pasta-id'), linha ? linha.getAttribute('data-nup') : '', linha ? (linha.getAttribute('data-nome') || '') : '', botao);
    });

    /* ── Seção COMPARTILHAR do menu de contexto (L6) ───────────────────── */
    function compartilharUmClique(pastaId, nup, destino) {
        carregar(pastaId).then(function (dados) {
            if (!dados) { aviso(BJ_T.t('expediente.js.compartilhar.erro_carregar')); return; }
            /* Como o desenho (L.830): ver, movimentar e enviar documentos; sem baixar. */
            postar(urlBase(pastaId), dados.token, { destinoId: destino.id, movimentar: true, enviarDocumentos: true, baixarDocumentos: false }).then(function (r) {
                if (!r.ok) { aviso(r.j.erro || BJ_T.t('expediente.js.compartilhar.falha_conexao')); return; }
                aviso(BJ_T.t('expediente.js.compartilhar.menu_compartilhada', { nup: nup, nome: destino.nome }));
                carregar(pastaId, true);
            }, function () { aviso(BJ_T.t('expediente.js.compartilhar.falha_conexao')); });
        });
    }
    function deixarDeColaborar(pastaId, nup) {
        carregar(pastaId, true).then(function (dados) {
            var meu = dados ? ativos(dados).filter(function (c) { return c.meu; })[0] : null;
            if (!meu) { aviso(BJ_T.t('expediente.js.compartilhar.erro_carregar')); return; }
            postar(urlBase(pastaId) + '/' + meu.id + '/encerrar', dados.token).then(function (r) {
                if (!r.ok) { aviso(r.j.erro || BJ_T.t('expediente.js.compartilhar.falha_conexao')); return; }
                delete cache[pastaId];
                aviso(BJ_T.t('expediente.js.compartilhar.menu_deixou', { nup: nup }));
                /* Sem a pasta no escopo, ela some da Lista na próxima carga. */
                setTimeout(function () { window.location.reload(); }, 900);
            }, function () { aviso(BJ_T.t('expediente.js.compartilhar.falha_conexao')); });
        });
    }

    /* Itens da seção, para a lista do menu (`abrir` de expediente-lista.js). Sem os dados do GET ainda, devolve nada e
       os busca; quando chegam, `aoCarregar` reabre o menu já com a seção. */
    function itensDoMenu(linha, nup, nome, aoCarregar) {
        var pastaId = linha.getAttribute('data-pasta-id');
        var pode = linha.getAttribute('data-compartilhar') === '1';
        var comigo = linha.getAttribute('data-compartilhada-comigo') === '1';
        if (!pastaId || (!pode && !comigo)) { return []; }
        var dados = cache[pastaId];
        if (!dados) { carregar(pastaId).then(function (d) { if (d) { aoCarregar(); } }); return []; }
        var lista = ['-'];
        if (comigo && !pode) {
            lista.push({ secao: BJ_T.t('expediente.js.compartilhar.menu_recebida'), icone: 'bi-arrow-repeat', corIcone: 'var(--cp-verde)' });
            lista.push({ rotulo: BJ_T.t('expediente.js.compartilhar.menu_deixar'), icone: 'bi-box-arrow-left', acao: function () { deixarDeColaborar(pastaId, nup); } });
            return ativos(dados).some(function (c) { return c.meu; }) ? lista : [];
        }
        if (!dados.podeCompartilhar && ativos(dados).length === 0) { return []; }
        lista.push({ secao: BJ_T.t('expediente.js.compartilhar.menu_secao'), icone: 'bi-arrow-repeat', corIcone: 'var(--cp-verde)' });
        if (dados.podeCompartilhar) {
            dados.recentes.slice(0, 3).forEach(function (r) {
                lista.push({ rotulo: r.nome, avatar: { ini: iniciais(r.nome), bg: 'var(--cp-azul-fundo)' }, extra: BJ_T.t('expediente.js.compartilhar.menu_um_clique'), dica: BJ_T.t('expediente.js.compartilhar.menu_recente_dica', { nome: r.nome }),
                    acao: function () { compartilharUmClique(pastaId, nup, r); } });
            });
            lista.push({ rotulo: BJ_T.t('expediente.js.compartilhar.menu_com_equipe'), icone: 'bi-people', acao: function () { abrirJanela(pastaId, nup, nome, null); } });
        }
        var n = ativos(dados).length;
        if (n > 0) { lista.push({ rotulo: BJ_T.t('expediente.js.compartilhar.menu_quem_colabora'), icone: 'bi-person-gear', selo: String(n), acao: function () { abrirJanela(pastaId, nup, nome, null); } }); }
        return lista;
    }

    window.ExpCompartilhar = { itensDoMenu: itensDoMenu, abrir: abrirJanela };
}());
