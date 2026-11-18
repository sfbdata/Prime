/* =============================================================================
   expediente-compartilhar.js: compartilhar a pasta com a equipe, na Lista do Expediente (spec
   acesso-restrito-e-compartilhar §3.2 L1, L3 a L6; desenho "03 - EXPEDIENTE - VS 30.10.26", janela "Compartilhar
   pasta N" L.488-506, menu da linha L.420-432). Abas Equipe do escritório e Outra conta (código, fatia 8, §4.5).

   Fala com o servidor pelas rotas JSON `pasta_compartilhamento_ver|criar|encerrar` (o GET devolve o token dos POSTs).
   Entrega ao menu de contexto (expediente-lista.js) os itens da seção COMPARTILHAR: `ExpCompartilhar.itensDoMenu`.

   Fatia 8 também: o botão "Recebidas" da barra (L9) e a janela "Pastas recebidas de outras contas" (L10, dc L.507-512),
   pelas rotas `expediente_recebidas` (GET, com o código pessoal e o token) e `expediente_recebida_responder`.
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
    /* O código pessoal como o desenho o trata (bj-republicar.js `normCod`, `valido`): maiúsculo, sem o que não é letra ou
       número, em grupos de 4 depois do "BJ-". */
    function normCod(t) {
        var s = String(t || '').toUpperCase().replace(/[^A-Z0-9]/g, '').replace(/^BJ/, '').slice(0, 12);
        return s.length ? 'BJ-' + [s.slice(0, 4), s.slice(4, 8), s.slice(8, 12)].filter(Boolean).join('-') : '';
    }
    function codValido(c) { return /^BJ-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/.test(c); }
    function copiar(texto) { try { navigator.clipboard.writeText(texto); } catch (e) { /* sem área de transferência */ } }
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

    /* Aba Equipe do escritório (dc L.495). */
    function grupoDaEquipe() {
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

        return gUsr;
    }

    /* Aba Outra conta (dc L.496): o código, normalizado enquanto se digita, e a confirmação de formato (o nome só
       aparece depois de enviar: o servidor não diz de quem é um código antes do convite). */
    function grupoDoCodigo() {
        var g = el('label', 'exp-cp-grupo');
        g.appendChild(rotulo(BJ_T.t('expediente.js.compartilhar.codigo_rotulo')));
        var campo = el('input', 'exp-cp-campo exp-cp-campo--alto exp-cp-codigo');
        campo.type = 'text';
        campo.autocomplete = 'off';
        campo.spellcheck = false;
        campo.placeholder = 'BJ-XXXX-XXXX-XXXX';
        campo.setAttribute('aria-label', BJ_T.t('expediente.js.compartilhar.codigo_rotulo'));
        campo.value = j.estado.codigo;
        var quem = el('span', 'exp-cp-quem');
        function pintarQuem() { quem.textContent = codValido(j.estado.codigo) ? BJ_T.t('expediente.js.compartilhar.codigo_valido') : ''; }
        campo.addEventListener('input', function () {
            j.estado.codigo = normCod(campo.value);
            campo.value = j.estado.codigo;
            pintarQuem();
            limparMensagens();
        });
        g.appendChild(campo);
        g.appendChild(quem);
        pintarQuem();
        return g;
    }

    function trocarDestino(destino) {
        if (!j || j.estado.destino === destino) { return; }
        j.estado.destino = destino;
        j.estado.erro = '';
        j.estado.ok = '';
        montarCorpo();
        var alvo = j.secoes.corpo.querySelector(destino === 'ext' ? '.exp-cp-codigo' : 'select');
        if (alvo) { alvo.focus(); }
    }

    function montarFormulario() {
        var f = el('div', 'exp-cp-form');
        var seg = el('div', 'exp-cp-seg');
        var naEquipe = j.estado.destino !== 'ext';
        var eq = el('button', 'exp-cp-seg-btn' + (naEquipe ? ' is-ativo' : ''));
        eq.type = 'button';
        eq.setAttribute('aria-pressed', naEquipe ? 'true' : 'false');
        eq.appendChild(icone('bi-people'));
        eq.appendChild(document.createTextNode(' ' + BJ_T.t('expediente.js.compartilhar.destino_equipe')));
        var ext = el('button', 'exp-cp-seg-btn' + (naEquipe ? '' : ' is-ativo'));
        ext.type = 'button';
        ext.setAttribute('aria-pressed', naEquipe ? 'false' : 'true');
        ext.appendChild(icone('bi-arrow-repeat'));
        ext.appendChild(document.createTextNode(' ' + BJ_T.t('expediente.js.compartilhar.destino_outra')));
        eq.addEventListener('click', function () { trocarDestino('eq'); });
        ext.addEventListener('click', function () { trocarDestino('ext'); });
        seg.appendChild(eq);
        seg.appendChild(ext);
        var g1 = el('div', 'exp-cp-grupo');
        g1.appendChild(rotulo(BJ_T.t('expediente.js.compartilhar.compartilhar_com')));
        g1.appendChild(seg);
        f.appendChild(g1);

        f.appendChild(naEquipe ? grupoDaEquipe() : grupoDoCodigo());

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
            info.appendChild(el('b', null, c.interno ? BJ_T.t('expediente.js.compartilhar.equipe_do_escritorio', { nome: c.nome }) : (c.conta ? c.nome + ' · ' + c.conta : c.nome)));
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
        var externo = e.destino === 'ext';
        if (!externo && !e.usuario) { e.ok = ''; e.erro = BJ_T.t('expediente.js.compartilhar.escolha_usuario'); pintarMensagens(); return; }
        if (externo && !codValido(e.codigo)) { e.ok = ''; e.erro = BJ_T.t('expediente.js.compartilhar.codigo_invalido'); pintarMensagens(); return; }
        botao.disabled = true;
        var corpo = { movimentar: e.movimentar, enviarDocumentos: e.enviarDocumentos, baixarDocumentos: e.baixarDocumentos, validoAte: validoAte(e.dias), mensagem: e.mensagem };
        if (externo) { corpo.outraConta = true; corpo.codigo = e.codigo; } else { corpo.destinoId = parseInt(e.usuario, 10); }
        postar(urlBase(j.pastaId), j.dados.token, corpo).then(function (r) {
            if (!j) { return; }
            botao.disabled = false;
            if (!r.ok) { e.ok = ''; e.erro = r.j.erro || BJ_T.t('expediente.js.compartilhar.falha_conexao'); pintarMensagens(); return; }
            e.erro = '';
            e.ok = r.j.mensagem || '';
            e.usuario = '';
            e.codigo = '';
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

    function abrirJanela(pastaId, nup, nome, origem, destino) {
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
                estado: { destino: destino === 'ext' ? 'ext' : 'eq', usuario: '', codigo: '', movimentar: true, enviarDocumentos: true, baixarDocumentos: false, dias: '', mensagem: '', erro: '', ok: '' } };
            montarCorpo();
            var primeiro = corpo.querySelector(destino === 'ext' ? '.exp-cp-codigo' : 'select, button');
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
            var corpo = { destinoId: destino.id, movimentar: true, enviarDocumentos: true, baixarDocumentos: false };
            if (!destino.interno) { corpo.outraConta = true; }
            postar(urlBase(pastaId), dados.token, corpo).then(function (r) {
                if (!r.ok) { aviso(r.j.erro || BJ_T.t('expediente.js.compartilhar.falha_conexao')); return; }
                aviso(BJ_T.t(destino.interno ? 'expediente.js.compartilhar.menu_compartilhada' : 'expediente.js.compartilhar.menu_republicada', { nup: nup, nome: destino.nome }));
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
                /* Equipe em azul; outra conta em verde, com a conta na dica (dc L.830). */
                var dica = r.interno ? BJ_T.t('expediente.js.compartilhar.menu_recente_dica', { nome: r.nome })
                    : BJ_T.t('expediente.js.compartilhar.menu_recente_dica_conta', { nome: r.nome, conta: r.conta || BJ_T.t('expediente.js.compartilhar.outra_conta') });
                lista.push({ rotulo: r.nome, avatar: { ini: iniciais(r.nome), bg: r.interno ? 'var(--cp-azul-fundo)' : 'var(--cp-verde)' }, extra: BJ_T.t('expediente.js.compartilhar.menu_um_clique'), dica: dica,
                    acao: function () { compartilharUmClique(pastaId, nup, r); } });
            });
            lista.push({ rotulo: BJ_T.t('expediente.js.compartilhar.menu_com_equipe'), icone: 'bi-people', acao: function () { abrirJanela(pastaId, nup, nome, null); } });
            lista.push({ rotulo: BJ_T.t('expediente.js.compartilhar.menu_outra_conta'), icone: 'bi-arrow-repeat', acao: function () { abrirJanela(pastaId, nup, nome, null, 'ext'); } });
        }
        var n = ativos(dados).length;
        if (n > 0) { lista.push({ rotulo: BJ_T.t('expediente.js.compartilhar.menu_quem_colabora'), icone: 'bi-person-gear', selo: String(n), acao: function () { abrirJanela(pastaId, nup, nome, null); } }); }
        if (dados.podeCompartilhar) {
            lista.push({ rotulo: BJ_T.t('expediente.js.compartilhar.menu_copiar_codigo'), icone: 'bi-upc', acao: function () {
                Recebidas.codigo().then(function (codigo) {
                    if (!codigo) { aviso(BJ_T.t('expediente.js.compartilhar.falha_conexao')); return; }
                    copiar(codigo);
                    aviso(BJ_T.t('expediente.js.compartilhar.menu_codigo_copiado', { codigo: codigo }));
                });
            } });
        }
        return lista;
    }

    /* Para a troca de responsável (fatia 7): depois de "Manter como colaborador", o ícone L1 da linha se atualiza. */
    function atualizar(pastaId) { delete cache[pastaId]; return carregar(pastaId, true); }

    /* ── Recebidas (L9 e L10, dc L.134 e L.507-512) ────────────────────── */
    var Recebidas = (function () {
        var dados = null;      // o último GET: { meuCodigo, pendentes, itens, token }
        var janela = null;     // { fundo, lista, origem }

        function url() {
            var b = document.querySelector('.js-recebidas');
            return b ? b.getAttribute('data-url') : '';
        }
        function buscar() {
            var u = url();
            if (!u) { return Promise.resolve(null); }
            return pedir(u, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }).then(function (r) {
                if (!r.ok || !r.j || !r.j.token) { return null; }
                dados = r.j;
                pintarBotoes();
                return dados;
            }, function () { return null; });
        }

        /* O botão da barra (L9): verde com o número dos que aguardam; cinza sem nenhum. A barra é recarregada a cada
           troca de painel, por isso pinta de novo quando ela volta. */
        function pintarBotoes() {
            var n = dados ? dados.pendentes : 0;
            document.querySelectorAll('.js-recebidas').forEach(function (b) {
                b.classList.toggle('exp-rc-btn--pend', n > 0);
                var selo = b.querySelector('.exp-rc-n');
                if (selo) { selo.remove(); }
                if (n > 0) { b.appendChild(el('span', 'exp-rc-n', String(n))); }
            });
        }

        function fechar() {
            if (!janela) { return; }
            var origem = janela.origem;
            janela.fundo.remove();
            janela = null;
            if (origem && document.contains(origem)) { origem.focus(); }
        }

        var ESTADO = {
            aguardando: ['expediente.js.recebidas.estado_aguardando', 'var(--cp-aviso)'],
            colaborando: ['expediente.js.recebidas.estado_colaborando', 'var(--cp-verde)'],
            expirada: ['expediente.js.recebidas.estado_expirada', 'var(--cp-estado-fim)']
        };
        function dataHora(iso) {
            var p = String(iso || '').split('T');
            return dataBr(p[0]) + (p[1] ? ' ' + p[1].slice(0, 5) : '');
        }
        function botao(classe, texto, iconeClasse, acao) {
            var b = el('button', 'exp-rc-acao ' + classe);
            b.type = 'button';
            if (iconeClasse) { b.appendChild(icone(iconeClasse)); b.appendChild(document.createTextNode(' ')); }
            b.appendChild(document.createTextNode(texto));
            b.addEventListener('click', function () { acao(b); });
            return b;
        }

        function cartao(r) {
            var c = el('div', 'exp-rc-item');
            var info = el('div', 'exp-rc-info');
            info.appendChild(el('b', 'exp-rc-tit', r.nup + ' · ' + r.cliente));
            var por = el('div');
            por.appendChild(document.createTextNode(BJ_T.t('expediente.js.recebidas.republicada_por') + ' '));
            por.appendChild(el('b', null, r.de + ' · ' + r.conta));
            info.appendChild(por);
            info.appendChild(el('div', null, permissoes(r)));
            if (r.mensagem) { info.appendChild(el('div', 'exp-rc-msg', '"' + r.mensagem + '"')); }
            info.appendChild(el('div', 'exp-rc-quando', r.ate ? BJ_T.t('expediente.js.recebidas.quando_ate', { em: dataHora(r.em), ate: dataBr(r.ate) }) : BJ_T.t('expediente.js.recebidas.quando', { em: dataHora(r.em) })));
            c.appendChild(info);
            var est = ESTADO[r.estado] || ESTADO.expirada;
            var st = el('span', 'exp-cp-estado', BJ_T.t(est[0]));
            st.style.background = est[1];
            c.appendChild(st);
            var acoes = el('div', 'exp-rc-acoes');
            if (r.estado === 'aguardando') {
                acoes.appendChild(botao('exp-rc-acao--recusar', BJ_T.t('expediente.js.recebidas.recusar'), null, function (b) { responder(r, 'recusar', b); }));
                acoes.appendChild(botao('exp-rc-acao--aceitar', BJ_T.t('expediente.js.recebidas.aceitar'), 'bi-check-lg', function (b) { responder(r, 'aceitar', b); }));
            } else if (r.estado === 'colaborando') {
                /* "Ver pasta" (dc L.511) abre a janela da pasta recebida: chega com a fatia 9. */
                acoes.appendChild(botao('exp-rc-acao--sair', BJ_T.t('expediente.js.recebidas.deixar'), null, function (b) { responder(r, 'deixar', b); }));
            }
            if (acoes.childNodes.length) { c.appendChild(acoes); }
            return c;
        }

        function pintarLista() {
            var alvo = janela.lista;
            alvo.textContent = '';
            janela.codigo.textContent = dados.meuCodigo;
            if (!dados.itens.length) {
                alvo.appendChild(el('div', 'exp-rc-vazio', BJ_T.t('expediente.js.recebidas.vazio')));
                return;
            }
            dados.itens.forEach(function (r) { alvo.appendChild(cartao(r)); });
        }

        function responder(r, acao, b) {
            b.disabled = true;
            postar(url() + '/' + r.id + '/' + acao, dados.token).then(function (resp) {
                if (!resp.ok) { b.disabled = false; aviso(resp.j.erro || BJ_T.t('expediente.js.compartilhar.falha_conexao')); buscar().then(function () { if (janela && dados) { pintarLista(); } }); return; }
                if (acao === 'aceitar') { aviso(BJ_T.t('expediente.js.recebidas.aceita', { nup: r.nup })); }
                if (acao === 'deixar') { aviso(BJ_T.t('expediente.js.compartilhar.menu_deixou', { nup: r.nup })); }
                buscar().then(function () { if (janela && dados) { pintarLista(); } });
            }, function () { b.disabled = false; aviso(BJ_T.t('expediente.js.compartilhar.falha_conexao')); });
        }

        function abrir(origem) {
            fechar();
            buscar().then(function (d) {
                if (!d) { aviso(BJ_T.t('expediente.js.recebidas.erro_carregar')); return; }
                var fundo = el('div', 'exp-cp-fundo');
                var caixa = el('div', 'exp-cp exp-rc');
                caixa.setAttribute('role', 'dialog');
                caixa.setAttribute('aria-modal', 'true');
                caixa.setAttribute('aria-label', BJ_T.t('expediente.js.recebidas.rotulo'));
                var cab = el('div', 'exp-cp-cab exp-rc-cab');
                cab.appendChild(icone('bi-arrow-repeat'));
                cab.appendChild(el('b', null, BJ_T.t('expediente.js.recebidas.titulo')));
                var x = el('button', 'exp-cp-x');
                x.type = 'button';
                x.setAttribute('aria-label', BJ_T.t('expediente.js.compartilhar.fechar'));
                x.appendChild(icone('bi-x-lg'));
                x.addEventListener('click', fechar);
                cab.appendChild(x);
                var faixa = el('div', 'exp-rc-faixa');
                var seu = el('span', 'exp-rc-seu');
                seu.appendChild(document.createTextNode(BJ_T.t('expediente.js.recebidas.seu_codigo') + ' '));
                var codigo = el('b', 'exp-rc-codigo');
                seu.appendChild(codigo);
                var cop = el('button', 'exp-rc-copiar');
                cop.type = 'button';
                cop.appendChild(icone('bi-clipboard'));
                cop.appendChild(document.createTextNode(' ' + BJ_T.t('expediente.js.recebidas.copiar')));
                cop.addEventListener('click', function () { copiar(dados.meuCodigo); aviso(BJ_T.t('expediente.js.recebidas.copiado')); });
                faixa.appendChild(seu);
                faixa.appendChild(cop);
                var lista = el('div');
                caixa.appendChild(cab);
                caixa.appendChild(faixa);
                caixa.appendChild(lista);
                fundo.appendChild(caixa);
                fundo.addEventListener('mousedown', function (e) { if (e.target === fundo) { fechar(); } });
                document.body.appendChild(fundo);
                janela = { fundo: fundo, lista: lista, codigo: codigo, origem: origem || null };
                pintarLista();
                cop.focus();
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && janela) { e.preventDefault(); e.stopPropagation(); fechar(); }
        }, true);
        document.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('.js-recebidas');
            if (!b) { return; }
            e.preventDefault();
            abrir(b);
        });

        /* O botão chega com o painel (carregado por AJAX): a primeira busca espera ele aparecer. O aviso do sino leva a
           `?recebidas=1`, e então a janela abre sozinha. */
        var iniciado = false;
        function iniciarQuandoHouverBotao() {
            if (iniciado || !url()) { return; }
            iniciado = true;
            buscar().then(function () {
                if (/[?&]recebidas=1(&|$)/.test(window.location.search)) { abrir(null); }
            });
        }
        function iniciar() {
            iniciarQuandoHouverBotao();
            var painel = document.getElementById('expediente-painel');
            if (painel && window.MutationObserver) {
                new MutationObserver(function () {
                    iniciarQuandoHouverBotao();
                    if (dados) { pintarBotoes(); }
                }).observe(painel, { childList: true });
            }
        }
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', iniciar); } else { iniciar(); }

        return {
            /* O código da pessoa (o mesmo GET da janela), para "Copiar meu código" do menu. */
            codigo: function () { return (dados ? Promise.resolve(dados) : buscar()).then(function (d) { return d ? d.meuCodigo : ''; }); }
        };
    }());

    window.ExpCompartilhar = { itensDoMenu: itensDoMenu, abrir: abrirJanela, atualizar: atualizar };
}());
