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
     * Chip das marcações pessoais (spec VS 18.10.26 §3.3 e §3.5). O desenho mostra só a lixeira (o
     * aviso `lixGrupo`); estrela e importância se filtram pelo "Selecionar por". Provisório até o zip.
     */
    var EXTRAS = [
        ['lixeira', 'Lixeira']
    ];
    /** Importância nível 0 a 3 (`impDe()` do desenho): fundo, cor e ícone graduados. Provisório até o zip. */
    var IMPORTANCIA = [
        { bg: '#eef2f5', cor: '#b5c3cc', ic: 'bi-dash' },
        { bg: '#e5f1f7', cor: '#0a6fa0', ic: 'bi-chevron-right' },
        { bg: '#fff4d6', cor: '#b07f00', ic: 'bi-chevron-double-right' },
        { bg: '#e0a106', cor: '#fff', ic: 'bi-exclamation-lg' }
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
        chatUndo: null
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
        apagarMsg: function (c, id) { return comCodigo(D.urlMensagemApagar, c).replace(/\/0\/apagar$/, '/' + id + '/apagar'); }
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
        pedir('GET', comComo(D.urlPainel)).then(function (r) {
            if (!r.ok || !r.painel) {
                S.como = antes;
                toastErro(r.mensagem);
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
        $('teLiberado').hidden = !!b;
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
                txt = S.grupo === 'lixeira' && !S.busca.trim() ? 'A lixeira está vazia.' : 'Nenhuma demanda neste filtro.';
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
     * Barra de lote e aviso da lixeira (desenho, linhas 124 a 126). Os botões são o conjunto de `lote()`
     * com os rótulos do mapa T; estrela e importante viram "tirar" quando todas as selecionadas já têm.
     */
    function renderLote() {
        var alvo = limpar($('teLote'));
        var naLixeira = S.grupo === 'lixeira';
        if (S.sel.length && !previa()) {
            var sel = demandas().filter(function (d) { return selecionada(d.codigo); });
            var todasEstrela = sel.length > 0 && sel.every(function (d) { return !!d.estrela; });
            var todasImp = sel.length > 0 && sel.every(importante);
            var acoes = naLixeira
                ? [
                    ['restaurar', 'Restaurar', 'bi-arrow-counterclockwise', null],
                    ['definitivo', 'Excluir definitivamente', 'bi-x-octagon', '#a3232b']
                ]
                : [
                    ['lixeira', 'Mover para a lixeira', 'bi-trash', null],
                    ['lida', 'Marcar como lida', 'bi-envelope-open', null],
                    ['naolida', 'Marcar como não lida', 'bi-envelope', null],
                    todasEstrela ? ['semestrela', 'Tirar estrela', 'bi-star', null] : ['estrela', 'Marcar com estrela', 'bi-star-fill', '#e0a106'],
                    todasImp ? ['naoimp', 'Marcar como não importante', 'bi-bookmark-x', null] : ['imp', 'Marcar como importante', 'bi-bookmark-star-fill', '#b07f00']
                ];
            var codigos = S.sel.slice();
            alvo.appendChild(el('div', { class: 'te-lote', attrs: { role: 'toolbar', 'aria-label': 'Ações nas selecionadas' } }, [
                el('b', { class: 'te-lote-n', text: S.sel.length + (S.sel.length === 1 ? ' selecionada' : ' selecionadas') })
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
        var dica = motivos ? 'Importância ' + nv + ' de 3: ' + motivos : 'Importância ' + nv + ' de 3';
        var parar = function (fn) { return function (e) { e.stopPropagation(); if (fn) { fn(); } }; };
        var pv = previa();

        return el('div', { class: 'te-l-marca', onclick: parar(null) }, [
            pv ? null : el('button', {
                type: 'button', class: 'te-sel-btn' + (sel ? ' is-on' : ''), title: 'Selecionar',
                attrs: { 'aria-pressed': sel ? 'true' : 'false' },
                onclick: parar(function () { alternarSel(d.codigo); })
            }, ic(sel ? 'bi-check-square-fill' : 'bi-square')),
            el('button', {
                type: 'button', class: 'te-estrela' + (d.estrela ? ' is-on' : ''), title: d.estrela ? 'Tirar estrela' : 'Estrela', disabled: pv,
                attrs: { 'aria-pressed': d.estrela ? 'true' : 'false' },
                onclick: parar(function () { lote([d.codigo], d.estrela ? 'semestrela' : 'estrela'); })
            }, ic(d.estrela ? 'bi-star-fill' : 'bi-star')),
            el('button', {
                type: 'button', class: 'te-imp', title: dica, disabled: pv,
                style: { background: I.bg, color: I.cor },
                attrs: { 'data-tip': dica, 'aria-label': dica },
                onclick: parar(function () { lote([d.codigo], importante(d) ? 'naoimp' : 'imp'); })
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

    /** Itens das marcações pessoais no menu da linha (spec VS 18.10.26 §3.5, provisório até o zip). */
    function itensDeMarcacao(d) {
        var c = [d.codigo];
        var itens = [
            d.estrela ? ['semestrela', 'Tirar estrela', 'bi-star'] : ['estrela', 'Marcar com estrela', 'bi-star-fill'],
            importante(d) ? ['naoimp', 'Marcar como não importante', 'bi-bookmark-x'] : ['imp', 'Marcar como importante', 'bi-bookmark-star'],
            +d.naoLidas > 0 ? ['lida', 'Marcar como lida', 'bi-envelope-open'] : ['naolida', 'Marcar como não lida', 'bi-envelope']
        ];
        if (d.lixeira) {
            itens.push(['restaurar', 'Restaurar', 'bi-arrow-counterclockwise']);
            itens.push(['definitivo', 'Excluir definitivamente', 'bi-x-octagon', true]);
        } else {
            itens.push(['lixeira', 'Mover para a lixeira', 'bi-trash']);
        }

        return itens.map(function (x, i) {
            return el('button', {
                type: 'button', class: 'te-menu-item' + (i === 0 ? ' is-sep' : '') + (x[3] ? ' is-perigo' : ''),
                onclick: function () { lote(c, x[0]); }
            }, [ic(x[2]), x[1]]);
        });
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
        var d = demandas().filter(function (x) { return x.codigo === m.codigo; })[0];
        if (d && !previa()) {
            itensDeMarcacao(d).forEach(function (b) { caixa.appendChild(b); });
        }
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

    /** Filtros do chat (provisório até o zip: o `chatFiltros` vinha do `renderVals()` perdido). */
    var CHAT_FILTROS = [
        ['todas', 'Todas', 'bi-chat-dots', 'Nenhuma mensagem nesta demanda.'],
        ['fav', 'Favoritas', 'bi-star', 'Nenhuma mensagem favorita.'],
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

    function enviarChatLote(codigo, ids, op) {
        return pedir('POST', ROTA.marcacoesMsg(codigo), { ids: ids, op: op }).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
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
                texto: 'Apagar esta mensagem para todos? Os outros participantes passam a ver "Mensagem apagada" no lugar do texto.',
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

    /** Menu de contexto de uma mensagem (desenho, linhas 423 a 429): título com autor e hora, e as ações dela. */
    function menuDaMensagem(caixa, m) {
        caixa.appendChild(el('div', { class: 'te-menu-tit', text: m.de + ' · ' + m.em }));
        var item = function (rot, icone, acao, perigo) {
            caixa.appendChild(el('button', {
                type: 'button', class: 'te-menu-item' + (perigo ? ' is-perigo' : ''),
                onclick: function () { fecharMenu(); acao(); }
            }, [ic(icone), rot]));
        };
        var sel = chatSelecionada(m.id);
        item(sel ? 'Desmarcar' : 'Selecionar', sel ? 'bi-square' : 'bi-check-square', function () { alternarChatSel(m.id); });
        if (m.lixeira) {
            item('Restaurar', 'bi-arrow-counterclockwise', function () { chatLote([m.id], 'restaurar'); });
            item('Excluir definitivamente', 'bi-x-octagon', function () { chatLote([m.id], 'definitivo'); }, true);
            return;
        }
        item(m.favorita ? 'Tirar dos favoritos' : 'Favoritar', m.favorita ? 'bi-star' : 'bi-star-fill', function () { chatLote([m.id], m.favorita ? 'desfav' : 'fav'); });
        item('Mover para a lixeira', 'bi-archive', function () { chatLote([m.id], 'lixeira'); });
        if (m.podeApagar && !m.apagada) {
            item('Apagar para todos', 'bi-trash', function () { apagarParaTodos(m); }, true);
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
        if (!pv && !m.lixeira) {
            cab.appendChild(el('span', { class: 'te-msg-acoes' }, [
                el('button', {
                    type: 'button', class: 'te-msg-btn' + (m.favorita ? ' is-fav' : ''), title: m.favorita ? 'Tirar dos favoritos' : 'Favoritar',
                    onclick: function () { chatLote([m.id], m.favorita ? 'desfav' : 'fav'); }
                }, ic(m.favorita ? 'bi-star-fill' : 'bi-star')),
                el('button', {
                    type: 'button', class: 'te-msg-btn', title: 'Mover para a lixeira',
                    onclick: function () { chatLote([m.id], 'lixeira'); }
                }, ic('bi-archive')),
                m.podeApagar && !m.apagada ? el('button', {
                    type: 'button', class: 'te-msg-btn is-perigo', title: 'Apagar para todos',
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
                ? el('div', { class: 'te-msg-apagada' }, [ic('bi-slash-circle'), 'Mensagem apagada'])
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
                    b.executar().then(function (r) { ok.disabled = false; if (r && r.ok) { fecharConf(); } });
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
        renderAviso();
        renderLista();
    }

    $('teBusca').addEventListener('input', function (e) {
        S.busca = e.target.value;
        renderLinhas();
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
        } else if (S.cfgAberta) {
            fecharCfg();
        } else if (S.hm) {
            fecharHm();
        } else if (S.menu) {
            fecharMenu();
        } else if (S.aberta && !S.nova) {
            fecharDrawer();
        }
    });

    renderTudo();

    if (S.painel.avisoDeMensagem) {
        var a = S.painel.avisoDeMensagem;
        mostrarToast({ id: a.codigo, tit: a.titulo, txt: a.texto, hora: a.hora });
    }

    var te = new URLSearchParams(location.search).get('te');
    if (te && demandas().some(function (d) { return d.codigo === te; })) {
        abrir(te);
    }
})();
