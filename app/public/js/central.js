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
    var TITULO = 'Central de Comunicação';
    // Token canônico de menção (o mesmo de MencoesDaCentral::PADRAO): @[Nome](user:ID).
    var PADRAO_MENCAO = /@\[([^\[\]<>]{1,120})\]\(user:(\d{1,10})\)/g;
    var DIGITANDO_MENCAO = /@([^\s@]{0,30})$/;   // L6075
    var MAX_SUGESTOES = 6;                       // L6094

    function iniciar(raiz) {
        var urls = {
            conversas: raiz.dataset.urlConversas,
            contatos: raiz.dataset.urlContatos,
            novidades: raiz.dataset.urlNovidades,
            base: raiz.dataset.urlConversaBase
        };
        var meuNome = raiz.dataset.euNome || '';
        var el = {
            voltar: raiz.querySelector('[data-central-voltar]'),
            cabAvatar: raiz.querySelector('[data-central-cab-avatar]'),
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
            respondendoCancelar: raiz.querySelector('[data-central-respondendo-cancelar]')
        };
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
            menu: null             // {fundo, caixa, origem} quando um menu de contexto está aberto
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
                        erro.sessao = r.status === 403;   // sem vínculo com o escritório (SemAcessoACentral)
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
        function atualizarCabecalho() {
            if (estado.aberta) {
                el.voltar.hidden = false;
                el.cabAvatar.textContent = iniciais(estado.aberta.outro.nome);
                el.cabAvatar.hidden = false;
                el.cabTitulo.textContent = estado.aberta.outro.nome;
                // O desenho mostra presença e setor · função (L6334), que a A1 não tem; só o que é verdade.
                el.cabSub.textContent = estado.aberta.outroAtivo ? '' : 'Saiu do escritório';
                return;
            }
            el.voltar.hidden = true;
            el.cabAvatar.hidden = true;
            el.cabTitulo.textContent = TITULO;
            el.cabSub.textContent = meuNome + ' · ' + (estado.naoLidas > 0
                ? (estado.naoLidas === 1 ? '1 não lida' : estado.naoLidas + ' não lidas')
                : 'em dia');
        }

        // ── lista ────────────────────────────────────────────────────────────────────
        function linhaDaConversa(c) {
            var naoLida = c.naoLidas > 0 || c.marcadaNaoLida;
            var b = criar('button', 'bj-central-linha' + (naoLida ? ' bj-central-linha--nao-lida' : ''));
            b.type = 'button';
            b.setAttribute('role', 'listitem');
            var av = criar('span', 'bj-central-avatar', iniciais(c.outro.nome));
            av.setAttribute('aria-hidden', 'true');
            b.appendChild(av);

            var textos = criar('span', 'bj-central-linha-textos');
            var topo = criar('span', 'bj-central-linha-topo');
            topo.appendChild(criar('span', 'bj-central-rotulo', 'Conversa'));
            if (c.ultima) { topo.appendChild(criar('span', 'bj-central-hora', quando(c.ultima.criadaEm))); }
            textos.appendChild(topo);
            textos.appendChild(criar('span', 'bj-central-nome d-block', c.outro.nome));

            var baixo = criar('span', 'bj-central-linha-baixo');
            // L5669: em conversa individual só a própria mensagem leva prefixo ("Você: "); o nome é de grupo.
            var previa = c.ultima ? (c.ultima.minha ? 'Você: ' : '') + comoTexto(c.ultima.conteudo) : 'Nenhuma mensagem ainda';
            baixo.appendChild(criar('span', 'bj-central-previa', previa));
            if (c.naoLidas > 0) {
                var selo = criar('span', 'bj-central-selo', String(c.naoLidas));
                selo.setAttribute('aria-label', c.naoLidas + ' não lidas');
                baixo.appendChild(selo);
            } else if (c.marcadaNaoLida) {
                var marca = criar('span', 'bj-central-selo bj-central-selo--marca');
                marca.setAttribute('aria-label', 'Marcada como não lida');
                baixo.appendChild(marca);
            }
            textos.appendChild(baixo);
            b.appendChild(textos);
            b.addEventListener('click', function () { abrirConversa(c.id, c.outro, c.outroAtivo); });
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
            if (estado.conversas.length === 0) {
                el.lista.appendChild(vazio('bi-chat-dots', 'Nenhuma conversa ainda', 'Pesquise um colega acima para iniciar.'));
                return;
            }
            estado.conversas.forEach(function (c) { el.lista.appendChild(linhaDaConversa(c)); });
        }

        function carregarConversas() {
            return pedir(urls.conversas).then(function (dados) {
                estado.conversas = dados.conversas || [];
                estado.naoLidas = estado.conversas.reduce(function (t, c) { return t + c.naoLidas; }, 0);
                estado.erro = false;
                if (!estado.aberta) { desenharLista(); }
                atualizarCabecalho();
            });
        }

        // ── conversa aberta ──────────────────────────────────────────────────────────
        function bolha(m) {
            var linha = criar('div', 'bj-central-msg' + (m.minha ? ' bj-central-msg--minha' : ''));
            linha.dataset.id = String(m.id);
            linha.dataset.dia = new Date(m.criadaEm).toDateString();
            var corpoBolha = criar('div', 'bj-central-bolha');
            corpoBolha.tabIndex = 0;
            if (m.respostaA) {   // citação, L6035
                var cit = criar('button', 'bj-central-citacao');
                cit.type = 'button';
                cit.appendChild(criar('span', 'bj-central-citacao-autor', '↩ ' + m.respostaA.autorNome));
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
            if (!estado.aberta || document.hidden || estado.aberta.ultimaId === 0) { return; }
            pedir(urls.base + '/' + estado.aberta.id + '/lida', { metodo: 'POST', corpo: { ateId: estado.aberta.ultimaId } })
                .then(carregarConversas)
                .catch(function () { /* a próxima volta do polling tenta de novo */ });
        }

        function ajustarCompositor() {
            var podeEnviar = estado.aberta && estado.aberta.outroAtivo;
            el.compositor.hidden = !podeEnviar;
            var aviso = el.telaConversa.querySelector('.bj-central-aviso');
            if (!podeEnviar && !aviso) {
                el.telaConversa.appendChild(criar('div', 'bj-central-aviso', 'Este colega não faz mais parte do escritório.'));
            } else if (podeEnviar && aviso) {
                aviso.remove();
            }
        }

        function abrirConversa(id, outro, outroAtivo) {
            fecharMenu();
            estado.aberta = { id: id, outro: outro, outroAtivo: outroAtivo !== false, primeiraId: 0, ultimaId: 0, temMais: false, mencionaveis: [] };
            estado.escolhidas = [];
            cancelarResposta();
            fecharSugestoes();
            el.telaLista.hidden = true;
            el.telaConversa.hidden = false;
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
                abrirConversa(dados.conversa.id, dados.conversa.outro, dados.conversa.outroAtivo);
                carregarConversas().catch(tratarFalha);
            }).catch(function (e) {
                if (e.status === 409 && !tentativa) { iniciarConversaCom(contato, true); return; }
                if (e.sessao) { tratarFalha(e); return; }
                window.alert(e.message);
            });
        }

        function fecharConversa() {
            fecharMenu();
            fecharSugestoes();
            cancelarResposta();
            estado.aberta = null;
            estado.escolhidas = [];
            el.telaConversa.hidden = true;
            el.telaLista.hidden = false;
            var aviso = el.telaConversa.querySelector('.bj-central-aviso');
            if (aviso) { aviso.remove(); }
            atualizarCabecalho();
            if (estado.semSessao) { desenharLista(); return; }
            carregarConversas().catch(tratarFalha);
        }

        function enviar() {
            var texto = el.campo.value.trim();
            if (!estado.aberta || texto === '' || estado.enviando) { return; }
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
                window.alert(e.message);
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
            var itens = lista.filter(function (p) { return semAcento(p.nome).indexOf(termo) !== -1; }).slice(0, MAX_SUGESTOES);
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
            return !!(estado.aberta && estado.aberta.outroAtivo && !estado.semSessao);
        }

        // ── responder (A2) ───────────────────────────────────────────────────────────
        function responderA(m) {   // faixa "Respondendo a…", L6068
            var trecho = comoTexto(m.conteudo);
            estado.respondendo = { id: m.id, autorNome: m.autorNome, minha: m.minha };
            el.respondendoRotulo.textContent = 'Respondendo a ' + (m.minha ? 'você' : m.autorNome);
            el.respondendoTrecho.textContent = trecho.length > 120 ? trecho.slice(0, 120) + '…' : trecho;
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
                return;
            }
            copiarSemClipboard(texto);
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

        function menuDaConversa(c, x, y, origem) {   // menuConversa, L5758 — só Abrir e lida/não lida
            var itens = [{ icone: 'bi-box-arrow-up-right', rotulo: 'Abrir', acao: function () { abrirConversa(c.id, c.outro, c.outroAtivo); } }];
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
            abrirMenu(itens, x, y, origem);
        }

        function menuDaMensagem(m, x, y, origem) {   // menuMensagem, L5873 — Responder, Copiar, Mencionar
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
            window.alert(e && e.message ? e.message : 'Falha na Central.');
        }

        // ── polling (D2) ─────────────────────────────────────────────────────────────
        function inicializarCursor() {
            return pedir(urls.novidades).then(function (d) {
                if (Number.isFinite(d.cursor)) { estado.cursor = d.cursor; }
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
                dados.mensagens = Array.isArray(dados.mensagens) ? dados.mensagens : [];
                var daAberta = estado.aberta ? dados.mensagens.filter(function (m) { return m.conversaId === estado.aberta.id; }) : [];
                if (daAberta.length > 0) {
                    anexarAoFim(daAberta);
                    marcarLida();
                }
                if (dados.mensagens.length > 0 || dados.naoLidas !== estado.naoLidas || estavaComErro) {
                    return carregarConversas().then(function () {
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
            estado.timer = window.setInterval(verificarNovidades, POLLING_MS);
        }

        function pararPolling() {
            if (estado.timer !== null) {
                window.clearInterval(estado.timer);
                estado.timer = null;
            }
        }

        // ── eventos ──────────────────────────────────────────────────────────────────
        el.voltar.addEventListener('click', fecharConversa);
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
            if (estado.menu) { fecharMenu(true); return; }
            if (estado.respondendo) { cancelarResposta(); return; }
            if (estado.aberta) { fecharConversa(); }
        });
        window.addEventListener('resize', function () { fecharMenu(false); });
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

        atualizarCabecalho();
        Promise.all([
            carregarConversas(),
            pedir(urls.contatos).then(function (d) { estado.contatos = d.contatos || []; }),
            inicializarCursor()
        ]).then(function () {
            // ?conversa=N (link da notificação de menção): só abre se a conversa estiver na MINHA lista.
            var alvo = abrirAoCarregar ? estado.conversas.filter(function (c) { return c.id === abrirAoCarregar; })[0] : null;
            if (alvo) { abrirConversa(alvo.id, alvo.outro, alvo.outroAtivo); }
        }).catch(tratarFalha).finally(iniciarPolling);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-central]').forEach(iniciar);
    });
})();
