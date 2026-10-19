/* =============================================================================
   trabalho-externo.js: a tela do módulo Trabalho Externo.
   Desenho aprovado: docs/design/trabalho-externo/06 - Trabalho Externo 1.0.dc.html
   (a lógica de tela é a do `renderVals()` da classe Component do protótipo).
   Marcações pessoais e chat avançado: desenho "06 - Trabalho Externo - VS 18.10.26"
   (spec docs/specs/trabalho-externo-vs-18-10-26.md §3); o que é provisório até o zip
   está marcado assim no comentário de cada lista.

   O servidor decide tudo que é sensível (perfil, botões, recorte de privacidade,
   o que cada um vê); este arquivo só pinta e chama a API. Dado vindo do servidor
   entra SEMPRE por textContent ou atributo, nunca por innerHTML.

   Sem dependência de jQuery ou do JS do Bootstrap: menus, painel lateral, modais
   e avisos são daqui. O CSRF vai no header X-CSRF-Token, posto pelo interceptador
   de fetch do base.html.twig.
   ============================================================================= */
(function () {
    'use strict';

    var raiz = document.getElementById('tePagina');
    if (!raiz) {
        return;
    }

    var D = raiz.dataset;
    var MARCADOR = D.codigoMarcador || 'TE-0';
    var MARCADOR_VAGA = 'VG-0';
    var FALHA = 'Não foi possível concluir. Tente de novo.';

    var MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    var FECHADAS = ['concluida', 'arquivada', 'recusada', 'cancelada', 'rascunho'];
    var GRUPOS = [
        ['todas', 'Todas', null],
        ['nova', 'Novas', ['nova']],
        ['analise', 'Em análise', ['analise', 'info']],
        ['proposta', 'Aguardando aceite', ['proposta']],
        ['execucao', 'Em execução', ['aceita', 'execucao', 'validacao']],
        ['concluida', 'Concluídas', ['concluida']],
        ['encerradas', 'Recusadas e canceladas', ['recusada', 'cancelada']],
        ['arquivada', 'Arquivadas', ['arquivada']],
        ['rascunho', 'Rascunhos', ['rascunho']]
    ];
    /**
     * Chips das marcações pessoais depois dos grupos (desenho VS 18.10.26, `chips` do renderVals):
     * estrela e importantes contam o que está fora da lixeira; a lixeira conta só o que está nela.
     */
    var EXTRAS = [
        ['estrela', '★ Com estrela'],
        ['imp', 'Importantes'],
        ['lixeira', 'Lixeira']
    ];
    /** Importância nível 0 a 3 (`impIc`, `impCor`, `impBg` e `impTip` do desenho). */
    var IMPORTANCIA = [
        { bg: 'transparent', cor: '#c9d3da', ic: 'bi-chevron-right', rot: 'Pouca importância' },
        { bg: 'transparent', cor: '#7aa7c2', ic: 'bi-chevron-right', rot: 'Importância moderada' },
        { bg: 'transparent', cor: '#0a6fa0', ic: 'bi-chevron-double-right', rot: 'Importante' },
        { bg: '#0a6fa0', cor: '#fff', ic: 'bi-chevron-double-right', rot: 'Muito importante' }
    ];
    var DESFAZER_MS = 8000;
    var ABAS = { resumo: 'Resumo', proposta: 'Proposta', exec: 'Execução', chat: 'Chat', hist: 'Histórico', whats: 'WhatsApp' };
    var OBRIGATORIOS = ['escritorio', 'email', 'advogado', 'oab', 'cidadeExecucao', 'descricao', 'prazoFinal'];

    var painelInicial;
    try {
        painelInicial = JSON.parse(D.painel || '{}');
    } catch (e) {
        painelInicial = {};
    }

    var S = {
        painel: painelInicial,
        grupo: 'todas',
        busca: '',
        perModo: 'tudo',
        perMesV: String(painelInicial.hoje || '').slice(0, 7),
        perAnoV: String(painelInicial.hoje || '').slice(0, 4),
        hm: false,
        como: null,
        menu: null,
        aberta: null,
        dz: null,
        aba: 'resumo',
        f: {},
        erroProp: '',
        nova: null,
        conf: null,
        cfgAberta: false,
        toast: null,
        sel: [],
        chatSel: [],
        chatVis: 'todas',
        chatUndo: null,
        // Aba Vagas (spec VS 18.10.26 §4.1)
        modulo: 'demandas',
        vg: null,
        vgRelogio: 0,
        vgFiltro: 'abertas',
        vgBusca: '',
        vgSel: [],
        vgDet: null,
        tlm: null,
        vf: null,
        sh: null,
        shPedindo: false
    };

    /* ---------------------------------------------------------------- utilidades */

    function $(id) { return document.getElementById(id); }

    /**
     * Cria um nó. Filhos primeiro, propriedades depois (o `value` de um <select> só pega
     * com as <option> já dentro). `text` vira textContent; `on*` vira addEventListener.
     */
    function el(tag, props, filhos) {
        var n = document.createElement(tag);
        if (filhos !== undefined && filhos !== null) {
            (Array.isArray(filhos) ? filhos : [filhos]).forEach(function (f) {
                if (f === null || f === undefined || f === false) {
                    return;
                }
                n.appendChild(typeof f === 'string' || typeof f === 'number' ? document.createTextNode(String(f)) : f);
            });
        }
        Object.keys(props || {}).forEach(function (k) {
            var v = props[k];
            if (v === undefined || v === null) {
                return;
            }
            if (k === 'class') {
                n.className = v;
            } else if (k === 'text') {
                n.textContent = String(v);
            } else if (k === 'style') {
                Object.keys(v).forEach(function (p) { n.style.setProperty(p, v[p]); });
            } else if (k.indexOf('on') === 0 && typeof v === 'function') {
                n.addEventListener(k.slice(2).toLowerCase(), v);
            } else if (k === 'attrs') {
                Object.keys(v).forEach(function (a) { n.setAttribute(a, v[a]); });
            } else {
                n[k] = v;
            }
        });
        return n;
    }

    function ic(nome, estilo) {
        return el('i', { class: 'bi ' + nome, style: estilo || null });
    }

    function limpar(n) {
        while (n.firstChild) {
            n.removeChild(n.firstChild);
        }
        return n;
    }

    function vazio(v) { return String(v === undefined || v === null ? '' : v).trim() === ''; }

    function dataBr(ymd) {
        if (!ymd) {
            return 'Não informado';
        }
        var p = String(ymd).slice(0, 10).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : String(ymd);
    }

    function diaUtc(ymd) {
        var p = String(ymd).slice(0, 10).split('-');
        return Date.UTC(+p[0], +p[1] - 1, +p[2]);
    }

    function local(cidade, uf) {
        return !vazio(cidade) ? cidade + '/' + uf : (uf || '');
    }

    /* ---------------------------------------------------------------- URLs */

    function comCodigo(base, codigo) { return base.replace(MARCADOR, encodeURIComponent(codigo)); }
    /** Pré-visualização do Master (spec §9): só as LEITURAS levam `?como=`; escrita nunca. */
    function comComo(url) { return S.como ? url + (url.indexOf('?') >= 0 ? '&' : '?') + 'como=' + encodeURIComponent(S.como) : url; }
    /** Vale desde o clique no seletor (S.como), antes mesmo de o painel da prévia chegar. */
    function previa() { return !!S.como || !!S.painel.previa; }
    var ROTA = {
        detalhe: function (c) { return comComo(comCodigo(D.urlDetalhe, c)); },
        acao: function (c, a) { return comCodigo(D.urlAcao, c).replace('__acao__', encodeURIComponent(a)); },
        proposta: function (c) { return comCodigo(D.urlProposta, c); },
        mensagem: function (c) { return comCodigo(D.urlMensagem, c); },
        lida: function (c) { return comCodigo(D.urlLida, c); },
        checklist: function (c, i) { return comCodigo(D.urlChecklist, c).replace(/\/0$/, '/' + i); },
        andamento: function (c) { return comCodigo(D.urlAndamento, c); },
        anexar: function (c) { return comCodigo(D.urlAnexar, c); },
        documento: function (c, id) { return comCodigo(D.urlDocumento, c).replace(/\/0$/, '/' + id); },
        convites: function (c, resto) { return comCodigo(D.urlConvites, c) + resto; },
        whatsapp: function (c, resto) { return comCodigo(D.urlWhatsapp, c).replace(/\/avisar-fase-atual$/, '') + resto; },
        marcacoes: function () { return D.urlMarcacoes; },
        marcacoesMsg: function (c) { return comCodigo(D.urlMensagensMarcacoes, c); },
        apagarMsg: function (c, id) { return comCodigo(D.urlMensagemApagar, c).replace(/\/0\/apagar$/, '/' + id + '/apagar'); },
        // Vagas: as rotas com {codigo} vêm com o marcador VG-0 (o requisito da rota é VG-\d+).
        vagas: function () { return comComo(D.urlVagas); },
        vaga: function (c) { return comComo(D.urlVaga.replace(MARCADOR_VAGA, encodeURIComponent(c))); },
        vagaAcao: function (c, a) { return D.urlVagaAcao.replace(MARCADOR_VAGA, encodeURIComponent(c)).replace('__acao__', encodeURIComponent(a)); },
        vagaSalvar: function () { return D.urlVagaSalvar; },
        vagasMarcacoes: function () { return D.urlVagasMarcacoes; },
        vagasCompartilhar: function () { return D.urlVagasCompartilhar; }
    };

    /* ---------------------------------------------------------------- HTTP */

    /** Toda resposta vira {ok, ...}; falha de rede, HTML de erro ou 403 de CSRF viram {ok:false, mensagem}. */
    function pedir(metodo, url, corpo) {
        var op = { method: metodo, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
        if (corpo instanceof FormData) {
            op.body = corpo;
        } else if (corpo !== undefined) {
            op.headers['Content-Type'] = 'application/json';
            op.body = JSON.stringify(corpo);
        }
        return fetch(url, op).then(function (r) {
            return r.json().then(function (j) {
                if (!j || typeof j !== 'object') {
                    return { ok: false, mensagem: FALHA };
                }
                if (!r.ok || j.ok !== true) {
                    return { ok: false, mensagem: j.mensagem || FALHA };
                }
                return j;
            }, function () {
                return { ok: false, mensagem: FALHA };
            });
        }, function () {
            return { ok: false, mensagem: FALHA };
        });
    }

    function recarregarPainel() {
        return pedir('GET', comComo(D.urlPainel)).then(function (r) {
            if (r.ok && r.painel) {
                S.painel = r.painel;
                renderTudo();
            }
        });
    }

    /* ---------------------------------------------------------------- perfil e configuração */

    function perfil() { return S.painel.perfil || {}; }
    function cfg() { return S.painel.configuracao || {}; }
    function demandas() { return S.painel.demandas || []; }
    function ehSolicitante() { return perfil().chave === 'solicitante'; }

    /* ---------------------------------------------------------------- linha do título */

    var PERFIS = [['master', 'Master', 'bi-shield-check'], ['solicitante', 'Solicitante', 'bi-building'], ['equipe', 'Equipe', 'bi-people'], ['convidado', 'Convidado', 'bi-person-badge']];

    function renderTitulo() {
        var alvo = limpar($('teTituloAcoes'));
        var p = perfil(), c = cfg();

        if (S.painel.podePrever) {
            var atual = S.como || 'master';
            alvo.appendChild(el('div', { class: 'te-perfis', title: 'Pré-visualizar como cada perfil vê a aba (somente leitura)' }, PERFIS.map(function (x) {
                return el('button', {
                    type: 'button', class: x[0] === atual ? 'is-on' : '',
                    attrs: { 'aria-pressed': x[0] === atual ? 'true' : 'false' },
                    onclick: function () { trocarPerfil(x[0]); }
                }, [ic(x[2]), x[1]]);
            })));
        }

        if (c.visibilidade && c.visibilidade !== 'todos' && p.interno) {
            alvo.appendChild(el('span', { class: 'te-selo-vis' }, [ic('bi-eye-slash'), c.visibilidade === 'interno' ? 'Somente equipe' : 'Aba suspensa']));
        }

        if (p.master) {
            var disp = c.disponibilidade || 'on';
            var dispTxt = disp === 'on' ? 'disponível' : disp === 'rest' ? 'com restrições' : 'suspensa';
            var dispCor = disp === 'on' ? '#1e7b45' : disp === 'rest' ? '#c76a12' : '#a3232b';
            alvo.appendChild(el('span', { class: 'te-pilula-disp' }, [ic('bi-circle-fill', { color: dispCor }), 'Central ' + dispTxt]));

            var hm = el('div', { class: 'te-hm' }, [
                el('button', { type: 'button', class: 'te-btn-icone', title: 'Opções do Master', onclick: function () { S.hm = !S.hm; renderTitulo(); } }, ic('bi-three-dots-vertical'))
            ]);
            if (S.hm) {
                hm.appendChild(el('div', { class: 'te-hm-fundo', onclick: fecharHm }));
                hm.appendChild(menuDoMaster());
            }
            alvo.appendChild(hm);
        }

        if (p.podeSolicitar) {
            alvo.appendChild(el('button', { type: 'button', class: 'te-btn-nova', onclick: function () { abrirNova(null, null); } }, [ic('bi-plus-lg'), 'Nova solicitação']));
        }
    }

    function fecharHm() { S.hm = false; renderTitulo(); }

    /** Troca a pré-visualização: recarrega o painel no recorte do perfil (o servidor monta tudo). */
    function trocarPerfil(chave) {
        var como = chave === 'master' ? null : chave;
        if (como === S.como) {
            return;
        }
        var antes = S.como;
        S.como = como;
        S.hm = false;
        fecharMenu();
        fecharDrawer();
        fecharToast();
        fecharTlm();
        // As vagas também vêm no recorte do perfil: some a aba até o servidor dizer se ele a vê.
        S.vg = null;
        S.vgSel = [];
        renderModulos();
        carregarVagas();
        pedir('GET', comComo(D.urlPainel)).then(function (r) {
            if (!r.ok || !r.painel) {
                S.como = antes;
                toastErro(r.mensagem);
                carregarVagas();
                return;
            }
            S.painel = r.painel;
            S.grupo = 'todas';
            S.sel = [];
            renderTudo();
            if (S.painel.avisoDeMensagem) {
                var a = S.painel.avisoDeMensagem;
                mostrarToast({ id: a.codigo, tit: a.titulo, txt: a.texto, hora: a.hora });
            }
        });
    }

    function renderPrevia() {
        var alvo = limpar($('tePrevia'));
        var pv = S.painel.previa;
        if (pv) {
            alvo.appendChild(el('div', { class: 'te-previa', attrs: { role: 'status' } }, [
                ic('bi-eye'),
                el('span', { text: 'Pré-visualizando como ' + pv.rotulo + ': somente leitura. Ações, mensagens e formulários ficam desligados.' }),
                el('button', { type: 'button', text: 'Voltar ao Master', onclick: function () { trocarPerfil('master'); } })
            ]));
        }
    }

    function menuDoMaster() {
        var c = cfg();
        var menu = el('div', { class: 'te-hm-menu' });
        var sec = function (t) { menu.appendChild(el('div', { class: 'te-hm-sec', text: t })); };
        var item = function (rot, icone, ativo, acao) {
            menu.appendChild(el('button', { type: 'button', class: 'te-hm-item', onclick: acao }, [
                ic(icone), el('span', { text: rot }), el('i', { class: 'bi te-hm-ok' + (ativo ? ' bi-check2' : '') })
            ]));
        };

        sec('VISIBILIDADE DA ABA');
        [['todos', 'Visível para todos', 'bi-eye'], ['interno', 'Somente Master e equipe', 'bi-person-lock'], ['oculta', 'Suspensa e oculta para todos', 'bi-eye-slash']].forEach(function (o) {
            item(o[1], o[2], c.visibilidade === o[0], function () { fecharHm(); gravarCfg({ visibilidade: o[0] }); });
        });
        sec('CENTRAL DE SOLICITAÇÕES');
        [['on', 'Disponível', 'bi-circle-fill'], ['rest', 'Disponível com restrições', 'bi-circle-half'], ['off', 'Temporariamente suspensa', 'bi-pause-circle']].forEach(function (o) {
            item(o[1], o[2], c.disponibilidade === o[0], function () { fecharHm(); gravarCfg({ disponibilidade: o[0] }); });
        });
        sec('CONFIGURAÇÕES');
        item('Equipe e privacidade…', 'bi-shield-lock', false, function () { fecharHm(); abrirCfg(); });
        item('Copiar link da aba', 'bi-link-45deg', false, function () {
            fecharHm();
            copiar(location.href.split('?')[0].split('#')[0]);
            mostrarToast({ tit: 'Link copiado', txt: 'Link da aba Trabalho Externo copiado.', hora: '' });
        });

        return menu;
    }

    function copiar(texto) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(texto).catch(function () {});
        }
    }

    /** Cada clique grava (o desenho não tem "Salvar"). `reverter` desfaz a marca se o servidor recusar. */
    function gravarCfg(corpo, reverter) {
        return pedir('POST', D.urlConfiguracao, corpo).then(function (r) {
            if (!r.ok) {
                if (reverter) {
                    reverter();
                }
                toastErro(r.mensagem);
                return;
            }
            recarregarPainel();
        });
    }

    /* ---------------------------------------------------------------- bloqueio e aviso */

    function renderBloqueio() {
        var alvo = limpar($('teBloqueio'));
        var b = S.painel.bloqueio;
        // Quem esconde #teLiberado e #teVagas é o renderModulos(), chamado logo depois.
        if (b) {
            alvo.appendChild(el('div', { class: 'te-bloqueio' }, [
                ic('bi-shield-lock'),
                el('div', { class: 'te-bloqueio-tit', text: b.titulo }),
                el('div', { class: 'te-bloqueio-txt', text: b.texto })
            ]));
        }
    }

    function renderAviso() {
        var alvo = limpar($('teAviso'));
        if (!vazio(S.painel.aviso)) {
            alvo.appendChild(el('div', { class: 'te-aviso' }, [ic('bi-exclamation-triangle'), S.painel.aviso]));
        }
    }

    /* ---------------------------------------------------------------- lista, KPIs, chips, período */

    function diasAte(d) {
        if (!d.prazo || !S.painel.hoje) {
            return null;
        }
        return Math.round((diaUtc(d.prazo) - diaUtc(S.painel.hoje)) / 864e5);
    }

    function noPeriodo(d) {
        if (S.perModo === 'tudo') {
            return true;
        }
        var criada = String(d.criada || '');
        return S.perModo === 'mes' ? criada.slice(0, 7) === S.perMesV : criada.slice(0, 4) === S.perAnoV;
    }

    function grupoAtual() {
        for (var i = 0; i < GRUPOS.length; i++) {
            if (GRUPOS[i][0] === S.grupo) {
                return GRUPOS[i];
            }
        }
        return GRUPOS[0];
    }

    function nivelDe(d) { return Math.max(0, Math.min(3, +((d.importancia || {}).nivel) || 0)); }
    function importante(d) { return nivelDe(d) >= 2; }
    function ehExtra(g) { return EXTRAS.some(function (x) { return x[0] === g; }); }

    /** Fora da lixeira pessoal: é o que conta nos KPIs e nos chips de situação (spec VS 18.10.26 §3.3). */
    function foraDaLixeira() { return demandas().filter(function (d) { return !d.lixeira; }); }

    /** Lista de cada chip extra, sem a busca. Estrela e importantes seguem a regra de "Todas" para rascunho. */
    function doExtra(g) {
        if (g === 'lixeira') {
            return demandas().filter(function (d) { return !!d.lixeira; });
        }
        var solic = ehSolicitante();
        return foraDaLixeira().filter(function (d) {
            if (d.situacao === 'rascunho' && !solic) {
                return false;
            }
            return g === 'estrela' ? !!d.estrela : importante(d);
        });
    }

    function filtradas() {
        var q = S.busca.trim().toLowerCase(), solic = ehSolicitante();
        var extra = ehExtra(S.grupo), G = extra ? null : grupoAtual();
        var V = extra ? doExtra(S.grupo) : foraDaLixeira();
        return V.filter(function (d) {
            if (G && G[2] && G[2].indexOf(d.situacao) < 0) {
                return false;
            }
            if (G && S.grupo === 'todas' && d.situacao === 'rascunho' && !solic) {
                return false;
            }
            if (!q) {
                return true;
            }
            return [d.codigo, d.tipo, d.descricao, d.cidadeExecucao, d.ufExecucao, d.cidadeOrigem, d.processo, d.escritorio].join(' ').toLowerCase().indexOf(q) >= 0;
        });
    }

    function renderLista() {
        renderPeriodo();
        renderKpis();
        renderChips();
        renderLinhas();
    }

    function renderPeriodo() {
        var alvo = limpar($('tePeriodo'));
        var seg = el('div', { class: 'te-seg' });
        [['tudo', 'Tudo'], ['mes', 'Mês'], ['ano', 'Ano']].forEach(function (m) {
            seg.appendChild(el('button', {
                type: 'button',
                class: 'te-seg-btn' + (S.perModo === m[0] ? ' is-ativo' : ''),
                text: m[1],
                onclick: function () { S.perModo = m[0]; renderPeriodo(); renderKpis(); }
            }));
        });
        alvo.appendChild(el('span', { class: 'te-periodo-rot', text: 'PERÍODO' }));
        alvo.appendChild(seg);

        if (S.perModo === 'mes') {
            alvo.appendChild(el('input', {
                type: 'month', class: 'te-periodo-ctl', value: S.perMesV,
                onchange: function (e) { if (e.target.value) { S.perMesV = e.target.value; renderPeriodo(); renderKpis(); } }
            }));
        }
        if (S.perModo === 'ano') {
            var anos = {};
            anos[S.perAnoV] = true;
            anos[String(S.painel.hoje || '').slice(0, 4)] = true;
            demandas().forEach(function (d) { if (d.criada) { anos[String(d.criada).slice(0, 4)] = true; } });
            var lista = Object.keys(anos).filter(Boolean).sort().reverse();
            alvo.appendChild(el('select', {
                class: 'te-periodo-ctl', value: S.perAnoV,
                onchange: function (e) { S.perAnoV = e.target.value; renderPeriodo(); renderKpis(); }
            }, lista.map(function (a) { return el('option', { value: a, text: a }); })));
        }

        var txt = S.perModo === 'tudo'
            ? 'Todas as demandas'
            : S.perModo === 'mes'
                ? 'Demandas criadas em ' + (MESES[+S.perMesV.slice(5, 7) - 1] || '') + ' de ' + S.perMesV.slice(0, 4)
                : 'Demandas criadas em ' + S.perAnoV;
        alvo.appendChild(el('span', { class: 'te-periodo-txt', text: txt }));
    }

    /** O período afeta SÓ os KPIs (spec §5), pela data de criação. */
    function renderKpis() {
        var alvo = limpar($('teKpis'));
        var VP = foraDaLixeira().filter(noPeriodo);
        var abertas = VP.filter(function (d) { return FECHADAS.indexOf(d.situacao) < 0; });
        var conta = function (lista) { return VP.filter(function (d) { return lista.indexOf(d.situacao) >= 0; }).length; };
        [
            ['Abertas', abertas.length, 'bi-folder2-open', '#0a6fa0', 'todas'],
            ['Aguardando aceite', conta(['proposta']), 'bi-file-earmark-text', '#5b3f9e', 'proposta'],
            ['Em execução', conta(['aceita', 'execucao', 'validacao']), 'bi-briefcase', '#0b5f86', 'execucao'],
            ['Prazo em até 3 dias', abertas.filter(function (d) { var n = diasAte(d); return n !== null && n <= 3; }).length, 'bi-alarm', '#a3232b', 'todas'],
            ['Concluídas', conta(['concluida']), 'bi-check2-all', '#1e7b45', 'concluida']
        ].forEach(function (k) {
            alvo.appendChild(el('button', { type: 'button', class: 'te-kpi', onclick: function () { irGrupo(k[4]); } }, [
                ic(k[2], { color: k[3] }),
                el('span', { class: 'te-kpi-textos' }, [el('b', { class: 'te-kpi-n', text: k[1] }), el('span', { class: 'te-kpi-rot', text: k[0] })])
            ]));
        });
    }

    function renderChips() {
        var alvo = limpar($('teChips'));
        var V = foraDaLixeira(), solic = ehSolicitante();
        var chip = function (chave, rot, n) {
            alvo.appendChild(el('button', {
                type: 'button',
                class: 'te-chip' + (S.grupo === chave ? ' is-ativo' : ''),
                onclick: function () { irGrupo(chave); }
            }, [rot, el('span', { class: 'te-chip-n', text: n })]));
        };
        GRUPOS.filter(function (g) { return g[0] !== 'rascunho' || solic; }).forEach(function (g) {
            chip(g[0], g[1], V.filter(function (d) { return !g[2] ? d.situacao !== 'rascunho' : g[2].indexOf(d.situacao) >= 0; }).length);
        });
        EXTRAS.forEach(function (x) { chip(x[0], x[1], doExtra(x[0]).length); });
    }

    /** Trocar de filtro limpa a seleção: a barra de lote muda de operações dentro e fora da lixeira. */
    function irGrupo(g) {
        S.grupo = g;
        S.sel = [];
        renderChips();
        renderLinhas();
    }

    function renderLinhas() {
        var alvo = limpar($('teLinhas'));
        var L = filtradas();
        // Só age sobre o que está à vista: a seleção perde o que saiu do filtro, da busca ou do painel.
        var vistos = L.map(function (d) { return d.codigo; });
        S.sel = S.sel.filter(function (c) { return vistos.indexOf(c) >= 0; });
        renderCabSel(L);
        renderLote();
        L.forEach(function (d) { alvo.appendChild(linha(d)); });
        if (!L.length) {
            var p = perfil(), txt;
            if (!demandas().length) {
                txt = p.interno
                    ? 'Nenhuma demanda recebida ainda. As solicitações enviadas pelos escritórios aparecem aqui.'
                    : ehSolicitante()
                        ? 'Você ainda não fez solicitações. Use "Nova solicitação" para pedir um serviço em outra localidade.'
                        : 'Nenhuma demanda atribuída a você.';
            } else {
                txt = 'Nenhuma demanda neste filtro.';
            }
            alvo.appendChild(el('div', { class: 'te-vazio', text: txt }));
        }
    }

    function selecionada(codigo) { return S.sel.indexOf(codigo) >= 0; }

    function alternarSel(codigo) {
        S.sel = selecionada(codigo) ? S.sel.filter(function (c) { return c !== codigo; }) : S.sel.concat([codigo]);
        renderLinhas();
    }

    /** Cabeçalho da coluna de marcação (desenho, linhas 130 a 133): selecionar todas, "Selecionar por" e Atualizar. */
    function renderCabSel(L) {
        var alvo = limpar($('teCabSel'));
        if (!previa()) {
            var todas = L.length > 0 && S.sel.length === L.length;
            var icone = todas ? 'bi-check-square-fill' : S.sel.length ? 'bi-dash-square-fill' : 'bi-square';
            alvo.appendChild(el('button', {
                type: 'button', class: 'te-sel-btn' + (S.sel.length ? ' is-on' : ''), title: 'Selecionar',
                attrs: { 'aria-label': todas ? 'Limpar seleção' : 'Selecionar todas' },
                onclick: function () {
                    S.sel = todas ? [] : L.map(function (d) { return d.codigo; });
                    renderLinhas();
                }
            }, ic(icone)));
            alvo.appendChild(el('button', {
                type: 'button', class: 'te-sel-caret', title: 'Selecionar por', attrs: { 'aria-haspopup': 'menu' },
                onclick: function (e) {
                    var r = e.currentTarget.getBoundingClientRect();
                    abrirMenuEspecial({ tipo: 'selpor' }, r.left - 30, r.bottom + 6, 180);
                }
            }, ic('bi-caret-down-fill')));
        }
        alvo.appendChild(el('button', {
            type: 'button', class: 'te-sel-atualizar', title: 'Atualizar',
            onclick: function () { recarregarPainel(); }
        }, ic('bi-arrow-clockwise')));
    }

    /** "Selecionar por" (provisório até o zip, spec VS 18.10.26 §3.5): sempre dentro do filtro atual. */
    var SEL_OPS = [
        ['Todas', function () { return true; }],
        ['Nenhuma', function () { return false; }],
        ['Lidas', function (d) { return !(+d.naoLidas > 0); }],
        ['Não lidas', function (d) { return +d.naoLidas > 0; }],
        ['Com estrela', function (d) { return !!d.estrela; }],
        ['Sem estrela', function (d) { return !d.estrela; }],
        ['Importantes', importante]
    ];

    /**
     * Barra de lote e aviso da lixeira (desenho, linhas 125 e 126; `loteAcoes` do renderVals). Um só
     * botão de leitura (lida se alguma tem não lidas) e um de estrela (remover se todas já têm);
     * importante e não importante aparecem sempre.
     */
    function renderLote() {
        var alvo = limpar($('teLote'));
        var naLixeira = S.grupo === 'lixeira';
        if (S.sel.length && !previa()) {
            var sel = demandas().filter(function (d) { return selecionada(d.codigo); });
            var algumaNaoLida = sel.some(function (d) { return +d.naoLidas > 0; });
            var todasEstrela = sel.length > 0 && sel.every(function (d) { return !!d.estrela; });
            var acoes = naLixeira
                ? [
                    ['restaurar', 'Restaurar', 'bi-arrow-counterclockwise', null],
                    ['definitivo', 'Excluir da minha lista definitivamente', 'bi-x-octagon', '#a3232b']
                ]
                : [
                    ['lixeira', 'Mover para a lixeira', 'bi-trash', null],
                    algumaNaoLida ? ['lida', 'Marcar como lida', 'bi-envelope-open', null] : ['naolida', 'Marcar como não lida', 'bi-envelope', null],
                    todasEstrela ? ['semestrela', 'Remover estrela', 'bi-star-fill', '#e0a106'] : ['estrela', 'Adicionar estrela', 'bi-star', null],
                    ['imp', 'Marcar como importante', 'bi-chevron-double-right', '#0a6fa0'],
                    ['naoimp', 'Marcar como não importante', 'bi-chevron-right', null]
                ];
            var codigos = S.sel.slice();
            alvo.appendChild(el('div', { class: 'te-lote', attrs: { role: 'toolbar', 'aria-label': 'Ações nas selecionadas' } }, [
                el('b', { class: 'te-lote-n', text: S.sel.length + ' selecionada(s)' })
            ].concat(acoes.map(function (a) {
                return el('button', {
                    type: 'button', class: 'te-lote-btn', title: a[1],
                    style: a[3] ? { color: a[3] } : null,
                    attrs: { 'data-tip': a[1], 'aria-label': a[1] },
                    onclick: function () { lote(codigos, a[0]); }
                }, ic(a[2]));
            })).concat([
                el('span', { class: 'te-espaco' }),
                el('button', { type: 'button', class: 'te-lote-cancelar', text: 'Cancelar', onclick: function () { S.sel = []; renderLinhas(); } })
            ])));
        }
        if (naLixeira) {
            alvo.appendChild(el('div', { class: 'te-lix-aviso' }, [ic('bi-info-circle'), ' A lixeira é só sua: as demandas continuam para os outros participantes. Restaure quando quiser.']));
        }
    }

    /* ---------------------------------------------------------------- marcações em lote */

    /** Operações de `lote()` do desenho, gravadas pelo servidor só para o usuário logado. */
    function lote(codigos, op) {
        if (!codigos.length || previa()) {
            return;
        }
        fecharMenu();
        if (op === 'definitivo') {
            var n = codigos.length;
            abrirConf(null, {
                k: '__definitivo__',
                confirmacao: {
                    titulo: 'Excluir definitivamente',
                    texto: 'Excluir ' + (n === 1 ? 'esta demanda' : n + ' demandas') + ' da sua lista definitivamente? Ela continua existindo para os outros participantes.',
                    botao: 'Excluir', motivo: false, perigo: true
                },
                executar: function () { return enviarLote(codigos, op); }
            });
            return;
        }
        enviarLote(codigos, op);
    }

    function enviarLote(codigos, op) {
        fecharMenu();
        return pedir('POST', ROTA.marcacoes(), { codigos: codigos, op: op }).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return r;
            }
            S.sel = [];
            renderTudo();
            mostrarDesfazer(r.texto, op === 'lixeira' ? function () { enviarLote(codigos, 'restaurar'); } : null);
            recarregarPainel();
            return r;
        });
    }

    /* ---------------------------------------------------------------- aviso de desfazer (8 s) */

    var temporizadorDesfazer = null;

    /** Faixa escura do canto esquerdo (desenho, linha 127). "Desfazer" só aparece quando há o que desfazer. */
    function mostrarDesfazer(texto, desfazer) {
        clearTimeout(temporizadorDesfazer);
        var alvo = limpar($('teDesfazer'));
        if (vazio(texto)) {
            return;
        }
        alvo.appendChild(el('div', { class: 'te-desfazer', attrs: { role: 'status' } }, [
            el('span', { text: texto }),
            desfazer ? el('button', { type: 'button', class: 'te-desfazer-btn', text: 'Desfazer', onclick: function () { fecharDesfazer(); desfazer(); } }) : null,
            el('button', { type: 'button', class: 'te-desfazer-fechar', title: 'Fechar', onclick: fecharDesfazer }, ic('bi-x-lg'))
        ]));
        temporizadorDesfazer = setTimeout(fecharDesfazer, DESFAZER_MS);
    }

    function fecharDesfazer() {
        clearTimeout(temporizadorDesfazer);
        limpar($('teDesfazer'));
    }

    function linha(d) {
        var n = diasAte(d), fech = !!d.fechada;
        var prazoTxt = fech ? 'encerrado' : n === null ? '' : n < 0 ? 'atrasada ' + (-n) + ' dia(s)' : n === 0 ? 'vence hoje' : 'em ' + n + ' dia(s)';
        var prazoCor = fech ? '#8496a3' : n === null ? '#5b7383' : n < 0 ? '#a3232b' : n <= 3 ? '#c76a12' : '#5b7383';
        var prazoIc = fech ? 'bi-dash' : n !== null && n <= 3 ? 'bi-alarm' : 'bi-calendar3';
        var semResp = vazio(d.responsavel) || d.responsavel === 'A definir';
        var nl = +d.naoLidas || 0;

        var abrirMenu = function (x, y) { abrirMenuDe(d.codigo, d.situacao, d.botoes || [], x, y); };
        var sel = selecionada(d.codigo);

        return el('div', {
            class: 'te-grade te-linha' + (d.urgente && !fech ? ' is-urgente' : '') + (sel ? ' is-sel' : '') + (nl > 0 ? ' is-naolida' : ''),
            onclick: function () {
                if (d.situacao === 'rascunho' && ehSolicitante()) {
                    editarRascunho(d.codigo);
                } else {
                    abrir(d.codigo);
                }
            },
            oncontextmenu: function (e) { e.preventDefault(); abrirMenu(e.clientX, e.clientY); }
        }, [
            marcasDaLinha(d, sel),
            el('div', { class: 'te-l-id', text: d.codigo }),
            el('div', { class: 'te-l-serv' }, [el('div', { class: 'te-l-tipo', text: d.tipo }), el('div', { class: 'te-l-desc', text: d.descricao || '' })]),
            el('div', { class: 'te-l-rota' }, [
                el('div', { text: (d.escritorio || 'Escritório') + ' · ' + local(d.cidadeOrigem, d.ufOrigem) }),
                el('div', { class: 'te-l-destino' }, [ic('bi-arrow-return-right'), ' ' + local(d.cidadeExecucao, d.ufExecucao)])
            ]),
            el('div', { class: 'te-l-prazo' }, [
                el('div', { text: dataBr(d.prazo) }),
                el('div', { class: 'te-l-prazo-txt', style: { color: prazoCor } }, [ic(prazoIc), prazoTxt])
            ]),
            el('div', { class: 'te-l-resp' + (semResp ? ' is-vazio' : ''), text: d.responsavel || 'A definir' }),
            el('div', { class: 'te-l-sit' }, el('span', { style: { color: d.situacaoCor } }, [ic(d.situacaoIcone), d.situacaoRotulo])),
            el('div', { class: 'te-l-chat' }, nl > 0 ? el('span', { class: 'te-l-chat-n', text: nl }) : ic('bi-chat')),
            el('div', { class: 'te-l-mais' }, el('button', {
                type: 'button', class: 'te-btn-mais', title: 'Mais opções',
                onclick: function (e) { e.stopPropagation(); var r = e.currentTarget.getBoundingClientRect(); abrirMenu(r.left - 220, r.bottom + 4); }
            }, ic('bi-three-dots-vertical')))
        ]);
    }

    /**
     * Coluna de marcação (desenho, linha 137): selecionar, estrela e importância. O clique nela não
     * abre a demanda. Na prévia do Master toda escrita é negada: só o indicador de importância fica.
     */
    function marcasDaLinha(d, sel) {
        var nv = nivelDe(d), I = IMPORTANCIA[nv];
        var motivos = ((d.importancia || {}).motivos || []).join(', ');
        var dica = I.rot + (motivos ? ': ' + motivos : '') + '. Clique para marcar ' + (nv >= 2 ? 'como não importante.' : 'como importante.');
        var parar = function (fn) { return function (e) { e.stopPropagation(); if (fn) { fn(); } }; };
        var pv = previa();

        return el('div', { class: 'te-l-marca', onclick: parar(null) }, [
            pv ? null : el('button', {
                type: 'button', class: 'te-sel-btn' + (sel ? ' is-on' : ''), title: 'Selecionar',
                attrs: { 'aria-pressed': sel ? 'true' : 'false' },
                onclick: parar(function () { alternarSel(d.codigo); })
            }, ic(sel ? 'bi-check-square-fill' : 'bi-square')),
            el('button', {
                type: 'button', class: 'te-estrela' + (d.estrela ? ' is-on' : ''), title: 'Estrela', disabled: pv,
                attrs: { 'aria-pressed': d.estrela ? 'true' : 'false' },
                onclick: parar(function () { lote([d.codigo], d.estrela ? 'semestrela' : 'estrela'); })
            }, ic(d.estrela ? 'bi-star-fill' : 'bi-star')),
            el('button', {
                type: 'button', class: 'te-imp', title: dica, disabled: pv,
                style: { background: I.bg, color: I.cor },
                attrs: { 'data-tip': dica, 'aria-label': dica },
                onclick: parar(function () { lote([d.codigo], nv >= 2 ? 'naoimp' : 'imp'); })
            }, ic(I.ic))
        ]);
    }

    /* ---------------------------------------------------------------- menu de contexto */

    function abrirMenuDe(codigo, situacao, botoes, x, y) {
        S.menu = {
            codigo: codigo, situacao: situacao, botoes: botoes,
            x: Math.max(0, Math.min(x, window.innerWidth - 260)),
            y: Math.max(0, Math.min(y, window.innerHeight - 460))
        };
        renderMenu();
    }

    /** Menus que não são o da demanda: "Selecionar por" e o de uma mensagem do chat. */
    function abrirMenuEspecial(m, x, y, largura) {
        m.x = Math.max(0, Math.min(x, window.innerWidth - largura - 10));
        m.y = Math.max(0, Math.min(y, window.innerHeight - 320));
        m.largura = largura;
        S.menu = m;
        renderMenu();
    }

    function fecharMenu() {
        if (S.menu) {
            S.menu = null;
            renderMenu();
        }
    }

    function renderMenu() {
        var alvo = limpar($('teMenu'));
        var m = S.menu;
        if (!m) {
            return;
        }
        var fechar = function (e) { e.preventDefault(); fecharMenu(); };
        var caixa = el('div', { class: 'te-menu', style: { left: m.x + 'px', top: m.y + 'px' } });
        alvo.appendChild(el('div', { class: 'te-menu-fundo', onclick: fechar, oncontextmenu: fechar }));
        if (m.tipo === 'selpor') {
            caixa.style.width = m.largura + 'px';
            SEL_OPS.forEach(function (o) {
                caixa.appendChild(el('button', {
                    type: 'button', class: 'te-menu-item', text: o[0],
                    onclick: function () {
                        fecharMenu();
                        S.sel = filtradas().filter(o[1]).map(function (d) { return d.codigo; });
                        renderLinhas();
                    }
                }));
            });
            alvo.appendChild(caixa);

            return;
        }
        if (m.tipo === 'msg') {
            caixa.style.width = m.largura + 'px';
            menuDaMensagem(caixa, m.msg);
            alvo.appendChild(caixa);

            return;
        }
        if (m.tipo === 'vgselpor') {
            caixa.style.width = m.largura + 'px';
            VG_SEL_OPS.forEach(function (o) {
                caixa.appendChild(el('button', {
                    type: 'button', class: 'te-menu-item', text: o[0],
                    onclick: function () {
                        fecharMenu();
                        S.vgSel = vagasFiltradas().filter(o[1]).map(function (v) { return v.codigo; });
                        renderVagas();
                    }
                }));
            });
            alvo.appendChild(caixa);

            return;
        }
        if (m.tipo === 'vaga') {
            caixa.style.width = m.largura + 'px';
            menuDaVaga(caixa, m.codigo);
            alvo.appendChild(caixa);

            return;
        }
        var base = [['abrir', 'Abrir demanda', 'bi-box-arrow-up-right'], ['chat', 'Abrir chat', 'bi-chat-dots']];
        if (m.situacao !== 'rascunho') {
            base.push(['hist', 'Histórico', 'bi-clock-history']);
        }
        base.forEach(function (b) {
            caixa.appendChild(el('button', {
                type: 'button', class: 'te-menu-item',
                onclick: function () { fecharMenu(); abrir(m.codigo, b[0] === 'abrir' ? 'resumo' : b[0]); }
            }, [ic(b[2]), b[1]]));
        });
        m.botoes.forEach(function (b, i) {
            caixa.appendChild(el('button', {
                type: 'button',
                class: 'te-menu-item' + (i === 0 ? ' is-sep' : '') + (b.tipo === 'perigo' ? ' is-perigo' : ''),
                onclick: function () { executar(m.codigo, b); }
            }, [ic(b.ic), b.rot]));
        });
        alvo.appendChild(caixa);
    }

    /* ---------------------------------------------------------------- ações */

    function executar(codigo, b) {
        fecharMenu();
        if (b.k === 'proposta') {
            abrir(codigo, 'proposta');
            return;
        }
        if (b.k === 'editar') {
            editarRascunho(codigo);
            return;
        }
        if (b.k === 'convidar') {
            abrir(codigo, 'exec');
            return;
        }
        if (b.atalho) {
            return;
        }
        if (b.confirmacao) {
            abrirConf(codigo, b);
            return;
        }
        aplicarAcao(codigo, b.k, null);
    }

    function aplicarAcao(codigo, k, motivo) {
        return pedir('POST', ROTA.acao(codigo, k), { motivo: motivo }).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return false;
            }
            fecharConf();
            if (S.aberta === codigo) {
                if (r.demanda) {
                    S.dz = r.demanda;
                    renderDrawerConteudo();
                } else {
                    fecharDrawer();
                }
            }
            recarregarPainel();
            return true;
        });
    }

    /** Depois de toda escrita no painel: redesenha com a demanda devolvida e atualiza a lista. */
    function aplicarDemanda(r) {
        if (r.demanda && S.aberta === r.demanda.codigo) {
            S.dz = r.demanda;
            renderDrawerConteudo();
        }
        recarregarPainel();
    }

    /* ---------------------------------------------------------------- painel lateral */

    function abrir(codigo, aba) {
        fecharMenu();
        if (S.modulo === 'vagas') {
            irModulo('demandas');
        }
        return pedir('GET', ROTA.detalhe(codigo)).then(function (r) {
            if (!r.ok || !r.demanda) {
                toastErro(r.mensagem);
                return;
            }
            var mesma = S.aberta === codigo;
            S.aberta = codigo;
            S.dz = r.demanda;
            S.aba = (S.dz.abas || []).indexOf(aba || 'resumo') >= 0 ? (aba || 'resumo') : 'resumo';
            if (!mesma) {
                S.f = {};
                S.erroProp = '';
                limparChat();
            }
            montarDrawer();
            if (S.aba === 'chat') {
                marcarLido();
            }
        });
    }

    function fecharDrawer() {
        S.aberta = null;
        S.dz = null;
        S.pctCodigo = null;
        limparChat();
        limpar($('teDrawer'));
    }

    function trocarAba(aba) {
        S.aba = aba;
        renderDrawerConteudo();
        if (aba === 'chat') {
            marcarLido();
        }
    }

    function marcarLido() {
        var dz = S.dz;
        if (!dz) {
            return;
        }
        if (S.toast && S.toast.id === dz.codigo) {
            fecharToast();
        }
        if (!(dz.naoLidas > 0) || previa()) {
            return;
        }
        var codigo = dz.codigo;
        pedir('POST', ROTA.lida(codigo)).then(function (r) {
            if (r.ok && S.dz && S.dz.codigo === codigo) {
                S.dz.naoLidas = 0;
                renderAbas();
                recarregarPainel();
            }
        });
    }

    /** A casca (fundo + aside) nasce uma vez por abertura, para a animação `teIn` não repetir a cada escrita. */
    function montarDrawer() {
        var alvo = $('teDrawer');
        if (!alvo.firstChild) {
            alvo.appendChild(el('div', { class: 'te-drawer-fundo', onclick: fecharDrawer }));
            alvo.appendChild(el('aside', { class: 'te-drawer', attrs: { 'aria-label': 'Demanda' } }, [
                el('div', { class: 'te-dz-cab', id: 'teDzCab' }),
                el('div', { class: 'te-dz-abas', id: 'teDzAbas' }),
                el('div', { class: 'te-and', id: 'teDzAnd' }),
                el('div', { class: 'te-dz-corpo', id: 'teDzCorpo' })
            ]));
        }
        renderDrawerConteudo();
    }

    function renderDrawerConteudo() {
        if (!S.dz || !$('teDzCab')) {
            return;
        }
        renderCabecalho();
        renderAbas();
        renderAndamento();
        renderCorpo();
    }

    function renderCabecalho() {
        var dz = S.dz, alvo = limpar($('teDzCab'));
        alvo.appendChild(el('div', { class: 'te-dz-cab-textos' }, [
            el('div', { class: 'te-dz-cod', text: dz.codigo + ' · ' + dz.situacaoRotulo }),
            el('div', { class: 'te-dz-tipo', text: dz.tipo }),
            el('div', { class: 'te-dz-destino', text: dz.destino })
        ]));
        alvo.appendChild(el('button', {
            type: 'button', class: 'te-dz-btn', title: 'Mais opções',
            onclick: function (e) { e.stopPropagation(); var r = e.currentTarget.getBoundingClientRect(); abrirMenuDe(dz.codigo, dz.situacao, dz.botoes || [], r.left - 220, r.bottom + 4); }
        }, ic('bi-three-dots-vertical')));
        alvo.appendChild(el('button', { type: 'button', class: 'te-dz-btn', title: 'Fechar', onclick: fecharDrawer }, ic('bi-x-lg')));
    }

    function renderAbas() {
        var dz = S.dz, alvo = limpar($('teDzAbas'));
        (dz.abas || []).forEach(function (a) {
            var n = a === 'chat' ? (+dz.naoLidas || 0) : a === 'whats' && dz.whatsapp ? dz.whatsapp.fila.length : 0;
            alvo.appendChild(el('button', {
                type: 'button', class: 'te-dz-aba' + (S.aba === a ? ' is-ativa' : ''),
                onclick: function () { trocarAba(a); }
            }, [ABAS[a] || a, n > 0 ? el('span', { class: 'te-dz-aba-n', text: n }) : null]));
        });
    }

    function renderAndamento() {
        var dz = S.dz, alvo = limpar($('teDzAnd'));
        var marcos = el('div', { class: 'te-and-marcos' });
        (dz.etapas || []).forEach(function (e) {
            marcos.appendChild(el('div', {
                class: 'te-marco' + (e.feito ? ' is-feito' : '') + (e.atual ? ' is-atual' : ''),
                title: e.dt ? e.rot + ' · ' + e.dt : e.rot
            }, [
                el('span', { class: 'te-marco-bola' }, ic(e.ic)),
                el('span', { class: 'te-marco-rot', text: e.rot }),
                el('span', { class: 'te-marco-dt', text: e.dt })
            ]));
        });
        // A barra nasce na largura anterior e anda até a nova (transição de .6s do desenho).
        var pct = Math.max(0, Math.min(1, +dz.progresso || 0));
        var antes = S.pctCodigo === dz.codigo ? S.pct : 0;
        var barra = el('div', { class: 'te-and-barra', style: { width: 'calc(88% * ' + antes + ')' } });
        S.pct = pct;
        S.pctCodigo = dz.codigo;
        alvo.appendChild(el('div', { class: 'te-secao-rot', text: 'ANDAMENTO DA DEMANDA' }));
        alvo.appendChild(el('div', { class: 'te-and-rolagem' }, el('div', { class: 'te-and-faixa' }, [el('div', { class: 'te-and-trilho' }), barra, marcos])));
        if (dz.encerrada) {
            alvo.appendChild(el('div', { class: 'te-encerrada' }, [ic('bi-x-circle'), dz.encerradaTexto]));
        }
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { barra.style.width = 'calc(88% * ' + pct + ')'; });
        });
    }

    function renderCorpo() {
        var dz = S.dz, alvo = limpar($('teDzCorpo'));
        var botoes = (dz.botoes || []).filter(function (b) { return !(b.k === 'proposta' && S.aba === 'proposta') && !(b.k === 'convidar' && S.aba === 'exec'); });
        if (botoes.length) {
            alvo.appendChild(el('div', { class: 'te-acoes' }, botoes.map(function (b) {
                return el('button', {
                    type: 'button',
                    class: 'te-btn-acao' + (b.tipo === 'pri' ? ' te-btn-acao--pri' : b.tipo === 'perigo' ? ' te-btn-acao--perigo' : ''),
                    onclick: function () { executar(dz.codigo, b); }
                }, [ic(b.ic), b.rot]);
            })));
        }
        var abas = { resumo: abaResumo, proposta: abaProposta, exec: abaExecucao, chat: abaChat, hist: abaHistorico, whats: abaWhatsapp };
        (abas[S.aba] || abaResumo)(alvo, dz);
    }

    function campo(r, v) {
        return el('div', null, [el('div', { class: 'te-campo-r', text: r }), el('div', { class: 'te-campo-v', text: v })]);
    }

    function abaResumo(alvo, dz) {
        alvo.appendChild(el('div', { class: 'te-campos' }, (dz.campos || []).map(function (c) { return campo(c.r, c.v); })));
        (dz.textos || []).forEach(function (t) {
            alvo.appendChild(el('div', { class: 'te-texto' }, [el('div', { class: 'te-secao-rot', text: t.r }), el('div', { class: 'te-texto-v', text: t.v })]));
        });

        var cab = el('div', { class: 'te-secao-rot te-docs-cab' }, ['DOCUMENTOS', el('span', { class: 'te-espaco' })]);
        if (dz.podeAnexar) {
            cab.appendChild(el('label', { class: 'te-anexar' }, [
                ic('bi-paperclip'), 'Anexar',
                el('input', { type: 'file', multiple: true, onchange: function (e) { anexar(dz.codigo, e.target); } })
            ]));
        }
        alvo.appendChild(cab);
        (dz.documentos || []).forEach(function (d) {
            alvo.appendChild(el('div', { class: 'te-doc' }, [
                ic('bi-file-earmark-text'),
                el('a', { class: 'te-doc-nome', href: ROTA.documento(dz.codigo, d.id), target: '_blank', rel: 'noopener', text: d.nome }),
                el('span', { class: 'te-doc-info', text: d.info })
            ]));
        });
        if (dz.semDocumentos !== undefined ? dz.semDocumentos : !(dz.documentos || []).length) {
            alvo.appendChild(el('div', { class: 'te-nada', text: 'Nenhum documento anexado.' }));
        }
    }

    function anexar(codigo, input) {
        var arquivos = Array.prototype.slice.call(input.files || []);
        input.value = '';
        if (!arquivos.length) {
            return;
        }
        var fd = new FormData();
        arquivos.forEach(function (a) { fd.append('documentos[]', a, a.name); });
        pedir('POST', ROTA.anexar(codigo), fd).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return;
            }
            aplicarDemanda(r);
        });
    }

    /** Campo que guarda o digitado em S.f, para o redesenho depois de uma escrita não apagar nada. */
    function ctl(tag, chave, props) {
        var p = Object.assign({ class: 'te-ctl', value: S.f[chave] || '' }, props || {});
        p.oninput = function (e) { S.f[chave] = e.target.value; };
        return el(tag, p);
    }

    function abaProposta(alvo, dz) {
        (dz.propostas || []).forEach(function (p) {
            alvo.appendChild(el('div', { class: 'te-prop' + (p.destaque ? ' is-destaque' : '') }, [
                el('div', { class: 'te-prop-cab' }, [
                    el('b', { text: 'Proposta v' + p.v }),
                    el('span', { class: 'te-prop-em', text: p.em }),
                    el('span', { class: 'te-espaco' }),
                    el('span', { class: 'te-prop-st', style: { color: p.stCor }, text: p.st })
                ]),
                el('div', { class: 'te-prop-campos' }, (p.campos || []).map(function (c) {
                    return el('div', null, [el('div', { class: 'te-campo-r', text: c.r }), el('div', { text: c.v })]);
                }))
            ]));
        });
        if (!(dz.propostas || []).length) {
            alvo.appendChild(el('div', { class: 'te-prop-vazio', text: 'Nenhuma proposta enviada ainda.' }));
        }
        if (!dz.podePropor) {
            return;
        }

        var rot = function (texto, controle) { return el('label', { class: 'te-rot' }, [texto, controle]); };
        var erro = el('span', { class: 'te-erro', text: S.erroProp });
        alvo.appendChild(el('div', { class: 'te-caixa' }, [
            el('div', { class: 'te-caixa-tit', text: dz.propostaTitulo }),
            el('div', { class: 'te-grade-form' }, [
                rot('Valor do serviço (R$) *', ctl('input', 'valor', { inputMode: 'decimal' })),
                rot('Despesas previstas (R$)', ctl('input', 'despesas', { inputMode: 'decimal' })),
                rot('Prazo estimado', ctl('input', 'prazoEst', { placeholder: 'Ex.: 2 dias úteis' })),
                rot('Validade da proposta', ctl('input', 'validade', { type: 'date' }))
            ]),
            rot('Condições de pagamento', ctl('input', 'pagamento', { placeholder: 'Ex.: 50% no aceite, 50% na entrega' })),
            rot('Escopo e atividades incluídas', ctl('textarea', 'escopo', { rows: 3 })),
            rot('Não incluído / responsabilidades', ctl('textarea', 'naoInclui', { rows: 2 })),
            el('div', { class: 'te-form-rodape' }, [
                erro,
                el('button', { type: 'button', class: 'te-btn-pri', onclick: function (e) { enviarProposta(dz.codigo, e.currentTarget, erro); } }, [ic('bi-send'), 'Enviar proposta'])
            ])
        ]));
    }

    function enviarProposta(codigo, botao, erro) {
        var f = S.f;
        if (vazio(f.valor)) {
            S.erroProp = 'Informe o valor do serviço.';
            erro.textContent = S.erroProp;
            return;
        }
        botao.disabled = true;
        pedir('POST', ROTA.proposta(codigo), {
            valor: f.valor || '', despesas: f.despesas || '', prazoEstimado: f.prazoEst || '', validade: f.validade || '',
            pagamento: f.pagamento || '', escopo: f.escopo || '', naoInclui: f.naoInclui || ''
        }).then(function (r) {
            botao.disabled = false;
            if (!r.ok) {
                S.erroProp = r.mensagem;
                erro.textContent = S.erroProp;
                return;
            }
            S.f = {};
            S.erroProp = '';
            aplicarDemanda(r);
        });
    }

    function abaExecucao(alvo, dz) {
        alvo.appendChild(el('div', { class: 'te-secao-rot', style: { 'margin-bottom': '8px' }, text: 'CHECKLIST DE EXECUÇÃO' }));
        (dz.checklist || []).forEach(function (c, i) {
            var pode = !!dz.podeAndamento;
            alvo.appendChild(el('label', { class: 'te-check' + (pode ? ' is-pode' : '') + (c.ok ? ' is-ok' : '') }, [
                el('input', {
                    type: 'checkbox', checked: !!c.ok, disabled: !pode,
                    onchange: function () {
                        pedir('POST', ROTA.checklist(dz.codigo, i)).then(function (r) {
                            if (!r.ok) {
                                toastErro(r.mensagem);
                                renderCorpo();
                                return;
                            }
                            aplicarDemanda(r);
                        });
                    }
                }),
                el('span', { text: c.t })
            ]));
        });

        if (dz.podeAndamento) {
            var campoAnd = ctl('input', 'andamento', { class: null, placeholder: 'Registrar andamento da execução' });
            alvo.appendChild(el('div', { class: 'te-and-reg' }, [
                campoAnd,
                el('button', { type: 'button', class: 'te-btn-contorno', text: 'Registrar', onclick: function (e) { registrarAndamento(dz.codigo, e.currentTarget); } })
            ]));
        }

        if (dz.mostraConvites) {
            alvo.appendChild(el('div', { class: 'te-secao-rot te-conv-rot', text: 'PROFISSIONAIS CONVIDADOS' }));
            var convites = dz.convites || [];
            convites.forEach(function (c) {
                alvo.appendChild(el('div', { class: 'te-conv' }, [
                    el('span', { class: 'te-conv-ini', text: c.ini }),
                    el('div', { class: 'te-flex1 te-min0' }, [
                        el('div', { class: 'te-conv-nome', text: c.nome }),
                        el('div', { class: 'te-conv-info te-reticencias', text: c.email + ' · ' + c.funcao + ' · válido até ' + c.validade })
                    ]),
                    el('span', { class: 'te-conv-st', style: { color: c.stCor }, text: c.st }),
                    c.gerir ? el('button', {
                        type: 'button', class: 'te-conv-btn', title: 'Reenviar convite',
                        onclick: function (e) { gerirConvite(dz.codigo, c, 'reenviar', e.currentTarget); }
                    }, ic('bi-arrow-repeat')) : null,
                    c.gerir ? el('button', {
                        type: 'button', class: 'te-conv-btn te-conv-btn--perigo', title: 'Revogar acesso',
                        onclick: function () {
                            abrirConf(dz.codigo, {
                                k: '__revogar__', confirmacao: { titulo: 'Revogar acesso', texto: c.nome + ' perde o acesso a esta demanda imediatamente. O histórico e os arquivos enviados são preservados.', botao: 'Revogar', motivo: false, perigo: true },
                                executar: function () { return gerirConvite(dz.codigo, c, 'revogar'); }
                            });
                        }
                    }, ic('bi-person-x')) : null
                ]));
            });
            if (!convites.length) {
                alvo.appendChild(el('div', { class: 'te-nada', text: 'Nenhum profissional convidado.' }));
            }
            if (dz.podeConvidar) {
                alvo.appendChild(formularioDeConvite(dz));
            }
        }
    }

    function gerirConvite(codigo, c, operacao, botao) {
        if (botao) { botao.disabled = true; }
        return pedir('POST', ROTA.convites(codigo, '/' + c.id + '/' + operacao), {}).then(function (r) {
            if (botao) { botao.disabled = false; }
            if (!r.ok) { toastErro(r.mensagem); return r; }
            aplicarDemanda(r);
            if (operacao === 'reenviar') {
                mostrarToast({ tit: 'Convite reenviado', txt: 'Novo e-mail enviado para ' + c.email + '.', hora: '' });
            }
            return r;
        });
    }

    /** "Convidar profissional por e-mail" (desenho, aba Execução). As permissões começam todas ligadas. */
    function formularioDeConvite(dz) {
        if (!S.permsConvite) {
            S.permsConvite = {};
            (dz.permissoesDeConvite || []).forEach(function (p) { S.permsConvite[p.k] = true; });
        }
        var erro = el('span', { class: 'te-erro', text: '' });
        var rot = function (texto, controle) { return el('label', { class: 'te-rot' }, [texto, controle]); };

        return el('div', { class: 'te-caixa te-conv-form' }, [
            el('div', { class: 'te-conv-form-tit', text: 'Convidar profissional por e-mail' }),
            el('div', { class: 'te-conv-form-txt', text: 'O convidado acessa somente esta demanda: instruções, documentos autorizados, checklist e chat.' }),
            el('div', { class: 'te-conv-grade' }, [
                rot('Nome completo *', ctl('input', 'cNome')),
                rot('E-mail *', ctl('input', 'cEmail', { type: 'email' })),
                rot('Função', ctl('input', 'cFuncao', { placeholder: 'Advogado correspondente' })),
                rot('Validade do convite', ctl('input', 'cValidade', { type: 'date', min: painelHoje() }))
            ]),
            el('div', { class: 'te-conv-perms' }, (dz.permissoesDeConvite || []).map(function (p) {
                return el('label', { class: 'te-conv-perm' }, [
                    el('input', { type: 'checkbox', checked: !!S.permsConvite[p.k], onchange: function (e) { S.permsConvite[p.k] = e.target.checked; } }),
                    p.rot
                ]);
            })),
            el('div', { class: 'te-conv-rodape' }, [
                erro,
                el('button', {
                    type: 'button', class: 'te-btn-pri',
                    onclick: function (e) {
                        var nome = String(S.f.cNome || '').trim(), email = String(S.f.cEmail || '').trim();
                        if (!nome || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { erro.textContent = 'Informe nome e um e-mail válido.'; return; }
                        var botao = e.currentTarget;
                        botao.disabled = true;
                        pedir('POST', ROTA.convites(dz.codigo, ''), { nome: nome, email: email, funcao: S.f.cFuncao || '', validade: S.f.cValidade || '', permissoes: S.permsConvite }).then(function (r) {
                            botao.disabled = false;
                            if (!r.ok) { erro.textContent = r.mensagem; return; }
                            ['cNome', 'cEmail', 'cFuncao', 'cValidade'].forEach(function (k) { S.f[k] = ''; });
                            S.permsConvite = null;
                            aplicarDemanda(r);
                            mostrarToast({ tit: 'Convite enviado', txt: nome + ' recebe o link de acesso somente a esta demanda.', hora: '' });
                        });
                    }
                }, [ic('bi-envelope'), 'Enviar convite'])
            ])
        ]);
    }

    function painelHoje() { return (S.painel && S.painel.hoje) || ''; }

    function registrarAndamento(codigo, botao) {
        var t = String(S.f.andamento || '').trim();
        if (!t) {
            return;
        }
        botao.disabled = true;
        pedir('POST', ROTA.andamento(codigo), { texto: t }).then(function (r) {
            botao.disabled = false;
            if (!r.ok) {
                toastErro(r.mensagem);
                return;
            }
            S.f.andamento = '';
            aplicarDemanda(r);
        });
    }

    /* ---------------------------------------------------------------- chat avançado (spec VS 18.10.26 §3.4) */

    /** Filtros do chat e o texto de vazio de cada um (`chatFiltros` e `chatVazioTxt` do desenho). */
    var CHAT_FILTROS = [
        ['todas', 'Todas', 'bi-chat-left-text', 'Nenhuma mensagem nesta demanda.'],
        ['fav', 'Favoritas', 'bi-star', 'Nenhuma mensagem favoritada. Use a estrela para guardar as importantes.'],
        ['lix', 'Lixeira', 'bi-trash', 'A lixeira está vazia.']
    ];
    var temporizadorChatUndo = null;

    function limparChat() {
        clearTimeout(temporizadorChatUndo);
        S.chatSel = [];
        S.chatVis = 'todas';
        S.chatUndo = null;
    }

    function mensagensVisiveis(dz) {
        return (dz.mensagens || []).filter(function (m) {
            if (S.chatVis === 'lix') {
                return !!m.lixeira;
            }
            return !m.lixeira && (S.chatVis !== 'fav' || !!m.favorita);
        });
    }

    /** Redesenha só o corpo, guardando a rolagem (selecionar uma mensagem não pode jogar a conversa para o topo). */
    function redesenharChat() {
        var corpo = $('teDzCorpo');
        if (!corpo || S.aba !== 'chat') {
            return;
        }
        var topo = corpo.scrollTop;
        renderCorpo();
        corpo.scrollTop = topo;
    }

    /** Tira só a faixa de desfazer: redesenhar a aba tiraria o foco de quem está escrevendo. */
    function fecharChatUndo() {
        clearTimeout(temporizadorChatUndo);
        S.chatUndo = null;
        var faixa = document.querySelector('#teDzCorpo .te-chat-undo');
        if (faixa) {
            faixa.remove();
        }
    }

    function chatSelecionada(id) { return S.chatSel.indexOf(id) >= 0; }

    function alternarChatSel(id) {
        S.chatSel = chatSelecionada(id) ? S.chatSel.filter(function (x) { return x !== id; }) : S.chatSel.concat([id]);
        redesenharChat();
    }

    /** Operações de `_chatLote()` do desenho. "definitivo" confirma antes, com o texto do desenho. */
    function chatLote(ids, op) {
        if (!S.dz || !ids.length || previa()) {
            return;
        }
        fecharMenu();
        var codigo = S.dz.codigo;
        if (op === 'definitivo') {
            var n = ids.length;
            abrirConf(codigo, {
                k: '__definitivo_msg__',
                confirmacao: {
                    titulo: 'Excluir definitivamente',
                    texto: n === 1 ? 'Excluir definitivamente esta mensagem? Não será possível recuperar.' : 'Excluir definitivamente ' + n + ' mensagens? Não será possível recuperar.',
                    botao: 'Excluir', motivo: false, perigo: true
                },
                executar: function () { return enviarChatLote(codigo, ids, op); }
            });
            return;
        }
        enviarChatLote(codigo, ids, op);
    }

    /**
     * Grava a operação. Com `aviso` (favoritar uma mensagem só, como o `tgFav` do desenho) mostra o
     * aviso do canto e não mexe na seleção nem na faixa de desfazer.
     */
    function enviarChatLote(codigo, ids, op, aviso) {
        return pedir('POST', ROTA.marcacoesMsg(codigo), { ids: ids, op: op }).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return r;
            }
            if (aviso) {
                mostrarToast(aviso);
                aplicarDemandaNoChat(r);
                return r;
            }
            S.chatSel = [];
            clearTimeout(temporizadorChatUndo);
            // Como no desenho: excluir definitivamente não deixa aviso; só a lixeira pode ser desfeita.
            S.chatUndo = op === 'definitivo' ? null : { txt: r.texto, codigo: codigo, ids: op === 'lixeira' ? ids : null };
            if (S.chatUndo) {
                temporizadorChatUndo = setTimeout(fecharChatUndo, DESFAZER_MS);
            }
            aplicarDemandaNoChat(r);
            return r;
        });
    }

    /** Estrela de uma mensagem (botão ou menu): alterna e avisa no canto, com os textos do desenho. */
    function alternarFavorita(m) {
        if (!S.dz || previa()) {
            return;
        }
        fecharMenu();
        var aviso = m.favorita
            ? { tit: 'Removida das favoritas', txt: '', hora: '' }
            : { tit: 'Mensagem favoritada', txt: 'Veja em Favoritas no topo do chat.', hora: '' };
        enviarChatLote(S.dz.codigo, [m.id], m.favorita ? 'desfav' : 'fav', aviso);
    }

    function copiarTexto(m) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(m.txt || '');
        }
        mostrarToast({ tit: 'Texto copiado', txt: '', hora: '' });
    }

    /** Como aplicarDemanda, mas sem perder a rolagem da conversa. */
    function aplicarDemandaNoChat(r) {
        if (r.demanda && S.aberta === r.demanda.codigo) {
            S.dz = r.demanda;
            var vistos = mensagensVisiveis(S.dz).map(function (m) { return m.id; });
            S.chatSel = S.chatSel.filter(function (id) { return vistos.indexOf(id) >= 0; });
            renderCabecalho();
            renderAbas();
            redesenharChat();
        }
        recarregarPainel();
    }

    function apagarParaTodos(m) {
        if (!S.dz || previa()) {
            return;
        }
        fecharMenu();
        var codigo = S.dz.codigo;
        abrirConf(codigo, {
            k: '__apagar_msg__',
            confirmacao: {
                titulo: 'Apagar para todos',
                texto: 'Apagar esta mensagem? Ela some para todos e fica registrado que foi apagada.',
                botao: 'Apagar para todos', motivo: false, perigo: true
            },
            executar: function () {
                return pedir('POST', ROTA.apagarMsg(codigo, m.id)).then(function (r) {
                    if (!r.ok) {
                        toastErro(r.mensagem);
                        return r;
                    }
                    aplicarDemandaNoChat(r);
                    return r;
                });
            }
        });
    }

    /**
     * Menu de contexto de uma mensagem (`ctx` do desenho). Na lixeira: só restaurar e excluir. Fora:
     * selecionar, favoritar e copiar (se não apagada), lixeira e apagar para todos; quando o servidor
     * nega o apagar, mostra o motivo num item travado que só fecha o menu.
     */
    function menuDaMensagem(caixa, m) {
        var item = function (rot, icone, acao, classe) {
            caixa.appendChild(el('button', {
                type: 'button', class: 'te-menu-item' + (classe ? ' ' + classe : ''),
                onclick: function () { fecharMenu(); acao(); }
            }, [ic(icone), rot]));
        };
        if (m.lixeira) {
            caixa.appendChild(el('div', { class: 'te-menu-tit', text: 'Lixeira' }));
            item('Restaurar', 'bi-arrow-counterclockwise', function () { chatLote([m.id], 'restaurar'); });
            item('Excluir definitivamente', 'bi-x-octagon', function () { chatLote([m.id], 'definitivo'); }, 'is-perigo');
            return;
        }
        caixa.appendChild(el('div', { class: 'te-menu-tit', text: m.de + ' · ' + m.em }));
        var sel = chatSelecionada(m.id);
        item(sel ? 'Desmarcar' : 'Selecionar', sel ? 'bi-check-square-fill' : 'bi-check-square', function () { alternarChatSel(m.id); });
        if (!m.apagada) {
            item(m.favorita ? 'Remover das favoritas' : 'Favoritar', m.favorita ? 'bi-star-fill' : 'bi-star', function () { alternarFavorita(m); });
            item('Copiar texto', 'bi-clipboard', function () { copiarTexto(m); });
        }
        item('Mover para a lixeira', 'bi-trash', function () { chatLote([m.id], 'lixeira'); });
        if (m.podeApagar) {
            item('Apagar para todos', 'bi-trash-fill', function () { apagarParaTodos(m); }, 'is-perigo');
        } else if (!vazio(m.apagarBloqueado)) {
            item(m.apagarBloqueado, 'bi-lock', function () {}, 'is-travado');
        }
    }

    /** Barra do chat (desenho, linhas 559 a 564): filtros, seleção, ações do lote, aviso e desfazer. */
    function barraDoChat(alvo, dz, visiveis) {
        var pv = previa(), noLix = S.chatVis === 'lix';
        var barra = el('div', { class: 'te-chat-barra' });
        barra.appendChild(el('div', { class: 'te-seg' }, CHAT_FILTROS.map(function (x) {
            return el('button', {
                type: 'button', class: 'te-seg-btn te-chat-filtro' + (S.chatVis === x[0] ? ' is-ativo' : ''),
                attrs: { 'aria-pressed': S.chatVis === x[0] ? 'true' : 'false' },
                onclick: function () { S.chatVis = x[0]; S.chatSel = []; redesenharChat(); }
            }, [ic(x[2]), x[1]]);
        })));

        if (!pv && visiveis.length) {
            var todas = S.chatSel.length === visiveis.length;
            barra.appendChild(el('button', {
                type: 'button', class: 'te-chat-todas', title: todas ? 'Limpar seleção' : 'Selecionar todas',
                onclick: function () {
                    S.chatSel = todas ? [] : visiveis.map(function (m) { return m.id; });
                    redesenharChat();
                }
            }, ic(todas ? 'bi-check-square-fill' : S.chatSel.length ? 'bi-dash-square' : 'bi-check2-square')));
        }

        if (!pv && S.chatSel.length) {
            var ids = S.chatSel.slice();
            var grupo = el('div', { class: 'te-chat-sel' }, [
                el('b', { class: 'te-chat-sel-n', text: ids.length + (ids.length === 1 ? ' selecionada' : ' selecionadas') })
            ]);
            if (noLix) {
                grupo.appendChild(el('button', { type: 'button', class: 'te-chat-acao', onclick: function () { chatLote(ids, 'restaurar'); } }, [ic('bi-arrow-counterclockwise'), 'Restaurar']));
                grupo.appendChild(el('button', { type: 'button', class: 'te-chat-acao is-perigo', onclick: function () { chatLote(ids, 'definitivo'); } }, [ic('bi-x-octagon'), 'Excluir definitivamente']));
            } else {
                grupo.appendChild(el('button', { type: 'button', class: 'te-chat-icone is-lixeira', title: 'Mover para a lixeira', onclick: function () { chatLote(ids, 'lixeira'); } }, ic('bi-trash')));
                grupo.appendChild(el('button', { type: 'button', class: 'te-chat-icone is-fav', title: 'Favoritar', onclick: function () { chatLote(ids, 'fav'); } }, ic('bi-star')));
            }
            grupo.appendChild(el('button', { type: 'button', class: 'te-chat-cancelar', title: 'Cancelar seleção', onclick: function () { S.chatSel = []; redesenharChat(); } }, ic('bi-x-lg')));
            barra.appendChild(grupo);
        }

        barra.appendChild(el('span', { class: 'te-espaco' }));
        if (!pv && noLix && visiveis.length) {
            barra.appendChild(el('button', {
                type: 'button', class: 'te-chat-esvaziar', text: 'Esvaziar lixeira',
                onclick: function () { chatLote(visiveis.map(function (m) { return m.id; }), 'definitivo'); }
            }));
        }
        alvo.appendChild(barra);

        if (noLix) {
            alvo.appendChild(el('div', { class: 'te-lix-aviso' }, [ic('bi-info-circle'), ' As mensagens na lixeira são excluídas automaticamente depois de 30 dias. Elas somem só para você; os outros participantes continuam vendo.']));
        }
        var u = S.chatUndo;
        if (u && u.codigo === dz.codigo) {
            alvo.appendChild(el('div', { class: 'te-chat-undo', attrs: { role: 'status' } }, [
                el('span', { class: 'te-flex1', text: u.txt }),
                u.ids ? el('button', {
                    type: 'button', class: 'te-desfazer-btn', text: 'Desfazer',
                    onclick: function () {
                        clearTimeout(temporizadorChatUndo);
                        S.chatUndo = null;
                        enviarChatLote(u.codigo, u.ids, 'restaurar');
                    }
                }) : null,
                el('button', {
                    type: 'button', class: 'te-desfazer-fechar', title: 'Fechar',
                    onclick: fecharChatUndo
                }, ic('bi-x-lg'))
            ]));
        }
    }

    function mensagemDoChat(m) {
        var pv = previa(), sel = chatSelecionada(m.id);
        var classe = 'te-msg' + (m.estilo === 'sistema' ? ' is-sistema' : '') + (m.meu && m.estilo !== 'sistema' ? ' is-meu' : '') + (m.estilo === 'interna' ? ' is-interna' : '') + (sel ? ' is-sel' : '');
        var cab = el('div', { class: 'te-msg-cab' }, [
            pv ? null : el('button', {
                type: 'button', class: 'te-msg-sel' + (sel ? ' is-on' : ''), title: 'Selecionar',
                attrs: { 'aria-pressed': sel ? 'true' : 'false' },
                onclick: function () { alternarChatSel(m.id); }
            }, ic(sel ? 'bi-check-square-fill' : 'bi-square')),
            el('b', { style: { color: m.nomeCor }, text: m.de }),
            el('span', { text: m.em }),
            m.tipo ? el('span', { text: m.tipo }) : null,
            m.favorita ? el('i', { class: 'bi bi-star-fill te-msg-fav', title: 'Favorita' }) : null,
            el('span', { class: 'te-espaco' })
        ]);
        // Como o `acoes` do desenho: mensagem apagada ou na lixeira não tem ações na linha.
        if (!pv && !m.lixeira && !m.apagada) {
            cab.appendChild(el('span', { class: 'te-msg-acoes' + (m.favorita ? ' is-fav' : '') }, [
                el('button', {
                    type: 'button', class: 'te-msg-btn' + (m.favorita ? ' is-fav' : ''), title: m.favorita ? 'Remover das favoritas' : 'Favoritar',
                    onclick: function () { alternarFavorita(m); }
                }, ic(m.favorita ? 'bi-star-fill' : 'bi-star')),
                el('button', {
                    type: 'button', class: 'te-msg-btn', title: 'Mover para a lixeira',
                    onclick: function () { chatLote([m.id], 'lixeira'); }
                }, ic('bi-archive')),
                m.podeApagar ? el('button', {
                    type: 'button', class: 'te-msg-btn is-perigo', title: m.apagarDica || 'Apagar para todos',
                    onclick: function () { apagarParaTodos(m); }
                }, ic('bi-trash')) : null
            ]));
        }
        if (!pv && m.lixeira) {
            cab.appendChild(el('button', {
                type: 'button', class: 'te-msg-restaurar',
                onclick: function () { chatLote([m.id], 'restaurar'); }
            }, [ic('bi-arrow-counterclockwise'), ' Restaurar']));
        }

        return el('div', {
            class: classe,
            oncontextmenu: function (e) {
                if (pv) {
                    return;
                }
                e.preventDefault();
                abrirMenuEspecial({ tipo: 'msg', msg: m }, e.clientX, e.clientY, 230);
            }
        }, [
            cab,
            m.apagada
                ? el('div', { class: 'te-msg-apagada' }, [ic('bi-slash-circle'), m.apagadaTxt || 'Mensagem apagada'])
                : el('div', { class: 'te-msg-txt', text: m.txt })
        ]);
    }

    function abaChat(alvo, dz) {
        var visiveis = mensagensVisiveis(dz);
        var vistos = visiveis.map(function (m) { return m.id; });
        S.chatSel = S.chatSel.filter(function (id) { return vistos.indexOf(id) >= 0; });
        barraDoChat(alvo, dz, visiveis);
        var lista = el('div', { class: 'te-msgs' });
        visiveis.forEach(function (m) { lista.appendChild(mensagemDoChat(m)); });
        if (!visiveis.length) {
            var f = CHAT_FILTROS.filter(function (x) { return x[0] === S.chatVis; })[0] || CHAT_FILTROS[0];
            lista.appendChild(el('div', { class: 'te-msgs-vazio', text: f[3] }));
        }
        alvo.appendChild(lista);

        if (dz.podeNotaInterna) {
            alvo.appendChild(el('label', { class: 'te-nota' }, [
                el('input', { type: 'checkbox', checked: !!S.f.interna, onchange: function (e) { S.f.interna = e.target.checked; } }),
                ic('bi-lock'), 'Nota interna: só o Master e a equipe veem'
            ]));
        }
        if (dz.podeConversar) {
            var area = ctl('textarea', 'msg', { class: null, rows: 2, placeholder: 'Escreva uma mensagem (Enter envia)' });
            var botao = el('button', { type: 'button', title: 'Enviar', onclick: function () { enviarMensagem(dz, area, botao); } }, ic('bi-send'));
            area.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
                    e.preventDefault();
                    enviarMensagem(dz, area, botao);
                }
            });
            alvo.appendChild(el('div', { class: 'te-chat-envio' }, [area, botao]));
        }
    }

    function enviarMensagem(dz, area, botao) {
        var t = String(S.f.msg || '').trim();
        if (!t || botao.disabled) {
            return;
        }
        botao.disabled = true;
        pedir('POST', ROTA.mensagem(dz.codigo), { texto: t, interna: !!S.f.interna && !!dz.podeNotaInterna }).then(function (r) {
            botao.disabled = false;
            if (!r.ok) {
                toastErro(r.mensagem);
                return;
            }
            S.f.msg = '';
            S.chatVis = 'todas';
            aplicarDemanda(r);
            var corpo = $('teDzCorpo');
            if (corpo && S.aba === 'chat') {
                corpo.scrollTop = corpo.scrollHeight;
                var nova = corpo.querySelector('.te-chat-envio textarea');
                if (nova) {
                    nova.focus();
                }
            }
        });
    }

    function abaHistorico(alvo, dz) {
        alvo.appendChild(el('div', { class: 'te-secao-rot te-hist-rot', text: 'REGISTRO COMPLETO' }));
        (dz.historico || []).forEach(function (h, i) {
            alvo.appendChild(el('div', { class: 'te-hist', style: { animation: 'teUp .3s ease ' + (i * 0.05) + 's both' } }, [
                el('div', { class: 'te-hist-eixo' }, [
                    el('span', { class: 'te-hist-bola', style: { color: h.cor } }, ic(h.ic)),
                    el('span', { class: 'te-hist-linha' })
                ]),
                el('div', { class: 'te-hist-corpo' }, [
                    el('div', null, [el('b', { style: { color: h.cor }, text: h.para }), ' ', el('span', { class: 'te-hist-de', text: h.de })]),
                    el('div', { class: 'te-hist-quem', text: h.quem ? h.quem + ' · ' + h.em : h.em }),
                    !vazio(h.motivo) ? el('div', { class: 'te-hist-motivo', text: h.motivo }) : null
                ])
            ]));
        });
    }

    /* ---------------------------------------------------------------- aba WhatsApp (F2) */

    /** Escrita da aba WhatsApp: a resposta traz a demanda inteira, que redesenha o painel. */
    function whats(codigo, resto, corpo, botao) {
        if (botao) { botao.disabled = true; }
        return pedir('POST', ROTA.whatsapp(codigo, resto), corpo || {}).then(function (r) {
            if (botao) { botao.disabled = false; }
            if (!r.ok) { return r; }
            aplicarDemanda(r);
            return r;
        });
    }

    function abaWhatsapp(alvo, dz) {
        var wa = dz.whatsapp;
        if (!wa) { return; }
        var cod = dz.codigo;
        var ler = previa();  // pré-visualização: a aba aparece como o perfil a veria, sem nenhum controle de escrita

        alvo.appendChild(el('div', { class: 'te-wa-intro' }, [
            ic('bi-whatsapp'),
            el('div', null, [
                el('b', { text: 'Avisos de andamento pelo WhatsApp' }), el('br'),
                'A cada fase, o BlueJus prepara uma mensagem pronta com o link da demanda. O cliente toca no link e vê o andamento sem precisar procurar.'
            ])
        ]));

        alvo.appendChild(el('div', { class: 'te-secao-rot te-wa-tit' }, [
            'CONTATOS CONECTADOS', el('span', { class: 'te-flex1' }),
            wa.temAtivos && !ler ? el('button', {
                type: 'button', class: 'te-wa-agora',
                onclick: function (e) { whats(cod, '/avisar-fase-atual', {}, e.currentTarget).then(function (r) { if (!r.ok) { toastErro(r.mensagem); } }); }
            }, 'Avisar fase atual: ' + wa.faseAtual) : null
        ]));

        (wa.contatos || []).forEach(function (ct) {
            alvo.appendChild(el('div', { class: 'te-wa-contato' }, [
                el('div', { class: 'te-wa-contato-topo' }, [
                    ic('bi-person-circle'),
                    el('div', { class: 'te-flex1 te-min0' }, [el('b', { text: ct.nome }), el('div', { class: 'te-wa-tel', text: ct.tel })]),
                    el('span', { class: 'te-wa-st' + (ct.ativo ? ' is-ativo' : ''), text: ct.ativo ? 'Conectado' : 'Pausado' }),
                    ler ? null : el('button', {
                        type: 'button', class: 'te-wa-tg',
                        onclick: function (e) { whats(cod, '/contatos/' + ct.id + '/alternar', {}, e.currentTarget).then(function (r) { if (!r.ok) { toastErro(r.mensagem); } }); }
                    }, ct.ativo ? 'Pausar' : 'Reativar'),
                    ler ? null : el('button', {
                        type: 'button', class: 'te-wa-rem', title: 'Desconectar',
                        onclick: function (e) { whats(cod, '/contatos/' + ct.id + '/remover', {}, e.currentTarget).then(function (r) { if (!r.ok) { toastErro(r.mensagem); } }); }
                    }, ic('bi-trash'))
                ]),
                el('div', { class: 'te-wa-fases' }, (ct.fases || []).map(function (fa) {
                    return el('label', { class: 'te-wa-fase' }, [
                        el('input', {
                            type: 'checkbox', checked: !!fa.ok, disabled: ler,
                            onchange: function () { whats(cod, '/contatos/' + ct.id + '/fases/' + fa.k).then(function (r) { if (!r.ok) { toastErro(r.mensagem); } }); }
                        }),
                        fa.rot
                    ]);
                }))
            ]));
        });
        if (!(wa.contatos || []).length) {
            alvo.appendChild(el('div', { class: 'te-wa-sem', text: 'Nenhum contato conectado a esta demanda.' }));
        }

        var erro = el('span', { class: 'te-erro te-flex1', text: S.erroWa || '' });
        var nome = ctl('input', 'waNome', { placeholder: 'Ex.: Dr. Teste' });
        var novoContato;
        var tel = ctl('input', 'waTel', { type: 'tel', placeholder: '(82) 99999-0000' });
        var ok = el('input', { type: 'checkbox', checked: !!S.f.waOk, onchange: function (e) { S.f.waOk = e.target.checked; } });
        novoContato = el('div', { class: 'te-wa-novo' }, [
            el('div', { class: 'te-wa-grade' }, [
                el('label', { class: 'te-rot' }, ['Nome do contato', nome]),
                el('label', { class: 'te-rot' }, ['Celular com DDD', tel])
            ]),
            el('label', { class: 'te-wa-ok' }, [ok, 'O contato autorizou receber avisos desta demanda pelo WhatsApp.']),
            el('div', { class: 'te-wa-rodape' }, [
                erro,
                el('button', {
                    type: 'button', class: 'te-wa-conectar',
                    onclick: function (e) {
                        var n = String(S.f.waNome || '').trim(), t = String(S.f.waTel || '').replace(/\D/g, '');
                        if (!n || t.length < 10) { erro.textContent = 'Informe o nome e um celular com DDD.'; return; }
                        if (!S.f.waOk) { erro.textContent = 'Confirme que o contato autorizou receber mensagens.'; return; }
                        whats(cod, '/contatos', { nome: n, telefone: t, autorizou: true }, e.currentTarget).then(function (r) {
                            if (!r.ok) { erro.textContent = r.mensagem; return; }
                            S.f.waNome = ''; S.f.waTel = ''; S.f.waOk = false; S.erroWa = '';
                            renderDrawerConteudo();
                        });
                    }
                }, [ic('bi-whatsapp'), 'Conectar WhatsApp'])
            ])
        ]);
        if (!ler) {
            alvo.appendChild(novoContato);
        }

        alvo.appendChild(el('div', { class: 'te-secao-rot te-wa-tit', text: 'MENSAGENS PRONTAS PARA ENVIAR' }));
        (wa.fila || []).forEach(function (m) {
            alvo.appendChild(el('div', { class: 'te-wa-msg' }, [
                el('div', { class: 'te-wa-msg-topo' }, [el('b', { text: m.fase }), el('span', { text: 'para ' + m.para }), el('span', { class: 'te-flex1' }), m.em]),
                el('div', { class: 'te-wa-balao', text: m.txt }),
                ler ? null : el('div', { class: 'te-wa-msg-botoes' }, [
                    el('button', {
                        type: 'button', class: 'te-wa-descartar',
                        onclick: function (e) { whats(cod, '/avisos/' + m.id + '/descartar', {}, e.currentTarget).then(function (r) { if (!r.ok) { toastErro(r.mensagem); } }); }
                    }, 'Descartar'),
                    el('a', {
                        href: m.href, target: '_blank', rel: 'noopener', class: 'te-wa-enviar',
                        onclick: function () { whats(cod, '/avisos/' + m.id + '/enviado').then(function (r) { if (!r.ok) { toastErro(r.mensagem); } }); }
                    }, [ic('bi-whatsapp'), 'Enviar pelo WhatsApp'])
                ])
            ]));
        });
        if (!(wa.fila || []).length) {
            alvo.appendChild(el('div', { class: 'te-wa-sem', text: 'Nenhuma mensagem pendente. Quando a demanda mudar de fase, a mensagem aparece aqui.' }));
        }

        if ((wa.enviadas || []).length) {
            alvo.appendChild(el('div', { class: 'te-secao-rot te-wa-tit te-wa-tit--log', text: 'ENVIADAS' }));
            wa.enviadas.forEach(function (l) {
                alvo.appendChild(el('div', { class: 'te-wa-log' }, [ic('bi-check2-all'), el('b', { text: l.fase }), el('span', { class: 'te-flex1', text: 'para ' + l.para }), el('span', { class: 'te-wa-log-em', text: l.em })]));
            });
        }

        alvo.appendChild(el('div', { class: 'te-wa-link' }, [
            ic('bi-link-45deg'),
            el('span', { class: 'te-flex1 te-min0 te-reticencias', text: wa.link }),
            el('button', {
                type: 'button', class: 'te-wa-copiar',
                onclick: function () { copiar(wa.link); mostrarToast({ tit: 'Link copiado', txt: 'Envie o link para o cliente acompanhar a demanda.', hora: '' }); }
            }, 'Copiar link')
        ]));
    }

    /* ---------------------------------------------------------------- modal "Nova solicitação" */

    function dadosNova() { return S.painel.novaSolicitacao || null; }

    /** `doEsc` do desenho: o escritório escolhido preenche nome, e-mail e origem. */
    function doEsc(e, base) {
        var U = dadosNova().usuario || {};
        return Object.assign(base, {
            escId: e.id,
            escritorio: e.nome || '',
            email: e.email || U.email || '',
            telefone: e.telefone || '',
            ufOrigem: e.uf || U.ufLotacao || 'DF',
            cidadeOrigem: e.cidade || (e.uf && e.uf !== U.ufLotacao ? '' : (U.cidadeLotacao || ''))
        });
    }

    function nfVazio() {
        var N = dadosNova(), U = N.usuario || {};
        var nf = {
            escId: '', escritorio: '', email: U.email || '', telefone: '',
            ufOrigem: U.ufLotacao || 'DF', cidadeOrigem: U.cidadeLotacao || '',
            emNomeDeOutro: false, advogado: U.nome || '', oab: U.oab || '', oabUf: U.oabUf || 'DF',
            ufExecucao: 'DF', cidadeExecucao: '', orgao: '', processo: '', partes: '',
            tipoServico: (N.tipos || [])[0] || '', prioridade: 'Normal', prazoFinal: N.prazoPadrao || '', dataPretendida: '',
            descricao: '', objetivo: '', instrucoes: '', urgente: false
        };
        if ((N.escritorios || []).length) {
            doEsc(N.escritorios[0], nf);
        }
        return nf;
    }

    function editarRascunho(codigo) {
        fecharMenu();
        pedir('GET', ROTA.detalhe(codigo)).then(function (r) {
            if (!r.ok || !r.demanda || !r.demanda.rascunho) {
                toastErro(r.ok ? 'Este rascunho não pode ser editado.' : r.mensagem);
                return;
            }
            abrirNova(r.demanda.rascunho, codigo);
        });
    }

    function abrirNova(rascunho, codigo) {
        if (!dadosNova()) {
            return;
        }
        var nf = nfVazio();
        if (rascunho) {
            Object.keys(nf).forEach(function (k) {
                if (Object.prototype.hasOwnProperty.call(rascunho, k)) {
                    nf[k] = rascunho[k];
                }
            });
            var esc = (dadosNova().escritorios || []).filter(function (e) { return e.nome === rascunho.escritorio; })[0];
            if (esc) {
                nf.escId = esc.id;
            }
        }
        S.nova = { nf: nf, arqs: [], rascId: codigo || null, erro: '', enviando: false };
        renderNova();
    }

    function fecharNova() {
        S.nova = null;
        limpar($('teNova'));
    }

    function renderNova() {
        var alvo = limpar($('teNova'));
        var N = S.nova;
        if (!N) {
            return;
        }
        var P = dadosNova(), U = P.usuario || {}, nf = N.nf;
        var ufs = P.ufs || [], tipos = P.tipos || [], prios = P.prioridades || ['Normal', 'Alta', 'Urgente'];

        var campoNova = function (k, rot, extra) {
            var e = extra || {};
            var props = {
                class: 'te-ctl' + (N.erro && e.req && vazio(nf[k]) ? ' is-erro' : ''),
                attrs: { 'data-campo': k }
            };
            var controle;
            if (e.ops) {
                props.value = nf[k] || '';
                props.onchange = function (ev) { nf[k] = ev.target.value; };
                controle = el('select', props, e.ops.map(function (o) { return el('option', { value: o, text: o }); }));
            } else if (e.area) {
                props.rows = 3;
                props.placeholder = e.ph || '';
                props.value = nf[k] || '';
                props.oninput = function (ev) { nf[k] = ev.target.value; marcarErro(ev.target, k, e.req); };
                controle = el('textarea', props);
            } else {
                props.type = e.tipo || 'text';
                props.placeholder = e.ph || '';
                props.value = nf[k] || '';
                props.oninput = function (ev) { nf[k] = ev.target.value; marcarErro(ev.target, k, e.req); };
                controle = el('input', props);
            }
            return el('label', { class: 'te-rot' + (e.full ? ' is-cheio' : '') }, [rot + (e.req ? ' *' : ''), controle]);
        };

        var secao = function (titulo, campos, extra) {
            return el('div', null, [
                el('div', { class: 'te-nova-sec-tit', text: titulo }),
                extra || null,
                el('div', { class: 'te-nova-grade' }, campos)
            ]);
        };

        var resumoPerfil = (U.nome || '') + (vazio(U.oab) ? '' : ' · OAB ' + U.oab + '/' + (U.oabUf || ''));
        var blocoPerfil = el('div', { class: 'te-perfil' }, [
            el('span', { class: 'te-perfil-rot' }, [ic('bi-person-check'), 'Preenchido do seu perfil']),
            P.peloMaster ? el('span', { class: 'te-perfil-dica', text: 'Você abre como usuário do escritório escolhido; a solicitação chega à Central para análise.' }) : null,
            el('select', {
                title: 'Escritórios da sua conta',
                value: String(nf.escId),
                onchange: function (ev) {
                    var x = (P.escritorios || []).filter(function (o) { return String(o.id) === ev.target.value; })[0];
                    if (x) {
                        doEsc(x, nf);
                        renderNova();
                    }
                }
            }, (P.escritorios || []).map(function (o) { return el('option', { value: String(o.id), text: o.nome }); })),
            el('label', { class: 'te-perfil-nome' }, [
                el('input', {
                    type: 'checkbox', checked: !!nf.emNomeDeOutro,
                    onchange: function () {
                        if (!nf.emNomeDeOutro) {
                            Object.assign(nf, { emNomeDeOutro: true, advogado: '', oab: '', oabUf: nf.ufOrigem || U.oabUf || 'DF' });
                        } else {
                            Object.assign(nf, { emNomeDeOutro: false, advogado: U.nome || '', oab: U.oab || '', oabUf: U.oabUf || 'DF' });
                        }
                        renderNova();
                    }
                }),
                'Estou solicitando em nome de outro advogado',
                el('span', { class: 'te-perfil-dica', text: '(desmarcado: ' + resumoPerfil + ')' })
            ])
        ]);

        var listaArq = el('div', { class: 'te-nova-arq-lista', hidden: !N.arqs.length, text: N.arqs.map(function (a) { return a.name; }).join(' · ') });
        var bloqueado = !!S.painel.envioBloqueado;
        var erro = el('span', { class: 'te-erro', id: 'teNovaErro', text: N.erro || (bloqueado ? 'Central suspensa: salve como rascunho para enviar depois.' : '') });
        // Pelo Master a solicitação sai enviada direto: o Master não vê rascunhos de escritório (spec §9.1).
        var btnRasc = el('button', { type: 'button', class: 'te-btn-sec', text: 'Salvar rascunho', hidden: !!P.peloMaster, onclick: function () { criar(false); } });
        var btnEnviar = el('button', { type: 'button', class: 'te-btn-pri', disabled: bloqueado, onclick: function () { if (!bloqueado) { criar(true); } } }, [ic('bi-send'), 'Enviar solicitação']);
        N.botoes = [btnRasc, btnEnviar];

        var corpo = el('div', { class: 'te-nova-corpo', id: 'teNovaCorpo' }, [
            secao('SOLICITANTE', [
                campoNova('escritorio', 'Escritório', { req: 1 }),
                campoNova('email', 'E-mail', { req: 1, tipo: 'email' }),
                campoNova('telefone', 'Telefone', { tipo: 'tel' }),
                campoNova('ufOrigem', 'UF de origem', { ops: ufs }),
                campoNova('cidadeOrigem', 'Município de origem'),
                campoNova('advogado', nf.emNomeDeOutro ? 'Advogado solicitante' : 'Advogado responsável', { req: 1, ph: 'Nome completo' }),
                campoNova('oab', 'OAB nº', { req: 1, ph: '00000' }),
                campoNova('oabUf', 'UF da OAB', { ops: ufs })
            ], blocoPerfil),
            secao('LOCAL E PROCESSO', [
                campoNova('ufExecucao', 'UF de execução', { ops: ufs }),
                campoNova('cidadeExecucao', 'Município de execução', { req: 1 }),
                campoNova('orgao', 'Comarca, tribunal, delegacia ou órgão'),
                campoNova('processo', 'Número do processo', { ph: '0000000-00.0000.0.00.0000' }),
                campoNova('partes', 'Partes', { full: 1 })
            ]),
            secao('SERVIÇO', [
                campoNova('tipoServico', 'Tipo de serviço', { ops: tipos }),
                campoNova('prioridade', 'Prioridade', { ops: prios }),
                campoNova('prazoFinal', 'Prazo final', { req: 1, tipo: 'date' }),
                campoNova('dataPretendida', 'Data e horário pretendidos', { tipo: 'datetime-local' }),
                campoNova('descricao', 'Descrição detalhada da atividade', { req: 1, area: 1, full: 1 }),
                campoNova('objetivo', 'Objetivo da diligência', { area: 1, full: 1 }),
                campoNova('instrucoes', 'Instruções e observações', { area: 1, full: 1 })
            ]),
            el('label', { class: 'te-urgente' }, [
                el('input', { type: 'checkbox', checked: !!nf.urgente, onchange: function (ev) { nf.urgente = ev.target.checked; } }),
                'Há risco de perda de prazo (urgente)'
            ]),
            el('label', { class: 'te-rot te-nova-arq' }, [
                'Documentos',
                el('input', {
                    type: 'file', multiple: true,
                    onchange: function (ev) {
                        N.arqs = N.arqs.concat(Array.prototype.slice.call(ev.target.files || []));
                        listaArq.textContent = N.arqs.map(function (a) { return a.name; }).join(' · ');
                        listaArq.hidden = !N.arqs.length;
                    }
                })
            ]),
            listaArq
        ]);

        alvo.appendChild(el('div', { class: 'te-modal-fundo te-nova-fundo' }, el('div', { class: 'te-nova', attrs: { role: 'dialog', 'aria-modal': 'true' } }, [
            el('div', { class: 'te-modal-cab' }, [
                ic('bi-plus-square'),
                el('b', { text: 'Nova solicitação de trabalho externo' }),
                el('button', { type: 'button', class: 'te-modal-fechar', title: 'Fechar', onclick: fecharNova }, ic('bi-x-lg'))
            ]),
            corpo,
            el('div', { class: 'te-nova-rodape' }, [erro, btnRasc, btnEnviar])
        ])));
    }

    /** Como o desenho: com o erro à vista, a borda vermelha some assim que o campo deixa de estar vazio. */
    function marcarErro(controle, k, req) {
        if (req && S.nova && S.nova.erro) {
            controle.classList.toggle('is-erro', vazio(S.nova.nf[k]));
        }
    }

    function mostrarErroNova() {
        var N = S.nova;
        $('teNovaErro').textContent = N.erro;
        Array.prototype.forEach.call($('teNovaCorpo').querySelectorAll('[data-campo]'), function (c) {
            var k = c.getAttribute('data-campo');
            c.classList.toggle('is-erro', !!N.erro && OBRIGATORIOS.indexOf(k) >= 0 && vazio(N.nf[k]));
        });
    }

    function criar(enviar) {
        var N = S.nova;
        if (!N || N.enviando) {
            return;
        }
        if (enviar && OBRIGATORIOS.some(function (k) { return vazio(N.nf[k]); })) {
            N.erro = 'Preencha os campos obrigatórios marcados com *.';
            mostrarErroNova();
            return;
        }
        var dados = Object.assign({}, N.nf);
        delete dados.escId;
        var fd = new FormData();
        fd.append('dados', JSON.stringify(dados));
        fd.append('enviar', enviar ? '1' : '0');
        fd.append('rascunho', N.rascId || '');
        if (dadosNova().peloMaster) {
            fd.append('escritorioId', String(N.nf.escId || ''));
        }
        N.arqs.forEach(function (a) { fd.append('documentos[]', a, a.name); });

        N.enviando = true;
        N.botoes.forEach(function (b) { b.disabled = true; });
        pedir('POST', D.urlSolicitacao, fd).then(function (r) {
            if (S.nova !== N) {
                return;
            }
            N.enviando = false;
            N.botoes[0].disabled = false;
            N.botoes[1].disabled = !!S.painel.envioBloqueado;
            if (!r.ok) {
                N.erro = r.mensagem;
                mostrarErroNova();
                return;
            }
            fecharNova();
            if (r.aviso) {
                mostrarToast({ tit: r.aviso.titulo, txt: r.aviso.texto, hora: '' });
            }
            if (S.aberta && S.aberta === N.rascId) {
                abrir(S.aberta, S.aba);
            }
            recarregarPainel();
        });
    }

    /* ---------------------------------------------------------------- modal de confirmação */

    function abrirConf(codigo, b) {
        S.conf = { codigo: codigo, b: b };
        var c = b.confirmacao, alvo = limpar($('teConf'));
        var motivo = c.motivo ? el('textarea', { class: 'te-conf-motivo', rows: 3, placeholder: 'Descreva (obrigatório)' }) : null;
        var ok = el('button', {
            type: 'button', class: 'te-btn-pri' + (c.perigo ? ' is-perigo' : ''), text: c.botao,
            onclick: function () {
                var m = motivo ? motivo.value.trim() : '';
                if (motivo && !m) {
                    motivo.focus();
                    return;
                }
                ok.disabled = true;
                if (b.executar) {
                    // Confirmação que não é ação de fluxo (ex.: revogar convite): fecha ao concluir.
                    b.executar(m || null).then(function (r) { ok.disabled = false; if (r && r.ok) { fecharConf(); } });
                    return;
                }
                aplicarAcao(codigo, b.k, m || null).then(function () { ok.disabled = false; });
            }
        });
        alvo.appendChild(el('div', { class: 'te-modal-fundo te-conf-fundo' }, el('div', { class: 'te-conf', attrs: { role: 'dialog', 'aria-modal': 'true' } }, [
            el('div', { class: 'te-conf-tit', text: c.titulo }),
            el('div', { class: 'te-conf-txt', text: c.texto }),
            motivo,
            el('div', { class: 'te-conf-botoes' }, [
                el('button', { type: 'button', class: 'te-btn-sec', text: 'Cancelar', onclick: fecharConf }),
                ok
            ])
        ])));
        if (motivo) {
            motivo.focus();
        }
    }

    function fecharConf() {
        S.conf = null;
        limpar($('teConf'));
    }

    /* ---------------------------------------------------------------- modal "Equipe e privacidade" */

    function abrirCfg() {
        var c = cfg(), alvo = limpar($('teCfg'));
        var equipe = c.equipe || {}, priv = c.privacidade || {};
        S.cfgAberta = true;

        var opcao = function (grupo, chave, rot, txt, valor) {
            var caixa = el('input', {
                type: 'checkbox', checked: !!valor,
                onchange: function (e) {
                    var corpo = {};
                    corpo[grupo] = {};
                    corpo[grupo][chave] = e.target.checked;
                    gravarCfg(corpo, function () { e.target.checked = !e.target.checked; });
                }
            });
            return txt
                ? el('label', { class: 'te-cfg-op is-priv' }, [caixa, el('span', null, [el('span', { text: rot }), el('span', { class: 'te-cfg-op-txt', text: txt })])])
                : el('label', { class: 'te-cfg-op' }, [caixa, rot]);
        };

        var espera = null;
        var corpo = el('div', { class: 'te-cfg-corpo' }, [
            c.visibilidade === 'oculta' ? el('div', null, [
                el('div', { class: 'te-cfg-sec is-perigo', text: 'ABA SUSPENSA · MOTIVO EXIBIDO AOS USUÁRIOS' }),
                el('input', {
                    class: 'te-cfg-motivo', value: c.motivo || '', placeholder: 'Opcional',
                    oninput: function (e) {
                        var v = e.target.value;
                        clearTimeout(espera);
                        espera = setTimeout(function () { gravarCfg({ motivo: v }); }, 500);
                    }
                })
            ]) : null,
            el('div', null, [
                el('div', { class: 'te-cfg-sec', text: 'EQUIPE DO MASTER (SECUNDÁRIOS)' }),
                el('div', { class: 'te-cfg-dica', text: 'O que os colaboradores da sua conta podem fazer nesta aba.' })
            ].concat([
                ['ver', 'Ver as demandas'], ['chat', 'Responder no chat'], ['proposta', 'Elaborar e enviar propostas'],
                ['convidar', 'Convidar e revogar profissionais'], ['situacao', 'Alterar a situação das demandas']
            ].map(function (o) { return opcao('equipe', o[0], o[1], null, equipe[o[0]]); }))),
            el('div', null, [
                el('div', { class: 'te-cfg-sec is-priv', text: 'PRIVACIDADE PARA CONTAS EXTERNAS' })
            ].concat([
                ['nomeResp', 'Mostrar aos externos o nome de quem atende', 'Desligado: aparece "Central BlueJus".'],
                ['contatoConv', 'Mostrar ao convidado o contato do solicitante', 'Desligado: o convidado fala só pelo chat.']
            ].map(function (o) { return opcao('privacidade', o[0], o[1], o[2], priv[o[0]]); })).concat([
                el('div', { class: 'te-cfg-sempre' }, [ic('bi-lock'), 'Sempre protegido: notas internas, convites, permissões e dados de outras contas nunca aparecem para solicitantes ou convidados.'])
            ])),
            el('label', { class: 'te-cfg-bloq' }, [
                el('input', {
                    type: 'checkbox', checked: !!c.bloquearSuspenso,
                    onchange: function (e) { gravarCfg({ bloquearSuspenso: e.target.checked }, function () { e.target.checked = !e.target.checked; }); }
                }),
                'Com a central suspensa, aceitar só rascunhos de novas solicitações'
            ])
        ]);

        alvo.appendChild(el('div', { class: 'te-cfg-fundo', onclick: fecharCfg }));
        alvo.appendChild(el('div', { class: 'te-cfg', attrs: { role: 'dialog', 'aria-modal': 'true' } }, [
            el('div', { class: 'te-modal-cab' }, [
                ic('bi-shield-lock'),
                el('b', { text: 'Equipe e privacidade · Trabalho Externo' }),
                el('button', { type: 'button', class: 'te-modal-fechar', title: 'Fechar', onclick: fecharCfg }, ic('bi-x-lg'))
            ]),
            corpo
        ]));
    }

    function fecharCfg() {
        S.cfgAberta = false;
        limpar($('teCfg'));
    }

    /* =============================================================================
       ABA VAGAS (spec VS 18.10.26 §4.1, fase F2a)
       Desenho "06 - Trabalho Externo - VS 18.10.26": faixa `mods` (linhas 85 a 87), aba Vagas
       (152 a 169), modal genérico `tlm` (230 a 234 e 372 a 381) com o formulário `tmVagaForm`
       (235 a 253), o detalhe `tmVaga` (285 a 300, sem a candidatura: F2b) e a configuração
       `tmCfgV` (363 a 369). Compartilhar vagas (F2c) e Currículos (F3) ficam de fora.
       ============================================================================= */

    /* ---------------------------------------------------------------- módulos: Demandas | Vagas */

    var MODULO_CHAVE = 'bj-te-modulo';
    var MODULOS = [['demandas', 'Demandas', 'bi-inbox'], ['vagas', 'Vagas', 'bi-megaphone']];

    function moduloSalvo() {
        try {
            return window.sessionStorage.getItem(MODULO_CHAVE) === 'vagas' ? 'vagas' : 'demandas';
        } catch (e) {
            return 'demandas';
        }
    }

    function salvarModulo(m) {
        try {
            window.sessionStorage.setItem(MODULO_CHAVE, m);
        } catch (e) {
            // Sem sessionStorage (modo privado, cota cheia): só não lembra a aba.
        }
    }

    function veVagas() { return !!(S.vg && S.vg.veAba); }
    function moduloAtual() { return S.modulo === 'vagas' && veVagas() ? 'vagas' : 'demandas'; }

    /** A faixa só aparece a quem vê a aba Vagas (`veAba`); com a tela bloqueada, nenhum painel aparece. */
    function renderModulos() {
        var alvo = limpar($('teModulos'));
        var bloq = !!S.painel.bloqueio, mod = moduloAtual();
        $('teLiberado').hidden = bloq || mod !== 'demandas';
        $('teVagas').hidden = bloq || mod !== 'vagas';
        if (bloq || !veVagas()) {
            return;
        }
        alvo.appendChild(el('div', { class: 'te-mods', attrs: { role: 'tablist', 'aria-label': 'Módulos do Trabalho Externo' } }, MODULOS.map(function (m) {
            var on = mod === m[0];
            return el('button', {
                type: 'button', class: 'te-mod' + (on ? ' is-ativo' : ''),
                attrs: { role: 'tab', 'aria-selected': on ? 'true' : 'false' },
                onclick: function () { irModulo(m[0]); }
            }, [ic(m[2]), m[1]]);
        })));
    }

    function irModulo(m) {
        if (m === moduloAtual()) {
            return;
        }
        S.modulo = m;
        salvarModulo(m);
        fecharMenu();
        renderModulos();
        if (m === 'vagas') {
            renderVagas();
            carregarVagas();
        }
    }

    /** GET /api/vagas no recorte do perfil (com `?como=` na prévia). Resposta pedida por outro perfil é descartada. */
    function carregarVagas() {
        var como = S.como;
        return pedir('GET', ROTA.vagas()).then(function (r) {
            if (como !== S.como) {
                return;
            }
            if (!r.ok || !r.vagas) {
                if (moduloAtual() === 'vagas') {
                    toastErro(r.mensagem);
                }
                return;
            }
            S.vg = r.vagas;
            // O cronômetro conta no relógio do servidor: guarda a diferença para o do navegador.
            var agora = Date.parse(r.vagas.agora || '');
            S.vgRelogio = isNaN(agora) ? 0 : agora - Date.now();
            renderModulos();
            renderVagas();
            agendarFimDaDivulgacao();
        });
    }

    /**
     * O `tlTick()` do desenho também encerra a vaga quando o período de divulgação acaba: aqui, uma
     * recarga no próximo fim (em até 24 h), para os botões não ficarem oferecendo ação vencida.
     */
    var temporizadorFimDivulgacao = null;
    function agendarFimDaDivulgacao() {
        clearTimeout(temporizadorFimDivulgacao);
        var agora = Date.now() + (S.vgRelogio || 0);
        var proximo = vagas().reduce(function (min, v) {
            var fim = v.situacao === 'aberta' ? Date.parse(v.fimDivulgacao || '') : NaN;
            return !isNaN(fim) && fim > agora && fim < min ? fim : min;
        }, agora + 864e5);
        if (proximo < agora + 864e5) {
            temporizadorFimDivulgacao = setTimeout(carregarVagas, proximo - agora + 1000);
        }
    }

    /* ---------------------------------------------------------------- dados da aba */

    function vagas() { return (S.vg && S.vg.vagas) || []; }
    function listasVg() { return (S.vg && S.vg.listas) || {}; }
    function cfgVagas() { return (S.vg && S.vg.configuracao) || null; }
    /** Prévia do Master: nada de escrita (sem Nova vaga, Divulgar, botões nem marcações). */
    function podeEscreverVaga() { return !previa(); }

    /** Filtros da barra (`vagaFiltros`). Rótulos e agrupamento provisórios até o zip. */
    var VG_ENCERRADAS = ['reprovada', 'ocupada', 'expirada', 'encerrada'];
    var VG_FILTROS = [
        ['abertas', 'Abertas', function (v) { return v.situacao === 'aberta'; }],
        ['pendente', 'Aguardando aprovação', function (v) { return v.situacao === 'pendente'; }],
        ['pausadas', 'Pausadas', function (v) { return v.situacao === 'pausada'; }],
        ['encerradas', 'Encerradas', function (v) { return VG_ENCERRADAS.indexOf(v.situacao) >= 0; }],
        ['lixeira', 'Lixeira', null]
    ];

    function doFiltroVaga(f) {
        return vagas().filter(function (v) { return f[2] ? !v.lixeira && f[2](v) : !!v.lixeira; });
    }

    /** "Aguardando aprovação" só aparece quando há alguma. */
    function filtrosDeVaga() {
        return VG_FILTROS.filter(function (f) { return (f[0] !== 'pendente' && f[0] !== 'pausadas') || doFiltroVaga(f).length > 0; });
    }

    function filtroDeVaga() {
        var vis = filtrosDeVaga();
        return vis.filter(function (f) { return f[0] === S.vgFiltro; })[0] || vis[0];
    }

    function vagasFiltradas() {
        var q = S.vgBusca.trim().toLowerCase();
        return doFiltroVaga(filtroDeVaga()).filter(function (v) {
            return !q || [v.codigo, v.titulo, v.cargo, v.contratacao, v.local, v.escritorio, (v.areas || []).join(' ')].join(' ').toLowerCase().indexOf(q) >= 0;
        });
    }

    function irFiltroVaga(k) {
        S.vgFiltro = k;
        S.vgSel = [];
        renderVagas();
    }

    /** A vaga guarda só sim, não ou sem marca: viram os níveis 3, 0 e 1 de IMPORTANCIA. Provisório até o zip. */
    function nivelDaVaga(v) { return v.importante === true ? 3 : v.importante === false ? 0 : 1; }
    function vagaImportante(v) { return v.importante === true; }

    /** "Selecionar por" da aba (`vgBulk.ops`), sempre dentro do filtro atual. Provisório até o zip. */
    var VG_SEL_OPS = [
        ['Todas', function () { return true; }],
        ['Nenhuma', function () { return false; }],
        ['Vistas', function (v) { return !!v.vista; }],
        ['Não vistas', function (v) { return !v.vista; }],
        ['Com estrela', function (v) { return !!v.estrela; }],
        ['Sem estrela', function (v) { return !v.estrela; }],
        ['Importantes', vagaImportante]
    ];

    function vgSelecionada(codigo) { return S.vgSel.indexOf(codigo) >= 0; }

    function alternarVgSel(codigo) {
        S.vgSel = vgSelecionada(codigo) ? S.vgSel.filter(function (c) { return c !== codigo; }) : S.vgSel.concat([codigo]);
        renderVagas();
    }

    function prazoDaVaga(v) { return v.prazo ? 'Inscrições até ' + dataBr(v.prazo) : 'Sem prazo'; }

    function seloSituacao(rotulo, cor) {
        return el('span', { class: 'te-vg-st', style: { color: cor } }, [ic('bi-circle-fill'), rotulo]);
    }

    /* ---------------------------------------------------------------- barra, lote e grade */

    function renderVagas() {
        var L = vagasFiltradas();
        // Só age sobre o que está à vista: a seleção perde o que saiu do filtro, da busca ou da lista.
        var vistos = L.map(function (v) { return v.codigo; });
        S.vgSel = S.vgSel.filter(function (c) { return vistos.indexOf(c) >= 0; });
        renderVgSel(L);
        renderVgFiltros();
        renderVgAcoes();
        renderVgLote();
        renderVgGrade(L);
    }

    /** Selecionar todas, "Selecionar por" e Atualizar (desenho, linha 153). */
    function renderVgSel(L) {
        var alvo = limpar($('teVgSel'));
        if (podeEscreverVaga()) {
            var todas = L.length > 0 && S.vgSel.length === L.length;
            alvo.appendChild(el('button', {
                type: 'button', class: 'te-sel-btn' + (S.vgSel.length ? ' is-on' : ''), title: 'Selecionar',
                attrs: { 'aria-label': todas ? 'Limpar seleção' : 'Selecionar todas' },
                onclick: function () {
                    S.vgSel = todas ? [] : L.map(function (v) { return v.codigo; });
                    renderVagas();
                }
            }, ic(todas ? 'bi-check-square-fill' : S.vgSel.length ? 'bi-dash-square-fill' : 'bi-square')));
            alvo.appendChild(el('button', {
                type: 'button', class: 'te-sel-caret', title: 'Selecionar por', attrs: { 'aria-haspopup': 'menu' },
                onclick: function (e) {
                    var r = e.currentTarget.getBoundingClientRect();
                    abrirMenuEspecial({ tipo: 'vgselpor' }, r.left - 30, r.bottom + 4, 170);
                }
            }, ic('bi-caret-down-fill')));
        }
        alvo.appendChild(el('button', {
            type: 'button', class: 'te-sel-atualizar', title: 'Atualizar',
            onclick: function () { carregarVagas(); }
        }, ic('bi-arrow-clockwise')));
    }

    function renderVgFiltros() {
        var alvo = limpar($('teVgFiltros')), atual = filtroDeVaga();
        filtrosDeVaga().forEach(function (f) {
            var on = f === atual;
            alvo.appendChild(el('button', {
                type: 'button', class: 'te-vg-filtro' + (on ? ' is-ativo' : ''),
                attrs: { 'aria-pressed': on ? 'true' : 'false' },
                onclick: function () { irFiltroVaga(f[0]); }
            }, [f[1] + ' ', el('span', { class: 'te-vg-filtro-n', text: doFiltroVaga(f).length })]));
        });
    }

    /** "Divulgar uma vaga" (podeDivulgar), "Nova vaga" (podeCriar) e, para o Master, a engrenagem da configuração (lugar provisório). */
    function renderVgAcoes() {
        var alvo = limpar($('teVgAcoes')), V = S.vg || {};
        if (!podeEscreverVaga()) {
            return;
        }
        // "Compartilhar vagas" (`temAbertas` / `shareTodas`, desenho linha 153).
        if (vagasCompartilhaveis(vagas()).length) {
            var dicaShare = 'Compartilhe as vagas abertas por WhatsApp, e-mail ou LinkedIn';
            alvo.appendChild(el('button', {
                type: 'button', class: 'te-vg-share', title: dicaShare, attrs: { 'data-tip': dicaShare },
                onclick: compartilharTodas
            }, [ic('bi-share'), 'Compartilhar vagas']));
        }
        if (V.podeDivulgar) {
            var dica ='Envie a vaga do seu escritório. A equipe BlueJus revisa e divulga na Central.';
            alvo.appendChild(el('button', {
                type: 'button', class: 'te-vg-divulgar', title: dica, attrs: { 'data-tip': dica },
                onclick: function () { abrirFormVaga('divulgar'); }
            }, [ic('bi-megaphone'), 'Divulgar uma vaga']));
        }
        if (V.podeCriar) {
            alvo.appendChild(el('button', { type: 'button', class: 'te-btn-nova', onclick: function () { abrirFormVaga('criar'); } }, [ic('bi-plus-lg'), 'Nova vaga']));
        }
        if (cfgVagas()) {
            alvo.appendChild(el('button', {
                type: 'button', class: 'te-btn-icone', title: 'Configurar vagas', attrs: { 'aria-label': 'Configurar vagas' },
                onclick: abrirCfgVagas
            }, ic('bi-gear')));
        }
    }

    /** Barra de lote (`vgBulk`, linha 154) com as operações das demandas; ações provisórias até o zip. */
    function renderVgLote() {
        var alvo = limpar($('teVgLote'));
        var naLixeira = filtroDeVaga()[0] === 'lixeira';
        if (S.vgSel.length && podeEscreverVaga()) {
            var sel = vagas().filter(function (v) { return vgSelecionada(v.codigo); });
            var todasEstrela = sel.length > 0 && sel.every(function (v) { return !!v.estrela; });
            var todasImp = sel.length > 0 && sel.every(vagaImportante);
            var acoes = naLixeira
                ? [
                    ['restaurar', 'Restaurar', 'bi-arrow-counterclockwise', null],
                    ['definitivo', 'Excluir definitivamente', 'bi-x-octagon', '#a3232b']
                ]
                : [
                    ['lixeira', 'Mover para a lixeira', 'bi-trash', null],
                    ['lida', 'Marcar como vista', 'bi-envelope-open', null],
                    ['naolida', 'Marcar como não vista', 'bi-envelope', null],
                    todasEstrela ? ['semestrela', 'Tirar estrela', 'bi-star', null] : ['estrela', 'Marcar com estrela', 'bi-star-fill', '#e0a106'],
                    todasImp ? ['naoimp', 'Marcar como não importante', 'bi-bookmark-x', null] : ['imp', 'Marcar como importante', 'bi-bookmark-star-fill', '#b07f00']
                ];
            var codigos = S.vgSel.slice();
            alvo.appendChild(el('div', { class: 'te-lote', attrs: { role: 'toolbar', 'aria-label': 'Ações nas vagas selecionadas' } }, [
                el('b', { class: 'te-lote-n', text: S.vgSel.length + (S.vgSel.length === 1 ? ' selecionada' : ' selecionadas') })
            ].concat(acoes.map(function (a) {
                return el('button', {
                    type: 'button', class: 'te-lote-btn', title: a[1],
                    style: a[3] ? { color: a[3] } : null,
                    attrs: { 'data-tip': a[1], 'aria-label': a[1] },
                    onclick: function () { loteVagas(codigos, a[0]); }
                }, ic(a[2]));
            })).concat([
                el('span', { class: 'te-espaco' }),
                el('button', { type: 'button', class: 'te-lote-cancelar', text: 'Cancelar', onclick: function () { S.vgSel = []; renderVagas(); } })
            ])));
        }
        if (naLixeira) {
            alvo.appendChild(el('div', { class: 'te-lix-aviso' }, [ic('bi-info-circle'), ' A lixeira é só sua: os itens continuam para os demais. Restaure quando quiser.']));
        }
    }

    function renderVgGrade(L) {
        var grade = limpar($('teVgGrade')), alvoVazio = limpar($('teVgVazio'));
        relogiosGrade = [];
        L.forEach(function (v) { grade.appendChild(cartaoVaga(v)); });
        grade.hidden = !L.length;
        if (!L.length) {
            alvoVazio.appendChild(vazioDeVagas());
        }
        ligarRelogio();
    }

    /** Vazio (`vagasVazio`, linha 155). Os textos são provisórios: o `renderVals()` que os dizia se perdeu. */
    function vazioDeVagas() {
        var V = S.vg || {}, f = filtroDeVaga()[0], tit, txt;
        if (!vagas().length) {
            tit = 'Nenhuma vaga por aqui ainda';
            txt = podeEscreverVaga() && V.podeCriar
                ? 'Use "Nova vaga" para publicar uma vaga na Central.'
                : podeEscreverVaga() && V.podeDivulgar
                    ? 'Use "Divulgar uma vaga" para enviar a vaga do seu escritório. A equipe BlueJus revisa e divulga na Central.'
                    : 'Quando houver vagas abertas na Central, elas aparecem aqui.';
        } else if (f === 'lixeira' && !S.vgBusca.trim()) {
            tit = 'A lixeira está vazia';
            txt = 'As vagas que você mover para a lixeira aparecem aqui.';
        } else {
            tit = 'Nenhuma vaga neste filtro';
            txt = 'Troque o filtro ou a busca para ver outras vagas.';
        }
        return el('div', { class: 'te-vg-vazio' }, [
            ic('bi-briefcase'),
            el('div', { class: 'te-vg-vazio-tit', text: tit }),
            el('div', { class: 'te-vg-vazio-txt', text: txt })
        ]);
    }

    /** Cartão da vaga (desenho, linhas 158 a 166). O clique abre o detalhe; o botão direito, o menu. */
    function cartaoVaga(v) {
        var sel = vgSelecionada(v.codigo), pv = previa();
        var I = IMPORTANCIA[nivelDaVaga(v)];
        var dica = v.importante === true ? 'Importante' : v.importante === false ? 'Não importante' : 'Marcar como importante';
        var parar = function (fn) { return function (e) { e.stopPropagation(); if (fn) { fn(); } }; };
        var linhaCargo = [v.cargo, v.contratacao].filter(function (x) { return !vazio(x); }).join(' · ');

        return el('div', {
            class: 'te-vg-card' + (sel ? ' is-sel' : ''),
            attrs: { 'data-codigo': v.codigo },
            onclick: function () { abrirVaga(v.codigo); },
            oncontextmenu: function (e) {
                e.preventDefault();
                abrirMenuEspecial({ tipo: 'vaga', codigo: v.codigo }, e.clientX, e.clientY, 250);
            }
        }, [
            el('div', { class: 'te-vg-topo' }, [
                el('span', { class: 'te-vg-ic' }, ic('bi-briefcase')),
                el('div', { class: 'te-flex1 te-min0' }, [
                    el('div', { class: 'te-vg-tit te-reticencias' + (v.vista ? '' : ' is-naovista'), text: v.titulo }),
                    el('div', { class: 'te-vg-esc te-reticencias', text: v.escritorio })
                ]),
                seloSituacao(v.situacaoRotulo, v.situacaoCor)
            ]),
            v.pendente ? el('div', { class: 'te-vg-pend' }, [ic('bi-hourglass-split'), 'Aguardando aprovação da Central']) : null,
            el('div', { class: 'te-vg-marcas' }, [
                el('div', { class: 'te-vg-marca', onclick: parar(null) }, [
                    pv ? null : el('button', {
                        type: 'button', class: 'te-sel-btn' + (sel ? ' is-on' : ''), title: 'Selecionar',
                        attrs: { 'aria-pressed': sel ? 'true' : 'false' },
                        onclick: parar(function () { alternarVgSel(v.codigo); })
                    }, ic(sel ? 'bi-check-square-fill' : 'bi-square')),
                    el('button', {
                        type: 'button', class: 'te-estrela' + (v.estrela ? ' is-on' : ''), title: v.estrela ? 'Tirar estrela' : 'Estrela', disabled: pv,
                        attrs: { 'aria-pressed': v.estrela ? 'true' : 'false' },
                        onclick: parar(function () { loteVagas([v.codigo], v.estrela ? 'semestrela' : 'estrela'); })
                    }, ic(v.estrela ? 'bi-star-fill' : 'bi-star')),
                    el('button', {
                        type: 'button', class: 'te-imp te-vg-imp', title: dica, disabled: pv,
                        style: { background: I.bg, color: I.cor },
                        attrs: { 'data-tip': dica, 'aria-label': dica },
                        onclick: parar(function () { loteVagas([v.codigo], vagaImportante(v) ? 'naoimp' : 'imp'); })
                    }, ic(I.ic))
                ]),
                v.oferta ? el('span', { class: 'te-vg-vivo' }, [el('span', { class: 'te-vg-vivo-pt' }), 'AO VIVO']) : null,
                v.nova ? el('span', { class: 'te-vg-nova', text: 'NOVA' }) : null
            ]),
            el('div', { class: 'te-vg-info' }, [
                el('span', null, [ic('bi-person-badge'), linhaCargo]),
                el('span', null, [ic('bi-geo-alt'), v.local || '']),
                el('span', null, [ic('bi-cash-stack'), v.remuneracao || ''])
            ]),
            (v.areas || []).length ? el('div', { class: 'te-vg-areas' }, v.areas.map(function (a) { return el('span', { class: 'te-vg-area', text: a }); })) : null,
            v.oferta && v.expiraEm ? relogioDaVaga(v, false, relogiosGrade) : null,
            el('div', { class: 'te-vg-rodape' }, [ic('bi-calendar3'), el('span', { class: 'te-flex1', text: prazoDaVaga(v) })])
        ]);
    }

    /* ---------------------------------------------------------------- cronômetro da oferta (tlTick, 1 s) */

    var relogiosGrade = [], relogiosDetalhe = [], temporizadorRelogio = null, zerados = {};

    /** `mmss()` do desenho. */
    function mmss(ms) {
        var t = Math.ceil(Math.max(0, ms) / 1000), m = Math.floor(t / 60), s = t % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    function agoraServidor() { return Date.now() + (S.vgRelogio || 0); }

    /** Contador mm:ss até `expiraEm` e barra proporcional ao tempo que resta desde a publicação. */
    function relogioDaVaga(v, grande, lista) {
        var r = {
            codigo: v.codigo,
            fim: Date.parse(v.expiraEm || ''),
            ini: Date.parse(v.publicadaEm || ''),
            ic: ic('bi-stopwatch'),
            tmr: el('b', { class: 'te-vg-tmr' }),
            barra: el('div', { class: 'te-vg-pct' })
        };
        if (isNaN(r.fim)) {
            return null;
        }
        lista.push(r);
        atualizarRelogio(r);
        var txt = 'restantes da oferta relâmpago' + (grande && +v.qtd > 1 ? ' · ' + v.qtd + ' vagas' : '');
        var trilho = el('div', { class: 'te-vg-trilho' }, r.barra);
        if (grande) {
            return el('div', { class: 'te-vg-relogio is-grande' }, [
                r.ic,
                el('div', { class: 'te-flex1 te-min0' }, [el('div', { class: 'te-vg-relogio-linha' }, [r.tmr, el('span', { text: txt })]), trilho])
            ]);
        }
        return el('div', { class: 'te-vg-relogio' }, [
            el('div', { class: 'te-vg-relogio-linha' }, [r.ic, r.tmr, el('span', { class: 'te-flex1 te-reticencias', text: txt })]),
            trilho
        ]);
    }

    /** Cor da barra: verde, laranja no último meio e vermelha no último quinto. Provisório até o zip. */
    function atualizarRelogio(r) {
        var resta = Math.max(0, r.fim - agoraServidor());
        var total = !isNaN(r.ini) && r.fim > r.ini ? r.fim - r.ini : 0;
        var pct = total ? Math.max(0, Math.min(1, resta / total)) : (resta > 0 ? 1 : 0);
        var cor = pct > 0.5 ? '#1e7b45' : pct > 0.2 ? '#c76a12' : '#a3232b';
        r.tmr.textContent = mmss(resta);
        r.tmr.style.color = cor;
        r.ic.style.color = cor;
        r.barra.style.background = cor;
        r.barra.style.width = (pct * 100).toFixed(2) + '%';
        return resta;
    }

    function ligarRelogio() {
        if (!temporizadorRelogio && (relogiosGrade.length || relogiosDetalhe.length)) {
            temporizadorRelogio = setInterval(tique, 1000);
        }
    }

    /** Quando uma oferta zera, recarrega a lista (o servidor a conta como expirada) uma vez por vaga. */
    function tique() {
        var zerou = null;
        relogiosGrade.concat(relogiosDetalhe).forEach(function (r) {
            if (atualizarRelogio(r) <= 0 && !zerados[r.codigo]) {
                zerados[r.codigo] = true;
                zerou = r.codigo;
            }
        });
        if (zerou) {
            carregarVagas();
            if (S.tlm === 'vaga' && S.vgDet) {
                abrirVaga(S.vgDet.codigo, true);
            }
        }
        if (!relogiosGrade.length && !relogiosDetalhe.length) {
            clearInterval(temporizadorRelogio);
            temporizadorRelogio = null;
        }
    }

    /* ---------------------------------------------------------------- menu de contexto, ações e marcações */

    /** Itens provisórios até o zip: Abrir, os botões da vaga e as marcações pessoais. */
    function menuDaVaga(caixa, codigo) {
        var v = vagas().filter(function (x) { return x.codigo === codigo; })[0];
        if (!v) {
            return;
        }
        var item = function (rot, icone, acao, classe) {
            caixa.appendChild(el('button', {
                type: 'button', class: 'te-menu-item' + (classe || ''),
                onclick: function () { fecharMenu(); acao(); }
            }, [ic(icone), rot]));
        };
        item('Abrir', 'bi-box-arrow-up-right', function () { abrirVaga(codigo); });
        if (!podeEscreverVaga()) {
            return;
        }
        (v.botoes || []).forEach(function (b, i) {
            item(b.rot, b.ic, function () { acaoVaga(codigo, b); }, (i === 0 ? ' is-sep' : '') + (b.tipo === 'perigo' ? ' is-perigo' : ''));
        });
        var primeiro = true;
        var marca = function (op, rot, icone, perigo) {
            item(rot, icone, function () { loteVagas([codigo], op); }, (primeiro ? ' is-sep' : '') + (perigo ? ' is-perigo' : ''));
            primeiro = false;
        };
        marca(v.estrela ? 'semestrela' : 'estrela', v.estrela ? 'Tirar estrela' : 'Marcar com estrela', v.estrela ? 'bi-star' : 'bi-star-fill');
        marca(vagaImportante(v) ? 'naoimp' : 'imp', vagaImportante(v) ? 'Marcar como não importante' : 'Marcar como importante', vagaImportante(v) ? 'bi-bookmark-x' : 'bi-bookmark-star');
        if (v.lixeira) {
            marca('restaurar', 'Restaurar', 'bi-arrow-counterclockwise');
            marca('definitivo', 'Excluir definitivamente', 'bi-x-octagon', true);
        } else {
            marca('lixeira', 'Mover para a lixeira', 'bi-trash');
        }
    }

    /** Botão da vaga: com `motivo`, pede o motivo; os de perigo confirmam antes; os demais vão direto. */
    function acaoVaga(codigo, b) {
        fecharMenu();
        if (!podeEscreverVaga()) {
            return;
        }
        if (b.motivo || b.tipo === 'perigo') {
            abrirConf(codigo, {
                k: '__vaga_' + b.k + '__',
                confirmacao: {
                    titulo: b.rot,
                    texto: b.motivo ? 'Escreva o motivo. Ele fica registrado na vaga ' + codigo + '.' : 'Confirmar "' + b.rot + '" na vaga ' + codigo + '?',
                    botao: b.rot, motivo: !!b.motivo, perigo: b.tipo === 'perigo'
                },
                executar: function (motivo) { return enviarAcaoVaga(codigo, b.k, motivo); }
            });
            return;
        }
        enviarAcaoVaga(codigo, b.k, null);
    }

    function enviarAcaoVaga(codigo, k, motivo) {
        return pedir('POST', ROTA.vagaAcao(codigo, k), { motivo: motivo }).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return r;
            }
            if (r.vaga && S.tlm === 'vaga' && S.vgDet && S.vgDet.codigo === codigo) {
                S.vgDet = r.vaga;
                renderDetalheVaga();
            }
            carregarVagas();
            return r;
        });
    }

    /** As mesmas operações do lote das demandas, gravadas só para o usuário logado. */
    function loteVagas(codigos, op) {
        if (!codigos.length || !podeEscreverVaga()) {
            return;
        }
        fecharMenu();
        if (op === 'definitivo') {
            var n = codigos.length;
            abrirConf(null, {
                k: '__definitivo_vaga__',
                confirmacao: {
                    titulo: 'Excluir definitivamente',
                    texto: n === 1
                        ? 'Excluir esta vaga da sua lista definitivamente? Ela continua existindo para os outros.'
                        : 'Excluir ' + n + ' vagas da sua lista definitivamente? Elas continuam existindo para os outros.',
                    botao: 'Excluir', motivo: false, perigo: true
                },
                executar: function () { return enviarLoteVagas(codigos, op); }
            });
            return;
        }
        enviarLoteVagas(codigos, op);
    }

    function enviarLoteVagas(codigos, op) {
        return pedir('POST', ROTA.vagasMarcacoes(), { codigos: codigos, op: op }).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return r;
            }
            S.vgSel = [];
            mostrarDesfazer(r.texto, op === 'lixeira' ? function () { enviarLoteVagas(codigos, 'restaurar'); } : null);
            carregarVagas();
            return r;
        });
    }

    /** Abrir o detalhe marca a vaga como vista (o título deixa de ficar em negrito), sem aviso. */
    function marcarVagaVista(v) {
        if (v.vista || !podeEscreverVaga()) {
            return;
        }
        pedir('POST', ROTA.vagasMarcacoes(), { codigos: [v.codigo], op: 'lida' }).then(function (r) {
            if (!r.ok) {
                return;
            }
            vagas().forEach(function (x) { if (x.codigo === v.codigo) { x.vista = true; } });
            if (S.vgDet && S.vgDet.codigo === v.codigo) {
                S.vgDet.vista = true;
            }
            renderVagas();
        });
    }

    /* ---------------------------------------------------------------- modal genérico (tlm) */

    function fecharTlm() {
        // Fechar no meio do envio esconderia a vaga criada: espera a resposta.
        if (S.vf && S.vf.enviando) {
            return;
        }
        S.tlm = null;
        S.vgDet = null;
        S.vf = null;
        S.sh = null;
        relogiosDetalhe = [];
        limpar($('teVagaModal'));
    }

    /** Cabeçalho azul, corpo com rolagem e rodapé com os botões (`tlmBtns`). Redesenhar guarda a rolagem. */
    function modalVaga(o) {
        var alvo = $('teVagaModal');
        var antes = $('teTlmCorpo'), entra = !antes, topo = antes ? antes.scrollTop : 0;
        limpar(alvo);
        var corpo = el('div', { class: 'te-tlm-corpo', id: 'teTlmCorpo' }, o.corpo);
        alvo.appendChild(el('div', { class: 'te-tlm-fundo', onclick: fecharTlm }));
        alvo.appendChild(el('div', {
            class: 'te-tlm' + (entra ? ' is-entra' : ''),
            style: { width: 'min(' + o.largura + ', calc(100vw - 24px))' },
            attrs: { role: 'dialog', 'aria-modal': 'true', 'aria-label': o.titulo }
        }, [
            el('div', { class: 'te-modal-cab' }, [
                ic(o.icone),
                el('b', { class: 'te-reticencias', text: o.titulo }),
                el('button', { type: 'button', class: 'te-modal-fechar', title: 'Fechar', onclick: fecharTlm }, ic('bi-x-lg'))
            ]),
            corpo,
            el('div', { class: 'te-tlm-rodape' }, o.botoes)
        ]));
        corpo.scrollTop = topo;
    }

    function botaoTlm(rot, icone, tipo, acao) {
        return el('button', {
            type: 'button',
            class: 'te-tlm-btn' + (tipo === 'pri' ? ' is-pri' : tipo === 'perigo' ? ' is-pri is-perigo' : ''),
            onclick: acao
        }, [ic(icone), rot]);
    }

    /** Erro do servidor (`tlErro`, linha 372): última faixa do corpo, logo acima do rodapé. */
    function erroTlm(txt) {
        return vazio(txt) ? null : el('div', { class: 'te-tlm-erro', attrs: { role: 'alert' } }, [ic('bi-exclamation-circle'), ' ', el('span', { text: txt })]);
    }

    /* ---------------------------------------------------------------- detalhe da vaga (tmVaga) */

    /** Ícone e nome de cada dado de quem oferece (`ofCampos`). */
    var OF_CAMPOS = {
        resp: ['bi-person', 'Responsável'],
        cidade: ['bi-geo-alt', 'Cidade'],
        uf: ['bi-map', 'UF'],
        email: ['bi-envelope', 'E-mail'],
        tel: ['bi-telephone', 'Telefone'],
        site: ['bi-globe', 'Site']
    };
    var OF_CHAVES = ['resp', 'cidade', 'uf', 'email', 'tel', 'site'];

    function abrirVaga(codigo, silencioso) {
        fecharMenu();
        return pedir('GET', ROTA.vaga(codigo)).then(function (r) {
            // Atualização em segundo plano não reabre um detalhe que o usuário já fechou.
            if (silencioso && !(S.tlm === 'vaga' && S.vgDet && S.vgDet.codigo === codigo)) {
                return;
            }
            if (!r.ok || !r.vaga) {
                if (!silencioso) {
                    toastErro(r.mensagem);
                }
                return;
            }
            S.tlm = 'vaga';
            S.vgDet = r.vaga;
            S.vf = null;
            renderDetalheVaga();
            marcarVagaVista(r.vaga);
        });
    }

    function dadoDaVaga(icone, texto) {
        return el('span', { class: 'te-tv-dado' }, [ic(icone), texto]);
    }

    function renderDetalheVaga() {
        var v = S.vgDet;
        if (!v) {
            return;
        }
        relogiosDetalhe = [];
        var of = v.ofertante || { nome: v.escritorio, campos: [], oculto: false };
        var corpo = [
            // "Compartilhar esta vaga" (`tvPodeShare` / `tvShare`, desenho linha 286).
            podeEscreverVaga() && vagaCompartilhavel(v)
                ? el('button', { type: 'button', class: 'te-tv-share', onclick: function () { abrirCompartilhar([v.codigo]); } }, [ic('bi-whatsapp'), 'Compartilhar esta vaga'])
                : null,
            el('div', { class: 'te-tv-of' }, [
                el('span', { class: 'te-tv-of-ic' }, ic('bi-building')),
                el('div', { class: 'te-tv-of-textos' }, [
                    el('div', { class: 'te-tv-of-rot', text: 'OFERECIDO POR' }),
                    el('b', { class: 'te-tv-of-nome', text: of.nome || v.escritorio || '' }),
                    (of.campos || []).length ? el('div', { class: 'te-tv-of-info' }, of.campos.map(function (c) {
                        var d = OF_CAMPOS[c.k] || ['bi-dot', c.k];
                        return el('span', { title: c.oculto ? d[1] + ': oculto para quem vê a vaga' : d[1] }, [
                            ic(d[0]), ' ' + c.v,
                            c.oculto ? el('i', { class: 'bi bi-eye-slash te-tv-of-olho' }) : null
                        ]);
                    })) : null,
                    of.oculto ? el('div', { class: 'te-tv-of-oculto' }, [ic('bi-shield-lock'), ' Contato pelo BlueJus, ao se candidatar.']) : null
                ])
            ]),
            el('div', { class: 'te-tv-sit' }, [
                seloSituacao(v.situacaoRotulo, v.situacaoCor),
                el('span', { text: (v.escritorio || '') + ' · ' + (v.publicadaEm ? 'publicada em ' + dataBr(v.publicadaEm) : 'ainda não publicada') })
            ]),
            el('div', { class: 'te-tv-dados' }, [
                dadoDaVaga('bi-person-badge', v.cargo || ''),
                dadoDaVaga('bi-file-earmark-text', vazio(v.contratacao) ? 'Contratação não informada' : v.contratacao),
                dadoDaVaga('bi-geo-alt', v.local || ''),
                dadoDaVaga('bi-cash-stack', v.remuneracao || ''),
                dadoDaVaga('bi-calendar3', prazoDaVaga(v)),
                dadoDaVaga('bi-award', v.exigeOab ? 'Exige OAB' : 'OAB não exigida')
            ]),
            (v.areas || []).length ? el('div', { class: 'te-vg-areas' }, v.areas.map(function (a) { return el('span', { class: 'te-vg-area', text: a }); })) : null,
            v.oferta && v.expiraEm ? relogioDaVaga(v, true, relogiosDetalhe) : null,
            // Motivo da reprovação: o desenho não mostra onde; fica antes dos requisitos (provisório).
            !vazio(v.motivo) ? el('div', { class: 'te-tv-motivo' }, [ic('bi-x-circle'), el('span', null, [el('b', { text: 'Motivo: ' }), v.motivo])]) : null,
            el('div', { class: 'te-tv-req', text: vazio(v.requisitos) ? 'Requisitos e atividades não informados.' : v.requisitos })
        ];
        if ((v.historico || []).length) {
            corpo.push(el('div', { class: 'te-tv-hist-rot', text: 'HISTÓRICO DA OFERTA' }));
            corpo.push(el('div', { class: 'te-tv-hist' }, [el('div', { class: 'te-tv-hist-eixo' })].concat(v.historico.map(function (h) {
                return el('div', { class: 'te-tv-hist-item' }, [
                    el('span', { class: 'te-tv-hist-bola', style: { color: h.cor, 'border-color': h.cor } }, ic(h.ic)),
                    el('span', { class: 'te-tv-hist-txt', text: h.txt }),
                    el('span', { class: 'te-tv-hist-em', text: h.em })
                ]);
            }))));
        }

        var botoes = [botaoTlm('Fechar', 'bi-x-lg', '', fecharTlm)];
        if (podeEscreverVaga()) {
            (v.botoes || []).forEach(function (b) {
                botoes.push(botaoTlm(b.rot, b.ic, b.tipo, function () { acaoVaga(v.codigo, b); }));
            });
        }
        modalVaga({ icone: 'bi-briefcase', titulo: v.codigo + ' · ' + v.titulo, largura: '640px', corpo: corpo, botoes: botoes });
        ligarRelogio();
    }

    /* ---------------------------------------------------------------- compartilhar vagas (tmShare) */

    /** A Central compartilha qualquer vaga aberta; o escritório, só as abertas dele (spec §4.2). */
    function vagaCompartilhavel(v) {
        return !!v && v.situacao === 'aberta' && (!!(S.vg && S.vg.daCentral) || !!v.minha);
    }

    function vagasCompartilhaveis(lista) {
        return lista.filter(function (v) { return !v.lixeira && vagaCompartilhavel(v); });
    }

    /**
     * `shareTodas`: as abertas compartilháveis à vista no filtro e na busca. Se o filtro atual não
     * mostra nenhuma (Encerradas, Lixeira), vão todas as abertas compartilháveis da aba.
     */
    function compartilharTodas() {
        var L = vagasCompartilhaveis(vagasFiltradas());
        if (!L.length) {
            L = vagasCompartilhaveis(vagas());
        }
        abrirCompartilhar(L.map(function (v) { return v.codigo; }));
    }

    function plural(n, um, varios) { return n + ' ' + (n === 1 ? um : varios); }

    /** Cargos distintos, na ordem da lista, das vagas que entraram no link. */
    function cargosDoLink(codigos) {
        var vistos = [];
        codigos.forEach(function (c) {
            var v = vagas().filter(function (x) { return x.codigo === c; })[0] || (S.vgDet && S.vgDet.codigo === c ? S.vgDet : null);
            if (v && !vazio(v.cargo) && vistos.indexOf(v.cargo) < 0) {
                vistos.push(v.cargo);
            }
        });
        return vistos;
    }

    /** Mensagem padrão (`msg` do desenho da página pública). O emoji é do desenho. */
    function mensagemPadrao(sh) {
        return '⚖️ Vagas para Advogados e área Jurídica disponíveis no BlueJus! '
            + plural(sh.n, 'vaga', 'vagas')
            + (sh.cargos.length ? ': ' + sh.cargos.slice(0, 3).join(', ') : '')
            + '. Veja: {link}';
    }

    function abrirCompartilhar(codigos) {
        if (!podeEscreverVaga() || !codigos.length || S.shPedindo) {
            return;
        }
        fecharMenu();
        S.shPedindo = true;
        pedir('POST', ROTA.vagasCompartilhar(), { codigos: codigos }).then(function (r) {
            S.shPedindo = false;
            if (!r.ok || !r.url) {
                toastErro(r.mensagem);
                return;
            }
            var dominio = '';
            try {
                dominio = new URL(r.url).host;
            } catch (e) {
                dominio = '';
            }
            var n = +r.vagas || codigos.length;
            S.tlm = 'share';
            S.vgDet = null;
            S.vf = null;
            relogiosDetalhe = [];
            // Vindo do detalhe, o modal entra de novo (sem herdar a rolagem dele).
            limpar($('teVagaModal'));
            S.sh = { url: r.url, dominio: dominio, n: n, cargos: cargosDoLink(codigos), msg: '' };
            S.sh.msg = mensagemPadrao(S.sh);
            renderCompartilhar();
        });
    }

    /** Canais (`shCanais`): provisórios até o zip. `semLink` desabilita todos. */
    function canaisDoLink(sh) {
        var texto = sh.msg.split('{link}').join(sh.url);

        return [
            { r: 'WhatsApp', ic: 'bi-whatsapp', cor: '#1e7b45', href: 'https://wa.me/?text=' + encodeURIComponent(texto) },
            { r: 'E-mail', ic: 'bi-envelope', cor: '#0a6fa0', href: 'mailto:?subject=' + encodeURIComponent('Vagas jurídicas no BlueJus') + '&body=' + encodeURIComponent(texto), mesmaAba: true },
            { r: 'LinkedIn', ic: 'bi-linkedin', cor: '#0a66c2', href: 'https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(sh.url) },
            { r: 'Copiar mensagem', ic: 'bi-clipboard', cor: '#34505f', copiar: texto }
        ];
    }

    function copiarMensagem(texto) {
        var falhou = function () { toastErro('Não foi possível copiar. Selecione o texto e copie manualmente.'); };
        try {
            if (!navigator.clipboard || !navigator.clipboard.writeText) {
                falhou();
                return;
            }
            navigator.clipboard.writeText(texto).then(function () {
                mostrarToast({ tit: 'Mensagem copiada', txt: 'Cole onde quiser compartilhar.', hora: '' });
            }, falhou);
        } catch (e) {
            falhou();
        }
    }

    /** Monta os botões dos canais no lugar, sem redesenhar o modal (a textarea não perde o foco). */
    function renderCanais(alvo, alerta) {
        var sh = S.sh;
        var semLink = sh.msg.indexOf('{link}') < 0;
        alerta.hidden = !semLink;
        limpar(alvo);
        canaisDoLink(sh).forEach(function (k) {
            var filhos = [ic(k.ic), k.r];
            var estilo = { color: k.cor };
            if (k.copiar !== undefined) {
                alvo.appendChild(el('button', {
                    type: 'button', class: 'te-sh-canal', style: estilo, disabled: semLink,
                    onclick: function () { copiarMensagem(k.copiar); }
                }, filhos));
                return;
            }
            if (semLink) {
                alvo.appendChild(el('button', { type: 'button', class: 'te-sh-canal', style: estilo, disabled: true }, filhos));
                return;
            }
            alvo.appendChild(el('a', {
                class: 'te-sh-canal', style: estilo, href: k.href,
                attrs: k.mesmaAba ? { rel: 'noopener noreferrer' } : { target: '_blank', rel: 'noopener noreferrer' }
            }, filhos));
        });
    }

    function renderCompartilhar() {
        var sh = S.sh;
        if (!sh) {
            return;
        }
        var canais = el('div', { class: 'te-sh-canais' });
        var alerta = el('div', { class: 'te-sh-sem-link', attrs: { role: 'alert' } }, [ic('bi-exclamation-triangle'), ' Inclua {link} no texto para o link entrar na mensagem.']);
        var texto = el('textarea', {
            class: 'te-sh-msg', rows: 10, value: sh.msg, attrs: { 'aria-label': 'Mensagem' },
            oninput: function (e) {
                sh.msg = e.target.value;
                renderCanais(canais, alerta);
            }
        });
        var corpo = [
            el('div', { class: 'te-sh' }, [
                el('div', { class: 'te-sh-col' }, [
                    el('div', { class: 'te-sh-rot', text: 'PRÉVIA DO LINK' }),
                    el('div', { class: 'te-sh-previa' }, [
                        el('div', { class: 'te-sh-cartao' }, [
                            el('div', { class: 'te-sh-capa' }, [
                                el('span', { class: 'te-sh-bola te-sh-bola--1' }),
                                el('span', { class: 'te-sh-bola te-sh-bola--2' }),
                                el('img', { class: 'te-sh-simbolo', src: '/images/logo/bluejus_simbolo.svg', alt: '' }),
                                el('div', { class: 'te-sh-capa-txt' }, [
                                    el('div', { class: 'te-sh-capa-rot', text: 'CENTRAL DE VAGAS JURÍDICAS' }),
                                    el('div', { class: 'te-sh-capa-nome', text: 'BlueJus' })
                                ])
                            ]),
                            el('div', { class: 'te-sh-info' }, [
                                el('div', { class: 'te-sh-dom', text: sh.dominio }),
                                el('b', { class: 'te-sh-tit', text: 'Vagas para Advogados e área Jurídica' }),
                                el('div', { class: 'te-sh-sub', text: plural(sh.n, 'vaga aberta', 'vagas abertas') }),
                                sh.cargos.length ? el('div', { class: 'te-sh-cargos' }, sh.cargos.map(function (g) { return el('span', { class: 'te-sh-cargo', text: g }); })) : null
                            ])
                        ])
                    ]),
                    el('div', { class: 'te-sh-aviso' }, [ic('bi-shield-lock'), ' A página pública mostra só cargo, local e tipo. Detalhes, remuneração, contatos e candidatura ficam liberados depois de criar a conta.'])
                ]),
                el('div', { class: 'te-sh-col' }, [
                    el('div', { class: 'te-sh-msg-cab' }, [
                        el('span', { class: 'te-sh-rot te-sh-msg-rot', text: 'MENSAGEM' }),
                        el('button', {
                            type: 'button', class: 'te-sh-padrao',
                            onclick: function () {
                                sh.msg = mensagemPadrao(sh);
                                texto.value = sh.msg;
                                renderCanais(canais, alerta);
                            }
                        }, [ic('bi-arrow-counterclockwise'), ' Mensagem padrão'])
                    ]),
                    texto,
                    alerta,
                    canais
                ])
            ])
        ];
        renderCanais(canais, alerta);
        modalVaga({ icone: 'bi-share', titulo: 'Compartilhar vagas', largura: '760px', corpo: corpo, botoes: [botaoTlm('Fechar', 'bi-x-lg', '', fecharTlm)] });
    }

    /* ---------------------------------------------------------------- formulário Nova vaga / Divulgar (tmVagaForm) */

    /** Passos de quem divulga (`divPassos`). Textos provisórios até o zip. */
    var DIV_PASSOS = [
        ['1', 'Envie a vaga', 'Preencha os dados e envie pelo BlueJus.'],
        ['2', 'A equipe BlueJus revisa', 'Conferimos as informações antes de divulgar.'],
        ['3', 'A vaga é divulgada na Central', 'Os profissionais da rede passam a ver a vaga.']
    ];
    /** Tipos da configuração interna (`tfTipos`). Textos provisórios até o zip. */
    var TF_TIPOS = [
        [false, 'Vaga', 'bi-briefcase', 'Divulgação comum, aberta durante o período escolhido.'],
        [true, 'Oferta relâmpago', 'bi-lightning-charge', 'Fica disponível por poucos minutos, com contador. Quem aceitar primeiro fica com a vaga.']
    ];

    /** `criar` = "Nova vaga" (Central, nasce aberta); `divulgar` = "Divulgar uma vaga" (escritório, nasce pendente). */
    function abrirFormVaga(modo) {
        if (!podeEscreverVaga() || !S.vg) {
            return;
        }
        fecharMenu();
        var L = listasVg(), P = S.vg.ofertantePadrao || {}, c = cfgVagas() || {};
        var periodos = L.periodos || [], duracoes = L.duracoesDaOferta || [];
        // Para o Master, o período começa no "Tempo padrão de disponibilidade" da configuração.
        var per = periodos.indexOf(+c.dur) >= 0 ? +c.dur : periodos.indexOf(15) >= 0 ? 15 : (periodos[0] || 15);
        S.vf = {
            modo: modo,
            f: {
                titulo: '', cargo: '', contratacao: '', modalidade: '', cidade: '', uf: '', remuneracao: '',
                areas: [], requisitos: '', inscricoesAte: '', exigeOab: false, periodoDias: per,
                oferta: false, duracaoDaOferta: duracoes.indexOf(15) >= 0 ? 15 : (duracoes[0] || 15), qtd: 1
            },
            of: { nome: P.nome || '', resp: P.resp || '', cidade: P.cidade || '', uf: P.uf || '', email: P.email || '', tel: '', site: '' },
            ocultos: [],
            editOf: vazio(P.nome),
            erro: '',
            enviando: false
        };
        S.tlm = 'form';
        S.vgDet = null;
        relogiosDetalhe = [];
        limpar($('teVagaModal'));
        renderFormVaga();
    }

    function rotuloVf(texto, controle, extra) {
        return el('label', { class: 'te-vf-rot' + (extra || '') }, [texto, controle]);
    }

    /** Campo que guarda o digitado no estado; o obrigatório perde a borda vermelha quando deixa de estar vazio. */
    function ctlVf(tag, obj, k, props, req) {
        var p = Object.assign({
            class: 'te-vf-ctl' + (req && S.vf && S.vf.erro && vazio(obj[k]) ? ' is-erro' : ''),
            value: obj[k] === undefined || obj[k] === null ? '' : String(obj[k])
        }, props || {});
        p.oninput = function (e) {
            obj[k] = e.target.value;
            if (req && S.vf && S.vf.erro) {
                e.target.classList.toggle('is-erro', vazio(obj[k]));
            }
        };
        return el(tag, p);
    }

    function selectVf(obj, k, ops, req) {
        return el('select', {
            class: 'te-vf-ctl' + (req && S.vf && S.vf.erro && vazio(obj[k]) ? ' is-erro' : ''),
            value: obj[k] || '',
            onchange: function (e) {
                obj[k] = e.target.value;
                if (req && S.vf && S.vf.erro) {
                    e.target.classList.toggle('is-erro', vazio(obj[k]));
                }
            }
        }, [el('option', { value: '', text: 'Selecione' })].concat((ops || []).map(function (o) { return el('option', { value: String(o), text: String(o) }); })));
    }

    function opcaoVf(rot, on, acao, extra) {
        return el('button', {
            type: 'button', class: 'te-vf-opcao' + (on ? ' is-on' : '') + (extra || ''),
            attrs: { 'aria-pressed': on ? 'true' : 'false' },
            onclick: acao
        }, rot);
    }

    function periodoVf(F, L) {
        return rotuloVf('QUANTO TEMPO A VAGA FICA DISPONÍVEL', el('select', {
            class: 'te-vf-ctl', value: String(F.f.periodoDias),
            onchange: function (e) { F.f.periodoDias = +e.target.value; }
        }, (L.periodos || []).map(function (d) { return el('option', { value: String(d), text: d + ' dias' }); })), ' is-cheio is-pequeno');
    }

    /** "QUEM ESTÁ OFERECENDO" (linhas 238 a 244): resumo com o olho em cada dado, ou os campos para editar. */
    function blocoOfertante(F, L) {
        var of = F.of, conteudo;
        if (!F.editOf) {
            var chips = OF_CHAVES.filter(function (k) { return !vazio(of[k]); }).map(function (k) {
                var oc = F.ocultos.indexOf(k) >= 0, d = OF_CAMPOS[k];
                var dica = oc ? d[1] + ' oculto: clique para mostrar na vaga' : d[1] + ' visível: clique para ocultar na vaga';
                return el('button', {
                    type: 'button', class: 'te-vf-of-chip' + (oc ? ' is-oculto' : ''), title: dica,
                    attrs: { 'data-tip': dica, 'aria-pressed': oc ? 'false' : 'true' },
                    onclick: function () {
                        F.ocultos = oc ? F.ocultos.filter(function (x) { return x !== k; }) : F.ocultos.concat([k]);
                        renderFormVaga();
                    }
                }, [ic(d[0]), of[k], el('i', { class: 'bi te-vf-of-olho ' + (oc ? 'bi-eye-slash' : 'bi-eye') })]);
            });
            conteudo = el('div', { class: 'te-vf-of-resumo' }, [
                el('b', { class: 'te-vf-of-nome', text: vazio(of.nome) ? 'Informe o escritório em Editar.' : of.nome }),
                chips.length ? el('div', { class: 'te-vf-of-chips' }, chips) : null,
                el('span', { class: 'te-vf-of-dica', text: 'Clique num dado para mostrar ou ocultar na vaga. Ocultos: os candidatos falam com você pelo BlueJus.' }),
                el('div', { class: 'te-vf-of-grade' }, [periodoVf(F, L)])
            ]);
        } else {
            conteudo = el('div', { class: 'te-vf-of-campos' }, [
                rotuloVf('ESCRITÓRIO *', ctlVf('input', of, 'nome', { type: 'text' }, true), ' is-pequeno'),
                rotuloVf('RESPONSÁVEL PELA VAGA', ctlVf('input', of, 'resp', { type: 'text' }), ' is-pequeno'),
                rotuloVf('CIDADE', ctlVf('input', of, 'cidade', { type: 'text' }), ' is-pequeno'),
                rotuloVf('UF', selectVf(of, 'uf', L.ufs), ' is-pequeno'),
                rotuloVf('E-MAIL', ctlVf('input', of, 'email', { type: 'email' }), ' is-pequeno'),
                rotuloVf('TELEFONE / WHATSAPP', ctlVf('input', of, 'tel', { type: 'tel' }), ' is-pequeno'),
                rotuloVf('SITE OU INSTAGRAM', ctlVf('input', of, 'site', { type: 'text' }), ' is-pequeno'),
                periodoVf(F, L)
            ]);
        }
        return el('div', { class: 'te-vf-of' }, [
            el('div', { class: 'te-vf-of-cab' }, [
                ic('bi-building-check'),
                el('b', { text: 'QUEM ESTÁ OFERECENDO' }),
                F.editOf ? null : el('span', { class: 'te-vf-of-orig', text: 'Preenchido do seu cadastro' }),
                el('button', {
                    type: 'button', class: 'te-vf-of-editar',
                    onclick: function () { F.editOf = !F.editOf; renderFormVaga(); }
                }, [ic(F.editOf ? 'bi-check2' : 'bi-pencil'), F.editOf ? 'Concluir' : 'Editar'])
            ]),
            conteudo
        ]);
    }

    function renderFormVaga() {
        var F = S.vf;
        if (!F) {
            return;
        }
        var L = listasVg(), f = F.f, criar = F.modo === 'criar';
        var corpo = [];

        if (!criar) {
            corpo.push(el('div', { class: 'te-vf-passos' }, DIV_PASSOS.map(function (d) {
                return el('div', { class: 'te-vf-passo' }, [
                    el('span', { class: 'te-vf-passo-n', text: d[0] }),
                    el('span', { class: 'te-vf-passo-txt' }, [el('b', { text: d[1] }), d[2]])
                ]);
            })));
        }
        corpo.push(blocoOfertante(F, L));

        if (criar) {
            corpo.push(el('div', { class: 'te-vf-interna' }, [ic('bi-lock'), 'CONFIGURAÇÃO INTERNA · SÓ O MASTER VÊ']));
            corpo.push(el('div', { class: 'te-vf-tipos' }, TF_TIPOS.map(function (t) {
                var on = !!f.oferta === t[0];
                return el('button', {
                    type: 'button', class: 'te-vf-tipo' + (on ? ' is-on' : ''),
                    attrs: { 'aria-pressed': on ? 'true' : 'false' },
                    onclick: function () { f.oferta = t[0]; renderFormVaga(); }
                }, [
                    ic(t[2]),
                    el('span', { class: 'te-flex1' }, [el('b', { text: t[1] }), el('span', { class: 'te-vf-tipo-d', text: t[3] })]),
                    on ? el('i', { class: 'bi bi-check-circle-fill te-vf-tipo-ok' }) : null
                ]);
            })));
            if (f.oferta) {
                corpo.push(el('div', { class: 'te-vf-oferta' }, [
                    el('div', { class: 'te-vf-oferta-grupo' }, [
                        'DISPONÍVEL POR',
                        el('div', { class: 'te-vf-opcoes' }, (L.duracoesDaOferta || []).map(function (m) {
                            return opcaoVf(m + ' min', +f.duracaoDaOferta === +m, function () { f.duracaoDaOferta = +m; renderFormVaga(); });
                        }))
                    ]),
                    el('label', { class: 'te-vf-oferta-grupo' }, [
                        'Nº DE VAGAS',
                        el('input', { type: 'number', min: 1, max: 20, class: 'te-vf-qtd', value: String(f.qtd), oninput: function (e) { f.qtd = e.target.value; } })
                    ]),
                    el('span', { class: 'te-vf-oferta-info' }, [ic('bi-info-circle'), ' O contador começa ao publicar. A oferta fecha sozinha quando todas as vagas forem aceitas ou o tempo acabar.'])
                ]));
            }
        }

        corpo.push(rotuloVf('TÍTULO DA VAGA *', ctlVf('input', f, 'titulo', { type: 'text', placeholder: 'Cargo e área da vaga' }, true)));
        corpo.push(el('div', { class: 'te-vf-grade' }, [
            rotuloVf('CARGO *', selectVf(f, 'cargo', L.cargos, true)),
            rotuloVf('CONTRATAÇÃO', selectVf(f, 'contratacao', L.contratacoes)),
            rotuloVf('MODALIDADE', selectVf(f, 'modalidade', L.modalidades))
        ]));
        corpo.push(el('div', { class: 'te-vf-grade' }, [
            rotuloVf('CIDADE', ctlVf('input', f, 'cidade', { type: 'text', placeholder: 'Cidade da vaga' })),
            rotuloVf('UF', selectVf(f, 'uf', L.ufs)),
            rotuloVf('REMUNERAÇÃO', ctlVf('input', f, 'remuneracao', { type: 'text', placeholder: 'Valor mensal ou A combinar' }))
        ]));
        corpo.push(el('div', { class: 'te-vf-rot' }, [
            'ÁREAS DE ATUAÇÃO',
            el('div', { class: 'te-vf-areas' }, (L.areas || []).map(function (a) {
                var on = f.areas.indexOf(a) >= 0;
                return el('button', {
                    type: 'button', class: 'te-vf-area' + (on ? ' is-on' : ''),
                    attrs: { 'aria-pressed': on ? 'true' : 'false' },
                    onclick: function () {
                        f.areas = on ? f.areas.filter(function (x) { return x !== a; }) : f.areas.concat([a]);
                        renderFormVaga();
                    }
                }, a);
            }))
        ]));
        corpo.push(rotuloVf('REQUISITOS E ATIVIDADES', ctlVf('textarea', f, 'requisitos', { rows: 4, placeholder: 'Experiência, conhecimentos e rotina da vaga' })));
        corpo.push(el('div', { class: 'te-vf-grade' }, [
            rotuloVf('INSCRIÇÕES ATÉ', ctlVf('input', f, 'inscricoesAte', { type: 'date' })),
            el('label', { class: 'te-vf-oab' }, [
                el('input', { type: 'checkbox', checked: !!f.exigeOab, onchange: function (e) { f.exigeOab = e.target.checked; } }),
                'Exige OAB ativa'
            ])
        ]));
        corpo.push(erroTlm(F.erro));

        modalVaga({
            icone: criar ? 'bi-plus-lg' : 'bi-megaphone',
            titulo: criar ? 'Nova vaga' : 'Divulgar uma vaga',
            largura: '720px',
            corpo: corpo,
            botoes: [
                botaoTlm('Cancelar', 'bi-x-lg', '', fecharTlm),
                botaoTlm(criar ? 'Publicar vaga' : 'Enviar para aprovação', criar ? 'bi-check2' : 'bi-send', 'pri', enviarFormVaga)
            ]
        });
    }

    function enviarFormVaga() {
        var F = S.vf;
        if (!F || F.enviando || !podeEscreverVaga()) {
            return;
        }
        var f = F.f, of = F.of;
        if (vazio(f.titulo) || vazio(f.cargo) || vazio(of.nome)) {
            F.erro = 'Preencha os campos obrigatórios marcados com *.';
            if (vazio(of.nome)) {
                F.editOf = true;
            }
            renderFormVaga();
            return;
        }
        var t = function (x) { return String(x || '').trim(); };
        var corpo = {
            titulo: t(f.titulo), cargo: f.cargo, contratacao: f.contratacao, modalidade: f.modalidade,
            cidade: t(f.cidade), uf: f.uf, remuneracao: t(f.remuneracao), areas: f.areas.slice(),
            requisitos: t(f.requisitos), inscricoesAte: f.inscricoesAte || '', exigeOab: !!f.exigeOab,
            periodoDias: +f.periodoDias,
            oferta: F.modo === 'criar' && !!f.oferta,
            duracaoDaOferta: +f.duracaoDaOferta,
            qtd: Math.max(1, Math.min(20, parseInt(f.qtd, 10) || 1)),
            ofertante: {
                nome: t(of.nome), resp: t(of.resp), cidade: t(of.cidade), uf: of.uf || '', email: t(of.email), tel: t(of.tel), site: t(of.site),
                // Dado vazio não conta como oculto: senão o detalhe diria "Contato pelo BlueJus" sem nada escondido.
                ocultos: F.ocultos.filter(function (k) { return !vazio(of[k]); })
            }
        };
        F.enviando = true;
        Array.prototype.forEach.call(document.querySelectorAll('#teVagaModal .te-tlm-rodape button'), function (b) { b.disabled = true; });
        pedir('POST', ROTA.vagaSalvar(), corpo).then(function (r) {
            if (S.vf !== F) {
                return;
            }
            F.enviando = false;
            if (!r.ok) {
                F.erro = r.mensagem;
                renderFormVaga();
                return;
            }
            var pendente = !!(r.vaga && r.vaga.pendente);
            fecharTlm();
            mostrarToast(pendente
                ? { tit: 'Vaga enviada', txt: 'A equipe BlueJus revisa e divulga na Central.', hora: '' }
                : { tit: 'Vaga publicada', txt: (r.codigo || 'A vaga') + ' já aparece na Central.', hora: '' });
            S.vgFiltro = pendente ? 'pendente' : 'abertas';
            S.vgSel = [];
            carregarVagas();
        });
    }

    /* ---------------------------------------------------------------- configuração das vagas (tmCfgV), só Master */

    /** Chaves da configuração (`cfgTgs`). Provisório até o zip: sino e motiv, com textos de apoio nossos. */
    var CFG_VAGAS_TGS = [
        ['sino', 'bi-bell', 'Avisar no sino quando chegar candidatura', 'O Master e a equipe recebem um aviso no sino a cada nova candidatura.'],
        ['motiv', 'bi-emoji-smile', 'Mostrar frases de ânimo a quem não conseguiu a vaga', 'Quando outra pessoa fica com a vaga, quem tentou vê uma mensagem de incentivo.']
    ];

    function abrirCfgVagas() {
        if (!cfgVagas() || !podeEscreverVaga()) {
            return;
        }
        fecharMenu();
        S.tlm = 'cfg';
        S.vgDet = null;
        S.vf = null;
        relogiosDetalhe = [];
        limpar($('teVagaModal'));
        renderCfgVagas();
    }

    function renderCfgVagas() {
        var c = cfgVagas();
        if (!c || S.tlm !== 'cfg') {
            return;
        }
        var grupo = function (k, tit, txt, ops, rot, primeiro) {
            return el('div', { class: 'te-cv-grupo' + (primeiro ? '' : ' is-div') }, [
                el('b', { text: tit }),
                el('span', { class: 'te-cv-txt', text: txt }),
                el('div', { class: 'te-vf-opcoes' }, ops.map(function (o) {
                    return opcaoVf(rot(o), +c[k] === o, function () { gravarCfgVaga(k, o); }, ' is-cfg');
                }))
            ]);
        };
        var corpo = [
            el('div', { class: 'te-cv-aviso' }, [ic('bi-lock'), ' Configuração interna. Os usuários não veem o tempo da oferta nem estas regras; a vaga simplesmente deixa de aparecer quando o tempo acaba ou é preenchida.']),
            grupo('dur', 'Tempo padrão de disponibilidade das vagas', 'Vale para as vagas que você aprovar ou publicar.',
                [5, 10, 15, 30, 60], function (d) { return d + ' dias'; }, true),
            grupo('maxDias', 'Divulgação máxima para vagas de escritórios', 'Limita o período que o escritório pode escolher. Pedidos acima do limite são ajustados na aprovação.',
                [0, 5, 10, 15, 30, 60], function (d) { return d === 0 ? 'Sem limite' : d + ' dias'; }),
            grupo('minEnvio', 'Candidatos enviados ao escritório', 'O escritório não vê todos os inscritos: você seleciona e envia os melhores. Padrão mínimo por vaga:',
                [1, 2, 3, 5, 10], function (n) { return n === 1 ? '1 candidato' : n + ' candidatos'; })
        ].concat(CFG_VAGAS_TGS.map(function (t) {
            return el('label', { class: 'te-cv-tg' }, [
                ic(t[1]),
                el('span', { class: 'te-flex1' }, [el('b', { text: t[2] }), el('span', { class: 'te-cv-txt', text: t[3] })]),
                el('input', { type: 'checkbox', checked: !!c[t[0]], onchange: function (e) { gravarCfgVaga(t[0], e.target.checked); } })
            ]);
        }));
        modalVaga({ icone: 'bi-gear', titulo: 'Configurar vagas', largura: '560px', corpo: corpo, botoes: [botaoTlm('Fechar', 'bi-x-lg', '', fecharTlm)] });
    }

    /** Cada clique grava (como o resto da configuração do módulo); se o servidor recusar, volta o valor. */
    function gravarCfgVaga(k, valor) {
        var c = cfgVagas();
        if (!c || !podeEscreverVaga()) {
            return;
        }
        var antes = c[k];
        c[k] = valor;
        renderCfgVagas();
        var corpo = { vagas: {} };
        corpo.vagas[k] = valor;
        pedir('POST', D.urlConfiguracao, corpo).then(function (r) {
            if (r.ok) {
                return;
            }
            var atual = cfgVagas();
            if (atual) {
                atual[k] = antes;
            }
            renderCfgVagas();
            toastErro(r.mensagem);
        });
    }

    /* ---------------------------------------------------------------- aviso no canto */

    var temporizadorToast = null;

    /** Aviso sem demanda some sozinho em 4,5 s; o de mensagem nova fica até abrir ou dispensar. */
    function mostrarToast(t) {
        S.toast = t;
        clearTimeout(temporizadorToast);
        var alvo = limpar($('teToast'));
        alvo.appendChild(el('div', { class: 'te-toast', attrs: { role: 'status' } }, [
            el('div', { class: 'te-toast-cab' }, [
                ic('bi-chat-dots-fill'),
                el('b', { text: t.tit }),
                t.hora || '',
                el('button', { type: 'button', class: 'te-toast-fechar', title: 'Dispensar', onclick: fecharToast }, ic('bi-x-lg'))
            ]),
            el('div', { class: 'te-toast-txt', text: t.txt }),
            t.id ? el('button', { type: 'button', class: 'te-toast-abrir', text: 'Abrir conversa', onclick: function () { abrir(t.id, 'chat'); } }) : null
        ]));
        if (!t.id) {
            temporizadorToast = setTimeout(fecharToast, 4500);
        }
    }

    function fecharToast() {
        clearTimeout(temporizadorToast);
        S.toast = null;
        limpar($('teToast'));
    }

    function toastErro(mensagem) {
        mostrarToast({ tit: 'Não foi possível concluir', txt: mensagem || FALHA, hora: '' });
    }

    /* ---------------------------------------------------------------- partida */

    function renderTudo() {
        renderTitulo();
        renderPrevia();
        renderBloqueio();
        renderModulos();
        renderAviso();
        renderLista();
    }

    $('teBusca').addEventListener('input', function (e) {
        S.busca = e.target.value;
        renderLinhas();
    });

    $('teVgBusca').addEventListener('input', function (e) {
        S.vgBusca = e.target.value;
        renderVagas();
    });

    /** `_chatTecla()` do desenho: Delete ou # com mensagens selecionadas, fora de campo de texto. */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Delete' && e.key !== '#') {
            return;
        }
        var tag = (e.target && e.target.tagName) || '';
        if (!S.aberta || S.aba !== 'chat' || !S.chatSel.length || S.conf || S.menu || S.nova || S.cfgAberta || S.hm || /INPUT|TEXTAREA|SELECT/.test(tag)) {
            return;
        }
        e.preventDefault();
        chatLote(S.chatSel.slice(), S.chatVis === 'lix' ? 'definitivo' : 'lixeira');
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') {
            return;
        }
        if (S.conf) {
            fecharConf();
        } else if (S.menu) {
            fecharMenu();
        } else if (S.tlm) {
            fecharTlm();
        } else if (S.cfgAberta) {
            fecharCfg();
        } else if (S.hm) {
            fecharHm();
        } else if (S.aberta && !S.nova) {
            fecharDrawer();
        }
    });

    S.modulo = moduloSalvo();
    renderTudo();
    // A aba Vagas só aparece depois que o servidor disser `veAba`; sem resposta, fica só Demandas.
    if (!S.painel.bloqueio) {
        carregarVagas();
    }

    if (S.painel.avisoDeMensagem) {
        var a = S.painel.avisoDeMensagem;
        mostrarToast({ id: a.codigo, tit: a.titulo, txt: a.texto, hora: a.hora });
    }

    var te = new URLSearchParams(location.search).get('te');
    if (te && demandas().some(function (d) { return d.codigo === te; })) {
        abrir(te);
    }
})();
