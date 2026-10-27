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
    var MARCADOR_CV = 'CV-0';
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
    var ABAS = { resumo: 'Resumo', proposta: 'Proposta', exec: 'Execução', chat: 'Chat', pag: 'Pagamento', hist: 'Histórico', whats: 'WhatsApp' };
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
        vgFiltro: 'aberta',
        vgVisitou: false,
        vgDel: null,
        vgBusca: '',
        vgSel: [],
        vgDet: null,
        tlm: null,
        vf: null,
        // Currículos e banco de talentos (spec VS 18.10.26 §4.5, fatia F3a)
        cv: null,
        cvFiltro: 'ativos',
        cvBusca: '',
        cvArea: '',
        cvSit: '',
        cvUf: '',
        cvTer: '',
        cvSel: [],
        cvDet: null,
        cvF: null,
        cvDoc: null,
        cvDel: null,
        tmo: null,
        // Painel do Master (spec VS 18.10.26 §4.9, fatia F3b-1)
        cvVista: null,
        cvPn: null,
        grModo: 'mes',
        grAno: null,
        grOn: {},
        // Campanha por e-mail do Master (spec VS 18.10.26 §4.10)
        cp: null,
        // Candidatura (spec VS 18.10.26 §4.6, fatia F2b)
        candSel: null,
        quase: null,
        quaseUlt: -1,
        // Visão externa, engajamento e avisos (spec VS 18.10.26 §4.7, fatia F4)
        eng: null,
        engIniciado: false,
        engVisivel: false,
        engAlertaEnviando: false,
        bv: null,
        dia: null
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

    /**
     * Selo "EXCLUSIVO DO PRIMÁRIO" (spec edicao-sem-prazo §5, item 8): o servidor manda o rótulo quando só o poder
     * sem prazo libera o apagar; o mesmo span do parcial `_selo_sem_prazo` (menu-usuario.css), visível no hover e no foco.
     */
    function seloSemPrazo(m) {
        if (vazio(m.seloSemPrazo)) {
            return null;
        }
        return el('span', { class: 'selo-sem-prazo' }, [el('i', { class: 'bi bi-star-fill', attrs: { 'aria-hidden': 'true' } }), m.seloSemPrazo]);
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
        despachar: function (c) { return comCodigo(D.urlDespachar, c); },
        pagamentoComprovante: function (c) { return comCodigo(D.urlPagamentoComprovante, c); },
        pagamentoConfirmar: function (c) { return comCodigo(D.urlPagamentoConfirmar, c); },
        pagamentoValor: function (c) { return comCodigo(D.urlPagamentoValor, c); },
        pagamentoCobranca: function (c) { return comCodigo(D.urlPagamentoCobranca, c); },
        pagamentoRecusar: function (c) { return comCodigo(D.urlPagamentoRecusar, c); },
        pagamentoAvisar: function (c) { return comCodigo(D.urlPagamentoAvisar, c); },
        despachoResponder: function (c) { return comCodigo(D.urlDespachoResponder, c); },
        despachoRespostas: function (c) { return comCodigo(D.urlDespachoRespostas, c); },
        despachoExtrato: function (c) { return comCodigo(D.urlDespachoExtrato, c); },
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
        vagaEditar: function (c) { return D.urlVagaEditar.replace(MARCADOR_VAGA, encodeURIComponent(c)); },
        vagaExcluir: function (c) { return D.urlVagaExcluir.replace(MARCADOR_VAGA, encodeURIComponent(c)); },
        vagasVisita: function () { return D.urlVagasVisita; },
        vagasMarcacoes: function () { return D.urlVagasMarcacoes; },
        vagasCompartilhar: function () { return D.urlVagasCompartilhar; },
        vagasDivulgacao: function () { return D.urlVagasDivulgacao; },
        // Currículos (§4.5): as rotas com {codigo} vêm com o marcador CV-0 (o requisito da rota é CV-\d+).
        curriculos: function () { return comComo(D.urlCurriculos); },
        curriculo: function (c) { return comComo(D.urlCurriculo.replace(MARCADOR_CV, encodeURIComponent(c))); },
        curriculoSalvar: function () { return D.urlCurriculoSalvar; },
        curriculoArquivo: function (t) { return D.urlCurriculoArquivo.replace('__tipo__', encodeURIComponent(t)); },
        // Sem data-url próprio: a rota de remover é a do arquivo com "/remover" no fim (contrato da §4.5).
        curriculoArquivoRemover: function (t) { return D.urlCurriculoArquivo.replace('__tipo__', encodeURIComponent(t)) + '/remover'; },
        curriculoExcluirMeu: function () { return D.urlCurriculoExcluirMeu; },
        curriculoExcluir: function (c) { return D.urlCurriculoExcluir.replace(MARCADOR_CV, encodeURIComponent(c)); },
        curriculoStatus: function (c) { return D.urlCurriculoStatus.replace(MARCADOR_CV, encodeURIComponent(c)); },
        curriculoNota: function (c) { return D.urlCurriculoNota.replace(MARCADOR_CV, encodeURIComponent(c)); },
        // Termo de voluntariado: o rascunho é leitura (aceita `?como=`); prévia, envio e aceite são escritas.
        termoRascunho: function (c) { return comComo(D.urlTermoRascunho.replace(MARCADOR_CV, encodeURIComponent(c))); },
        termoPrevia: function (c) { return D.urlTermoPrevia.replace(MARCADOR_CV, encodeURIComponent(c)); },
        termoEnviar: function (c) { return D.urlTermoEnviar.replace(MARCADOR_CV, encodeURIComponent(c)); },
        termoAceitar: function (c) { return D.urlTermoAceitar.replace('TV-0000', encodeURIComponent(c)); },
        curriculosMarcacoes: function () { return D.urlCurriculosMarcacoes; },
        curriculoPrevia: function () { return D.urlCurriculoPrevia; },
        curriculosCsv: function (q) { return comComo(D.urlCurriculosCsv + (q ? (D.urlCurriculosCsv.indexOf('?') >= 0 ? '&' : '?') + q : '')); },
        // Candidatura (§4.6): só escrita, nunca com `?como=`. A de status vem com o id 0 no lugar do da candidatura.
        vagaCandidatura: function (c) { return String(D.urlVagaCandidatura || '').replace(MARCADOR_VAGA, encodeURIComponent(c)); },
        vagaRecusa: function (c) { return String(D.urlVagaRecusa || '').replace(MARCADOR_VAGA, encodeURIComponent(c)); },
        vagaRecusaDesfazer: function (c) { return String(D.urlVagaRecusaDesfazer || '').replace(MARCADOR_VAGA, encodeURIComponent(c)); },
        vagaEnviar: function (c) { return String(D.urlVagaEnviar || '').replace(MARCADOR_VAGA, encodeURIComponent(c)); },
        candidaturaStatus: function (id) { return String(D.urlCandidaturaStatus || '').replace(/\/0\/status$/, '/' + encodeURIComponent(id) + '/status'); },
        // Engajamento (§4.7): a leitura aceita `?como=`; o aviso de vagas é escrita (nunca na prévia).
        engajamento: function (q) {
            var u = String(D.urlEngajamento || '');
            return u ? comComo(u + (q ? (u.indexOf('?') >= 0 ? '&' : '?') + q : '')) : '';
        },
        vagasAlerta: function () { return String(D.urlVagasAlerta || ''); }
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
        // O banco de currículos também: some a aba até a resposta no novo recorte.
        S.cv = null;
        S.cvSel = [];
        // Engajamento (§4.7): banner, selos e avisos voltam no recorte do novo perfil.
        S.eng = null;
        fecharBoasVindas();
        fecharLembrete();
        renderModulos();
        carregarVagas();
        carregarCurriculos();
        pedir('GET', comComo(D.urlPainel)).then(function (r) {
            if (!r.ok || !r.painel) {
                S.como = antes;
                toastErro(r.mensagem);
                carregarVagas();
                carregarCurriculos();
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
        // Configuração das vagas e a campanha por e-mail (desenho, l.1265; campanha na spec §4.10).
        if (veVagas() && vgMaster() && cfgVagas()) {
            sec('VAGAS E CURRÍCULOS');
            // D12 (desenho 06 de 24/10, l.1340): liga e desliga o `vagas.sino` direto do menu.
            var sino = cfgVagas().sino !== false;
            item(sino ? 'Alertas de vagas ativados' : 'Alertas de vagas desativados', sino ? 'bi-bell' : 'bi-bell-slash', sino, function () {
                fecharHm();
                alternarAlertasDeVagas(!sino);
            });
            item('Ofertas, avisos e mensagens…', 'bi-sliders', false, function () { fecharHm(); abrirCfgVagas(); });
            item('Campanha por e-mail…', 'bi-envelope-paper', false, function () { fecharHm(); abrirCampanha(); });
        }
        sec('CONFIGURAÇÕES');
        item('Equipe e privacidade…', 'bi-shield-lock', false, function () { fecharHm(); abrirCfg(); });
        item('Copiar link da aba', 'bi-link-45deg', false, function () {
            fecharHm();
            copiar(location.href.split('?')[0].split('#')[0]);
            mostrarToast({ tit: 'Link copiado', txt: 'Link da aba Trabalho Externo copiado.', hora: '' });
        });

        return menu;
    }

    /** Grava o `vagas.sino` e mostra o título do novo estado (`hmItens`, l.1340); recusado, volta e avisa. */
    function alternarAlertasDeVagas(ligar) {
        var c = cfgVagas();
        if (!c || !vgMaster() || !podeEscreverVaga()) {
            return;
        }
        var antes = c.sino;
        c.sino = ligar;
        pedir('POST', D.urlConfiguracao, { vagas: { sino: ligar } }).then(function (r) {
            if (!r.ok) {
                var atual = cfgVagas();
                if (atual) {
                    atual.sino = antes;
                }
                toastErro(r.mensagem);
                return;
            }
            mostrarToast(ligar
                ? { tit: 'Alertas de vagas ativados', txt: 'Os usuários voltam a receber o sininho e o aviso de vaga nova.', hora: '' }
                : { tit: 'Alertas de vagas desativados', txt: 'Ninguém recebe o sininho nem o aviso de vaga nova até você ativar de novo.', hora: '' });
        });
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
        var caixa = el('div', { class: 'te-menu' + (m.tipo ? '' : ' te-menu--demanda'), style: { left: m.x + 'px', top: m.y + 'px' } });
        if (m.tipo) {
            caixa.setAttribute('role', 'menu');
        }
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
        if (m.tipo === 'cvselpor') {
            caixa.style.width = m.largura + 'px';
            CV_SEL_OPS.forEach(function (o) {
                caixa.appendChild(el('button', {
                    type: 'button', class: 'te-menu-item', text: o[0],
                    onclick: function () {
                        fecharMenu();
                        S.cvSel = cvFiltrados().filter(o[1]).map(function (x) { return x.codigo; });
                        renderCurriculos();
                    }
                }));
            });
            alvo.appendChild(caixa);

            return;
        }
        if (m.tipo === 'cv') {
            caixa.style.width = m.largura + 'px';
            menuDoCv(caixa, m.codigo);
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
        if (dz.pausada) {
            alvo.appendChild(el('div', { class: 'te-previa', attrs: { role: 'status' } }, [ic('bi-pause-circle'), el('span', { text: dz.pausada })]));
        }
        if (botoes.length) {
            alvo.appendChild(el('div', { class: 'te-acoes' }, botoes.map(function (b) {
                return el('button', {
                    type: 'button',
                    class: 'te-btn-acao' + (b.tipo === 'pri' ? ' te-btn-acao--pri' : b.tipo === 'perigo' ? ' te-btn-acao--perigo' : ''),
                    onclick: function () { executar(dz.codigo, b); }
                }, [ic(b.ic), b.rot]);
            })));
        }
        var abas = { resumo: abaResumo, proposta: abaProposta, exec: abaExecucao, chat: abaChat, pag: abaPagamento, hist: abaHistorico, whats: abaWhatsapp };
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
        if (dz.despacho) {
            despachoDoResumo(alvo, dz);
        }
    }

    /* ---------------------------------------------------------------- DESPACHO no Resumo (desenho 06 de 24/10, l.512 a 530) */

    /**
     * O servidor decide quem despacha (`podeDespachar`, só o Master), quem responde (`podeResponder`) e o que cada
     * um lê (`lista`, já na ordem do desenho: o mais novo em cima). O rascunho do despacho fica em `S.f` (some ao
     * trocar de demanda, fica ao redesenhar).
     */
    function despachoDoResumo(alvo, dz) {
        var P = dz.despacho, f = S.f;
        alvo.appendChild(el('div', { class: 'te-dp-cab' }, el('div', { class: 'te-dp-cab-linha' }, [
            el('b', { class: 'te-dp-tit', text: 'DESPACHO' }),
            P.respInfo ? el('span', { class: 'te-dp-resp-info' }, [ic('bi-reply'), ' Respostas liberadas']) : null
        ])));
        var corpo = el('div', { class: 'te-dp-corpo' });
        alvo.appendChild(corpo);
        if (P.podeDespachar) {
            formularioDoDespacho(corpo, dz, P, f);
        }
        if (P.leSem) {
            corpo.appendChild(el('div', { class: 'te-dp-lesem' }, [ic('bi-info-circle'), ' Os despachos são publicados pela Central BlueJus.']));
        }
        if (P.podeResponder) {
            var resp = el('textarea', {
                class: 'te-dp-txt', rows: 3, maxLength: 3000, value: f.dpRTxt || '',
                placeholder: 'Responder ao despacho. A Central BlueJus recebe na hora.',
                oninput: function (e) { f.dpRTxt = e.target.value; }
            });
            corpo.appendChild(el('div', { class: 'te-dp-resp' }, [
                resp,
                el('div', { class: 'te-dp-resp-acoes' }, el('button', { type: 'button', class: 'te-dp-btn', onclick: function () { responderAoDespacho(dz.codigo); } }, [ic('bi-reply'), 'Responder']))
            ]));
        }
        if (!(P.lista || []).length) {
            corpo.appendChild(el('div', { class: 'te-dp-vazio' }, [ic('bi-clock-history'), 'Nenhum despacho nesta demanda.']));
        }
        (P.lista || []).forEach(function (x) { corpo.appendChild(itemDoDespacho(dz, x)); });
        var on = !!P.respostasLiberadas;
        corpo.appendChild(el('div', { class: 'te-dp-rodape' }, [
            P.podeDespachar ? el('button', {
                type: 'button', class: 'te-dp-chave',
                title: on ? 'O solicitante e o profissional podem responder. Clique para bloquear.' : 'Permitir que o solicitante e o profissional respondam aos despachos',
                attrs: { 'aria-pressed': on ? 'true' : 'false' },
                onclick: function () { liberarRespostas(dz.codigo, !on); }
            }, ['Aceitar resposta', el('span', { class: 'te-dp-chave-trilho' + (on ? ' is-on' : '') }, el('span', { class: 'te-dp-chave-bola' }))]) : null,
            el('button', { type: 'button', class: 'te-dp-extrato', onclick: function () { window.open(comComo(ROTA.despachoExtrato(dz.codigo)), '_blank'); } }, [ic('bi-printer'), 'Extrato'])
        ]));
    }

    /** O formulário do Master (l.514 a 518): natureza, prazo e dias úteis, texto com contador, anexos e "Despachar". */
    function formularioDoDespacho(corpo, dz, P, f) {
        var linha = el('div', { class: 'te-dp-linha' }, [el('span', { class: 'te-dp-nat-rot', text: 'Natureza' })]);
        (P.naturezas || []).forEach(function (n) {
            linha.appendChild(el('button', {
                type: 'button', class: 'te-dp-nat' + (f.dpNat === n.k ? ' is-on' : ''), text: n.r,
                attrs: { 'aria-pressed': f.dpNat === n.k ? 'true' : 'false' },
                onclick: function () { f.dpNat = f.dpNat === n.k ? '' : n.k; renderDrawerConteudo(); }
            }));
        });
        var uteis = el('span', { class: 'te-dp-uteis', text: diasUteisDoDespacho(P, f.dpPrazo) });
        linha.appendChild(el('span', { class: 'te-espaco' }));
        linha.appendChild(el('label', { class: 'te-dp-prazo' }, [
            ic('bi-calendar3'), 'Prazo',
            el('input', { type: 'date', value: f.dpPrazo || P.prazoPadrao || '', onchange: function (e) { f.dpPrazo = e.target.value; uteis.textContent = diasUteisDoDespacho(P, f.dpPrazo); } })
        ]));
        linha.appendChild(uteis);
        corpo.appendChild(linha);

        var n = el('span', { class: 'te-dp-n', text: String((f.dpTxt || '').length) + ' / 5.000' });
        corpo.appendChild(el('textarea', {
            class: 'te-dp-txt', rows: 3, maxLength: 5000, value: f.dpTxt || '',
            placeholder: 'Escreva o despacho. Ele fica visível para o solicitante e para a equipe.',
            oninput: function (e) { f.dpTxt = e.target.value; n.textContent = String(f.dpTxt.length) + ' / 5.000'; }
        }));
        var arqs = f.dpArq || [];
        if (arqs.length) {
            corpo.appendChild(el('div', { class: 'te-dp-arqs' }, arqs.map(function (a, i) {
                return el('span', { class: 'te-dp-arq' }, [
                    ic('bi-paperclip'), a.name,
                    el('button', { type: 'button', class: 'te-dp-arq-rm', title: 'Remover', attrs: { 'aria-label': 'Remover ' + a.name }, onclick: function () { f.dpArq = arqs.filter(function (_, j) { return j !== i; }); renderDrawerConteudo(); } }, ic('bi-x'))
                ]);
            })));
        }
        corpo.appendChild(el('div', { class: 'te-dp-acoes' }, [
            el('label', { class: 'te-dp-anexar' }, [
                ic('bi-paperclip'), 'Anexar arquivo',
                el('input', { type: 'file', multiple: true, onchange: function (e) {
                    f.dpArq = (f.dpArq || []).concat(Array.prototype.slice.call(e.target.files || []));
                    e.target.value = '';
                    renderDrawerConteudo();
                } })
            ]),
            el('span', { class: 'te-espaco' }),
            n,
            el('button', { type: 'button', class: 'te-dp-btn', onclick: function () { despachar(dz.codigo); } }, [ic('bi-send'), 'Despachar'])
        ]));
    }

    /**
     * `dpUteis` (l.1393): sem data escolhida, "5 dias úteis"; com data, os dias úteis de amanhã até ela (sábado,
     * domingo e os feriados do sistema, que o servidor manda em `feriados`, não contam).
     */
    function diasUteisDoDespacho(P, alvo) {
        if (!alvo) {
            return '5 dias úteis';
        }
        var feriados = P.feriados || [], fim = new Date(alvo + 'T12:00'), d = new Date(), n = 0;
        d.setHours(12, 0, 0, 0);
        while (d < fim) {
            d.setDate(d.getDate() + 1);
            if (d.getDay() % 6 && feriados.indexOf(d.toLocaleDateString('sv-SE')) < 0) {
                n++;
            }
        }
        return n + (n === 1 ? ' dia útil' : ' dias úteis');
    }

    /** Uma linha da lista (l.521 a 527): despacho em azul ou resposta em verde, recuada. */
    function itemDoDespacho(dz, x) {
        var resposta = x.tipo === 'resposta', arqs = x.arqs || [];
        var anexos = [];
        arqs.forEach(function (a, i) {
            if (i) {
                anexos.push(', ');
            }
            anexos.push(el('a', { href: ROTA.documento(dz.codigo, a.id), target: '_blank', rel: 'noopener', text: a.nome }));
        });
        return el('div', { class: 'te-dp-item' + (resposta ? ' is-resposta' : '') }, [
            el('div', { class: 'te-dp-item-cab' }, [
                el('b', { class: 'te-dp-item-n', text: x.n }),
                x.nat ? el('span', { class: 'te-dp-item-nat', text: x.nat }) : null,
                el('span', { class: 'te-espaco' }),
                x.prazo ? el('span', { class: 'te-dp-item-prazo' }, [ic('bi-calendar3'), ' ' + x.prazo]) : null
            ]),
            el('div', { class: 'te-dp-item-txt', text: x.txt }),
            arqs.length ? el('div', { class: 'te-dp-item-arqs' }, [ic('bi-paperclip'), ' '].concat(anexos)) : null,
            el('div', { class: 'te-dp-item-local', text: x.local }),
            el('div', { class: 'te-dp-item-ass' }, [ic('bi-patch-check-fill'), x.ass])
        ]);
    }

    /** `dpEnviar` (l.1394): ao menos 3 caracteres, senão "Escreva o despacho"; o resto o servidor confere. */
    function despachar(codigo) {
        var f = S.f, t = String(f.dpTxt || '').trim();
        if (previa()) {
            return;
        }
        if (t.length < 3) {
            mostrarToast({ tit: 'Escreva o despacho', txt: 'O texto do despacho está vazio.', hora: '' });
            return;
        }
        var fd = new FormData();
        fd.append('texto', t);
        fd.append('natureza', f.dpNat || '');
        fd.append('prazo', f.dpPrazo || '');
        (f.dpArq || []).forEach(function (a) { fd.append('documentos[]', a, a.name); });
        pedir('POST', ROTA.despachar(codigo), fd).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return;
            }
            f.dpTxt = '';
            f.dpNat = '';
            f.dpPrazo = '';
            f.dpArq = [];
            toastDoServidor(r.toast, 'Despacho publicado', 'O solicitante vê o despacho no Resumo e recebe um aviso.');
            aplicarDemanda(r);
        });
    }

    /** `dpResponder`: com menos de 2 caracteres nada acontece, como no desenho. */
    function responderAoDespacho(codigo) {
        var f = S.f, t = String(f.dpRTxt || '').trim();
        if (previa() || t.length < 2) {
            return;
        }
        pedir('POST', ROTA.despachoResponder(codigo), { texto: t }).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return;
            }
            f.dpRTxt = '';
            toastDoServidor(r.toast, 'Resposta enviada', 'A Central BlueJus foi avisada.');
            aplicarDemanda(r);
        });
    }

    function liberarRespostas(codigo, liberar) {
        if (previa()) {
            return;
        }
        pedir('POST', ROTA.despachoRespostas(codigo), { liberar: liberar }).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return;
            }
            aplicarDemanda(r);
        });
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
                rot('Prazo estimado', ctl('input', 'prazoEst', { placeholder: 'Prazo para executar' })),
                rot('Validade da proposta', ctl('input', 'validade', { type: 'date' }))
            ]),
            rot('Condições de pagamento', ctl('input', 'pagamento', { placeholder: 'Forma de pagamento' })),
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
            var aviso = avisoEmailAConfirmar();
            if (aviso && (dz.podeConvidar || convites.some(function (c) { return c.gerir; }))) {
                alvo.appendChild(aviso);
            }
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

    /**
     * Conta com o e-mail a confirmar não convida nem reenvia (o servidor recusa com 422). O template só põe os
     * data-* quando é o caso; aqui vira o aviso com o botão "Reenviar o link de confirmação" (POST comum, volta com
     * flash). Sem os data-*, nada.
     */
    function avisoEmailAConfirmar() {
        if (!D.urlReenviarConfirmacao) {
            return null;
        }
        return el('div', { class: 'te-caixa te-conv-confirmar' }, [
            el('div', { class: 'te-conv-form-txt', text: D.recusaEmailAConfirmar || '' }),
            el('form', { attrs: { method: 'post', action: D.urlReenviarConfirmacao } }, [
                el('input', { type: 'hidden', name: '_token', value: D.csrfReenviarConfirmacao || '' }),
                el('button', { type: 'submit', class: 'te-btn-contorno', text: 'Reenviar o link de confirmação' })
            ])
        ]);
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
        // Como o desenho (l.1334): confirm() nativo; cancelar não faz nada.
        if (!window.confirm('Apagar esta mensagem? Ela some para todos e fica registrado que foi apagada.')) {
            return;
        }
        pedir('POST', ROTA.apagarMsg(codigo, m.id)).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return;
            }
            aplicarDemandaNoChat(r);
        });
    }

    /**
     * Menu de contexto de uma mensagem (`ctx` do desenho). Na lixeira: só restaurar e excluir. Fora:
     * selecionar, favoritar e copiar (se não apagada), lixeira e apagar para todos; quando o servidor
     * nega o apagar, mostra o motivo num item travado que só fecha o menu.
     */
    function menuDaMensagem(caixa, m) {
        var item = function (rot, icone, acao, classe, selo) {
            caixa.appendChild(el('button', {
                type: 'button', class: 'te-menu-item' + (classe ? ' ' + classe : ''),
                onclick: function () { fecharMenu(); acao(); }
            }, [ic(icone), rot, selo || null]));
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
                type: 'button', class: 'te-chat-todas', title: todas ? 'Desmarcar todas' : 'Selecionar todas',
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
                    type: 'button', class: 'te-msg-btn is-perigo' + (m.seloSemPrazo ? ' com-selo-sem-prazo' : ''), title: m.apagarDica || 'Apagar para todos',
                    onclick: function () { apagarParaTodos(m); }
                }, [ic('bi-trash'), seloSemPrazo(m)]) : null
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

    /* ---------------------------------------------------------------- aba Pagamento (desenho 06 de 25/10, l.620 a 637 e 1417 a 1427) */

    /**
     * O servidor monta tudo em `dz.pagamento` (valor, forma, código, QR, estado e quem pode o quê); aqui só se pinta.
     * O Convidado não recebe o campo nem a aba. O SVG do QR vem do serviço do servidor (nunca de entrada do usuário)
     * e entra como nó, lido como XML: só um <svg> raiz é aceito.
     */
    function abaPagamento(alvo, dz) {
        var pg = dz.pagamento;
        if (!pg) { return; }
        var cod = dz.codigo;
        var caixa = el('div', { class: 'te-pg' });
        prepararCobranca(cod, pg);
        caixa.appendChild(el('div', { class: 'te-pg-st', style: { background: pg.stBg, color: pg.stCor } }, [ic(pg.stIc), pg.stTxt]));
        if (pg.semValor) {
            caixa.appendChild(el('div', { class: 'te-pg-sem', text: 'O pagamento fica disponível quando uma proposta for aceita. O valor e o QR Code PIX são gerados automaticamente.' }));
        }
        if (S.avisoPg && S.avisoPg[cod]) {
            caixa.appendChild(el('div', { class: 'te-pg-sem', text: S.avisoPg[cod] }));
        }
        if (pg.avisoDoBanco) {
            caixa.appendChild(el('div', { class: 'te-pg-sem', text: pg.avisoDoBanco }));
        }
        if (pg.vencida) {
            caixa.appendChild(el('div', { class: 'te-pg-sem', text: 'Cobrança vencida.' }));
            caixa.appendChild(el('button', { type: 'button', class: 'te-pg-copiar', onclick: function () { prepararCobranca(cod, pg, true); } }, [ic('bi-qr-code'), ' Gerar novo QR']));
        }
        if (pg.temValor) {
            var linha = el('div', { class: 'te-pg-linha' });
            if (pg.mostraQr) {
                var qr = el('div', { class: 'te-pg-qr' });
                var svg = svgDoQr(pg.qr);
                if (svg) { qr.appendChild(svg); }
                linha.appendChild(qr);
            }
            var dados = el('div', { class: 'te-pg-dados' }, [
                el('div', { class: 'te-pg-rot', text: pg.valorAjustado ? 'VALOR DO PAGAMENTO' : 'VALOR DA PROPOSTA ACEITA' }),
                el('span', { class: 'te-pg-valor-linha' }, [
                    el('b', { class: 'te-pg-valor', text: pg.valor }),
                    pg.podeEditarValor ? el('button', {
                        type: 'button', class: 'te-pg-lapis', title: 'Alterar o valor', attrs: { 'aria-label': 'Alterar o valor do pagamento' },
                        onclick: function () { alterarValorDoPagamento(cod, pg); }
                    }, [ic('bi-pencil')]) : null
                ]),
                el('span', { class: 'te-pg-forma', text: pg.forma }),
                pg.dinamico && pg.venceEm ? el('span', { class: 'te-pg-forma', text: 'Confirmação automática pelo banco · válido até ' + pg.venceEm }) : null,
                el('span', null, ['Favorecido: ', el('b', { text: 'BlueJus Gestão e Tecnologia LTDA' })]),
                el('span', null, ['CNPJ 66.803.163/0001-10 · identificação ', el('b', { text: pg.txid })])
            ]);
            if (pg.mostraQr) {
                dados.appendChild(el('div', { class: 'te-pg-cod', text: pg.cod }));
                var rotCopiar = el('span', { text: 'Copiar código PIX' });
                var espera = null;
                var botoes = el('div', { class: 'te-pg-botoes' }, [
                    el('button', {
                        type: 'button', class: 'te-pg-copiar',
                        onclick: function () {
                            copiar(pg.cod);
                            rotCopiar.textContent = 'Copiado';
                            clearTimeout(espera);
                            espera = setTimeout(function () { rotCopiar.textContent = 'Copiar código PIX'; }, 2000);
                        }
                    }, [ic('bi-qr-code'), ' ', rotCopiar])
                ]);
                if (pg.podeConfirmar) {
                    botoes.appendChild(el('button', { type: 'button', class: 'te-pg-chat', onclick: function () { avisarPagamentoNoChat(cod); } }, [ic('bi-chat-dots'), ' Enviar no chat']));
                }
                dados.appendChild(botoes);
            }
            linha.appendChild(dados);
            caixa.appendChild(linha);
            if (pg.podeComprovante) {
                caixa.appendChild(el('label', { class: 'te-pg-comp' }, [
                    ic('bi-paperclip'), 'Já paguei · enviar comprovante',
                    el('input', { type: 'file', accept: '.pdf,image/*', onchange: function (e) { enviarComprovante(cod, e.target); } })
                ]));
            }
            if (pg.comprovante) {
                caixa.appendChild(el('button', {
                    type: 'button', class: 'te-pg-baixar',
                    onclick: function () { window.open(ROTA.documento(cod, pg.comprovante.id), '_blank', 'noopener'); }
                }, [ic('bi-paperclip'), ' ' + pg.comprovante.nome]));
            }
            if (pg.podeRecusar) {
                caixa.appendChild(el('button', { type: 'button', class: 'te-pg-recusar', onclick: function () { recusarComprovante(cod, pg); } }, [ic('bi-x-circle'), ' Recusar comprovante']));
            }
            if (pg.podeConfirmar) {
                caixa.appendChild(el('button', { type: 'button', class: 'te-pg-conf', onclick: function () { confirmarRecebimento(cod, pg); } }, [ic('bi-check2-circle'), ' Confirmar recebimento']));
            }
        }
        alvo.appendChild(caixa);
    }

    /**
     * Ao abrir a aba o servidor prepara a cobrança PIX dinâmica (só quando a integração está ligada: `pg.preparar`). Uma
     * tentativa por demanda e por abertura da tela; o botão "Gerar novo QR" (`novo`) pede outra. O aviso vai para a aba.
     */
    function prepararCobranca(codigo, pg, novo) {
        S.preparado = S.preparado || {};
        S.avisoPg = S.avisoPg || {};
        if (previa() || !(pg.preparar || novo) || (S.preparado[codigo] && !novo)) { return; }
        S.preparado[codigo] = true;
        pedir('POST', ROTA.pagamentoCobranca(codigo), { novo: !!novo }).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return;
            }
            S.avisoPg[codigo] = r.avisoPagamento || '';
            aplicarDemanda(r);
        });
    }

    /** O SVG do QR como nó (XML), ou null se não vier um <svg> inteiro. */
    function svgDoQr(texto) {
        if (vazio(texto)) { return null; }
        var doc = new DOMParser().parseFromString(texto, 'image/svg+xml');
        var raiz = doc.documentElement;
        if (!raiz || raiz.nodeName !== 'svg' || doc.getElementsByTagName('parsererror').length) { return null; }
        return document.importNode(raiz, true);
    }

    function enviarComprovante(codigo, input) {
        var arquivo = input.files && input.files[0];
        input.value = '';
        if (!arquivo || previa()) { return; }
        var fd = new FormData();
        fd.append('comprovante', arquivo, arquivo.name);
        pedir('POST', ROTA.pagamentoComprovante(codigo), fd).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return;
            }
            aplicarDemanda(r);
        });
    }

    /** `pg.avisarChat`: o servidor grava o aviso do PIX no chat e a tela abre a aba Chat. */
    function avisarPagamentoNoChat(codigo) {
        if (previa()) { return; }
        pedir('POST', ROTA.pagamentoAvisar(codigo)).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return;
            }
            aplicarDemanda(r);
            trocarAba('chat');
        });
    }

    /**
     * O lápis do Master: o diálogo da tela com o campo de valor e o motivo. O servidor lê o valor ("1.234,56"),
     * confere tudo de novo e responde com a demanda inteira; erro volta no toast e o diálogo segue aberto.
     */
    function alterarValorDoPagamento(codigo, pg) {
        if (previa()) { return; }
        abrirConf(codigo, {
            k: '__pagamento_valor__',
            confirmacao: {
                titulo: 'Alterar o valor do pagamento',
                texto: 'Valor atual: ' + pg.valor + '. Informe o novo valor e o motivo. O solicitante será avisado.',
                botao: 'Salvar', motivo: true, motivoMax: 300,
                campoValor: { rotulo: 'Novo valor (R$)', inicial: String(pg.valorNumero || '').replace('.', ',') }
            },
            executar: function (motivo, valor) {
                return pedir('POST', ROTA.pagamentoValor(codigo), { valor: valor, motivo: motivo }).then(function (r) {
                    if (!r.ok) {
                        toastErro(r.mensagem);
                        return r;
                    }
                    toastDoServidor(r.toast, 'Valor alterado', 'O solicitante foi avisado no chat e por e-mail.');
                    aplicarDemanda(r);
                    return r;
                });
            }
        });
    }

    /** "Recusar comprovante" (só Master): o diálogo da tela com o motivo; o servidor tira o comprovante e avisa o solicitante. */
    function recusarComprovante(codigo, pg) {
        if (previa()) { return; }
        abrirConf(codigo, {
            k: '__pagamento_recusar__',
            confirmacao: {
                titulo: 'Recusar comprovante',
                texto: 'O comprovante ' + (pg.comprovante ? pg.comprovante.nome : '') + ' será removido e o solicitante poderá enviar outro. Informe o motivo. O solicitante será avisado.',
                botao: 'Recusar', motivo: true, motivoMax: 300
            },
            executar: function (motivo) {
                return pedir('POST', ROTA.pagamentoRecusar(codigo), { motivo: motivo }).then(function (r) {
                    if (!r.ok) {
                        toastErro(r.mensagem);
                        return r;
                    }
                    toastDoServidor(r.toast, 'Comprovante recusado', 'O solicitante foi avisado no chat e por e-mail.');
                    aplicarDemanda(r);
                    return r;
                });
            }
        });
    }

    /** `pg.confirmar`: o diálogo da tela (o desenho usa o confirm nativo do protótipo) com o texto do desenho. */
    function confirmarRecebimento(codigo, pg) {
        if (previa()) { return; }
        abrirConf(codigo, {
            k: '__pagamento_pix__',
            confirmacao: pg.confirmaComMotivo
                ? { titulo: 'Confirmar recebimento', texto: pg.confirmacao + ' Há um PIX dinâmico aguardando o banco: ao confirmar à mão, essa cobrança é cancelada. Informe o motivo.', botao: 'Confirmar', motivo: true, motivoMax: 300 }
                : { titulo: 'Confirmar recebimento', texto: pg.confirmacao, botao: 'Confirmar', motivo: false },
            executar: function (motivo) {
                return pedir('POST', ROTA.pagamentoConfirmar(codigo), motivo ? { motivo: motivo } : undefined).then(function (r) {
                    if (!r.ok) {
                        toastErro(r.mensagem);
                        return r;
                    }
                    toastDoServidor(r.toast, 'Pagamento confirmado', 'O solicitante foi avisado no chat e por e-mail.');
                    aplicarDemanda(r);
                    return r;
                });
            }
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

    /** O escritório guardado pelo usuário (id `s<n>`) que está escolhido no select; null se for escritório da plataforma. */
    function escritorioSalvoEscolhido(nf) {
        var achado = (dadosNova().escritorios || []).filter(function (e) { return e.salvo && String(e.id) === String(nf.escId); })[0];
        return achado || null;
    }

    /** `escMudou` do desenho: nome, e-mail, telefone, UF ou município digitados divergem do que o escritório preenche. */
    function escritorioSalvoMudou(nf) {
        var x = escritorioSalvoEscolhido(nf);
        if (!x) {
            return false;
        }
        var base = doEsc(x, {});
        return ['escritorio', 'email', 'telefone', 'ufOrigem', 'cidadeOrigem'].some(function (k) { return String(base[k] || '') !== String(nf[k] || ''); });
    }

    /** Mostra ou esconde o "Salvar edição" enquanto o usuário digita (sem redesenhar o modal e perder o foco). */
    function atualizarBotaoEdicao() {
        var b = $('teEscSalvarEdicao');
        if (b && S.nova) {
            b.hidden = !escritorioSalvoMudou(S.nova.nf);
        }
    }

    function dadosDoEscritorioDigitado(nf) {
        return { nome: String(nf.escritorio || '').trim(), email: nf.email || '', telefone: nf.telefone || '', uf: nf.ufOrigem || '', cidade: nf.cidadeOrigem || '' };
    }

    /** "Novo escritório" (l.1438 do desenho): guarda os dados digitados e seleciona o recém-criado. */
    function guardarNovoEscritorio() {
        var N = S.nova;
        if (!N || N.salvandoEsc) {
            return;
        }
        if (!String(N.nf.escritorio || '').trim()) {
            N.erro = 'Informe o nome do escritório para salvar.';
            mostrarErroNova();
            return;
        }
        N.salvandoEsc = true;
        pedir('POST', D.urlEscritoriosSolicitantes, dadosDoEscritorioDigitado(N.nf)).then(function (r) {
            N.salvandoEsc = false;
            if (S.nova !== N) {
                return;
            }
            if (!r.ok) {
                N.erro = r.mensagem;
                mostrarErroNova();
                return;
            }
            dadosNova().escritorios.push(r.escritorio);
            N.erro = '';
            N.nf.escId = r.escritorio.id;
            renderNova();
            mostrarToast({ tit: r.aviso.titulo, txt: r.aviso.texto, hora: '' });
        });
    }

    /** "Salvar edição" (l.1437 do desenho): grava as alterações no escritório guardado que está escolhido. */
    function atualizarEscritorioSalvo() {
        var N = S.nova, x = N ? escritorioSalvoEscolhido(N.nf) : null;
        if (!N || !x || N.salvandoEsc) {
            return;
        }
        if (!String(N.nf.escritorio || '').trim()) {
            N.erro = 'Informe o nome do escritório para salvar.';
            mostrarErroNova();
            return;
        }
        N.salvandoEsc = true;
        pedir('POST', D.urlEscritoriosSolicitantes + '/' + encodeURIComponent(String(x.id).replace(/^s/, '')), dadosDoEscritorioDigitado(N.nf)).then(function (r) {
            N.salvandoEsc = false;
            if (S.nova !== N) {
                return;
            }
            if (!r.ok) {
                N.erro = r.mensagem;
                mostrarErroNova();
                return;
            }
            var lista = dadosNova().escritorios;
            lista.forEach(function (e, i) { if (e.salvo && e.id === r.escritorio.id) { lista[i] = r.escritorio; } });
            N.erro = '';
            renderNova();
            mostrarToast({ tit: r.aviso.titulo, txt: r.aviso.texto, hora: '' });
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
                props.onchange = function (ev) { nf[k] = ev.target.value; atualizarBotaoEdicao(); };
                controle = el('select', props, e.ops.map(function (o) { return el('option', { value: o, text: o }); }));
            } else if (e.area) {
                props.rows = 3;
                props.placeholder = e.ph || '';
                props.value = nf[k] || '';
                props.oninput = function (ev) { nf[k] = ev.target.value; marcarErro(ev.target, k, e.req); atualizarBotaoEdicao(); };
                controle = el('textarea', props);
            } else {
                props.type = e.tipo || 'text';
                props.placeholder = e.ph || '';
                props.value = nf[k] || '';
                props.oninput = function (ev) { nf[k] = ev.target.value; marcarErro(ev.target, k, e.req); atualizarBotaoEdicao(); };
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
            // Pelo Master o escritório é um da plataforma (os guardados não entram), então não há o que guardar.
            P.peloMaster ? null : el('button', {
                type: 'button', class: 'te-perfil-btn', id: 'teEscSalvarEdicao', title: 'Gravar as alterações neste escritório',
                hidden: !escritorioSalvoMudou(nf), onclick: atualizarEscritorioSalvo
            }, [ic('bi-pencil'), 'Salvar edição']),
            P.peloMaster ? null : el('button', {
                type: 'button', class: 'te-perfil-btn', title: 'Guardar os dados digitados como um novo escritório', onclick: guardarNovoEscritorio
            }, [ic('bi-plus-lg'), 'Novo escritório']),
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
        // Só o Master manda `escritorioId`, e só o de um escritório da plataforma: o guardado (`s<n>`) nunca vira tenant.
        if (dadosNova().peloMaster && !escritorioSalvoEscolhido(N.nf)) {
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
            var nf = N.nf || {};
            fecharNova();
            // Enviar: a animação do desenho 06 de 24/10 (l.737 a 767) no lugar do toast "Solicitação enviada".
            // O rascunho continua com o "Rascunho salvo".
            if (enviar && r.codigo) {
                abrirEnvio({ id: r.codigo, cid: nf.cidadeExecucao || '', uf: nf.ufExecucao || '', serv: nf.tipoServico || 'Trabalho externo' });
            } else if (r.aviso) {
                mostrarToast({ tit: r.aviso.titulo, txt: r.aviso.texto, hora: '' });
            }
            if (S.aberta && S.aberta === N.rascId) {
                abrir(S.aberta, S.aba);
            }
            recarregarPainel();
        });
    }

    /* ---------------------------------------------------------------- animação depois de enviar (D9) */

    var temporizadoresEnvio = [];

    /**
     * Sons do envio (`envSom`, desenho 06 de 24/10, l.886 a 889): "busca" (dois toques curtos) e "ok" (três
     * notas), com eco. Respeitam os "Sons de alerta" do Meu Perfil, como o resto do sistema.
     */
    function somDoEnvio(t) {
        if (!perfilComSons()) {
            return;
        }
        try {
            var A = audioDaTela(), now = A.currentTime;
            var out = A.createGain(); out.gain.value = 0.9;
            var dl = A.createDelay(); dl.delayTime.value = 0.18;
            var fb = A.createGain(); fb.gain.value = 0.22;
            dl.connect(fb); fb.connect(dl); out.connect(A.destination); out.connect(dl); dl.connect(A.destination);
            var nota = function (f, t0, d, v, tipo) {
                var o = A.createOscillator(), o2 = A.createOscillator(), g = A.createGain(), lp = A.createBiquadFilter(), g2 = A.createGain();
                lp.type = 'lowpass'; lp.frequency.value = 2600;
                o.type = tipo || 'sine'; o2.type = 'sine';
                o.frequency.setValueAtTime(f, now + t0); o2.frequency.setValueAtTime(f * 2, now + t0);
                g.gain.setValueAtTime(0, now + t0);
                g.gain.linearRampToValueAtTime(v, now + t0 + 0.012);
                g.gain.exponentialRampToValueAtTime(0.0001, now + t0 + d);
                g2.gain.value = 0.18;
                o.connect(g); o2.connect(g2); g2.connect(g); g.connect(lp); lp.connect(out);
                o.start(now + t0); o2.start(now + t0); o.stop(now + t0 + d + 0.05); o2.stop(now + t0 + d + 0.05);
            };
            if (t === 'ok') {
                nota(783.99, 0, 0.5, 0.22, 'triangle'); nota(1174.66, 0.11, 0.55, 0.2, 'triangle'); nota(1567.98, 0.22, 0.9, 0.16, 'triangle');
            } else {
                nota(987.77, 0, 0.32, 0.09); nota(1318.51, 0.09, 0.4, 0.07);
            }
        } catch (e) { /* navegador sem áudio ou bloqueado: segue só a animação */ }
    }

    /** Fases (`criar`, l.900): `busca` na hora, `parc` em 2300 ms, `ok` em 5200 ms, cada uma com o seu som. */
    function abrirEnvio(fx) {
        fecharEnvio();
        S.envFx = { fase: 'busca', id: fx.id, cid: fx.cid, uf: fx.uf, serv: fx.serv };
        somDoEnvio('busca');
        renderEnvio();
        temporizadoresEnvio.push(setTimeout(function () {
            if (S.envFx) { S.envFx.fase = 'parc'; renderEnvio(); somDoEnvio('busca'); }
        }, 2300));
        temporizadoresEnvio.push(setTimeout(function () {
            if (S.envFx) { S.envFx.fase = 'ok'; renderEnvio(); somDoEnvio('ok'); }
        }, 5200));
    }

    /** O fundo, o "x" e o "Fechar" fecham e cancelam os temporizadores (`envFechar`). */
    function fecharEnvio() {
        temporizadoresEnvio.forEach(clearTimeout);
        temporizadoresEnvio = [];
        S.envFx = null;
        limpar($('teEnvio'));
    }

    /** `envMapaQ` (l.1419): a cidade e a UF da execução no Google Maps; sem cidade, Brasília, como no desenho. */
    function urlDoMapa(fx) {
        return 'https://maps.google.com/maps?q=' + encodeURIComponent((fx.cid || 'Brasília') + ' ' + (fx.uf || '') + ' Brasil') + '&z=12&output=embed';
    }

    /**
     * O mapa por baixo da animação (l.740, decisão P2 do dono): o iframe do desenho, sem clique (`pointer-events:none`
     * no CSS). Ele fica de uma fase para a outra (iframe reinserido recarrega): só o resto do diálogo é redesenhado.
     */
    function renderEnvio() {
        var fx = S.envFx, alvo = $('teEnvio');
        if (!fx) {
            limpar(alvo);
            return;
        }
        var src = urlDoMapa(fx), dialogo = alvo.querySelector('.te-env'), mapa = dialogo && dialogo.querySelector('.te-env-mapa');
        if (mapa && mapa.getAttribute('src') === src) {
            Array.prototype.slice.call(dialogo.children).forEach(function (c) {
                if (c !== mapa) {
                    dialogo.removeChild(c);
                }
            });
        } else {
            limpar(alvo);
            dialogo = el('div', { class: 'te-env', attrs: { role: 'dialog', 'aria-modal': 'true' } }, [
                el('iframe', { class: 'te-env-mapa', attrs: { src: src, title: 'Mapa' } })
            ]);
            alvo.appendChild(el('div', { class: 'te-env-fundo', onclick: fecharEnvio }));
            alvo.appendChild(el('div', { class: 'te-env-pos' }, dialogo));
        }
        var ponto = function (atraso) { return el('span', { class: 'te-env-pt', style: { 'animation-delay': atraso } }, '.'); };
        dialogo.appendChild(el('button', { type: 'button', class: 'te-env-fechar', title: 'Fechar', attrs: { 'aria-label': 'Fechar' }, onclick: fecharEnvio }, ic('bi-x-lg')));
        dialogo.appendChild(el('span', { class: 'te-env-veu' }));
        if (fx.fase === 'busca' || fx.fase === 'parc') {
            var parc = fx.fase === 'parc';
            var lugar = (fx.cid || 'sua cidade') + (fx.uf ? ' · ' + fx.uf : '');
            dialogo.appendChild(el('div', { class: 'te-env-radar' }, [
                el('span', { class: 'te-env-onda' }),
                el('span', { class: 'te-env-onda', style: { 'animation-delay': '.6s' } }),
                el('span', { class: 'te-env-onda', style: { 'animation-delay': '1.2s' } }),
                el('span', { class: 'te-env-alvo' })
            ]));
            dialogo.appendChild(el('div', { class: 'te-env-rodape' }, [
                el('div', { class: 'te-env-trilho' }, el('span', { class: 'te-env-barra' })),
                parc ? el('div', { class: 'te-env-parcs' }, [
                    el('span', { class: 'te-env-parc', style: { background: '#e5f1f7' } }),
                    el('span', { class: 'te-env-parc', style: { background: '#d6e8f3', 'animation-delay': '.2s' } }),
                    el('span', { class: 'te-env-parc', style: { background: '#c6deee', 'animation-delay': '.4s' } })
                ]) : null,
                el('b', { class: 'te-env-tit' }, [
                    parc ? 'Procurando parceiro' : 'Localizando correspondentes em ' + lugar,
                    el('span', { class: 'te-env-pts' }, [ponto('0s'), ponto('.3s'), ponto('.6s')])
                ]),
                el('span', { class: 'te-env-sub', text: parc
                    ? 'Verificando quem atende ' + (fx.serv || 'o serviço') + ' na região…'
                    : 'Enviando ' + (fx.serv || '') + ' para a Central BlueJus…' })
            ]));
        } else {
            dialogo.appendChild(el('div', { class: 'te-env-ok' }, ic('bi-check-lg')));
            dialogo.appendChild(el('div', { class: 'te-env-final' }, [
                el('b', { class: 'te-env-final-tit', text: 'Solicitação recebida e em análise' }),
                el('span', { class: 'te-env-final-txt', text: fx.id + ' chegou à Central BlueJus. Você recebe um aviso assim que a proposta estiver pronta.' }),
                el('div', { class: 'te-env-final-botoes' }, [
                    el('button', { type: 'button', class: 'te-env-btn', text: 'Fechar', onclick: fecharEnvio }),
                    el('button', { type: 'button', class: 'te-env-btn is-pri', text: 'Acompanhar solicitação', onclick: function () {
                        var id = S.envFx && S.envFx.id;
                        fecharEnvio();
                        if (id) { abrir(id); }
                    } })
                ])
            ]));
        }
    }

    /* ---------------------------------------------------------------- som e aviso do andamento (D10) */

    var audioTela = null;

    function audioDaTela() {
        audioTela = audioTela || new (window.AudioContext || window.webkitAudioContext)();
        if (audioTela.state === 'suspended') {
            audioTela.resume().catch(function () { /* sem gesto do usuário ainda */ });
        }
        return audioTela;
    }

    /** "Sons de alerta" do Meu Perfil (`perfil.sons`, no servidor): com "0", nenhum som nesta tela. */
    function perfilComSons() {
        return D.perfilSons !== '0';
    }

    /** `tocarAlerta` (l.809): triangular 880 Hz em 0 s e 1174,66 Hz em 0,13 s, ganho 0,18 em 15 ms, queda até 0,45 s. */
    function tocarAlerta() {
        if (!perfilComSons()) {
            return;
        }
        try {
            var A = audioDaTela(), now = A.currentTime;
            [[880, 0], [1174.66, 0.13]].forEach(function (n) {
                var o = A.createOscillator(), g = A.createGain(), t0 = n[1];
                o.type = 'triangle';
                o.frequency.value = n[0];
                g.gain.setValueAtTime(0, now + t0);
                g.gain.linearRampToValueAtTime(0.18, now + t0 + 0.015);
                g.gain.exponentialRampToValueAtTime(0.0001, now + t0 + 0.45);
                o.connect(g);
                g.connect(A.destination);
                o.start(now + t0);
                o.stop(now + t0 + 0.5);
            });
        } catch (e) { /* navegador bloqueou o áudio: fica só o aviso */ }
    }

    /**
     * Um som só: o lançador da Central (`central-flutuante.js`) já toca quando chega aviso novo no sino, e o andamento
     * é um aviso. Com o lançador na página, o som é dele (com o volume, o "Avisar: Importantes" e o silêncio de lá) e
     * esta tela só mostra o toast; sem o lançador (por exemplo, o convidado sem escritório), toca o `tocarAlerta`.
     */
    function centralTocaOsAvisos() {
        return document.querySelector('[data-central-flutuante]') !== null;
    }

    var ANDAMENTOS_MS = 20000, cursorDeAndamentos = null, pedindoAndamentos = false;

    /**
     * Andamento novo numa demanda de que a pessoa é parte (o `storage` do protótipo, l.1423): a tela pergunta ao
     * servidor de tempos em tempos, com a aba à vista, e mostra o mais novo: toque e "Andamento · {ID}" (texto até
     * 110, hora HH:MM, "Abrir conversa"). Só para quem é de fora da Central: Master e Equipe não recebem.
     */
    function conferirAndamentos() {
        if (pedindoAndamentos || vazio(D.urlAndamentos) || S.como || !ehExterno() || document.hidden) {
            return;
        }
        pedindoAndamentos = true;
        pedir('GET', D.urlAndamentos + (cursorDeAndamentos !== null ? '?desde=' + cursorDeAndamentos : '')).then(function (r) {
            pedindoAndamentos = false;
            if (!r.ok) {
                return;
            }
            cursorDeAndamentos = typeof r.cursor === 'number' ? r.cursor : cursorDeAndamentos;
            var lista = Array.isArray(r.andamentos) ? r.andamentos : [], ultimo = lista[lista.length - 1];
            if (!ultimo) {
                return;
            }
            if (!centralTocaOsAvisos()) {
                tocarAlerta();
            }
            mostrarToast({ id: ultimo.codigo, tit: 'Andamento · ' + ultimo.codigo, txt: String(ultimo.texto || '').slice(0, 110), hora: ultimo.hora || '' });
        });
    }

    /* ---------------------------------------------------------------- modal de confirmação */

    function abrirConf(codigo, b) {
        S.conf = { codigo: codigo, b: b };
        var c = b.confirmacao, alvo = limpar($('teConf'));
        // `motivoOpcional`: o campo aparece, mas pode ir vazio (ex.: "Não aprovar" uma vaga).
        var motivo = c.motivo ? el('textarea', { class: 'te-conf-motivo', rows: 3, placeholder: c.motivoOpcional ? 'Opcional' : 'Descreva (obrigatório)', attrs: c.motivoMax ? { maxlength: String(c.motivoMax) } : {} }) : null;
        // `campoValor`: campo de valor em reais acima do motivo (o servidor lê e valida; aqui só se digita).
        var valor = c.campoValor ? el('input', { class: 'te-conf-valor', type: 'text', value: c.campoValor.inicial || '', attrs: { inputmode: 'decimal', 'aria-label': c.campoValor.rotulo } }) : null;
        var ok = el('button', {
            type: 'button', class: 'te-btn-pri' + (c.perigo ? ' is-perigo' : ''), text: c.botao,
            onclick: function () {
                var m = motivo ? motivo.value.trim() : '';
                if (valor && !valor.value.trim()) {
                    valor.focus();
                    return;
                }
                if (motivo && !m && !c.motivoOpcional) {
                    motivo.focus();
                    return;
                }
                ok.disabled = true;
                if (b.executar) {
                    // Confirmação que não é ação de fluxo (ex.: revogar convite): fecha ao concluir.
                    b.executar(m || null, valor ? valor.value.trim() : null).then(function (r) { ok.disabled = false; if (r && r.ok) { fecharConf(); } });
                    return;
                }
                aplicarAcao(codigo, b.k, m || null).then(function () { ok.disabled = false; });
            }
        });
        alvo.appendChild(el('div', { class: 'te-modal-fundo te-conf-fundo' }, el('div', { class: 'te-conf', attrs: { role: 'dialog', 'aria-modal': 'true' } }, [
            el('div', { class: 'te-conf-tit', text: c.titulo }),
            el('div', { class: 'te-conf-txt', text: c.texto }),
            valor ? el('label', { class: 'te-conf-rot', text: c.campoValor.rotulo }) : null,
            valor,
            motivo,
            el('div', { class: 'te-conf-botoes' }, [
                el('button', { type: 'button', class: 'te-btn-sec', text: 'Cancelar', onclick: fecharConf }),
                ok
            ])
        ])));
        if (valor) {
            valor.focus();
        } else if (motivo) {
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
                ['convidar', 'Convidar e revogar profissionais'], ['situacao', 'Alterar a situação das demandas'],
                ['talentos', 'Publicar vagas e analisar currículos']
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
       (235 a 253), o detalhe `tmVaga` (285 a 300, sem a candidatura: F2b), a exclusão `tmDel`
       (371) e a configuração `tmCfgV` (363 a 369, aberta pelo menu do Master, l.1265). A lógica
       é a do `renderVals()` (l.979 a 1006, 1051, 1093 a 1118, 1156 a 1188). Candidatura e aceite
       (F2b) moram no bloco CANDIDATURA, mais abaixo; a campanha por e-mail fica de fora (depende da 5d, fora da F4).
       ============================================================================= */

    /* ---------------------------------------------------------------- módulos: Demandas | Vagas */

    var MODULO_CHAVE = 'bj-te-modulo';
    /**
     * Faixa `mods` (desenho, l.1105): Demandas sem número; Vagas com o selo `m.n`; "Currículos" para o
     * interno (selo dos novos) e "Meu currículo" para o externo (sem selo).
     */
    var MODULOS = [['demandas', 'Demandas', 'bi-briefcase'], ['vagas', 'Vagas', 'bi-megaphone'], ['cv', 'Currículos', 'bi-person-vcard']];

    function moduloSalvo() {
        try {
            var m = window.sessionStorage.getItem(MODULO_CHAVE);
            return m === 'vagas' || m === 'cv' ? m : 'demandas';
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
    function moduloAtual() {
        if (S.modulo === 'vagas' && veVagas()) {
            return 'vagas';
        }
        return S.modulo === 'cv' && veCv() ? 'cv' : 'demandas';
    }

    /** Selo da aba Vagas: os pedidos pendentes (interno, se houver), senão as abertas (l.1105). */
    function numeroDaAbaVagas() {
        var cont = contadorDoEngajamento('vagas');
        if (cont !== null) {
            return cont;
        }
        var pend = vagas().filter(function (v) { return v.situacao === 'pendente'; }).length;
        if (vgInterno() && pend > 0) {
            return pend;
        }
        return vagas().filter(function (v) { return v.situacao === 'aberta'; }).length;
    }

    /**
     * A faixa só aparece a quem vê a aba Vagas (`veAba`) ou o módulo de currículos (a lista respondeu);
     * com a tela bloqueada, nenhum painel aparece.
     */
    function renderModulos() {
        renderAbasDeModulo();
        renderEngajamento();
    }

    function renderAbasDeModulo() {
        var alvo = limpar($('teModulos'));
        var bloq = !!S.painel.bloqueio, mod = moduloAtual();
        $('teLiberado').hidden = bloq || mod !== 'demandas';
        $('teVagas').hidden = bloq || mod !== 'vagas';
        caixaCv().hidden = bloq || mod !== 'cv';
        if (bloq || (!veVagas() && !veCv())) {
            return;
        }
        var visivel = function (m) { return m[0] === 'demandas' || (m[0] === 'vagas' ? veVagas() : veCv()); };
        alvo.appendChild(el('div', { class: 'te-mods', attrs: { role: 'tablist', 'aria-label': 'Módulos do Trabalho Externo' } }, MODULOS.filter(visivel).map(function (m) {
            var on = mod === m[0];
            var n = m[0] === 'vagas' ? numeroDaAbaVagas() : m[0] === 'cv' ? numeroDaAbaCv() : 0;
            var rot = m[0] === 'cv' && !cvInterno() ? 'Meu currículo' : m[1];
            return el('button', {
                type: 'button', class: 'te-mod' + (on ? ' is-ativo' : ''),
                attrs: { role: 'tab', 'aria-selected': on ? 'true' : 'false' },
                onclick: function () { irModulo(m[0]); }
            }, [ic(m[2]), rot, n > 0 ? el('span', { class: 'te-mod-n', text: n }) : null]);
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
        } else if (m === 'cv') {
            renderCurriculos();
            carregarCurriculos();
        }
    }

    /**
     * GET /api/vagas no recorte do perfil (com `?como=` na prévia). Resposta pedida por outro perfil é
     * descartada. Depois do primeiro GET bem-sucedido da página (fora da prévia), avisa a visita uma vez:
     * é ela que define o "NOVA" da próxima vez.
     */
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
            renderTitulo();
            renderModulos();
            renderVagas();
            agendarEngajamento();
            if (!S.vgVisitou && !previa() && r.vagas.veAba) {
                S.vgVisitou = true;
                pedir('POST', ROTA.vagasVisita(), {});
            }
        });
    }

    /* ---------------------------------------------------------------- dados da aba */

    function vagas() { return (S.vg && S.vg.vagas) || []; }
    function listasVg() { return (S.vg && S.vg.listas) || {}; }
    function cfgVagas() { return (S.vg && S.vg.configuracao) || null; }
    function vgInterno() { return !!(S.vg && S.vg.interno); }
    function vgDaCentral() { return !!(S.vg && S.vg.daCentral); }
    function vgMaster() { return !!(S.vg && S.vg.master); }
    /** Prévia do Master: nada de escrita (sem Nova vaga, Divulgar, botões nem marcações). */
    function podeEscreverVaga() { return !previa(); }
    function vagaDe(codigo) { return vagas().filter(function (x) { return x.codigo === codigo; })[0] || null; }

    function nivelDaVaga(v) { return Math.max(0, Math.min(3, +((v.importancia || {}).nivel) || 0)); }
    function vagaImportante(v) { return nivelDaVaga(v) >= 2; }

    /**
     * Filtros da barra (`vagaFiltros`, l.1116). Estrela, importantes e as situações contam fora da
     * lixeira; a lixeira conta só o que está nela. "Pedidos de divulgação" só aparece se houver pedido
     * e "Minhas vagas enviadas" só se o usuário tiver enviado alguma.
     */
    function filtrosDeVaga() {
        var L = vagas(), sit = function (s) { return function (v) { return v.situacao === s; }; };
        var base = vgInterno()
            ? (L.some(sit('pendente')) ? [['pendente', 'Pedidos de divulgação', sit('pendente')]] : []).concat([
                ['aberta', 'Abertas', sit('aberta')],
                ['pausada', 'Pausadas', sit('pausada')],
                ['ocupada', 'Preenchidas', sit('ocupada')],
                ['expirada', 'Expiradas', sit('expirada')],
                ['encerrada', 'Encerradas', sit('encerrada')],
                ['todas', 'Todas', function () { return true; }]
            ])
            // D4 (l.1126): o externo vê, junto com as abertas, as expiradas há até 30 dias (`expRec`).
            : [['aberta', 'Abertas', function (v) { return v.situacao === 'aberta' || !!v.expRec; }]].concat(L.some(function (v) { return !!v.minha; }) ? [['minhas', 'Minhas vagas enviadas', function (v) { return !!v.minha; }]] : []);
        return base.concat([
            ['estrela', '★ Estrela', function (v) { return !!v.estrela; }],
            ['imp', 'Importantes', vagaImportante],
            ['lix', 'Lixeira', null]
        ]);
    }

    function doFiltroVaga(f) {
        return vagas().filter(function (v) { return f[2] ? !v.lixeira && f[2](v) : !!v.lixeira; });
    }

    /** O filtro inicial é "Abertas"; se o escolhido sumiu (ex.: não há mais pedidos), volta para ele. */
    function filtroDeVaga() {
        var vis = filtrosDeVaga();
        return vis.filter(function (f) { return f[0] === S.vgFiltro; })[0] || vis.filter(function (f) { return f[0] === 'aberta'; })[0] || vis[0];
    }

    /** A ordem é a do servidor; a busca olha título, cargo, local, áreas e escritório (l.990). */
    function vagasFiltradas() {
        var q = S.vgBusca.trim().toLowerCase();
        return doFiltroVaga(filtroDeVaga()).filter(function (v) {
            return !q || [v.titulo, v.cargo, v.local, (v.areas || []).join(' '), v.escritorio].join(' ').toLowerCase().indexOf(q) >= 0;
        });
    }

    function irFiltroVaga(k) {
        S.vgFiltro = k;
        S.vgSel = [];
        renderVagas();
    }

    /** "Selecionar por" da aba (`vgBulk.ops`, l.1051), sempre dentro do filtro atual. */
    var VG_SEL_OPS = [
        ['Todos', function () { return true; }],
        ['Nenhum', function () { return false; }],
        ['Vistos', function (v) { return !!v.vista; }],
        ['Não vistos', function (v) { return !v.vista; }],
        ['Com estrela', function (v) { return !!v.estrela; }],
        ['Importantes', vagaImportante]
    ];

    function vgSelecionada(codigo) { return S.vgSel.indexOf(codigo) >= 0; }

    function alternarVgSel(codigo) {
        S.vgSel = vgSelecionada(codigo) ? S.vgSel.filter(function (c) { return c !== codigo; }) : S.vgSel.concat([codigo]);
        renderVagas();
    }

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
            var on = f[0] === atual[0];
            alvo.appendChild(el('button', {
                type: 'button', class: 'te-vg-filtro' + (on ? ' is-ativo' : ''),
                attrs: { 'aria-pressed': on ? 'true' : 'false' },
                onclick: function () { irFiltroVaga(f[0]); }
            }, [f[1] + ' ', el('span', { class: 'te-vg-filtro-n', text: doFiltroVaga(f).length })]));
        });
    }

    /** "Divulgar uma vaga" (podeDivulgar) e "Nova vaga" (podeCriar). A configuração mora no menu ⋮ do Master. */
    function renderVgAcoes() {
        var alvo = limpar($('teVgAcoes')), V = S.vg || {};
        if (!podeEscreverVaga()) {
            return;
        }
        if (vagas().some(function (x) { return x.podeCompartilhar; })) {
            var dicaSh = 'Compartilhe as vagas abertas por WhatsApp, e-mail ou LinkedIn';
            alvo.appendChild(el('button', {
                type: 'button', class: 'te-vg-share', title: dicaSh, attrs: { 'data-tip': dicaSh },
                onclick: function () { abrirShare(null); }
            }, [ic('bi-share'), 'Compartilhar vagas']));
        }
        if (V.podeDivulgar) {
            var dica = 'Envie a vaga do seu escritório. A equipe BlueJus revisa e divulga na Central.';
            alvo.appendChild(el('button', {
                type: 'button', class: 'te-vg-divulgar', title: dica, attrs: { 'data-tip': dica },
                onclick: function () { abrirFormVaga(null); }
            }, [ic('bi-megaphone'), 'Divulgar uma vaga']));
        }
        if (V.podeCriar) {
            alvo.appendChild(el('button', { type: 'button', class: 'te-btn-nova', onclick: function () { abrirFormVaga(null); } }, [ic('bi-plus-lg'), 'Nova vaga']));
        }
    }

    /**
     * Barra de lote (`vgBulk`, l.1052): um só botão de "visto" (visto se alguma não foi vista) e um de
     * estrela (remover se todas já têm); importante e não importante aparecem sempre.
     */
    function renderVgLote() {
        var alvo = limpar($('teVgLote'));
        var naLixeira = filtroDeVaga()[0] === 'lix';
        if (S.vgSel.length && podeEscreverVaga()) {
            var sel = vagas().filter(function (v) { return vgSelecionada(v.codigo); });
            var algumaNaoVista = sel.some(function (v) { return !v.vista; });
            var todasEstrela = sel.length > 0 && sel.every(function (v) { return !!v.estrela; });
            var acoes = naLixeira
                ? [
                    ['restaurar', 'Restaurar', 'bi-arrow-counterclockwise', null],
                    ['definitivo', 'Excluir da minha lista', 'bi-x-octagon', '#a3232b']
                ]
                : [
                    ['lixeira', 'Mover para a lixeira', 'bi-trash', null],
                    algumaNaoVista ? ['lida', 'Marcar como visto', 'bi-eye', null] : ['naolida', 'Marcar como não visto', 'bi-eye-slash', null],
                    todasEstrela ? ['semestrela', 'Remover estrela', 'bi-star-fill', '#e0a106'] : ['estrela', 'Adicionar estrela', 'bi-star', null],
                    ['imp', 'Marcar como importante', 'bi-chevron-double-right', '#0a6fa0'],
                    ['naoimp', 'Marcar como não importante', 'bi-chevron-right', null]
                ];
            var codigos = S.vgSel.slice();
            alvo.appendChild(el('div', { class: 'te-lote', attrs: { role: 'toolbar', 'aria-label': 'Ações nas vagas selecionadas' } }, [
                el('b', { class: 'te-lote-n', text: S.vgSel.length + ' selecionado(s)' })
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

    /** Vazio (`vagasVazio`, l.1118). */
    function vazioDeVagas() {
        var tit, txt;
        if (vgInterno()) {
            tit = vagas().length ? 'Nenhuma vaga neste filtro' : 'Nenhuma vaga publicada';
            txt = 'Publique vagas para advogados, estagiários e correspondentes que usam o BlueJus. Elas aparecem para todos os usuários da Central.';
        } else {
            tit = 'Nenhuma vaga aberta no momento';
            txt = 'Quando o escritório publicar novas vagas, elas aparecem aqui. Mantenha seu currículo atualizado para se candidatar rápido.';
        }
        return el('div', { class: 'te-vg-vazio' }, [
            ic('bi-briefcase'),
            el('div', { class: 'te-vg-vazio-tit', text: tit }),
            el('div', { class: 'te-vg-vazio-txt', text: txt })
        ]);
    }

    /** Dica da importância, igual à das demandas (`impTip`). */
    function dicaDeImportancia(nv, importancia) {
        var motivos = ((importancia || {}).motivos || []).join(', ');
        return IMPORTANCIA[nv].rot + (motivos ? ': ' + motivos : '') + '. Clique para marcar ' + (nv >= 2 ? 'como não importante.' : 'como importante.');
    }

    /** Cartão da vaga (desenho, l.158 a 166 e l.1117). O clique abre o detalhe; o botão direito, o menu. */
    function cartaoVaga(v) {
        var sel = vgSelecionada(v.codigo), pv = previa();
        var nv = nivelDaVaga(v), I = IMPORTANCIA[nv], dica = dicaDeImportancia(nv, v.importancia);
        var parar = function (fn) { return function (e) { e.stopPropagation(); if (fn) { fn(); } }; };

        return el('div', {
            class: 'te-vg-card' + (sel ? ' is-sel' : ''),
            attrs: { 'data-codigo': v.codigo },
            onclick: function () {
                // D4 (l.1191): o externo não abre a expirada; o interno abre normalmente.
                if (v.expOn && !vgInterno()) {
                    mostrarToast({ tit: 'Vaga expirada', txt: 'As inscrições desta vaga se encerraram em ' + String(v.expTxt || '').replace('Expirou em ', '') + '. Ative os alertas para não perder as próximas.', hora: '' });
                    return;
                }
                marcarVagaVista(v);
                abrirVaga(v.codigo);
            },
            oncontextmenu: function (e) {
                e.preventDefault();
                abrirMenuEspecial({ tipo: 'vaga', codigo: v.codigo }, e.clientX, e.clientY, 230);
            }
        }, [
            // D4 (l.164): véu e carimbo "EXPIRADA", sem clique.
            v.expOn ? el('span', { class: 'te-vg-veu', attrs: { 'aria-hidden': 'true' } }) : null,
            v.expOn ? el('span', { class: 'te-vg-carimbo', text: 'EXPIRADA', attrs: { 'aria-hidden': 'true' } }) : null,
            el('div', { class: 'te-vg-topo' }, [
                el('span', { class: 'te-vg-ic' }, ic('bi-briefcase')),
                el('div', { class: 'te-flex1 te-min0' }, [
                    el('div', { class: 'te-vg-tit te-reticencias' + (v.vista ? '' : ' is-naovista'), text: v.titulo }),
                    el('div', { class: 'te-vg-esc te-reticencias', text: v.escritorio })
                ]),
                seloSituacao(v.situacaoRotulo, v.situacaoCor)
            ]),
            v.pend ? el('div', { class: 'te-vg-pend', style: { background: v.pendBg, color: v.pendCor } }, [ic('bi-hourglass-split'), el('span', { text: v.pendTxt })]) : null,
            el('div', { class: 'te-vg-marcas' }, [
                el('div', { class: 'te-vg-marca', onclick: parar(null) }, [
                    pv ? null : el('button', {
                        type: 'button', class: 'te-sel-btn' + (sel ? ' is-on' : ''), title: 'Selecionar',
                        attrs: { 'aria-pressed': sel ? 'true' : 'false' },
                        onclick: parar(function () { alternarVgSel(v.codigo); })
                    }, ic(sel ? 'bi-check-square-fill' : 'bi-square')),
                    el('button', {
                        type: 'button', class: 'te-estrela' + (v.estrela ? ' is-on' : ''), title: 'Estrela', disabled: pv,
                        attrs: { 'aria-pressed': v.estrela ? 'true' : 'false' },
                        onclick: parar(function () { loteVagas([v.codigo], v.estrela ? 'semestrela' : 'estrela'); })
                    }, ic(v.estrela ? 'bi-star-fill' : 'bi-star')),
                    el('button', {
                        type: 'button', class: 'te-imp te-vg-imp', title: dica, disabled: pv,
                        style: { background: I.bg, color: I.cor },
                        attrs: { 'data-tip': dica, 'aria-label': dica },
                        onclick: parar(function () { loteVagas([v.codigo], nv >= 2 ? 'naoimp' : 'imp'); })
                    }, ic(I.ic))
                ]),
                v.vivo ? el('span', { class: 'te-vg-vivo' }, [el('span', { class: 'te-vg-vivo-pt' }), 'AO VIVO']) : null,
                v.nova ? el('span', { class: 'te-vg-nova', text: 'NOVA' }) : null,
                seloCompat(v)
            ]),
            el('div', { class: 'te-vg-info' }, [
                el('span', null, [ic('bi-person-badge'), (v.cargo || '') + ' · ' + (v.contratacao || '')]),
                el('span', null, [ic('bi-geo-alt'), v.local || '']),
                el('span', null, [ic('bi-cash-stack'), v.remuneracao || ''])
            ]),
            (v.areas || []).length ? el('div', { class: 'te-vg-areas' }, v.areas.slice(0, 4).map(function (a) { return el('span', { class: 'te-vg-area', text: a }); })) : null,
            v.oferta && !v.expOn ? relogioDaVaga(v, false, relogiosGrade) : null,
            el('div', { class: 'te-vg-rodape' }, [
                ic('bi-calendar3'),
                el('span', { class: 'te-flex1', text: v.prazoTxt || '' }),
                // `mostraN`/`nCand` (l.165 e 1117): o interno vê quantas candidaturas a vaga tem; o dono externo, quantos o BlueJus indicou.
                vgInterno() && v.nCand !== undefined && v.nCand !== null ? el('b', { class: 'te-cand-n' }, [ic('bi-people'), ' ' + v.nCand]) : null,
                !vgInterno() && v.minha && typeof v.indicadosN === 'number' ? el('b', { class: 'te-cand-n' }, [ic('bi-people'), ' ' + v.indicadosN + ' indicado(s) pelo BlueJus']) : null,
                !vgInterno() && v.jaFoi ? el('b', { class: 'te-cand-foi' }, [ic('bi-check2-circle'), ' Candidatura enviada']) : null
            ]),
            // D4 (l.172): "Expirou em dd/mm/aaaa" no pé do cartão.
            v.expOn ? el('div', { class: 'te-vg-expirou', text: v.expTxt }) : null
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

    /**
     * `tempo()` do desenho (l.996), só do interno (o servidor só manda `oferta` a ele). Total =
     * duração em minutos; aberta conta mm:ss até `expiraEm`; fora de aberta mostra a situação.
     */
    function relogioDaVaga(v, grande, lista) {
        var aberta = v.situacao === 'aberta', qtd = +v.qtd || 1, ac = +v.aceites || 0;
        var r = {
            codigo: v.codigo,
            aberta: aberta,
            rotulo: v.situacaoRotulo || '',
            fim: Date.parse(v.expiraEm || ''),
            total: (+v.duracao || 0) * 6e4,
            ic: ic('bi-stopwatch'),
            tmr: el('b', { class: 'te-vg-tmr' }),
            barra: el('div', { class: 'te-vg-pct' })
        };
        var txt = aberta ? 'disponível · ' + ac + ' de ' + qtd + ' aceita(s)' : ac ? ac + ' de ' + qtd + ' aceita(s)' : 'sem aceite';
        atualizarRelogio(r);
        if (aberta && !isNaN(r.fim)) {
            lista.push(r);
        }
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

    /** Barra azul, laranja abaixo de 50% e vermelha abaixo de 20% (l.996). */
    function atualizarRelogio(r) {
        var resta = r.aberta && !isNaN(r.fim) ? Math.max(0, r.fim - agoraServidor()) : 0;
        var pct = r.total ? Math.max(0, Math.min(1, resta / r.total)) : 0;
        var cor = pct < 0.2 ? '#a3232b' : pct < 0.5 ? '#c76a12' : '#0a6fa0';
        r.tmr.textContent = r.aberta ? mmss(resta) : r.rotulo;
        r.tmr.style.color = cor;
        r.ic.style.color = cor;
        r.barra.style.background = cor;
        r.barra.style.width = Math.round(pct * 100) + '%';
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

    /**
     * Menu do cartão (`vMenu`, l.1001), com o título da vaga e sem as marcações. Interno: Abrir vaga,
     * aprovar e não aprovar, Editar vaga, os demais botões de situação e Excluir vaga. Externo: Ver vaga.
     * "Divulgar vaga" (interno) e "Compartilhar vaga" (externo) na aberta; Candidatar-se ou Aceitar
     * oferta entram na F2b.
     */
    function menuDaVaga(caixa, codigo) {
        var v = vagaDe(codigo);
        if (!v) {
            return;
        }
        var item = function (rot, icone, acao, perigo) {
            caixa.appendChild(el('button', {
                type: 'button', class: 'te-menu-item' + (perigo ? ' is-perigo' : ''),
                onclick: function () { fecharMenu(); acao(); }
            }, [ic(icone), rot]));
        };
        caixa.appendChild(el('div', { class: 'te-menu-tit', text: v.titulo }));
        if (!vgInterno()) {
            item('Ver vaga', 'bi-box-arrow-up-right', function () { abrirVaga(codigo); });
            // l.1001: "Aceitar oferta" na oferta com tempo (o externo só sabe dela pelo AO VIVO), senão
            // "Candidatar-se". A vaga própria não mostra (§4.6); quem decide de fato é o servidor.
            if (!v.minha && podeEscreverVaga()) {
                var oferta = !!(v.vivo || v.oferta);
                item(oferta ? 'Aceitar oferta' : 'Candidatar-se', oferta ? 'bi-hand-thumbs-up' : 'bi-send', function () { candidatar(codigo); });
            }
            if (v.podeCompartilhar && podeEscreverVaga()) {
                item('Compartilhar vaga', 'bi-share', function () { abrirShare([codigo]); });
            }
            return;
        }
        item('Abrir vaga', 'bi-box-arrow-up-right', function () { abrirVaga(codigo); });
        if (!podeEscreverVaga()) {
            return;
        }
        if (v.podeCompartilhar) {
            item('Divulgar vaga', 'bi-share', function () { abrirShare([codigo]); });
        }
        var botoes = v.botoes || [];
        var daAprovacao = function (b) { return b.k === 'aprovar' || b.k === 'reprovar'; };
        botoes.filter(daAprovacao).forEach(function (b) {
            item(b.rot, b.ic, function () { acaoVaga(codigo, b); }, b.k === 'reprovar');
        });
        if (v.podeEditar) {
            item('Editar vaga', 'bi-pencil', function () { editarVaga(codigo); });
        }
        botoes.filter(function (b) { return !daAprovacao(b); }).forEach(function (b) {
            item(b.rot, b.ic, function () { acaoVaga(codigo, b); }, b.tipo === 'perigo');
        });
        if (v.podeExcluir) {
            item('Excluir vaga', 'bi-trash', function () { pedirExclusaoVaga(v); }, true);
        }
    }

    /** "Não aprovar" pede o motivo, opcional ("Motivo para não publicar (o solicitante verá):"); os demais vão direto. */
    function acaoVaga(codigo, b) {
        fecharMenu();
        if (!podeEscreverVaga()) {
            return;
        }
        if (b.motivo) {
            // Como o desenho (l.1076): prompt() nativo; vazio vale, cancelar (null) aborta.
            var motivo = window.prompt('Motivo para não publicar (o solicitante verá):', '');
            if (motivo === null) {
                return;
            }
            enviarAcaoVaga(codigo, b.k, motivo.trim());
            return;
        }
        enviarAcaoVaga(codigo, b.k, null);
    }

    /** Avisos do desenho para cada ação (l.979 a 984). */
    function avisoDaAcao(k, v) {
        if (k === 'aprovar') {
            return { tit: 'Vaga publicada', txt: 'Já está visível para todos os usuários da Central.' };
        }
        if (k === 'reprovar') {
            return { tit: 'Pedido não aprovado', txt: 'O solicitante foi avisado.' };
        }
        if (k === 'ofertar') {
            return { tit: 'Oferta reaberta', txt: 'O contador começou de novo.' };
        }
        return { tit: 'Vaga ' + String((v && v.situacaoRotulo) || '').toLowerCase(), txt: (v && v.titulo) || '' };
    }

    /** Aprovar, não aprovar e encerrar fecham o detalhe; as demais o atualizam. */
    function enviarAcaoVaga(codigo, k, motivo) {
        return pedir('POST', ROTA.vagaAcao(codigo, k), { motivo: motivo }).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return r;
            }
            var noDetalhe = S.tlm === 'vaga' && S.vgDet && S.vgDet.codigo === codigo;
            if (noDetalhe && (k === 'aprovar' || k === 'reprovar' || k === 'encerrar')) {
                fecharTlm();
            } else if (noDetalhe && r.vaga) {
                S.vgDet = r.vaga;
                renderDetalheVaga();
            }
            var a = avisoDaAcao(k, r.vaga);
            mostrarToast({ tit: a.tit, txt: a.txt, hora: '' });
            carregarVagas();
            return r;
        });
    }

    /** A confirmação de "Excluir vaga" (`delPedir`, l.1001; modal `tmDel`, l.371). */
    function pedirExclusaoVaga(v) {
        if (!podeEscreverVaga() || !v.podeExcluir) {
            return;
        }
        fecharMenu();
        S.tlm = 'del';
        S.vgDet = null;
        S.vf = null;
        S.vgDel = { codigo: v.codigo, txt: 'Excluir a vaga "' + v.titulo + '"? As candidaturas recebidas também serão removidas. Esta ação não pode ser desfeita.', erro: '', enviando: false };
        relogiosDetalhe = [];
        limpar($('teVagaModal'));
        renderExclusaoVaga();
    }

    function renderExclusaoVaga() {
        var X = S.vgDel;
        if (!X || S.tlm !== 'del') {
            return;
        }
        modalVaga({
            icone: 'bi-trash', titulo: 'Confirmar exclusão', largura: '720px',
            corpo: [
                el('div', { class: 'te-tlm-del' }, [ic('bi-exclamation-triangle'), el('span', { text: X.txt })]),
                erroTlm(X.erro)
            ],
            botoes: [
                botaoTlm('Cancelar', 'bi-x', '', fecharTlm),
                botaoTlm('Excluir', 'bi-trash', 'perigo', function (e) {
                    if (X.enviando) {
                        return;
                    }
                    X.enviando = true;
                    e.currentTarget.disabled = true;
                    pedir('POST', ROTA.vagaExcluir(X.codigo), {}).then(function (r) {
                        X.enviando = false;
                        if (S.vgDel !== X) {
                            return;
                        }
                        if (!r.ok) {
                            X.erro = r.mensagem;
                            renderExclusaoVaga();
                            return;
                        }
                        fecharTlm();
                        mostrarToast({ tit: 'Vaga excluída', txt: '', hora: '' });
                        carregarVagas();
                    });
                })
            ]
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
                    titulo: 'Excluir da minha lista',
                    texto: 'Excluir ' + n + ' item(ns) da sua lista definitivamente? Continuam existindo para os demais.',
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

    /** Abrir o cartão marca a vaga como vista (o título deixa de ficar em negrito), sem aviso. */
    function marcarVagaVista(v) {
        if (v.vista || !podeEscreverVaga()) {
            return;
        }
        v.vista = true;
        pedir('POST', ROTA.vagasMarcacoes(), { codigos: [v.codigo], op: 'lida' }).then(function (r) {
            if (!r.ok) {
                v.vista = false;
                return;
            }
            if (S.vgDet && S.vgDet.codigo === v.codigo) {
                S.vgDet.vista = true;
            }
            renderVagas();
        });
    }

    /* ---------------------------------------------------------------- divulgar vaga(s) (tmShare) */

    /** Mensagem padrão (`msgPadrao`, l.1075): uma vaga com cargo, local e quantidade; várias por cargo. */
    function msgPadrao(L) {
        var qtd = function (v) { return v.qtd || 1; };
        if (L.length === 1) {
            var v = L[0], loc = [v.cidade, v.uf].filter(Boolean).join('/') || 'Brasil';
            return '⚖️ Vaga na área jurídica: ' + v.titulo + '\n\n💼 ' + v.cargo + (v.contrato ? ' · ' + v.contrato : '')
                + '\n📍 ' + (v.modalidade ? v.modalidade + ' · ' : '') + loc + (qtd(v) > 1 ? '\n👥 ' + qtd(v) + ' vagas' : '')
                + '\n\nAproveite! Crie sua conta gratuita no BlueJus para ver os detalhes e se candidatar:\n{link}';
        }
        var total = L.reduce(function (a, x) { return a + qtd(x); }, 0);
        return '⚖️ Vagas para Advogados e área Jurídica disponíveis!\n\n' + total + ' vaga(s) abertas agora na Central BlueJus:\n'
            + porCargo(L).map(function (c) { return '• ' + c[1] + ' × ' + c[0]; }).join('\n')
            + '\n\nAproveite! Crie sua conta gratuita para ver os detalhes e se candidatar:\n{link}';
    }

    /** [[cargo, soma das quantidades], ...] na ordem em que aparecem. */
    function porCargo(L) {
        var cg = {}, ordem = [];
        L.forEach(function (v) {
            if (!(v.cargo in cg)) {
                cg[v.cargo] = 0;
                ordem.push(v.cargo);
            }
            cg[v.cargo] += v.qtd || 1;
        });
        return ordem.map(function (k) { return [k, cg[k]]; });
    }

    /** `abrirShare` (l.1076): `codigos` nulo = todas as abertas. O link vem do servidor (token próprio). */
    function abrirShare(codigos) {
        fecharMenu();
        var L = vagas().filter(function (v) { return v.podeCompartilhar && (!codigos || codigos.indexOf(v.codigo) >= 0); });
        if (!L.length) {
            mostrarToast({ tit: 'Nenhuma vaga aberta', txt: 'Só vagas abertas podem ser divulgadas.', hora: '' });
            return;
        }
        var cods = L.map(function (v) { return v.codigo; });
        pedir('POST', ROTA.vagasCompartilhar(), { codigos: cods }).then(function (r) {
            if (!r.ok || !r.url) {
                toastErro(r.mensagem);
                return;
            }
            S.tlm = 'share';
            S.vgDet = null;
            S.sh = { vagas: L, codigos: cods, link: r.url, msg: msgPadrao(L), copiou: '' };
            renderShare();
        });
    }

    /** Cada canal soma uma divulgação e vai ao histórico (`regShare`, l.1077); a falha não trava o canal. */
    function registrarDivulgacao(canal) {
        var sh = S.sh;
        pedir('POST', ROTA.vagasDivulgacao(), { codigos: sh.codigos, canal: canal });
    }

    function copiarDoShare(texto, k) {
        var marcar = function () {
            if (S.sh) {
                S.sh.copiou = k;
                renderShare();
            }
        };
        var reserva = function () {
            var ta = document.createElement('textarea');
            ta.value = texto;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (e) { /* o navegador recusou; o texto segue na tela */ }
            ta.remove();
            marcar();
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(texto).then(marcar, reserva);
        } else {
            reserva();
        }
    }

    /** Os cinco canais (`shCanais`, l.1167), com o texto já com o link no lugar de {link}. */
    function canaisDoShare() {
        var sh = S.sh, L = sh.vagas, link = sh.link;
        var txt = function () { return sh.msg.replace('{link}', link); };
        var assunto = L.length === 1 ? 'Vaga jurídica: ' + L[0].titulo : 'Vagas para Advogados e área Jurídica no BlueJus';
        return [
            ['WhatsApp', 'bi-whatsapp', '#1e7b45', function () { registrarDivulgacao('WhatsApp'); window.open('https://wa.me/?text=' + encodeURIComponent(txt()), '_blank', 'noopener'); }],
            ['E-mail', 'bi-envelope', '#0a6fa0', function () { registrarDivulgacao('E-mail'); window.open('mailto:?subject=' + encodeURIComponent(assunto) + '&body=' + encodeURIComponent(txt()), '_self'); }],
            ['LinkedIn', 'bi-linkedin', '#0b5f86', function () { registrarDivulgacao('LinkedIn'); copiarDoShare(txt(), 'li'); window.open('https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(link), '_blank', 'noopener'); }],
            [sh.copiou === 'msg' ? 'Mensagem copiada' : 'Copiar mensagem', sh.copiou === 'msg' ? 'bi-check2' : 'bi-chat-left-text', '#5b3f9e', function () { registrarDivulgacao('mensagem copiada'); copiarDoShare(txt(), 'msg'); }],
            [sh.copiou === 'link' ? 'Link copiado' : 'Copiar link', sh.copiou === 'link' ? 'bi-check2' : 'bi-link-45deg', '#34505f', function () { registrarDivulgacao('link'); copiarDoShare(link, 'link'); }]
        ].map(function (k) {
            return el('button', { type: 'button', class: 'te-sh-canal', style: { color: k[2] }, onclick: k[3] }, [ic(k[1]), k[0]]);
        });
    }

    /** Prévia do link (l.344 a 353): o cartão como as redes mostram, com cargos e UFs. */
    function previaDoShare() {
        var L = S.sh.vagas, cg = porCargo(L), total = L.reduce(function (a, v) { return a + (v.qtd || 1); }, 0);
        var ufs = L.map(function (v) { return v.uf; }).filter(function (u, i, a) { return u && a.indexOf(u) === i; });
        var sub = total + ' vaga(s) · ' + cg.length + ' cargo(s)' + (ufs.length ? ' · ' + ufs.slice(0, 4).join(', ') + (ufs.length > 4 ? '…' : '') : '');
        var dominio = (function () { try { return new URL(S.sh.link).host; } catch (e) { return location.host; } })() || 'bluejus.com.br';
        return el('div', { class: 'te-sh-col' }, [
            el('div', { class: 'te-sh-rot', text: 'PRÉVIA DO LINK' }),
            el('div', { class: 'te-sh-moldura' }, el('div', { class: 'te-sh-cartao' }, [
                el('div', { class: 'te-sh-capa' }, [
                    el('span', { class: 'te-sh-bola1' }), el('span', { class: 'te-sh-bola2' }),
                    el('img', { attrs: { src: D.urlSimbolo || '/images/logo/bluejus_simbolo.svg', alt: '' } }),
                    el('div', { class: 'te-sh-marca' }, [el('div', { text: 'CENTRAL DE VAGAS JURÍDICAS' }), el('div', { text: 'BlueJus' })])
                ]),
                el('div', { class: 'te-sh-info' }, [
                    el('div', { class: 'te-sh-dom', text: dominio }),
                    el('b', { text: L.length === 1 ? L[0].titulo : 'Vagas para Advogados e área Jurídica' }),
                    el('div', { class: 'te-sh-sub', text: sub }),
                    el('div', { class: 'te-sh-cargos' }, cg.slice(0, 6).map(function (c) { return el('span', { text: c[1] + ' × ' + c[0] }); }))
                ])
            ])),
            el('div', { class: 'te-sh-nota' }, [ic('bi-shield-lock'), ' A página pública mostra só cargo, local e tipo. Detalhes, remuneração, contatos e candidatura ficam liberados depois de criar a conta.'])
        ]);
    }

    function mensagemDoShare() {
        var sh = S.sh;
        var area = el('textarea', { class: 'te-sh-msg', attrs: { rows: '10', 'aria-label': 'Mensagem' } });
        area.value = sh.msg;
        area.addEventListener('input', function (e) {
            sh.msg = e.target.value;
            var antes = sh.copiou;
            sh.copiou = '';
            if (antes || (sh.msg.indexOf('{link}') < 0) !== !!$('teShSemLink')) {
                renderShare(true);
            }
        });
        return el('div', { class: 'te-sh-col' }, [
            el('div', { class: 'te-sh-cab' }, [
                el('span', { class: 'te-sh-rot', text: 'MENSAGEM' }),
                el('button', { type: 'button', class: 'te-sh-padrao', onclick: function () { sh.msg = msgPadrao(sh.vagas); sh.copiou = ''; renderShare(); } }, [ic('bi-arrow-counterclockwise'), ' Mensagem padrão'])
            ]),
            area,
            sh.msg.indexOf('{link}') < 0 ? el('div', { class: 'te-sh-semlink', id: 'teShSemLink' }, [ic('bi-exclamation-triangle'), ' Inclua {link} no texto para o link entrar na mensagem.']) : null,
            el('div', { class: 'te-sh-canais' }, canaisDoShare())
        ]);
    }

    /** `tmShare` (l.343 a 362), 960px, com "Fechar" e "Ver página pública" (l.1098). `manterFoco` devolve o cursor à mensagem. */
    function renderShare(manterFoco) {
        var sh = S.sh;
        if (!sh || S.tlm !== 'share') {
            return;
        }
        var ativo = document.activeElement, pos = ativo && ativo.classList && ativo.classList.contains('te-sh-msg') ? ativo.selectionStart : null;
        modalVaga({
            titulo: 'Divulgar vaga' + (sh.vagas.length > 1 ? 's' : '') + ' · WhatsApp, e-mail e redes',
            icone: 'bi-share',
            largura: '960px',
            corpo: [el('div', { class: 'te-sh' }, [previaDoShare(), mensagemDoShare()])],
            botoes: [
                botaoTlm('Fechar', 'bi-x', '', fecharTlm),
                botaoTlm('Ver página pública', 'bi-box-arrow-up-right', 'pri', function () { window.open(sh.link, '_blank', 'noopener'); })
            ]
        });
        var nova = document.querySelector('#teVagaModal .te-sh-msg');
        if (manterFoco && nova && pos !== null) {
            nova.focus();
            nova.setSelectionRange(pos, pos);
        }
    }

    /* ---------------------------------------------------------------- modal genérico (tlm) */

    function fecharTlm() {
        // Fechar no meio do envio esconderia a vaga (ou o currículo) gravado: espera a resposta.
        if ((S.vf && S.vf.enviando) || (S.vgDel && S.vgDel.enviando) || (S.cvF && S.cvF.enviando) || (S.cvDel && S.cvDel.enviando) || (S.tmo && S.tmo.enviando)) {
            return;
        }
        // A nota interna que ainda esperava os 800 ms vai agora, antes de o campo sumir.
        gravarNotaJa();
        // Arquivo enviado no formulário já está no servidor: "Meu currículo" precisa refletir mesmo sem salvar.
        var recarregarCv = !!(S.cvF && S.cvF.mudouArquivo);
        S.tlm = null;
        S.vgDet = null;
        S.vf = null;
        S.vgDel = null;
        S.sh = null;
        S.cvDet = null;
        S.cvF = null;
        S.cvDoc = null;
        S.cvDel = null;
        S.tmo = null;
        clearTimeout(temporizadorTermo);
        S.cp = null;
        relogiosDetalhe = [];
        limpar($('teVagaModal'));
        if (recarregarCv) {
            carregarCurriculos();
        }
    }

    /** Cabeçalho azul, corpo com rolagem e rodapé com os botões (`tlmBtns`). Redesenhar guarda a rolagem. */
    function modalVaga(o) {
        var alvo = $('teVagaModal');
        var antes = $('teTlmCorpo'), topo = antes ? antes.scrollTop : 0;
        limpar(alvo);
        var corpo = el('div', { class: 'te-tlm-corpo', id: 'teTlmCorpo' }, o.corpo);
        alvo.appendChild(el('div', { class: 'te-tlm-fundo', onclick: fecharTlm }));
        alvo.appendChild(el('div', {
            class: 'te-tlm',
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

    /**
     * `tipo`: '' secundário, 'pri' azul, 'verde' (#1e7b45), 'perigo' (#a3232b) e 'cinza' (#6b8494, "Oferta
     * indisponível", l.1095), como o `B(rot, ic, ir, pri, bg)` do desenho.
     */
    function botaoTlm(rot, icone, tipo, acao) {
        return el('button', {
            type: 'button',
            class: 'te-tlm-btn' + (tipo === 'pri' ? ' is-pri' : tipo === 'verde' ? ' is-pri is-verde' : tipo === 'perigo' ? ' is-pri is-perigo' : tipo === 'cinza' ? ' is-pri te-cand-cinza' : tipo === 'recusar' ? ' is-recusar' : ''),
            onclick: acao
        }, [ic(icone), rot]);
    }

    /** Erro do servidor (`tlErro`, linha 372): última faixa do corpo, logo acima do rodapé. */
    function erroTlm(txt) {
        return vazio(txt) ? null : el('div', { class: 'te-tlm-erro', attrs: { role: 'alert' } }, [ic('bi-exclamation-circle'), ' ', el('span', { text: txt })]);
    }

    /* ---------------------------------------------------------------- detalhe da vaga (tmVaga) */

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
            lembrarOferta(r.vaga);
            S.vf = null;
            S.vgDel = null;
            S.cvDoc = null;
            // A marcação para envio é por vaga (lacuna 9): outra vaga começa sem nada marcado.
            if (!S.candSel || S.candSel.vaga !== codigo) {
                S.candSel = { vaga: codigo, ids: [] };
            }
            renderDetalheVaga();
        });
    }

    function dadoDaVaga(icone, texto) {
        return el('span', { class: 'te-tv-dado' }, [ic(icone), texto]);
    }

    /** O botão do servidor no rodapé do detalhe: "Finalizar oferta" vermelho e "Ofertar novamente" azul (l.1095). */
    function tipoNoRodape(v, b) {
        if (b.k === 'encerrar' && v.oferta) {
            return 'perigo';
        }
        return b.k === 'ofertar' ? 'pri' : '';
    }

    /** Detalhe (`tmVaga`, l.285 a 300; `tv`, l.1003) e o rodapé (`tlmBtns`, l.1095). */
    function renderDetalheVaga() {
        var v = S.vgDet;
        if (!v) {
            return;
        }
        relogiosDetalhe = [];
        var of = v.ofertante || { nome: v.escritorio, info: [], oculto: false };
        var corpo = [
            // "Compartilhar esta vaga" (`tvPodeShare`, l.286): na aberta, para quem a vê.
            v.podeCompartilhar && podeEscreverVaga() ? el('button', { type: 'button', class: 'te-tv-share', onclick: function () { abrirShare([v.codigo]); } }, [ic('bi-whatsapp'), 'Compartilhar esta vaga']) : null,
            el('div', { class: 'te-tv-of' }, [
                el('span', { class: 'te-tv-of-ic' }, ic('bi-building')),
                el('div', { class: 'te-tv-of-textos' }, [
                    el('div', { class: 'te-tv-of-rot', text: 'OFERECIDO POR' }),
                    el('b', { class: 'te-tv-of-nome', text: of.nome || v.escritorio || '' }),
                    (of.info || []).length ? el('div', { class: 'te-tv-of-info' }, of.info.map(function (o) {
                        return el('span', null, [ic(o.ic), ' ' + o.v]);
                    })) : null,
                    of.oculto ? el('div', { class: 'te-tv-of-oculto' }, [ic('bi-shield-lock'), ' Contato pelo BlueJus, ao se candidatar.']) : null
                ])
            ]),
            el('div', { class: 'te-tv-sit' }, [
                seloSituacao(v.situacaoRotulo, v.situacaoCor),
                el('span', { text: (v.escritorio || '') + ' · publicada em ' + (v.em || '') })
            ]),
            el('div', { class: 'te-tv-dados' }, [
                dadoDaVaga('bi-person-badge', v.cargo || ''),
                dadoDaVaga('bi-file-earmark-text', v.contratacao || ''),
                dadoDaVaga('bi-geo-alt', v.local || ''),
                dadoDaVaga('bi-cash-stack', v.remuneracao || ''),
                dadoDaVaga('bi-calendar3', v.prazoDetalhe || ''),
                dadoDaVaga('bi-award', v.oab || '')
            ]),
            (v.areas || []).length ? el('div', { class: 'te-vg-areas' }, v.areas.map(function (a) { return el('span', { class: 'te-vg-area', text: a }); })) : null,
            v.oferta ? relogioDaVaga(v, true, relogiosDetalhe) : null,
            // "SUA CANDIDATURA" (`tv.minhaOn`, l.292): só o externo que se candidatou.
            !vgInterno() ? blocoMinhaCandidatura(v.minhaCandidatura) : null,
            el('div', { class: 'te-tv-req', text: v.requisitos || '' })
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
        // Candidatos (`tv.candOn`, l.295 a 298): o interno e o dono externo da vaga.
        if ((vgInterno() || v.minha) && Array.isArray(v.candidatos)) {
            corpo = corpo.concat(blocoCandidatos(v));
        }

        var botoes = [botaoTlm('Fechar', 'bi-x', '', fecharTlm)];
        if (podeEscreverVaga()) {
            var B = v.botoes || [], doServidor = function (k) { return B.filter(function (b) { return b.k === k; })[0]; };
            if (vgInterno() && v.situacao === 'pendente') {
                var rep = doServidor('reprovar'), apr = doServidor('aprovar');
                if (rep) {
                    botoes.push(botaoTlm('Não aprovar', 'bi-x-circle', '', function () { acaoVaga(v.codigo, rep); }));
                }
                if (v.podeEditar) {
                    botoes.push(botaoTlm('Ajustar antes', 'bi-pencil', '', function () { editarVaga(v.codigo); }));
                }
                if (apr) {
                    botoes.push(botaoTlm('Aprovar e publicar', 'bi-megaphone', 'verde', function () { acaoVaga(v.codigo, apr); }));
                }
            } else if (!vgInterno() && v.minha && v.situacao !== 'aberta') {
                if (v.podeEditar) {
                    botoes.push(botaoTlm(v.rotuloEditar, 'bi-pencil', 'pri', function () { editarVaga(v.codigo); }));
                }
            } else if (vgInterno()) {
                // "Enviar {N} ao escritório" (l.1095) logo depois de Fechar, só com alguém marcado.
                var nSel = selecaoDeEnvio(v).length;
                if (v.podeEnviar && nSel) {
                    botoes.push(botaoTlm('Enviar ' + nSel + ' ao escritório', 'bi-send-check', 'verde', function () { enviarCandidatos(v); }));
                }
                B.forEach(function (b) {
                    botoes.push(botaoTlm(b.rot, b.ic, tipoNoRodape(v, b), function () { acaoVaga(v.codigo, b); }));
                });
                if (v.podeEditar) {
                    botoes.push(botaoTlm('Editar vaga', 'bi-pencil', 'pri', function () { editarVaga(v.codigo); }));
                }
            } else if (!v.minha) {
                var bx = botaoDoExterno(v);
                (Array.isArray(bx) ? bx : bx ? [bx] : []).forEach(function (b) { botoes.push(b); });
            } else {
                // D11a (desenho 06 de 24/10, l.1169): a vaga própria aberta, para o externo; só fecha.
                botoes.push(botaoTlm('Sua vaga · acompanhe os candidatos acima', 'bi-people', 'pri', fecharTlm));
            }
        }
        modalVaga({ icone: 'bi-briefcase', titulo: v.titulo || 'Vaga', largura: '720px', corpo: corpo, botoes: botoes });
        ligarRelogio();
    }

    /* ---------------------------------------------------------------- formulário da vaga (tmVagaForm) */

    /** Passos de quem divulga (`divPassos`, l.1186): o primeiro azul, os outros claros. */
    var DIV_PASSOS = [
        ['1', 'Você envia', 'Preencha os dados da vaga.'],
        ['2', 'BlueJus revisa', 'O Master confere e ajusta se preciso.'],
        ['3', 'Vaga publicada', 'Você acompanha candidatos e o histórico.']
    ];
    /** Tipos da configuração interna (`tfTipos`, l.1188), só para o Master. */
    var TF_TIPOS = [
        [false, 'Vaga comum', 'bi-briefcase', 'Recebe candidaturas até o prazo; você escolhe quem contratar.'],
        [true, 'Oferta com tempo', 'bi-stopwatch', 'Disponível pelo tempo que você definir; os usuários não veem o contador.']
    ];
    /** Os dados de quem oferece com o olho (`ofCampos`, l.1182): `local` junta cidade e UF. */
    var OF_CAMPOS = [['resp', 'bi-person'], ['local', 'bi-geo-alt'], ['email', 'bi-envelope'], ['tel', 'bi-whatsapp'], ['site', 'bi-globe2']];

    /** Opções de "Disponível por" do desenho (`DUR`, l.933). Valor antigo fora delas (120, 1440) cai no padrão. */
    var DURACOES_OFERTA = [5, 10, 15, 30, 60];
    function duracaoValida(n) {
        return DURACOES_OFERTA.indexOf(n) >= 0 ? n : 0;
    }

    /** "N min", 120 vira "2 horas" e 1440 "24 horas" (`cfgDurs`, l.1159). */
    function rotuloDeMinutos(n) {
        return n >= 120 && n % 60 === 0 ? (n / 60) + ' horas' : n + ' min';
    }

    function dataDaqui(dias) {
        var d = new Date(agoraServidor() + dias * 864e5);
        var p = function (x) { return (x < 10 ? '0' : '') + x; };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    }

    /** Edição: busca o detalhe (traz `formulario`) e abre o formulário preenchido. */
    function editarVaga(codigo) {
        if (!podeEscreverVaga()) {
            return;
        }
        fecharMenu();
        var abrirCom = function (v) {
            if (!v.formulario) {
                toastErro(null);
                return;
            }
            abrirFormVaga(v);
        };
        if (S.vgDet && S.vgDet.codigo === codigo && S.vgDet.formulario) {
            abrirCom(S.vgDet);
            return;
        }
        pedir('GET', ROTA.vaga(codigo)).then(function (r) {
            if (!r.ok || !r.vaga) {
                toastErro(r.mensagem);
                return;
            }
            abrirCom(r.vaga);
        });
    }

    /**
     * `vaga` nula: "Nova vaga" (Central, nasce aberta) ou "Divulgar uma vaga na Central" (externo,
     * nasce pendente); com `vaga`: edição, preenchida com `vaga.formulario`.
     */
    function abrirFormVaga(vaga) {
        if (!podeEscreverVaga() || !S.vg) {
            return;
        }
        fecharMenu();
        var P = S.vg.ofertantePadrao || {}, c = cfgVagas() || {}, X = vaga ? vaga.formulario : null;
        var of = X ? (X.ofertante || {}) : P;
        S.vf = {
            codigo: vaga ? vaga.codigo : null,
            f: X ? {
                titulo: X.titulo || '', cargo: X.cargo || '', contratacao: X.contratacao || '', modalidade: X.modalidade || '',
                cidade: X.cidade || '', uf: X.uf || '', remuneracao: X.remuneracao || '', areas: (X.areas || []).slice(),
                requisitos: X.requisitos || '', inscricoesAte: X.inscricoesAte || '', exigeOab: !!X.exigeOab,
                periodoDias: X.periodoDias === undefined || X.periodoDias === null ? 15 : +X.periodoDias,
                oferta: !!X.oferta, duracaoDaOferta: duracaoValida(+X.duracaoDaOferta) || duracaoValida(+c.dur) || 15, qtd: +X.qtd || 1
            } : {
                titulo: '', cargo: '', contratacao: '', modalidade: '', cidade: '', uf: '', remuneracao: '',
                areas: [], requisitos: '', inscricoesAte: '', exigeOab: false, periodoDias: 15,
                // Em "Nova vaga" do Master, a oferta já vem ligada se houver tempo padrão (`novaVaga`, l.1115).
                oferta: vgMaster() && +c.dur > 0, duracaoDaOferta: duracaoValida(+c.dur) || 15, qtd: 1
            },
            of: { nome: of.nome || '', resp: of.resp || '', cidade: of.cidade || '', uf: of.uf || '', email: of.email || '', tel: of.tel || '', site: of.site || '' },
            ocultos: (of.ocultos || []).slice(),
            // O externo sem padrão salvo já abre editando os dados de quem oferece (`ofEd`, l.1115).
            editOf: !vaga && P.origem !== 'salvo' && !vgInterno(),
            erro: '',
            enviando: false
        };
        S.tlm = 'form';
        S.vgDet = null;
        S.vgDel = null;
        relogiosDetalhe = [];
        limpar($('teVagaModal'));
        renderFormVaga();
    }

    /** Modelo de requisitos por cargo (`MODELOS`, l.1068), textos do desenho. */
    var MODELOS = {
        'Advogado(a)': 'Atividades: acompanhamento processual, elaboração de peças, audiências e atendimento a clientes.\nRequisitos: OAB ativa, boa redação jurídica e organização com prazos.',
        'Advogado(a) associado(a)': 'Atividades: condução de carteira de processos com autonomia, audiências e relacionamento com clientes.\nRequisitos: OAB ativa e experiência na área.',
        'Correspondente': 'Atividades: diligências, protocolos, audiências e cópias de autos na comarca indicada.\nRequisitos: OAB ativa (para atos privativos) e disponibilidade na região.',
        'Estagiário(a)': 'Atividades: pesquisa jurídica, apoio na elaboração de peças, acompanhamento processual e organização de documentos.\nRequisitos: cursando Direito, boa escrita e vontade de aprender.',
        'Assistente jurídico': 'Atividades: organização de pastas, controle de prazos, protocolos e apoio à equipe.\nRequisitos: experiência com rotina de escritório e sistemas processuais.',
        'Controladoria': 'Atividades: controle de prazos e publicações, distribuição de tarefas e relatórios.\nRequisitos: experiência em controladoria jurídica e organização.',
        'Administrativo': 'Atividades: rotinas administrativas e financeiras do escritório, atendimento e agenda.\nRequisitos: organização e boa comunicação.'
    };

    /**
     * `tfOn.cargo` (l.970): ao escolher o cargo, "Requisitos e atividades" recebe o modelo dele se estiver
     * vazio ou ainda igual ao modelo do cargo anterior. O que a pessoa escreveu não é sobrescrito.
     */
    function aplicarModeloDoCargo(f, anterior) {
        var modelo = Object.prototype.hasOwnProperty.call(MODELOS, f.cargo) ? MODELOS[f.cargo] : '';
        var prev = Object.prototype.hasOwnProperty.call(MODELOS, anterior || '') ? MODELOS[anterior] : '';
        var atual = String(f.requisitos || '');
        if (!modelo || (atual.trim() && atual !== prev)) {
            return;
        }
        f.requisitos = modelo;
        if (S.tlm === 'form' && S.vf && S.vf.f === f) {
            renderFormVaga();
        }
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

    /** `aoMudar(anterior)`: depois de gravar a escolha (ex.: o modelo de requisitos do cargo). */
    function selectVf(obj, k, ops, req, aoMudar) {
        return el('select', {
            class: 'te-vf-ctl' + (req && S.vf && S.vf.erro && vazio(obj[k]) ? ' is-erro' : ''),
            value: obj[k] || '',
            onchange: function (e) {
                var anterior = obj[k];
                obj[k] = e.target.value;
                if (req && S.vf && S.vf.erro) {
                    e.target.classList.toggle('is-erro', vazio(obj[k]));
                }
                if (aoMudar) {
                    aoMudar(anterior);
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

    /** Limite de dias do não-Master (`maxDias`); o Master não tem limite. */
    function maxDiasVf() { return vgMaster() ? 0 : +((cfgVagas() || {}).maxDias) || 0; }

    /** `tfPer` (l.1185): com limite, o período fora dele vira o menor entre 15 e o máximo. */
    function periodoEfetivo(f) {
        var mx = maxDiasVf(), cur = +f.periodoDias;
        return mx ? (cur && cur <= mx ? cur : Math.min(15, mx)) : cur;
    }

    /** `perOps` (l.1185): 1 a 60 dias; com limite, só até o máximo (marcado); sem limite, "Até a vaga ser preenchida". */
    function opcoesDePeriodo() {
        var mx = maxDiasVf(), B = [1, 3, 7, 15, 30, 60];
        var L = B.concat(mx && B.indexOf(mx) < 0 ? [mx] : []).sort(function (a, b) { return a - b; })
            .filter(function (n) { return !mx || n <= mx; })
            .map(function (n) { return [n, n + (n === 1 ? ' dia' : ' dias') + (mx && n === mx ? ' (máximo)' : '')]; });
        return mx ? L : L.concat([[0, 'Até a vaga ser preenchida']]);
    }

    /** Trocar o período também preenche "INSCRIÇÕES ATÉ" (`tfOnPer`, l.1185). */
    function periodoVf(F) {
        return rotuloVf('QUANTO TEMPO A VAGA FICA DISPONÍVEL', el('select', {
            class: 'te-vf-ctl', value: String(periodoEfetivo(F.f)),
            onchange: function (e) {
                var d = +e.target.value;
                F.f.periodoDias = d;
                F.f.inscricoesAte = d ? dataDaqui(d) : '';
                renderFormVaga();
            }
        }, opcoesDePeriodo().map(function (o) { return el('option', { value: String(o[0]), text: o[1] }); })), ' is-cheio is-pequeno');
    }

    /** "QUEM ESTÁ OFERECENDO" (l.238 a 244 e l.1182 a 1184): resumo com o olho em cada dado, ou os campos para editar. */
    function blocoOfertante(F, L) {
        var of = F.of, conteudo, P = (S.vg && S.vg.ofertantePadrao) || {};
        if (!F.editOf) {
            var valor = { resp: of.resp, local: [of.cidade, of.uf].filter(function (x) { return !vazio(x); }).join('/'), email: of.email, tel: of.tel, site: of.site };
            var chips = OF_CAMPOS.filter(function (c) { return !vazio(valor[c[0]]); }).map(function (c) {
                var k = c[0], oc = F.ocultos.indexOf(k) >= 0;
                var dica = oc ? 'Oculto · clique para mostrar na vaga' : 'Visível na vaga · clique para ocultar';
                return el('button', {
                    type: 'button', class: 'te-vf-of-chip' + (oc ? ' is-oculto' : ''), title: dica,
                    attrs: { 'data-tip': dica, 'aria-pressed': oc ? 'false' : 'true' },
                    onclick: function () {
                        F.ocultos = oc ? F.ocultos.filter(function (x) { return x !== k; }) : F.ocultos.concat([k]);
                        renderFormVaga();
                    }
                }, [ic(c[1]), valor[k], el('i', { class: 'bi te-vf-of-olho ' + (oc ? 'bi-eye-slash' : 'bi-eye') })]);
            });
            conteudo = el('div', { class: 'te-vf-of-resumo' }, [
                el('b', { class: 'te-vf-of-nome', text: of.nome }),
                chips.length ? el('div', { class: 'te-vf-of-chips' }, chips) : null,
                el('span', { class: 'te-vf-of-dica', text: 'Clique num dado para mostrar ou ocultar na vaga. Ocultos: os candidatos falam com você pelo BlueJus.' }),
                el('div', { class: 'te-vf-of-grade' }, [periodoVf(F)])
            ]);
        } else {
            conteudo = el('div', null, [
                el('div', { class: 'te-vf-of-campos' }, [
                    rotuloVf('ESCRITÓRIO *', ctlVf('input', of, 'nome', { type: 'text' }, true), ' is-pequeno'),
                    rotuloVf('RESPONSÁVEL PELA VAGA', ctlVf('input', of, 'resp', { type: 'text' }), ' is-pequeno'),
                    rotuloVf('CIDADE', ctlVf('input', of, 'cidade', { type: 'text' }), ' is-pequeno'),
                    rotuloVf('UF', selectVf(of, 'uf', L.ufs), ' is-pequeno'),
                    rotuloVf('E-MAIL', ctlVf('input', of, 'email', { type: 'email' }), ' is-pequeno'),
                    rotuloVf('TELEFONE / WHATSAPP', ctlVf('input', of, 'tel', { type: 'tel' }), ' is-pequeno'),
                    rotuloVf('SITE OU INSTAGRAM', ctlVf('input', of, 'site', { type: 'text' }), ' is-pequeno'),
                    periodoVf(F)
                ]),
                el('div', { class: 'te-vf-of-padrao' }, [ic('bi-bookmark-check'), ' Estes dados ficam salvos como padrão para as próximas vagas.'])
            ]);
        }
        return el('div', { class: 'te-vf-of' }, [
            el('div', { class: 'te-vf-of-cab' }, [
                ic('bi-building-check'),
                el('b', { text: 'QUEM ESTÁ OFERECENDO' }),
                el('span', { class: 'te-vf-of-orig', text: P.origem === 'salvo' ? 'padrão salvo' : 'do seu cadastro' }),
                el('button', {
                    type: 'button', class: 'te-vf-of-editar',
                    onclick: function () { F.editOf = !F.editOf; renderFormVaga(); }
                }, [ic(F.editOf ? 'bi-check2' : 'bi-pencil'), F.editOf ? 'Concluir' : 'Editar dados'])
            ]),
            conteudo
        ]);
    }

    function renderFormVaga() {
        var F = S.vf;
        if (!F) {
            return;
        }
        var L = listasVg(), f = F.f, gere = vgDaCentral(), V = S.vg || {};
        var corpo = [];

        if (V.podeDivulgar) {
            corpo.push(el('div', { class: 'te-vf-passos' }, DIV_PASSOS.map(function (d, i) {
                return el('div', { class: 'te-vf-passo' }, [
                    el('span', { class: 'te-vf-passo-n', style: { background: i === 0 ? '#0a6fa0' : '#9fc3d8' }, text: d[0] }),
                    el('span', { class: 'te-vf-passo-txt' }, [el('b', { text: d[1] }), d[2]])
                ]);
            })));
        }
        corpo.push(blocoOfertante(F, L));

        if (vgMaster()) {
            corpo.push(el('div', { class: 'te-vf-interna' }, [ic('bi-lock'), 'CONFIGURAÇÃO INTERNA · SÓ O MASTER VÊ']));
            corpo.push(el('div', { class: 'te-vf-tipos' }, TF_TIPOS.map(function (t) {
                var on = !!f.oferta === t[0];
                return el('button', {
                    type: 'button', class: 'te-vf-tipo' + (on ? ' is-on' : ''),
                    attrs: { 'aria-pressed': on ? 'true' : 'false' },
                    onclick: function () {
                        f.oferta = t[0];
                        f.duracaoDaOferta = duracaoValida(+f.duracaoDaOferta) || duracaoValida(+((cfgVagas() || {}).dur)) || 15;
                        f.qtd = f.qtd || 1;
                        renderFormVaga();
                    }
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
                            return opcaoVf(rotuloDeMinutos(+m), +f.duracaoDaOferta === +m, function () { f.duracaoDaOferta = +m; renderFormVaga(); });
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
            rotuloVf('CARGO *', selectVf(f, 'cargo', L.cargos, true, function (anterior) { aplicarModeloDoCargo(f, anterior); })),
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
            icone: 'bi-megaphone',
            titulo: F.codigo ? 'Editar vaga' : gere ? 'Nova vaga' : 'Divulgar uma vaga na Central',
            largura: '720px',
            corpo: corpo,
            botoes: [
                botaoTlm('Cancelar', 'bi-x', '', fecharTlm),
                botaoTlm(F.codigo ? 'Salvar alterações' : gere ? 'Publicar vaga' : 'Enviar para aprovação', gere ? 'bi-megaphone' : 'bi-send', 'pri', enviarFormVaga)
            ]
        });
    }

    /** Validação na ordem do `salvarVaga()` (l.1005); o servidor repete e responde 422 com as mesmas mensagens. */
    function erroDoFormVaga(F) {
        if (vazio(F.of.nome)) {
            F.editOf = true;
            return 'Informe o escritório que está oferecendo a vaga.';
        }
        if (vazio(F.f.titulo)) {
            return 'Informe o título da vaga.';
        }
        return vazio(F.f.cargo) ? 'Escolha o cargo.' : '';
    }

    function enviarFormVaga() {
        var F = S.vf;
        if (!F || F.enviando || !podeEscreverVaga()) {
            return;
        }
        var erro = erroDoFormVaga(F);
        if (erro) {
            F.erro = erro;
            renderFormVaga();
            return;
        }
        var f = F.f, of = F.of, gere = vgDaCentral(), edicao = !!F.codigo;
        var t = function (x) { return String(x || '').trim(); };
        var corpo = {
            titulo: t(f.titulo), cargo: f.cargo, contratacao: f.contratacao, modalidade: f.modalidade,
            cidade: t(f.cidade), uf: f.uf, remuneracao: t(f.remuneracao), areas: f.areas.slice(),
            requisitos: t(f.requisitos), inscricoesAte: f.inscricoesAte || '', exigeOab: !!f.exigeOab,
            periodoDias: periodoEfetivo(f),
            duracaoDaOferta: +f.duracaoDaOferta,
            qtd: Math.max(1, Math.min(20, parseInt(f.qtd, 10) || 1)),
            ofertante: {
                nome: t(of.nome), resp: t(of.resp), cidade: t(of.cidade), uf: of.uf || '', email: t(of.email), tel: t(of.tel), site: t(of.site),
                ocultos: F.ocultos.slice()
            }
        };
        // Só o Master escolhe oferta (o servidor ignora dos outros).
        if (vgMaster()) {
            corpo.oferta = !!f.oferta;
        }
        F.enviando = true;
        Array.prototype.forEach.call(document.querySelectorAll('#teVagaModal .te-tlm-rodape button'), function (b) { b.disabled = true; });
        pedir('POST', edicao ? ROTA.vagaEditar(F.codigo) : ROTA.vagaSalvar(), corpo).then(function (r) {
            if (S.vf !== F) {
                return;
            }
            F.enviando = false;
            if (!r.ok) {
                F.erro = r.mensagem;
                renderFormVaga();
                return;
            }
            fecharTlm();
            mostrarToast({
                tit: edicao ? 'Vaga atualizada' : gere ? 'Vaga publicada' : 'Pedido enviado ao BlueJus',
                txt: gere ? t(f.titulo) : 'A equipe revisa e publica. Você acompanha em Minhas vagas enviadas.',
                hora: ''
            });
            S.vgFiltro = gere ? 'aberta' : 'minhas';
            S.vgSel = [];
            carregarVagas();
        });
    }

    /* ---------------------------------------------------------------- configuração das vagas (tmCfgV), só Master */

    /** `cfgTgs` (l.1160). */
    var CFG_VAGAS_TGS = [
        ['sino', 'bi-bell', 'Sininho de vaga nova', 'Os usuários podem ligar o aviso e recebem uma notificação quando uma vaga for publicada.'],
        ['motiv', 'bi-emoji-smile', 'Mensagens motivacionais', 'Quando alguém perde uma vaga disputada, aparece uma mensagem animada incentivando a continuar tentando.']
    ];

    /** Aberto pelo menu ⋮ "Opções do Master" > "Ofertas, avisos e mensagens…" (l.1265). */
    function abrirCfgVagas() {
        if (!cfgVagas() || !vgMaster() || !podeEscreverVaga()) {
            return;
        }
        fecharMenu();
        S.tlm = 'cfg';
        S.vgDet = null;
        S.vf = null;
        S.vgDel = null;
        relogiosDetalhe = [];
        limpar($('teVagaModal'));
        renderCfgVagas();
    }

    function renderCfgVagas() {
        var c = cfgVagas();
        if (!c || S.tlm !== 'cfg') {
            return;
        }
        var grupo = function (k, tit, txt, ops, primeiro) {
            return el('div', { class: 'te-cv-grupo' + (primeiro ? '' : ' is-div') }, [
                el('b', { text: tit }),
                el('span', { class: 'te-cv-txt', text: txt }),
                el('div', { class: 'te-vf-opcoes' }, ops.map(function (o) {
                    return opcaoVf(o[1], +c[k] === o[0], function () { gravarCfgVaga(k, o[0]); }, ' is-cfg');
                }))
            ]);
        };
        var corpo = [
            el('div', { class: 'te-cv-aviso' }, [ic('bi-lock'), ' Configuração interna. Os usuários não veem o tempo da oferta nem estas regras; a vaga simplesmente deixa de aparecer quando o tempo acaba ou é preenchida.']),
            grupo('dur', 'Tempo padrão de disponibilidade das vagas', 'Vale para as vagas que você aprovar ou publicar. Pode ser alterado em cada vaga pelo botão Ajustar.',
                [[0, 'Sem tempo (vaga comum)']].concat([5, 10, 15, 30, 60, 120, 1440].map(function (n) { return [n, rotuloDeMinutos(n)]; })), true),
            grupo('maxDias', 'Divulgação máxima para vagas de escritórios', 'Limita o período que o escritório pode escolher. Pedidos acima do limite são ajustados na aprovação.',
                [[0, 'Sem limite']].concat([2, 3, 4, 7, 15, 30].map(function (n) { return [n, n + ' dias']; }))),
            grupo('minEnvio', 'Candidatos enviados ao escritório', 'O escritório não vê todos os inscritos: você seleciona e envia os melhores. Padrão mínimo por vaga:',
                [1, 2, 3, 4, 5, 10].map(function (n) { return [n, n + (n === 1 ? ' candidato' : ' candidatos')]; }))
        ].concat(CFG_VAGAS_TGS.map(function (t) {
            return el('label', { class: 'te-cv-tg' }, [
                ic(t[1]),
                el('span', { class: 'te-flex1' }, [el('b', { text: t[2] }), el('span', { class: 'te-cv-txt', text: t[3] })]),
                el('input', { type: 'checkbox', checked: !!c[t[0]], onchange: function (e) { gravarCfgVaga(t[0], e.target.checked); } })
            ]);
        }));
        modalVaga({
            icone: 'bi-sliders', titulo: 'Configurações de vagas e ofertas · Master', largura: '720px', corpo: corpo,
            // l.1099: "Ver exemplo de mensagem" abre o QUASE (como o `quase()`, só com as mensagens ligadas).
            botoes: [botaoTlm('Ver exemplo de mensagem', 'bi-emoji-smile', '', verExemploDoQuase), botaoTlm('Concluir', 'bi-check2', 'pri', fecharTlm)]
        });
    }

    /** Cada clique grava (como o resto da configuração do módulo); se o servidor recusar, volta o valor. */
    function gravarCfgVaga(k, valor) {
        var c = cfgVagas();
        if (!c || !vgMaster() || !podeEscreverVaga()) {
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

    /* =============================================================================
       CAMPANHA POR E-MAIL DO MASTER (spec VS 18.10.26 §4.10)
       Desenho "06 - Trabalho Externo - VS 18.10.26": modal `tmCamp` (l.330 a 341), `campDe` e o
       rodapé (l.1082 a 1097), título e largura (l.1155 e 1156), públicos (l.1168 e 1169). Monta, mostra
       e exporta; não envia. O servidor decide quem recebe (LGPD), monta o e-mail e grava o log de cada
       exportação; a tela só escolhe público, filtros e assunto e pinta a prévia (iframe sem scripts).
       Entradas: menu ⋮ do Master (l.1265), `#camp=1` (l.1078) e o KPI ALERTAS POR E-MAIL (l.1146).
       ============================================================================= */

    var CAMP_ASSUNTO_MS = 400;
    var campTemporizador = null;

    function abrirCampanha() {
        if (!vgMaster() || previa() || !D.urlCampanha) {
            return;
        }
        fecharMenu();
        S.tlm = 'camp';
        S.vgDet = null;
        S.vf = null;
        S.vgDel = null;
        relogiosDetalhe = [];
        S.cp = { pub: 'cand', uf: '', area: '', assunto: null, dados: null, seq: 0 };
        limpar($('teVagaModal'));
        carregarCampanha();
    }

    /** `pub`, `uf`, `area` e, se o Master escreveu, `assunto`: a query do GET da campanha (os POST levam no corpo). */
    function campParams() {
        var cp = S.cp, q = new URLSearchParams({ pub: cp.pub, uf: cp.uf, area: cp.area });
        if (cp.assunto !== null && !vazio(cp.assunto)) {
            q.set('assunto', cp.assunto);
        }
        return q;
    }

    function campUrl(base) {
        var u = String(base || '');
        return u + (u.indexOf('?') >= 0 ? '&' : '?') + campParams().toString();
    }

    /** A resposta de um pedido antigo (filtro trocado no meio) é descartada. */
    function carregarCampanha() {
        var cp = S.cp, seq = ++cp.seq;
        pedir('GET', campUrl(D.urlCampanha)).then(function (r) {
            if (S.cp !== cp || S.tlm !== 'camp' || seq !== cp.seq) {
                return;
            }
            if (!r.ok) {
                toastErro(r.mensagem);
                if (!cp.dados) {
                    fecharTlm();
                }
                return;
            }
            cp.dados = r;
            renderCampanha();
        });
    }

    function campTrocar(mudanca) {
        Object.keys(mudanca).forEach(function (k) { S.cp[k] = mudanca[k]; });
        carregarCampanha();
    }

    /** O assunto refaz a prévia depois de uma pausa na digitação; o campo guarda o cursor. */
    function campAssunto(e) {
        S.cp.assunto = e.target.value;
        clearTimeout(campTemporizador);
        campTemporizador = setTimeout(function () {
            if (S.cp && S.tlm === 'camp') {
                carregarCampanha();
            }
        }, CAMP_ASSUNTO_MS);
    }

    /** Os botões de público (`cpPubs`, l.1169): o indisponível fica desabilitado, com a dica do servidor. */
    function campPublicos(K) {
        return (Array.isArray(K.publicos) ? K.publicos : []).map(function (u) {
            var on = S.cp.pub === u.k, livre = u.disponivel === true;
            return el('button', {
                type: 'button', class: 'te-cp-pub' + (on ? ' is-on' : ''), disabled: !livre,
                attrs: livre ? { 'aria-pressed': on ? 'true' : 'false' } : { 'aria-pressed': 'false', title: String(u.dica || '') },
                onclick: function () {
                    if (livre && !on) {
                        campTrocar({ pub: u.k, assunto: null });
                    }
                }
            }, [
                ic(String(u.ic || 'bi-people')),
                el('span', { class: 'te-flex1' }, [el('b', { text: u.rot }), el('span', { class: 'te-cp-pub-sub', text: u.sub })]),
                !livre && !vazio(u.dica) ? el('span', { class: 'te-cp-dica', text: u.dica }) : null,
                on ? ic('bi-check-circle-fill', { color: '#0a6fa0' }) : null
            ]);
        });
    }

    function campSelect(valor, rotuloTodos, opcoes, chave) {
        return el('select', {
            class: 'te-cp-sel', value: valor, attrs: { 'aria-label': rotuloTodos },
            onchange: function (e) { var m = {}; m[chave] = e.target.value; campTrocar(m); }
        }, [el('option', { value: '', text: rotuloTodos })].concat((Array.isArray(opcoes) ? opcoes : []).map(function (o) {
            return el('option', { value: String(o), text: String(o) });
        })));
    }

    /** A coluna da esquerda (l.332 a 338): público, filtros, assunto, resumo, quem fica de fora e a nota. */
    function campColunaDosDados(K) {
        var cp = S.cp, resumo = K.resumo || {};
        var assunto = el('input', { class: 'te-cp-assunto', id: 'teCpAssunto', value: cp.assunto !== null ? cp.assunto : String(K.assunto || ''), oninput: campAssunto });
        var foras = (Array.isArray(K.deFora) ? K.deFora : []).map(function (f) {
            return el('span', { class: 'te-cp-fora' }, [ic('bi-person-dash'), ' ', el('span', { text: f.motivo })]);
        });
        return el('div', { class: 'te-cp-col' }, [
            el('div', { class: 'te-sh-rot', text: 'PÚBLICO' })
        ].concat(campPublicos(K)).concat([
            el('div', { class: 'te-cp-filtros' }, [campSelect(cp.uf, 'Todas as UFs', K.ufs, 'uf'), campSelect(cp.area, 'Todas as áreas', K.areas, 'area')]),
            el('label', { class: 'te-cp-rot-campo' }, ['ASSUNTO DO E-MAIL', assunto]),
            el('div', { class: 'te-cp-resumo' }, [
                el('span', null, [ic('bi-people', { color: '#0a6fa0' }), ' ', el('b', { text: resumo.destinatarios || '' })]),
                el('span', null, [ic('bi-briefcase', { color: '#0a6fa0' }), ' ', resumo.vagas || ''])
            ].concat(foras)),
            el('div', { class: 'te-cp-nota' }, [ic('bi-info-circle', { color: '#0a6fa0' }), ' O e-mail é feito em tabelas com estilos no próprio HTML, o formato aceito por Gmail, Outlook e celulares. Baixe o HTML e a lista e envie pela sua ferramenta de e-mail em massa (Brevo, Mailchimp, Amazon SES). O rodapé já traz o link de cancelar inscrição: troque o marcador pelo link da ferramenta.'])
        ]));
    }

    /** "PRÉVIA DO E-MAIL" (l.340): o HTML do servidor num iframe sem scripts. */
    function campPrevia(K) {
        return el('div', { class: 'te-cp-col' }, [
            el('div', { class: 'te-sh-rot', text: 'PRÉVIA DO E-MAIL' }),
            el('iframe', { class: 'te-cp-previa', srcdoc: String(K.html || ''), attrs: { title: 'Prévia do e-mail', sandbox: '' } })
        ]);
    }

    /** `tmCamp`, 960px (l.1155), com o rodapé do desenho (l.1097). */
    function renderCampanha() {
        var cp = S.cp;
        if (!cp || !cp.dados || S.tlm !== 'camp') {
            return;
        }
        var ativo = document.activeElement, noAssunto = !!(ativo && ativo.id === 'teCpAssunto');
        var ini = noAssunto ? ativo.selectionStart : null, fim = noAssunto ? ativo.selectionEnd : null;
        modalVaga({
            icone: 'bi-envelope-paper', titulo: 'Campanha por e-mail · Master', largura: '960px',
            corpo: [el('div', { class: 'te-cp' }, [campColunaDosDados(cp.dados), campPrevia(cp.dados)])],
            botoes: [
                botaoTlm('Fechar', 'bi-x', '', fecharTlm),
                botaoTlm('Lista de destinatários (CSV)', 'bi-filetype-csv', '', campBaixarCsv),
                botaoTlm('Copiar HTML', 'bi-code-slash', '', campCopiarHtml),
                botaoTlm('Baixar HTML do e-mail', 'bi-download', 'pri', campBaixarHtml)
            ]
        });
        var novo = $('teCpAssunto');
        if (noAssunto && novo) {
            novo.focus();
            novo.setSelectionRange(ini, fim);
        }
    }

    function campSemDestinatarios() {
        mostrarToast({ tit: 'Sem destinatários', txt: 'Ainda não há e-mails autorizados para este público.', hora: '' });
    }

    function campBaixarCsv() {
        if (!S.cp || !S.cp.dados) {
            return;
        }
        if (!(+S.cp.dados.destinatarios > 0)) {
            campSemDestinatarios();
            return;
        }
        campBaixar(D.urlCampanhaCsv, 'destinatarios-campanha.csv', function () {});
    }

    function campBaixarHtml() {
        if (!S.cp || !S.cp.dados) {
            return;
        }
        var n = +S.cp.dados.destinatarios || 0;
        campBaixar(D.urlCampanhaHtml, 'email-campanha-bluejus.html', function () {
            mostrarToast({ tit: 'E-mail pronto', txt: 'Envie para ' + n + ' destinatário(s) pela sua ferramenta de e-mail em massa.', hora: '' });
        });
    }

    /**
     * "Copiar HTML": o servidor registra a exportação e devolve o HTML com o link de vagas próprio; a
     * área de transferência recebe esse HTML (toasts da l.1097).
     */
    function campCopiarHtml() {
        if (!S.cp || !S.cp.dados) {
            return;
        }
        var corpo = campCorpo();
        corpo.acao = 'copiar';
        pedir('POST', String(D.urlCampanhaRegistro || ''), corpo).then(function (r) {
            if (!r.ok || typeof r.html !== 'string') {
                toastErro(r.mensagem);
                return;
            }
            (navigator.clipboard && navigator.clipboard.writeText ? navigator.clipboard.writeText(r.html) : Promise.reject()).then(function () {
                mostrarToast({ tit: 'HTML copiado', txt: 'Cole no editor de e-mail da sua ferramenta de envio.', hora: '' });
            }, function () {
                mostrarToast({ tit: 'Não foi possível copiar', txt: 'Use Baixar HTML.', hora: '' });
            });
        });
    }

    /** Os filtros no corpo dos POST (CSV, HTML e registro do "Copiar HTML"). */
    function campCorpo() {
        var cp = S.cp, corpo = { pub: cp.pub, uf: cp.uf, area: cp.area };
        if (cp.assunto !== null && !vazio(cp.assunto)) {
            corpo.assunto = cp.assunto;
        }
        return corpo;
    }

    /**
     * O download por `fetch` em POST (a exportação grava o log; o CSRF vai no header pelo interceptador do
     * base.html.twig): a recusa e a lista vazia chegam em JSON (403 e 404) e viram toast; o arquivo vem
     * como blob, com o nome do Content-Disposition.
     */
    function campBaixar(url, nomePadrao, pronto) {
        fetch(String(url || ''), {
            method: 'POST', credentials: 'same-origin', body: JSON.stringify(campCorpo()),
            headers: { 'Accept': 'text/csv, text/html, application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            var tipo = (r.headers.get('Content-Type') || '').toLowerCase();
            if (!r.ok || tipo.indexOf('json') >= 0) {
                return r.json().then(function (j) { return j; }, function () { return null; }).then(function (j) {
                    if (r.status === 404 && j && typeof j === 'object') {
                        campSemDestinatarios();
                        return;
                    }
                    toastErro(j && j.mensagem);
                });
            }
            var nome = nomeDoAnexo(r.headers.get('Content-Disposition')) || nomePadrao;
            return r.blob().then(function (blob) {
                var u = URL.createObjectURL(blob);
                var a = el('a', { href: u, attrs: { download: nome } });
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(function () { URL.revokeObjectURL(u); }, 1000);
                pronto();
            });
        }).then(null, function () {
            toastErro();
        });
    }

    /* =============================================================================
       CURRÍCULOS E BANCO DE TALENTOS (spec VS 18.10.26 §4.5, fatia F3a)
       Desenho "06 - Trabalho Externo - VS 18.10.26": banco interno (l.170 a 213), "Meu currículo"
       do externo (l.214 a 224), formulário `tmCvForm` (l.254 a 283), detalhe `tmCv` (l.301 a 312),
       pré-visualização `tmCvDoc` (l.370) e o menu de contexto (l.423 a 428). Lógica do
       `renderVals()`: l.987, 995 a 1014, 1038, 1048 a 1052, 1062 a 1066, 1094, 1100, 1101, 1105,
       1108 a 1120, 1148 a 1156 e 1171 a 1190. Painel, KPIs, gráficos e Termo (F3b), candidaturas
       (F2b) e engajamento (F4) ficam de fora.
       O servidor decide tudo (quem vê, quem age, percentual, textos de OAB e local); a prévia do
       Master (`?como=`) só lê.
       ============================================================================= */

    /** Situação no banco de talentos (`CST`, l.845): rótulo e cor. A ordem é a dos botões do detalhe. */
    var CST = [
        ['novo', 'Novo', '#0a6fa0'],
        ['analise', 'Em análise', '#9a5b00'],
        ['entrevista', 'Entrevista', '#5b3f9e'],
        ['favorito', 'Favorito', '#1e7b45'],
        ['arquivado', 'Arquivado', '#6b8494']
    ];
    /** Filtro "Situação OAB" do banco (`SIT` curto, l.1120). */
    var CV_SIT_FILTRO = [['adv', 'Advogado(a)'], ['estOab', 'Estagiário(a) com OAB'], ['est', 'Estudante'], ['bach', 'Bacharel(a)']];
    /** Filtro do KPI "COM OAB" (§4.9, lacuna 1): `adv` e `estOab` juntos. */
    var CV_SIT_COM_OAB = 'oab';
    /** Filtro "Termo" (`cvTerOps`, l.1148); só aparece com o termo ligado pelo Master (`termoLigado`). */
    var CV_TERMO_FILTRO = [['sem', 'Sem termo'], ['enviado', 'Termo aguardando'], ['aceito', 'Termo aceito']];
    /** Botões de situação do formulário (`tfSitOps`, l.1173). */
    var CV_SIT_OPS = [
        ['adv', 'Advogado(a) inscrito(a)', 'bi-award'],
        ['estOab', 'Estagiário(a) com OAB', 'bi-mortarboard-fill'],
        ['est', 'Estudante de Direito', 'bi-mortarboard'],
        ['bach', 'Bacharel(a) sem OAB', 'bi-journal-bookmark']
    ];
    /** Trabalho voluntário (`tfVolOps`, l.1187): clicar de novo no escolhido limpa. */
    var CV_VOL_OPS = [['sim', 'Sim'], ['talvez', 'Talvez'], ['nao', 'Não']];
    var CV_NIVEIS = ['Básico', 'Intermediário', 'Avançado', 'Fluente', 'Nativo'];
    var CV_LGPD = 'Autorizo o BlueJus a guardar meus dados e compartilhá-los apenas com quem publica as vagas a que eu me candidatar (LGPD). Posso excluir meu currículo a qualquer momento.';
    var NOTA_MS = 800;

    /* ---------------------------------------------------------------- dados e HTTP do módulo */

    function veCv() { return !!S.cv && !S.painel.bloqueio; }
    function cvInterno() { return !!(S.cv && S.cv.interno); }
    function cvMaster() { return !!(S.cv && S.cv.ehMaster); }
    /** Prévia do Master: nada de escrita (marcações, status, nota, formulário, exclusão). */
    function podeEscreverCv() { return !previa(); }
    /** Status, nota e CSV exigem `talentos` (o servidor manda `podeGerir`). */
    function cvPodeGerir() { return !!(S.cv && S.cv.podeGerir) && podeEscreverCv(); }
    function curriculos() { return (S.cv && S.cv.curriculos) || []; }
    function cvDe(codigo) { return curriculos().filter(function (x) { return x.codigo === codigo; })[0] || null; }
    function nivelDoCv(x) { return Math.max(0, Math.min(3, +((x.importancia || {}).nivel) || 0)); }
    function cvImportante(x) { return nivelDoCv(x) >= 2; }
    /** Áreas, UFs, cargos e modalidades: as mesmas listas da aba Vagas (a resposta das vagas traz `listas`). */
    function listasCv() { return (S.cv && S.cv.listas) || listasVg(); }

    /** Selo da aba no interno: os currículos com situação "Novo" (`novosCv`, l.1105). O externo não tem selo. */
    function numeroDaAbaCv() {
        var cont = contadorDoEngajamento('curriculos');
        if (cont !== null) {
            return cvInterno() ? cont : 0;
        }
        return cvInterno() ? curriculos().filter(function (x) { return x.status === 'novo'; }).length : 0;
    }

    /**
     * Como o `pedir()`, mas aceita a resposta 2xx sem `ok` (o contrato da §4.5 só garante `ok` no erro
     * e na nota). Erro: `{ok:false, mensagem}` com 422, 403 ou 404.
     */
    function pedirCv(metodo, url, corpo) {
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
                if (!r.ok || j.ok === false) {
                    return { ok: false, mensagem: j.mensagem || FALHA };
                }
                j.ok = true;
                return j;
            }, function () {
                return { ok: false, mensagem: FALHA };
            });
        }, function () {
            return { ok: false, mensagem: FALHA };
        });
    }

    /** O documento do currículo (HTML do `cvHTML`): GET do salvo ou POST da prévia do formulário. */
    function pedirHtml(metodo, url, corpo) {
        var op = { method: metodo, credentials: 'same-origin', headers: { 'Accept': 'text/html, application/json', 'X-Requested-With': 'XMLHttpRequest' } };
        if (corpo !== undefined) {
            op.headers['Content-Type'] = 'application/json';
            op.body = JSON.stringify(corpo);
        }
        return fetch(url, op).then(function (r) {
            var tipo = (r.headers.get('Content-Type') || '').toLowerCase();
            if (tipo.indexOf('json') >= 0) {
                return r.json().then(function (j) {
                    if (!r.ok || !j || j.ok === false || typeof j.html !== 'string') {
                        return { ok: false, mensagem: (j && j.mensagem) || FALHA };
                    }
                    return { ok: true, html: j.html };
                }, function () { return { ok: false, mensagem: FALHA }; });
            }
            if (!r.ok) {
                return { ok: false, mensagem: FALHA };
            }
            return r.text().then(function (h) { return { ok: true, html: h }; }, function () { return { ok: false, mensagem: FALHA }; });
        }, function () {
            return { ok: false, mensagem: FALHA };
        });
    }

    /**
     * GET da lista no recorte do perfil (com `?como=` na prévia). Resposta pedida por outro perfil é
     * descartada. Sem acesso (403), o módulo simplesmente não aparece. `aviso`: o toast "Lista atualizada".
     */
    function carregarCurriculos(aviso) {
        var como = S.como;
        if (!D.urlCurriculos) {
            return Promise.resolve({ ok: false });
        }
        return pedirCv('GET', ROTA.curriculos()).then(function (r) {
            if (como !== S.como) {
                return r;
            }
            if (!r.ok) {
                if (moduloAtual() === 'cv') {
                    toastErro(r.mensagem);
                }
                return r;
            }
            // Tolera a lista embrulhada como a das vagas (`{ok, curriculos: {...}}`).
            var L = r.curriculos && !Array.isArray(r.curriculos) && typeof r.curriculos === 'object' ? r.curriculos : r;
            S.cv = {
                interno: !!L.interno, podeGerir: !!L.podeGerir, ehMaster: !!L.ehMaster,
                meu: L.meu || null, curriculos: Array.isArray(L.curriculos) ? L.curriculos : [],
                listas: L.listas || null, agora: L.agora || null,
                // As candidaturas do externo (§4.6), para o convite de vaga não reaparecer.
                minhas: Array.isArray(L.minhas) ? L.minhas : []
            };
            renderModulos();
            renderCurriculos();
            agendarEngajamento();
            // O painel do Master (§4.9) recarrega junto com a lista, se estiver à vista.
            if (moduloAtual() === 'cv' && vistaCv() === 'painel') {
                carregarPainelCv();
            }
            if (aviso) {
                mostrarToast({ tit: 'Lista atualizada', txt: '', hora: '' });
            }
            return r;
        });
    }

    /** O contêiner do módulo: o do template, se existir; senão nasce logo depois de #teVagas. */
    function caixaCv() {
        var c = $('teCurriculos');
        if (!c) {
            c = el('div', { id: 'teCurriculos', hidden: true });
            var vg = $('teVagas');
            vg.parentNode.insertBefore(c, vg.nextSibling);
        }
        return c;
    }

    /* ---------------------------------------------------------------- utilidades de tela */

    function iniciaisDe(nome) {
        return String(nome || '?').split(' ').filter(Boolean).slice(0, 2).map(function (w) { return w[0]; }).join('').toUpperCase();
    }

    function fotoDe(x) {
        return (x && (x.fotoUrl || (x.arquivos && x.arquivos.foto && x.arquivos.foto.url))) || null;
    }

    /** Avatar (`avBg`, l.998): a foto cobre o círculo; sem foto, as iniciais no azul. */
    function avatarCv(x, extra) {
        var foto = fotoDe(x);
        var a = el('span', { class: 'te-cv-av' + (extra || '') + (foto ? ' is-foto' : ''), text: foto ? '' : (x.iniciais || iniciaisDe(x.nome)) });
        if (foto) {
            a.style.backgroundImage = 'url("' + String(foto).replace(/["\\\n\r]/g, function (c) { return encodeURIComponent(c); }) + '")';
        }
        return a;
    }

    /** O "@usuario" do Instagram abre o perfil (`abrirInsta`, l.999). */
    function botaoInsta(insta, extra) {
        if (vazio(insta)) {
            return null;
        }
        return el('button', {
            type: 'button', class: 'te-cv-insta' + (extra || ''),
            onclick: function (e) {
                e.stopPropagation();
                window.open('https://instagram.com/' + encodeURIComponent(insta), '_blank', 'noopener');
            }
        }, [ic('bi-instagram'), '@' + insta]);
    }

    function dadoCv(icone, texto) {
        return el('span', { class: 'te-cv-dado' }, [ic(icone), texto]);
    }

    /** Baixa pelo controller (attachment): o navegador não sai da página. */
    function baixar(url) {
        if (vazio(url)) {
            return;
        }
        var a = el('a', { href: url, attrs: { download: '' } });
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    function stCv(k) { return CST.filter(function (c) { return c[0] === k; })[0] || [k, k, '#6b8494']; }

    /* ---------------------------------------------------------------- banco interno: filtros */

    /**
     * Abas do banco (`cvFiltros`, l.1119), contando o que a lista mostraria em cada uma (decisão 6):
     * fora da Lixeira, nada do que está nela aparece; a Lixeira mostra só o que está nela.
     */
    function filtrosDeCv() {
        var st = function (s) { return function (x) { return x.status === s; }; };
        return [
            ['ativos', 'Ativos', function (x) { return x.status !== 'arquivado'; }],
            ['novo', 'Novos', st('novo')],
            ['favorito', 'Favoritos', st('favorito')],
            ['vol', 'Voluntários', function (x) { return !!x.voluntario && x.status !== 'arquivado'; }],
            ['arquivado', 'Arquivados', st('arquivado')],
            ['estrela', '★ Estrela', function (x) { return !!x.estrela; }],
            ['imp', 'Importantes', cvImportante],
            ['lix', 'Lixeira', null]
        ];
    }

    function doFiltroCv(f) {
        return curriculos().filter(function (x) { return f[2] ? !x.lixeira && f[2](x) : !!x.lixeira; });
    }

    function filtroDeCv() {
        var vis = filtrosDeCv();
        return vis.filter(function (f) { return f[0] === S.cvFiltro; })[0] || vis[0];
    }

    /** Selects e busca (l.995): nome, oabNum, oabUf, cidade, uf, cargo e áreas, como o servidor. A ordem é a do servidor (recebidoEm). */
    function cvFiltrados() {
        var q = S.cvBusca.trim().toLowerCase();
        return doFiltroCv(filtroDeCv()).filter(function (x) {
            // "oab" é o filtro do KPI COM OAB (§4.9, lacuna 1): advogado(a) e estagiário(a) com OAB.
            return (!S.cvSit || x.situacao === S.cvSit || (S.cvSit === CV_SIT_COM_OAB && (x.situacao === 'adv' || x.situacao === 'estOab')))
                && (!S.cvUf || x.uf === S.cvUf)
                && (!S.cvTer || (x.termo || 'sem') === S.cvTer)
                && (!S.cvArea || (x.areas || []).indexOf(S.cvArea) >= 0)
                && (!q || [x.nome, x.oabNum, x.oabUf, x.cidade, x.uf, x.cargo, (x.areas || []).join(' ')].join(' ').toLowerCase().indexOf(q) >= 0);
        });
    }

    /** `cvFiltroAtivo` (l.1149). */
    function cvFiltroAtivo() {
        return !!(S.cvSit || S.cvUf || S.cvTer || S.cvArea || S.cvBusca.trim() || S.cvFiltro !== 'ativos');
    }

    /** `cvLimpar` (l.1149). */
    function limparFiltrosCv() {
        S.cvSit = '';
        S.cvUf = '';
        S.cvTer = '';
        S.cvArea = '';
        S.cvBusca = '';
        S.cvFiltro = 'ativos';
        S.cvSel = [];
        var b = $('teCvBusca');
        if (b) {
            b.value = '';
        }
        renderCurriculos();
    }

    /** CSV da lista filtrada (l.1150): o servidor monta com os mesmos filtros, enviados na query. */
    function exportarCsv() {
        var aba = filtroDeCv()[0];
        var q = [['filtro', aba === 'vol' ? 'voluntarios' : aba], ['busca', S.cvBusca.trim()], ['area', S.cvArea], ['situacao', S.cvSit], ['uf', S.cvUf], ['termo', S.cvTer]]
            .filter(function (p) { return !vazio(p[1]); })
            .map(function (p) { return encodeURIComponent(p[0]) + '=' + encodeURIComponent(p[1]); }).join('&');
        baixar(ROTA.curriculosCsv(q));
        mostrarToast({ tit: 'Planilha exportada', txt: cvFiltrados().length + ' currículo(s) com os filtros atuais.', hora: '' });
    }

    /** "Selecionar por" do banco (`cvBulk.ops`, l.1051), sempre dentro da lista à vista. */
    var CV_SEL_OPS = [
        ['Todos', function () { return true; }],
        ['Nenhum', function () { return false; }],
        ['Vistos', function (x) { return !!x.vista; }],
        ['Não vistos', function (x) { return !x.vista; }],
        ['Com estrela', function (x) { return !!x.estrela; }],
        ['Importantes', cvImportante]
    ];

    function cvSelecionado(codigo) { return S.cvSel.indexOf(codigo) >= 0; }

    function alternarCvSel(codigo) {
        S.cvSel = cvSelecionado(codigo) ? S.cvSel.filter(function (c) { return c !== codigo; }) : S.cvSel.concat([codigo]);
        renderCurriculos();
    }

    /* ---------------------------------------------------------------- render do módulo */

    /**
     * Monta o esqueleto uma vez por modo (banco ou "Meu currículo"): a busca não pode ser recriada
     * a cada tecla, senão perde o foco.
     */
    function montarCv() {
        var c = caixaCv(), modo = cvInterno() ? 'banco' : 'meu';
        if (c.getAttribute('data-modo') === modo) {
            return;
        }
        limpar(c);
        c.setAttribute('data-modo', modo);
        if (modo === 'meu') {
            c.appendChild(el('div', { class: 'te-cv-ext', id: 'teCvMeu' }));
            return;
        }
        var busca = el('input', {
            type: 'text', id: 'teCvBusca', placeholder: 'Buscar nome, OAB, área ou cidade', autocomplete: 'off', value: S.cvBusca,
            oninput: function (e) { S.cvBusca = e.target.value; renderCurriculos(); }
        });
        c.appendChild(el('div', { class: 'te-cv-topo', id: 'teCvTopo' }));
        // Painel do Master (l.172 a 193), entre a linha de cima e a barra do banco.
        c.appendChild(el('div', { class: 'te-pn', id: 'teCvPainel', hidden: true }));
        c.appendChild(el('div', { class: 'te-cv-barra' }, [
            el('div', { class: 'te-vg-sel', id: 'teCvSel' }),
            el('label', { class: 'te-vg-busca' }, [ic('bi-search'), busca]),
            el('div', { class: 'te-vg-filtros', id: 'teCvFiltros' }),
            el('div', { class: 'te-cv-selects', id: 'teCvSelects' })
        ]));
        c.appendChild(el('div', { id: 'teCvLote' }));
        c.appendChild(el('div', { id: 'teCvLista' }));
    }

    function renderCurriculos() {
        if (!S.cv) {
            limpar(caixaCv()).removeAttribute('data-modo');
            return;
        }
        montarCv();
        if (!cvInterno()) {
            renderMeuCv();
            return;
        }
        var L = cvFiltrados();
        var vistos = L.map(function (x) { return x.codigo; });
        S.cvSel = S.cvSel.filter(function (c) { return vistos.indexOf(c) >= 0; });
        renderCvTopo(L);
        renderCvPainel();
        renderCvSel(L);
        renderCvFiltros();
        renderCvSelects();
        // Na vista Painel, a barra do banco fica (l.194) e a lista some (`cvLista`, l.195).
        var lista = vistaCv() === 'lista';
        $('teCvLote').hidden = !lista;
        $('teCvLista').hidden = !lista;
        if (lista) {
            renderCvLote();
            renderCvLista(L);
        } else {
            limpar($('teCvLote'));
            limpar($('teCvLista'));
        }
    }

    /**
     * Linha de cima (l.171): o seletor Painel/Lista do Master (§4.9) ou, para a Equipe, o aviso de que
     * as estatísticas são só do Master; "Limpar filtros" com algum filtro e o CSV.
     */
    function renderCvTopo(L) {
        var alvo = limpar($('teCvTopo'));
        if (temPainelCv()) {
            alvo.appendChild(renderVistasCv());
        } else if (!cvMaster()) {
            alvo.appendChild(el('span', { class: 'te-cv-cadeado' }, [ic('bi-lock'), 'Estatísticas disponíveis só para o Master']));
        }
        alvo.appendChild(el('span', { class: 'te-flex1' }));
        if (cvFiltroAtivo()) {
            alvo.appendChild(el('button', { type: 'button', class: 'te-cv-limpar', onclick: limparFiltrosCv }, [ic('bi-x-circle'), ' Limpar filtros']));
        }
        if (cvPodeGerir()) {
            alvo.appendChild(el('button', { type: 'button', class: 'te-cv-csv', onclick: exportarCsv }, [ic('bi-filetype-csv'), 'Exportar ' + L.length + ' currículo(s)']));
        }
    }

    /* =============================================================================
       PAINEL DO MASTER (spec VS 18.10.26 §4.9, fatia F3b-1)
       Desenho "06 - Trabalho Externo - VS 18.10.26": seletor Painel/Lista (l.171), KPIs (l.173),
       gráficos de barras (l.175), "Crescimento do banco de talentos" (l.175 a 193), `_contar`
       (l.887) e a lógica do `renderVals()` (l.1120 a 1146). Números, rótulos e cliques dos KPIs e
       das barras vêm prontos do servidor (GET painel, só Master); daqui saem a pintura, os cliques e
       as contas do gráfico de crescimento a partir das contagens por mês.
       ============================================================================= */

    var PN_AZUL = '#0a6fa0';
    var PN_MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    /** `CORES_A` (l.1126): uma cor por ano em "Todos os anos". */
    var PN_CORES_ANOS = ['#0a6fa0', '#c76a12', '#1e7b45', '#5b3f9e', '#a3235f', '#6b8494'];
    /** Séries do gráfico (l.1127): chave, rótulo, cor e o campo de `crescimento` da resposta. */
    var PN_SERIES = [['cv', 'Currículos recebidos', '#0a6fa0', 'cvs'], ['cand', 'Candidaturas', '#5b3f9e', 'cands'], ['vol', 'Voluntários', '#a3235f', 'vols']];
    var PN_SVG = 'http://www.w3.org/2000/svg';
    var pnPedido = 0;

    /** Só o Master tem o painel (`cvViewsTem`, l.1136); sem a URL no template, fica a lista. */
    function temPainelCv() { return cvMaster() && !!D.urlCurriculosPainel; }

    function chaveVistaCv() { return 'bj-te-cv-vista-' + euId(); }

    /** Vista do banco (`cvView`, l.1122): padrão Painel para o Master, lembrada por usuário; os demais só têm a Lista. */
    function vistaCv() {
        if (!temPainelCv()) {
            return 'lista';
        }
        if (S.cvVista !== 'painel' && S.cvVista !== 'lista') {
            S.cvVista = lerLocal(chaveVistaCv(), 'painel') === 'lista' ? 'lista' : 'painel';
        }
        return S.cvVista;
    }

    /** O seletor grava a escolha; o clique num KPI ou numa barra só troca a vista desta vez. */
    function trocarVistaCv(v, lembrar) {
        if (v === vistaCv()) {
            return;
        }
        S.cvVista = v;
        if (lembrar) {
            gravarLocal(chaveVistaCv(), v);
        }
        S.cvSel = [];
        renderCurriculos();
        if (v === 'painel') {
            carregarPainelCv();
        }
    }

    /** `cvViews` (l.171 e l.1136): Painel e Lista. */
    function renderVistasCv() {
        var at = vistaCv();
        return el('div', { class: 'te-pn-vistas', attrs: { role: 'group', 'aria-label': 'Vista dos currículos' } }, [
            ['painel', 'Painel', 'bi-bar-chart'],
            ['lista', 'Lista', 'bi-list-ul']
        ].map(function (x) {
            var on = at === x[0];
            return el('button', {
                type: 'button', class: 'te-pn-vista' + (on ? ' is-ativo' : ''),
                attrs: { 'aria-pressed': on ? 'true' : 'false' },
                onclick: function () { trocarVistaCv(x[0], true); }
            }, [ic(x[2]), x[1]]);
        }));
    }

    function listaPn(v) { return Array.isArray(v) ? v : []; }
    function mapaPn(v) { return v && typeof v === 'object' && !Array.isArray(v) ? v : {}; }
    /** A cor entra em gradiente e ganha transparência (`cor + '14'`, `cor + 'cc'`): só #rrggbb. */
    function corPn(c, padrao) { return /^#[0-9a-f]{6}$/i.test(String(c || '')) ? String(c) : (padrao || PN_AZUL); }
    function iconePn(n, padrao) { return /^bi-[a-z0-9-]+$/.test(String(n || '')) ? String(n) : padrao; }
    function numeroPn(v) { var n = +v; return isFinite(n) ? n : 0; }

    /**
     * GET do painel (só Master; 403 para os demais): ao abrir o Painel e ao recarregar os currículos.
     * Resposta pedida por outro perfil, ou superada por um pedido mais novo, é descartada.
     */
    function carregarPainelCv() {
        if (!temPainelCv()) {
            return Promise.resolve(null);
        }
        var como = S.como, n = ++pnPedido;
        return pedirCv('GET', comComo(D.urlCurriculosPainel)).then(function (r) {
            if (como !== S.como || n !== pnPedido) {
                return r;
            }
            if (!r.ok) {
                if (moduloAtual() === 'cv' && vistaCv() === 'painel') {
                    toastErro(r.mensagem);
                }
                return r;
            }
            var P = r.painel && typeof r.painel === 'object' && !Array.isArray(r.painel) ? r.painel : r;
            S.cvPn = {
                kpis: listaPn(P.kpis), barras: listaPn(P.barras),
                crescimento: mapaPn(P.crescimento), inscritos: numeroPn(P.inscritos)
            };
            renderCvPainel();
            return r;
        });
    }

    /**
     * Clique num KPI ou numa barra (`ir` do servidor): filtro nos currículos e a Lista; `mod: vagas`
     * com o filtro `vf`; `acao: campanha` abre a campanha por e-mail (l.1146, §4.10); `acao: alertasCsv`
     * (o clique de antes da campanha) ainda baixa a lista de alertas (l.1137).
     */
    function irDoPainel(ir) {
        if (!ir || typeof ir !== 'object') {
            return;
        }
        if (ir.acao === 'campanha') {
            abrirCampanha();
            return;
        }
        if (ir.acao === 'alertasCsv') {
            baixarAlertasCsv();
            return;
        }
        var m = moduloDaChave(ir.mod);
        if (m === 'vagas') {
            // `vf` é o do contrato; um `filtro` em texto (como nos chips do engajamento) também vale.
            irPara('vagas', ir.vf ? String(ir.vf) : typeof ir.filtro === 'string' && ir.filtro ? ir.filtro : null);
            return;
        }
        if (m !== 'cv') {
            return;
        }
        var f = mapaPn(ir.filtro);
        if (f.cf !== undefined) {
            // A aba "Voluntários" é `vol` aqui e `voluntarios` no servidor (CSV): aceita as duas.
            S.cvFiltro = String(f.cf || 'ativos') === 'voluntarios' ? 'vol' : String(f.cf || 'ativos');
        }
        if (f.cs !== undefined) {
            S.cvSit = situacaoDoPainel(f.cs);
        }
        if (f.ca !== undefined) {
            S.cvArea = String(f.ca || '');
        }
        if (f.cu !== undefined) {
            S.cvUf = String(f.cu || '');
        }
        if (f.busca !== undefined) {
            S.cvBusca = String(f.busca || '');
            var b = $('teCvBusca');
            if (b) {
                b.value = S.cvBusca;
            }
        }
        S.cvSel = [];
        S.cvVista = 'lista';
        if (moduloAtual() === 'cv') {
            renderCurriculos();
        } else {
            irModulo('cv');
        }
    }

    /** COM OAB filtra `adv` e `estOab` (lacuna 1): aceita "oab" ou a lista das duas. */
    function situacaoDoPainel(cs) {
        if (Array.isArray(cs)) {
            if (cs.indexOf('adv') >= 0 && cs.indexOf('estOab') >= 0) {
                return CV_SIT_COM_OAB;
            }
            return String(cs[0] || '');
        }
        return String(cs || '') === 'comOab' ? CV_SIT_COM_OAB : String(cs || '');
    }

    function nomeDoAnexo(disp) {
        var m = /filename\*=UTF-8''([^;]+)/i.exec(String(disp || '')) || /filename="?([^";]+)"?/i.exec(String(disp || ''));
        if (!m) {
            return '';
        }
        try {
            return decodeURIComponent(m[1]);
        } catch (e) {
            return m[1];
        }
    }

    /**
     * "ALERTAS POR E-MAIL" (l.1137): baixa o CSV do servidor. 404 com JSON = ninguém inscrito, com o
     * toast do desenho; com sucesso, "Lista exportada" com o número de linhas do CSV baixado
     * (linhas não vazias, menos o cabeçalho).
     */
    function baixarAlertasCsv() {
        var url = String(D.urlAlertasCsv || '');
        if (!url) {
            toastErro();
            return;
        }
        fetch(url, { method: 'GET', credentials: 'same-origin', headers: { 'Accept': 'text/csv, application/json', 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) {
            var tipo = (r.headers.get('Content-Type') || '').toLowerCase();
            if (!r.ok || tipo.indexOf('json') >= 0) {
                return r.json().then(function (j) { return j; }, function () { return null; }).then(function (j) {
                    if (r.status === 404 && j && typeof j === 'object') {
                        mostrarToast({ tit: 'Ainda sem inscritos', txt: 'Compartilhe vagas para começar a captar e-mails.', hora: '' });
                        return;
                    }
                    toastErro(j && j.mensagem);
                });
            }
            var nome = nomeDoAnexo(r.headers.get('Content-Disposition')) || 'alertas-vagas-bluejus.csv';
            return r.blob().then(function (blob) {
                return blob.text().then(function (t) { return [blob, t]; }, function () { return [blob, '']; });
            }).then(function (par) {
                var blob = par[0];
                var linhas = String(par[1]).split(/\r?\n/).filter(function (l) { return l.trim() !== ''; }).length;
                var u = URL.createObjectURL(blob);
                var a = el('a', { href: u, attrs: { download: nome } });
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(function () { URL.revokeObjectURL(u); }, 1000);
                mostrarToast({ tit: 'Lista exportada', txt: Math.max(0, linhas - 1) + ' e-mail(s) inscrito(s).', hora: '' });
            });
        }).then(null, function () {
            toastErro();
        });
    }

    /**
     * `_contar()` (l.887): conta de 0 até o número em 900 ms (easeOutCubic), só quando o número do
     * elemento muda. Um pedido novo no mesmo elemento encerra o anterior.
     */
    function contarPn(raizPn) {
        Array.prototype.forEach.call(raizPn.querySelectorAll('[data-cnt]'), function (n) {
            var fim = +n.getAttribute('data-cnt');
            if (isNaN(fim) || n._cntFim === fim) {
                return;
            }
            n._cntFim = fim;
            var vez = (n._cntVez || 0) + 1, ini = performance.now(), dur = 900;
            n._cntVez = vez;
            var passo = function (t) {
                if (n._cntVez !== vez) {
                    return;
                }
                var k = Math.max(0, Math.min(1, (t - ini) / dur)), e = 1 - Math.pow(1 - k, 3);
                n.textContent = String(Math.round(fim * e));
                if (k < 1) {
                    requestAnimationFrame(passo);
                }
            };
            requestAnimationFrame(passo);
        });
    }

    /**
     * O painel (`cvPainel`, l.172): monta ao entrar na vista Painel (as animações de entrada rodam
     * como no desenho) e, com a vista aberta, atualiza no lugar quando os dados mudam.
     */
    function renderCvPainel() {
        var caixa = $('teCvPainel');
        if (!caixa) {
            return;
        }
        var on = vistaCv() === 'painel';
        caixa.hidden = !on;
        if (!on || !S.cvPn) {
            limpar(caixa);
            return;
        }
        if (!caixa.firstChild) {
            caixa.appendChild(el('div', { class: 'te-pn-kpis', id: 'teCvPnKpis' }));
            caixa.appendChild(el('div', { class: 'te-pn-grafs', id: 'teCvPnGrafs' }, [el('div', { class: 'te-pn-gr', id: 'teCvPnGr' })]));
        }
        renderPnKpis();
        renderPnBarras();
        renderPnCrescimento();
        contarPn(caixa);
    }

    /** KPIs (`cvKpis`, l.173 e l.1137): rótulo, ícone, cor, número animado, subtítulo e o clique. */
    function renderPnKpis() {
        var alvo = $('teCvPnKpis'), K = S.cvPn.kpis;
        var chaves = K.map(function (k, i) { return String(k && k.k !== undefined ? k.k : i); }).join('|');
        if (alvo.getAttribute('data-chaves') !== chaves) {
            limpar(alvo);
            alvo.setAttribute('data-chaves', chaves);
            K.forEach(function (k, i) {
                var b = el('button', {
                    type: 'button', class: 'te-pn-kpi', style: { 'animation-delay': (i * 70) + 'ms' },
                    onclick: function () { irDoPainel(b._ir); }
                }, [
                    el('span', { class: 'te-pn-kpi-faixa' }),
                    el('span', { class: 'te-pn-kpi-rot' }, [el('span', { class: 'te-pn-kpi-ic' }, el('i', { class: 'bi' })), el('span', { class: 'te-pn-kpi-rot-txt' })]),
                    el('b', { class: 'te-pn-kpi-n', text: '0' }),
                    el('span', { class: 'te-pn-kpi-sub' })
                ]);
                alvo.appendChild(b);
            });
        }
        K.forEach(function (k, i) {
            var b = alvo.children[i];
            if (!b || !k) {
                return;
            }
            var cor = corPn(k.cor);
            b._ir = k.ir || null;
            b.style.setProperty('--te-pn-cor', cor);
            b.style.setProperty('--te-pn-cor-bg', cor + '14');
            b.querySelector('.te-pn-kpi-ic > i').className = 'bi ' + iconePn(k.ic, 'bi-bar-chart');
            b.querySelector('.te-pn-kpi-rot-txt').textContent = String(k.rot || '');
            b.querySelector('.te-pn-kpi-n').setAttribute('data-cnt', String(Math.round(numeroPn(k.n))));
            b.querySelector('.te-pn-kpi-sub').textContent = String(k.sub || '');
        });
    }

    /**
     * Gráficos de barras (`cvGraf`, l.175 e l.1124): top 8, barra relativa ao maior da caixa,
     * "Sem dados ainda." quando tudo é zero (as linhas zeradas continuam, como no desenho).
     * Mesma estrutura (títulos e rótulos): atualiza no lugar e a largura anda pela transição.
     */
    function renderPnBarras() {
        var grade = $('teCvPnGrafs'), gr = $('teCvPnGr');
        var B = S.cvPn.barras.filter(function (g) { return g && typeof g === 'object'; }).map(function (g) {
            return { g: g, itens: listaPn(g.itens).filter(function (i) { return i && typeof i === 'object'; }).slice(0, 8) };
        });
        var estrutura = JSON.stringify(B.map(function (x) { return [x.g.tit, x.g.ic, x.itens.map(function (i) { return i.rot; })]; }));
        var montar = grade.getAttribute('data-estrutura') !== estrutura;
        if (montar) {
            Array.prototype.slice.call(grade.children).forEach(function (c) {
                if (c !== gr) {
                    c.remove();
                }
            });
            grade.setAttribute('data-estrutura', estrutura);
        }
        B.forEach(function (x, gi) {
            var g = x.g, itens = x.itens;
            var mx = Math.max.apply(null, [1].concat(itens.map(function (i) { return numeroPn(i.n); })));
            var semDados = !itens.length || itens.every(function (i) { return !numeroPn(i.n); });
            var card;
            if (montar) {
                card = el('div', { class: 'te-pn-graf', style: { 'animation-delay': (380 + gi * 80) + 'ms' } }, [
                    el('div', { class: 'te-pn-graf-cab' }, [
                        ic(iconePn(g.ic, 'bi-bar-chart')),
                        el('b', { class: 'te-pn-graf-tit', text: String(g.tit || '') }),
                        el('span', { class: 'te-pn-graf-dica', text: 'clique para filtrar' })
                    ]),
                    el('div', { class: 'te-pn-graf-vazio', text: 'Sem dados ainda.' })
                ].concat(itens.map(function (it, ii) {
                    var b = el('button', {
                        type: 'button', class: 'te-pn-bar',
                        onclick: function () { irDoPainel(b._ir); }
                    }, [
                        el('span', { class: 'te-pn-bar-rot', text: String(it.rot || ''), title: String(it.rot || '') }),
                        el('span', { class: 'te-pn-bar-trilho' }, el('span', { class: 'te-pn-bar-fill', style: { 'animation-delay': (520 + gi * 80 + ii * 60) + 'ms' } })),
                        el('b', { class: 'te-pn-bar-n' })
                    ]);
                    return b;
                })));
                grade.insertBefore(card, gr);
            } else {
                card = grade.children[gi];
            }
            if (!card) {
                return;
            }
            card.querySelector('.te-pn-graf-vazio').hidden = !semDados;
            var botoes = card.querySelectorAll('.te-pn-bar');
            itens.forEach(function (it, ii) {
                var b = botoes[ii];
                if (!b) {
                    return;
                }
                var n = numeroPn(it.n), cor = corPn(it.cor, corPn(g.cor));
                b._ir = it.ir || null;
                b.querySelector('.te-pn-bar-n').textContent = String(n);
                var fill = b.querySelector('.te-pn-bar-fill');
                fill.style.setProperty('--te-pn-cor', cor);
                fill.style.setProperty('--te-pn-cor-cc', cor + 'cc');
                fill.style.width = Math.round(n / mx * 100) + '%';
            });
        });
    }

    function contaPn(mapa, prefixo) {
        return Object.keys(mapa).reduce(function (a, k) { return String(k).indexOf(prefixo) === 0 ? a + numeroPn(mapa[k]) : a; }, 0);
    }

    function mesPn(ano, i) { return ano + '-' + String(i + 1).padStart(2, '0'); }

    /**
     * `curva` (l.1129): Catmull-Rom em Bézier cúbica, tensão .18. O Y dos pontos de controle fica
     * limitado a [yMin, yMax] (Y1 e Y0 do gráfico): com picos isolados a curva não passa abaixo do
     * zero nem acima do topo. Os pontos dos dados não mudam.
     */
    function curvaPn(P, yMin, yMax) {
        var lim = function (y) { return Math.max(yMin, Math.min(yMax, y)); };
        if (P.length < 2) {
            return P.length ? 'M' + P[0].join(',') : '';
        }
        return P.reduce(function (d, pt, i) {
            if (!i) {
                return 'M' + pt[0] + ',' + pt[1];
            }
            var a = P[i - 2] || P[i - 1], b = P[i - 1], c2 = pt, d2 = P[i + 1] || pt, t = .18;
            return d + ' C' + (b[0] + (c2[0] - a[0]) * t).toFixed(1) + ',' + lim(b[1] + (c2[1] - a[1]) * t).toFixed(1)
                + ' ' + (c2[0] - (d2[0] - b[0]) * t).toFixed(1) + ',' + lim(c2[1] - (d2[1] - b[1]) * t).toFixed(1)
                + ' ' + c2[0].toFixed(1) + ',' + c2[1].toFixed(1);
        }, '');
    }

    /**
     * As contas do "Crescimento do banco de talentos" (l.1123 a 1135), a partir das contagens por
     * AAAA-MM. Correções da §4.9 (lacuna 6): em "Todos os anos" a variação compara o ano atual com o
     * anterior; no Mensal do ano corrente, com o mesmo período do ano anterior; sem série ligada,
     * o resumo some.
     */
    function calcularCrescimento(C) {
        var cvs = mapaPn(C.cvs), mapas = { cvs: cvs, cands: mapaPn(C.cands), vols: mapaPn(C.vols) };
        var hoje = hojeEmBrasilia(), anoAt = +hoje.slice(0, 4), mesAt = +hoje.slice(5, 7);
        var anosDe = function (m) { return Object.keys(m).map(function (k) { return +String(k).slice(0, 4); }); };
        var anos = anosDe(cvs).concat(anosDe(mapas.cands)).concat([anoAt])
            .filter(function (a, i, A) { return a > 0 && A.indexOf(a) === i; })
            .sort(function (a, b) { return a - b; });
        var gm = S.grModo === 'ano' ? 'ano' : 'mes';
        var todos = gm === 'mes' && S.grAno === 'todos';
        var ga = todos ? anoAt : (+S.grAno || anoAt);
        if (anos.indexOf(ga) < 0) {
            ga = anoAt;
        }
        var on = Object.assign({ cv: true, cand: true, vol: true }, S.grOn || {});
        var buckets;
        if (gm === 'mes') {
            buckets = PN_MESES.map(function (r, i) { return { rot: r, k: mesPn(ga, i) }; });
        } else {
            var a0 = Math.min.apply(null, anos), a1 = Math.max.apply(null, anos.concat([a0 + 1]));
            buckets = [];
            for (var a = a0; a <= a1; a++) {
                buckets.push({ rot: String(a), k: String(a) });
            }
        }
        var SER = todos
            ? anos.slice().reverse().map(function (a, i) {
                return { k: 'a' + a, r: String(a), cor: PN_CORES_ANOS[i % PN_CORES_ANOS.length], vals: PN_MESES.map(function (_, m) { return contaPn(cvs, mesPn(a, m)); }) };
            })
            : PN_SERIES.map(function (x) {
                return { k: x[0], r: x[1], cor: x[2], vals: buckets.map(function (b) { return contaPn(mapas[x[3]], b.k); }) };
            });
        var ativos = SER.filter(function (x) { return on[x.k] !== false; });
        var mx = Math.max.apply(null, [4].concat([].concat.apply([], ativos.map(function (x) { return x.vals; }))));
        var stepY = Math.ceil(mx / 4), topo = stepY * 4, X0 = 60, X1 = 975, Y0 = 210, Y1 = 14, n = buckets.length;
        var xs = function (i) { return n === 1 ? (X0 + X1) / 2 : X0 + (X1 - X0) * i / (n - 1); };
        var ys = function (v) { return Y0 - (Y0 - Y1) * v / topo; };
        var linhas = ativos.map(function (x, li) {
            var P = x.vals.map(function (v, i) { return [xs(i), ys(v)]; }), d = curvaPn(P, Y1, Y0);
            return {
                k: x.k, delay: (li * 150) + 'ms', gid: 'teGrG' + x.k, cor: x.cor, d: d,
                area: d ? d + ' L' + xs(n - 1) + ',' + Y0 + ' L' + xs(0) + ',' + Y0 + ' Z' : '',
                pts: P.map(function (pt, i) {
                    return {
                        delay: (700 + li * 150 + i * 55) + 'ms', x: pt[0].toFixed(1), y: pt[1].toFixed(1),
                        tit: (todos ? 'Currículos ' : x.r + ' · ') + buckets[i].rot + (todos ? '/' + x.r : gm === 'mes' ? '/' + ga : '') + ': ' + x.vals[i]
                    };
                })
            };
        });
        var totS = function (k) { var x = SER.filter(function (y) { return y.k === k; })[0]; return x ? x.vals.reduce(function (s, v) { return s + v; }, 0) : 0; };
        var cvV = todos ? PN_MESES.map(function (_, m) { return SER.reduce(function (s, x) { return s + x.vals[m]; }, 0); }) : SER[0].vals;
        var ult = cvV.filter(function (v) { return v; }).length;
        var melhor = cvV.reduce(function (m3, v, i) { return v > m3.v ? { v: v, i: i } : m3; }, { v: 0, i: -1 });
        var atual = todos ? contaPn(cvs, '') : totS('cv');
        // Variação (lacuna 6): o ano de comparação e os dois totais comparados.
        var cmp = 0, ant = 0, anoBase = ga - 1;
        // "Todos os anos" (ga = ano atual) e o Mensal do ano corrente: jan até o mês atual, contra o mesmo período do ano anterior.
        if (todos || (gm === 'mes' && ga === anoAt)) {
            for (var m = 0; m < mesAt; m++) {
                cmp += contaPn(cvs, mesPn(ga, m));
                ant += contaPn(cvs, mesPn(ga - 1, m));
            }
        } else if (gm === 'mes') {
            cmp = atual;
            ant = contaPn(cvs, String(ga - 1));
        }
        var varTxt = gm === 'mes' && ant ? (cmp >= ant ? '+' : '') + Math.round((cmp - ant) / ant * 100) + '% em relação a ' + anoBase : '';
        var resumo = !ativos.length ? [] : [
            { v: atual, t: 'currículo(s) ' + (todos ? 'no total' : gm === 'mes' ? 'em ' + ga : 'no total') },
            { v: todos ? anos.length : totS('cand'), t: todos ? 'ano(s) comparados' : 'candidatura(s)' },
            { v: melhor.i >= 0 ? buckets[melhor.i].rot : '·', t: 'melhor ' + (gm === 'mes' ? 'mês' : 'ano') + (melhor.i >= 0 ? ' (' + melhor.v + ')' : '') }
        ].concat(varTxt ? [{ v: varTxt.split(' ')[0], t: varTxt.split(' ').slice(1).join(' ') }] : [])
            .concat([{ v: ult ? (atual / ult).toFixed(1).replace('.', ',') : '0', t: 'por ' + (gm === 'mes' ? 'mês' : 'ano') + ' com recebimento' }]);
        return {
            gm: gm, todos: todos, ga: ga, anos: anos, on: on, SER: SER, totS: totS, linhas: linhas, buckets: buckets,
            sub: todos ? 'currículos mês a mês, comparando os anos' : gm === 'mes' ? 'mês a mês em ' + ga : 'ano a ano',
            grade: [0, 1, 2, 3, 4].map(function (i) { return { y: ys(stepY * i).toFixed(1), top: (ys(stepY * i) / 240 * 100).toFixed(2) + '%', v: stepY * i }; }),
            eixoX: buckets.map(function (b, i) { return { left: (xs(i) / 10).toFixed(2) + '%', top: (222 / 240 * 100).toFixed(2) + '%', rot: b.rot }; }),
            vazio: !ativos.some(function (x) { return x.vals.some(function (v) { return v; }); }),
            resumo: resumo
        };
    }

    function svgPn(tag, attrs, filhos) {
        var n = document.createElementNS(PN_SVG, tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'style') {
                Object.keys(attrs.style).forEach(function (p) { n.style.setProperty(p, attrs.style[p]); });
            } else if (attrs[k] !== undefined && attrs[k] !== null) {
                n.setAttribute(k, String(attrs[k]));
            }
        });
        (filhos || []).forEach(function (f) { if (f) { n.appendChild(f); } });
        return n;
    }

    /**
     * "Crescimento do banco de talentos" (l.175 a 193): séries com total e toggle, Mensal/Anual,
     * o ano ou "Todos os anos" no Mensal, eixos, curvas, pontos com dica, vazio e resumo. Redesenha
     * só quando os dados ou a escolha mudam (o SVG novo refaz as animações, como o `key` do desenho).
     */
    function renderPnCrescimento() {
        var gr = $('teCvPnGr'), C = S.cvPn.crescimento;
        var sig = JSON.stringify([C, S.grModo, S.grAno, S.grOn, hojeEmBrasilia().slice(0, 7)]);
        if (gr.getAttribute('data-sig') === sig) {
            return;
        }
        gr.setAttribute('data-sig', sig);
        limpar(gr);
        var M = calcularCrescimento(C);
        var series = el('div', { class: 'te-pn-gr-series' }, M.SER.map(function (x) {
            var ok = M.on[x.k] !== false;
            return el('button', {
                type: 'button', class: 'te-pn-gr-serie' + (ok ? '' : ' is-off'), style: { '--te-pn-cor': x.cor },
                attrs: { 'aria-pressed': ok ? 'true' : 'false' },
                onclick: function () {
                    var o = {};
                    o[x.k] = !ok;
                    S.grOn = Object.assign({}, M.on, o);
                    renderPnCrescimento();
                }
            }, [el('span', { class: 'te-pn-gr-cor' }), x.r + ' ', el('span', { class: 'te-pn-gr-tot', text: String(M.totS(x.k)) })]);
        }));
        var modos = el('div', { class: 'te-pn-gr-modos' }, [['mes', 'Mensal'], ['ano', 'Anual']].map(function (x) {
            var on = M.gm === x[0];
            return el('button', {
                type: 'button', class: 'te-pn-gr-modo' + (on ? ' is-ativo' : ''), text: x[1],
                attrs: { 'aria-pressed': on ? 'true' : 'false' },
                onclick: function () { S.grModo = x[0]; renderPnCrescimento(); }
            });
        }));
        var anoSel = M.gm === 'mes' ? el('select', {
            class: 'te-pn-gr-ano', value: M.todos ? 'todos' : String(M.ga), attrs: { 'aria-label': 'Ano do gráfico' },
            onchange: function (e) { S.grAno = e.target.value; renderPnCrescimento(); }
        }, [el('option', { value: 'todos', text: 'Todos os anos' })].concat(M.anos.slice().reverse().map(function (a) {
            return el('option', { value: String(a), text: String(a) });
        }))) : null;
        gr.appendChild(el('div', { class: 'te-pn-gr-cab' }, [
            ic('bi-graph-up-arrow'),
            el('b', { class: 'te-pn-gr-tit', text: 'Crescimento do banco de talentos' }),
            el('span', { class: 'te-pn-gr-sub', text: M.sub }),
            el('span', { class: 'te-flex1' }),
            series, modos, anoSel
        ]));

        var defs = svgPn('defs', null, M.linhas.map(function (l) {
            return svgPn('linearGradient', { id: l.gid, x1: '0', y1: '0', x2: '0', y2: '1' }, [
                svgPn('stop', { offset: '0', 'stop-color': l.cor, 'stop-opacity': '.22' }),
                svgPn('stop', { offset: '1', 'stop-color': l.cor, 'stop-opacity': '0' })
            ]);
        }));
        var filhosSvg = [defs].concat(M.grade.map(function (y) {
            return svgPn('line', { class: 'te-pn-gr-grade', x1: '46', x2: '990', y1: y.y, y2: y.y, stroke: '#eef2f5', 'stroke-width': '1' });
        }));
        M.linhas.forEach(function (l) {
            filhosSvg.push(svgPn('path', { class: 'te-pn-gr-area-p', d: l.area, fill: 'url(#' + l.gid + ')' }));
            filhosSvg.push(svgPn('path', {
                class: 'te-pn-gr-linha', d: l.d, pathLength: '1', fill: 'none', stroke: l.cor, 'stroke-width': '2.5',
                'stroke-linecap': 'round', 'vector-effect': 'non-scaling-stroke', style: { 'animation-delay': l.delay }
            }));
            l.pts.forEach(function (p) {
                filhosSvg.push(svgPn('circle', {
                    class: 'te-pn-gr-pto', cx: p.x, cy: p.y, r: '4', fill: '#fff', stroke: l.cor, 'stroke-width': '2',
                    'vector-effect': 'non-scaling-stroke', style: { 'animation-delay': p.delay }
                }));
            });
        });
        var svg = svgPn('svg', { class: 'teGc te-pn-gr-svg', viewBox: '0 0 1000 240', preserveAspectRatio: 'none', 'aria-hidden': 'true' }, filhosSvg);
        var area = el('div', { class: 'te-pn-gr-area' }, [svg]);
        M.grade.forEach(function (y) {
            area.appendChild(el('span', { class: 'te-pn-gr-y', style: { top: y.top }, text: String(y.v) }));
        });
        M.eixoX.forEach(function (x) {
            area.appendChild(el('span', { class: 'te-pn-gr-x', style: { left: x.left, top: x.top }, text: x.rot }));
        });
        M.linhas.forEach(function (l) {
            l.pts.forEach(function (p) {
                area.appendChild(el('span', {
                    class: 'te-pn-gr-pt', title: p.tit, attrs: { 'data-tip': p.tit },
                    style: { left: (p.x / 10).toFixed(2) + '%', top: (p.y / 240 * 100).toFixed(2) + '%' }
                }));
            });
        });
        if (M.vazio) {
            area.appendChild(el('div', { class: 'te-pn-gr-vazio', text: 'Sem registros neste período. A curva cresce conforme os currículos chegam.' }));
        }
        gr.appendChild(area);
        if (M.resumo.length) {
            gr.appendChild(el('div', { class: 'te-pn-gr-resumo' }, M.resumo.map(function (r) {
                return el('span', null, [el('b', { text: String(r.v) }), ' ' + r.t]);
            })));
        }
    }

    /** Selecionar, "Selecionar por" e Atualizar (l.195), como na aba Vagas. */
    function renderCvSel(L) {
        var alvo = limpar($('teCvSel'));
        if (podeEscreverCv()) {
            var todas = L.length > 0 && S.cvSel.length === L.length;
            alvo.appendChild(el('button', {
                type: 'button', class: 'te-sel-btn' + (S.cvSel.length ? ' is-on' : ''), title: 'Selecionar',
                attrs: { 'aria-label': todas ? 'Limpar seleção' : 'Selecionar todos' },
                onclick: function () {
                    S.cvSel = todas ? [] : L.map(function (x) { return x.codigo; });
                    renderCurriculos();
                }
            }, ic(todas ? 'bi-check-square-fill' : S.cvSel.length ? 'bi-dash-square-fill' : 'bi-square')));
            alvo.appendChild(el('button', {
                type: 'button', class: 'te-sel-caret', title: 'Selecionar por', attrs: { 'aria-haspopup': 'menu' },
                onclick: function (e) {
                    var r = e.currentTarget.getBoundingClientRect();
                    abrirMenuEspecial({ tipo: 'cvselpor' }, r.left - 30, r.bottom + 4, 170);
                }
            }, ic('bi-caret-down-fill')));
        }
        alvo.appendChild(el('button', {
            type: 'button', class: 'te-sel-atualizar', title: 'Atualizar',
            onclick: function () { carregarCurriculos(true); }
        }, ic('bi-arrow-clockwise')));
    }

    function renderCvFiltros() {
        var alvo = limpar($('teCvFiltros')), atual = filtroDeCv();
        filtrosDeCv().forEach(function (f) {
            var on = f[0] === atual[0];
            alvo.appendChild(el('button', {
                type: 'button', class: 'te-vg-filtro' + (on ? ' is-ativo' : ''),
                attrs: { 'aria-pressed': on ? 'true' : 'false' },
                onclick: function () { S.cvFiltro = f[0]; S.cvSel = []; renderCurriculos(); }
            }, [f[1] + ' ', el('span', { class: 'te-vg-filtro-n', text: doFiltroCv(f).length })]));
        });
    }

    /** Selects da barra (l.195): áreas, "Situação OAB", UFs e Termo. */
    function renderCvSelects() {
        var alvo = limpar($('teCvSelects')), Lt = listasCv();
        var sel = function (k, primeiro, ops, extra) {
            return el('select', {
                class: 'te-cv-select' + (extra || ''), value: S[k] || '',
                attrs: { 'aria-label': primeiro },
                onchange: function (e) { S[k] = e.target.value; S.cvSel = []; renderCurriculos(); }
            }, [el('option', { value: '', text: primeiro })].concat(ops.map(function (o) { return el('option', { value: o[0], text: o[1] }); })));
        };
        alvo.appendChild(sel('cvArea', 'Todas as áreas', (Lt.areas || []).map(function (a) { return [a, a]; }), ' is-area'));
        // A opção "Com OAB" só existe enquanto o filtro veio do KPI (o select do desenho não a tem).
        alvo.appendChild(sel('cvSit', 'Situação OAB', S.cvSit === CV_SIT_COM_OAB ? [[CV_SIT_COM_OAB, 'Com OAB']].concat(CV_SIT_FILTRO) : CV_SIT_FILTRO));
        alvo.appendChild(sel('cvUf', 'Todas as UFs', (Lt.ufs || []).map(function (u) { return [u, u]; })));
        if (S.cv && S.cv.termoLigado) {
            alvo.appendChild(sel('cvTer', 'Termo', CV_TERMO_FILTRO));
        }
    }

    /** Barra de lote (`cvBulk`, l.1052), igual à das vagas; e o aviso da lixeira. */
    function renderCvLote() {
        var alvo = limpar($('teCvLote'));
        var naLixeira = filtroDeCv()[0] === 'lix';
        if (S.cvSel.length && podeEscreverCv()) {
            var sel = curriculos().filter(function (x) { return cvSelecionado(x.codigo); });
            var algumNaoVisto = sel.some(function (x) { return !x.vista; });
            var todosEstrela = sel.length > 0 && sel.every(function (x) { return !!x.estrela; });
            var acoes = naLixeira
                ? [
                    ['restaurar', 'Restaurar', 'bi-arrow-counterclockwise', null],
                    ['definitivo', 'Excluir da minha lista', 'bi-x-octagon', '#a3232b']
                ]
                : [
                    ['lixeira', 'Mover para a lixeira', 'bi-trash', null],
                    algumNaoVisto ? ['lida', 'Marcar como visto', 'bi-eye', null] : ['naolida', 'Marcar como não visto', 'bi-eye-slash', null],
                    todosEstrela ? ['semestrela', 'Remover estrela', 'bi-star-fill', '#e0a106'] : ['estrela', 'Adicionar estrela', 'bi-star', null],
                    ['imp', 'Marcar como importante', 'bi-chevron-double-right', '#0a6fa0'],
                    ['naoimp', 'Marcar como não importante', 'bi-chevron-right', null]
                ];
            var codigos = S.cvSel.slice();
            alvo.appendChild(el('div', { class: 'te-lote', attrs: { role: 'toolbar', 'aria-label': 'Ações nos currículos selecionados' } }, [
                el('b', { class: 'te-lote-n', text: S.cvSel.length + ' selecionado(s)' })
            ].concat(acoes.map(function (a) {
                return el('button', {
                    type: 'button', class: 'te-lote-btn', title: a[1],
                    style: a[3] ? { color: a[3] } : null,
                    attrs: { 'data-tip': a[1], 'aria-label': a[1] },
                    onclick: function () { loteCv(codigos, a[0]); }
                }, ic(a[2]));
            })).concat([
                el('span', { class: 'te-espaco' }),
                el('button', { type: 'button', class: 'te-lote-cancelar', text: 'Cancelar', onclick: function () { S.cvSel = []; renderCurriculos(); } })
            ])));
        }
        if (naLixeira) {
            alvo.appendChild(el('div', { class: 'te-lix-aviso' }, [ic('bi-info-circle'), ' A lixeira é só sua: os itens continuam para os demais. Restaure quando quiser.']));
        }
    }

    /** Colunas do desenho (l.200): 86px | 2fr | 130px | 1.6fr | 150px | 110px | 120px | 40px. */
    function renderCvLista(L) {
        var alvo = limpar($('teCvLista'));
        if (!curriculos().length) {
            alvo.appendChild(el('div', { class: 'te-vg-vazio' }, [
                ic('bi-person-vcard'),
                el('div', { class: 'te-vg-vazio-tit', text: 'Nenhum currículo recebido' }),
                el('div', { class: 'te-vg-vazio-txt', text: 'Os currículos enviados por advogados e escritórios que usam o BlueJus aparecem aqui, com OAB, área de atuação e local.' })
            ]));
            return;
        }
        if (!L.length) {
            // Decisão 7: com filtro e nada à vista, o texto curto e o atalho para limpar.
            alvo.appendChild(el('div', { class: 'te-vg-vazio' }, [
                ic('bi-person-vcard'),
                el('div', { class: 'te-vg-vazio-tit', text: 'Nenhum currículo neste filtro.' }),
                el('button', { type: 'button', class: 'te-cv-limpar is-vazio', onclick: limparFiltrosCv }, [ic('bi-x-circle'), ' Limpar filtros'])
            ]));
            return;
        }
        var cab = el('div', { class: 'te-cv-grade te-cv-cab' }, [
            el('span'), el('span', { class: 'is-prof', text: 'PROFISSIONAL' }), el('span', { text: 'OAB' }), el('span', { text: 'ÁREAS' }),
            el('span', { text: 'LOCAL' }), el('span', { text: 'RECEBIDO' }), el('span', { text: 'SITUAÇÃO' }), el('span')
        ]);
        alvo.appendChild(el('div', { class: 'te-cv-tabela' }, el('div', { class: 'te-cv-tabela-in' }, [cab].concat(L.map(linhaCv)))));
    }

    /** Linha do banco (l.202 a 209): o clique abre o detalhe e marca visto; o botão direito e o ⋮ abrem o menu. */
    function linhaCv(x) {
        var sel = cvSelecionado(x.codigo), pv = !podeEscreverCv();
        var nv = nivelDoCv(x), I = IMPORTANCIA[nv], dica = dicaDeImportancia(nv, x.importancia);
        var parar = function (fn) { return function (e) { e.stopPropagation(); if (fn) { fn(); } }; };
        var menu = function (cx, cy) { abrirMenuEspecial({ tipo: 'cv', codigo: x.codigo }, cx, cy, 230); };
        return el('div', {
            class: 'te-cv-grade te-cv-linha' + (sel ? ' is-sel' : ''),
            attrs: { 'data-codigo': x.codigo },
            onclick: function () { marcarCvVisto(x); abrirCv(x.codigo); },
            oncontextmenu: function (e) { e.preventDefault(); menu(e.clientX, e.clientY); }
        }, [
            el('span', { class: 'te-cv-marca', onclick: parar(null) }, [
                pv ? null : el('button', {
                    type: 'button', class: 'te-sel-btn' + (sel ? ' is-on' : ''), title: 'Selecionar',
                    attrs: { 'aria-pressed': sel ? 'true' : 'false' },
                    onclick: parar(function () { alternarCvSel(x.codigo); })
                }, ic(sel ? 'bi-check-square-fill' : 'bi-square')),
                el('button', {
                    type: 'button', class: 'te-estrela' + (x.estrela ? ' is-on' : ''), title: 'Estrela', disabled: pv,
                    attrs: { 'aria-pressed': x.estrela ? 'true' : 'false' },
                    onclick: parar(function () { loteCv([x.codigo], x.estrela ? 'semestrela' : 'estrela'); })
                }, ic(x.estrela ? 'bi-star-fill' : 'bi-star')),
                el('button', {
                    type: 'button', class: 'te-imp te-vg-imp', title: dica, disabled: pv,
                    style: { background: I.bg, color: I.cor },
                    attrs: { 'data-tip': dica, 'aria-label': dica },
                    onclick: parar(function () { loteCv([x.codigo], nv >= 2 ? 'naoimp' : 'imp'); })
                }, ic(I.ic))
            ]),
            el('span', { class: 'te-cv-prof' }, [
                avatarCv(x),
                el('span', { class: 'te-min0' }, [
                    el('b', { class: 'te-cv-nome' + (x.vista ? '' : ' is-naovisto'), text: x.nome || '' }),
                    el('span', { class: 'te-cv-cargo', text: x.cargo || 'Cargo não informado' }),
                    x.voluntario ? el('span', { class: 'te-cv-vol' }, [ic('bi-heart-fill'), 'Voluntário']) : null
                ])
            ]),
            el('span', { class: 'te-cv-cel', text: x.oab || '' }),
            el('span', { class: 'te-cv-cel te-reticencias', text: (x.areas || []).join(', ') || 'Áreas não informadas' }),
            el('span', { class: 'te-cv-cel te-reticencias', text: x.local || '' }),
            el('span', { class: 'te-cv-cel is-fraco', text: x.recebido || '' }),
            el('span', { class: 'te-cv-cel' }, seloSituacao(x.statusRotulo || stCv(x.status)[1], x.statusCor || stCv(x.status)[2])),
            el('button', {
                type: 'button', class: 'te-cv-mais', title: 'Mais opções',
                onclick: function (e) { e.stopPropagation(); if (e.clientX || e.clientY) { menu(e.clientX, e.clientY); return; } var r = e.currentTarget.getBoundingClientRect(); menu(r.left - 200, r.bottom + 4); }
            }, ic('bi-three-dots-vertical'))
        ]);
    }

    /* ---------------------------------------------------------------- menu, marcações, status e exclusão */

    /**
     * Menu da linha (`cvMenu`, l.1000): Abrir currículo, Ver currículo formatado / PDF, "Marcar como …"
     * para as situações diferentes da atual (com `talentos`) e Excluir currículo (só o Master).
     * "Gerar termo de voluntariado" (l.1093): quem gere o banco, com voluntariado diferente de "não".
     */
    function menuDoCv(caixa, codigo) {
        var x = cvDe(codigo);
        if (!x) {
            return;
        }
        var item = function (rot, icone, acao, perigo) {
            caixa.appendChild(el('button', {
                type: 'button', class: 'te-menu-item' + (perigo ? ' is-perigo' : ''),
                onclick: function () { fecharMenu(); acao(); }
            }, [ic(icone), rot]));
        };
        caixa.appendChild(el('div', { class: 'te-menu-tit', text: x.nome || '' }));
        item('Abrir currículo', 'bi-box-arrow-up-right', function () { marcarCvVisto(x); abrirCv(codigo); });
        item('Ver currículo formatado / PDF', 'bi-file-earmark-pdf', function () { verCvDoCodigo(codigo); });
        if (cvPodeGerir() && x.vol !== 'nao' && S.cv.termoLigado) {
            item('Gerar termo de voluntariado', 'bi-file-earmark-check', function () { abrirTermoNovo(codigo, x.nome); });
        }
        if (cvPodeGerir()) {
            CST.filter(function (c) { return c[0] !== x.status; }).forEach(function (c) {
                item('Marcar como ' + c[1].toLowerCase(), c[0] === 'favorito' ? 'bi-star' : c[0] === 'arquivado' ? 'bi-archive' : 'bi-flag', function () { mudarStatusCv(codigo, c[0]); });
            });
        }
        if (cvMaster() && podeEscreverCv()) {
            item('Excluir currículo', 'bi-trash', function () {
                pedirExclusaoCv(ROTA.curriculoExcluir(codigo), 'Excluir o currículo de ' + (x.nome || '') + '? As candidaturas ligadas a ele também serão removidas. Esta ação não pode ser desfeita.');
            }, true);
        }
    }

    /** As mesmas operações do lote das vagas, gravadas só para o usuário logado. */
    function loteCv(codigos, op) {
        if (!codigos.length || !podeEscreverCv()) {
            return;
        }
        fecharMenu();
        if (op === 'definitivo') {
            abrirConf(null, {
                k: '__definitivo_cv__',
                confirmacao: {
                    titulo: 'Excluir da minha lista',
                    texto: 'Excluir ' + codigos.length + ' item(ns) da sua lista definitivamente? Continuam existindo para os demais.',
                    botao: 'Excluir', motivo: false, perigo: true
                },
                executar: function () { return enviarLoteCv(codigos, op); }
            });
            return;
        }
        enviarLoteCv(codigos, op);
    }

    function enviarLoteCv(codigos, op) {
        return pedirCv('POST', ROTA.curriculosMarcacoes(), { codigos: codigos, op: op }).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return r;
            }
            S.cvSel = [];
            mostrarDesfazer(r.texto, op === 'lixeira' ? function () { enviarLoteCv(codigos, 'restaurar'); } : null);
            carregarCurriculos();
            return r;
        });
    }

    /** Abrir a linha marca o currículo como visto (o nome deixa de ficar em negrito), sem aviso. */
    function marcarCvVisto(x) {
        if (x.vista || !podeEscreverCv()) {
            return;
        }
        x.vista = true;
        pedirCv('POST', ROTA.curriculosMarcacoes(), { codigos: [x.codigo], op: 'lida' }).then(function (r) {
            if (!r.ok) {
                x.vista = false;
            }
            renderCurriculos();
        });
    }

    /** `cStatus` (l.984): grava e atualiza o detalhe aberto e a lista, sem aviso. */
    function mudarStatusCv(codigo, status) {
        if (!cvPodeGerir()) {
            return;
        }
        fecharMenu();
        pedirCv('POST', ROTA.curriculoStatus(codigo), { status: status }).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return;
            }
            if (S.tlm === 'cv' && S.cvDet && S.cvDet.codigo === codigo && r.curriculo) {
                // A nota digitada é a mais nova: a resposta do status não a sobrescreve.
                r.curriculo.nota = S.cvDet.nota;
                S.cvDet = r.curriculo;
                renderDetalheCv();
            }
            carregarCurriculos();
        });
    }

    /** Confirmação do desenho (`delPedir` + `tmDel`, l.371), para o Master e para "Excluir meu currículo". */
    function pedirExclusaoCv(url, txt) {
        if (!podeEscreverCv()) {
            return;
        }
        fecharMenu();
        gravarNotaJa();
        S.tlm = 'cvDel';
        S.vgDet = null;
        S.vf = null;
        S.vgDel = null;
        S.cvDet = null;
        S.cvF = null;
        S.cvDoc = null;
        S.cvDel = { url: url, txt: txt, erro: '', enviando: false };
        relogiosDetalhe = [];
        limpar($('teVagaModal'));
        renderExclusaoCv();
    }

    function renderExclusaoCv() {
        var X = S.cvDel;
        if (!X || S.tlm !== 'cvDel') {
            return;
        }
        modalVaga({
            icone: 'bi-trash', titulo: 'Confirmar exclusão', largura: '720px',
            corpo: [
                el('div', { class: 'te-tlm-del' }, [ic('bi-exclamation-triangle'), el('span', { text: X.txt })]),
                erroTlm(X.erro)
            ],
            botoes: [
                botaoTlm('Cancelar', 'bi-x', '', fecharTlm),
                botaoTlm('Excluir', 'bi-trash', 'perigo', function (e) {
                    if (X.enviando) {
                        return;
                    }
                    X.enviando = true;
                    e.currentTarget.disabled = true;
                    pedirCv('POST', X.url, {}).then(function (r) {
                        X.enviando = false;
                        if (S.cvDel !== X) {
                            return;
                        }
                        if (!r.ok) {
                            X.erro = r.mensagem;
                            renderExclusaoCv();
                            return;
                        }
                        fecharTlm();
                        mostrarToast({ tit: 'Currículo excluído', txt: '', hora: '' });
                        carregarCurriculos();
                    });
                })
            ]
        });
    }

    /* ---------------------------------------------------------------- detalhe (tmCv) */

    function abrirCv(codigo) {
        fecharMenu();
        return pedirCv('GET', ROTA.curriculo(codigo)).then(function (r) {
            if (!r.ok || !r.curriculo) {
                toastErro(r.mensagem);
                return;
            }
            gravarNotaJa();
            S.tlm = 'cv';
            S.cvDet = r.curriculo;
            S.vgDet = null;
            S.vf = null;
            S.vgDel = null;
            S.cvF = null;
            S.cvDoc = null;
            S.cvDel = null;
            relogiosDetalhe = [];
            limpar($('teVagaModal'));
            renderDetalheCv();
        });
    }

    /** Botão de arquivo do detalhe (carteira, comprovante): baixa pelo controller com permissão. */
    function botaoArquivoCv(a, icone, rot, classe) {
        return a && a.url ? el('button', { type: 'button', class: classe || 'te-cv-doc-btn', onclick: function () { baixar(a.url); } }, [ic(icone), rot]) : null;
    }

    /** Detalhe "Currículo · {nome}" (l.301 a 312; `tc`, l.1004) e o rodapé (l.1101). */
    function renderDetalheCv() {
        var x = S.cvDet;
        if (!x || S.tlm !== 'cv') {
            return;
        }
        var A = x.arquivos || {}, st = stCv(x.status);
        var curso = !vazio(x.inst) ? x.inst + (!vazio(x.sem) ? ' · ' + x.sem : '') : '';
        var corpo = [
            el('div', { class: 'te-cv-det-cab' }, [
                avatarCv(x, ' is-56'),
                el('div', { class: 'te-flex1 te-min0' }, [
                    el('b', { class: 'te-cv-det-nome', text: x.nome || '' }),
                    el('div', { class: 'te-cv-det-sub', text: x.experienciaTxt || '' }),
                    botaoInsta(x.insta, ' is-det')
                ]),
                seloSituacao(x.statusRotulo || st[1], x.statusCor || st[2])
            ]),
            el('div', { class: 'te-cv-dados' }, [
                dadoCv('bi-award', x.oabTxt || x.oab || ''),
                dadoCv('bi-geo-alt', x.local || ''),
                dadoCv('bi-laptop', x.modalidade || 'Sem preferência'),
                dadoCv('bi-envelope', x.email || ''),
                dadoCv('bi-telephone', x.tel || 'Não informado'),
                dadoCv('bi-calendar3', 'Recebido em ' + (x.recebido || '')),
                dadoCv('bi-cash-coin', x.pretensao || 'Pretensão não informada'),
                dadoCv('bi-heart', x.volTxt || '')
            ]),
            (x.areas || []).length ? el('div', { class: 'te-vg-areas' }, x.areas.map(function (a) { return el('span', { class: 'te-vg-area', text: a }); })) : null,
            el('div', { class: 'te-tv-req', text: x.resumo || 'Sem resumo.' }),
            el('div', { class: 'te-cv-docs' }, [
                x.situacao ? el('span', { class: 'te-cv-sit' }, [ic('bi-mortarboard'), x.situacaoLonga || '']) : null,
                curso ? el('span', { class: 'te-cv-curso', text: curso }) : null,
                botaoArquivoCv(A.oab, 'bi-person-badge', 'Carteira da OAB'),
                botaoArquivoCv(A.matricula, 'bi-file-earmark-text', 'Comprovante de curso')
            ]),
            botaoArquivoCv(A.curriculo, 'bi-file-earmark-arrow-down', A.curriculo ? A.curriculo.nome || '' : '', 'te-cv-arq-btn'),
            x.termoResumo ? botaoDoTermo(x.termoResumo.botaoTxt, x.termoResumo.cor, function () { abrirTermoVer(x.codigo, x.nome, x.termoResumo); }, '') : null
        ];
        // Situação no banco e nota interna: só o interno recebe a nota (o externo recebe null, decisão 1).
        if (x.nota !== null && x.nota !== undefined) {
            var age = !!x.podeGerir && cvPodeGerir();
            corpo.push(el('div', { class: 'te-cv-sec', text: 'SITUAÇÃO NO BANCO DE TALENTOS' }));
            corpo.push(el('div', { class: 'te-cv-sts' }, CST.map(function (c) {
                var on = x.status === c[0];
                return el('button', {
                    type: 'button', class: 'te-cv-st-btn' + (on ? ' is-on' : ''), disabled: !age,
                    style: on ? { background: c[2], 'border-color': c[2] } : null,
                    attrs: { 'aria-pressed': on ? 'true' : 'false' },
                    onclick: function () { if (!on) { mudarStatusCv(x.codigo, c[0]); } }
                }, c[1]);
            })));
            corpo.push(campoNota(x, age));
        }
        // "Candidaturas:" (`tc.cands`, l.312 e 999): os títulos das vagas, separados por vírgula.
        var titulosCand = (x.candidaturasVagas || []).map(function (c) { return c && typeof c === 'object' ? c.titulo : c; }).filter(function (t) { return !vazio(t); });
        if (titulosCand.length) {
            corpo.push(el('div', { class: 'te-cv-cands' }, [el('b', { text: 'Candidaturas:' }), ' ' + titulosCand.join(', ')]));
        }
        var botoes = [
            botaoTlm('Fechar', 'bi-x', '', fecharTlm),
            botaoTlm('Ver currículo formatado', 'bi-file-earmark-person', '', function () { verCvDe(x, false); })
        ];
        if (x.podeGerarTermo && cvPodeGerir()) {
            botoes.push(botaoTlm(x.termoResumo ? 'Novo termo de voluntariado' : 'Gerar termo de voluntariado', 'bi-file-earmark-check', 'pri', function () { abrirTermoNovo(x.codigo, x.nome); }));
        }
        modalVaga({ icone: 'bi-person-vcard', titulo: 'Currículo · ' + (x.nome || ''), largura: '720px', corpo: corpo, botoes: botoes });
    }

    /* ---------------------------------------------------------------- nota interna (decisão 8) */

    var notaPendente = null;

    /** "NOTA INTERNA (SÓ MASTER E EQUIPE)": grava 800 ms depois da última tecla e ao sair do campo. */
    function campoNota(x, age) {
        var salvo = el('span', { class: 'te-cv-salvo', attrs: { 'aria-live': 'polite' } });
        var area = el('textarea', {
            class: 'te-vf-ctl te-cv-nota-ctl', rows: 3, placeholder: 'Impressões, entrevista, indicação…',
            readOnly: !age, value: x.nota || ''
        });
        if (age) {
            area.addEventListener('input', function (e) {
                x.nota = e.target.value;
                salvo.textContent = '';
                clearTimeout(notaPendente && notaPendente.t);
                notaPendente = { codigo: x.codigo, nota: x.nota, salvo: salvo, t: setTimeout(gravarNotaJa, NOTA_MS) };
            });
            area.addEventListener('blur', gravarNotaJa);
        }
        return el('label', { class: 'te-cv-nota' }, [
            el('span', { class: 'te-cv-nota-cab' }, [el('span', null, [ic('bi-lock'), ' NOTA INTERNA (SÓ MASTER E EQUIPE)']), salvo]),
            area
        ]);
    }

    function gravarNotaJa() {
        var p = notaPendente;
        if (!p) {
            return;
        }
        clearTimeout(p.t);
        notaPendente = null;
        pedirCv('POST', ROTA.curriculoNota(p.codigo), { nota: p.nota }).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return;
            }
            if (p.salvo && p.salvo.isConnected && !notaPendente) {
                p.salvo.textContent = 'Salvo';
            }
        });
    }

    /* ---------------------------------------------------------------- pré-visualização (tmCvDoc) */

    /** Do menu da linha: o documento vem do detalhe (`documentoUrl` e `pdfUrl`). */
    function verCvDoCodigo(codigo) {
        pedirCv('GET', ROTA.curriculo(codigo)).then(function (r) {
            if (!r.ok || !r.curriculo) {
                toastErro(r.mensagem);
                return;
            }
            verCvDe(r.curriculo, false);
        });
    }

    /** `verCv` (l.1063): HTML do servidor no iframe. `volta`: veio do formulário ("Voltar a editar"). */
    function verCvDe(x, volta) {
        if (vazio(x.documentoUrl)) {
            toastErro(null);
            return;
        }
        pedirHtml('GET', comComo(x.documentoUrl)).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return;
            }
            abrirDocCv(r.html, x.pdfUrl || '', volta);
        });
    }

    /** `voltaVaga`: código da vaga quando veio do botão "Currículo" de um candidato ("Voltar a editar" reabre a vaga, como o desenho, l.1100). */
    function baixarPdfDoCv(X) {
        if (!vazio(X.pdfUrl)) {
            baixar(X.pdfUrl);
            return;
        }
        var w = window.open('', '_blank');
        if (!w) {
            toastErro('O navegador bloqueou a janela de impressão. Permita pop-ups.');
            return;
        }
        w.document.write(X.html);
        w.document.close();
        setTimeout(function () { w.focus(); w.print(); }, 400);
    }

    function abrirDocCv(html, pdfUrl, volta, voltaVaga) {
        gravarNotaJa();
        if (!volta) {
            S.cvF = null;
        }
        S.tlm = 'cvDoc';
        S.cvDet = null;
        S.vgDet = null;
        S.vf = null;
        S.vgDel = null;
        S.cvDel = null;
        S.cvDoc = { html: html, pdfUrl: pdfUrl, volta: !!volta, voltaVaga: voltaVaga || null };
        relogiosDetalhe = [];
        limpar($('teVagaModal'));
        renderDocCv();
    }

    /** "Currículo · pré-visualização" (l.370, 960px; rodapé l.1100). */
    function renderDocCv() {
        var X = S.cvDoc;
        if (!X || S.tlm !== 'cvDoc') {
            return;
        }
        // Sem scripts no documento: só o HTML e as imagens do próprio sistema.
        var quadro = el('iframe', { class: 'te-cv-doc', title: 'Currículo', attrs: { sandbox: 'allow-same-origin' } });
        quadro.srcdoc = X.html;
        var botoes = [X.volta
            ? botaoTlm('Voltar a editar', 'bi-arrow-left', '', function () { S.tlm = 'cvForm'; S.cvDoc = null; limpar($('teVagaModal')); renderFormCv(); })
            : X.voltaVaga
                ? botaoTlm('Voltar a editar', 'bi-arrow-left', '', function () { abrirVaga(X.voltaVaga); })
                : botaoTlm('Fechar', 'bi-x', '', fecharTlm)];
        // Sempre o botão, como o desenho (l.1197). Sem `pdfUrl`, abre o documento numa janela e imprime (`imprimirCv`, l.1164).
        botoes.push(botaoTlm('Baixar PDF', 'bi-file-earmark-pdf', 'pri', function () { baixarPdfDoCv(X); }));
        modalVaga({ icone: 'bi-file-earmark-person', titulo: 'Currículo · pré-visualização', largura: '960px', corpo: [quadro], botoes: botoes });
    }

    /* ---------------------------------------------------------------- termo de voluntariado (tmTermo) */

    /**
     * Os campos do modal (l.334 a 341) na ordem do desenho: [chave, rótulo, tipo, placeholder]. `vNac`, `vSit`, `vInst`,
     * `vSem` e `data` não têm campo no desenho: seguem do rascunho do servidor e entram no termo.
     */
    var TERMO_GRUPOS = [
        ['ESCRITÓRIO', [
            ['eNome', 'NOME / RAZÃO SOCIAL'], ['eDoc', 'CNPJ / CPF'], ['eEnd', 'ENDEREÇO DA SEDE'], ['eEmail', 'E-MAIL'], ['eTel', 'TELEFONE'],
            ['resp', 'REPRESENTANTE'], ['respOab', 'OAB Nº'], ['respOabUf', 'OAB UF']
        ]],
        ['VOLUNTÁRIO(A)', [
            ['vNome', 'NOME COMPLETO'], ['vCpf', 'CPF'], ['vRg', 'RG'], ['vOab', 'OAB Nº (se houver)'], ['vOabUf', 'OAB UF'],
            ['vEnd', 'ENDEREÇO'], ['vEmail', 'E-MAIL']
        ]]
    ];
    var TERMO_CONDICOES = [
        ['orient', 'ORIENTADOR(A)', 'text', 'Se diferente do representante'], ['horas', 'HORAS POR SEMANA', 'number', ''], ['modal', 'MODALIDADE', 'text', ''],
        ['inicio', 'INÍCIO', 'date', ''], ['meses', 'PRAZO (MESES)', 'number', ''], ['aviso', 'AVISO PRÉVIO (DIAS)', 'number', ''],
        ['foro', 'FORO (COMARCA)', 'text', ''], ['cidade', 'LOCAL DA ASSINATURA', 'text', '']
    ];
    var temporizadorTermo = null;

    function termoAberto() { return S.tlm === 'termo' && S.tmo; }

    /** "Gerar termo" / "Novo termo": o rascunho vem do servidor (cadastro do escritório e currículo). */
    function abrirTermoNovo(codigo, nome) {
        if (!podeEscreverCv() || vazio(D.urlTermoRascunho)) {
            return;
        }
        fecharMenu();
        pedirCv('GET', ROTA.termoRascunho(codigo)).then(function (r) {
            if (!r.ok || !r.campos) {
                toastErro(r.mensagem);
                return;
            }
            mostrarTermo({ modo: 'novo', codigo: codigo, nome: nome || '', campos: r.campos, resumo: null, html: '', erro: '', enviando: false, li: false });
            atualizarPreviaDoTermo(true);
        });
    }

    /** O termo já enviado ou aceito: o HTML gravado no iframe. `resumo` vem do detalhe (sem CPF nem RG). */
    function abrirTermoVer(codigo, nome, resumo) {
        if (!resumo || vazio(resumo.documentoUrl)) {
            return;
        }
        fecharMenu();
        pedirHtml('GET', comComo(resumo.documentoUrl)).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return;
            }
            mostrarTermo({ modo: 'ver', codigo: codigo, nome: nome || '', campos: {}, resumo: resumo, html: r.html, erro: '', enviando: false, li: false });
        });
    }

    /** O chip "Você tem um termo de voluntariado para aceitar" e o botão de Meu currículo. */
    function abrirTermoDoMeu() {
        var abrir = function () {
            var m = S.cv && S.cv.meu;
            if (m && m.termoResumo) {
                abrirTermoVer(m.codigo, m.nome, m.termoResumo);
            }
        };
        if (S.cv && S.cv.meu) {
            abrir();
            return;
        }
        carregarCurriculos().then(abrir);
    }

    function mostrarTermo(estado) {
        gravarNotaJa();
        S.tlm = 'termo';
        S.tmo = estado;
        S.cvDet = null;
        S.vgDet = null;
        S.vf = null;
        S.vgDel = null;
        S.cvF = null;
        S.cvDoc = null;
        S.cvDel = null;
        relogiosDetalhe = [];
        limpar($('teVagaModal'));
        renderTermo();
    }

    /** O HTML do rascunho vem sempre do servidor (nada de montar o texto jurídico aqui). Sem `agora`, espera 400 ms. */
    function atualizarPreviaDoTermo(agora) {
        clearTimeout(temporizadorTermo);
        var T = S.tmo;
        if (!T || T.modo !== 'novo') {
            return;
        }
        temporizadorTermo = setTimeout(function () {
            pedirHtml('POST', ROTA.termoPrevia(T.codigo), T.campos).then(function (r) {
                if (S.tmo !== T) {
                    return;
                }
                if (!r.ok) {
                    T.erro = r.mensagem || FALHA;
                    renderTermo();
                    return;
                }
                T.html = r.html;
                var quadro = $('teTermoPrevia');
                if (quadro) {
                    quadro.srcdoc = r.html;
                }
            });
        }, agora ? 0 : 400);
    }

    function campoDoTermo(T, chave, rot, tipo, dica) {
        var ctl = el('input', { class: 'te-vf-ctl', type: tipo, value: T.campos[chave] || '', placeholder: dica || '', attrs: { 'data-termo-campo': chave } });
        ctl.addEventListener('input', function (e) {
            T.campos[chave] = e.target.value;
            T.erro = '';
            atualizarPreviaDoTermo(false);
        });
        return el('label', { class: 'te-vf-rot is-pequeno' }, [rot, ctl]);
    }

    /** O formulário do desenho (l.333 a 341), só no rascunho. */
    function formularioDoTermo(T) {
        var corpo = [
            el('div', { class: 'te-tm-aviso' }, [ic('bi-info-circle'), ' Modelo padrão do BlueJus, igual para todos os escritórios. Os dados vêm do cadastro do escritório e do currículo; o que estiver em branco aparece como linha para preencher.'])
        ];
        TERMO_GRUPOS.forEach(function (g) {
            corpo.push(el('div', { class: 'te-tm-grupo', text: g[0] }));
            corpo.push(el('div', { class: 'te-tm-grade' }, g[1].map(function (c) { return campoDoTermo(T, c[0], c[1], 'text', ''); })));
        });
        corpo.push(el('div', { class: 'te-tm-grupo', text: 'CONDIÇÕES' }));
        var ativ = el('textarea', { class: 'te-vf-ctl', rows: 2, value: T.campos.ativ || '' });
        ativ.addEventListener('input', function (e) {
            T.campos.ativ = e.target.value;
            T.erro = '';
            atualizarPreviaDoTermo(false);
        });
        corpo.push(el('label', { class: 'te-vf-rot is-pequeno' }, ['ATIVIDADES', ativ]));
        corpo.push(el('div', { class: 'te-tm-grade' }, TERMO_CONDICOES.map(function (c) { return campoDoTermo(T, c[0], c[1], c[2], c[3]); })));
        var sim = T.campos.ress === 'sim';
        var opcao = function (k, rot) {
            var on = (T.campos.ress || 'nao') === k;
            return el('button', {
                type: 'button', class: 'te-tm-seg' + (on ? ' is-on' : ''), attrs: { 'aria-pressed': on ? 'true' : 'false' },
                onclick: function () { T.campos.ress = k; renderTermo(); atualizarPreviaDoTermo(true); }
            }, rot);
        };
        corpo.push(el('div', { class: 'te-tm-ress' }, [
            el('div', { class: 'te-vf-rot is-pequeno' }, ['RESSARCIMENTO DE DESPESAS', el('div', { class: 'te-tm-segs' }, [opcao('nao', 'Sem ressarcimento'), opcao('sim', 'Ressarcir despesas')])]),
            sim ? el('div', { class: 'te-tm-lim' }, campoDoTermo(T, 'ressLim', 'LIMITE MENSAL (R$)', 'text', 'Opcional')) : null
        ]));
        corpo.push(el('div', { class: 'te-tm-grupo', text: 'PRÉVIA DO TERMO' }));
        return corpo;
    }

    /** Pede o HTML atual (rascunho: do servidor com os campos de agora; salvo: o gravado). */
    function htmlDoTermo() {
        var T = S.tmo;
        return T.modo === 'novo' ? pedirHtml('POST', ROTA.termoPrevia(T.codigo), T.campos) : Promise.resolve({ ok: true, html: T.html });
    }

    /** Word: `Blob` application/msword com BOM e o nome "Termo de voluntariado - {nome}.doc" (`baixarDoc`, l.1132). */
    function baixarWordDoTermo() {
        var T = S.tmo;
        htmlDoTermo().then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return;
            }
            var url = URL.createObjectURL(new Blob(['﻿' + r.html], { type: 'application/msword' }));
            var a = el('a', { href: url, attrs: { download: 'Termo de voluntariado - ' + ((T.campos.vNome || T.nome) || 'voluntario') + '.doc' } });
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        });
    }

    /** Imprimir / PDF (`imprimir`, l.1133): a janela abre no clique (senão o navegador bloqueia) e recebe o HTML depois. */
    function imprimirTermo() {
        var w = window.open('', '_blank');
        if (!w) {
            mostrarToast({ tit: 'Permita pop-ups', txt: 'O navegador bloqueou a janela de impressão.', hora: '' });
            return;
        }
        htmlDoTermo().then(function (r) {
            if (!r.ok) {
                w.close();
                toastErro(r.mensagem);
                return;
            }
            w.document.write(r.html);
            w.document.close();
            setTimeout(function () { w.focus(); w.print(); }, 300);
        });
    }

    /** "Enviar para aceite": o servidor confere os 7 obrigatórios e devolve a mensagem do desenho (l.1134). */
    function enviarTermoParaAceite() {
        var T = S.tmo;
        if (!T || T.enviando) {
            return;
        }
        T.enviando = true;
        T.erro = '';
        renderTermo();
        pedirCv('POST', ROTA.termoEnviar(T.codigo), T.campos).then(function (r) {
            T.enviando = false;
            if (!r.ok) {
                T.erro = r.mensagem || FALHA;
                renderTermo();
                return;
            }
            fecharTlm();
            mostrarToast({ tit: 'Termo enviado para aceite', txt: (T.campos.vNome || T.nome) + ' recebe o termo em Meu currículo.', hora: '' });
            carregarCurriculos();
        });
    }

    /** "Aceitar termo": a caixa "Li e aceito" é obrigatória; data, IP e hash são do servidor. */
    function aceitarTermo() {
        var T = S.tmo;
        if (!T || T.enviando || !T.resumo) {
            return;
        }
        if (!T.li) {
            T.erro = 'Marque "Li e aceito" para confirmar.';
            renderTermo();
            return;
        }
        T.enviando = true;
        T.erro = '';
        renderTermo();
        pedirCv('POST', ROTA.termoAceitar(T.resumo.codigo), { li: true }).then(function (r) {
            T.enviando = false;
            if (!r.ok) {
                T.erro = r.mensagem || FALHA;
                renderTermo();
                return;
            }
            fecharTlm();
            mostrarToast({ tit: 'Termo aceito', txt: 'O escritório foi avisado. Você pode baixar sua via a qualquer momento.', hora: '' });
            carregarCurriculos();
            carregarEngajamento(false);
        });
    }

    /** O modal "Termo de adesão ao serviço voluntário" (l.330 a 352, 960px; rodapé l.1193). */
    function renderTermo() {
        var T = termoAberto() ? S.tmo : null;
        if (!T) {
            return;
        }
        var novo = T.modo === 'novo', R = T.resumo;
        var statusTxt = novo ? 'Rascunho · revise os dados e envie para aceite' : R.statusTxt;
        var statusCor = novo ? '#0a6fa0' : R.statusCor;
        var corpo = [el('div', { class: 'te-tm-status', style: { color: statusCor } }, [ic('bi-circle-fill'), statusTxt])];
        if (novo) {
            formularioDoTermo(T).forEach(function (n) { corpo.push(n); });
        }
        var quadro = el('iframe', { class: 'te-tm-previa', id: 'teTermoPrevia', title: 'Prévia do termo', attrs: { sandbox: '' } });
        quadro.style.height = novo ? '420px' : '62vh';
        quadro.srcdoc = T.html;
        corpo.push(quadro);
        if (!novo && R.podeAceitar) {
            var caixa = el('input', { type: 'checkbox', checked: !!T.li });
            caixa.addEventListener('change', function (e) { T.li = e.target.checked; T.erro = ''; });
            corpo.push(el('label', { class: 'te-tm-li' }, [
                caixa,
                el('span', { text: 'Li e aceito o Termo de Adesão ao Serviço Voluntário. Sei que não há remuneração nem vínculo de emprego (Lei nº 9.608/1998). O aceite fica registrado com data e hora.' })
            ]));
        }
        corpo.push(erroTlm(T.erro));
        var botoes = [
            botaoTlm('Fechar', 'bi-x', '', fecharTlm),
            botaoTlm(cvInterno() ? 'Word' : 'Baixar Word', 'bi-file-earmark-word', '', baixarWordDoTermo),
            botaoTlm('Imprimir / PDF', 'bi-printer', '', imprimirTermo)
        ];
        if (novo) {
            botoes.push(botaoTlm(T.enviando ? 'Enviando…' : 'Enviar para aceite', 'bi-send', 'pri', enviarTermoParaAceite));
        } else if (R.podeAceitar) {
            botoes.push(botaoTlm(T.enviando ? 'Aceitando…' : 'Aceitar termo', 'bi-check2-circle', 'verde', aceitarTermo));
        }
        modalVaga({
            icone: 'bi-file-earmark-check', titulo: 'Termo de adesão ao serviço voluntário' + (T.campos.vNome || T.nome ? ' · ' + (T.campos.vNome || T.nome) : ''),
            largura: '960px', corpo: corpo.filter(Boolean), botoes: botoes
        });
    }

    /** Botão do termo (detalhe e Meu currículo): ícone, texto na cor do estado e seta (`termoAbrir`, l.322; l.230). */
    function botaoDoTermo(texto, cor, acao, classe) {
        return el('button', { type: 'button', class: 'te-tm-botao' + (classe || ''), style: { color: cor }, onclick: acao }, [
            ic('bi-file-earmark-check'), texto, ic('bi-chevron-right')
        ]);
    }

    /* ---------------------------------------------------------------- "Meu currículo" do externo */

    /**
     * "Meu currículo" compacto e "Prévia do currículo" (desenho 06 de 24/10, l.221 a 239; `cvPct`/`cvFalta`, l.1182;
     * `mcPdf`/`mcWord`, l.1227). O cartão "Minhas candidaturas" saiu (decisão P1 do dono): a candidatura aparece no
     * cartão da vaga ("Candidatura enviada").
     */
    function renderMeuCv() {
        var alvo = limpar($('teCvMeu')), m = S.cv.meu, escreve = podeEscreverCv();
        var botaoCard = function (rot, icone, acao) { return el('button', { type: 'button', class: 'te-cv-card-btn', onclick: acao }, [ic(icone), rot]); };
        var conteudo;
        if (!m) {
            conteudo = el('div', { class: 'te-cv-card-vazio' }, [
                el('div', { class: 'te-cv-card-vazio-txt', text: 'Cadastre seu currículo uma vez e use para se candidatar às vagas publicadas na Central.' }),
                escreve ? el('button', { type: 'button', class: 'te-cv-cadastrar', onclick: abrirFormCv }, [ic('bi-plus-lg'), ' Cadastrar currículo']) : null
            ]);
        } else {
            var P = m.percentual || {}, n = Math.max(0, Math.min(100, parseInt(P.pct, 10) || 0));
            var cor = P.cor || (n >= 90 ? '#1e7b45' : n >= 60 ? '#0a6fa0' : '#c76a12');
            var faltas = P.faltas || m.faltas || [];
            var linha = [m.cargo || '', m.oabTxt || m.oab || ''].filter(function (t) { return !vazio(t); }).join(' · ');
            conteudo = el('div', { class: 'te-cv-meu' }, [
                el('div', { class: 'te-cv-meu-topo' }, [
                    avatarCv(m, ' is-52'),
                    el('span', { class: 'te-min0' }, [el('b', { class: 'te-cv-meu-nome', text: m.nome || '' }), el('span', { class: 'te-cv-meu-linha', text: linha })])
                ]),
                el('div', { class: 'te-cv-pct' }, [
                    el('div', { class: 'te-cv-pct-cab' }, [el('b', { text: 'Currículo completo' }), el('b', { style: { color: cor }, text: n + '%' })]),
                    el('div', { class: 'te-cv-pct-trilho' }, el('div', { class: 'te-cv-pct-barra', style: { width: n + '%', background: cor } })),
                    faltas.length ? el('div', { class: 'te-cv-falta', text: 'Falta: ' + faltas.join(', ') }) : null
                ]),
                m.termoResumo ? botaoDoTermo(m.termoResumo.meuTxt, m.termoResumo.cor, function () { abrirTermoVer(m.codigo, m.nome, m.termoResumo); }, ' is-meu') : null
            ]);
        }
        alvo.appendChild(el('div', { class: 'te-cv-card', attrs: { 'data-screen-label': 'Meu currículo' } }, [
            el('div', { class: 'te-cv-card-cab' }, [
                ic('bi-person-vcard'),
                el('b', { text: 'Meu currículo' }),
                m ? el('span', { class: 'te-cv-card-acoes' }, [
                    escreve ? botaoCard('Editar', 'bi-pencil', abrirFormCv) : null,
                    botaoCard('Word', 'bi-file-earmark-word', function () { baixar(D.urlCurriculoWord); })
                ]) : null
            ]),
            conteudo
        ]));
        alvo.appendChild(cartaoPreviaCv(m));
    }

    /** "Prévia do currículo" (l.232 a 239): o documento salvo no iframe, ou o esqueleto A4 sem currículo. */
    function cartaoPreviaCv(m) {
        var corpo;
        if (m) {
            var quadro = el('iframe', { class: 'te-cv-prev-doc', title: 'Meu currículo', attrs: { sandbox: 'allow-same-origin' } });
            var chave = (m.codigo || '') + '|' + (m.atualizadoEm || '');
            if (S.cvPrevia && S.cvPrevia.chave === chave) {
                quadro.srcdoc = S.cvPrevia.html;
            } else if (!vazio(m.documentoUrl)) {
                pedirHtml('GET', comComo(m.documentoUrl)).then(function (r) {
                    if (r.ok) {
                        S.cvPrevia = { chave: chave, html: r.html };
                        quadro.srcdoc = r.html;
                    }
                });
            }
            corpo = el('div', { class: 'te-cv-prev-corpo' }, quadro);
        } else {
            var barra = function (h, w, fundo, extra) { return el('span', { class: 'te-cv-prev-barra', style: Object.assign({ height: h, width: w, background: fundo }, extra || {}) }); };
            corpo = el('div', { class: 'te-cv-prev-corpo is-vazio' }, [
                el('div', { class: 'te-cv-prev-a4', attrs: { 'aria-hidden': 'true' } }, [
                    el('div', { class: 'te-cv-prev-lado' }, [
                        el('span', { class: 'te-cv-prev-foto' }),
                        barra('6px', '80%', 'rgba(255,255,255,.35)'), barra('6px', '70%', 'rgba(255,255,255,.25)'), barra('6px', '75%', 'rgba(255,255,255,.25)'),
                        el('span', { class: 'te-cv-prev-esp', style: { height: '14px' } }),
                        barra('6px', '60%', 'rgba(255,255,255,.35)'), barra('6px', '80%', 'rgba(255,255,255,.25)')
                    ]),
                    el('div', { class: 'te-cv-prev-main' }, [
                        barra('14px', '70%', '#c9d6df'), barra('8px', '45%', '#9fc3d8'),
                        el('span', { class: 'te-cv-prev-linha' }),
                        barra('7px', '35%', '#9fc3d8'), barra('8px', '100%', '#e3e9ee'), barra('8px', '92%', '#e3e9ee'), barra('8px', '96%', '#e3e9ee'),
                        el('span', { class: 'te-cv-prev-esp', style: { height: '10px', display: 'block' } }),
                        barra('7px', '35%', '#9fc3d8'), barra('8px', '85%', '#e3e9ee'), barra('8px', '90%', '#e3e9ee'), barra('8px', '70%', '#e3e9ee')
                    ])
                ]),
                el('span', { class: 'te-cv-prev-vazio', text: 'Cadastre seu currículo para vê-lo formatado aqui.' })
            ]);
        }
        return el('div', { class: 'te-cv-prev', attrs: { 'data-screen-label': 'Meu currículo formatado' } }, [
            el('div', { class: 'te-cv-prev-cab' }, [
                ic('bi-file-earmark-person'),
                el('b', { text: 'Prévia do currículo' }),
                m && !vazio(m.pdfUrl) ? el('button', { type: 'button', class: 'te-cv-prev-pdf', onclick: function () { baixar(m.pdfUrl); } }, [ic('bi-download'), 'Baixar PDF']) : null
            ]),
            corpo
        ]);
    }

    /* ---------------------------------------------------------------- formulário (tmCvForm) */

    /**
     * `editarCv` (l.987): o próprio currículo do externo, preenchido com o detalhe de `meu`.
     * `candidatarA`: código da vaga quando veio de "Candidatar-se" sem currículo (`tlPend`, l.974): o
     * formulário vira "Seu currículo para candidatura" e o salvar já candidata (§4.6). Como `onclick`,
     * o primeiro argumento é o evento: só vale se for texto.
     */
    function abrirFormCv(candidatarA) {
        if (!podeEscreverCv() || !S.cv || cvInterno()) {
            return;
        }
        var pend = typeof candidatarA === 'string' && !vazio(candidatarA) ? candidatarA : null;
        fecharMenu();
        var m = S.cv.meu, v = function (k) { return m && m[k] !== null && m[k] !== undefined ? String(m[k]) : ''; };
        var copia = function (L, campos) {
            return (L || []).map(function (it) {
                var o = {};
                campos.forEach(function (k) { o[k] = it && it[k] !== null && it[k] !== undefined ? String(it[k]) : ''; });
                return o;
            });
        };
        S.cvF = {
            edicao: !!m,
            candidatarA: pend,
            f: {
                nome: v('nome'), email: v('email'), tel: v('tel'), insta: v('insta'), situacao: v('situacao'),
                oabNum: v('oabNum'), oabUf: v('oabUf'), inst: v('inst'), sem: v('sem'), cargo: v('cargo'),
                cidade: v('cidade'), uf: v('uf'), modalidade: v('modalidade'), anos: v('anos'), pretensao: v('pretensao'),
                vol: v('vol'), areas: ((m && m.areas) || []).slice(), resumo: v('resumo'), tituloP: v('tituloP'),
                linkedin: v('linkedin'), lattes: v('lattes'), habs: v('habs'),
                exps: copia(m && m.exps, ['cargo', 'org', 'ini', 'fim', 'desc']),
                forms: copia(m && m.forms, ['curso', 'inst', 'ini', 'fim']),
                idis: copia(m && m.idis, ['lng', 'nivel']),
                // Quem já tem currículo já autorizou no primeiro envio.
                lgpd: !!m
            },
            arquivos: Object.assign({}, (m && m.arquivos) || {}),
            fotoUrl: fotoDe(m),
            fotoLocal: null,
            subindo: {},
            // Sem currículo salvo, o servidor recusa anexo (422): os escolhidos esperam o primeiro "Salvar".
            pendentes: {},
            mudouArquivo: false,
            rolagem: 0,
            erro: '',
            enviando: false
        };
        gravarNotaJa();
        S.tlm = 'cvForm';
        S.vgDet = null;
        S.vf = null;
        S.vgDel = null;
        S.cvDet = null;
        S.cvDoc = null;
        S.cvDel = null;
        relogiosDetalhe = [];
        limpar($('teVagaModal'));
        renderFormCv();
    }

    /**
     * Anexo do formulário (carteira, comprovante, currículo): vai ao servidor assim que escolhido
     * (multipart `arquivo`); o servidor valida tipo e tamanho e o erro dele aparece no rodapé do corpo.
     */
    function campoAnexo(tipo, texto, aceita) {
        var F = S.cvF, a = F.arquivos[tipo];
        var nome = F.subindo[tipo] || (F.pendentes[tipo] && F.pendentes[tipo].name) || (a && a.nome) || texto;
        return el('span', { class: 'te-cv-anexo' }, [
            ic('bi-paperclip'),
            el('span', { class: 'te-flex1 te-reticencias', text: nome }),
            el('input', { type: 'file', attrs: { accept: aceita }, onchange: function (e) { enviarArquivoCv(tipo, e.target); } })
        ]);
    }

    function enviarArquivoCv(tipo, input) {
        var F = S.cvF, arq = input.files && input.files[0];
        if (!F || !arq || !podeEscreverCv()) {
            return;
        }
        F.rolagem = ($('teTlmCorpo') || {}).scrollTop || 0;
        F.erro = '';
        if (tipo === 'foto') {
            if (F.fotoLocal && window.URL && URL.revokeObjectURL) {
                URL.revokeObjectURL(F.fotoLocal);
            }
            F.fotoLocal = window.URL && URL.createObjectURL ? URL.createObjectURL(arq) : null;
        }
        if (!F.edicao) {
            // Primeiro cadastro: guarda e mostra como escolhido; vai ao servidor depois do primeiro salvar.
            F.pendentes[tipo] = arq;
            renderFormCv();
            return;
        }
        F.subindo[tipo] = arq.name;
        renderFormCv();
        var fd = new FormData();
        fd.append('arquivo', arq);
        pedirCv('POST', ROTA.curriculoArquivo(tipo), fd).then(function (r) {
            if (S.cvF !== F) {
                return;
            }
            delete F.subindo[tipo];
            if (tipo === 'foto') {
                F.fotoLocal = null;
            }
            if (!r.ok) {
                F.erro = r.mensagem;
                renderFormCv();
                return;
            }
            var c = r.curriculo || {};
            F.arquivos = Object.assign({}, F.arquivos, c.arquivos || {});
            F.fotoUrl = fotoDe(c) || F.fotoUrl;
            F.mudouArquivo = true;
            renderFormCv();
        });
    }

    function removerFotoCv(e) {
        e.preventDefault();
        e.stopPropagation();
        var F = S.cvF;
        if (!F || !podeEscreverCv()) {
            return;
        }
        if (F.pendentes.foto) {
            // Foto só escolhida (ainda não enviada): basta esquecê-la.
            delete F.pendentes.foto;
            if (F.fotoLocal && window.URL && URL.revokeObjectURL) {
                URL.revokeObjectURL(F.fotoLocal);
            }
            F.fotoLocal = null;
            renderFormCv();
            return;
        }
        pedirCv('POST', ROTA.curriculoArquivoRemover('foto'), {}).then(function (r) {
            if (S.cvF !== F) {
                return;
            }
            if (!r.ok) {
                F.erro = r.mensagem;
                renderFormCv();
                return;
            }
            F.arquivos = Object.assign({}, F.arquivos, { foto: null });
            F.fotoUrl = null;
            F.mudouArquivo = true;
            renderFormCv();
        });
    }

    /** Listas de experiência, formação e idiomas (l.1179 a 1181): cada campo grava no item; a lixeira remove. */
    function itensCv(k, montar, novo, rotAdd) {
        var F = S.cvF, L = F.f[k];
        var rm = function (i) {
            return el('button', {
                type: 'button', class: 'te-cv-rm', title: 'Remover',
                onclick: function (e) { e.preventDefault(); F.f[k] = F.f[k].filter(function (_, j) { return j !== i; }); renderFormCv(); }
            }, ic('bi-trash'));
        };
        return L.map(function (it, i) { return montar(it, rm(i)); }).concat([
            el('button', {
                type: 'button', class: 'te-cv-add',
                onclick: function () { F.f[k] = F.f[k].concat([Object.assign({}, novo)]); renderFormCv(); }
            }, [ic('bi-plus-lg'), rotAdd])
        ]);
    }

    function secaoCv(icone, texto) {
        return el('div', { class: 'te-cv-secao' }, [ic(icone), texto]);
    }

    function pequeno(texto, controle) { return rotuloVf(texto, controle, ' is-pequeno'); }

    /** O formulário na ordem do desenho (l.254 a 283), com os blocos condicionais por situação (l.1174). */
    function renderFormCv() {
        var F = S.cvF;
        if (!F || S.tlm !== 'cvForm') {
            return;
        }
        var f = F.f, Lt = listasCv(), sit = f.situacao;
        var temOab = sit === 'adv' || sit === 'estOab', temCurso = sit === 'est' || sit === 'estOab' || sit === 'bach';
        var foto = F.fotoLocal || F.fotoUrl;
        var circulo = el('label', { class: 'te-cv-foto' + (foto ? ' is-foto' : ''), title: 'Escolher foto' }, [
            foto ? null : ic('bi-camera'),
            el('span', { class: 'te-cv-foto-lapis' }, ic('bi-pencil')),
            el('input', { type: 'file', attrs: { accept: 'image/*' }, onchange: function (e) { enviarArquivoCv('foto', e.target); } })
        ]);
        if (foto) {
            circulo.style.backgroundImage = 'url("' + String(foto).replace(/["\\\n\r]/g, function (c) { return encodeURIComponent(c); }) + '")';
        }
        var insta = el('input', {
            class: 'te-cv-insta-ctl', value: f.insta, placeholder: 'usuario',
            // `tfOnInsta` (l.1178): sem @, sem o endereço do Instagram, sem o resto da URL e sem espaço.
            oninput: function (e) {
                var limpo = e.target.value.replace(/^@+/, '').replace(/^https?:\/\/(www\.)?instagram\.com\//i, '').replace(/[\/?#].*$/, '').replace(/\s/g, '');
                f.insta = limpo;
                if (e.target.value !== limpo) {
                    e.target.value = limpo;
                }
            }
        });

        var corpo = [
            el('div', { class: 'te-cv-foto-linha' }, [
                circulo,
                el('div', { class: 'te-cv-foto-txt' }, [
                    el('b', { text: 'Foto de perfil' }),
                    el('span', { text: 'JPG ou PNG. Aparece para o escritório junto com seu currículo.' }),
                    foto && !F.subindo.foto ? el('button', { type: 'button', class: 'te-cv-foto-rm', onclick: removerFotoCv }, [ic('bi-trash'), ' Remover foto']) : null
                ]),
                el('label', { class: 'te-vf-rot te-cv-insta-campo' }, [
                    'INSTAGRAM',
                    el('span', { class: 'te-cv-insta-box' }, [el('span', { class: 'te-cv-insta-pre' }, [ic('bi-instagram'), el('b', { text: '@' })]), insta])
                ])
            ]),
            el('div', { class: 'te-vf-grade' }, [
                rotuloVf('NOME COMPLETO *', ctlVf('input', f, 'nome', { type: 'text' })),
                rotuloVf('E-MAIL *', ctlVf('input', f, 'email', { type: 'email' })),
                rotuloVf('TELEFONE / WHATSAPP', ctlVf('input', f, 'tel', { type: 'tel' }))
            ]),
            el('div', { class: 'te-vf-rot' }, [
                'SITUAÇÃO ACADÊMICA E NA OAB',
                el('div', { class: 'te-cv-sits' }, CV_SIT_OPS.map(function (o) {
                    var on = sit === o[0];
                    return el('button', {
                        type: 'button', class: 'te-cv-sit-op' + (on ? ' is-on' : ''),
                        attrs: { 'aria-pressed': on ? 'true' : 'false' },
                        onclick: function () { f.situacao = o[0]; F.erro = ''; renderFormCv(); }
                    }, [ic(o[2]), o[1]]);
                }))
            ]),
            temOab ? el('div', { class: 'te-vf-grade is-base' }, [
                rotuloVf(sit === 'estOab' ? 'INSCRIÇÃO DE ESTAGIÁRIO' : 'OAB Nº', ctlVf('input', f, 'oabNum', { type: 'text', placeholder: 'Somente números', inputMode: 'numeric' })),
                rotuloVf('OAB UF', selectVf(f, 'oabUf', Lt.ufs)),
                rotuloVf('CARTEIRA DA OAB', campoAnexo('oab', 'Anexar carteira (PDF ou foto)', '.pdf,.jpg,.jpeg,.png'))
            ]) : null,
            temCurso ? el('div', { class: 'te-vf-grade is-base' }, [
                rotuloVf('INSTITUIÇÃO DE ENSINO', ctlVf('input', f, 'inst', { type: 'text', placeholder: 'Nome da faculdade' })),
                rotuloVf(sit === 'bach' ? 'ANO DE CONCLUSÃO' : 'SEMESTRE ATUAL', ctlVf('input', f, 'sem', { type: 'text', placeholder: sit === 'bach' ? 'Ano' : 'Semestre' })),
                rotuloVf(sit === 'bach' ? 'DIPLOMA OU CERTIFICADO DE CONCLUSÃO' : 'COMPROVANTE DE MATRÍCULA', campoAnexo('matricula', 'Anexar comprovante (PDF ou foto)', '.pdf,.jpg,.jpeg,.png'))
            ]) : null,
            el('div', { class: 'te-vf-grade' }, [rotuloVf('CARGO PRETENDIDO', selectVf(f, 'cargo', Lt.cargos))]),
            el('div', { class: 'te-vf-grade' }, [
                rotuloVf('CIDADE', ctlVf('input', f, 'cidade', { type: 'text' })),
                rotuloVf('UF', selectVf(f, 'uf', Lt.ufs)),
                rotuloVf('MODALIDADE PREFERIDA', selectVf(f, 'modalidade', Lt.modalidades)),
                rotuloVf('ANOS DE EXPERIÊNCIA', ctlVf('input', f, 'anos', { type: 'number', min: 0 }))
            ]),
            el('div', { class: 'te-vf-grade is-base' }, [
                rotuloVf('PRETENSÃO SALARIAL', ctlVf('input', f, 'pretensao', { type: 'text', placeholder: 'Valor mensal ou A combinar' })),
                el('div', { class: 'te-vf-rot' }, [
                    'TRABALHO VOLUNTÁRIO',
                    el('div', { class: 'te-cv-vol-seg' }, CV_VOL_OPS.map(function (o) {
                        var on = f.vol === o[0];
                        return el('button', {
                            type: 'button', class: 'te-cv-vol-op' + (on ? ' is-on' : ''),
                            attrs: { 'aria-pressed': on ? 'true' : 'false' },
                            onclick: function () { f.vol = on ? '' : o[0]; renderFormCv(); }
                        }, o[1]);
                    }))
                ])
            ]),
            el('div', { class: 'te-vf-rot' }, [
                'ÁREAS DE ATUAÇÃO',
                el('div', { class: 'te-vf-areas' }, (Lt.areas || []).map(function (a) {
                    var on = f.areas.indexOf(a) >= 0;
                    return el('button', {
                        type: 'button', class: 'te-cv-area' + (on ? ' is-on' : ''),
                        attrs: { 'aria-pressed': on ? 'true' : 'false' },
                        onclick: function () {
                            f.areas = on ? f.areas.filter(function (y) { return y !== a; }) : f.areas.concat([a]);
                            renderFormCv();
                        }
                    }, a);
                }))
            ]),
            rotuloVf('RESUMO PROFISSIONAL', ctlVf('textarea', f, 'resumo', { rows: 4, placeholder: 'Formação, experiência e diferenciais' })),
            secaoCv('bi-file-earmark-person', 'APRESENTAÇÃO DO CURRÍCULO'),
            el('div', { class: 'te-vf-grade' }, [
                pequeno('TÍTULO PROFISSIONAL', ctlVf('input', f, 'tituloP', { type: 'text', placeholder: 'Profissões separadas por |' })),
                pequeno('LINKEDIN', ctlVf('input', f, 'linkedin', { type: 'text', placeholder: 'Endereço do perfil' })),
                pequeno('CURRÍCULO LATTES', ctlVf('input', f, 'lattes', { type: 'text', placeholder: 'Endereço do Lattes (opcional)' }))
            ]),
            secaoCv('bi-briefcase', 'EXPERIÊNCIA PROFISSIONAL')
        ].concat(itensCv('exps', function (it, rm) {
            return el('div', { class: 'te-cv-item' }, [
                el('div', { class: 'te-cv-item-exp' }, [
                    pequeno('CARGO / FUNÇÃO', ctlVf('input', it, 'cargo', { type: 'text' })),
                    pequeno('EMPRESA / INSTITUIÇÃO', ctlVf('input', it, 'org', { type: 'text' })),
                    pequeno('INÍCIO', ctlVf('input', it, 'ini', { type: 'text', placeholder: 'Ano' })),
                    pequeno('FIM', ctlVf('input', it, 'fim', { type: 'text', placeholder: 'Ano ou Atual' })),
                    rm
                ]),
                pequeno('ATIVIDADES', ctlVf('textarea', it, 'desc', { rows: 2, placeholder: 'Principais atividades e resultados' }))
            ]);
        }, { cargo: '', org: '', ini: '', fim: '', desc: '' }, 'Adicionar experiência')).concat([
            secaoCv('bi-mortarboard', 'FORMAÇÃO ACADÊMICA E CURSOS')
        ]).concat(itensCv('forms', function (it, rm) {
            return el('div', { class: 'te-cv-item te-cv-item-form' }, [
                pequeno('CURSO / NÍVEL', ctlVf('input', it, 'curso', { type: 'text' })),
                pequeno('INSTITUIÇÃO', ctlVf('input', it, 'inst', { type: 'text' })),
                pequeno('INÍCIO', ctlVf('input', it, 'ini', { type: 'text', placeholder: 'Ano' })),
                pequeno('CONCLUSÃO', ctlVf('input', it, 'fim', { type: 'text', placeholder: 'Ano' })),
                rm
            ]);
        }, { curso: '', inst: '', ini: '', fim: '' }, 'Adicionar formação ou curso')).concat([
            secaoCv('bi-translate', 'IDIOMAS E HABILIDADES')
        ]).concat(itensCv('idis', function (it, rm) {
            return el('div', { class: 'te-cv-item-idi' }, [
                pequeno('IDIOMA', ctlVf('input', it, 'lng', { type: 'text' })),
                pequeno('NÍVEL', selectVf(it, 'nivel', CV_NIVEIS)),
                rm
            ]);
        }, { lng: '', nivel: '' }, 'Adicionar idioma')).concat([
            pequeno('HABILIDADES PROFISSIONAIS', ctlVf('input', f, 'habs', { type: 'text', placeholder: 'Separe por vírgula' })),
            rotuloVf('CURRÍCULO EM PDF OU WORD', campoAnexo('curriculo', 'Anexar arquivo (opcional)', '.pdf,.doc,.docx')),
            el('label', { class: 'te-cv-lgpd' }, [
                el('input', { type: 'checkbox', checked: !!f.lgpd, onchange: function (e) { f.lgpd = e.target.checked; } }),
                el('span', { text: CV_LGPD })
            ]),
            erroTlm(F.erro)
        ]);

        var botoes = [
            botaoTlm('Cancelar', 'bi-x', '', fecharTlm),
            botaoTlm('Pré-visualizar', 'bi-eye', '', previaDoFormCv),
            botaoTlm(F.candidatarA ? 'Salvar e candidatar-se' : 'Salvar currículo', 'bi-check2', 'pri', salvarCv)
        ];
        if (F.edicao) {
            botoes.push(botaoTlm('Excluir meu currículo', 'bi-trash', '', function () {
                pedirExclusaoCv(ROTA.curriculoExcluirMeu(), 'Excluir seu currículo e todas as suas candidaturas? Esta ação não pode ser desfeita.');
            }));
        }
        // `tlmTit` (l.1156): com candidatura pendente, "Seu currículo para candidatura".
        var tituloForm = F.candidatarA ? 'Seu currículo para candidatura' : F.edicao ? 'Editar currículo' : 'Cadastrar currículo';
        modalVaga({ icone: 'bi-person-vcard', titulo: tituloForm, largura: '720px', corpo: corpo, botoes: botoes });
        if (F.rolagem) {
            $('teTlmCorpo').scrollTop = F.rolagem;
            F.rolagem = 0;
        }
    }

    /**
     * Corpo do POST (salvar e prévia): as listas só com os itens preenchidos (l.1006 e 1094). A situação
     * vai como `sit` (nome do desenho, lido pelo DadosDoCurriculo) e repetida em `situacao` (nome da resposta).
     */
    function corpoDoCv(f) {
        var t = function (x) { return String(x || '').trim(); };
        return {
            nome: t(f.nome), email: t(f.email), tel: t(f.tel), insta: t(f.insta), sit: f.situacao || '', situacao: f.situacao || '',
            oabNum: t(f.oabNum), oabUf: f.oabUf || '', inst: t(f.inst), sem: t(f.sem), cargo: f.cargo || '',
            cidade: t(f.cidade), uf: f.uf || '', modalidade: f.modalidade || '', anos: t(f.anos), resumo: t(f.resumo),
            pretensao: t(f.pretensao), vol: f.vol || '', tituloP: t(f.tituloP), linkedin: t(f.linkedin), lattes: t(f.lattes),
            habs: t(f.habs), areas: f.areas.slice(),
            exps: f.exps.filter(function (x) { return !vazio(x.cargo) || !vazio(x.org); }),
            forms: f.forms.filter(function (x) { return !vazio(x.curso) || !vazio(x.inst); }),
            idis: f.idis.filter(function (x) { return !vazio(x.lng); }),
            lgpd: !!f.lgpd
        };
    }

    /** Validação na ordem do `salvarCv()` (l.1006); o servidor repete e responde 422 com as mesmas mensagens. */
    function erroDoFormCv(f) {
        if (vazio(f.nome)) {
            return 'Informe o nome completo.';
        }
        if (!/^\S+@\S+\.\S+$/.test(String(f.email || '').trim())) {
            return 'Informe um e-mail válido.';
        }
        if (!f.situacao) {
            return 'Informe sua situação: advogado(a), estagiário(a) com OAB, estudante ou bacharel(a).';
        }
        if ((f.situacao === 'adv' || f.situacao === 'estOab') && vazio(f.oabNum)) {
            return 'Informe o número de inscrição na OAB.';
        }
        if ((f.situacao === 'est' || f.situacao === 'estOab') && vazio(f.inst)) {
            return 'Informe a instituição de ensino.';
        }
        return f.lgpd ? '' : 'É preciso autorizar o uso dos dados (LGPD) para enviar o currículo.';
    }

    function travarRodape(sim) {
        Array.prototype.forEach.call(document.querySelectorAll('#teVagaModal .te-tlm-rodape button'), function (b) { b.disabled = sim; });
    }

    function salvarCv() {
        var F = S.cvF;
        if (!F || F.enviando || !podeEscreverCv()) {
            return;
        }
        var erro = erroDoFormCv(F.f);
        if (erro) {
            F.erro = erro;
            F.rolagem = 1e6;
            renderFormCv();
            return;
        }
        F.enviando = true;
        travarRodape(true);
        var corpoCv = corpoDoCv(F.f);
        if (F.candidatarA) {
            // O salvar segue o mesmo caso de uso da candidatura (§4.6): o servidor candidata e responde o `toast`.
            corpoCv.candidatarA = F.candidatarA;
        }
        // `pedirCand` guarda o corpo do erro: com `candidatarA`, o 422 pode trazer `curriculoSalvo` (§4.6).
        pedirCand('POST', ROTA.curriculoSalvar(), corpoCv).then(function (r) {
            if (S.cvF !== F) {
                return;
            }
            F.enviando = false;
            if (!r.ok && !r.curriculoSalvo) {
                F.erro = r.mensagem;
                F.rolagem = 1e6;
                renderFormCv();
                return;
            }
            // Daqui em diante o currículo está gravado (mesmo com a candidatura recusada): fecha o formulário,
            // o que também descarta a candidatura pendente (`candidatarA`, o `tlPend` do desenho).
            var pendentes = F.pendentes;
            F.mudouArquivo = false;
            F.pendentes = {};
            var candidatou = !!F.candidatarA;
            if (candidatou) {
                esquecerOferta(F.candidatarA);
            }
            F.candidatarA = null;
            fecharTlm();
            enviarPendentesCv(pendentes).then(function () { carregarCurriculos(); });
            if (candidatou) {
                // O cartão passa a mostrar "Candidatura enviada" e a compatibilidade com o currículo novo.
                carregarVagas();
            }
            if (!r.ok) {
                // Currículo salvo, candidatura recusada: o QUASE com `quase`, senão o aviso do servidor.
                if (r.quase) {
                    abrirQuase();
                } else {
                    toastDoServidor({ titulo: r.titulo, texto: r.mensagem }, 'Currículo salvo', r.mensagem);
                }
                return;
            }
            if (r.toast && !vazio(r.toast.titulo)) {
                mostrarToast({ tit: r.toast.titulo, txt: r.toast.texto || '', hora: '' });
            } else {
                mostrarToast({ tit: 'Currículo salvo', txt: 'Use-o para se candidatar às vagas.', hora: '' });
            }
        });
    }

    /**
     * Depois do primeiro "Salvar currículo": envia, um de cada vez, os arquivos escolhidos antes de o
     * currículo existir. O erro de cada envio sai em toast (o formulário já fechou).
     */
    function enviarPendentesCv(pendentes) {
        return ['foto', 'oab', 'matricula', 'curriculo'].filter(function (t) { return !!pendentes[t]; }).reduce(function (fila, t) {
            return fila.then(function () {
                var fd = new FormData();
                fd.append('arquivo', pendentes[t]);
                return pedirCv('POST', ROTA.curriculoArquivo(t), fd).then(function (r) {
                    if (!r.ok) {
                        toastErro(r.mensagem);
                    }
                });
            });
        }, Promise.resolve());
    }

    /** "Pré-visualizar" (l.1094): manda os dados ainda não salvos e mostra o HTML; volta ao formulário. */
    function previaDoFormCv() {
        var F = S.cvF;
        if (!F || F.enviando || !podeEscreverCv()) {
            return;
        }
        F.rolagem = ($('teTlmCorpo') || {}).scrollTop || 0;
        travarRodape(true);
        pedirHtml('POST', ROTA.curriculoPrevia(), corpoDoCv(F.f)).then(function (r) {
            if (S.cvF !== F || S.tlm !== 'cvForm') {
                return;
            }
            if (!r.ok) {
                travarRodape(false);
                F.erro = r.mensagem;
                F.rolagem = 1e6;
                renderFormCv();
                return;
            }
            // O PDF é sempre do currículo salvo: só aparece quando ele já existe.
            abrirDocCv(r.html, (S.cv && S.cv.meu && S.cv.meu.pdfUrl) || '', true);
        });
    }

    /* =============================================================================
       CANDIDATURA (spec VS 18.10.26 §4.6, fatia F2b)
       Desenho "06 - Trabalho Externo - VS 18.10.26": selo de compatibilidade e rodapé do cartão
       (l.161 e 165), "SUA CANDIDATURA" e candidatos no detalhe
       da vaga (l.292 e 295 a 298), modal QUASE (l.392 a 400). Lógica do `renderVals()`: l.839, 846,
       974 a 985, 1001, 1003, 1006, 1039 a 1047, 1095, 1103, 1117, 1154, 1156 e 1190.
       O servidor decide tudo (botão do externo, etapas, quem vê quem, quem envia e muda status);
       a prévia do Master (`?como=`) só lê.
       ============================================================================= */

    /** Situação da candidatura (`AST`, l.846): chave, rótulo e cor, na ordem das pílulas do detalhe. */
    var AST = [
        ['recebida', 'Recebida', '#0a6fa0'],
        ['analise', 'Em análise', '#9a5b00'],
        ['entrevista', 'Entrevista', '#5b3f9e'],
        ['aprovado', 'Aprovado', '#1e7b45'],
        ['contratado', 'Contratado', '#1e7b45'],
        ['nao', 'Não selecionado', '#6b8494']
    ];
    /** Frases do QUASE (l.839): ícone, título e texto. */
    var QUASE = [
        ['bi-lightning-charge', 'Nossa, foi por pouco!', 'Outro profissional aceitou primeiro. Fique de olho: novas vagas aparecem toda hora.'],
        ['bi-rocket-takeoff', 'Quase lá!', 'Essa já foi preenchida, mas seu perfil está no radar. Continue tentando!'],
        ['bi-trophy', 'Por um triz!', 'Quem tenta sempre chega. A próxima pode ser sua.'],
        ['bi-stopwatch', 'Corrida acirrada!', 'A vaga voou. Ative os avisos e seja o primeiro da próxima vez.'],
        ['bi-star', 'Você chegou perto!', 'Mantenha o currículo completo: perfis completos aparecem primeiro.'],
        ['bi-emoji-smile', 'Não desanime!', 'Hoje não foi, mas a rede BlueJus cresce todo dia. Bora para a próxima!'],
        ['bi-fire', 'Disputada essa, hein?', 'Sinal de que é uma ótima área. Continue de olho nas vagas.'],
        ['bi-compass', 'Seguimos em frente!', 'Veja as outras vagas abertas: tem oportunidade esperando por você.'],
        ['bi-hand-thumbs-up', 'Foi quase!', 'Seu interesse foi registrado. Continue tentando, a persistência vence.'],
        ['bi-graph-up-arrow', 'Ritmo bom!', 'Cada tentativa deixa seu perfil mais visível para os escritórios.']
    ];
    /**
     * Como o `pedirCv()`, mas o erro devolve o corpo inteiro (`titulo`, `semCurriculo`, `quase` do contrato
     * da §4.6), com `ok:false` e `mensagem`. Sem a URL no template, falha sem sair da página.
     */
    function pedirCand(metodo, url, corpo) {
        if (vazio(url)) {
            return Promise.resolve({ ok: false, mensagem: FALHA });
        }
        var op = { method: metodo, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
        if (corpo !== undefined) {
            op.headers['Content-Type'] = 'application/json';
            op.body = JSON.stringify(corpo);
        }
        return fetch(url, op).then(function (r) {
            return r.json().then(function (j) {
                if (!j || typeof j !== 'object') {
                    return { ok: false, mensagem: FALHA };
                }
                if (!r.ok || j.ok === false) {
                    j.ok = false;
                    j.mensagem = j.mensagem || FALHA;
                    return j;
                }
                j.ok = true;
                return j;
            }, function () {
                return { ok: false, mensagem: FALHA };
            });
        }, function () {
            return { ok: false, mensagem: FALHA };
        });
    }

    /** `{titulo, texto}` do servidor no aviso do canto; sem título, o padrão. */
    function toastDoServidor(t, tituloPadrao, textoPadrao) {
        var ok = t && !vazio(t.titulo);
        mostrarToast({ tit: ok ? t.titulo : tituloPadrao, txt: ok ? (t.texto || '') : (textoPadrao || ''), hora: '' });
    }

    /** "N dia(s)" do desenho (l.1046) quando o servidor manda número; texto vai como veio. */
    function textoDeDias(t) {
        if (typeof t === 'number' && !isNaN(t)) {
            return t + (t === 1 ? ' dia' : ' dias');
        }
        return t === null || t === undefined ? '' : String(t);
    }

    /* ---------------------------------------------------------------- cartão: compatibilidade */

    /** Selo "{N}% compatível" (`cp`/`cpCor`, l.161 e 1117), só para o externo com currículo. */
    function seloCompat(v) {
        if (vgInterno() || v.compat === null || v.compat === undefined || v.compat === '') {
            return null;
        }
        var n = Math.round(+v.compat);
        if (isNaN(n)) {
            return null;
        }
        var cor = n >= 70 ? '#1e7b45' : n >= 40 ? '#0a6fa0' : '#6b8494';
        return el('span', { class: 'te-cand-cp', style: { color: cor } }, [ic('bi-bullseye'), n + '% compatível']);
    }

    /* ---------------------------------------------------------------- linha do tempo (etapas, l.1039 a 1047) */

    /**
     * Visual do `etapas()`: o servidor manda os passos com `estado` (feito, atual, futuro, nao); a tela
     * pinta como o desenho. Feito vira o check a partir do segundo passo; o traço até o próximo fica
     * azul entre passos alcançados e some no último e antes de "Não selecionado". `compacto`: 26px
     * (lista de candidatos); senão 30px ("SUA CANDIDATURA").
     */
    function linhaDoTempo(et, compacto) {
        var P = (et && et.passos) || [];
        if (!P.length) {
            return null;
        }
        return el('div', { class: 'te-cand-et-rolo' }, el('div', {
            class: 'te-cand-et' + (compacto ? ' is-compacto' : ''),
            style: { 'min-width': (P.length * (compacto ? 78 : 96)) + 'px' }
        }, P.map(function (p, i) {
            var est = ['feito', 'atual', 'futuro', 'nao'].indexOf(p.estado) >= 0 ? p.estado : 'futuro';
            var prox = P[i + 1];
            var alcancado = prox && (prox.estado === 'feito' || prox.estado === 'atual');
            var linha = !prox || prox.estado === 'nao' ? 'transparent' : est === 'feito' && alcancado ? 'var(--te-cand-feito)' : 'var(--te-cand-linha)';
            var icone = est === 'nao' ? 'bi-x-lg' : est === 'feito' && i > 0 ? 'bi-check-lg' : (p.ic || 'bi-circle');
            var dias = textoDeDias(p.dias);
            return el('div', { class: 'te-cand-passo' }, [
                el('span', { class: 'te-cand-passo-linha', style: { background: linha } }),
                dias ? el('span', { class: 'te-cand-passo-dias', text: dias }) : null,
                el('span', { class: 'te-cand-passo-bola is-' + est }, ic(icone)),
                el('span', { class: 'te-cand-passo-rot is-' + est, text: p.rot || '' }),
                el('span', { class: 'te-cand-passo-dt', text: p.data || '' })
            ]);
        })));
    }

    /* ---------------------------------------------------------------- detalhe da vaga */

    /** "SUA CANDIDATURA" (l.292): título, total de dias à direita e a linha do tempo de 30px. */
    function blocoMinhaCandidatura(mc) {
        if (!mc) {
            return null;
        }
        var et = mc.etapas || {};
        var total = mc.total !== undefined && mc.total !== null ? mc.total : et.total;
        return el('div', { class: 'te-cand-minha' }, [
            el('div', { class: 'te-cand-minha-cab' }, [ic('bi-signpost-split'), el('b', { text: 'SUA CANDIDATURA' }), el('span', { text: textoDeDias(total) })]),
            linhaDoTempo(et, false)
        ]);
    }

    /** Marcados para envio nesta vaga, sem os que já foram (o `S` do `enviarCands`, l.981). */
    function selecaoDeEnvio(v) {
        if (!S.candSel || S.candSel.vaga !== v.codigo) {
            return [];
        }
        var livres = (v.candidatos || []).filter(function (k) { return !k.enviado; }).map(function (k) { return k.id; });
        return S.candSel.ids.filter(function (id) { return livres.indexOf(id) >= 0; });
    }

    /** Título (`tvCandTit`), `envioInfo` com os marcados (`tvEnvTxt`, l.1190), o vazio e as linhas (l.296 a 298). */
    function blocoCandidatos(v) {
        var L = v.candidatos || [], sel = selecaoDeEnvio(v);
        var info = !vazio(v.envioInfo) ? v.envioInfo + (sel.length ? ' · ' + sel.length + ' marcado(s)' : '') : '';
        var nos = [el('div', { class: 'te-cand-cab' }, [
            el('span', { class: 'te-cand-tit', text: v.candTitulo || 'CANDIDATOS (' + L.length + ')' }),
            info ? el('span', { class: 'te-cand-env', text: info }) : null
        ])];
        if (!L.length) {
            nos.push(el('div', { class: 'te-cand-vazio', text: 'Ainda não há candidaturas para esta vaga.' }));
        }
        L.forEach(function (k) { nos.push(linhaCandidato(v, k, sel)); });
        return nos;
    }

    /**
     * Linha do candidato (l.298): marcar para envio (`podeEnviar`), avatar, nome que abre o detalhe do
     * currículo, "Currículo" (documento formatado com "Voltar a editar" para a vaga), as pílulas de situação
     * (`podeMudarStatus`) e a linha do tempo compacta.
     */
    function linhaCandidato(v, k, sel) {
        var escreve = podeEscreverVaga(), marcado = sel.indexOf(k.id) >= 0;
        var envio = null;
        if (v.podeEnviar && escreve) {
            var dicaEnv = 'Marcar para enviar ao escritório';
            envio = el('button', {
                type: 'button', class: 'te-cand-env-btn' + (k.enviado ? ' is-enviado' : marcado ? ' is-on' : ''),
                title: k.enviado ? 'Enviado ao escritório' : dicaEnv,
                attrs: { 'data-tip': dicaEnv, 'aria-label': k.enviado ? 'Enviado ao escritório' : dicaEnv, 'aria-pressed': marcado || k.enviado ? 'true' : 'false' },
                onclick: function () { alternarEnvio(v, k); }
            }, ic(k.enviado ? 'bi-send-check-fill' : marcado ? 'bi-check-square-fill' : 'bi-square'));
        }
        var pilulas = null;
        if (v.podeMudarStatus && escreve) {
            pilulas = el('div', { class: 'te-cand-sts' }, AST.map(function (a) {
                var on = k.status === a[0];
                return el('button', {
                    type: 'button', class: 'te-cand-st' + (on ? ' is-on' : ''),
                    style: on ? { background: a[2], 'border-color': a[2] } : null,
                    attrs: { 'aria-pressed': on ? 'true' : 'false' },
                    onclick: function () { mudarStatusCand(v, k, a[0]); }
                }, a[1]);
            }));
        }
        return el('div', { class: 'te-cand-linha' }, [
            envio,
            avatarCv(k, ' is-30'),
            el('button', {
                type: 'button', class: 'te-cand-nome',
                onclick: function () { if (!vazio(k.codigoCv)) { abrirCv(k.codigoCv); } }
            }, [el('b', { text: k.nome || 'Currículo removido' }), el('span', { text: k.info || '' })]),
            el('button', { type: 'button', class: 'te-cand-doc', onclick: function () { verDocCandidato(v, k); } }, [ic('bi-file-earmark-person'), 'Currículo']),
            pilulas,
            el('div', { class: 'te-cand-linha-et' }, linhaDoTempo(k.etapas, true))
        ]);
    }

    /** Marca e desmarca para envio (`tgEnv`, l.1003); quem já foi enviado não volta. */
    function alternarEnvio(v, k) {
        if (k.enviado || !v.podeEnviar || !podeEscreverVaga()) {
            return;
        }
        if (!S.candSel || S.candSel.vaga !== v.codigo) {
            S.candSel = { vaga: v.codigo, ids: [] };
        }
        var ids = S.candSel.ids;
        S.candSel.ids = ids.indexOf(k.id) >= 0 ? ids.filter(function (y) { return y !== k.id; }) : ids.concat([k.id]);
        renderDetalheVaga();
    }

    /**
     * `enviarCands` (l.981): sem marcados, o aviso do desenho; abaixo do mínimo (contando os já enviados),
     * a confirmação do sistema com o texto da spec; depois, o POST e o aviso que o servidor manda.
     */
    function enviarCandidatos(v) {
        if (!v.podeEnviar || !podeEscreverVaga()) {
            return;
        }
        var ids = selecaoDeEnvio(v);
        if (!ids.length) {
            mostrarToast({ tit: 'Selecione candidatos', txt: 'Marque quem será enviado ao escritório.', hora: '' });
            return;
        }
        var total = (+v.enviadosN || 0) + ids.length, min = +v.minEnvio || 0;
        var ir = function () {
            if (S.candSel && S.candSel.enviando) {
                return Promise.resolve({ ok: false });
            }
            if (S.candSel) {
                S.candSel.enviando = true;
            }
            travarRodape(true);
            return pedirCand('POST', ROTA.vagaEnviar(v.codigo), { candidaturas: ids }).then(function (r) {
                if (S.candSel) {
                    S.candSel.enviando = false;
                }
                var noDetalhe = S.tlm === 'vaga' && S.vgDet && S.vgDet.codigo === v.codigo;
                if (!r.ok) {
                    if (noDetalhe) {
                        travarRodape(false);
                    }
                    toastDoServidor({ titulo: r.titulo, texto: r.mensagem }, 'Não foi possível concluir', r.mensagem);
                    return r;
                }
                if (S.candSel && S.candSel.vaga === v.codigo) {
                    S.candSel.ids = [];
                }
                if (noDetalhe && r.vaga) {
                    S.vgDet = r.vaga;
                    renderDetalheVaga();
                } else if (noDetalhe) {
                    abrirVaga(v.codigo, true);
                }
                toastDoServidor(r.toast, 'Candidatos enviados', '');
                carregarVagas();
                return r;
            });
        };
        if (min && total < min) {
            abrirConf(null, {
                k: '__enviar_candidatos__',
                confirmacao: {
                    titulo: 'Enviar ao escritório',
                    texto: 'O padrão é enviar pelo menos ' + min + ' candidatos. Enviar mesmo assim ' + total + '?',
                    botao: 'Enviar', motivo: false
                },
                executar: ir
            });
            return;
        }
        ir();
    }

    /** `aStatus` (l.985): grava e redesenha o detalhe com a vaga da resposta (ou relê a vaga). */
    function mudarStatusCand(v, k, status) {
        if (!v.podeMudarStatus || !podeEscreverVaga() || k.status === status) {
            return;
        }
        pedirCand('POST', ROTA.candidaturaStatus(k.id), { status: status }).then(function (r) {
            if (!r.ok) {
                toastDoServidor({ titulo: r.titulo, texto: r.mensagem }, 'Não foi possível concluir', r.mensagem);
                return;
            }
            var noDetalhe = S.tlm === 'vaga' && S.vgDet && S.vgDet.codigo === v.codigo;
            if (noDetalhe && r.vaga) {
                S.vgDet = r.vaga;
                renderDetalheVaga();
            } else if (noDetalhe) {
                abrirVaga(v.codigo, true);
            }
            carregarVagas();
        });
    }

    /**
     * "Currículo" do candidato (`verDoc`, l.1003): o documento formatado, com "Voltar a editar" para a vaga (l.1100). O PDF
     * sai da mesma rota do documento trocando `/documento` por `/pdf` (§4.5), quando o candidato não traz `pdfUrl`.
     */
    function verDocCandidato(v, k) {
        if (vazio(k.documentoUrl)) {
            toastErro(null);
            return;
        }
        pedirHtml('GET', comComo(k.documentoUrl)).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return;
            }
            var pdf = k.pdfUrl || (/\/documento$/.test(k.documentoUrl) ? k.documentoUrl.replace(/\/documento$/, '/pdf') : '');
            abrirDocCv(r.html, pdf, false, v.codigo);
        });
    }

    /** Rodapé do externo (l.1095) pelo `botaoExterno` do servidor. */
    function botaoDoExterno(v) {
        var ir = function () { candidatar(v.codigo); };
        switch (v.botaoExterno) {
            case 'enviada':
                return botaoTlm('Candidatura enviada', 'bi-check2-circle', 'verde', fecharTlm);
            case 'aceitar':
                // D11b (l.1169): "Recusar" (secundário) ao lado de "Aceitar oferta".
                return [botaoTlm('Recusar', 'bi-x-circle', 'recusar', function () { recusarOferta(v); }), botaoTlm('Aceitar oferta', 'bi-hand-thumbs-up', 'verde', ir)];
            case 'recusou':
                return botaoTlm('Você recusou · aceitar mesmo assim', 'bi-arrow-counterclockwise', 'verde', function () { aceitarMesmoAssim(v); });
            case 'indisponivel':
                return botaoTlm('Oferta indisponível', 'bi-slash-circle', 'cinza', fecharTlm);
            case 'candidatar':
                return botaoTlm('Candidatar-se', 'bi-send', 'pri', ir);
            default:
                return null;
        }
    }

    /**
     * `recusar` (l.1169): a confirmação da tela (não o `confirm()` do navegador), depois a recusa no servidor,
     * que grava, registra no histórico e avisa o dono e a Central. Fecha o detalhe e mostra o toast do desenho.
     */
    function recusarOferta(v) {
        if (!podeEscreverVaga()) {
            return;
        }
        abrirConf(null, {
            k: '__recusar_oferta__',
            confirmacao: { titulo: 'Recusar', texto: 'Recusar esta oferta? O escritório e a Central BlueJus serão avisados.', botao: 'Recusar', perigo: true, motivo: false },
            executar: function () {
                return pedir('POST', ROTA.vagaRecusa(v.codigo), {}).then(function (r) {
                    if (!r.ok) {
                        toastErro(r.mensagem);
                        return r;
                    }
                    fecharTlm();
                    toastDoServidor(r.toast, 'Oferta recusada', 'Obrigado por responder. Você continua recebendo novas oportunidades.');
                    carregarVagas();
                    return r;
                });
            }
        });
    }

    /** "Você recusou · aceitar mesmo assim" (l.1169): tira a recusa e candidata, como no desenho. */
    function aceitarMesmoAssim(v) {
        if (!podeEscreverVaga()) {
            return;
        }
        pedir('POST', ROTA.vagaRecusaDesfazer(v.codigo), {}).then(function (r) {
            if (!r.ok) {
                toastErro(r.mensagem);
                return;
            }
            candidatar(v.codigo);
        });
    }

    /* ---------------------------------------------------------------- candidatar-se e aceitar oferta */

    var candidatando = {};

    /**
     * `candidatar`/`aceitar` (l.974 e 978): o servidor decide pela oferta. Deu certo: fecha o detalhe e
     * mostra o aviso da resposta. Sem currículo (409 `semCurriculo`): o formulário da candidatura. 422 com
     * `quase`: o modal QUASE; os demais 422 viram aviso com o `titulo` e a `mensagem` do servidor.
     */
    function candidatar(codigo) {
        fecharMenu();
        if (!podeEscreverVaga() || candidatando[codigo]) {
            return;
        }
        candidatando[codigo] = true;
        var noDetalhe = function () { return S.tlm === 'vaga' && S.vgDet && S.vgDet.codigo === codigo; };
        if (noDetalhe()) {
            travarRodape(true);
        }
        pedirCand('POST', ROTA.vagaCandidatura(codigo), {}).then(function (r) {
            candidatando[codigo] = false;
            if (r.ok || r.quase) {
                // Aceitou (ou já soube pelo QUASE): esta oferta não pede outro QUASE no recarregar.
                esquecerOferta(codigo);
            }
            if (r.ok) {
                if (noDetalhe()) {
                    fecharTlm();
                }
                toastDoServidor(r.toast, 'Candidatura enviada', '');
                carregarVagas();
                if (S.cv) {
                    carregarCurriculos();
                }
                return;
            }
            if (noDetalhe()) {
                travarRodape(false);
            }
            if (r.semCurriculo) {
                abrirFormDaCandidatura(codigo);
                return;
            }
            if (r.quase) {
                if (noDetalhe()) {
                    fecharTlm();
                }
                abrirQuase();
                carregarVagas();
                return;
            }
            toastDoServidor({ titulo: r.titulo, texto: r.mensagem }, 'Não foi possível concluir', r.mensagem);
            carregarVagas();
            if (noDetalhe()) {
                abrirVaga(codigo, true);
            }
        });
    }

    /** "Seu currículo para candidatura" (l.974 e 1156): precisa da lista de currículos do externo. */
    function abrirFormDaCandidatura(codigo) {
        var abrirJa = function () {
            if (S.cv && !cvInterno()) {
                abrirFormCv(codigo);
            } else {
                toastErro(null);
            }
        };
        if (S.cv) {
            abrirJa();
        } else {
            carregarCurriculos().then(abrirJa);
        }
    }

    /* ---------------------------------------------------------------- modal QUASE (l.392 a 400, 841) */

    /**
     * `quase()` (l.841): sorteia a frase, nunca a mesma da última vez. `op.semPreenchida` (oferta que
     * expirou sem aceite dela, §4.7): tira as duas frases que falam de vaga preenchida
     * ("Outro profissional aceitou primeiro", "Essa já foi preenchida").
     */
    function abrirQuase(op) {
        var semPre = !!(op && op.semPreenchida);
        var pode = QUASE.map(function (q, n) { return n; }).filter(function (n) { return !semPre || (n !== 0 && n !== 1); });
        var i;
        do {
            i = pode[Math.floor(Math.random() * pode.length)];
        } while (pode.length > 1 && i === S.quaseUlt);
        S.quaseUlt = i;
        S.quase = QUASE[i];
        renderQuase();
    }

    function caixaQuase() {
        var c = $('teQuase');
        if (!c) {
            c = el('div', { id: 'teQuase' });
            raiz.appendChild(c);
        }
        return c;
    }

    function renderQuase() {
        var alvo = limpar(caixaQuase()), Q = S.quase;
        if (!Q) {
            return;
        }
        alvo.appendChild(el('div', { class: 'te-cand-quase-fundo', onclick: fecharQuase }));
        alvo.appendChild(el('div', { class: 'te-cand-quase-pos' }, el('div', { class: 'te-cand-quase', attrs: { role: 'dialog', 'aria-modal': 'true', 'aria-label': Q[1] } }, [
            el('div', { class: 'te-cand-quase-faixa' }),
            el('div', { class: 'te-cand-quase-corpo' }, [
                el('span', { class: 'te-cand-quase-ic' }, ic(Q[0])),
                el('div', { class: 'te-cand-quase-tit', text: Q[1] }),
                el('div', { class: 'te-cand-quase-txt', text: Q[2] }),
                el('div', { class: 'te-cand-quase-botoes' }, [
                    el('button', { type: 'button', class: 'te-cand-quase-fechar', text: 'Fechar', onclick: fecharQuase }),
                    el('button', { type: 'button', class: 'te-cand-quase-ver', onclick: verOutrasVagas }, [ic('bi-search'), 'Ver outras vagas'])
                ])
            ])
        ])));
    }

    function fecharQuase() {
        S.quase = null;
        limpar(caixaQuase());
    }

    /** `quaseVer` (l.1110): aba Vagas no filtro "Abertas". */
    function verOutrasVagas() {
        fecharQuase();
        S.vgFiltro = 'aberta';
        S.vgSel = [];
        if (moduloAtual() !== 'vagas') {
            irModulo('vagas');
        } else {
            renderVagas();
        }
    }

    /** As candidaturas do externo (`S.cv.minhas`): o convite de vaga não reaparece para quem já se candidatou nela. */
    function minhas() { return (S.cv && S.cv.minhas) || []; }

    /* =============================================================================
       VISÃO EXTERNA, ENGAJAMENTO E AVISOS (spec VS 18.10.26 §4.7, fatia F4)
       Desenho "06 - Trabalho Externo - VS 18.10.26": abas e banner (l.85 a 96, 1013 a 1023, 1105 a
       1107), boas-vindas pelo link (l.382 a 391, 1078, 1079, 1111), lembrete diário (l.404 a 422,
       1054 a 1061, 1112), QUASE da oferta perdida (l.1012, 1069) e "Ver exemplo de mensagem" (l.1099).
       O servidor monta tudo (`GET /api/engajamento`): saudação, chips, contadores, estado do aviso,
       lembrete e boas-vindas. Fica no navegador só a conveniência por pessoa: a marca do lembrete do
       dia e as ofertas abertas que a pessoa abriu. Na prévia do Master nada disso grava.
       ============================================================================= */

    var QS = new URLSearchParams(location.search);
    var ORIGEM_LINK = QS.get('origem') === 'link';

    /** Contador do selo (`contadores`); null enquanto o engajamento não chegou. */
    function contadorDoEngajamento(k) {
        var c = S.eng && S.eng.contadores;
        return c && typeof c[k] === 'number' ? c[k] : null;
    }

    /** Quem usa o navegador: `data-eu-id` de #tePagina; na falta, o do lançador da Central. */
    function euId() {
        if (D.euId) {
            return String(D.euId);
        }
        var n = document.querySelector('[data-eu-id]');
        if (n && n.getAttribute('data-eu-id')) {
            return n.getAttribute('data-eu-id');
        }
        // Sem o id na página: um resumo do nome, para não misturar duas pessoas no mesmo navegador.
        var nome = String(perfil().nome || ''), h = 0;
        for (var i = 0; i < nome.length; i++) {
            h = ((h << 5) - h + nome.charCodeAt(i)) | 0;
        }
        return 'n' + (h >>> 0).toString(36);
    }

    function lerLocal(chave, padrao) {
        try {
            var v = JSON.parse(window.localStorage.getItem(chave));
            return v === null || v === undefined ? padrao : v;
        } catch (e) {
            return padrao;
        }
    }

    /** Escrita no navegador: nunca na prévia; sem localStorage (modo privado, cota), só não lembra. */
    function gravarLocal(chave, valor) {
        if (previa()) {
            return;
        }
        try {
            window.localStorage.setItem(chave, JSON.stringify(valor));
        } catch (e) {
            // Só não lembra.
        }
    }

    /** Data (aaaa-mm-dd) e hora no horário de Brasília (lacuna 9 da §4.7). */
    function hojeEmBrasilia() {
        try {
            return new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Sao_Paulo', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
        } catch (e) {
            return new Date().toISOString().slice(0, 10);
        }
    }

    function horaEmBrasilia() {
        try {
            var h = +new Intl.DateTimeFormat('en-GB', { timeZone: 'America/Sao_Paulo', hour: '2-digit', hourCycle: 'h23' }).format(new Date());
            return isNaN(h) ? new Date().getHours() : h % 24;
        } catch (e) {
            return new Date().getHours();
        }
    }

    function ehExterno() { return !perfil().interno; }

    /* ---------------------------------------------------------------- carga */

    var temporizadorEng = null;

    /** Recarrega junto com as vagas e os currículos (as duas respostas chegam juntas: um pedido só). */
    function agendarEngajamento() {
        if (!S.engIniciado) {
            return;
        }
        clearTimeout(temporizadorEng);
        temporizadorEng = setTimeout(function () { carregarEngajamento(false); }, 150);
    }

    /**
     * GET /api/engajamento no recorte do perfil (`?como=` na prévia). Na primeira carga vão os `vagas` e
     * `cadastro` do link de boas-vindas. Falha não mostra erro: o banner só não aparece.
     */
    function carregarEngajamento(primeira) {
        var como = S.como;
        var q = [];
        if (primeira && ORIGEM_LINK) {
            // O servidor monta as boas-vindas pelo `origem=link`, mesmo sem vaga válida (l.1078, 1111).
            q.push('origem=link');
            var vid = String(QS.get('vagas') || '').split(',').filter(function (c) { return /^VG-\d{1,9}$/.test(c.trim()); }).map(function (c) { return c.trim(); });
            if (vid.length) {
                q.push('vagas=' + encodeURIComponent(vid.join(',')));
            }
            if (QS.get('cadastro') === '1') {
                q.push('cadastro=1');
            }
        }
        var perguntadas = ofertasAPerguntar();
        if (perguntadas.length) {
            q.push('ofertas=' + encodeURIComponent(perguntadas.join(',')));
        }
        var url = ROTA.engajamento(q.join('&'));
        if (!url) {
            return Promise.resolve(null);
        }
        return pedirCv('GET', url).then(function (r) {
            if (como !== S.como || !r.ok) {
                return null;
            }
            var E = r.engajamento && typeof r.engajamento === 'object' && !Array.isArray(r.engajamento) ? r.engajamento : r;
            S.eng = E;
            renderModulos();
            conferirOfertasPerdidas(perguntadas, E.ofertas);
            return E;
        });
    }

    /* ---------------------------------------------------------------- banner (l.88 a 96) */

    function caixaEng() {
        var c = $('teEngajamento');
        if (!c) {
            c = el('div', { id: 'teEngajamento' });
            var m = $('teModulos');
            m.parentNode.insertBefore(c, m.nextSibling);
        }
        return c;
    }

    /** Só nas abas Vagas e Currículo (`engOn`, l.1106), com as abas visíveis e o engajamento carregado. */
    function renderEngajamento() {
        var alvo = limpar(caixaEng()), E = S.eng, B = E && E.banner;
        var mostra = !!B && !S.painel.bloqueio && (veVagas() || veCv()) && moduloAtual() !== 'demandas';
        if (!mostra) {
            S.engVisivel = false;
            return;
        }
        var entra = !S.engVisivel;
        S.engVisivel = true;
        var chips = Array.isArray(B.chips) ? B.chips : [];
        var A = E.alerta || {};
        var botaoAlerta = null;
        if (A.disponivel) {
            var pv = previa();
            botaoAlerta = el('button', {
                type: 'button', class: 'te-eng-alerta', disabled: pv || S.engAlertaEnviando,
                title: pv ? 'Pré-visualização: somente leitura' : null,
                onclick: alternarAlerta
            }, [ic('bi-bell'), A.ligado ? 'Avisos de vagas ligados' : 'Avisar quando sair vaga da minha área', A.ligado ? ic('bi-check-circle-fill', { color: 'var(--te-eng-ok)' }) : null]);
        }
        alvo.appendChild(el('div', { class: 'te-eng' + (entra ? ' is-entra' : '') }, [
            el('div', { class: 'te-eng-topo' }, [
                el('div', { class: 'te-eng-textos' }, [
                    el('div', { class: 'te-eng-sau', text: B.saudacao || '' }),
                    el('div', { class: 'te-eng-sub', text: B.subtitulo || '' })
                ]),
                botaoAlerta
            ]),
            chips.length ? null : el('div', { class: 'te-eng-vazio', text: 'Tudo em dia por aqui.' }),
            el('div', { class: 'te-eng-chips' }, chips.map(function (c) {
                var temIr = !!(c && c.ir);
                return el('button', {
                    type: 'button', class: 'te-eng-chip' + (temIr ? '' : ' is-sem-ir'),
                    onclick: function () { if (temIr) { irDoChip(c.ir); } }
                }, [ic(c.ic || 'bi-dot', { color: c.cor || 'var(--te-azul)' }), c.txt || '', temIr ? el('i', { class: 'bi bi-chevron-right te-eng-seta' }) : null]);
            }))
        ]));
    }

    /** Chaves de módulo do servidor e dos endereços (`vag`/`vagas`, `cv`, `dem`/`demandas`). */
    function moduloDaChave(k) {
        return { vag: 'vagas', vagas: 'vagas', cv: 'cv', curriculos: 'cv', dem: 'demandas', demandas: 'demandas' }[String(k || '')] || null;
    }

    /** Vai à aba (e ao filtro dela), redesenhando se já estiver nela. */
    function irPara(mod, filtro) {
        var m = moduloDaChave(mod);
        if (!m) {
            return;
        }
        if (m === 'vagas' && filtro) {
            S.vgFiltro = String(filtro);
            S.vgSel = [];
        }
        if (m === 'cv' && filtro) {
            S.cvFiltro = String(filtro);
            S.cvSel = [];
        }
        if (m === moduloAtual()) {
            if (m === 'vagas') {
                renderVagas();
            } else if (m === 'cv') {
                renderCurriculos();
            }
            return;
        }
        irModulo(m);
    }

    /** Roda `fn` com a lista de currículos carregada (o formulário do externo precisa de `meu`). */
    function comCurriculos(fn) {
        if (S.cv) {
            fn();
        } else {
            carregarCurriculos().then(function () { if (S.cv) { fn(); } });
        }
    }

    /** O próprio currículo do externo (`editarCv`, l.987), na aba "Meu currículo". */
    function abrirMeuFormCv() {
        irPara('cv');
        comCurriculos(function () { abrirFormCv(); });
    }

    /**
     * "Publique a primeira vaga ou oferta com tempo" (`chipsI`, l.1020): o formulário da vaga com a oferta
     * ligada, 15 minutos e 1 vaga, como o desenho (`{ oferta: true, dur: 15, qtd: 1 }`).
     */
    function abrirNovaOferta() {
        irPara('vagas');
        abrirFormVaga(null);
        if (S.tlm === 'form' && S.vf) {
            S.vf.f.oferta = true;
            S.vf.f.duracaoDaOferta = 15;
            S.vf.f.qtd = 1;
            renderFormVaga();
        }
    }

    /** `ir` do chip: `{mod, filtro?, acao?}`; `acao` "novaVaga" (a oferta da l.1020) e "editarCv" abrem os formulários. */
    function irDoChip(ir) {
        if (ir.acao === 'novaVaga') {
            abrirNovaOferta();
            return;
        }
        if (ir.acao === 'abrirTermo') {
            abrirTermoDoMeu();
            return;
        }
        if (ir.acao === 'editarCv') {
            abrirMeuFormCv();
            return;
        }
        irPara(ir.mod, ir.filtro);
    }

    /** "Avisar quando sair vaga da minha área" (`alertaTg`, l.1107): a escolha é do usuário, no servidor. */
    function alternarAlerta() {
        var E = S.eng;
        if (!E || !E.alerta || previa() || S.engAlertaEnviando || !ROTA.vagasAlerta()) {
            return;
        }
        var ligar = !E.alerta.ligado;
        S.engAlertaEnviando = true;
        renderEngajamento();
        pedirCv('POST', ROTA.vagasAlerta(), { ligado: ligar }).then(function (r) {
            S.engAlertaEnviando = false;
            if (!r.ok) {
                renderEngajamento();
                toastErro(r.mensagem);
                return;
            }
            if (S.eng === E) {
                E.alerta = r.alerta && typeof r.alerta === 'object' ? r.alerta : { disponivel: true, ligado: ligar };
            }
            renderEngajamento();
            var on = !!(r.alerta ? r.alerta.ligado : ligar);
            toastDoServidor(r.toast, on ? 'Avisos ligados' : 'Avisos desligados', on ? 'Você recebe um aviso assim que sair vaga ou oferta na sua área.' : '');
        });
    }

    /* ---------------------------------------------------------------- boas-vindas pelo link (l.382 a 391) */

    function caixaDe(id) {
        var c = $(id);
        if (!c) {
            c = el('div', { id: id });
            raiz.appendChild(c);
        }
        return c;
    }

    function abrirBoasVindas(bv) {
        S.bv = bv;
        renderBoasVindas();
    }

    function renderBoasVindas() {
        var alvo = limpar(caixaDe('teBoasVindas')), B = S.bv;
        if (!B) {
            return;
        }
        var botao = B.botao || {};
        alvo.appendChild(el('div', { class: 'te-eng-bv-fundo', onclick: fecharBoasVindas }));
        alvo.appendChild(el('div', { class: 'te-eng-bv-pos' }, el('div', { class: 'te-eng-bv', attrs: { role: 'dialog', 'aria-modal': 'true', 'aria-label': B.titulo || '' } }, [
            el('div', { class: 'te-eng-bv-cab' }, [
                el('span', { class: 'te-eng-bv-bola' }),
                el('img', { class: 'te-eng-bv-simbolo', attrs: { src: D.urlSimbolo || '/images/logo/bluejus_simbolo.svg', alt: '' } }),
                el('div', { class: 'te-eng-bv-tit', text: B.titulo || '' }),
                el('div', { class: 'te-eng-bv-txt', text: B.texto || '' })
            ]),
            el('div', { class: 'te-eng-bv-corpo' }, (B.passos || []).map(function (p) {
                return el('div', { class: 'te-eng-bv-passo' + (p.feito ? ' is-feito' : '') }, [ic(p.feito ? 'bi-check-circle-fill' : (p.ic || 'bi-circle')), p.rot || '']);
            }).concat([
                el('div', { class: 'te-eng-bv-botoes' }, [
                    el('button', { type: 'button', class: 'te-eng-sec', text: 'Explorar primeiro', onclick: fecharBoasVindas }),
                    vazio(botao.rot) ? null : el('button', { type: 'button', class: 'te-eng-pri', onclick: function () { irDasBoasVindas(botao); } }, [ic('bi-arrow-right-circle'), botao.rot])
                ])
            ]))
        ])));
    }

    function fecharBoasVindas() {
        S.bv = null;
        var c = $('teBoasVindas');
        if (c) {
            limpar(c);
        }
    }

    /**
     * `bvIr` (l.1111): sem currículo, o formulário (o da candidatura quando há vaga indicada aberta);
     * com currículo, a vaga indicada; senão as vagas abertas.
     */
    function irDasBoasVindas(b) {
        fecharBoasVindas();
        var codigo = typeof b.codigo === 'string' && /^VG-\d{1,9}$/.test(b.codigo) ? b.codigo : null;
        if (b.acao === 'cadastrarCv') {
            if (codigo) {
                irPara('cv');
                abrirFormDaCandidatura(codigo);
            } else {
                abrirMeuFormCv();
            }
        } else if (b.acao === 'abrirVaga' && codigo) {
            irPara('vagas', 'aberta');
            abrirVaga(codigo);
        } else {
            // "verVagas": a aba Vagas no filtro Abertas.
            irPara('vagas', 'aberta');
        }
    }

    /* ---------------------------------------------------------------- lembrete diário (l.404 a 422) */

    /** Os quatro estados do lembrete (`dia`, l.1057 a 1060), com o que o servidor calculou. */
    function textosDoLembrete(L) {
        var pct = Math.max(0, Math.min(100, parseInt(L.pct, 10) || 0));
        var faltas = Array.isArray(L.faltas) ? L.faltas : [];
        var abertas = +L.vagasAbertas || 0;
        if (L.estado === 'cadastrar') {
            return { ic: 'bi-person-vcard', cor: '#0a6fa0', tit: 'Cadastre seu currículo para concorrer às vagas', txt: 'Os escritórios da rede BlueJus só recebem candidaturas de quem tem currículo cadastrado. Leva poucos minutos e você usa o mesmo currículo em todas as vagas.', btn: 'Cadastrar meu currículo', pct: null };
        }
        if (L.estado === 'completar') {
            return { ic: 'bi-graph-up-arrow', cor: '#c76a12', tit: 'Seu currículo está ' + pct + '% completo', txt: 'Para concorrer em igualdade às vagas, mantenha o currículo atualizado. Falta: ' + faltas.slice(0, 3).join(', ') + (faltas.length > 3 ? ' e mais ' + (faltas.length - 3) : '') + '.', btn: 'Completar agora', pct: pct };
        }
        if (L.estado === 'revisar') {
            return { ic: 'bi-arrow-repeat', cor: '#9a5b00', tit: 'Seu currículo não é atualizado há ' + (+L.dias || 0) + ' dias', txt: 'Currículos atualizados aparecem primeiro para os escritórios. Revise experiência, cargo pretendido e disponibilidade antes de concorrer.', btn: 'Revisar meu currículo', pct: null };
        }
        return { ic: 'bi-patch-check', cor: '#1e7b45', tit: 'Seu currículo está em dia', txt: abertas ? 'Há ' + abertas + ' vaga(s) aberta(s) agora. Seu currículo está pronto para você se candidatar.' : 'Ótimo! Assim que surgir uma vaga da sua área, você já pode se candidatar.', btn: abertas ? 'Ver vagas abertas' : '', pct: null };
    }

    /**
     * Uma vez por dia pela data de Brasília (l.1055): a marca vai no navegador, por usuário, ao agendar.
     * Nunca na prévia, para o interno nem na visita com boas-vindas.
     */
    function agendarLembrete() {
        var L = S.eng && S.eng.lembrete;
        if (!L || previa() || !ehExterno() || ORIGEM_LINK || S.bv) {
            return;
        }
        var chave = 'bj-te-dia-' + euId(), hoje = hojeEmBrasilia();
        if (lerLocal(chave, '') === hoje) {
            return;
        }
        gravarLocal(chave, hoje);
        setTimeout(function () {
            if (previa() || S.bv) {
                return;
            }
            S.dia = textosDoLembrete(L);
            S.dia.estado = L.estado;
            renderLembrete();
        }, 900);
    }

    function renderLembrete() {
        var alvo = limpar(caixaDe('teLembrete')), T = S.dia;
        if (!T) {
            return;
        }
        var h = horaEmBrasilia();
        alvo.appendChild(el('div', { class: 'te-eng-dia-fundo', onclick: fecharLembrete }));
        alvo.appendChild(el('div', { class: 'te-eng-dia-pos' }, el('div', {
            class: 'te-eng-dia', style: { '--te-eng-dia-cor': T.cor },
            attrs: { role: 'dialog', 'aria-modal': 'true', 'aria-label': T.tit }
        }, [
            el('div', { class: 'te-eng-dia-faixa' }),
            el('button', { type: 'button', class: 'te-eng-dia-fechar', title: 'Fechar', onclick: fecharLembrete }, ic('bi-x-lg')),
            el('div', { class: 'te-eng-dia-corpo' }, [
                el('span', { class: 'te-eng-dia-ic' }, ic(T.ic)),
                el('div', { class: 'te-eng-dia-sau', text: (h < 12 ? 'Bom dia' : h < 18 ? 'Boa tarde' : 'Boa noite') + '!' }),
                el('div', { class: 'te-eng-dia-tit', text: T.tit }),
                el('div', { class: 'te-eng-dia-txt', text: T.txt }),
                T.pct !== null && T.pct < 100 ? el('div', { class: 'te-eng-dia-trilho' }, el('div', { class: 'te-eng-dia-barra', style: { width: T.pct + '%' } })) : null,
                el('div', { class: 'te-eng-dia-botoes' }, [
                    el('button', { type: 'button', class: 'te-eng-sec', text: 'Agora não', onclick: fecharLembrete }),
                    T.btn ? el('button', { type: 'button', class: 'te-eng-pri', onclick: irDoLembrete }, [ic('bi-arrow-right-circle'), T.btn]) : null
                ]),
                el('div', { class: 'te-eng-dia-rodape', text: 'Este lembrete aparece uma vez por dia.' })
            ])
        ])));
    }

    function fecharLembrete() {
        S.dia = null;
        var c = $('teLembrete');
        if (c) {
            limpar(c);
        }
    }

    /** `diaIr` (l.1061): currículo a cadastrar, completar ou revisar abre o formulário; em dia, as vagas. */
    function irDoLembrete() {
        var T = S.dia;
        fecharLembrete();
        if (T && T.estado !== 'emDia') {
            abrirMeuFormCv();
        } else {
            irPara('vagas');
        }
    }

    /* ---------------------------------------------------------------- QUASE da oferta perdida (l.1012, 1069) */

    function chaveDasOfertas() { return 'bj-te-ofertas-' + euId(); }

    function ofertaViva(v) { return !!(v && (v.vivo || (v.oferta && v.situacao === 'aberta'))); }
    function jaFoiNela(v) { return !!(v && (v.jaFoi || v.minhaCandidatura || v.botaoExterno === 'enviada')); }

    /** Guarda a oferta aberta que o externo abriu (sem candidatura dele e que não é dele). */
    function lembrarOferta(v) {
        if (previa() || vgInterno() || !ofertaViva(v) || jaFoiNela(v) || v.minha) {
            return;
        }
        var o = lerLocal(chaveDasOfertas(), {});
        if (!o || typeof o !== 'object') {
            o = {};
        }
        if (!o[v.codigo]) {
            o[v.codigo] = 1;
            gravarLocal(chaveDasOfertas(), o);
        }
    }

    function esquecerOferta(codigo) {
        if (previa() || !codigo) {
            return;
        }
        var o = lerLocal(chaveDasOfertas(), {});
        if (o && typeof o === 'object' && o[codigo]) {
            delete o[codigo];
            gravarLocal(chaveDasOfertas(), o);
        }
    }

    function lerOfertas() {
        var o = lerLocal(chaveDasOfertas(), {});
        return o && typeof o === 'object' && !Array.isArray(o) ? o : {};
    }

    /**
     * Antes do GET do engajamento: soma as ofertas vistas na lista (`F(v.id).lida`, l.1069), esquece as
     * que já têm candidatura da pessoa (ou são dela) e devolve os códigos a perguntar ao servidor
     * (`?ofertas=`). As que seguem abertas na lista não precisam de pergunta.
     */
    function ofertasAPerguntar() {
        if (previa() || !S.vg || vgInterno() || !ehExterno()) {
            return [];
        }
        var o = lerOfertas(), mudou = false;
        vagas().forEach(function (v) {
            if (v.vista && ofertaViva(v) && !jaFoiNela(v) && !v.minha && !o[v.codigo]) {
                o[v.codigo] = 1;
                mudou = true;
            }
        });
        var minhasVagas = minhas().map(function (a) { return a.codigoVaga; });
        var perguntar = [];
        Object.keys(o).forEach(function (codigo) {
            var v = vagaDe(codigo);
            if (!/^VG-\d{1,9}$/.test(codigo) || (v && (jaFoiNela(v) || v.minha)) || minhasVagas.indexOf(codigo) >= 0) {
                delete o[codigo];
                mudou = true;
            } else if (!(v && ofertaViva(v))) {
                perguntar.push(codigo);
            }
        });
        if (mudou) {
            gravarLocal(chaveDasOfertas(), o);
        }
        return perguntar.slice(0, 50);
    }

    /**
     * Resposta `ofertas: {codigo: situacao}` do servidor para os códigos perguntados: preenchida mostra
     * o QUASE com todas as frases; expirada, sem as duas de "preenchida"; pausada e encerrada (ou
     * ausente da resposta) são esquecidas sem modal; aberta continua guardada. Uma vez só, com `motiv`.
     */
    function conferirOfertasPerdidas(perguntadas, situacoes) {
        if (previa() || !perguntadas || !perguntadas.length) {
            return;
        }
        var R = situacoes && typeof situacoes === 'object' ? situacoes : {};
        var o = lerOfertas(), perdidas = [];
        perguntadas.forEach(function (codigo) {
            var sit = R[codigo];
            if (sit === 'aberta') {
                return;
            }
            if (!o[codigo]) {
                return;
            }
            if (sit === 'preenchida' || sit === 'expirada') {
                perdidas.push(sit);
            }
            delete o[codigo];
        });
        gravarLocal(chaveDasOfertas(), o);
        if (perdidas.length && S.eng && S.eng.motiv && !S.quase) {
            abrirQuase({ semPreenchida: perdidas.indexOf('preenchida') < 0 });
        }
    }

    /** "Ver exemplo de mensagem" do modal de configuração (l.1099): o `quase()` só abre com as mensagens ligadas. */
    function verExemploDoQuase() {
        if ((cfgVagas() || {}).motiv) {
            abrirQuase();
        }
    }

    /* ---------------------------------------------------------------- endereços (#mod=, #vaga=, #cv=, #camp=) */

    /**
     * `#mod=vagas|cv|dem` abre a aba; `#vaga=VG-n` a vaga; `#cv=CV-n` o currículo (interno) ou
     * "Meu currículo" (externo). Usados pelos avisos do sino. `#camp=1` abre a aba Vagas com a campanha
     * por e-mail do Master (l.1078; para os outros perfis, só a aba). O hash sai do endereço depois de usado.
     * `inicial`: na carga a aba só é escolhida (as listas ainda vão chegar) e o item espera as listas.
     */
    function lerHash() {
        var h = String(location.hash || '').replace(/^#/, '');
        if (!h) {
            return null;
        }
        var p = new URLSearchParams(h);
        var r = { mod: moduloDaChave(p.get('mod')), vaga: p.get('vaga'), cv: p.get('cv'), camp: p.get('camp') === '1' };
        r.vaga = r.vaga && /^VG-\d{1,9}$/.test(r.vaga) ? r.vaga : null;
        r.cv = r.cv && /^CV-\d{1,9}$/.test(r.cv) ? r.cv : null;
        if (!r.mod && !r.vaga && !r.cv && !r.camp) {
            return null;
        }
        try {
            history.replaceState(null, '', location.pathname + location.search);
        } catch (e) {
            // Sem history: o hash fica, e o próximo hashchange com o mesmo valor não dispara.
        }
        return r;
    }

    function modDoHash(H) {
        return H.mod || (H.vaga || H.camp ? 'vagas' : H.cv ? 'cv' : null);
    }

    function abrirItemDoHash(H) {
        if (H.vaga) {
            abrirVaga(H.vaga);
        } else if (H.cv && perfil().interno) {
            abrirCv(H.cv);
        } else if (H.camp) {
            abrirCampanha();
        }
    }

    function aplicarHash() {
        var H = lerHash();
        if (!H) {
            return;
        }
        var m = modDoHash(H);
        if (m) {
            irPara(m);
        }
        abrirItemDoHash(H);
    }

    window.addEventListener('hashchange', aplicarHash);

    /* ---------------------------------------------------------------- primeira carga */

    /**
     * Depois das listas e do primeiro engajamento: boas-vindas do link (externo), "Divulgar uma vaga na
     * Central" com `divulgar=1`, o item do endereço e o lembrete do dia.
     */
    function depoisDaPrimeiraCarga(E, H) {
        S.engIniciado = true;
        if (H) {
            abrirItemDoHash(H);
        }
        if (previa() || !ehExterno()) {
            return;
        }
        if (QS.get('divulgar') === '1') {
            if (S.vg && S.vg.podeDivulgar) {
                irPara('vagas', 'aberta');
                abrirFormVaga(null);
            }
            return;
        }
        if (ORIGEM_LINK && E && E.boasVindas) {
            abrirBoasVindas(E.boasVindas);
            return;
        }
        agendarLembrete();
    }

    function iniciarEngajamento() {
        var H = lerHash();
        if (H && modDoHash(H)) {
            S.modulo = moduloDaChave(modDoHash(H));
            salvarModulo(S.modulo);
        } else if (ORIGEM_LINK && ehExterno()) {
            // O link de vagas abre a aba Vagas no filtro Abertas (l.1078).
            S.modulo = 'vagas';
            S.vgFiltro = 'aberta';
            salvarModulo('vagas');
        }
        return H;
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
        if (S.envFx) {
            fecharEnvio();
        } else if (S.conf) {
            fecharConf();
        } else if (S.quase) {
            fecharQuase();
        } else if (S.bv) {
            fecharBoasVindas();
        } else if (S.dia) {
            fecharLembrete();
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
    var hashInicial = iniciarEngajamento();
    renderTudo();
    // A aba Vagas só aparece depois que o servidor disser `veAba`; sem resposta, fica só Demandas.
    // O engajamento (§4.7) vem depois das duas listas; dali em diante recarrega junto com elas.
    if (!S.painel.bloqueio) {
        Promise.all([carregarVagas(), carregarCurriculos()]).then(function () {
            return carregarEngajamento(true);
        }).then(function (E) {
            depoisDaPrimeiraCarga(E, hashInicial);
        }, function () {
            S.engIniciado = true;
        });
    }

    if (S.painel.avisoDeMensagem) {
        var a = S.painel.avisoDeMensagem;
        mostrarToast({ id: a.codigo, tit: a.titulo, txt: a.texto, hora: a.hora });
    }

    conferirAndamentos();
    setInterval(conferirAndamentos, ANDAMENTOS_MS);

    var te = new URLSearchParams(location.search).get('te');
    if (te && demandas().some(function (d) { return d.codigo === te; })) {
        abrir(te);
    }
})();
