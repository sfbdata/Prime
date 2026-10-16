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
            grupos: raiz.dataset.urlGrupos
        };
        var meuNome = raiz.dataset.euNome || '';
        var tamanhoMaximo = Number(raiz.dataset.tamanhoMaximo) || 4000;   // Mensagem::TAMANHO_MAXIMO
        var el = {
            voltar: raiz.querySelector('[data-central-voltar]'),
            cabAvatar: raiz.querySelector('[data-central-cab-avatar]'),
            cabEu: raiz.querySelector('[data-central-cab-eu]'),
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
            tela: 'lista',         // B: 'lista' | 'conversa' | 'novoGrupo' | 'info'
            verArquivadas: false,  // B: "Ver conversas arquivadas" (desenho L6260)
            vistas: new Set(),     // B: ids já entregues — a janela do polling repete as recentes (spec B §6)
            aba: 'entrada',        // lançador: 'entrada' | 'painel' (desenho L6366)
            filtro: '',            // lançador: '' (tudo) | 'mencoes' (L5776)
            comMencao: new Set(),  // conversas com menção por ver (servidor: conversasComMencao)
            janelaAberta: !flutuante,
            listaCarregada: false  // flutuante: lista e contatos só carregam na primeira abertura (carga por página)
        };
        var abrirAoCarregar = Number(raiz.dataset.abrirConversa) || null;   // ?conversa=N (link da menção)

        // ── utilitários ──────────────────────────────────────────────────────────────
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
                        var erro = new Error(dados.erro || 'Falha na Central.');
                        erro.status = r.status;
                        // 403 sem `codigo` = sem vínculo com o escritório (sessão); com `sem_permissao_no_grupo` é regra
                        // do grupo (B): a sessão continua.
                        erro.sessao = r.status === 403 && dados.codigo !== 'sem_permissao_no_grupo';
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
            return c.tipo === 'grupo' ? (c.nome || 'Grupo') : (c.outro ? c.outro.nome : '');
        }

        function atualizarCabecalho() {
            // O ⋮ fica no cabeçalho da lista e da conversa, como no desenho (L6339); some nas telas de grupo.
            el.mais.hidden = estado.tela !== 'lista' && estado.tela !== 'conversa';
            el.cabEu.hidden = !(estado.tela === 'lista' && !estado.aberta);
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
                el.cabAvatar.textContent = iniciais(estado.aberta.nome);
                el.cabAvatar.hidden = false;
                el.cabTitulo.textContent = estado.aberta.nome;
                // O desenho mostra presença e setor · função (L6334), que a Central não tem; só o que é verdade.
                el.cabSub.textContent = estado.aberta.grupo
                    ? plural(estado.aberta.participantes, 'participante', 'participantes')
                    : (estado.aberta.outroAtivo ? '' : 'Saiu do escritório');
                return;
            }
            el.voltar.hidden = true;
            el.cabAvatar.hidden = true;
            el.cabTitulo.textContent = TITULO;
            // Subtítulo do desenho (L6338): "nome · N não lidas | em dia | não perturbe". Status manual não existe aqui.
            var silencio = typeof raiz.bjSilencio === 'function' && raiz.bjSilencio();
            el.cabSub.textContent = meuNome + ' · ' + (silencio ? 'não perturbe' : estado.naoLidas > 0
                ? (estado.naoLidas === 1 ? '1 não lida' : estado.naoLidas + ' não lidas')
                : 'em dia');
        }

        // ── lista ────────────────────────────────────────────────────────────────────
        function linhaDaConversa(c) {
            var naoLida = c.naoLidas > 0 || c.marcadaNaoLida;
            var b = criar('button', 'bj-central-linha' + (naoLida ? ' bj-central-linha--nao-lida' : ''));
            b.type = 'button';
            b.setAttribute('role', 'listitem');
            var grupo = c.tipo === 'grupo';
            var av = criar('span', 'bj-central-avatar' + (grupo ? ' bj-central-avatar--grupo' : ''), iniciais(nomeDaConversa(c)));
            av.setAttribute('aria-hidden', 'true');
            b.appendChild(av);

            var textos = criar('span', 'bj-central-linha-textos');
            var topo = criar('span', 'bj-central-linha-topo');
            topo.appendChild(criar('span', 'bj-central-rotulo', grupo ? 'Grupo' : 'Conversa'));
            // Marcas, na ordem do desenho (L5676): fixada, silenciada, arquivada.
            [[c.fixada, 'bi-pin-angle-fill bj-central-marca--fixada', 'Fixada'],
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
            var previa = 'Nenhuma mensagem ainda';
            if (c.ultima) {
                var prefixo = c.ultima.sistema ? '' : (c.ultima.minha ? 'Você: ' : (grupo ? primeiroNome(c.ultima.autorNome) + ': ' : ''));
                previa = prefixo + comoTexto(c.ultima.conteudo);
            } else if (grupo) {
                previa = plural(c.participantes, 'participante', 'participantes');
            }
            baixo.appendChild(criar('span', 'bj-central-previa', previa));
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
            if (estado.aba === 'painel') {
                el.faixaArquivadas.hidden = true;
                desenharPainel();
                return;
            }
            // Arquivada some da lista principal (L320) e volta com "Ver conversas arquivadas".
            el.faixaArquivadas.hidden = !estado.verArquivadas;
            var visiveis = estado.conversas.filter(function (c) {
                return (estado.verArquivadas || !c.arquivada) && (estado.filtro !== 'mencoes' || estado.comMencao.has(c.id));
            });
            if (visiveis.length === 0 && estado.filtro === 'mencoes') {
                el.lista.appendChild(vazio('bi-at', 'Nenhuma menção por ver', 'Quando alguém mencionar você, a conversa aparece aqui.'));
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

        function atualizarAbas() {
            if (!el.abas.length) { return; }
            el.abas.forEach(function (b) { b.setAttribute('aria-selected', b.dataset.centralAba === estado.aba ? 'true' : 'false'); });
            el.abaContador.hidden = totalMensagens() === 0;
            el.abaContador.textContent = String(totalMensagens());
            el.filtros.hidden = estado.aba !== 'entrada';
            el.botoesFiltro.forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.centralFiltro === estado.filtro ? 'true' : 'false'); });
            el.painel.hidden = estado.aba !== 'painel';
            el.lista.hidden = estado.aba === 'painel';
        }

        function desenharPainel() {   // telaPrioridades, L5632 — só os grupos que têm dado no BlueJus
            el.painel.replaceChildren();
            [['mencoes', 'Menções', '#0a7aad', estado.comMencao.size],
             ['mensagens', 'Mensagens', '#0b5f86', totalMensagens()]].forEach(function (g) {
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
                    estado.filtro = g[0] === 'mencoes' ? 'mencoes' : '';
                    desenharLista();
                });
                el.painel.appendChild(card);
            });
        }

        function emitirContadores(chegaram) {   // para o lançador flutuante: badge, "@n", salto e som
            raiz.dispatchEvent(new CustomEvent('bj-central:contadores', {
                bubbles: true,
                detail: { naoLidas: estado.naoLidas, mencoes: estado.comMencao.size, chegaram: chegaram || 0 }
            }));
        }

        function lerMencoes(dados) {
            if (Array.isArray(dados.conversasComMencao)) { estado.comMencao = new Set(dados.conversasComMencao); }
        }

        function carregarConversas() {
            return pedir(urls.conversas).then(function (dados) {
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
            var linha = criar('div', 'bj-central-msg' + (m.minha ? ' bj-central-msg--minha' : ''));
            linha.dataset.id = String(m.id);
            linha.dataset.dia = new Date(m.criadaEm).toDateString();
            var corpoBolha = criar('div', 'bj-central-bolha');
            corpoBolha.tabIndex = 0;
            if (!m.minha && estado.aberta && estado.aberta.grupo) {   // em grupo, quem escreveu (cor por pessoa, L5814)
                var autor = criar('span', 'bj-central-msg-autor', m.autorNome);
                autor.style.color = corNome(m.autorId);
                corpoBolha.appendChild(autor);
            }
            if (m.respostaA) {   // citação, L6035
                var cit = criar('button', 'bj-central-citacao');
                cit.type = 'button';
                if (!m.minha) { cit.style.borderLeftColor = corNome(m.respostaA.autorId); }   // no balão próprio: branca
                cit.appendChild(criar('span', 'bj-central-citacao-autor', '↩ ' + (m.respostaA.minha ? 'Você' : m.respostaA.autorNome)));
                cit.appendChild(criar('span', 'bj-central-citacao-trecho', m.respostaA.trecho));
                cit.title = 'Ir para a mensagem respondida';
                cit.addEventListener('click', function () { irParaMensagem(m.respostaA.id); });
                corpoBolha.appendChild(cit);
            }
            pintarTexto(corpoBolha, m.conteudo);
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
            mensagens.forEach(function (m) {
                if (el.corpo.querySelector('[data-id="' + m.id + '"]')) { return; }
                var todas = el.corpo.querySelectorAll('.bj-central-msg');
                var anterior = todas.length > 0 ? todas[todas.length - 1] : null;
                var sep = separadorSeNovoDia(anterior, m);
                if (sep) { el.corpo.appendChild(sep); }
                el.corpo.appendChild(bolha(m));
                estado.aberta.ultimaId = Math.max(estado.aberta.ultimaId, m.id);
            });
            if (perto || mensagens.some(function (m) { return m.minha; })) {
                el.corpo.scrollTop = el.corpo.scrollHeight;
            }
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
                el.corpo.insertBefore(fragmento, el.corpo.firstChild);
                el.corpo.scrollTop = el.corpo.scrollHeight - altura;
            } else {
                el.corpo.replaceChildren(fragmento);
                if (dados.mensagens.length === 0) {
                    el.corpo.appendChild(vazio('bi-chat', 'Comece a conversa'));
                }
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
            if (!estado.aberta || document.hidden || !estado.janelaAberta || estado.aberta.ultimaId === 0) { return; }
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
            var aviso = el.telaConversa.querySelector('.bj-central-aviso');
            if (aviso) { aviso.remove(); }
            if (!podeEnviar && estado.aberta && !estado.semSessao) {
                el.telaConversa.appendChild(criar('div', 'bj-central-aviso', estado.aberta.fora
                    ? 'Você não participa mais desta conversa.'
                    : 'Este colega não faz mais parte do escritório.'));
            }
        }

        // Só uma tela à vista: lista, conversa, novo grupo ou informações.
        function mostrarTela(nome) {
            estado.tela = nome;
            el.telaLista.hidden = nome !== 'lista';
            el.telaConversa.hidden = nome !== 'conversa';
            el.telaNovoGrupo.hidden = nome !== 'novoGrupo';
            el.telaInfo.hidden = nome !== 'info';
            atualizarCabecalho();
        }

        /**
         * A conversa aberta acompanha a lista: nome e participantes do grupo mudam; e, se ela sumiu da minha lista
         * (fui removido ou saí em outra aba), deixa de dar para escrever.
         */
        function sincronizarAberta() {
            if (!estado.aberta) { return; }
            var atual = estado.conversas.filter(function (c) { return c.id === estado.aberta.id; })[0];
            if (!atual) {
                estado.aberta.fora = true;
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
                id: id, grupo: c.tipo === 'grupo', nome: nomeDaConversa(c), outro: c.outro || null,
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
            pedir(urls.base + '/' + id + '/mensagens').then(function (dados) {
                if (!estado.aberta || estado.aberta.id !== id) { return; }
                estado.aberta.mencionaveis = Array.isArray(dados.mencionaveis) ? dados.mencionaveis : [];
                desenharPagina(dados, false);
                marcarLida();
                if (estado.aberta.outroAtivo) { el.campo.focus(); }
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

        function iniciarConversaCom(contato, tentativa) {
            pedir(urls.conversas, { metodo: 'POST', corpo: { destinatarioId: contato.id } }).then(function (dados) {
                el.busca.value = '';
                abrirConversa(dados.conversa);
                carregarConversas().catch(tratarFalha);
            }).catch(function (e) {
                if (e.status === 409 && !tentativa) { iniciarConversaCom(contato, true); return; }
                if (e.sessao) { tratarFalha(e); return; }
                avisar(e.message);
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
            if (!estado.aberta || texto === '' || estado.enviando) { return; }
            if (tamanhoVisivel(texto) > tamanhoMaximo) {
                avisar('A mensagem passa de ' + tamanhoMaximo + ' caracteres (tem ' + tamanhoVisivel(texto) + ').');
                return;
            }
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
            }).finally(function () {
                estado.enviando = false;
                el.enviar.disabled = false;
            });
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
            document.body.appendChild(area);
            area.select();
            try { document.execCommand('copy'); } catch (e) { /* sem cópia: nada a fazer */ }
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
            if (devolverFoco && origem && document.contains(origem)) { origem.focus(); }
        }

        function abrirMenu(itens, x, y, origem) {
            fecharMenu();
            if (itens.length === 0) { return; }
            var fundo = criar('div', 'bj-central-ctx-fundo');
            var caixa = criar('div', 'bj-central-ctx');
            caixa.setAttribute('role', 'menu');
            itens.forEach(function (it) {
                var b = criar('button', 'bj-central-ctx-item');
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
            caixa.style.left = Math.max(8, Math.min(x, window.innerWidth - largura - 8)) + 'px';
            caixa.style.top = Math.max(8, Math.min(y, window.innerHeight - altura - 8)) + 'px';
            fundo.addEventListener('click', function () { fecharMenu(true); });
            fundo.addEventListener('contextmenu', function (ev) { ev.preventDefault(); fecharMenu(true); });
            caixa.addEventListener('keydown', function (ev) {
                var botoes = Array.prototype.slice.call(caixa.querySelectorAll('.bj-central-ctx-item'));
                var atual = botoes.indexOf(document.activeElement);
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
            abrirMenu(itens, x, y, origem);
        }

        // ── menu ⋮ em painel (menu(), L6244-6293) ─────────────────────────────────────
        // Painel preso ao canto da janela, com seções, chaves e valores. SÓ comandos que funcionam de verdade (D5): as
        // conversas daqui e, no lançador, as preferências da janela (raiz.bjMenuJanela). Chaves e valores mudam sem
        // fechar o painel, como no desenho; os demais itens fecham.
        function secoesDoMenu() {
            var secoes = [{ titulo: 'Conversas', itens: [
                { icone: 'bi-people', rotulo: 'Novo grupo', acao: function () {
                    if (estado.aberta) { fecharConversa(); }
                    abrirNovoGrupo();
                } },
                { icone: 'bi-archive', rotulo: estado.verArquivadas ? 'Ocultar arquivadas' : 'Ver conversas arquivadas', acao: function () {
                    estado.verArquivadas = !estado.verArquivadas;
                    desenharLista();
                } }
            ] }];
            if (typeof raiz.bjMenuJanela === 'function') { secoes = secoes.concat(raiz.bjMenuJanela()); }
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
            } else if (it.valor) {
                b.appendChild(criar('span', 'bj-central-menu-valor', it.valor));
            }
            b.addEventListener('click', function () {
                var manter = it.chave !== undefined || it.valor !== undefined;
                if (!manter) { fecharPainelMenu(false); }
                it.acao();
                if (manter && estado.painelMenu) { desenharPainelMenu(it.rotulo); }
            });
            return b;
        }

        function desenharPainelMenu(focarRotulo) {
            var caixa = estado.painelMenu ? estado.painelMenu.caixa : criar('div', 'bj-central-menu');
            caixa.replaceChildren();
            caixa.setAttribute('role', 'menu');
            caixa.setAttribute('aria-label', 'Mais opções');
            secoesDoMenu().forEach(function (sc, i) {
                if (i > 0) { caixa.appendChild(criar('div', 'bj-central-menu-sep')); }
                if (sc.titulo) { caixa.appendChild(criar('div', 'bj-central-menu-secao', sc.titulo)); }
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
            var alvo = Array.prototype.slice.call(caixa.querySelectorAll('.bj-central-menu-item')).filter(function (b) {
                return !focarRotulo || b.querySelector('.bj-central-menu-rotulo').textContent === focarRotulo;
            })[0] || caixa.querySelector('.bj-central-menu-item');
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

        function voltar() {
            if (estado.tela === 'info' && estado.aberta) { mostrarTela('conversa'); ajustarCompositor(); return; }
            if (estado.tela === 'novoGrupo') { mostrarTela('lista'); desenharLista(); return; }
            fecharConversa();
        }

        function menuDaMensagem(m, x, y, origem) {   // menuMensagem, L5873 — Responder, Copiar, Mencionar
            if (m.sistema) { return; }
            var itens = [];
            if (podeEscrever()) {
                itens.push({ icone: 'bi-reply', rotulo: 'Responder', acao: function () { responderA(m); } });
            }
            itens.push({ icone: 'bi-clipboard', rotulo: 'Copiar', acao: function () { copiar(comoTexto(m.conteudo)); } });
            var autor = !m.minha && podeEscrever() ? mencionavel(m.autorId) : null;
            if (autor) {
                itens.push({ icone: 'bi-at', rotulo: 'Mencionar ' + primeiroNome(autor.nome), acao: function () { mencionar(autor); } });
            }
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
                var novas = (Array.isArray(dados.mensagens) ? dados.mensagens : []).filter(function (m) { return !estado.vistas.has(m.id); });
                // Alerta (salto/som do lançador) só quando o total de não lidas SOBE: o servidor já deixa de fora as
                // conversas silenciadas (spec B §4; desenho L137) e as repetições da janela não somam.
                var chegaram = Number.isFinite(dados.naoLidas) ? Math.max(0, dados.naoLidas - estado.naoLidas) : 0;
                novas.forEach(function (m) { estado.vistas.add(m.id); });
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
            if (document.hidden || estado.semSessao) { return; }   // aba aberta em segundo plano não consulta
            estado.timer = window.setInterval(verificarNovidades, flutuante && !estado.janelaAberta ? POLLING_FECHADA_MS : POLLING_MS);
        }

        function pararPolling() {
            if (estado.timer !== null) {
                window.clearInterval(estado.timer);
                estado.timer = null;
            }
        }

        // ── eventos ──────────────────────────────────────────────────────────────────
        el.voltar.addEventListener('click', voltar);
        el.abas.forEach(function (b) {
            b.addEventListener('click', function () { estado.aba = b.dataset.centralAba; estado.filtro = ''; desenharLista(); });
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
            primeira.then(function () { return verificarNovidades(); }).then(marcarLida).catch(tratarFalha);
        });
        el.mais.addEventListener('click', alternarPainelMenu);
        // Clique fora do ⋮ fecha o painel (no documento em que a Central estiver: a página ou a janela destacada).
        raiz.addEventListener('pointerdown', function (ev) {
            if (estado.painelMenu && !estado.painelMenu.caixa.contains(ev.target) && !el.mais.contains(ev.target)) { fecharPainelMenu(false); }
        });
        // O lançador avisa quando muda uma preferência que aparece no cabeçalho (não perturbe).
        raiz.addEventListener('bj-central:preferencias', atualizarCabecalho);
        el.info.addEventListener('click', function () {
            var c = estado.aberta ? estado.conversas.filter(function (x) { return x.id === estado.aberta.id; })[0] : null;
            if (c) { abrirInfo(c, false); }
        });
        el.ocultarArquivadas.addEventListener('click', function () { estado.verArquivadas = false; desenharLista(); });
        el.busca.addEventListener('input', desenharLista);
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
            if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) {   // Enter envia; Shift+Enter quebra linha
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
            if (estado.tela === 'info' || estado.tela === 'novoGrupo' || estado.aberta) { voltar(); }
        });
        window.addEventListener('resize', function () { fecharMenu(false); });
        window.addEventListener('scroll', function () { fecharMenu(false); }, true);
        el.lista.addEventListener('scroll', function () { fecharMenu(false); });
        el.corpo.addEventListener('scroll', function () {
            fecharMenu(false);
            if (el.corpo.scrollTop === 0) { carregarAnteriores(); }
        });
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                pararPolling();
                return;
            }
            if (estado.semSessao) { return; }
            verificarNovidades().then(marcarLida);
            iniciarPolling();
        });

        function carregarContatos() {
            return pedir(urls.contatos).then(function (d) { estado.contatos = d.contatos || []; });
        }

        atualizarCabecalho();
        // Página /central: tudo na hora. Lançador (em TODA página do layout): só o cursor e os contadores — a lista e
        // os contatos esperam a primeira abertura da janela.
        Promise.all(flutuante ? [inicializarCursor()] : [carregarConversas(), carregarContatos(), inicializarCursor()]).then(function () {
            // As menções chegam pela primeira chamada do polling, que pode responder DEPOIS da lista: refaz a lista e o
            // lançador com elas (sem isto o "@n" só apareceria no próximo polling, 30 s depois com a janela fechada).
            if (estado.tela === 'lista') { desenharLista(); }
            emitirContadores(0);
            // ?conversa=N (link da notificação de menção): só abre se a conversa estiver na MINHA lista.
            var alvo = abrirAoCarregar ? estado.conversas.filter(function (c) { return c.id === abrirAoCarregar; })[0] : null;
            if (alvo) { abrirConversa(alvo); }
        }).catch(tratarFalha).finally(iniciarPolling);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-central]').forEach(iniciar);
    });
})();
