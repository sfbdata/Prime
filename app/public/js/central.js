/*
 * Central de Comunicação — A1 (docs/specs/central-comunicacao-a1.md) e A2 (central-comunicacao-a2.md): @menção,
 * responder, marcar como não lida e os menus de botão direito da conversa e da mensagem.
 *
 * Tudo vem do servidor (rotas central_*); nada de dado de conversa no navegador além do que está na tela.
 * Conteúdo de mensagem e nomes entram SÓ por textContent — nunca innerHTML. O token CSRF 'ajax' é injetado
 * em todo fetch same-origin pelo interceptador do layout. Polling (D2): 5 s com a aba visível, parado com
 * ela oculta, retomado ao voltar.
 */
(function () {
    'use strict';

    var POLLING_MS = 5000;
    var POLLING_FECHADA_MS = 30000;   // lançador flutuante com a janela fechada: só o contador precisa andar
    var TITULO = 'Central de Comunicação';
    // Token canônico de menção (o mesmo de MencoesDaCentral::PADRAO): @[Nome](user:ID).
    var PADRAO_MENCAO = /@\[([^\[\]<>]{1,120})\]\(user:(\d{1,10})\)/g;
    var DIGITANDO_MENCAO = /@([^\s@]{0,30})$/;   // L6075
    var MAX_SUGESTOES = 6;                       // L6094
    var CORES_NOME = ['#0a6fa8', '#8a4fb5', '#1e7b45', '#b4460a', '#a8326e', '#2f6f73'];   // L5814
    var AVISO_MS = 2600;                         // aviso(), L5398
    // F2, presença (L45, L279): status e cores do desenho; batimento a cada 20 s (on-line = menos de 60 s, no servidor).
    // `inativo` (ausente por falta de atividade, spec presenca-e-ponto-no-cabecalho §2) aparece aqui como o Ausente da Central.
    var STATUS = { disponivel: ['Disponível', '#3fd17f'], ocupado: ['Ocupado', '#e5484d'], ausente: ['Ausente', '#f2a93b'], inativo: ['Ausente', '#f2a93b'], offline: ['Off-line', '#b7c4cd'] };
    var BATIMENTO_MS = 20000;
    // Resolvidas (RES_JANELA, L91): com a lista à vista, o tempo da baixa anda a cada minuto sem ir ao servidor.
    var RELOGIO_DAS_RESOLVIDAS_MS = 60000;
    // Encaminhar via Chat I.A (docs/specs/encaminhar-via-chat-ia.md §4.1).
    // Ícone do cartão por tipo (BC:6221) e os nossos tipos (TipoDeItemEncaminhado) levados aos do desenho.
    var ICONE_DO_DESPACHO = { meta: 'bi-check2-square', documento: 'bi-file-earmark-text', push: 'bi-broadcast', registro: 'bi-journal-text', pasta: 'bi-folder2', processo: 'bi-bank' };
    var TIPO_DO_DESENHO = { registro_dados: 'registro', registro_detalhes: 'registro', registro_financeiro: 'registro', secao: 'pasta', prazo: 'meta', nota_tecnica: 'push' };
    var PRIORIDADES = [['normal', 'Normal'], ['urgente', 'Urgente'], ['para_hoje', 'Para hoje']];   /* BC:6226 ('hoje' do desenho = para_hoje) */
    var INSTRUCAO_MAXIMA = 1000;   /* EncaminharItemInput::INSTRUCAO_MAXIMA */

    function corNome(id) {   // L5815 — a mesma cor para a mesma pessoa
        var h = String(id).split('').reduce(function (t, ch) { return (t * 31 + ch.charCodeAt(0)) | 0; }, 7);
        return CORES_NOME[Math.abs(h) % CORES_NOME.length];
    }

    function iniciar(raiz) {
        var urls = {
            conversas: raiz.dataset.urlConversas,
            contatos: raiz.dataset.urlContatos,
            novidades: raiz.dataset.urlNovidades,
            base: raiz.dataset.urlConversaBase,
            grupos: raiz.dataset.urlGrupos,
            equipe: raiz.dataset.urlEquipe,
            avisos: raiz.dataset.urlAvisos,
            lerTudo: raiz.dataset.urlLerTudo,
            limparLidos: raiz.dataset.urlLimparLidos,
            presenca: raiz.dataset.urlPresenca,
            encaminharItem: raiz.dataset.urlEncaminharItem,          /* Encaminhar via Chat I.A (spec §4.1) */
            encaminharDestinos: raiz.dataset.urlEncaminharDestinos,
            encaminhar: raiz.dataset.urlEncaminhar,
            pastaBase: raiz.dataset.urlPastaBase,   /* "/pasta/": o chip "Pasta N" abre a pasta (BC:6041) */
            iaPergunta: raiz.dataset.urlIaPergunta,   /* F3: POST /central/ia/perguntas */
            iaPastaBase: raiz.dataset.urlIaPastaBase,   /* PR 4b: GET /central/ia/pasta/{id} (a base termina em "/") */
            iaStatus: raiz.dataset.urlIaStatus        /* F3: GET /central/ia/perguntas/{analiseId} (a base termina em "/") */
        };
        // F3, conversa com o BlueJus IA: o texto vem do Twig (translations central.ia.*), nunca fixo aqui.
        var ia = {
            nome: raiz.dataset.iaNome || 'BlueJus IA',
            selo: raiz.dataset.iaSelo || 'IA',
            setor: raiz.dataset.iaSetor || '',
            funcao: raiz.dataset.iaFuncao || '',
            vazioTitulo: raiz.dataset.iaVazioTitulo || '',
            vazioTexto: raiz.dataset.iaVazioTexto || '',
            demora: raiz.dataset.iaDemora || '',
            digitando: raiz.dataset.iaDigitando || '',
            aviso: raiz.dataset.iaAviso || '',
            placeholder: raiz.dataset.iaPlaceholder || '',
            placeholderAguardando: raiz.dataset.iaPlaceholderAguardando || '',
            previaVazia: raiz.dataset.iaPreviaVazia || '',
            aria: raiz.dataset.iaAria || '',
            /* PR 4b: a pasta aberta na tela é só uma DICA (o servidor revalida o rótulo e a pergunta) */
            pastaId: /^[1-9][0-9]{0,8}$/.test(raiz.dataset.iaPasta || '') ? Number(raiz.dataset.iaPasta) : 0,
            sugestaoPasta: raiz.dataset.iaSugestaoPasta || '',
            pastaRotulo: raiz.dataset.iaPastaRotulo || '',
            abrirPasta: raiz.dataset.iaAbrirPasta || '',
            pastaAria: raiz.dataset.iaPastaAria || '',
            sugestoes: []
        };
        try {
            var listaSugestoes = JSON.parse(raiz.dataset.iaSugestoes || '[]');
            ia.sugestoes = Array.isArray(listaSugestoes) ? listaSugestoes.filter(function (t) { return typeof t === 'string' && t !== ''; }) : [];
        } catch (e) { ia.sugestoes = []; }
        var NS_SVG = 'http://www.w3.org/2000/svg';
        var meuNome = raiz.dataset.euNome || '';
        var TAMANHO_MAXIMO_DA_PERGUNTA = 2000;   /* PerguntarABlueJusIaInput */
        var ESPERA_MAXIMA_DA_IA = 11 * 60 * 1000;   /* os 10 min do servidor + folga: depois disso a pergunta presa não conta mais */
        var tamanhoMaximo = Number(raiz.dataset.tamanhoMaximo) || 4000;   // Mensagem::TAMANHO_MAXIMO
        var el = {
            voltar: raiz.querySelector('[data-central-voltar]'),
            cabAvatar: raiz.querySelector('[data-central-cab-avatar]'),
            cabEu: raiz.querySelector('[data-central-cab-eu]'),
            cabPonto: raiz.querySelector('[data-central-cab-ponto]'),
            online: raiz.querySelector('[data-central-online]'),
            onlineTexto: raiz.querySelector('[data-central-online-texto]'),
            cabTitulo: raiz.querySelector('[data-central-cab-titulo]'),
            cabSub: raiz.querySelector('[data-central-cab-sub]'),
            telaLista: raiz.querySelector('[data-central-tela-lista]'),
            telaConversa: raiz.querySelector('[data-central-tela-conversa]'),
            busca: raiz.querySelector('[data-central-busca]'),
            lista: raiz.querySelector('[data-central-lista]'),
            corpo: raiz.querySelector('[data-central-corpo]'),
            compositor: raiz.querySelector('[data-central-compositor]'),
            campo: raiz.querySelector('[data-central-campo]'),
            enviar: raiz.querySelector('[data-central-enviar]'),
            rodape: raiz.querySelector('[data-central-rodape]'),
            mencoes: raiz.querySelector('[data-central-mencoes]'),
            respondendo: raiz.querySelector('[data-central-respondendo]'),
            respondendoRotulo: raiz.querySelector('[data-central-respondendo-rotulo]'),
            respondendoTrecho: raiz.querySelector('[data-central-respondendo-trecho]'),
            respondendoCancelar: raiz.querySelector('[data-central-respondendo-cancelar]'),
            info: raiz.querySelector('[data-central-info]'),
            mais: raiz.querySelector('[data-central-mais]'),
            faixaArquivadas: raiz.querySelector('[data-central-faixa-arquivadas]'),
            ocultarArquivadas: raiz.querySelector('[data-central-ocultar-arquivadas]'),
            telaNovoGrupo: raiz.querySelector('[data-central-tela-novo-grupo]'),
            telaInfo: raiz.querySelector('[data-central-tela-info]'),
            telaDespacho: raiz.querySelector('[data-central-tela-despacho]'),
            abas: raiz.querySelectorAll('[data-central-aba]'),
            abaContador: raiz.querySelector('[data-central-aba-contador]'),
            filtros: raiz.querySelector('[data-central-filtros]'),
            botoesFiltro: raiz.querySelectorAll('[data-central-filtro]'),
            painel: raiz.querySelector('[data-central-painel]')
        };
        // Página /central: a janela está sempre à vista. Flutuante: começa fechada (o desenho não lembra "aberto").
        var flutuante = raiz.dataset.modo === 'flutuante';
        var estado = {
            conversas: [],
            contatos: [],
            naoLidas: 0,
            cursor: null,          // null = ainda não inicializado (a primeira chamada vai sem `desde`)
            aberta: null,          // {id, outro, outroAtivo, primeiraId, ultimaId, temMais}
            timer: null,
            enviando: false,
            erro: false,
            semSessao: false,
            respondendo: null,     // {id, autorNome, trecho, minha}
            escolhidas: [],        // menções escolhidas no autocomplete: [{id, nome}]
            sugestoes: null,       // {itens, indice, inicio} quando o autocomplete está aberto
            menu: null,            // {fundo, caixa, origem} quando um menu de contexto está aberto
            painelMenu: null,      // B1: {caixa} quando o ⋮ está aberto (menu(), L6244)
            confirma: null,        // B1: {fundo, origem} quando a confirmação da janela está aberta (L6394)
            tela: 'lista',         /* B: 'lista' | 'conversa' | 'novoGrupo' | 'info' | 'despacho' */
            despacho: null,        /* Encaminhar: {pastaId, tipo, itemId, prio, enviando} com a tela de despacho à vista (ui.despacho, BC:6645) */
            verArquivadas: false,  // B: "Ver conversas arquivadas" (desenho L6260)
            vistas: new Set(),     // B: ids já entregues — a janela do polling repete as recentes (spec B §6)
            aba: 'entrada',        // lançador: 'entrada' | 'painel' (desenho L6366)
            filtro: '',            // lançador: '' (tudo) | 'mencoes' (L5776)
            comMencao: new Set(),  // conversas com menção por ver (servidor: conversasComMencao)
            janelaAberta: !flutuante,
            avisos: [],            // F1: avisos do sino na Caixa (GET /central/avisos)
            avisosNaoLidos: 0,     // F1: contagens que vêm no polling (sem a lista)
            avisosUrgentes: 0,
            avisosImportantes: 0,
            verResolvidas: false,  // F3: ⋮ "Ver resolvidas antigas" (L6261)
            ajuda: false,          // F3: ⋮ "Ajuda" (L6372)
            chegaramImportantes: 0,   // F3: desde o último aviso ao lançador, quantos de nível ≥ 2 (menção e aviso importante)
            ultimoAviso: null,     // F1: maior id; quando muda, a lista recarrega
            equipe: null,          // F2: {equipe, online, total} de /central/equipe (Painel e "N on-line")
            verOnline: false,      // F2: lista "on-line agora / off-line" (L6380)
            externa: null,         // B2: a janela destacada (Picture-in-Picture ou pop-up) em que a Central está agora
            iaPasta: null,         // PR 4b: {pastaId, nup, cliente} da barra "Pasta N" (null = sem barra e sem pastaId na pergunta)
            iaPastaTentada: false, // o rótulo é buscado uma vez (sucesso ou 404)
            iaAguardando: null,    // F3: {analiseId, conversaId, semFala} da pergunta à IA ainda sem resposta (mostra digitando...)
            iaConsultando: false,  // F3: uma consulta de status por vez
            relogio: window,       // B2: de qual janela é o setInterval do polling (a destacada, quando houver)
            timerResolvidas: null, // F3: o minuto das resolvidas (no mesmo relógio do polling)
            resolvidasDesenhadas: 0, // F3: quantas resolvidas dentro da janela o último desenho da lista mostrou
            listaCarregada: false  // flutuante: lista e contatos só carregam na primeira abertura (carga por página)
        };
        var abrirAoCarregar = Number(raiz.dataset.abrirConversa) || null;   // ?conversa=N (link da menção)

        // ── utilitários ──────────────────────────────────────────────────────────────
        // B2: destacada, a Central mora no documento de OUTRA janela. Medidas, foco e "está à vista" são dela.
        function vista() {
            return raiz.ownerDocument.defaultView || window;
        }

        // A Central (na página ou na janela destacada) não está à vista: não marca nada como lido.
        function centralOculta() {
            return estado.externa ? estado.externa.document.hidden : document.hidden;
        }

        // Nada do BlueJus à vista: nem a Central nem a página com o lançador. Só então o polling para. (Destacada num
        // pop-up minimizado, a página continua à vista e o lançador dela precisa do contador.)
        function oculta() {
            return estado.externa ? estado.externa.document.hidden && document.hidden : document.hidden;
        }

        function criar(tag, classe, texto) {
            var n = document.createElement(tag);
            if (classe) { n.className = classe; }
            if (texto !== undefined && texto !== null) { n.textContent = texto; }
            return n;
        }

        function iniciais(nome) {   // ini(), L34: duas primeiras palavras
            return (nome || '').trim().split(/\s+/).slice(0, 2).map(function (p) {
                return p.charAt(0).toUpperCase();
            }).join('');
        }

        function quando(iso) {      // quando(), L5403
            var d = new Date(iso);
            var seg = (Date.now() - d.getTime()) / 1000;
            if (seg < 60) { return 'agora'; }
            if (seg < 3600) { return Math.floor(seg / 60) + ' min'; }
            var hhmm = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
            var hoje = new Date();
            if (d.toDateString() === hoje.toDateString()) { return hhmm; }
            return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + ' ' + hhmm;
        }

        function horaDe(iso) {
            var d = new Date(iso);
            return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        }

        function rotuloDoDia(iso) {
            var d = new Date(iso);
            if (d.toDateString() === new Date().toDateString()) { return 'Hoje'; }
            return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + '/' + d.getFullYear();
        }

        // Texto com menções: o token vira "@Nome" (prévia, citação, cópia) ou um <span> destacado (balão).
        function comoTexto(conteudo) {
            return String(conteudo || '').replace(PADRAO_MENCAO, '@$1');
        }

        function pintarTexto(alvo, conteudo) {   // pintarTexto, L5856 — só nós de texto e <span>, nunca HTML
            var texto = String(conteudo || '');
            var re = new RegExp(PADRAO_MENCAO.source, 'g');
            var ultimo = 0;
            var achado;
            while ((achado = re.exec(texto)) !== null) {
                if (achado.index > ultimo) { alvo.appendChild(document.createTextNode(texto.slice(ultimo, achado.index))); }
                var mencao = criar('span', 'bj-central-mencao', '@' + achado[1]);
                mencao.dataset.userId = achado[2];
                alvo.appendChild(mencao);
                ultimo = re.lastIndex;
            }
            if (ultimo < texto.length) { alvo.appendChild(document.createTextNode(texto.slice(ultimo))); }
        }

        function primeiroNome(nome) {
            return String(nome || '').trim().split(/\s+/)[0] || '';
        }

        function semAcento(t) {
            return String(t || '').toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }

        function pedir(url, opcoes) {
            opcoes = opcoes || {};
            var init = { method: opcoes.metodo || 'GET', headers: { 'Accept': 'application/json' }, credentials: 'same-origin' };
            if (opcoes.corpo !== undefined) {
                init.headers['Content-Type'] = 'application/json';
                init.body = JSON.stringify(opcoes.corpo);
            }
            return fetch(url, init).then(function (r) {
                // Sessão que expirou ou vínculo removido: a plataforma responde com redirecionamento para uma
                // página HTML, que o fetch segue e devolve como 200. Sem esta guarda o polling seguiria batendo
                // no redirecionamento, calado, com o cursor virando NaN.
                // Só o REDIRECIONAMENTO (ou o 403 da própria Central) encerra a sessão. Uma página de erro em HTML
                // (500, 502/503 durante um deploy, 403 de CSRF) é falha passageira: "Sem conexão" e nova tentativa.
                if (r.redirected) {
                    var semSessao = new Error('Sua sessão nesta Central terminou. Recarregue a página para continuar.');
                    semSessao.sessao = true;
                    throw semSessao;
                }
                var tipo = r.headers.get('Content-Type') || '';
                if (tipo.indexOf('application/json') === -1) {
                    var passageira = new Error('A Central não respondeu. Tente de novo em instantes.');
                    passageira.status = r.status;
                    throw passageira;
                }
                return r.json().catch(function () { return {}; }).then(function (dados) {
                    if (!r.ok) {
                        // 401 = sessão vencida: o login responde em JSON aos pedidos de dados (não redireciona mais).
                        var erro = new Error(r.status === 401
                            ? 'Sua sessão nesta Central terminou. Recarregue a página para continuar.'
                            : (dados.erro || dados.mensagem || 'Falha na Central.'));
                        erro.status = r.status;
                        erro.codigo = dados.codigo || dados.motivo || null;
                        // 403 sem `codigo` = sem vínculo com o escritório (sessão); com `sem_permissao_no_grupo` é regra
                        // do grupo (B): a sessão continua.
                        erro.sessao = r.status === 401 || (r.status === 403 && dados.codigo !== 'sem_permissao_no_grupo');
                        throw erro;
                    }
                    return dados;
                });
            });
        }

        function vazio(icone, titulo, sub) {   // vazio(), L5659
            var caixa = criar('div', 'bj-central-vazio');
            var i = criar('i', 'bi ' + icone);
            i.setAttribute('aria-hidden', 'true');
            caixa.appendChild(i);
            caixa.appendChild(criar('div', 'bj-central-vazio-titulo', titulo));
            if (sub) { caixa.appendChild(criar('div', 'bj-central-vazio-sub', sub)); }
            return caixa;
        }

        // ── cabeçalho ────────────────────────────────────────────────────────────────
        function plural(n, um, varios) {
            return n === 1 ? '1 ' + um : n + ' ' + varios;
        }

        function nomeDaConversa(c) {
            if (ehIa(c)) { return ia.nome; }
            return c.tipo === 'grupo' ? (c.nome || 'Grupo') : (c.outro ? c.outro.nome : '');
        }

        // ── BlueJus IA (F3): a conversa vem na lista com `ia: true`, `tipo: 'ia'` e `outro: null` ──────────────
        function ehIa(c) {
            return !!c && (c.ia === true || c.tipo === 'ia');
        }

        function avatarIa(classe) {   // avatar(), L5406-5407: círculo 135deg #0c7a9c→#0a5f86 e a estrela do IA_SVG (L5405)
            var av = criar('span', 'bj-central-avatar bj-central-avatar--ia' + (classe ? ' ' + classe : ''));
            av.setAttribute('aria-hidden', 'true');
            av.appendChild(estrelaIa());
            return av;
        }

        function estrelaIa() {
            var svg = document.createElementNS(NS_SVG, 'svg');
            svg.setAttribute('viewBox', '0 0 24 24');
            svg.setAttribute('width', '60%');
            svg.setAttribute('height', '60%');
            var estrela = document.createElementNS(NS_SVG, 'path');
            estrela.setAttribute('d', 'M12 2.5l1.9 5.6 5.6 1.9-5.6 1.9L12 17.5l-1.9-5.6L4.5 10l5.6-1.9z');
            estrela.setAttribute('fill', '#fff');
            svg.appendChild(estrela);
            [['18.5', '17.5', '1.6'], ['6', '18.5', '1.1']].forEach(function (c) {
                var bolinha = document.createElementNS(NS_SVG, 'circle');
                bolinha.setAttribute('cx', c[0]);
                bolinha.setAttribute('cy', c[1]);
                bolinha.setAttribute('r', c[2]);
                bolinha.setAttribute('fill', '#bfe6f7');
                svg.appendChild(bolinha);
            });
            return svg;
        }

        function pintarAvatarDoCabecalho() {   // IA: a estrela no lugar das iniciais; as demais voltam às iniciais
            var comIa = !!(estado.aberta && estado.aberta.ia);
            el.cabAvatar.classList.toggle('bj-central-avatar--ia', comIa);
            if (comIa) {
                el.cabAvatar.replaceChildren(estrelaIa());
            } else if (estado.aberta) {
                el.cabAvatar.textContent = iniciais(estado.aberta.nome);
            }
        }

        function atualizarCabecalho() {
            // O ⋮ fica no cabeçalho da lista e da conversa, como no desenho (L6339); some nas telas de grupo.
            // A tela de despacho fica sob o cabeçalho da lista, como no desenho (BC:6330: sem conversa, o da lista).
            var comoLista = estado.tela === 'lista' || estado.tela === 'despacho';
            el.mais.hidden = !comoLista && estado.tela !== 'conversa';
            el.cabEu.hidden = !(comoLista && !estado.aberta);
            // F2: ponto do MEU status no avatar e a pílula "N on-line" (L6337), só no cabeçalho da lista.
            var meu = statusManual();
            el.cabPonto.style.background = STATUS[meu][1];
            el.cabPonto.title = STATUS[meu][0];
            el.online.hidden = !(comoLista && !estado.aberta && estado.equipe);
            if (estado.equipe) {
                el.onlineTexto.textContent = estado.equipe.online + ' on-line';
                el.online.title = 'Ver quem está on-line: ' + estado.equipe.equipe.filter(function (m) { return m.online; })
                    .map(function (m) { return m.nome; }).join(', ');
                el.online.setAttribute('aria-expanded', String(estado.verOnline));
            }
            el.info.hidden = !(estado.tela === 'conversa' && estado.aberta && estado.aberta.grupo && !estado.aberta.fora);
            if (estado.tela === 'novoGrupo' || estado.tela === 'info') {
                el.voltar.hidden = false;
                el.cabAvatar.hidden = true;
                el.cabTitulo.textContent = estado.tela === 'novoGrupo' ? 'Novo grupo' : 'Informações do grupo';
                el.cabSub.textContent = estado.tela === 'info' && estado.aberta ? estado.aberta.nome : '';
                return;
            }
            if (estado.aberta) {
                el.voltar.hidden = false;
                pintarAvatarDoCabecalho();
                el.cabAvatar.hidden = false;
                el.cabTitulo.textContent = estado.aberta.nome;
                // O desenho mostra presença e setor · função (L6334), que a Central não tem; só o que é verdade.
                // Conversa 1:1 (L6334): "on-line · cargo" com a presença real (F2); o setor não existe aqui.
                var outro = !estado.aberta.grupo && estado.aberta.outro ? membroDaEquipe(estado.aberta.outro.id) : null;
                el.cabSub.textContent = estado.aberta.ia
                    ? [ia.setor, ia.funcao].filter(Boolean).join(' · ')   // convInfo, L304: setor · função, sem presença nem ligar
                    : estado.aberta.grupo
                    ? plural(estado.aberta.participantes, 'participante', 'participantes')
                    : (!estado.aberta.outroAtivo ? 'Saiu do escritório'
                        : outro ? [outro.online ? 'on-line' : '', outro.cargo || ''].filter(Boolean).join(' · ') : '');
                return;
            }
            el.voltar.hidden = true;
            el.cabAvatar.hidden = true;
            el.cabTitulo.textContent = TITULO;
            // Subtítulo do desenho (L6338): "nome · N não lidas | em dia | não perturbe". Status manual não existe aqui.
            var silencio = typeof raiz.bjSilencio === 'function' && raiz.bjSilencio();
            var nl = totalCaixa();   // L6338: mensagens + avisos
            el.cabSub.textContent = meuNome + ' · ' + (silencio ? 'não perturbe' : nl > 0
                ? (nl === 1 ? '1 não lida' : nl + ' não lidas')
                : 'em dia');
        }

        // ── lista ────────────────────────────────────────────────────────────────────
        function linhaDaConversa(c) {
            var naoLida = c.naoLidas > 0 || c.marcadaNaoLida;
            var b = criar('button', 'bj-central-linha' + (naoLida ? ' bj-central-linha--nao-lida' : ''));
            b.type = 'button';
            b.setAttribute('role', 'listitem');
            var grupo = c.tipo === 'grupo';
            var comIa = ehIa(c);
            var av;
            if (comIa) {
                av = avatarIa();
            } else {
                av = criar('span', 'bj-central-avatar' + (grupo ? ' bj-central-avatar--grupo' : ''), iniciais(nomeDaConversa(c)));
                av.setAttribute('aria-hidden', 'true');
            }
            b.appendChild(av);

            var textos = criar('span', 'bj-central-linha-textos');
            var topo = criar('span', 'bj-central-linha-topo');
            topo.appendChild(criar('span', 'bj-central-rotulo', comIa ? ia.setor : (grupo ? 'Grupo' : 'Conversa')));
            // Marcas, na ordem do desenho (L5676): fixada, silenciada, arquivada.
            [[c.fixada || comIa, 'bi-pin-angle-fill bj-central-marca--fixada', 'Fixada'],   /* a IA é fixa por padrão (L316/L324) */
             [c.silenciada, 'bi-bell-slash', 'Silenciada'],
             [c.arquivada, 'bi-archive', 'Arquivada']].forEach(function (m) {
                if (!m[0]) { return; }
                var ic = criar('i', 'bi bj-central-marca ' + m[1]);
                ic.title = m[2];
                ic.setAttribute('aria-label', m[2]);
                topo.appendChild(ic);
            });
            if (estado.comMencao.has(c.id)) {
                var arroba = criar('span', 'bj-central-marca bj-central-marca--mencao', '@');
                arroba.title = 'Menção por ver';
                arroba.setAttribute('aria-label', 'Menção por ver');
                topo.appendChild(arroba);
            }
            if (c.ultima) { topo.appendChild(criar('span', 'bj-central-hora', quando(c.ultima.criadaEm))); }
            textos.appendChild(topo);
            textos.appendChild(criar('span', 'bj-central-nome d-block', nomeDaConversa(c)));

            var baixo = criar('span', 'bj-central-linha-baixo');
            // L5669: "Você: " na própria; em grupo, o primeiro nome de quem escreveu; a de sistema vai sem prefixo.
            var previa = comIa ? ia.previaVazia : 'Nenhuma mensagem ainda';
            if (c.ultima) {
                var prefixo = c.ultima.sistema ? '' : (c.ultima.minha ? 'Você: ' : (grupo ? primeiroNome(c.ultima.autorNome) + ': ' : ''));
                previa = prefixo + comoTexto(c.ultima.conteudo);
            } else if (grupo) {
                previa = plural(c.participantes, 'participante', 'participantes');
            }
            /* Última mensagem é um despacho que eu não vejo: "Item de pasta sem acesso", apagado como no balão. */
            baixo.appendChild(criar('span', 'bj-central-previa' + (c.ultima && c.ultima.ocultoPorPermissao ? ' bj-central-oculto-trecho' : ''), previa));
            if (c.naoLidas > 0) {
                // Silenciada: o selo fica cinza (L5693) e a conversa sai do total do cabeçalho.
                var selo = criar('span', 'bj-central-selo' + (c.silenciada ? ' bj-central-selo--mudo' : ''), String(c.naoLidas));
                selo.setAttribute('aria-label', c.naoLidas + ' não lidas');
                baixo.appendChild(selo);
            } else if (c.marcadaNaoLida) {
                var marca = criar('span', 'bj-central-selo bj-central-selo--marca');
                marca.setAttribute('aria-label', 'Marcada como não lida');
                baixo.appendChild(marca);
            }
            textos.appendChild(baixo);
            b.appendChild(textos);
            b.addEventListener('click', function () { abrirConversa(c); });
            b.addEventListener('contextmenu', function (ev) { ev.preventDefault(); menuDaConversa(c, ev.clientX, ev.clientY, b); });
            b.addEventListener('keydown', function (ev) {
                if (ev.key === 'ContextMenu' || (ev.shiftKey && ev.key === 'F10')) {
                    ev.preventDefault();
                    var r = b.getBoundingClientRect();
                    menuDaConversa(c, r.left + 24, r.top + r.height / 2, b);
                }
            });
            return b;
        }

        function linhaDoContato(contato) {
            var b = criar('button', 'bj-central-linha bj-central-linha--contato');
            b.type = 'button';
            b.setAttribute('role', 'listitem');
            var av = criar('span', 'bj-central-avatar bj-central-avatar--contato', iniciais(contato.nome));
            av.setAttribute('aria-hidden', 'true');
            b.appendChild(av);
            var textos = criar('span', 'bj-central-linha-textos');
            textos.appendChild(criar('span', 'bj-central-nome d-block', contato.nome));
            textos.appendChild(criar('span', 'bj-central-contato-sub d-block', 'Iniciar conversa'));
            b.appendChild(textos);
            b.addEventListener('click', function () { iniciarConversaCom(contato); });
            return b;
        }

        function desenharLista() {
            estado.resolvidasDesenhadas = resolvidasNaJanela();   // o minuto das resolvidas compara com isto
            el.lista.replaceChildren();
            if (estado.semSessao) {
                el.lista.appendChild(vazio('bi-box-arrow-in-right', 'Sessão encerrada', 'Recarregue a página para continuar.'));
                return;
            }
            if (estado.erro) {
                el.lista.appendChild(vazio('bi-wifi-off', 'Sem conexão com a Central', 'Tentando de novo em instantes.'));
                return;
            }
            var termo = el.busca.value.trim().toLocaleLowerCase('pt-BR');
            if (termo !== '') {
                var achados = estado.contatos.filter(function (c) {
                    return c.nome.toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[̀-ͯ]/g, '')
                        .indexOf(termo.normalize('NFD').replace(/[̀-ͯ]/g, '')) !== -1;
                });
                if (achados.length === 0) {
                    el.lista.appendChild(vazio('bi-search', 'Nenhum usuário encontrado', 'Busque pelo nome do colega.'));
                    return;
                }
                achados.forEach(function (c) { el.lista.appendChild(linhaDoContato(c)); });
                return;
            }
            atualizarAbas();
            if (estado.ajuda) {
                el.faixaArquivadas.hidden = true;
                el.painel.hidden = true;
                desenharAjuda();
                return;
            }
            if (estado.verOnline) {
                el.faixaArquivadas.hidden = true;
                el.painel.hidden = true;
                desenharOnline();
                return;
            }
            if (estado.aba === 'painel') {
                el.faixaArquivadas.hidden = true;
                desenharPainel();
                desenharEquipe();
                return;
            }
            // Arquivada some da lista principal (L320) e volta com "Ver conversas arquivadas".
            el.faixaArquivadas.hidden = !estado.verArquivadas;
            var f = estado.filtro;
            // Filtros (L5776, F1): Tudo = conversas + avisos; Conversas = só conversas; Menções = conversas com menção +
            // avisos de menção; Urgente/Atenção/Metas = avisos do grupo.
            var visiveis = ['', 'conversas', 'mencoes'].indexOf(f) === -1 ? [] : estado.conversas.filter(function (c) {
                return (estado.verArquivadas || !c.arquivada || ehIa(c)) && (f !== 'mencoes' || estado.comMencao.has(c.id));
            });
            visiveis.sort(function (x, y) { return (ehIa(y) ? 1 : 0) - (ehIa(x) ? 1 : 0); });   // BlueJus IA sempre no topo (L324)
            // Resolvidas antigas saem da Caixa (L5782) e voltam com ⋮ "Ver resolvidas antigas".
            var avisos = f === 'conversas' ? [] : estado.avisos.filter(function (a) {
                return (f === '' || a.grupo === f) && (estado.verResolvidas || !resolvidaExpirada(a));
            });
            if (estado.verResolvidas) {   // L5785
                var faixa = criar('div', 'bj-central-faixa-resolvidas');
                var ic = criar('i', 'bi bi-check2-circle');
                ic.setAttribute('aria-hidden', 'true');
                faixa.appendChild(ic);
                faixa.appendChild(document.createTextNode('Mostrando resolvidas antigas'));
                faixa.appendChild(botao('bj-central-faixa-resolvidas-ocultar', 'Ocultar', function () { estado.verResolvidas = false; desenharLista(); }));
                el.lista.appendChild(faixa);
            }
            if (visiveis.length === 0 && avisos.length === 0 && f !== '' && f !== 'conversas') {
                el.lista.appendChild(f === 'mencoes'
                    ? vazio('bi-at', 'Nenhuma menção por ver', 'Quando alguém mencionar você, aparece aqui.')
                    : vazio('bi-check2-all', 'Tudo em dia', 'Nenhuma notificação pendente para você.'));
                return;
            }
            if (avisos.length > 0) {
                // Ordem (L5781): fixadas primeiro, depois o mais recente, conversas e avisos misturados.
                var itens = visiveis.map(function (c) { return { fixa: !!c.fixada || ehIa(c), ia: ehIa(c), quando: c.ultima ? c.ultima.criadaEm : (c.criadaEm || ''), c: c }; })
                    .concat(avisos.map(function (a) { return { fixa: false, quando: a.criadoEm, a: a }; }));
                itens.sort(function (x, y) { return ((y.ia ? 1 : 0) - (x.ia ? 1 : 0)) || (y.fixa - x.fixa) || (x.quando < y.quando ? 1 : x.quando > y.quando ? -1 : 0); });
                itens.slice(0, 100).forEach(function (it) { el.lista.appendChild(it.c ? linhaDaConversa(it.c) : linhaDoAviso(it.a)); });
                return;
            }
            if (visiveis.length === 0) {
                el.lista.appendChild(estado.verArquivadas || estado.conversas.length > 0
                    ? vazio('bi-archive', 'Nenhuma conversa aqui', estado.verArquivadas ? '' : 'As arquivadas aparecem em ⋮ → Ver conversas arquivadas.')
                    : vazio('bi-chat-dots', 'Nenhuma conversa ainda', 'Pesquise um colega acima ou crie um grupo em ⋮.'));
                return;
            }
            visiveis.forEach(function (c) { el.lista.appendChild(linhaDaConversa(c)); });
        }

        // ── abas, filtros e Painel (desenho L6366, L5776, L5632) ─────────────────────
        function totalMensagens() {
            return estado.naoLidas;
        }

        function totalCaixa() {   // aba "Caixa de entrada" e lançador: mensagens + avisos não lidos (L6323)
            return estado.naoLidas + estado.avisosNaoLidos;
        }

        function atualizarAbas() {
            if (!el.abas.length) { return; }
            el.abas.forEach(function (b) { b.setAttribute('aria-selected', b.dataset.centralAba === estado.aba ? 'true' : 'false'); });
            el.abaContador.hidden = totalCaixa() === 0;
            el.abaContador.textContent = String(totalCaixa());
            el.filtros.hidden = estado.aba !== 'entrada';
            el.botoesFiltro.forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.centralFiltro === estado.filtro ? 'true' : 'false'); });
            el.painel.hidden = estado.aba !== 'painel';
            el.lista.hidden = false;   // no Painel ela leva a "Equipe · em aberto" (F2), abaixo dos cartões
        }

        function desenharPainel() {   // telaPrioridades, L5632 — só os grupos que têm dado no BlueJus
            el.painel.replaceChildren();
            var porGrupo = { urgente: 0, atencao: 0, mencoes: 0, mensagens: 0, metas: 0 };
            estado.avisos.forEach(function (a) { if (!a.lido && porGrupo[a.grupo] !== undefined) { porGrupo[a.grupo]++; } });
            // GRUPOS, L84: Urgente, Atenção, Menções, Mensagens, Metas (F1: com os avisos do sino).
            [['urgente', 'Urgente', '#c0392f', porGrupo.urgente],
             ['atencao', 'Atenção', '#c8952a', porGrupo.atencao],
             ['mencoes', 'Menções', '#0a7aad', estado.comMencao.size + porGrupo.mencoes],
             ['mensagens', 'Mensagens', '#0b5f86', totalMensagens()],   // L5634: só mensagens (o clique leva a "Conversas")
             ['metas', 'Metas', '#0a7aad', porGrupo.metas]].forEach(function (g) {
                var card = criar('button', 'bj-central-painel-card' + (g[3] ? ' bj-central-painel-card--ativo' : ''));
                card.type = 'button';
                card.title = 'Ver ' + g[1].toLowerCase() + ' na Caixa de entrada';
                if (g[3]) { card.style.borderLeftColor = g[2]; }
                var rotulo = criar('span', 'bj-central-painel-rotulo', g[1]);
                if (g[3]) { rotulo.style.color = g[2]; }
                card.appendChild(rotulo);
                card.appendChild(criar('span', 'bj-central-painel-valor', String(g[3])));
                card.addEventListener('click', function () {
                    estado.aba = 'entrada';
                    estado.filtro = g[0] === 'mensagens' ? 'conversas' : g[0];
                    desenharLista();
                });
                el.painel.appendChild(card);
            });
        }

        // ── F3: resolvidas; ações do ⋮ ──────────────────────────────────────────────
        // RES_JANELA, L91: o desenho deixava escolher 1 h, 1 dia ou 1 semana; o dono fixou 30 min para todos (18/10).
        var JANELA_DAS_RESOLVIDAS_MS = 30 * 60000;

        function idadeDaBaixa(a) {   // resIdade, L92: 0 (agora) a 1 (fim da janela)
            return a.resolvidoEm ? (Date.now() - new Date(a.resolvidoEm).getTime()) / JANELA_DAS_RESOLVIDAS_MS : 0;
        }

        function resolvidaExpirada(a) {   // resExpirada, L93
            return !!a.resolvidoEm && idadeDaBaixa(a) >= 1;
        }

        // Quantas resolvidas ainda estão dentro da janela. O minuto só muda algo na tela se há alguma ("sai da caixa em
        // N min", a opacidade) ou se alguma acabou de expirar desde o último desenho (a saída da Caixa aos 30 min).
        function resolvidasNaJanela() {
            return estado.avisos.filter(function (a) { return !!a.resolvidoEm && !resolvidaExpirada(a); }).length;
        }

        // A cada minuto, sem pedir nada ao servidor: redesenha a Caixa para a resolvida sair na hora certa. Não mexe
        // em nada aberto por cima (menu, ⋮, confirmação), na busca digitada nem nas outras telas da lista.
        function avancarResolvidas() {
            if (centralOculta() || !estado.janelaAberta || estado.semSessao || estado.erro) { return; }
            if (estado.tela !== 'lista' || estado.aba !== 'entrada' || estado.verOnline || estado.ajuda || estado.filtro === 'conversas') { return; }
            if (el.busca.value.trim() !== '' || estado.menu || estado.painelMenu || estado.confirma) { return; }
            var naJanela = resolvidasNaJanela();
            if (naJanela === 0 && estado.resolvidasDesenhadas === 0) { return; }
            // A rolagem fica onde estava. O foco volta ao mesmo botão só se nenhum cartão saiu (senão a posição
            // apontaria para o botão de outro cartão).
            var saiuCartao = naJanela !== estado.resolvidasDesenhadas && !estado.verResolvidas;
            var rolagem = el.lista.scrollTop;
            var focado = raiz.ownerDocument.activeElement;
            var seletor = 'button, [role="button"]';
            var indice = !saiuCartao && focado && el.lista.contains(focado) ? Array.prototype.indexOf.call(el.lista.querySelectorAll(seletor), focado) : -1;
            desenharLista();
            el.lista.scrollTop = rolagem;
            if (indice >= 0) { var b = el.lista.querySelectorAll(seletor)[indice]; if (b) { b.focus(); } }
        }

        function irParaLista() {   // os comandos do ⋮ que mostram a lista saem da conversa/tela de grupo
            estado.verOnline = false;
            estado.ajuda = false;
            if (estado.aberta) { fecharConversa(); } else if (estado.tela !== 'lista') { mostrarTela('lista'); }
        }

        function recarregarTudo() {
            return Promise.all([carregarConversas(), carregarAvisos()]).then(function () { return verificarNovidades(); });
        }

        function marcarTudoComoLido() {   // L6262
            pedir(urls.lerTudo, { metodo: 'POST', corpo: {} })
                .then(function () { estado.comMencao = new Set(); return recarregarTudo(); })
                .then(function () { emitirContadores(0); atualizarCabecalho(); })
                .catch(falhaDeAcao);
        }

        function limparLidas() {   // L6283: confirmação do desenho; apaga as já lidas da Caixa
            var q = estado.avisos.filter(function (a) { return a.lido; }).length;
            if (!q) { avisar('Não há notificações lidas para limpar.'); return; }
            var texto = q >= 100 ? 'Apagar todas as notificações já lidas? Esta ação não poderá ser desfeita.'
                : 'Apagar ' + q + ' notificação(ões) já lida(s)? Esta ação não poderá ser desfeita.';
            confirmar(texto, 'Excluir', function () {
                pedir(urls.limparLidos, { metodo: 'POST', corpo: {} })
                    .then(function (d) { avisar((d.apagadas || 0) + ' notificação(ões) apagada(s).'); return carregarAvisos(); })
                    .catch(falhaDeAcao);
            });
        }

        function resolverAviso(a, resolver) {   // resolverNotif, L5620
            pedir(urls.avisos + '/' + a.id + '/' + (resolver ? 'resolver' : 'reabrir'), { metodo: 'POST', corpo: {} })
                .then(function () { return Promise.all([carregarAvisos(), verificarNovidades()]); })
                .catch(falhaDeAcao);
        }

        function desenharAjuda() {   // ui.ajuda, L6372 — o que existe de verdade aqui
            var caixa = criar('div', 'bj-central-ajuda');
            caixa.appendChild(criar('b', 'bj-central-ajuda-titulo', 'Como funciona'));
            ['Caixa de entrada: suas conversas e os avisos do sistema (metas, menções, publicações, agenda), do mais recente ao mais antigo. Clique para ir direto ao local.',
             'Conversas: pesquise um colega pelo nome ou abra a lista em ⋮ → Nova conversa. Digite @ para mencionar alguém.',
             'Botão direito numa mensagem, conversa ou aviso: responder, copiar, mencionar, fixar, silenciar, arquivar, marcar como lida ou não lida, dar baixa e excluir.',
             'Resolvidas saem da caixa 30 minutos depois da baixa. Excluir limpa só a sua caixa: ninguém mais perde nada.',
             'Painel: o que está em aberto por tipo e por pessoa da equipe. Clique numa pessoa para conversar.',
             'Destacar: leva a Central para uma janela própria, que pode ir para outro monitor.',
             'Fechar a central não marca nada como lido.'].forEach(function (t) { caixa.appendChild(criar('div', null, t)); });
            caixa.appendChild(botao('bj-central-ajuda-ok', 'Entendi', function () { estado.ajuda = false; desenharLista(); }));
            el.lista.appendChild(caixa);
        }

        // ── F1: cartão do aviso (cartaoNotif, L5603-5630; F3: com a baixa) ───────────────────────
        function abrirAviso(a) {
            // Destacada, a Central mora em outra janela: navegar a aba de origem a fecharia (pagehide). O item abre numa
            // aba nova e a Central destacada continua à vista (revisão do F1).
            var ir = function () {
                if (!a.url) { desenharLista(); return; }
                if (estado.externa) { var nova = window.open(a.url, '_blank'); if (nova) { nova.focus(); } return; }
                window.location.href = a.url;
            };
            if (a.lido) { ir(); return; }
            pedir(urls.avisos + '/' + a.id + '/lido', { metodo: 'POST', corpo: {} })
                .then(function () { a.lido = true; estado.avisosNaoLidos = Math.max(0, estado.avisosNaoLidos - 1); emitirContadores(0); atualizarCabecalho(); })
                .catch(function () { /* abre mesmo assim; a marcação vem na próxima */ })
                .finally(ir);
        }

        function marcarAviso(a, lido) {
            pedir(urls.avisos + '/' + a.id + '/' + (lido ? 'lido' : 'nao-lido'), { metodo: 'POST', corpo: {} })
                .then(function () { return verificarNovidades(); })
                .then(carregarAvisos)
                .catch(falhaDeAcao);
        }

        function excluirAviso(a) {   // L5586: limpa a MINHA caixa; a meta, a pasta ou a menção de origem não mudam
            pedir(urls.avisos + '/' + a.id + '/excluir', { metodo: 'POST', corpo: {} })
                .then(function () { avisar('Notificação excluída.'); return Promise.all([carregarAvisos(), verificarNovidades()]); })
                .catch(falhaDeAcao);
        }

        function menuDoAviso(a, x, y, origem) {   // menuNotif: abrir, resolver/reabrir, lida/não lida, excluir
            var itens = [{ icone: 'bi-box-arrow-up-right', rotulo: a.acao || 'Abrir', acao: function () { abrirAviso(a); } }];   /* "Ver sugestão" no Contribua */
            itens.push(a.resolvidoEm
                ? { icone: 'bi-arrow-counterclockwise', rotulo: 'Reabrir', acao: function () { resolverAviso(a, false); } }
                : { icone: 'bi-check2-circle', rotulo: 'Resolver (dar baixa)', corIcone: '#1e8a4c', acao: function () { resolverAviso(a, true); } });
            itens.push(a.lido
                ? { icone: 'bi-circle-fill', rotulo: 'Marcar como não lida', corIcone: '#3b6e8f', acao: function () { marcarAviso(a, false); } }
                : { icone: 'bi-check2', rotulo: 'Marcar como lida', acao: function () { marcarAviso(a, true); } });
            itens.push({ icone: 'bi-trash3', rotulo: 'Excluir', perigo: true, acao: function () { excluirAviso(a); } });   // L5586
            abrirMenu(itens, x, y, origem);
        }

        function linhaDoAviso(a) {
            var ok = !!a.resolvidoEm, VERDE = '#1e8a4c';
            var c = criar('div', 'bj-central-aviso-cartao' + (a.lido ? ' bj-central-aviso-cartao--lido' : '') + (ok ? ' bj-central-aviso-cartao--resolvido' : ''));
            c.setAttribute('role', 'button');
            c.tabIndex = 0;
            c.title = 'Clique para ' + (a.acao ? a.acao.toLowerCase() : 'abrir') + '. Botão direito: mais opções';
            var cor = ok ? VERDE : a.cor;
            var fundo = ok ? '#f4f9f5' : a.cor + (a.lido ? '08' : '14');   // L5605: a cor do tipo bem clara (alfa em hexadecimal)
            c.style.setProperty('--bjc-aviso', cor);
            c.style.background = fundo;
            // L5606: a resolvida esmaece com a idade da baixa; passada a janela, fica em .55 ("resolvidas antigas").
            if (ok) { c.style.opacity = (resolvidaExpirada(a) ? 0.55 : Math.max(0.45, 1 - idadeDaBaixa(a) * 0.55)).toFixed(2); }
            c.appendChild(criar('span', 'bj-central-aviso-faixa'));
            var ic = criar('span', 'bj-central-aviso-icone' + (ok ? ' bj-central-aviso-icone--ok' : ''));
            if (!ok) { ic.style.background = a.cor + '14'; } else { ic.title = 'Resolvida'; }
            var i = criar('i', 'bi ' + (ok ? 'bi-check-lg' : a.icone));
            i.setAttribute('aria-hidden', 'true');
            ic.appendChild(i);
            c.appendChild(ic);
            var corpo = criar('span', 'bj-central-aviso-corpo');
            var topo = criar('span', 'bj-central-aviso-topo');
            topo.appendChild(criar('span', 'bj-central-aviso-rotulo', a.rotulo));
            if (ok) {
                var selo = criar('span', 'bj-central-aviso-selo-ok');
                var si = criar('i', 'bi bi-check2');
                si.setAttribute('aria-hidden', 'true');
                selo.appendChild(si);
                selo.appendChild(document.createTextNode('RESOLVIDA'));
                topo.appendChild(selo);
            }
            topo.appendChild(criar('span', 'bj-central-aviso-hora', quando(a.criadoEm)));
            corpo.appendChild(topo);
            corpo.appendChild(criar('span', 'bj-central-aviso-titulo', a.titulo));
            if (a.texto && !ok) { corpo.appendChild(criar('span', 'bj-central-aviso-texto', a.texto)); }
            if (ok) {   // L5626: "Baixa por Você · quando · sai da caixa em N h"
                var resta = JANELA_DAS_RESOLVIDAS_MS - (Date.now() - new Date(a.resolvidoEm).getTime());
                corpo.appendChild(criar('span', 'bj-central-aviso-baixa', 'Baixa por Você · ' + quando(a.resolvidoEm)
                    + (resolvidaExpirada(a) ? '' : ' · sai da caixa ' + (resta < 60000 ? 'em menos de 1 min' : 'em ' + Math.ceil(resta / 60000) + ' min'))));
            }
            c.appendChild(corpo);
            // Baixa rápida (L5628): aparece ao passar o cursor ou no foco.
            var bx = criar('button', 'bj-central-aviso-bx' + (ok ? ' bj-central-aviso-bx--ok' : ''));
            bx.type = 'button';
            bx.title = ok ? 'Reabrir demanda' : 'Marcar como resolvida (dar baixa)';
            bx.setAttribute('aria-label', ok ? 'Reabrir demanda' : 'Marcar como resolvida');
            var bi = criar('i', 'bi ' + (ok ? 'bi-arrow-counterclockwise' : 'bi-check-lg'));
            bi.setAttribute('aria-hidden', 'true');
            bx.appendChild(bi);
            bx.addEventListener('click', function (ev) { ev.stopPropagation(); resolverAviso(a, !ok); });
            c.appendChild(bx);
            c.addEventListener('mouseenter', function () { c.style.background = ok ? '#e9f4ec' : a.cor + '22'; });
            c.addEventListener('mouseleave', function () { c.style.background = fundo; });
            c.addEventListener('click', function () { abrirAviso(a); });
            c.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') { abrirAviso(a); return; }
                if (ev.key === 'ContextMenu' || (ev.shiftKey && ev.key === 'F10')) {
                    ev.preventDefault();
                    var r = c.getBoundingClientRect();
                    menuDoAviso(a, r.left + 20, r.top + 20, c);
                }
            });
            c.addEventListener('contextmenu', function (ev) { ev.preventDefault(); menuDoAviso(a, ev.clientX, ev.clientY, c); });
            return c;
        }

        // ── F2: equipe e presença (telaPrioridades L5638-5653; verOnline L6380-6386) ─────
        // Preferências da Central neste navegador (as mesmas do lançador; na página /central não há lançador, então lê
        // direto). Lidas a cada uso: outra aba pode ter mudado o status.
        function prefsGuardadas() {
            try { return JSON.parse(window.localStorage.getItem('bj-central-prefs:' + (raiz.dataset.euId || '0')) || '{}') || {}; } catch (e) { return {}; }
        }

        function statusManual() {   // o que a pessoa escolheu no ⋮ "Status" (L6264); é o que o ponto do cabeçalho mostra (L6337)
            var s = prefsGuardadas().status;   // fonte única: o lançador grava aqui antes de avisar
            return STATUS[s] && s !== 'offline' && s !== 'inativo' ? s : 'disponivel';
        }

        // Atividade (spec presenca-e-ponto-no-cabecalho §2): um número só (o instante) numa chave do usuário, para todas
        // as abas e janelas concordarem; a última escrita vence sem perda. O `storage` desta chave NÃO bate.
        var CHAVE_ATIVIDADE = 'bj-central-ativ:' + (raiz.dataset.euId || '0');
        var INATIVO_MS = (Number(raiz.dataset.inativoAposMin) || 60) * 60000;
        var ATIVIDADE_A_CADA_MS = 15000;
        function ultimaAtividade() {
            try { return Number(window.localStorage.getItem(CHAVE_ATIVIDADE)) || 0; } catch (e) { return 0; }
        }
        function marcarAtividade() {
            var agora = Date.now();
            if (agora - ultimaAtividade() < ATIVIDADE_A_CADA_MS) { return; }
            var estava = statusEfetivo();
            try { window.localStorage.setItem(CHAVE_ATIVIDADE, String(agora)); } catch (e) { /* sem armazenamento: segue ativo */ }
            if (estava === 'inativo') { bater(); }   // voltou: os colegas sabem na hora, sem esperar os 20 s
        }
        function ouvirAtividade(alvo) {
            ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function (ev) {
                alvo.addEventListener(ev, marcarAtividade, { passive: true, capture: true });
            });
        }

        function statusEfetivo() {   // o que vai no batimento (L276): "ocupado" com Não perturbe; "inativo" sem atividade
            var p = prefsGuardadas();
            if (p.dnd && Date.now() < p.dnd) { return 'ocupado'; }
            var ativ = ultimaAtividade();
            if (ativ && Date.now() - ativ > INATIVO_MS) { return 'inativo'; }

            return statusManual();
        }

        function membroDaEquipe(id) {
            return estado.equipe ? estado.equipe.equipe.filter(function (m) { return m.id === id; })[0] || null : null;
        }

        function avatarDe(m, classe) {   // avatarU, L5409: foto do perfil ou iniciais
            var av = criar('span', 'bj-central-avatar ' + classe, m.foto ? null : iniciais(m.nome));
            av.setAttribute('aria-hidden', 'true');
            if (m.foto) {
                var img = criar('img');
                img.src = m.foto;
                img.alt = '';
                av.appendChild(img);
            }
            return av;
        }

        function hmDe(iso) {   // hm(), L286: "hoje às HH:MM" ou "dd/mm às HH:MM"
            var d = new Date(iso), hoje = new Date();
            var dia = d.toDateString() === hoje.toDateString() ? 'hoje' : String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0');
            return dia + ' às ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        }

        function detalheDePresenca(m) {   // det, L287
            if (m.online) { return m.desde ? 'desde ' + hmDe(m.desde).replace(/^.* às /, '') : 'on-line agora'; }
            return m.vistoEm ? 'visto por último ' + hmDe(m.vistoEm) : 'sem atividade recente';
        }

        function abrirComColega(m) {
            if (m.eu) { return; }
            estado.aba = 'entrada';
            estado.filtro = '';
            estado.verOnline = false;
            iniciarConversaCom({ id: m.id, nome: m.nome });
        }

        function linhaDaEquipe(m) {   // L5645-5652
            var rot = STATUS[m.status] ? STATUS[m.status][0] : STATUS.offline[0];
            var b = criar('button', 'bj-central-equipe-linha' + (m.eu ? ' bj-central-equipe-linha--eu' : ''));
            b.type = 'button';
            b.title = m.eu ? 'Você' : 'Conversar com ' + m.nome + ' · ' + rot + ' · ' + detalheDePresenca(m);
            var lugar = criar('span', 'bj-central-equipe-av');
            lugar.appendChild(avatarDe(m, 'bj-central-avatar--equipe'));
            var ponto = criar('span', 'bj-central-equipe-ponto');
            ponto.style.background = (STATUS[m.status] || STATUS.offline)[1];
            ponto.title = rot;
            lugar.appendChild(ponto);
            b.appendChild(lugar);
            var textos = criar('span', 'bj-central-equipe-textos');
            textos.appendChild(criar('span', 'bj-central-equipe-nome', m.nome + (m.eu ? ' (você)' : '')));
            textos.appendChild(criar('span', 'bj-central-equipe-sub', [m.cargo, rot].filter(Boolean).join(' · ')));
            b.appendChild(textos);
            var n = m.emAberto;
            var cont = criar('span', 'bj-central-equipe-contador bj-central-equipe-contador--' + (n >= 10 ? 'alto' : n ? 'algum' : 'zero'), String(n));
            cont.title = n + (n === 1 ? ' mensagem em aberto' : ' mensagens em aberto');
            b.appendChild(cont);
            if (!m.eu) {
                var ic = criar('i', 'bi bi-chat-dots bj-central-equipe-conversar');
                ic.setAttribute('aria-hidden', 'true');
                b.appendChild(ic);
            }
            b.addEventListener('click', function () { abrirComColega(m); });
            return b;
        }

        function desenharEquipe() {   // "Equipe · em aberto", abaixo dos cartões do Painel
            if (!estado.equipe) { return; }
            var caixa = criar('div', 'bj-central-equipe');
            var tit = criar('div', 'bj-central-equipe-titulo');
            var ic = criar('i', 'bi bi-people');
            ic.setAttribute('aria-hidden', 'true');
            tit.appendChild(ic);
            tit.appendChild(document.createTextNode('Equipe · em aberto'));
            tit.appendChild(criar('span', 'bj-central-equipe-total', estado.equipe.total + ' no total'));
            caixa.appendChild(tit);
            estado.equipe.equipe.forEach(function (m) { caixa.appendChild(linhaDaEquipe(m)); });
            el.lista.appendChild(caixa);
        }

        function linhaDePessoa(m) {   // linhaUsuario, L5803: avatar 34, ponto verde só on-line
            var b = criar('button', 'bj-central-pessoa' + (m.online ? '' : ' bj-central-pessoa--off'));
            b.type = 'button';
            b.title = m.nome + ' · ' + (STATUS[m.status] || STATUS.offline)[0] + ' · ' + detalheDePresenca(m);
            var lugar = criar('span', 'bj-central-equipe-av');
            lugar.appendChild(avatarDe(m, 'bj-central-avatar--contato'));
            if (m.online) {
                var ponto = criar('span', 'bj-central-pessoa-ponto');
                ponto.title = 'On-line';
                lugar.appendChild(ponto);
            }
            b.appendChild(lugar);
            var textos = criar('span', 'bj-central-pessoa-textos');
            textos.appendChild(criar('span', 'bj-central-pessoa-nome', m.nome));
            textos.appendChild(criar('span', 'bj-central-pessoa-sub', m.eu ? 'Você' : (m.cargo || '')));
            b.appendChild(textos);
            b.addEventListener('click', function () { abrirComColega(m); });
            return b;
        }

        function faixaOnline(texto, cor, ponto, extra) {   // tit(), L6382
            var f = criar('div', 'bj-central-online-faixa');
            f.style.color = cor;
            var p = criar('span', 'bj-central-online-faixa-ponto');
            p.style.background = ponto;
            f.appendChild(p);
            f.appendChild(document.createTextNode(texto));
            if (extra) { f.appendChild(extra); }
            return f;
        }

        function desenharOnline() {   // verOnline, L6380-6386 (sem "Todos" e "BlueJus IA": não existem aqui)
            var eq = estado.equipe ? estado.equipe.equipe : [];
            var porNome = function (a, b) { return a.nome.localeCompare(b.nome, 'pt-BR'); };
            var on = eq.filter(function (m) { return m.online; }).sort(function (a, b) { return (a.eu - b.eu) || porNome(a, b); });
            var off = eq.filter(function (m) { return !m.online; }).sort(porNome);
            var voltarBtn = criar('button', 'bj-central-online-voltar', 'Voltar');
            voltarBtn.type = 'button';
            voltarBtn.addEventListener('click', function () { estado.verOnline = false; atualizarCabecalho(); desenharLista(); });
            el.lista.appendChild(faixaOnline(on.length + ' on-line agora', '#1e7b45', '#3fd17f', voltarBtn));
            on.forEach(function (m) { el.lista.appendChild(linhaDePessoa(m)); });
            if (off.length) {
                el.lista.appendChild(faixaOnline(off.length + ' off-line', '#7b93a2', '#c3cfd6'));
                off.forEach(function (m) { el.lista.appendChild(linhaDePessoa(m)); });
            }
        }

        function carregarEquipe() {
            return pedir(urls.equipe).then(function (d) {
                var mudou = JSON.stringify(d) !== JSON.stringify(estado.equipe);
                estado.equipe = d;
                atualizarCabecalho();
                // Redesenha só se mudou, só o que mostra a equipe (o Painel ou a lista de on-line) e sem busca digitada;
                // a rolagem e o foco ficam onde estavam (quem navega por teclado não perde o lugar).
                if (!mudou || estado.tela !== 'lista' || !(estado.aba === 'painel' || estado.verOnline) || el.busca.value.trim() !== '') { return; }
                var rolagem = el.lista.scrollTop;
                var focado = raiz.ownerDocument.activeElement;
                var indice = focado && el.lista.contains(focado) ? Array.prototype.indexOf.call(el.lista.querySelectorAll('button'), focado) : -1;
                desenharLista();
                el.lista.scrollTop = rolagem;
                if (indice >= 0) { var b = el.lista.querySelectorAll('button')[indice]; if (b) { b.focus(); } }
            });
        }

        // Batimento (F2): toda página com a Central bate a cada 20 s, com a janela aberta ou não (como no desenho,
        // L344); com ela aberta, a equipe vem junto.
        // Só com algo do BlueJus à vista (a página ou a Central destacada): aba esquecida em segundo plano não fica
        // "on-line" nem mantém a sessão viva (revisão do F2). O relógio é o da janela à vista, como o do polling.
        var batimento = { timer: null, relogio: window };
        function iniciarBatimento() {
            if (batimento.timer !== null) {
                try { batimento.relogio.clearInterval(batimento.timer); } catch (e) { /* janela destacada já fechada */ }
                batimento.timer = null;
            }
            if (oculta() || estado.semSessao || !urls.presenca) { return; }
            batimento.relogio = estado.externa && !estado.externa.document.hidden ? estado.externa : window;
            batimento.timer = batimento.relogio.setInterval(bater, BATIMENTO_MS);
        }

        function bater() {
            if (estado.semSessao || !urls.presenca || oculta()) { return Promise.resolve(); }
            var meu = statusEfetivo();
            return pedir(urls.presenca, { metodo: 'POST', corpo: { status: meu } })
                .then(function (dados) {
                    // Para o selo e o painel do cabeçalho (presenca-cabecalho.js): quem está on-line, sem outra requisição.
                    // No `document` da página, não na raiz: com a Central destacada a raiz vai para o documento da outra
                    // janela e o evento não chegaria ao cabeçalho.
                    document.dispatchEvent(new CustomEvent('bj-central:presenca', { detail: { online: (dados && dados.online) || [] } }));
                    if (estado.janelaAberta) { return carregarEquipe(); }
                })
                .catch(function (e) { if (e && e.sessao) { tratarFalha(e); } });
        }

        function emitirContadores(chegaram) {   // para o lançador flutuante: badge, "@n", salto e som
            raiz.dispatchEvent(new CustomEvent('bj-central:contadores', {
                bubbles: true,
                detail: { naoLidas: totalCaixa(), mencoes: estado.comMencao.size, urgentes: estado.avisosUrgentes, chegaram: chegaram || 0, importantes: estado.chegaramImportantes }
            }));
            estado.chegaramImportantes = 0;
        }

        function lerMencoes(dados) {
            if (Array.isArray(dados.conversasComMencao)) { estado.comMencao = new Set(dados.conversasComMencao); }
        }

        // F1: as contagens dos avisos vêm no polling; a lista só recarrega quando algo mudou (e com a janela aberta).
        // Devolve quantos avisos novos chegaram (para o salto e o som do lançador).
        function lerAvisos(dados) {
            if (!Number.isFinite(dados.avisosNaoLidos)) { return 0; }
            var primeira = estado.ultimoAviso === null;
            var mudou = dados.ultimoAviso !== estado.ultimoAviso || dados.avisosNaoLidos !== estado.avisosNaoLidos;
            var chegaram = !primeira && dados.ultimoAviso > estado.ultimoAviso ? Math.max(0, dados.avisosNaoLidos - estado.avisosNaoLidos) : 0;
            // ⋮ "Avisar: Importantes" (L140): só os de nível ≥ 2 fazem saltar e soar.
            estado.chegaramImportantes += !primeira && dados.ultimoAviso > estado.ultimoAviso ? Math.max(0, (dados.avisosImportantes || 0) - estado.avisosImportantes) : 0;
            estado.avisosImportantes = dados.avisosImportantes || 0;
            estado.avisosNaoLidos = dados.avisosNaoLidos;
            estado.avisosUrgentes = dados.avisosUrgentes || 0;
            estado.ultimoAviso = dados.ultimoAviso || 0;
            if (mudou && estado.janelaAberta && !primeira) { carregarAvisos().catch(function () { /* tenta no próximo */ }); }

            return chegaram;
        }

        function carregarAvisos() {
            return pedir(urls.avisos).then(function (d) {
                estado.avisos = Array.isArray(d.avisos) ? d.avisos : [];
                atualizarCabecalho();
                emitirContadores(0);   // badge e "urgente" do lançador em dia (lido aqui ou no sino de outra aba)
                if (estado.tela === 'lista' && !estado.verOnline && el.busca.value.trim() === '') { desenharLista(); }
            });
        }

        function carregarConversas() {
            // 1a vez com duas abas: o servidor pode responder 409 ao criar a conversa da IA; repete a lista UMA vez.
            return pedir(urls.conversas).catch(function (e) {
                if (e && e.status === 409) { return pedir(urls.conversas); }
                throw e;
            }).then(function (dados) {
                estado.conversas = dados.conversas || [];
                estado.listaCarregada = true;
                // O total do cabeçalho deixa de fora as silenciadas — a mesma regra do servidor (spec B §4).
                estado.naoLidas = estado.conversas.reduce(function (t, c) { return t + (c.silenciada ? 0 : c.naoLidas); }, 0);
                estado.erro = false;
                sincronizarAberta();
                if (estado.tela === 'lista') { desenharLista(); }
                atualizarCabecalho();
                emitirContadores(0);
            });
        }

        // ── conversa aberta ──────────────────────────────────────────────────────────
        function bolha(m) {
            estado.vistas.add(m.id);
            if (m.sistema) {   // linha de sistema do grupo — pílula centralizada, L6029; sem menu
                var sis = criar('div', 'bj-central-msg bj-central-msg--sistema');
                sis.dataset.id = String(m.id);
                sis.dataset.dia = new Date(m.criadaEm).toDateString();
                sis.appendChild(criar('span', 'bj-central-sistema', m.conteudo + ' · ' + horaDe(m.criadaEm)));
                return sis;
            }
            var deIa = m.deIa === true;   // a fala da IA vem com minha=false; testa deIa ANTES de minha
            var linha = criar('div', 'bj-central-msg' + (deIa ? ' bj-central-msg--ia' : (m.minha ? ' bj-central-msg--minha' : '')));
            linha.dataset.id = String(m.id);
            linha.dataset.dia = new Date(m.criadaEm).toDateString();
            var corpoBolha = criar('div', 'bj-central-bolha' + (deIa ? ' bj-central-bolha--ia' : ''));
            corpoBolha.tabIndex = 0;
            if (deIa) {   // autor da IA, L6035: ícone bi-stars, nome e o selo "IA"
                var autorIa = criar('span', 'bj-central-msg-autor bj-central-msg-autor--ia');
                var estrela = icone('bi-stars');
                autorIa.appendChild(estrela);
                autorIa.appendChild(criar('span', '', m.autorNome || ia.nome));
                autorIa.appendChild(criar('span', 'bj-central-selo-ia', ia.selo));
                corpoBolha.appendChild(autorIa);
            } else if (!m.minha && estado.aberta && estado.aberta.grupo) {   // em grupo, quem escreveu (cor por pessoa, L5814)
                var autor = criar('span', 'bj-central-msg-autor', m.autorNome);
                autor.style.color = corNome(m.autorId);
                corpoBolha.appendChild(autor);
            }
            if (m.respostaA) {   // citação, L6035
                var cit = criar('button', 'bj-central-citacao');
                cit.type = 'button';
                if (!m.minha) { cit.style.borderLeftColor = corNome(m.respostaA.autorId); }   // no balão próprio: branca
                cit.appendChild(criar('span', 'bj-central-citacao-autor', '↩ ' + (m.respostaA.minha ? 'Você' : m.respostaA.autorNome)));
                /* Citação de um despacho que eu não vejo: o servidor manda "Item de pasta sem acesso"; aqui, apagada. */
                cit.appendChild(criar('span', 'bj-central-citacao-trecho' + (m.respostaA.ocultoPorPermissao ? ' bj-central-oculto-trecho' : ''), m.respostaA.trecho));
                cit.title = 'Ir para a mensagem respondida';
                cit.addEventListener('click', function () { irParaMensagem(m.respostaA.id); });
                corpoBolha.appendChild(cit);
            }
            if (m.ocultoPorPermissao) {
                /* Despacho de pasta que eu não vejo: o servidor já trocou o texto; apagado, sem chip (BC:6039). */
                var oculto = criar('span', 'bj-central-oculto');   /* "Mensagem excluída", BC:6039 */
                var icOculto = icone('bi-slash-circle');
                icOculto.classList.add('bj-central-oculto-ico');
                oculto.appendChild(icOculto);
                oculto.appendChild(document.createTextNode(m.conteudo));
                corpoBolha.appendChild(oculto);
            } else {
                var chip = chipDaPasta(m.despacho);
                pintarTexto(corpoBolha, chip ? semALinhaDaPasta(m.conteudo, m.despacho) : m.conteudo);
                if (chip) { corpoBolha.appendChild(chip); }
            }
            corpoBolha.addEventListener('contextmenu', function (ev) { ev.preventDefault(); menuDaMensagem(m, ev.clientX, ev.clientY, corpoBolha); });
            corpoBolha.addEventListener('keydown', function (ev) {
                if (ev.key === 'ContextMenu' || (ev.shiftKey && ev.key === 'F10')) {
                    ev.preventDefault();
                    var r = corpoBolha.getBoundingClientRect();
                    menuDaMensagem(m, r.left + 16, r.top + 12, corpoBolha);
                }
            });
            linha.appendChild(corpoBolha);
            var hora = criar('span', 'bj-central-msg-hora', quando(m.criadaEm));   // L6054
            hora.title = horaDe(m.criadaEm);
            if (m.minha) {
                var recibo = criar('i', 'bi bi-check2');
                recibo.setAttribute('aria-label', 'Enviada');
                recibo.title = 'Enviada';
                hora.appendChild(recibo);
            }
            linha.appendChild(hora);
            return linha;
        }

        function separadorSeNovoDia(anterior, m) {
            var dia = new Date(m.criadaEm).toDateString();
            if (anterior && anterior.dataset.dia === dia) { return null; }
            return criar('div', 'bj-central-dia', rotuloDoDia(m.criadaEm));
        }

        function anexarAoFim(mensagens) {
            var perto = el.corpo.scrollHeight - el.corpo.scrollTop - el.corpo.clientHeight < 60;
            var vazia = el.corpo.querySelector('.bj-central-vazio');
            if (vazia) { vazia.remove(); }
            var vazioIa = el.corpo.querySelector('.bj-central-ia-vazio');
            if (vazioIa) { vazioIa.remove(); }
            esconderDigitando();   // o digitando... fica sempre por último: sai antes e volta depois das novas
            mensagens.forEach(function (m) {
                if (el.corpo.querySelector('[data-id="' + m.id + '"]')) { return; }
                var todas = el.corpo.querySelectorAll('.bj-central-msg');
                var anterior = todas.length > 0 ? todas[todas.length - 1] : null;
                var sep = separadorSeNovoDia(anterior, m);
                if (sep) { el.corpo.appendChild(sep); }
                el.corpo.appendChild(bolha(m));
                estado.aberta.ultimaId = Math.max(estado.aberta.ultimaId, m.id);
            });
            mostrarDigitandoSeAguardando();
            if (perto || mensagens.some(function (m) { return m.minha; })) {
                el.corpo.scrollTop = el.corpo.scrollHeight;
            }
        }

        // ── F3: digitando..., estado vazio e campo da conversa com a IA ────────────────────────────────
        function esconderDigitando() {
            var d = el.corpo.querySelector('.bj-central-ia-digitando');
            if (d) { d.remove(); }
        }

        function mostrarDigitandoSeAguardando() {
            esconderDigitando();
            if (!estado.iaAguardando || !estado.aberta || !estado.aberta.ia) { return; }
            // L6057: avatar 22, três pontos de 6 px (bjcPonto 1.2 s, atrasos 0/.2/.4 s) e o texto
            var d = criar('div', 'bj-central-ia-digitando');
            d.setAttribute('role', 'status');
            d.appendChild(avatarIa('bj-central-avatar--ia-pequeno'));
            var pontos = criar('span', 'bj-central-ia-pontos');
            pontos.setAttribute('aria-hidden', 'true');
            for (var i = 0; i < 3; i++) { pontos.appendChild(criar('span', 'bj-central-ia-ponto')); }
            d.appendChild(pontos);
            d.appendChild(document.createTextNode(ia.digitando));
            el.corpo.appendChild(d);
            el.corpo.scrollTop = el.corpo.scrollHeight;
        }

        function desenharVazioDaIa() {   // L6021 e L397: estado vazio e as sugestões (sem comandos "/": PR 5)
            var caixa = criar('div', 'bj-central-ia-vazio');
            caixa.appendChild(vazio('bi-stars', ia.vazioTitulo, ia.vazioTexto));
            // L397: "Faça um resumo desta pasta" só com a barra da pasta na conversa
            var textos = ia.sugestoes.slice();
            if (estado.iaPasta && ia.sugestaoPasta) { textos.push(ia.sugestaoPasta); }
            if (textos.length > 0) {
                var sugestoes = criar('div', 'bj-central-ia-sugestoes');
                textos.forEach(function (texto) {
                    var b = criar('button', 'bj-central-ia-sugestao', texto);
                    b.type = 'button';
                    b.addEventListener('click', function () {
                        if (estado.iaAguardando || estado.enviando) { return; }
                        el.campo.value = texto;
                        enviar();
                    });
                    sugestoes.appendChild(b);
                });
                caixa.appendChild(sugestoes);
            }
            return caixa;
        }

        // PR 4b: barra de contexto "Pasta N" do desenho (BC L6001-6006), só na conversa com a IA.
        function barraDaPasta() {
            var p = estado.iaPasta;
            var barra = criar('div', 'bj-central-ia-pasta');
            barra.setAttribute('role', 'group');
            if (ia.pastaAria) { barra.setAttribute('aria-label', ia.pastaAria); }
            barra.appendChild(icone('bi-folder2-open'));
            var texto = criar('span', 'bj-central-ia-pasta-texto');
            texto.appendChild(criar('span', 'bj-central-ia-pasta-rotulo', ia.pastaRotulo + ' ' + p.nup));
            if (p.cliente) { texto.appendChild(criar('span', 'bj-central-ia-pasta-cliente', p.cliente)); }
            barra.appendChild(texto);
            var abrir = criar('a', 'bj-central-ia-pasta-abrir', ia.abrirPasta);
            abrir.href = urls.pastaBase + String(p.pastaId);
            abrir.addEventListener('click', function (ev) {
                if (ev.button !== 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey) { return; }
                /* Destacada: aba nova, e a Central segue aberta (navegar a página de origem fecharia o pop-up). */
                if (vista() !== window) { ev.preventDefault(); window.open(abrir.href, '_blank', 'noopener'); }
            });
            barra.appendChild(abrir);
            return barra;
        }

        function inserirBarraDaPasta() {
            if (!estado.iaPasta || !estado.aberta || !estado.aberta.ia || el.corpo.querySelector('.bj-central-ia-pasta')) { return; }
            el.corpo.insertBefore(barraDaPasta(), el.corpo.firstChild);
        }

        // Busca o rótulo uma vez (sucesso ou 404). Nunca rejeita: sem rótulo = sem barra e sem pastaId na pergunta.
        function carregarPastaDaIa() {
            if (!ia.pastaId || estado.iaPastaTentada) { return Promise.resolve(estado.iaPasta); }
            return pedir(urls.iaPastaBase + String(ia.pastaId)).then(function (d) {
                estado.iaPastaTentada = true;
                estado.iaPasta = d && Number(d.pastaId) === ia.pastaId && typeof d.nup === 'string'
                    ? { pastaId: ia.pastaId, nup: d.nup, cliente: typeof d.cliente === 'string' ? d.cliente : null }
                    : null;
                return estado.iaPasta;
            }).catch(function (e) {
                if (e && e.status === 404) { estado.iaPastaTentada = true; }   // não existe ou sem acesso: não avisa
                estado.iaPasta = null;
                return null;
            });
        }

        function removerBarraDaPasta() {   // o servidor disse que a pasta não vale mais (404 da pergunta)
            estado.iaPasta = null;
            var barra = el.corpo.querySelector('.bj-central-ia-pasta');
            if (barra) { barra.remove(); }
            var vazioIa = el.corpo.querySelector('.bj-central-ia-vazio');
            if (vazioIa) { vazioIa.replaceWith(desenharVazioDaIa()); }
        }

        function iaBloqueada() {   // pergunta em andamento: sem segundo envio (evita custo duplo)
            return !!(estado.aberta && estado.aberta.ia && (estado.iaAguardando || estado.enviando));
        }

        function atualizarCampoDaIa() {
            var comIa = !!(estado.aberta && estado.aberta.ia);
            if (el.campo.dataset.textoOriginal === undefined) {
                el.campo.dataset.textoOriginal = el.campo.getAttribute('placeholder') || '';
                el.campo.dataset.ariaOriginal = el.campo.getAttribute('aria-label') || '';
            }
            el.campo.setAttribute('placeholder', comIa ? (estado.iaAguardando ? ia.placeholderAguardando : ia.placeholder) : el.campo.dataset.textoOriginal);
            el.campo.setAttribute('aria-label', comIa && ia.aria ? ia.aria : el.campo.dataset.ariaOriginal);
            el.campo.readOnly = iaBloqueada();
            el.enviar.disabled = estado.enviando || iaBloqueada();
        }

        function desenharPagina(dados, antigas) {
            var altura = el.corpo.scrollHeight;
            var fragmento = document.createDocumentFragment();
            var anterior = null;
            if (dados.temMais) {
                var mais = criar('button', 'bj-central-mais', 'Carregar mensagens anteriores');
                mais.type = 'button';
                mais.addEventListener('click', carregarAnteriores);
                fragmento.appendChild(mais);
            }
            dados.mensagens.forEach(function (m) {
                var sep = separadorSeNovoDia(anterior, m);
                if (sep) { fragmento.appendChild(sep); }
                anterior = bolha(m);
                fragmento.appendChild(anterior);
            });
            if (antigas) {
                var botao = el.corpo.querySelector('.bj-central-mais');
                if (botao) { botao.remove(); }
                var barraFixa = el.corpo.querySelector('.bj-central-ia-pasta');
                el.corpo.insertBefore(fragmento, barraFixa ? barraFixa.nextSibling : el.corpo.firstChild);
                el.corpo.scrollTop = el.corpo.scrollHeight - altura;
            } else {
                el.corpo.replaceChildren(fragmento);
                inserirBarraDaPasta();
                if (dados.mensagens.length === 0) {
                    el.corpo.appendChild(estado.aberta.ia ? desenharVazioDaIa() : vazio('bi-chat', 'Comece a conversa'));
                }
                mostrarDigitandoSeAguardando();
                el.corpo.scrollTop = el.corpo.scrollHeight;
            }
            if (dados.mensagens.length > 0) {
                estado.aberta.primeiraId = Math.min(estado.aberta.primeiraId || Infinity, dados.mensagens[0].id);
                estado.aberta.ultimaId = Math.max(estado.aberta.ultimaId, dados.mensagens[dados.mensagens.length - 1].id);
            }
            estado.aberta.temMais = dados.temMais;
        }

        function marcarLida() {
            // Janela flutuante fechada não "lê": a conversa só vira lida quando está de fato à vista.
            if (!estado.aberta || centralOculta() || !estado.janelaAberta || estado.aberta.ultimaId === 0) { return; }
            var id = estado.aberta.id;
            pedir(urls.base + '/' + id + '/lida', { metodo: 'POST', corpo: { ateId: estado.aberta.ultimaId } })
                .then(function () {
                    // Ler a conversa lê as menções dela (regra do servidor): o "@" sai já, sem esperar o polling.
                    estado.comMencao.delete(id);
                    return carregarConversas();
                })
                .catch(function () { /* a próxima volta do polling tenta de novo */ });
        }

        function ajustarCompositor() {
            var podeEnviar = podeEscrever();
            el.compositor.hidden = !podeEnviar;
            el.telaConversa.querySelectorAll('.bj-central-aviso').forEach(function (a) { a.remove(); });
            atualizarCampoDaIa();
            if (podeEnviar && estado.aberta && estado.aberta.ia && ia.aviso) {   // aviso fixo: a resposta é de IA e pode errar
                el.telaConversa.appendChild(criar('div', 'bj-central-aviso bj-central-aviso--ia', ia.aviso));
            }
            if (!podeEnviar && estado.aberta && !estado.semSessao) {
                el.telaConversa.appendChild(criar('div', 'bj-central-aviso', estado.aberta.fora
                    ? 'Você não participa mais desta conversa.'
                    : 'Este colega não faz mais parte do escritório.'));
            }
        }

        // Só uma tela à vista: lista, conversa, novo grupo, informações ou despacho.
        function mostrarTela(nome) {
            estado.tela = nome;
            el.telaLista.hidden = nome !== 'lista';
            el.telaConversa.hidden = nome !== 'conversa';
            el.telaNovoGrupo.hidden = nome !== 'novoGrupo';
            el.telaInfo.hidden = nome !== 'info';
            el.telaDespacho.hidden = nome !== 'despacho';
            if (nome !== 'despacho') { estado.despacho = null; }   /* saiu da tela: a resposta que chegar depois é ignorada */
            atualizarCabecalho();
        }

        /**
         * A conversa aberta acompanha a lista: nome e participantes do grupo mudam; e, se o GRUPO sumiu da minha lista
         * (fui removido ou saí em outra aba), deixa de dar para escrever. A conversa 1:1 não tem saída (sair e remover
         * só valem para grupo, no servidor): se ela não está na lista, é porque eu a excluí da minha caixa
         * (`limpa_ate_id`) e ainda não chegou mensagem nova. Sigo participando e posso escrever de novo.
         */
        function sincronizarAberta() {
            if (!estado.aberta) { return; }
            var atual = estado.conversas.filter(function (c) { return c.id === estado.aberta.id; })[0];
            if (!atual) {
                estado.aberta.fora = estado.aberta.grupo;
            } else {
                estado.aberta.fora = false;
                estado.aberta.nome = nomeDaConversa(atual);
                estado.aberta.participantes = atual.participantes;
                estado.aberta.outroAtivo = atual.outroAtivo !== false;
            }
            if (estado.tela === 'conversa') { ajustarCompositor(); }
        }

        function abrirConversa(c) {
            fecharMenu();
            var id = c.id;
            estado.aberta = {
                id: id, grupo: c.tipo === 'grupo', ia: ehIa(c), nome: nomeDaConversa(c), outro: c.outro || null,
                outroAtivo: c.outroAtivo !== false, participantes: c.participantes || 0, fora: false,
                primeiraId: 0, ultimaId: 0, temMais: false, mencionaveis: []
            };
            estado.escolhidas = [];
            cancelarResposta();
            fecharSugestoes();
            mostrarTela('conversa');
            el.corpo.replaceChildren();
            atualizarCabecalho();
            ajustarCompositor();
            var rotuloDaPasta = ehIa(c) ? carregarPastaDaIa() : Promise.resolve(null);
            Promise.all([pedir(urls.base + '/' + id + '/mensagens'), rotuloDaPasta]).then(function (resultado) {
                var dados = resultado[0];
                if (!estado.aberta || estado.aberta.id !== id) { return; }
                estado.aberta.mencionaveis = !estado.aberta.ia && Array.isArray(dados.mencionaveis) ? dados.mencionaveis : [];
                desenharPagina(dados, false);
                marcarLida();
                if (estado.aberta.outroAtivo && !iaBloqueada()) { el.campo.focus(); }
            }).catch(function (e) {
                el.corpo.replaceChildren(vazio('bi-exclamation-circle', 'Não foi possível abrir a conversa', e.message));
            });
        }

        function carregarAnteriores() {
            if (!estado.aberta || !estado.aberta.temMais) { return; }
            var id = estado.aberta.id;
            pedir(urls.base + '/' + id + '/mensagens?antesDe=' + estado.aberta.primeiraId).then(function (dados) {
                if (estado.aberta && estado.aberta.id === id) { desenharPagina(dados, true); }
            }).catch(function () { /* o botão continua lá para tentar de novo */ });
        }

        // `calado`: BJCentral.conversar (spec §4.1). Si mesmo ou colega fora do escritório só abre a janela, sem aviso.
        function iniciarConversaCom(contato, tentativa, calado) {
            pedir(urls.conversas, { metodo: 'POST', corpo: { destinatarioId: contato.id } }).then(function (dados) {
                el.busca.value = '';
                abrirConversa(dados.conversa);
                carregarConversas().catch(tratarFalha);
            }).catch(function (e) {
                if (e.status === 409 && !tentativa) { iniciarConversaCom(contato, true, calado); return; }
                if (e.sessao) { tratarFalha(e); return; }
                if (!calado) { avisar(e.message); }
            });
        }

        function fecharConversa() {
            fecharMenu();
            fecharSugestoes();
            cancelarResposta();
            estado.aberta = null;
            estado.escolhidas = [];
            var aviso = el.telaConversa.querySelector('.bj-central-aviso');
            if (aviso) { aviso.remove(); }
            mostrarTela('lista');
            if (estado.semSessao) { desenharLista(); return; }
            carregarConversas().catch(tratarFalha);
        }

        // O que conta no teto é o texto VISÍVEL (a menção como "@Nome") — é o que está no campo — medido por
        // caractere, como o mb_strlen do servidor (Mensagem::textoVisivel). Array.from conta emoji como 1.
        function tamanhoVisivel(texto) {
            return Array.from(texto).length;
        }

        function enviar() {
            var texto = el.campo.value.trim();
            if (!estado.aberta || texto === '' || estado.enviando || iaBloqueada()) { return; }
            var limite = estado.aberta.ia ? TAMANHO_MAXIMO_DA_PERGUNTA : tamanhoMaximo;
            if (tamanhoVisivel(texto) > limite) {
                avisar('A mensagem passa de ' + limite + ' caracteres (tem ' + tamanhoVisivel(texto) + ').');
                return;
            }
            if (estado.aberta.ia) { perguntarAIa(texto); return; }
            estado.enviando = true;
            el.enviar.disabled = true;
            var id = estado.aberta.id;
            var corpo = { conteudo: comTokens(texto) };
            if (estado.respondendo) { corpo.respostaAId = estado.respondendo.id; }
            fecharSugestoes();
            pedir(urls.base + '/' + id + '/mensagens', { metodo: 'POST', corpo: corpo }).then(function (dados) {
                el.campo.value = '';
                estado.escolhidas = [];
                cancelarResposta();
                ajustarAltura();
                if (estado.aberta && estado.aberta.id === id) { anexarAoFim([dados.mensagem]); }
                carregarConversas().catch(tratarFalha);
            }).catch(function (e) {
                if (e.sessao) { tratarFalha(e); return; }
                avisar(e.message);
                // 503: a pergunta já estava gravada quando a fila falhou; relê o histórico para não reenviar em duplicidade
                if (e.status === 503 && estado.aberta && estado.aberta.id === id) {
                    el.campo.value = '';
                    ajustarAltura();
                    return pedir(urls.base + '/' + id + '/mensagens').then(function (pagina) { anexarAoFim(pagina.mensagens || []); }).catch(function () {});
                }
            }).finally(function () {
                estado.enviando = false;
                el.enviar.disabled = false;
            });
        }

        // F3: pergunta ao BlueJus IA. Não usa /conversas/{id}/mensagens (o servidor recusa com 403 `conversa_ia`).
        // 201 = pergunta nova, 202 = já havia uma em andamento (devolve a mesma, sem custo novo). A fala da IA chega pelo
        // polling de /central/novidades; aqui só se mostra a pergunta e o digitando....
        function perguntarAIa(texto) {
            var id = estado.aberta.id;
            estado.enviando = true;
            atualizarCampoDaIa();
            // pastaId só enquanto a barra existir: é dica, o servidor revalida (404/409 abaixo)
            var corpoDaPergunta = { conteudo: texto };
            if (estado.iaPasta) { corpoDaPergunta.pastaId = estado.iaPasta.pastaId; }
            pedir(urls.iaPergunta, { metodo: 'POST', corpo: corpoDaPergunta }).then(function (dados) {
                estado.iaAguardando = { analiseId: dados.analiseId, conversaId: dados.conversaId, semFala: 0, desde: Date.now() };
                if (dados.emAndamento !== true) {   // 201: a pergunta foi gravada; 202: o texto novo NÃO foi, o rascunho fica
                    el.campo.value = '';
                    ajustarAltura();
                }
                if (estado.aberta && estado.aberta.id === id) {
                    mostrarDigitandoSeAguardando();
                    return pedir(urls.base + '/' + id + '/mensagens').then(function (pagina) {
                        // anexarAoFim ignora o que já está na tela e o polling descarta o que já foi visto (estado.vistas)
                        var mensagens = pagina.mensagens || [];
                        // a fala pode ter chegado pelo polling antes desta resposta voltar: a pergunta já está respondida
                        if (mensagens.some(function (m) { return m.deIa === true && m.id > dados.mensagemId; })) { estado.iaAguardando = null; }
                        if (estado.aberta && estado.aberta.id === id) { anexarAoFim(mensagens); }
                    });
                }
            }).catch(function (e) {
                if (e.sessao) { tratarFalha(e); return; }
                avisar(e.message);   // 409 contexto_bloqueado e 404 pasta_nao_encontrada: a mensagem fixa do servidor
                if (e.status === 404 && e.codigo === 'pasta_nao_encontrada') { removerBarraDaPasta(); }
            }).finally(function () {
                estado.enviando = false;
                atualizarCampoDaIa();
                carregarConversas().catch(tratarFalha);
            });
        }

        function encerrarPerguntaDaIa() {   // a fala chegou, ou o servidor disse que não vai chegar
            estado.iaAguardando = null;
            esconderDigitando();
            atualizarCampoDaIa();
            if (estado.aberta && estado.aberta.ia && !iaBloqueada() && estado.tela === 'conversa') { el.campo.focus(); }
        }

        // Uma consulta de status por ciclo do polling, só enquanto há pergunta pendente e nenhuma fala nova chegou.
        // Nunca mostra texto da IA: só o `mensagem` fixo do servidor (falhou/indisponível), no aviso da Central.
        function conferirPerguntaDaIa() {
            var pendente = estado.iaAguardando;
            if (!pendente || estado.iaConsultando) { return; }
            if (Date.now() - pendente.desde > ESPERA_MAXIMA_DA_IA) {   // worker parado: não trava o campo para sempre
                encerrarPerguntaDaIa();
                if (ia.demora) { avisar(ia.demora); }
                return;
            }
            estado.iaConsultando = true;
            pedir(urls.iaStatus + pendente.analiseId).then(function (d) {
                if (estado.iaAguardando !== pendente) { return; }   // a fala chegou enquanto a consulta ia
                var falhou = d.status === 'falhou' || d.status === 'indisponivel';
                if (!falhou && d.terminal !== true) { pendente.semFala = 0; return; }
                // Terminal sem falha: a fala pode estar a um ciclo de aparecer; só desiste no segundo ciclo seguido.
                pendente.semFala += 1;
                if (!falhou && pendente.semFala < 2) { return; }
                encerrarPerguntaDaIa();
                if (d.mensagem) { avisar(d.mensagem); }
            }).catch(function (e) {
                if (e && e.sessao) { tratarFalha(e); return; }
                if (e && e.status === 404 && estado.iaAguardando === pendente) { encerrarPerguntaDaIa(); }
            }).finally(function () { estado.iaConsultando = false; });
        }

        function ajustarAltura() {   // textarea 36–110 px, L6073
            el.campo.style.height = 'auto';
            el.campo.style.height = Math.min(el.campo.scrollHeight, 110) + 'px';
        }

        // ── @menção no compositor (A2) ───────────────────────────────────────────────
        // O campo mostra "@Nome"; no envio, cada pessoa ESCOLHIDA na lista vira o token @[Nome](user:ID). Quem
        // confere se a pessoa pode ser mencionada é o servidor — aqui só se monta o pedido.
        function escaparRegex(t) {
            return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        function comTokens(texto) {
            var escolhidas = estado.escolhidas.slice().sort(function (a, b) { return b.nome.length - a.nome.length; });
            escolhidas.forEach(function (p) {
                var re = new RegExp('@' + escaparRegex(p.nome) + '(?![\\p{L}\\p{N}])', 'gu');
                texto = texto.replace(re, function () { return '@[' + p.nome.replace(/[\[\]<>]/g, '') + '](user:' + p.id + ')'; });
            });
            return texto;
        }

        function lembrarEscolhida(p) {
            if (!estado.escolhidas.some(function (e) { return e.id === p.id; })) {
                estado.escolhidas.push({ id: p.id, nome: p.nome });
            }
        }

        function fecharSugestoes() {
            estado.sugestoes = null;
            el.mencoes.hidden = true;
            el.mencoes.replaceChildren();
            el.campo.removeAttribute('aria-activedescendant');
        }

        function atualizarSugestoes() {
            var lista = estado.aberta ? estado.aberta.mencionaveis : [];
            var antes = el.campo.value.slice(0, el.campo.selectionStart);
            var digitando = antes.match(DIGITANDO_MENCAO);
            if (!lista.length || !digitando) { fecharSugestoes(); return; }
            var termo = semAcento(digitando[1]);
            var itens = lista.filter(function (p) {   // prefixo de alguma palavra do nome, L6101
                var nome = semAcento(p.nome);
                return termo === '' || nome.indexOf(termo) === 0 || nome.split(/\s+/).some(function (w) { return w.indexOf(termo) === 0; });
            }).slice(0, MAX_SUGESTOES);
            if (itens.length === 0) { fecharSugestoes(); return; }
            var indice = estado.sugestoes ? Math.min(estado.sugestoes.indice, itens.length - 1) : 0;
            estado.sugestoes = { itens: itens, indice: indice, inicio: antes.length - digitando[0].length };
            desenharSugestoes();
        }

        function desenharSugestoes() {
            var sug = estado.sugestoes;
            el.mencoes.replaceChildren(criar('div', 'bj-central-mencoes-cab', 'Mencionar'));
            sug.itens.forEach(function (p, i) {
                var item = criar('button', 'bj-central-mencoes-item');
                item.type = 'button';
                item.id = 'bj-central-mencao-' + p.id;
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', i === sug.indice ? 'true' : 'false');
                item.tabIndex = -1;
                var av = criar('span', 'bj-central-avatar', iniciais(p.nome));
                av.setAttribute('aria-hidden', 'true');
                item.appendChild(av);
                var nome = criar('span', null, p.nome);
                nome.style.flex = '1';
                item.appendChild(nome);
                item.addEventListener('mousedown', function (ev) { ev.preventDefault(); });   // o foco fica no campo
                item.addEventListener('click', function () { escolherSugestao(i); });
                el.mencoes.appendChild(item);
            });
            el.mencoes.hidden = false;
            el.campo.setAttribute('aria-activedescendant', 'bj-central-mencao-' + sug.itens[sug.indice].id);
        }

        function escolherSugestao(i) {
            var sug = estado.sugestoes;
            if (!sug || !sug.itens[i]) { return; }
            var p = sug.itens[i];
            var fim = el.campo.selectionStart;
            var inserido = '@' + p.nome + ' ';
            el.campo.value = el.campo.value.slice(0, sug.inicio) + inserido + el.campo.value.slice(fim);
            var cursor = sug.inicio + inserido.length;
            el.campo.setSelectionRange(cursor, cursor);
            lembrarEscolhida(p);
            fecharSugestoes();
            ajustarAltura();
            el.campo.focus();
        }

        function mencionar(p) {   // "Mencionar <Nome>" do menu da mensagem, L5884
            var atual = el.campo.value;
            el.campo.value = atual + (atual === '' || /\s$/.test(atual) ? '' : ' ') + '@' + p.nome + ' ';
            lembrarEscolhida(p);
            ajustarAltura();
            el.campo.focus();
            el.campo.setSelectionRange(el.campo.value.length, el.campo.value.length);
        }

        function mencionavel(id) {
            var lista = estado.aberta ? estado.aberta.mencionaveis : [];
            for (var i = 0; i < lista.length; i++) {
                if (lista[i].id === id) { return lista[i]; }
            }
            return null;
        }

        function podeEscrever() {
            return !!(estado.aberta && estado.aberta.outroAtivo && !estado.aberta.fora && !estado.semSessao);
        }

        // ── responder (A2) ───────────────────────────────────────────────────────────
        function responderA(m) {   // faixa "Respondendo a…", L6068
            var trecho = comoTexto(m.conteudo);
            estado.respondendo = { id: m.id, autorNome: m.autorNome, minha: m.minha };
            el.respondendoRotulo.textContent = 'Respondendo a ' + (m.minha ? 'você' : m.autorNome);
            el.respondendoTrecho.textContent = trecho.length > 120 ? trecho.slice(0, 120) + '…' : trecho;
            el.respondendo.querySelector('.bj-central-respondendo-barra').style.background = corNome(m.autorId);
            el.respondendo.hidden = false;
            el.campo.focus();
        }

        function cancelarResposta() {
            estado.respondendo = null;
            el.respondendo.hidden = true;
            el.respondendoRotulo.textContent = '';
            el.respondendoTrecho.textContent = '';
        }

        function irParaMensagem(id) {   // irParaMsg, L5871
            var alvo = el.corpo.querySelector('[data-id="' + Number(id) + '"]');
            if (!alvo) { return; }
            alvo.scrollIntoView({ block: 'center', behavior: 'smooth' });
            alvo.classList.add('bj-central-msg--destaque');
            window.setTimeout(function () { alvo.classList.remove('bj-central-msg--destaque'); }, 1600);
        }

        function copiar(texto) {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(texto).catch(function () { copiarSemClipboard(texto); });
            } else {
                copiarSemClipboard(texto);
            }
            avisar('Mensagem copiada.');
        }

        var avisoAtual = null;
        function avisar(texto) {   // aviso(), L5398: acima da janela, some em 2,6 s
            if (flutuante) {   // no flutuante, o aviso sai acima do lançador (L6410)
                raiz.dispatchEvent(new CustomEvent('bj-central:aviso', { bubbles: true, detail: { texto: texto } }));
                return;
            }
            if (avisoAtual) { avisoAtual.remove(); }
            var caixa = criar('div', 'bj-central-toast');
            caixa.setAttribute('role', 'status');
            var ic = criar('i', 'bi bi-bell-fill');
            ic.setAttribute('aria-hidden', 'true');
            caixa.appendChild(ic);
            caixa.appendChild(document.createTextNode(texto));
            raiz.parentNode.insertBefore(caixa, raiz);
            avisoAtual = caixa;
            window.setTimeout(function () { if (avisoAtual === caixa) { caixa.remove(); avisoAtual = null; } }, AVISO_MS);
        }

        function copiarSemClipboard(texto) {   // contexto sem HTTPS (dev): execCommand
            var area = criar('textarea');
            area.value = texto;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            raiz.ownerDocument.body.appendChild(area);
            area.select();
            try { raiz.ownerDocument.execCommand('copy'); } catch (e) { /* sem cópia: nada a fazer */ }
            area.remove();
        }

        // ── menus de botão direito (A2) — menuCtxEl, L5697 ──────────────────────────
        // Só ações que existem de verdade no servidor ou no navegador (D5). Fecha com clique fora, Esc, rolagem,
        // outro botão direito ou ao escolher; ↑/↓ andam e Enter escolhe.
        function fecharMenu(devolverFoco) {
            if (!estado.menu) { return; }
            var origem = estado.menu.origem;
            estado.menu.fundo.remove();
            estado.menu.caixa.remove();
            estado.menu = null;
            if (devolverFoco && origem && raiz.ownerDocument.contains(origem)) { origem.focus(); }
        }

        function abrirMenu(itens, x, y, origem) {
            fecharMenu();
            if (itens.length === 0) { return; }
            var fundo = criar('div', 'bj-central-ctx-fundo');
            var caixa = criar('div', 'bj-central-ctx');
            caixa.setAttribute('role', 'menu');
            itens.forEach(function (it) {
                var b = criar('button', 'bj-central-ctx-item' + (it.perigo ? ' bj-central-ctx-item--perigo' : ''));
                b.type = 'button';
                b.setAttribute('role', 'menuitem');
                var ic = criar('i', 'bi ' + it.icone);
                ic.setAttribute('aria-hidden', 'true');
                if (it.corIcone) { ic.style.color = it.corIcone; }
                b.appendChild(ic);
                b.appendChild(criar('span', null, it.rotulo));
                b.addEventListener('click', function () { fecharMenu(false); it.acao(); });
                caixa.appendChild(b);
            });
            var largura = 220;
            var altura = itens.length * 33 + 10;
            caixa.style.left = Math.max(8, Math.min(x, vista().innerWidth - largura - 8)) + 'px';
            caixa.style.top = Math.max(8, Math.min(y, vista().innerHeight - altura - 8)) + 'px';
            fundo.addEventListener('click', function () { fecharMenu(true); });
            fundo.addEventListener('contextmenu', function (ev) { ev.preventDefault(); fecharMenu(true); });
            caixa.addEventListener('keydown', function (ev) {
                var botoes = Array.prototype.slice.call(caixa.querySelectorAll('.bj-central-ctx-item'));
                var atual = botoes.indexOf(raiz.ownerDocument.activeElement);
                if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                    ev.preventDefault();
                    var passo = ev.key === 'ArrowDown' ? 1 : -1;
                    botoes[(atual + passo + botoes.length) % botoes.length].focus();
                } else if (ev.key === 'Escape' || ev.key === 'Tab') {
                    ev.preventDefault();
                    ev.stopPropagation();
                    fecharMenu(true);
                }
            });
            raiz.appendChild(fundo);
            raiz.appendChild(caixa);
            estado.menu = { fundo: fundo, caixa: caixa, origem: origem };
            caixa.querySelector('.bj-central-ctx-item').focus();
        }

        // Fixar, silenciar, arquivar (B): preferências SÓ minhas, gravadas no servidor.
        function preferir(c, corpo, aviso) {
            return pedir(urls.base + '/' + c.id + '/preferencias', { metodo: 'POST', corpo: corpo })
                .then(function () { avisar(aviso); return carregarConversas(); })
                .catch(falhaDeAcao);
        }

        function menuDaConversa(c, x, y, origem) {   // menuConversa, L5758 — só o que tem regra e rota (D5)
            var itens = [{ icone: 'bi-box-arrow-up-right', rotulo: 'Abrir', acao: function () { abrirConversa(c); } }];
            if (c.ultima && (c.naoLidas > 0 || c.marcadaNaoLida)) {
                itens.push({ icone: 'bi-check2', rotulo: 'Marcar como lida', acao: function () {
                    pedir(urls.base + '/' + c.id + '/lida', { metodo: 'POST', corpo: { ateId: c.ultima.id } })
                        .then(carregarConversas).catch(falhaDeAcao);
                } });
            } else if (c.ultima) {
                itens.push({ icone: 'bi-circle-fill', rotulo: 'Marcar como não lida', corIcone: '#3b6e8f', acao: function () {
                    pedir(urls.base + '/' + c.id + '/nao-lida', { metodo: 'POST', corpo: {} })
                        .then(carregarConversas).catch(falhaDeAcao);
                } });
            }
            if (ehIa(c)) {
                // F3: a IA é fixa e não se arquiva nem se exclui (o servidor não a esconde): só lida/não lida e silenciar.
                itens.push({ icone: c.silenciada ? 'bi-bell' : 'bi-bell-slash', rotulo: c.silenciada ? 'Reativar notificações' : 'Silenciar', acao: function () {
                    preferir(c, { silenciada: !c.silenciada }, c.silenciada ? 'Notificações reativadas.' : 'Conversa silenciada.');
                } });
                abrirMenu(itens, x, y, origem);
                return;
            }
            itens.push({ icone: 'bi-pin-angle', rotulo: c.fixada ? 'Desafixar' : 'Fixar conversa', acao: function () {
                preferir(c, { fixada: !c.fixada }, c.fixada ? 'Conversa desafixada.' : 'Conversa fixada.');
            } });
            itens.push({ icone: c.silenciada ? 'bi-bell' : 'bi-bell-slash', rotulo: c.silenciada ? 'Reativar notificações' : 'Silenciar', acao: function () {
                preferir(c, { silenciada: !c.silenciada }, c.silenciada ? 'Notificações reativadas.' : 'Conversa silenciada.');
            } });
            itens.push({ icone: 'bi-archive', rotulo: c.arquivada ? 'Desarquivar' : 'Arquivar', acao: function () {
                preferir(c, { arquivada: !c.arquivada }, c.arquivada ? 'Conversa desarquivada.' : 'Conversa arquivada.');
            } });
            if (c.tipo === 'grupo') {
                itens.push({ icone: 'bi-pencil', rotulo: 'Renomear grupo', acao: function () { abrirInfo(c, true); } });
                itens.push({ icone: 'bi-info-circle', rotulo: 'Ver informações', acao: function () { abrirInfo(c, false); } });
            }
            if (c.ultima) {   // L5757: limpa só para mim; o colega segue vendo tudo e mensagem nova traz a conversa de volta
                itens.push({ icone: 'bi-trash3', rotulo: 'Excluir conversa', perigo: true, acao: function () {
                    confirmar('Excluir a conversa "' + nomeDaConversa(c) + '" da sua visualização? Esta ação não poderá ser desfeita.', 'Excluir', function () {
                        pedir(urls.base + '/' + c.id + '/limpar', { metodo: 'POST', corpo: {} })
                            .then(function () {
                                avisar('Conversa excluída.');
                                // fecharConversa já recarrega a lista; sem conversa aberta, recarrega aqui.
                                if (estado.aberta && estado.aberta.id === c.id) { fecharConversa(); return verificarNovidades(); }
                                return Promise.all([carregarConversas(), verificarNovidades()]);
                            })
                            .catch(falhaDeAcao);
                    });
                } });
            }
            abrirMenu(itens, x, y, origem);
        }

        // ── menu ⋮ em painel (menu(), L6244-6293) ─────────────────────────────────────
        // Painel preso ao canto da janela, com seções, chaves e valores. SÓ comandos que funcionam de verdade (D5): as
        // conversas daqui e, no lançador, as preferências da janela (raiz.bjMenuJanela). Chaves e valores mudam sem
        // fechar o painel, como no desenho; os demais itens fecham.
        function secoesDoMenu() {
            var antigas = estado.avisos.filter(resolvidaExpirada).length;
            var secoes = [{ titulo: 'Conversas', itens: [
                { icone: 'bi-pencil-square', rotulo: 'Nova conversa', acao: function () {   // L6258: a lista de pessoas
                    irParaLista();
                    estado.verOnline = true;
                    atualizarCabecalho();
                    desenharLista();
                } },
                { icone: 'bi-people', rotulo: 'Novo grupo', acao: function () {
                    if (estado.aberta) { fecharConversa(); }
                    abrirNovoGrupo();
                } },
                { icone: 'bi-archive', rotulo: estado.verArquivadas ? 'Ocultar arquivadas' : 'Ver conversas arquivadas', acao: function () {
                    estado.verArquivadas = !estado.verArquivadas;
                    desenharLista();
                } },
                { icone: 'bi-check2-circle', rotulo: estado.verResolvidas ? 'Ocultar resolvidas antigas' : 'Ver resolvidas antigas (' + antigas + ')', acao: function () {
                    irParaLista();
                    estado.verResolvidas = !estado.verResolvidas;
                    desenharLista();
                } },
                { icone: 'bi-check2-all', rotulo: 'Marcar todas como lidas', acao: marcarTudoComoLido },
                { icone: 'bi-bell', rotulo: 'Ver todas as notificações', acao: function () {   // L6263: a Caixa, filtro Tudo
                    irParaLista();
                    estado.aba = 'entrada';
                    estado.filtro = '';
                    desenharLista();
                } }
            ] }];
            if (typeof raiz.bjMenuJanela === 'function') { secoes = secoes.concat(raiz.bjMenuJanela()); }
            // Limpeza (L6282) antes do bloco final (sem título) e Ajuda no fim dele (L6292).
            var limpeza = { titulo: 'Limpeza', itens: [{ icone: 'bi-trash3', rotulo: 'Limpar notificações lidas', acao: limparLidas }] };
            var ajuda = { icone: 'bi-question-circle', rotulo: 'Ajuda', acao: function () { irParaLista(); estado.ajuda = true; desenharLista(); } };
            var final = secoes.filter(function (sc) { return sc.titulo === null; })[0];
            if (final) {
                secoes.splice(secoes.indexOf(final), 0, limpeza);
                final.itens.push(ajuda);
            } else {
                secoes.push(limpeza, { titulo: null, itens: [ajuda] });
            }

            return secoes.filter(function (sc) { return sc.itens.length > 0; });
        }

        function itemDoMenu(it) {
            var b = criar('button', 'bj-central-menu-item');
            b.type = 'button';
            b.setAttribute('role', it.chave === undefined ? 'menuitem' : 'menuitemcheckbox');
            if (it.chave !== undefined) { b.setAttribute('aria-checked', String(!!it.chave)); }
            var ic = criar('i', 'bi ' + it.icone);
            ic.setAttribute('aria-hidden', 'true');
            b.appendChild(ic);
            b.appendChild(criar('span', 'bj-central-menu-rotulo', it.rotulo));
            if (it.chave !== undefined) {
                var chave = criar('span', 'bj-central-menu-chave');
                chave.setAttribute('aria-hidden', 'true');
                if (it.chave) { chave.dataset.ligada = ''; }
                chave.appendChild(criar('span', 'bj-central-menu-chave-bola'));
                b.appendChild(chave);
            } else if (it.valor && it.ponto) {   // "Status" (L6264): ponto colorido + valor
                var comPonto = criar('span', 'bj-central-menu-valor-ponto');
                var bolinha = criar('span', 'bj-central-menu-ponto');
                bolinha.style.background = it.ponto;
                comPonto.appendChild(bolinha);
                comPonto.appendChild(criar('span', 'bj-central-menu-valor', it.valor));
                b.appendChild(comPonto);
            } else if (it.valor) {
                b.appendChild(criar('span', 'bj-central-menu-valor', it.valor));
            }
            b.addEventListener('click', function () {
                var manter = it.chave !== undefined || it.valor !== undefined || it.manter === true;
                if (!manter) { fecharPainelMenu(false); }
                it.acao();
                // Mantém o foco na mesma posição: o rótulo pode mudar ("Não perturbe até HH:MM").
                if (manter && estado.painelMenu) {
                    desenharPainelMenu(Array.prototype.indexOf.call(estado.painelMenu.caixa.querySelectorAll('.bj-central-menu-item'), b));
                }
            });
            return b;
        }

        function desenharPainelMenu(focarIndice) {
            var caixa = estado.painelMenu ? estado.painelMenu.caixa : criar('div', 'bj-central-menu');
            caixa.replaceChildren();
            caixa.setAttribute('role', 'menu');
            caixa.setAttribute('aria-label', 'Mais opções');
            secoesDoMenu().forEach(function (sc, i) {
                if (i > 0) {
                    var sep = criar('div', 'bj-central-menu-sep');
                    sep.setAttribute('role', 'separator');
                    caixa.appendChild(sep);
                }
                if (sc.titulo) {
                    var tit = criar('div', 'bj-central-menu-secao', sc.titulo);
                    tit.setAttribute('role', 'presentation');
                    caixa.appendChild(tit);
                }
                sc.itens.forEach(function (it) { caixa.appendChild(itemDoMenu(it)); });
            });
            if (!estado.painelMenu) {
                caixa.addEventListener('keydown', function (ev) {
                    var botoes = Array.prototype.slice.call(caixa.querySelectorAll('.bj-central-menu-item'));
                    var atual = botoes.indexOf(caixa.ownerDocument.activeElement);
                    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                        ev.preventDefault();
                        botoes[(atual + (ev.key === 'ArrowDown' ? 1 : -1) + botoes.length) % botoes.length].focus();
                    } else if (ev.key === 'Escape' || ev.key === 'Tab') {
                        ev.preventDefault();
                        ev.stopPropagation();
                        fecharPainelMenu(true);
                    }
                });
                raiz.appendChild(caixa);
                estado.painelMenu = { caixa: caixa };
                el.mais.setAttribute('aria-expanded', 'true');
            }
            var itens = caixa.querySelectorAll('.bj-central-menu-item');
            var alvo = (typeof focarIndice === 'number' && focarIndice >= 0 ? itens[focarIndice] : null) || itens[0];
            if (alvo) { alvo.focus(); }
        }

        function fecharPainelMenu(devolverFoco) {
            if (!estado.painelMenu) { return; }
            estado.painelMenu.caixa.remove();
            estado.painelMenu = null;
            el.mais.setAttribute('aria-expanded', 'false');
            if (devolverFoco && !el.mais.hidden) { el.mais.focus(); }
        }

        function alternarPainelMenu() {
            if (estado.painelMenu) { fecharPainelMenu(true); return; }
            fecharMenu(false);
            desenharPainelMenu(null);
        }

        // ── confirmação dentro da janela (ui.confirma, L6394) ────────────────────────
        // No lugar da confirmação nativa do navegador: sobreposição na própria janela, Cancelar e a ação (vermelha quando destrói).
        function confirmar(texto, rotulo, acao) {
            fecharConfirmacao(false);
            fecharPainelMenu(false);
            fecharMenu(false);
            var fundo = criar('div', 'bj-central-confirma');
            var caixa = criar('div', 'bj-central-confirma-caixa');
            caixa.setAttribute('role', 'alertdialog');
            caixa.setAttribute('aria-modal', 'true');
            var frase = criar('span', 'bj-central-confirma-texto', texto);
            frase.id = 'bj-central-confirma-' + Date.now();
            caixa.setAttribute('aria-describedby', frase.id);
            caixa.setAttribute('aria-label', rotulo);
            var botoes = criar('div', 'bj-central-confirma-botoes');
            var cancelar = botao('bj-central-confirma-btn', 'Cancelar', function () { fecharConfirmacao(true); });
            var ok = botao('bj-central-confirma-btn bj-central-confirma-btn--' + (/^(Excluir|Remover|Apagar|Revogar|Sair)/.test(rotulo) ? 'perigo' : 'acao'), rotulo, function () {
                fecharConfirmacao(true);
                acao();
            });
            botoes.appendChild(cancelar);
            botoes.appendChild(ok);
            caixa.appendChild(frase);
            caixa.appendChild(botoes);
            fundo.appendChild(caixa);
            fundo.addEventListener('click', function (ev) { if (ev.target === fundo) { fecharConfirmacao(true); } });
            fundo.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape') {
                    ev.preventDefault();
                    ev.stopPropagation();
                    fecharConfirmacao(true);
                } else if (ev.key === 'Tab') {   // o foco fica nos dois botões enquanto a pergunta está aberta
                    ev.preventDefault();
                    (caixa.ownerDocument.activeElement === cancelar ? ok : cancelar).focus();
                }
            });
            raiz.appendChild(fundo);
            estado.confirma = { fundo: fundo, origem: raiz.ownerDocument.activeElement };
            cancelar.focus();
        }

        function fecharConfirmacao(devolverFoco) {
            if (!estado.confirma) { return; }
            var origem = estado.confirma.origem;
            estado.confirma.fundo.remove();
            estado.confirma = null;
            if (devolverFoco && origem && raiz.contains(origem)) { origem.focus(); }
        }

        // ── grupos (B) ───────────────────────────────────────────────────────────────
        function campoTexto(classe, valor, placeholder, rotulo) {
            var i = criar('input', classe);
            i.type = 'text';
            i.maxLength = 60;
            i.value = valor || '';
            i.placeholder = placeholder;
            i.setAttribute('aria-label', rotulo);
            return i;
        }

        function botao(classe, texto, acao) {
            var b = criar('button', classe, texto);
            b.type = 'button';
            b.addEventListener('click', acao);
            return b;
        }

        function abrirNovoGrupo() {   // telaNovoGrupo, L6202
            var escolhidos = new Set();
            var p = el.telaNovoGrupo;
            p.replaceChildren();
            var nome = campoTexto('bj-central-campo-linha', '', 'Nome do grupo', 'Nome do grupo');
            var contador = criar('div', 'bj-central-painel-sub', '');
            var busca = campoTexto('bj-central-campo-linha', '', 'Pesquisar colega', 'Pesquisar colega');
            busca.maxLength = 60;
            var lista = criar('div', 'bj-central-escolha');
            var criarBtn = botao('bj-central-btn bj-central-btn--primario', 'Criar grupo', criarGrupo);
            var cancelar = botao('bj-central-btn', 'Cancelar', function () { mostrarTela('lista'); desenharLista(); });

            function atualizar() {
                contador.textContent = escolhidos.size === 1 ? '1 participante selecionado' : escolhidos.size + ' participantes selecionados';
                criarBtn.disabled = escolhidos.size === 0;
            }
            function desenhar() {
                var termo = semAcento(busca.value.trim());
                lista.replaceChildren();
                estado.contatos.filter(function (c) { return termo === '' || semAcento(c.nome).indexOf(termo) !== -1; }).forEach(function (c) {
                    var rotulo = criar('label', 'bj-central-escolha-item');
                    var caixa = criar('input');
                    caixa.type = 'checkbox';
                    caixa.checked = escolhidos.has(c.id);
                    caixa.addEventListener('change', function () {
                        if (caixa.checked) { escolhidos.add(c.id); } else { escolhidos.delete(c.id); }
                        atualizar();
                    });
                    rotulo.appendChild(caixa);
                    var av = criar('span', 'bj-central-avatar', iniciais(c.nome));
                    av.setAttribute('aria-hidden', 'true');
                    rotulo.appendChild(av);
                    rotulo.appendChild(criar('span', null, c.nome));
                    lista.appendChild(rotulo);
                });
                if (!lista.firstChild) { lista.appendChild(vazio('bi-search', 'Ninguém encontrado.')); }
            }
            function criarGrupo() {
                if (escolhidos.size === 0) { return; }
                var ids = Array.from(escolhidos);
                // Sem nome, o desenho usa os três primeiros nomes escolhidos (L6211).
                var nomeFinal = nome.value.trim() || estado.contatos.filter(function (c) { return escolhidos.has(c.id); })
                    .slice(0, 3).map(function (c) { return primeiroNome(c.nome); }).join(', ').slice(0, 60);
                criarBtn.disabled = true;
                pedir(urls.grupos, { metodo: 'POST', corpo: { nome: nomeFinal, participantes: ids } }).then(function (dados) {
                    return carregarConversas().then(function () {
                        var novo = estado.conversas.filter(function (c) { return c.id === dados.grupo.id; })[0];
                        if (novo) { abrirConversa(novo); } else { mostrarTela('lista'); desenharLista(); }
                    });
                }).catch(function (e) { criarBtn.disabled = false; falhaDeAcao(e); });
            }

            busca.addEventListener('input', desenhar);
            p.appendChild(nome);
            p.appendChild(contador);
            p.appendChild(busca);
            p.appendChild(lista);
            var rodape = criar('div', 'bj-central-painel-acoes');
            rodape.appendChild(cancelar);
            rodape.appendChild(criarBtn);
            p.appendChild(rodape);
            desenhar();
            atualizar();
            mostrarTela('novoGrupo');
            nome.focus();
        }

        /** "Ver informações" do grupo (telaInfo, L6164): participantes, renomear, adicionar/remover (administrador), sair. */
        function abrirInfo(c, focarNome) {
            if (!estado.aberta || estado.aberta.id !== c.id) { abrirConversa(c); }
            var id = c.id;
            var p = el.telaInfo;
            p.replaceChildren(vazio('bi-hourglass-split', 'Carregando…'));
            mostrarTela('info');
            pedir(urls.base + '/' + id + '/participantes').then(function (dados) {
                if (estado.tela !== 'info' || !estado.aberta || estado.aberta.id !== id) { return; }
                desenharInfo(c, dados, focarNome);
            }).catch(function (e) {
                p.replaceChildren(vazio('bi-exclamation-circle', 'Não foi possível carregar o grupo', e.message));
            });
        }

        function desenharInfo(c, dados, focarNome) {
            var p = el.telaInfo;
            var id = dados.conversaId;
            p.replaceChildren();

            var topo = criar('div', 'bj-central-info-topo');
            var av = criar('span', 'bj-central-avatar bj-central-avatar--grande', iniciais(dados.nome || ''));
            av.setAttribute('aria-hidden', 'true');
            topo.appendChild(av);
            topo.appendChild(criar('div', 'bj-central-info-nome', dados.nome || ''));
            topo.appendChild(criar('div', 'bj-central-painel-sub', plural(dados.participantes.length, 'participante', 'participantes')));
            p.appendChild(topo);

            // Renomear — qualquer participante (desenho L6191).
            var nome = campoTexto('bj-central-campo-linha', dados.nome, 'Nome do grupo', 'Nome do grupo');
            var renomear = criar('div', 'bj-central-info-linha');
            renomear.appendChild(nome);
            renomear.appendChild(botao('bj-central-btn', 'Renomear', function () {
                pedir(urls.base + '/' + id + '/renomear', { metodo: 'POST', corpo: { nome: nome.value } }).then(function () {
                    avisar('Grupo renomeado.');
                    return carregarConversas().then(function () { abrirInfo(c, false); });
                }).catch(falhaDeAcao);
            }));
            p.appendChild(renomear);

            p.appendChild(criar('div', 'bj-central-info-secao', 'Participantes · ' + dados.participantes.length));
            var naoMembros = estado.contatos.filter(function (ct) { return !dados.participantes.some(function (pp) { return pp.id === ct.id; }); });
            dados.participantes.forEach(function (pp) {
                var linha = criar('div', 'bj-central-info-pessoa');
                var a = criar('span', 'bj-central-avatar', iniciais(pp.nome));
                a.setAttribute('aria-hidden', 'true');
                linha.appendChild(a);
                var textos = criar('div', 'bj-central-linha-textos');
                textos.appendChild(criar('span', 'bj-central-nome', pp.nome + (pp.eu ? ' (você)' : '')));
                var sub = pp.administrador ? 'Administrador do grupo' : (pp.ativoNoEscritorio ? '' : 'Saiu do escritório');
                if (sub) { textos.appendChild(criar('span', 'bj-central-contato-sub', sub)); }
                linha.appendChild(textos);
                if (dados.souAdministrador && !pp.eu) {
                    linha.appendChild(botao('bj-central-btn bj-central-btn--perigo', 'Remover', function () {
                        confirmar('Remover ' + pp.nome + ' do grupo "' + dados.nome + '"? A pessoa deixará de ver o grupo e de receber as mensagens.', 'Remover', function () {
                            pedir(urls.base + '/' + id + '/participantes/' + pp.id, { metodo: 'DELETE' })
                                .then(function () { return carregarConversas().then(function () { abrirInfo(c, false); }); })
                                .catch(falhaDeAcao);
                        });
                    }));
                }
                p.appendChild(linha);
            });

            if (dados.souAdministrador) {   // adicionar — só o administrador (spec B §2)
                p.appendChild(criar('div', 'bj-central-info-secao', 'Adicionar participante'));
                if (naoMembros.length === 0) {
                    p.appendChild(criar('div', 'bj-central-painel-sub', 'Toda a equipe já participa deste grupo.'));
                }
                naoMembros.forEach(function (ct) {
                    var linha = criar('div', 'bj-central-info-pessoa');
                    var a = criar('span', 'bj-central-avatar bj-central-avatar--contato', iniciais(ct.nome));
                    a.setAttribute('aria-hidden', 'true');
                    linha.appendChild(a);
                    linha.appendChild(criar('span', 'bj-central-nome', ct.nome));
                    linha.appendChild(botao('bj-central-btn', 'Adicionar', function () {
                        pedir(urls.base + '/' + id + '/participantes', { metodo: 'POST', corpo: { usuarioId: ct.id } })
                            .then(function () { avisar(primeiroNome(ct.nome) + ' entrou no grupo.'); return carregarConversas().then(function () { abrirInfo(c, false); }); })
                            .catch(falhaDeAcao);
                    }));
                    p.appendChild(linha);
                });
            }

            var atual = estado.conversas.filter(function (x) { return x.id === id; })[0] || c;
            p.appendChild(criar('div', 'bj-central-info-secao', 'Configurações'));
            var conf = criar('div', 'bj-central-painel-acoes bj-central-painel-acoes--coluna');
            conf.appendChild(botao('bj-central-btn', atual.silenciada ? 'Reativar notificações' : 'Silenciar conversa', function () {
                preferir(atual, { silenciada: !atual.silenciada }, atual.silenciada ? 'Notificações reativadas.' : 'Conversa silenciada.')
                    .then(function () { abrirInfo(atual, false); });
            }));
            conf.appendChild(botao('bj-central-btn', atual.fixada ? 'Desafixar conversa' : 'Fixar conversa', function () {
                preferir(atual, { fixada: !atual.fixada }, atual.fixada ? 'Conversa desafixada.' : 'Conversa fixada.')
                    .then(function () { abrirInfo(atual, false); });
            }));
            conf.appendChild(botao('bj-central-btn bj-central-btn--perigo', 'Sair do grupo', function () {
                confirmar('Sair do grupo "' + dados.nome + '"? Você deixará de ver o grupo e de receber as mensagens.', 'Sair', function () {
                    pedir(urls.base + '/' + id + '/sair', { metodo: 'POST', corpo: {} }).then(function () {
                        avisar('Você saiu do grupo.');
                        fecharConversa();
                    }).catch(falhaDeAcao);
                });
            }));
            p.appendChild(conf);
            if (focarNome) { nome.focus(); nome.select(); }
        }

        // ── Despachar via Chat I.A (telaDespacho, BC:6218-6238; spec encaminhar-via-chat-ia §4.1) ──────────────────
        // Só identificadores vêm de fora (BJCentral.despachar): o cartão e os destinos são relidos no servidor.
        function abrirDespacho(pedido) {   /* despachar, BC:6645: sai da conversa e de qualquer painel */
            var p = pedido || {};
            var pastaId = Number(p.pastaId);
            var tipo = typeof p.tipo === 'string' ? p.tipo : '';
            var itemId = tipo === 'pasta' || p.itemId === null || p.itemId === undefined ? null : Number(p.itemId);
            if (!Number.isInteger(pastaId) || pastaId <= 0 || tipo === '' || (itemId !== null && (!Number.isInteger(itemId) || itemId <= 0))) { return; }
            fecharMenu(false);
            fecharPainelMenu(false);
            fecharConfirmacao(false);
            fecharSugestoes();
            if (estado.aberta) {
                cancelarResposta();
                estado.aberta = null;
                estado.escolhidas = [];
                var aviso = el.telaConversa.querySelector('.bj-central-aviso');
                if (aviso) { aviso.remove(); }
            }
            estado.verOnline = false;
            estado.ajuda = false;
            mostrarTela('despacho');
            var d = { pastaId: pastaId, tipo: tipo, itemId: itemId, prio: 'normal', enviando: false };
            estado.despacho = d;
            desenharDespacho(d);
        }

        function icone(classe) {
            var i = criar('i', 'bi ' + classe);
            i.setAttribute('aria-hidden', 'true');
            return i;
        }

        function desenharDespacho(d) {
            var p = el.telaDespacho;
            p.replaceChildren();
            var topo = criar('div', 'bj-central-despacho-topo');   /* BC:6222 */
            var cab = criar('div', 'bj-central-despacho-cab');     /* BC:6223 */
            var ic = icone('bi-send');
            ic.classList.add('bj-central-despacho-cab-ico');
            cab.appendChild(ic);
            cab.appendChild(criar('span', 'bj-central-despacho-titulo', 'Despachar via Chat I.A'));
            cab.appendChild(botao('bj-central-despacho-cancelar', 'Cancelar', sairDoDespacho));
            topo.appendChild(cab);

            var cartao = criar('div', 'bj-central-despacho-cartao');   /* BC:6224 */
            var icTipo = icone(ICONE_DO_DESPACHO[TIPO_DO_DESENHO[d.tipo] || d.tipo] || 'bi-send');
            icTipo.classList.add('bj-central-despacho-cartao-ico');
            cartao.appendChild(icTipo);
            var textos = criar('div', 'bj-central-despacho-cartao-textos');
            var titulo = criar('span', 'bj-central-despacho-cartao-titulo', '');
            var sub = criar('span', 'bj-central-despacho-cartao-sub', '');
            textos.appendChild(titulo);
            textos.appendChild(sub);
            cartao.appendChild(textos);
            topo.appendChild(cartao);

            var instrucao = criar('textarea', 'bj-central-despacho-instrucao');   /* BC:6225 */
            instrucao.placeholder = 'Instrução (opcional). Ex.: favor concluir até sexta.';
            instrucao.setAttribute('aria-label', 'Instrução (opcional)');
            instrucao.maxLength = INSTRUCAO_MAXIMA;   /* em UTF-16: mais estrito que o servidor (emoji conta 2), nunca recusado */
            topo.appendChild(instrucao);

            var prios = criar('div', 'bj-central-despacho-prioridades');   /* BC:6226 */
            PRIORIDADES.forEach(function (par) {
                var b = botao('bj-central-despacho-prio', par[1], function () {
                    d.prio = par[0];
                    prios.querySelectorAll('.bj-central-despacho-prio').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
                });
                b.setAttribute('aria-pressed', String(d.prio === par[0]));
                prios.appendChild(b);
            });
            topo.appendChild(prios);
            p.appendChild(topo);

            var erro = criar('div', 'bj-central-despacho-erro');
            erro.setAttribute('role', 'alert');
            erro.hidden = true;
            p.appendChild(erro);
            p.appendChild(criar('div', 'bj-central-despacho-rotulo', 'Para quem?'));   /* BC:6228 */
            var lista = criar('div', 'bj-central-despacho-destinos');
            p.appendChild(lista);
            d.instrucao = instrucao;
            d.erro = erro;
            d.lista = lista;

            var consulta = '?pasta=' + encodeURIComponent(d.pastaId);
            var doItem = consulta + '&tipo=' + encodeURIComponent(d.tipo) + (d.itemId !== null ? '&item=' + encodeURIComponent(d.itemId) : '');
            pedir(urls.encaminharItem + doItem).then(function (item) {
                if (estado.despacho !== d) { return; }
                titulo.textContent = item.titulo || '';
                sub.textContent = item.resumo || '';
                return pedir(urls.encaminharDestinos + consulta).then(function (dados) {
                    if (estado.despacho !== d) { return; }
                    var destinos = Array.isArray(dados.destinos) ? dados.destinos : [];
                    destinos.forEach(function (dest) { lista.appendChild(linhaDoDestino(d, dest)); });
                    if (!destinos.length) { lista.appendChild(vazio('bi-people', 'Ninguém para receber.', 'Só aparece quem vê esta pasta.')); }
                });
            }).catch(function (e) { falhaDoDespacho(d, e); });
            instrucao.focus();
        }

        function linhaDoDestino(d, dest) {   /* BC:6230 e BC:6237 */
            var travado = dest.disponivel === false;
            var b = criar('button', 'bj-central-despacho-destino' + (travado ? ' bj-central-despacho-destino--travado' : ''));
            b.type = 'button';
            var membro = !dest.grupo && dest.usuarioId ? membroDaEquipe(dest.usuarioId) : null;
            var av = dest.grupo
                ? criar('span', 'bj-central-avatar bj-central-avatar--grupo', iniciais(dest.nome))
                : avatarDe({ nome: dest.nome, foto: membro ? membro.foto : null }, '');
            av.setAttribute('aria-hidden', 'true');
            b.appendChild(av);
            b.appendChild(criar('span', 'bj-central-despacho-destino-nome', dest.nome));
            // Grupo: "Grupo" (BC:6237); travado, o motivo no lugar. Pessoa: a função do desenho é o cargo da equipe (F2).
            b.appendChild(criar('span', 'bj-central-despacho-destino-sub', travado ? (dest.motivo || '') : (dest.grupo ? 'Grupo' : (membro && membro.cargo) || '')));
            if (travado) {
                b.disabled = true;
                b.title = dest.motivo || '';
                return b;
            }
            b.addEventListener('click', function () { despachar(d, dest); });
            return b;
        }

        function despachar(d, dest) {   /* BC:6231-6236: envia, abre a conversa do destino e avisa */
            if (d.enviando || estado.despacho !== d) { return; }
            d.enviando = true;
            d.erro.hidden = true;
            travarDestinos(d, true);
            var instrucao = d.instrucao.value.trim();
            var corpo = { pastaId: d.pastaId, tipo: d.tipo, itemId: d.itemId, instrucao: instrucao === '' ? null : instrucao, prioridade: d.prio };
            if (dest.tipo === 'colega') { corpo.usuarioId = dest.usuarioId; } else { corpo.conversaId = dest.conversaId; }
            pedir(urls.encaminhar, { metodo: 'POST', corpo: corpo }).then(function (r) {
                /* Despacho feito sempre avisa, mesmo se a pessoa saiu da tela no meio (pelo ⋮ ou pelo BJCentral). */
                avisar('Despachado para ' + (r.destinoNome || dest.nome) + '.');
                var aindaAqui = estado.despacho === d;
                carregarConversas().then(function () {
                    if (!aindaAqui || estado.despacho !== d) { return; }
                    var c = estado.conversas.filter(function (x) { return x.id === r.conversaId; })[0];
                    if (c) { abrirConversa(c); } else { mostrarTela('lista'); desenharLista(); }
                }).catch(function (e) {
                    /* A lista não veio: a tela não fica presa no despacho já feito. */
                    if (estado.despacho === d) { mostrarTela('lista'); desenharLista(); }
                    tratarFalha(e);
                });
            }, function (e) {
                d.enviando = false;
                travarDestinos(d, false);
                falhaDoDespacho(d, e);
            });
        }

        // Cancelar e Esc: com o envio em andamento ficam travados (o despacho pode já ter sido feito no servidor).
        function sairDoDespacho() {
            if (estado.despacho && estado.despacho.enviando) { return; }
            mostrarTela('lista');
            desenharLista();
        }

        function travarDestinos(d, sim) {
            d.lista.querySelectorAll('.bj-central-despacho-destino:not(.bj-central-despacho-destino--travado)').forEach(function (b) { b.disabled = sim; });
        }

        function falhaDoDespacho(d, e) {   /* o `erro` do servidor, na própria tela */
            if (estado.despacho !== d) { return; }
            d.erro.textContent = e && e.message ? e.message : 'Falha na Central.';
            d.erro.hidden = false;
            if (e && e.sessao) { tratarFalha(e); }
        }

        // BJCentral.conversar (spec §4.1, conversar BC:6646): vai para a 1:1; si mesmo ou id torto só abre a janela.
        function conversarCom(userId) {
            var id = Number(userId);
            if (!Number.isInteger(id) || id <= 0 || String(id) === String(raiz.dataset.euId || '') || estado.semSessao) { return; }
            iniciarConversaCom({ id: id }, false, true);
        }

        /**
         * Chip "Pasta N" (BC:6041) da mensagem de despacho. Sai SÓ da referência que o servidor manda (`despacho`,
         * `{pastaId, nup}`, presente quando eu vejo a pasta agora), nunca do texto: quem digita no mesmo formato não
         * ganha chip. Abre a pasta, como o `bj-navegar` do desenho.
         */
        function chipDaPasta(despacho) {
            var id = despacho ? Number(despacho.pastaId) : NaN;
            if (!Number.isInteger(id) || id <= 0) { return null; }
            var nup = typeof despacho.nup === 'string' && despacho.nup !== '' ? despacho.nup : null;
            var rotulo = 'Pasta ' + (nup !== null ? nup : String(id));
            var chip = criar('a', 'bj-central-chip-pasta');
            chip.href = urls.pastaBase + String(id);
            chip.appendChild(icone('bi-folder2-open'));
            chip.appendChild(document.createTextNode(rotulo));
            chip.title = rotulo;
            chip.addEventListener('click', function (ev) {
                /* Ctrl, Cmd, Shift, Alt ou outro botão: o padrão do navegador, em qualquer modo. */
                if (ev.button !== 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey) { return; }
                /* Destacada: aba nova, e a Central segue aberta (navegar a página de origem fecharia o pop-up). */
                if (vista() !== window) { ev.preventDefault(); window.open(chip.href, '_blank', 'noopener'); }
            });
            return chip;
        }

        /**
         * O servidor sempre põe a linha "Pasta NUP" (ou "Pasta #id") no fim do primeiro bloco do despacho. Com o chip
         * à vista ela sairia duas vezes: tira só essa linha, e só se for IGUAL à da referência (comparação exata, sem
         * regex). No tipo pasta o título já é a pasta e o texto fica inteiro.
         */
        function semALinhaDaPasta(conteudo, despacho) {
            var texto = String(conteudo || '');
            var linha = ('Pasta ' + (despacho.nup ? despacho.nup : '#' + despacho.pastaId)).split('@[').join('@ [');   /* rotuloDaPasta, desarmado */
            var fim = texto.indexOf('\n\n');
            var bloco = fim === -1 ? texto : texto.slice(0, fim);
            var corte = bloco.lastIndexOf('\n');
            if (corte === -1 || bloco.slice(corte + 1) !== linha) { return texto; }
            return bloco.slice(0, corte) + (fim === -1 ? '' : texto.slice(fim));
        }

        function voltar() {
            if (estado.tela === 'info' && estado.aberta) { mostrarTela('conversa'); ajustarCompositor(); return; }
            if (estado.tela === 'despacho') { sairDoDespacho(); return; }
            if (estado.tela === 'novoGrupo') { mostrarTela('lista'); desenharLista(); return; }
            fecharConversa();
        }

        function menuDaMensagem(m, x, y, origem) {   // menuMensagem, L5873 — Responder, Copiar, Mencionar
            if (m.sistema) { return; }
            var itens = [];
            /* Despacho de pasta que eu não vejo: nada útil a citar ou copiar; só "Mencionar" o autor. */
            var oculta = !!m.ocultoPorPermissao;
            var comIa = !!(estado.aberta && estado.aberta.ia);   // F3: na conversa da IA só se copia (não há resposta nem menção)
            if (podeEscrever() && !oculta && !comIa) {
                itens.push({ icone: 'bi-reply', rotulo: 'Responder', acao: function () { responderA(m); } });
            }
            if (!oculta) {
                itens.push({ icone: 'bi-clipboard', rotulo: 'Copiar', acao: function () { copiar(comoTexto(m.conteudo)); } });
            }
            var autor = !m.minha && !comIa && podeEscrever() ? mencionavel(m.autorId) : null;
            if (autor) {
                itens.push({ icone: 'bi-at', rotulo: 'Mencionar ' + primeiroNome(autor.nome), acao: function () { mencionar(autor); } });
            }
            if (!itens.length) { return; }
            abrirMenu(itens, x, y, origem);
        }

        function falhaDeAcao(e) {
            if (e && e.sessao) { tratarFalha(e); return; }
            avisar(e && e.message ? e.message : 'Falha na Central.');
        }

        // ── polling (D2) ─────────────────────────────────────────────────────────────
        function inicializarCursor() {
            return pedir(urls.novidades).then(function (d) {
                if (Number.isFinite(d.cursor)) { estado.cursor = d.cursor; }
                if (Number.isFinite(d.naoLidas)) { estado.naoLidas = d.naoLidas; }
                lerMencoes(d);
                lerAvisos(d);
            });
        }

        function verificarNovidades() {
            // Cursor que não chegou a ser inicializado (a primeira chamada falhou) é re-inicializado — nunca
            // vira 0, que baixaria o histórico inteiro como se fosse novidade.
            if (estado.cursor === null) {
                return inicializarCursor().then(function () { estado.erro = false; }).catch(tratarFalha);
            }
            return pedir(urls.novidades + '?desde=' + estado.cursor).then(function (dados) {
                var estavaComErro = estado.erro;
                estado.erro = false;
                if (Number.isFinite(dados.cursor)) { estado.cursor = Math.max(estado.cursor, dados.cursor); }
                // A janela do servidor (spec B §6) repete as recentes: só o que ainda não foi visto é novidade.
                var mencoesAntes = estado.comMencao.size;
                lerMencoes(dados);
                estado.chegaramImportantes += Math.max(0, estado.comMencao.size - mencoesAntes);   // menção = nível 2 (L384)
                var novas = (Array.isArray(dados.mensagens) ? dados.mensagens : []).filter(function (m) { return !estado.vistas.has(m.id); });
                // Alerta (salto/som do lançador) só quando o total de não lidas SOBE: o servidor já deixa de fora as
                // conversas silenciadas (spec B §4; desenho L137) e as repetições da janela não somam.
                var chegaram = (Number.isFinite(dados.naoLidas) ? Math.max(0, dados.naoLidas - estado.naoLidas) : 0) + lerAvisos(dados);
                novas.forEach(function (m) { estado.vistas.add(m.id); });
                // F3: a fala da IA encerra a pergunta pendente; sem fala nova, confere o status UMA vez neste ciclo.
                if (novas.some(function (m) { return m.deIa === true; })) { encerrarPerguntaDaIa(); } else { conferirPerguntaDaIa(); }
                if (estado.vistas.size > 5000) { estado.vistas = new Set(novas.map(function (m) { return m.id; })); }
                // A conversa aberta recebe as novas mesmo com a tela de informações por cima — senão elas iriam para
                // `vistas` e nunca apareceriam ao voltar.
                var daAberta = estado.aberta ? novas.filter(function (m) { return m.conversaId === estado.aberta.id; }) : [];
                if (daAberta.length > 0) {
                    anexarAoFim(daAberta);
                    marcarLida();
                }
                // Lançador com a janela fechada: só o contador anda — a lista é carregada quando a janela abrir.
                if (flutuante && !estado.janelaAberta) {
                    if (Number.isFinite(dados.naoLidas)) { estado.naoLidas = dados.naoLidas; }
                    emitirContadores(chegaram);
                    if (!dados.completo) { return verificarNovidades(); }
                    return;
                }
                if (chegaram > 0 && novas.length === 0 && dados.naoLidas === estado.naoLidas) { emitirContadores(chegaram); atualizarCabecalho(); }
                if (novas.length > 0 || dados.naoLidas !== estado.naoLidas || estavaComErro || estado.comMencao.size !== mencoesAntes) {
                    return carregarConversas().then(function () {
                        if (chegaram > 0) { emitirContadores(chegaram); }
                        if (!dados.completo) { return verificarNovidades(); }
                    });
                }
                if (!dados.completo) { verificarNovidades(); }
            }).catch(tratarFalha);
        }

        function tratarFalha(e) {
            if (e && e.sessao) {
                estado.semSessao = true;
                pararPolling();
                if (estado.aberta) {
                    // Não fecha a conversa: o rascunho fica no campo; só deixa de dar para enviar.
                    el.compositor.hidden = true;
                    el.telaConversa.querySelectorAll('.bj-central-aviso--ia').forEach(function (a) { a.remove(); });
                    if (!el.telaConversa.querySelector('.bj-central-aviso')) {
                        el.telaConversa.appendChild(criar('div', 'bj-central-aviso', e.message));
                    }
                    return;
                }
            } else {
                estado.erro = true;
            }
            if (!estado.aberta) { desenharLista(); }
        }

        function iniciarPolling() {
            pararPolling();
            if (oculta() || estado.semSessao) { return; }   // aba aberta em segundo plano não consulta
            // Destacada, o relógio é o da janela destacada: a aba do BlueJus pode estar em segundo plano (e o navegador
            // desacelera os timers dela), mas a Central está à vista. Um polling só: o anterior sempre para antes.
            estado.relogio = estado.externa && !estado.externa.document.hidden ? estado.externa : window;
            estado.timer = estado.relogio.setInterval(verificarNovidades, flutuante && !estado.janelaAberta ? POLLING_FECHADA_MS : POLLING_MS);
            // O minuto das resolvidas nasce e morre com o polling, no mesmo relógio (nunca dois ao mesmo tempo).
            estado.timerResolvidas = estado.relogio.setInterval(avancarResolvidas, RELOGIO_DAS_RESOLVIDAS_MS);
        }

        function pararPolling() {
            if (estado.timer !== null) {
                try { estado.relogio.clearInterval(estado.timer); } catch (e) { /* janela destacada já fechada: o timer foi junto */ }
                estado.timer = null;
            }
            if (estado.timerResolvidas !== null) {
                try { estado.relogio.clearInterval(estado.timerResolvidas); } catch (e) { /* idem */ }
                estado.timerResolvidas = null;
            }
        }

        // ── eventos ──────────────────────────────────────────────────────────────────
        el.voltar.addEventListener('click', voltar);
        el.abas.forEach(function (b) {
            b.addEventListener('click', function () { estado.aba = b.dataset.centralAba; estado.filtro = ''; estado.verOnline = false; atualizarCabecalho(); desenharLista(); });
        });
        el.botoesFiltro.forEach(function (b) {
            b.addEventListener('click', function () { estado.filtro = b.dataset.centralFiltro; desenharLista(); });
        });
        // O lançador avisa quando a janela abre ou fecha: polling rápido só com ela à vista, e só então "lê".
        raiz.addEventListener('bj-central:visibilidade', function (ev) {
            estado.janelaAberta = !!(ev.detail && ev.detail.aberta);
            if (!estado.janelaAberta) { fecharMenu(false); fecharSugestoes(); fecharPainelMenu(false); fecharConfirmacao(false); }
            if (estado.semSessao) { return; }
            iniciarPolling();
            if (!estado.janelaAberta) { return; }
            var primeira = estado.listaCarregada ? Promise.resolve() : Promise.all([carregarConversas(), carregarContatos()]);
            carregarEquipe().catch(function () { /* a equipe vem de novo no próximo batimento */ });
            carregarAvisos().catch(function () { /* idem, no próximo polling */ });
            primeira.then(function () { return verificarNovidades(); }).then(marcarLida).catch(tratarFalha);
        });
        // BJCentral (central-flutuante.js) pede a tela de despacho ou a 1:1 com um colega, já com a janela aberta.
        raiz.addEventListener('bj-central:janela-despachar', function (ev) { if (!estado.semSessao) { abrirDespacho(ev.detail); } });
        raiz.addEventListener('bj-central:janela-conversar', function (ev) { conversarCom(ev.detail && ev.detail.userId); });
        el.mais.addEventListener('click', alternarPainelMenu);
        el.online.addEventListener('click', function () {
            estado.verOnline = !estado.verOnline;
            el.busca.value = '';
            atualizarCabecalho();
            desenharLista();
        });
        // Clique fora do ⋮ fecha o painel (no documento em que a Central estiver: a página ou a janela destacada).
        raiz.addEventListener('pointerdown', function (ev) {
            if (estado.painelMenu && !estado.painelMenu.caixa.contains(ev.target) && !el.mais.contains(ev.target)) { fecharPainelMenu(false); }
        });
        // O lançador avisa quando muda uma preferência que aparece no cabeçalho (não perturbe).
        // ... e o status efetivo (⋮ "Status" ou Não perturbe) vai ao servidor na hora, não só no próximo batimento.
        raiz.addEventListener('bj-central:preferencias', function () { atualizarCabecalho(); bater(); });
        el.info.addEventListener('click', function () {
            var c = estado.aberta ? estado.conversas.filter(function (x) { return x.id === estado.aberta.id; })[0] : null;
            if (c) { abrirInfo(c, false); }
        });
        el.ocultarArquivadas.addEventListener('click', function () { estado.verArquivadas = false; desenharLista(); });
        el.busca.addEventListener('input', function () { estado.verOnline = false; desenharLista(); });
        el.compositor.addEventListener('submit', function (ev) { ev.preventDefault(); enviar(); });
        el.campo.addEventListener('input', function () { ajustarAltura(); atualizarSugestoes(); });
        el.campo.addEventListener('click', atualizarSugestoes);
        el.campo.addEventListener('blur', function () { window.setTimeout(fecharSugestoes, 150); });
        el.campo.addEventListener('keydown', function (ev) {
            if (estado.sugestoes && !ev.isComposing) {   // autocomplete aberto: ↑/↓ andam, Enter/Tab escolhem, Esc fecha
                var sug = estado.sugestoes;
                if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                    ev.preventDefault();
                    sug.indice = (sug.indice + (ev.key === 'ArrowDown' ? 1 : -1) + sug.itens.length) % sug.itens.length;
                    desenharSugestoes();
                    return;
                }
                if (ev.key === 'Enter' || ev.key === 'Tab') {
                    ev.preventDefault();
                    escolherSugestao(sug.indice);
                    return;
                }
                if (ev.key === 'Escape') {
                    ev.preventDefault();
                    ev.stopPropagation();
                    fecharSugestoes();
                    return;
                }
            }
            // Enter envia; Shift+Enter quebra linha. ⋮ "Enter envia" desligado (L6130): Enter quebra e Ctrl/⌘+Enter envia.
            var enterEnvia = prefsGuardadas().enterEnvia !== false;
            if (ev.key === 'Enter' && !ev.isComposing && (enterEnvia ? !ev.shiftKey : (ev.ctrlKey || ev.metaKey))) {
                ev.preventDefault();
                enviar();
            }
        });
        el.respondendoCancelar.addEventListener('click', function () { cancelarResposta(); el.campo.focus(); });
        raiz.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape') { return; }
            // Esc desfaz uma camada por vez: menu → resposta em andamento → conversa aberta (L6129).
            if (estado.confirma) { fecharConfirmacao(true); return; }
            if (estado.painelMenu) { fecharPainelMenu(true); return; }
            if (estado.menu) { fecharMenu(true); return; }
            if (estado.respondendo) { cancelarResposta(); return; }
            if (estado.tela === 'info' || estado.tela === 'novoGrupo' || estado.tela === 'despacho' || estado.aberta) { voltar(); }
        });
        window.addEventListener('resize', function () { fecharMenu(false); });
        window.addEventListener('scroll', function () { fecharMenu(false); }, true);
        el.lista.addEventListener('scroll', function () { fecharMenu(false); });
        el.corpo.addEventListener('scroll', function () {
            fecharMenu(false);
            if (el.corpo.scrollTop === 0) { carregarAnteriores(); }
        });
        function aoMudarVisibilidade() {
            iniciarBatimento();
            if (oculta()) {
                pararPolling();
                return;
            }
            bater();
            if (estado.semSessao) { return; }
            verificarNovidades().then(marcarLida);
            iniciarPolling();
        }
        // Destacada, contam as duas: a janela destacada (relógio, "lida") e a página (lançador).
        document.addEventListener('visibilitychange', aoMudarVisibilidade);

        // B2: o lançador avisa quando a Central vai para outra janela (detail.janela) ou volta (null). O nó é o MESMO:
        // conversa aberta, rascunho, cursor e contadores continuam; só o relógio do polling e a visibilidade mudam.
        raiz.addEventListener('bj-central:separada', function (ev) {
            var externa = ev.detail && ev.detail.janela ? ev.detail.janela : null;
            fecharMenu(false);
            fecharPainelMenu(false);
            fecharSugestoes();
            pararPolling();
            estado.externa = externa;
            iniciarBatimento();
            if (externa) {
                ouvirAtividade(externa);   // atividade na Central destacada também conta
                externa.document.addEventListener('visibilitychange', function () { if (estado.externa === externa) { aoMudarVisibilidade(); } });
                externa.addEventListener('resize', function () { fecharMenu(false); });
            }
            if (estado.semSessao) { return; }
            iniciarPolling();
        });

        function carregarContatos() {
            return pedir(urls.contatos).then(function (d) { estado.contatos = d.contatos || []; });
        }

        atualizarCabecalho();
        // Página /central: tudo na hora. Lançador (em TODA página do layout): só o cursor e os contadores — a lista e
        // os contatos esperam a primeira abertura da janela.
        // Página /central (sem lançador, sem o evento de abrir a janela): os avisos vêm já na carga (revisão do F1).
        Promise.all(flutuante ? [inicializarCursor()] : [carregarConversas(), carregarContatos(), inicializarCursor(), carregarAvisos()]).then(function () {
            // As menções chegam pela primeira chamada do polling, que pode responder DEPOIS da lista: refaz a lista e o
            // lançador com elas (sem isto o "@n" só apareceria no próximo polling, 30 s depois com a janela fechada).
            if (estado.tela === 'lista') { desenharLista(); }
            emitirContadores(0);
            // ?conversa=N (link da notificação de menção): só abre se a conversa estiver na MINHA lista.
            var alvo = abrirAoCarregar ? estado.conversas.filter(function (c) { return c.id === abrirAoCarregar; })[0] : null;
            if (alvo) { abrirConversa(alvo); }
        }).catch(tratarFalha).finally(iniciarPolling);
        // Abrir uma página é atividade: sem isto, uma marca antiga (de ontem) deixaria a pessoa "inativa" até mexer.
        try { window.localStorage.setItem(CHAVE_ATIVIDADE, String(Date.now())); } catch (e) { /* sem armazenamento */ }
        ouvirAtividade(window);
        bater();
        iniciarBatimento();
        // Outra aba mudou o status ou o Não perturbe: o cabeçalho e o próximo batimento já usam o novo.
        window.addEventListener('storage', function (ev) {
            if (ev.key === 'bj-central-prefs:' + (raiz.dataset.euId || '0')) { atualizarCabecalho(); bater(); }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-central]').forEach(iniciar);
    });
})();
