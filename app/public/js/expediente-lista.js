/* =============================================================================
   expediente-lista.js: menus de botão direito, menus suspensos e comportamentos da Lista do Expediente no
   desenho `03 - EXPEDIENTE Lista 1.1.dc.html` (E8 a E8d e E12 da frente `fidelidade-designer`).

   E12 (16/10, pedido do dono: o desenho na íntegra) trouxe o que antes ficava de fora por falta de backend:
   Editar nome rápido (F2), Trancar/Destrancar (só o administrador do escritório), Destacar linha, menu de
   colunas com CSV, pastas por página e os menus próprios de Situação, Responsável, Prioridade e Marcador.
   O trilho (marcadores e filtros do sistema) mora em expediente-trilho.js.

   Teclado: o menu abre também pela tecla de menu (ou Shift+F10); setas, Home/End navegam, Esc fecha e devolve
   o foco, Tab fecha. Nada aqui monta HTML com dado: só textContent.
   ============================================================================= */
(function () {
    'use strict';

    var menu = null;
    var origem = null;   // quem tinha o foco ao abrir
    var aviso = null;
    var avisoTimer = 0;

    /* Região viva criada na carga, vazia (lição da PAS-034c): leitor de tela só anuncia mudança de uma
       região que já existia. O aviso visual fica `aria-hidden`, para não ser lido duas vezes. */
    var vivo = document.createElement('div');
    vivo.className = 'visually-hidden';
    vivo.setAttribute('role', 'status');
    vivo.setAttribute('aria-live', 'polite');
    vivo.setAttribute('aria-atomic', 'true');
    document.body.appendChild(vivo);

    function fechar(devolverFoco) {
        if (!menu) { return; }
        menu.remove();
        menu = null;
        if (devolverFoco && origem && document.contains(origem) && origem.focus) { origem.focus(); }
        origem = null;
    }

    function itens() {
        return menu ? Array.prototype.slice.call(menu.querySelectorAll('.exp-ctx-item')) : [];
    }

    function abrir(titulo, lista, x, y, quem, variante, subtitulo) {
        fechar(false);
        origem = quem || document.activeElement;
        menu = document.createElement('div');
        menu.className = 'exp-ctx' + (variante ? ' exp-ctx--' + variante : '');
        menu.setAttribute('role', 'menu');
        menu.setAttribute('aria-label', titulo || BJ_T.t('expediente.js.lista.editar_nome'));

        if (titulo) {
            var cab = document.createElement('div');
            cab.className = 'exp-ctx-titulo';
            cab.textContent = titulo;
            // Subfrase (ex.: "Ajuste só seu" do menu de colunas): entra antes de medir, para o encaixe na janela valer.
            if (subtitulo) {
                var sub = document.createElement('span');
                sub.className = 'exp-ctx-subtitulo';
                if (subtitulo.icone) {
                    var si = document.createElement('i');
                    si.className = 'bi ' + subtitulo.icone;
                    si.setAttribute('aria-hidden', 'true');
                    sub.appendChild(si);
                    sub.appendChild(document.createTextNode(' '));
                }
                sub.appendChild(document.createTextNode(subtitulo.texto));
                cab.appendChild(sub);
            }
            menu.appendChild(cab);
        }

        lista.forEach(function (it) {
            if (it === '-') {
                var sep = document.createElement('div');
                sep.className = 'exp-ctx-sep';
                sep.setAttribute('role', 'separator');
                menu.appendChild(sep);
                return;
            }
            // E12: um nó pronto (editor do F2, amostras de cor do "Destacar linha"), ou um cabeçalho de seção.
            if (it.no) { menu.appendChild(it.no); return; }
            if (it.secao) {
                var sec = document.createElement('div');
                sec.className = 'exp-ctx-secao';
                if (it.icone) {
                    var si = document.createElement('i');
                    si.className = 'bi ' + it.icone;
                    si.setAttribute('aria-hidden', 'true');
                    sec.appendChild(si);
                }
                sec.appendChild(document.createTextNode(it.secao));
                menu.appendChild(sec);
                return;
            }
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'exp-ctx-item' + (it.perigo ? ' exp-ctx-item--perigo' : '') + (it.apagado ? ' exp-ctx-item--apagado' : '');
            b.setAttribute('role', it.marcado === undefined ? 'menuitem' : 'menuitemcheckbox');
            if (it.marcado !== undefined) { b.setAttribute('aria-checked', it.marcado ? 'true' : 'false'); }
            if (it.dica) { b.setAttribute('data-tip', it.dica); }
            var i = document.createElement('i');
            i.className = 'bi ' + it.icone;
            i.setAttribute('aria-hidden', 'true');
            if (it.corIcone) { i.style.color = it.corIcone; }
            var t = document.createElement('span');
            t.className = 'exp-ctx-rotulo';
            t.textContent = it.rotulo;
            b.appendChild(i);
            b.appendChild(t);
            if (it.atalho || it.extra) {
                var k = document.createElement('span');
                k.className = 'exp-ctx-atalho';
                k.textContent = it.atalho || it.extra;
                b.appendChild(k);
            }
            b.addEventListener('click', function (ev) {
                // `manter`: o menu continua aberto (liga e desliga colunas, como o dc).
                if (it.manter) { ev.stopPropagation(); it.acao(b); return; }
                fechar(false);
                // Depois que ESTE clique terminar de subir: o menu de responsável (e outros) fecham no
                // clique do documento — acionado aqui dentro, abriria e fecharia no mesmo clique.
                setTimeout(it.acao, 0);
            });
            menu.appendChild(b);
        });

        document.body.appendChild(menu);
        var r = menu.getBoundingClientRect();
        var esq = Math.max(8, Math.min(x, window.innerWidth - r.width - 8));
        var topo = Math.max(8, Math.min(y, window.innerHeight - r.height - 8));
        menu.style.left = esq + 'px';
        menu.style.top = topo + 'px';

        var primeiro = itens()[0];
        if (primeiro) { primeiro.focus(); }

        return menu;
    }

    function avisar(texto) {
        if (!aviso) {
            aviso = document.createElement('div');
            aviso.className = 'exp-aviso';
            aviso.setAttribute('aria-hidden', 'true');
            var i = document.createElement('i');
            i.className = 'bi bi-check-circle-fill';
            i.setAttribute('aria-hidden', 'true');
            aviso.appendChild(i);
            aviso.appendChild(document.createElement('span'));
            document.body.appendChild(aviso);
        }
        aviso.querySelector('span').textContent = texto;
        aviso.hidden = false;
        vivo.textContent = '';
        setTimeout(function () { vivo.textContent = texto; }, 50);
        clearTimeout(avisoTimer);
        avisoTimer = setTimeout(function () { aviso.hidden = true; }, 2800);
    }

    /* Copiar: a API do navegador só existe em contexto seguro (https); fora dele, o caminho antigo. */
    function copiar(texto, confirmacao) {
        var feito = function () { avisar(confirmacao); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(texto).then(feito, function () { copiarAntigo(texto) && feito(); });
            return;
        }
        if (copiarAntigo(texto)) { feito(); }
    }

    function copiarAntigo(texto) {
        var area = document.createElement('textarea');
        area.value = texto;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        area.remove();
        return ok;
    }

    function textoLimpo(el) {
        return el ? el.textContent.replace(/\s+/g, ' ').trim() : '';
    }

    /* ── Linha da pasta (dc L.346-367, E12) ────────────────────────────────
       Abrir pasta · Abrir em nova aba · Editar nome rápido (F2) · Alterar responsável · Alterar marcador ·
       Copiar número · Copiar nome · Trancar/Destrancar (só o administrador, o "primário" do desenho) ·
       Destacar linha (6 cores e sem destaque). Na lápide (somente leitura) só abrir e copiar. */
    var DESTAQUES = [['amarelo', BJ_T.t('expediente.js.lista.cor.amarelo')], ['verde', BJ_T.t('expediente.js.lista.cor.verde')], ['azul', BJ_T.t('expediente.js.lista.cor.azul')], ['rosa', BJ_T.t('expediente.js.lista.cor.rosa')], ['laranja', BJ_T.t('expediente.js.lista.cor.laranja')], ['lilas', BJ_T.t('expediente.js.lista.cor.lilas')]];
    var linhaDoMenu = null;
    // Nos modos Lista e Cartões o menu age na linha da tabela (que tem os dados e as URLs) e reflete no item visível.
    var espelhoDoMenu = null;

    function tabela() { return document.getElementById('tabelaPastas'); }
    function souAdmin() { var t = tabela(); return !!t && t.getAttribute('data-sou-admin') === '1'; }
    function tokenLista() { var t = tabela(); return t ? t.getAttribute('data-csrf-lista') : ''; }

    function postar(url, dados) {
        var corpo = new URLSearchParams(dados || {});
        corpo.append('_token', tokenLista());
        return fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: corpo.toString()
        }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); });
    }

    /* Pasta com cadeado em que quem olha está Oculto (data-oculta, spec pasta-cadeado §6): a janela de sigilo
       (dc L.368-384) no lugar da pasta. Quem só visualiza abre a pasta normalmente. */
    function abrirOuAvisar(oculta, nup, pastaId, abrirDeFato) {
        if (oculta) { mostrarSigilo(nup, pastaId); return; }
        abrirDeFato();
    }
    function mostrarSigilo(nup, pastaId) {
        var j = document.getElementById('expSigilo');
        if (!j) { return; }
        j.querySelector('.js-sigilo-nup').textContent = nup;
        j.setAttribute('data-pasta-id', pastaId);
        j.hidden = false;
        var b = j.querySelector('.js-sigilo-fechar');
        if (b) { b.focus(); }
    }
    document.addEventListener('click', function (e) {
        var j = document.getElementById('expSigilo');
        if (!j || j.hidden) { return; }
        if (e.target === j || e.target.closest('.js-sigilo-fechar')) { j.hidden = true; return; }
        if (e.target.closest('.js-sigilo-pedir')) {
            var id = j.getAttribute('data-pasta-id');
            j.hidden = true;
            /* A rota do pedido é a da pasta (pasta_acesso_pedir), com o token da própria pasta, que vem na linha
               (`data-csrf-pedir`, só nas linhas ocultas). Erro: `{status: 'erro', erro, mensagem}`. */
            var linhaDoPedido = document.querySelector('[data-pasta-id="' + id + '"][data-csrf-pedir]');
            /* Pede Visualizar, como o sigiloPedir do desenho 03 ('ver'); o "Pedir para editar" é o da faixa da pasta. */
            var corpoDoPedido = new URLSearchParams({ nivel: 'visualizar', _token: linhaDoPedido ? linhaDoPedido.getAttribute('data-csrf-pedir') : '' });
            fetch(j.getAttribute('data-url-pedir').replace('/0/', '/' + id + '/'), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: corpoDoPedido.toString()
            }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (dados) { return { ok: r.ok, j: dados }; }); }).then(function (r) {
                if (!r.ok) { avisar(r.j.mensagem || r.j.erro || BJ_T.t('expediente.js.lista.sigilo.erro_pedir')); return; }
                avisar(r.j.avisados > 0 ? BJ_T.t('expediente.js.lista.sigilo.pedido_enviado') : BJ_T.t('expediente.js.lista.sigilo.sem_primario'));
            }, function () { avisar(BJ_T.t('expediente.js.lista.sigilo.falha_conexao')); });
        }
    });
    document.addEventListener('keydown', function (e) {
        var j = document.getElementById('expSigilo');
        if (e.key === 'Escape' && j && !j.hidden) { e.preventDefault(); j.hidden = true; }
    });
    /* Clique na linha, no cartão ou na linha simples de pasta trancada: antes do handler que navega. */
    document.addEventListener('click', function (e) {
        var alvo = e.target.closest('#tabelaPastas tr.pasta-row-link[data-oculta="1"], .exp-simples-linha[data-oculta="1"], .exp-cartao[data-oculta="1"]');
        if (!alvo) { return; }
        /* Com um menu aberto, o clique na linha só fecha o menu (dc `abrir`; ExpMenu do #85, se presente). */
        if (window.ExpMenu && window.ExpMenu.abertoNoToque && window.ExpMenu.abertoNoToque()) { return; }
        if (e.target.closest('button, input, select, .js-toggle-situacao')) { return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        if (window.ExpMenu.abertoNoToque()) { return; }   // com um menu aberto, o clique só o fecha (dc `abrir`)
        mostrarSigilo(alvo.getAttribute('data-nup'), alvo.getAttribute('data-pasta-id'));
    }, true);

    /* Os três modos (tabela, Lista, Cartões) ficam todos na página: o que muda numa pasta muda em todos. */
    function outrosModos(linha) {
        var id = linha.getAttribute('data-pasta-id');
        return id ? Array.prototype.slice.call(document.querySelectorAll('.exp-simples-linha[data-pasta-id="' + id + '"], .exp-cartao[data-pasta-id="' + id + '"]')) : [];
    }

    function editorDoNome(linha, nup, nome, espelho) {
        var box = document.createElement('div');
        box.className = 'exp-ctx-editor';
        var rot = document.createElement('span');
        rot.className = 'exp-ctx-editor-rotulo';
        rot.textContent = BJ_T.t('expediente.js.lista.editor.rotulo', {nup: nup});
        var campo = document.createElement('input');
        campo.type = 'text';
        campo.value = nome;
        campo.maxLength = 255;
        campo.setAttribute('aria-label', BJ_T.t('expediente.js.lista.editor.campo'));
        var acoes = document.createElement('div');
        acoes.className = 'exp-ctx-editor-acoes';
        var cancelar = document.createElement('button');
        cancelar.type = 'button';
        cancelar.className = 'exp-ctx-editor-cancelar';
        cancelar.textContent = BJ_T.t('expediente.js.lista.editor.cancelar');
        var salvar = document.createElement('button');
        salvar.type = 'button';
        salvar.className = 'exp-ctx-editor-salvar';
        salvar.textContent = BJ_T.t('expediente.js.lista.editor.salvar');
        acoes.appendChild(cancelar);
        acoes.appendChild(salvar);
        box.appendChild(rot); box.appendChild(campo); box.appendChild(acoes);

        var gravar = function () {
            var v = campo.value.replace(/\s+/g, ' ').trim();
            if (!v) { avisar(BJ_T.t('expediente.js.lista.editor.vazio')); campo.focus(); return; }
            fechar(false);
            postar(linha.getAttribute('data-url-nome'), { nome: v }).then(function (r) {
                if (!r.ok) { avisar(r.j.erro || BJ_T.t('expediente.js.lista.editor.erro')); return; }
                linha.setAttribute('data-nome', r.j.nome);
                var el = linha.querySelector('.js-nome-cliente');
                if (el) { el.textContent = r.j.nome; el.setAttribute('data-tip', r.j.nome); }
                outrosModos(linha).forEach(function (item) {
                    var cli = item.querySelector('.exp-simples-cli, .exp-cartao-cli');
                    if (cli) { cli.textContent = r.j.nome; cli.setAttribute('data-tip', r.j.nome); }
                });
                avisar(BJ_T.t('expediente.js.lista.editor.atualizado', {nup: nup}));
            }, function () { avisar(BJ_T.t('expediente.js.lista.editor.falha_conexao')); });
        };
        cancelar.addEventListener('click', function (e) { e.stopPropagation(); menuDaLinha(linha, parseInt(menu.style.left, 10), parseInt(menu.style.top, 10), false, espelho); });
        salvar.addEventListener('click', function (e) { e.stopPropagation(); gravar(); });
        campo.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); gravar(); }
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cancelar.click(); }
        });
        setTimeout(function () { campo.focus(); campo.select(); }, 0);

        return box;
    }

    function amostrasDeDestaque(linha, nup) {
        var atual = linha.getAttribute('data-dest') || '';
        var box = document.createElement('div');
        box.className = 'exp-ctx-cores';
        DESTAQUES.concat([['', BJ_T.t('expediente.js.lista.destaque.nenhum')]]).forEach(function (c) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'exp-ctx-cor';
            b.setAttribute('data-dest', c[0]);
            b.setAttribute('aria-label', c[1]);
            b.setAttribute('data-tip', c[1]);
            var i = document.createElement('i');
            i.className = 'bi ' + (c[0] === '' ? 'bi-slash-circle' : (atual === c[0] ? 'bi-check-lg' : ''));
            i.setAttribute('aria-hidden', 'true');
            b.appendChild(i);
            b.addEventListener('click', function () {
                fechar(false);
                postar(linha.getAttribute('data-url-destaque'), { cor: c[0] }).then(function (r) {
                    if (!r.ok) { avisar(r.j.erro || BJ_T.t('expediente.js.lista.destaque.erro')); return; }
                    if (r.j.cor) { linha.setAttribute('data-dest', r.j.cor); } else { linha.removeAttribute('data-dest'); }
                    avisar(r.j.cor ? BJ_T.t('expediente.js.lista.destaque.aplicado', {nup: nup, cor: c[1].toLowerCase()}) : BJ_T.t('expediente.js.lista.destaque.removido', {nup: nup}));
                }, function () { avisar(BJ_T.t('expediente.js.lista.destaque.falha_conexao')); });
            });
            box.appendChild(b);
        });

        return box;
    }

    function trancar(linha, nup) {
        if (!souAdmin()) { avisar(BJ_T.t('expediente.js.lista.trancar.so_primario_aviso')); return; }
        postar(linha.getAttribute('data-url-trancar')).then(function (r) {
            if (!r.ok) { avisar(r.j.erro || BJ_T.t('expediente.js.lista.trancar.erro')); return; }
            linha.setAttribute('data-trancada', r.j.trancada ? '1' : '0');
            outrosModos(linha).forEach(function (item) {
                item.setAttribute('data-trancada', r.j.trancada ? '1' : '0');
                var rotulo = item.querySelector('.exp-cartao-nup');
                var cadCartao = rotulo ? rotulo.querySelector('.exp-cadeado') : null;
                if (r.j.trancada && rotulo && !cadCartao) {
                    cadCartao = document.createElement('i');
                    cadCartao.className = 'bi bi-lock exp-cadeado';
                    cadCartao.setAttribute('aria-label', BJ_T.t('expediente.js.lista.trancar.pasta_trancada'));
                    rotulo.appendChild(document.createTextNode(' '));
                    rotulo.appendChild(cadCartao);
                }
                if (!r.j.trancada && cadCartao) { cadCartao.remove(); }
            });
            var cel = linha.querySelector('td[data-col="nup"]');
            var cad = cel ? cel.querySelector('.js-cadeado') : null;
            if (r.j.trancada && cel && !cad) {
                cad = document.createElement('i');
                cad.className = 'bi bi-lock exp-cadeado js-cadeado';
                cad.setAttribute('aria-label', BJ_T.t('expediente.js.lista.trancar.pasta_trancada'));
                cel.appendChild(cad);
            }
            if (cad) {
                if (r.j.trancada) {
                    var dicaCad = BJ_T.t('expediente.js.lista.trancar.dica');
                    if (r.j.por) { dicaCad = BJ_T.t('expediente.js.lista.trancar.dica_por', {dica: dicaCad, por: r.j.por}); }
                    if (r.j.em) { dicaCad = BJ_T.t('expediente.js.lista.trancar.dica_em', {dica: dicaCad, em: r.j.em}); }
                    cad.setAttribute('data-tip', dicaCad);
                } else { cad.remove(); }
            }
            avisar(r.j.trancada ? BJ_T.t('expediente.js.lista.trancar.trancada_aviso', {nup: nup}) : BJ_T.t('expediente.js.lista.trancar.destrancada_aviso', {nup: nup}));
        }, function () { avisar(BJ_T.t('expediente.js.lista.trancar.falha_conexao')); });
    }

    function menuDaLinha(linha, x, y, editando, espelho) {
        var href = linha.getAttribute('data-href');
        if (!href) { return; }
        var nup = linha.getAttribute('data-nup') || textoLimpo(linha.cells[0]);
        var nome = linha.getAttribute('data-nome') || '';
        var excluida = linha.classList.contains('pasta-excluida');
        var trancada = linha.getAttribute('data-trancada') === '1';
        var oculta = linha.getAttribute('data-oculta') === '1';
        var pastaId = linha.getAttribute('data-pasta-id');
        var chip = linha.querySelector('.pasta-resp-chip');
        var marcar = linha.querySelector('.js-marc-pop');
        var temNome = nome !== '';
        linhaDoMenu = linha;
        espelhoDoMenu = espelho || null;

        var lista = [];
        if (editando) { lista.push({ no: editorDoNome(linha, nup, nome, espelho) }); }
        lista.push({ rotulo: BJ_T.t('expediente.js.lista.menu.abrir'), icone: 'bi-folder2-open', acao: function () { abrirOuAvisar(oculta, nup, pastaId, function () { window.location.href = href; }); } });
        lista.push({ rotulo: BJ_T.t('expediente.js.lista.menu.abrir_nova_aba'), icone: 'bi-box-arrow-up-right', acao: function () { abrirOuAvisar(oculta, nup, pastaId, function () { window.open(href, '_blank', 'noopener'); }); } });
        if (!excluida) {
            // Nome montado pela cobrança (credor e devedor) não se edita, como na tela da pasta.
            if (linha.getAttribute('data-nome-editavel') === '0') {
                lista.push({ rotulo: BJ_T.t('expediente.js.lista.editar_nome'), icone: 'bi-pencil', extra: BJ_T.t('expediente.js.lista.menu.da_cobranca'), apagado: true,
                    acao: function () { avisar(BJ_T.t('expediente.js.lista.menu.nome_da_cobranca')); } });
            } else {
                lista.push({ rotulo: BJ_T.t('expediente.js.lista.editar_nome'), icone: 'bi-pencil', atalho: 'F2', acao: function () { menuDaLinha(linha, x, y, true, espelho); } });
            }
            if (chip && !espelho) { lista.push({ rotulo: BJ_T.t('expediente.js.lista.menu.alterar_responsavel'), icone: 'bi-person-badge', acao: function () { chip.click(); } }); }
            if (marcar && !espelho) { lista.push({ rotulo: BJ_T.t('expediente.js.lista.menu.alterar_marcador'), icone: 'bi-tags', acao: function () { marcar.click(); } }); }
        }
        if (nup) { lista.push({ rotulo: BJ_T.t('expediente.js.lista.menu.copiar_numero'), icone: 'bi-hash', acao: function () { copiar(nup, BJ_T.t('expediente.js.lista.menu.numero_copiado', {nup: nup})); } }); }
        if (temNome) { lista.push({ rotulo: BJ_T.t('expediente.js.lista.menu.copiar_nome'), icone: 'bi-clipboard', acao: function () { copiar(nome, BJ_T.t('expediente.js.lista.menu.nome_copiado')); } }); }
        if (!excluida) {
            lista.push('-');
            lista.push({ rotulo: trancada ? BJ_T.t('expediente.js.lista.menu.destrancar') : BJ_T.t('expediente.js.lista.menu.trancar'), icone: trancada ? 'bi-unlock' : 'bi-lock', corIcone: '#141e5c',
                extra: souAdmin() ? '' : BJ_T.t('expediente.js.lista.menu.so_primario'), apagado: !souAdmin(), acao: function () { trancar(linha, nup); } });
            if (!espelho) {
                lista.push('-');
                lista.push({ secao: BJ_T.t('expediente.js.lista.menu.destacar_linha'), icone: 'bi-highlighter' });
                lista.push({ no: amostrasDeDestaque(linha, nup) });
            }
        }

        abrir(editando ? '' : (temNome ? BJ_T.t('expediente.js.lista.menu.titulo_com_nome', {nup: nup, nome: nome}) : BJ_T.t('expediente.js.lista.menu.titulo', {nup: nup})), lista, x, y, espelho || linha, editando ? 'editando' : null);
    }

    /* Linha simples (div com data-href): Enter abre, como um link. */
    document.addEventListener('keydown', function (e) {
        var l = e.key === 'Enter' && e.target.closest && e.target.closest('.exp-simples-linha[data-href]');
        if (l && e.target === l) { e.preventDefault(); l.click(); }
    });

    /* F2 com o menu da linha aberto: o editor do nome no próprio menu (dc `_esc`). */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'F2' || !menu || !linhaDoMenu || menu.classList.contains('exp-ctx--editando') || !document.contains(linhaDoMenu)) { return; }
        if (linhaDoMenu.classList.contains('pasta-excluida') || linhaDoMenu.getAttribute('data-nome-editavel') === '0') { return; }
        e.preventDefault();
        menuDaLinha(linhaDoMenu, parseInt(menu.style.left, 10), parseInt(menu.style.top, 10), true, espelhoDoMenu);
    });

    /* A linha da tabela que tem os dados de um item da Lista ou dos Cartões (mesma pasta). */
    function linhaDaTabela(item) {
        var id = item.getAttribute('data-pasta-id');
        return id ? document.querySelector('#tabelaPastas tbody tr.pasta-row-link[data-pasta-id="' + id + '"]') : null;
    }

    /* F2 numa linha simples focada (ela é a única que recebe foco): abre direto o editor do nome. */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'F2' || menu) { return; }
        var item = e.target.closest && e.target.closest('.exp-simples-linha[data-pasta-id]');
        var linha = item && e.target === item ? linhaDaTabela(item) : null;
        if (!linha || linha.classList.contains('pasta-excluida') || linha.getAttribute('data-nome-editavel') === '0') { return; }
        e.preventDefault();
        var r = item.getBoundingClientRect();
        menuDaLinha(linha, r.left + 24, r.top + r.height, true, item);
    });

    /* O menu do marcador do trilho mora em expediente-trilho.js (E12). */

    /* ── Ligações ───────────────────────────────────────────────────────── */
    document.addEventListener('contextmenu', function (e) {
        var linha = e.target.closest('#tabelaPastas tbody tr.pasta-row-link');
        var espelho = linha ? null : e.target.closest('.exp-simples-linha[data-pasta-id], .exp-cartao[data-pasta-id]');
        if (espelho) { linha = linhaDaTabela(espelho); }
        if (!linha) { return; }
        // Dentro de campo de texto, o menu do navegador (colar, corretor) continua.
        if (e.target.closest('input, textarea, [contenteditable="true"]')) { return; }
        e.preventDefault();
        var x = e.clientX;
        var y = e.clientY;
        if (!x && !y) {   // pela tecla de menu: abre junto do elemento
            var r = (espelho || linha).getBoundingClientRect();
            x = r.left + 24;
            y = r.top + r.height;
        }
        menuDaLinha(linha, x, y, false, espelho);
    });

    document.addEventListener('keydown', function (e) {
        if (!menu) { return; }
        // No editor do F2 as teclas são do campo (Enter salva, Esc volta ao menu, setas andam no texto).
        if (e.target.closest && e.target.closest('.exp-ctx-editor')) { return; }
        var lista = itens();
        var i = lista.indexOf(document.activeElement);
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); fechar(true); return; }
        if (e.key === 'Tab') { e.preventDefault(); fechar(true); return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); lista[(i + 1) % lista.length].focus(); return; }
        if (e.key === 'ArrowUp') { e.preventDefault(); lista[(i - 1 + lista.length) % lista.length].focus(); return; }
        if (e.key === 'Home') { e.preventDefault(); lista[0].focus(); return; }
        if (e.key === 'End') { e.preventDefault(); lista[lista.length - 1].focus(); }
    }, true);

    document.addEventListener('mousedown', function (e) {
        if (menu && !menu.contains(e.target)) { fechar(false); }
    }, true);
    window.addEventListener('scroll', function () { fechar(false); }, true);
    window.addEventListener('resize', function () { fechar(false); });

    // E12: o trilho (expediente-trilho.js) usa o mesmo menu e o mesmo aviso.
    window.ExpMenu = { abrir: abrir, fechar: fechar, aberto: function () { return menu; } };

    /* dc `abrir` da linha: com um menu aberto, o clique na linha só fecha o menu, não abre a pasta. A pergunta é
       feita no mousedown (captura), antes de o próprio clique fechar o menu; o clique da linha lê a resposta. */
    var menuAbertoNoToque = false;
    document.addEventListener('mousedown', function () {
        menuAbertoNoToque = !!(menu || document.querySelector('.exp-pop, #pastaRespMenu:not([hidden])'));
    }, true);
    window.ExpMenu.abertoNoToque = function () { return menuAbertoNoToque; };
    window.ExpCopiar = copiar;
    window.ExpAviso = avisar;
}());

/* =============================================================================
   Cabeçalho fixo da tabela (LX-TAB-02; dc L.145 `sticky top:124px` + `syncCab` L.475).

   O `.table-responsive` rola nos dois eixos e prende qualquer `position: sticky` do thead. Em vez de
   clonar o cabeçalho, como o dc, o próprio thead desce (translateY) enquanto a tabela passa sob o topo fixo
   do sistema. Um cabeçalho só: a ordenação, os estilos de #tabelaPastas e o leitor de tela continuam
   valendo, e na horizontal ele rola junto com as linhas por estar no mesmo contêiner.
   ============================================================================= */
(function () {
    'use strict';

    var agendado = 0;

    // Fundo do topo do sistema (cabeçalho azul + abas), que é fixo; se um dia deixar de ser, o topo é 0.
    function topoFixo() {
        var topo = document.querySelector('.app-header');
        if (!topo) { return 0; }
        var pos = window.getComputedStyle(topo).position;
        return (pos === 'fixed' || pos === 'sticky') ? Math.max(0, topo.getBoundingClientRect().bottom) : 0;
    }

    function posicionar() {
        agendado = 0;
        var t = document.getElementById('tabelaPastas');
        var cab = t && t.tHead;
        if (!cab) { return; }
        // Tabela escondida (cartões, celular): nada a fixar.
        if (!t.offsetParent) { cab.style.transform = ''; return; }
        var r = t.getBoundingClientRect();
        var desce = Math.min(Math.max(0, topoFixo() - r.top), Math.max(0, r.height - cab.offsetHeight));
        cab.style.transform = desce > 0 ? 'translateY(' + desce + 'px)' : '';
    }

    function agendar() {
        if (!agendado) { agendado = window.requestAnimationFrame(posicionar); }
    }

    window.addEventListener('scroll', agendar, { passive: true, capture: true });
    window.addEventListener('resize', agendar);
    // A tabela chega e é trocada por AJAX (filtro, página, marcador, modo de visualização).
    new MutationObserver(agendar).observe(document.body, { childList: true, subtree: true });
    // Alternar tabela/cartões só troca classes; observar atributos entraria em laço com o próprio transform.
    document.addEventListener('click', agendar, true);
    agendar();
}());

/* =============================================================================
   E8d. Faixa de filtros, "Visualizar" e responsável no desenho 1.1 (dc L.98-128, L.159-160).

   - "Visualizar" (AUD-12): abre o menu com os dois modos que existem e o "Painel de marcadores". Quem troca
     o modo é o handler de sempre (`.js-view-toggle`, pasta/_tabela); o painel é o botão de recolher do trilho.
   - Filtro com valor (AUD-10): a caixa fica azul-clara com o texto azul (dc `onBg`/`onCor`), também o da
     situação, que mora na barra do acervo fora do <form> (por isso `form.elements`).
   - Responsável (AUD-25/26), SÓ na Lista, por decisão do dono: iniciais pelas duas primeiras palavras (dc
     `ini2`: THÂMARA THAYS → TT) e "?" no lugar do ícone quando não há responsável. O chip e o avatar são
     parciais compartilhados com a Pasta (`pasta/_resp_chip`, `_partials/_avatar_usuario`): ficam como estão,
     e o ajuste é feito aqui, nos chips do #pastasView, depois de cada desenho (servidor, AJAX ou troca).
   ============================================================================= */
(function () {
    'use strict';

    /* ── Visualizar ─────────────────────────────────────────────────────── */
    function menuVis() { return document.getElementById('expVisualizarMenu'); }
    function botaoVis() { return document.querySelector('.js-visualizar'); }
    function itensVis() {
        var m = menuVis();
        return m ? Array.prototype.slice.call(m.querySelectorAll('.exp-vis-item')) : [];
    }
    function painelAberto() {
        var s = document.getElementById('expedienteSidebar');
        return !!s && !s.classList.contains('sidebar-collapsed');
    }
    function fecharVis(devolverFoco) {
        var m = menuVis();
        var b = botaoVis();
        if (!m || m.hidden) { return; }
        m.hidden = true;
        if (b) {
            b.setAttribute('aria-expanded', 'false');
            if (devolverFoco) { b.focus(); }
        }
    }
    function abrirVis() {
        var m = menuVis();
        var b = botaoVis();
        if (!m || !b) { return; }
        var painel = m.querySelector('.js-vis-painel');
        if (painel) { painel.setAttribute('aria-checked', painelAberto() ? 'true' : 'false'); }
        m.hidden = false;
        b.setAttribute('aria-expanded', 'true');
        var marcado = m.querySelector('.js-view-toggle[aria-checked="true"]') || itensVis()[0];
        if (marcado) { marcado.focus(); }
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('.js-visualizar')) {
            var m = menuVis();
            if (m && m.hidden) { abrirVis(); } else { fecharVis(false); }
            return;
        }
        if (e.target.closest('.js-vis-painel')) {
            var recolher = document.getElementById('expedienteSidebarToggleBtn');
            if (recolher) { recolher.click(); }
            fecharVis(true);
            return;
        }
        if (e.target.closest('.exp-vis-item')) { fecharVis(true); return; }
        if (!e.target.closest('.exp-visualizar')) { fecharVis(false); }
    });
    // Botão direito (ou qualquer pressão) fora dele também fecha: senão o menu de contexto abria com o
    // Visualizar aberto e os dois disputavam as setas do teclado.
    document.addEventListener('mousedown', function (e) {
        if (!e.target.closest('.exp-visualizar')) { fecharVis(false); }
    }, true);

    document.addEventListener('keydown', function (e) {
        var m = menuVis();
        if (!m || m.hidden) { return; }
        var lista = itensVis();
        var i = lista.indexOf(document.activeElement);
        if (e.key === 'Escape') { e.preventDefault(); fecharVis(true); return; }
        if (e.key === 'Tab') { fecharVis(false); return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); lista[(i + 1) % lista.length].focus(); return; }
        if (e.key === 'ArrowUp') { e.preventDefault(); lista[(i - 1 + lista.length) % lista.length].focus(); return; }
        if (e.key === 'Home') { e.preventDefault(); lista[0].focus(); return; }
        if (e.key === 'End') { e.preventDefault(); lista[lista.length - 1].focus(); }
    });

    /* ── Filtro com valor ───────────────────────────────────────────────── */
    function marcarFiltros() {
        var form = document.getElementById('formFiltrosPasta');
        if (!form) { return; }
        Array.prototype.forEach.call(form.elements, function (el) {
            if (el.matches('.js-pasta-busca')) {
                var caixa = el.closest('.pasta-filtros-busca');
                if (caixa) { caixa.classList.toggle('is-ativo', el.value.trim() !== ''); }
            } else if (el.matches('select.js-pasta-filtro')) {
                var envolto = el.closest('.exp-filtro, .exp-situacao');
                if (envolto) {
                    envolto.classList.toggle('is-ativo', el.value !== '');
                    envolto.setAttribute('data-valor', el.value);
                }
            }
        });
        var periodo = form.querySelector('.pasta-filtros-periodo');
        if (periodo) {
            periodo.classList.toggle('is-ativo', Array.prototype.some.call(
                periodo.querySelectorAll('input[type="date"]'), function (d) { return d.value !== ''; }));
        }
    }
    document.addEventListener('input', marcarFiltros, true);
    document.addEventListener('change', marcarFiltros, true);

    /* ── Responsável na Lista ───────────────────────────────────────────── */
    function iniciaisDoDesenho(nome) {
        return nome.trim().split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0); }).join('').toUpperCase();
    }
    function ajustarResponsaveis() {
        document.querySelectorAll('#pastasView .pasta-resp-chip').forEach(function (chip) {
            var nomeEl = chip.querySelector('.pasta-resp-nome');
            var nome = chip.dataset.respId && nomeEl ? nomeEl.textContent : '';
            if (nome) {
                var ini = iniciaisDoDesenho(nome);
                var comIniciais = chip.querySelector('.resp-avatar-ini');
                if (comIniciais && comIniciais.textContent !== ini) { comIniciais.textContent = ini; }
                var comFoto = chip.querySelector('.resp-avatar-img');
                if (comFoto) { comFoto.setAttribute('data-ini', ini); }   // se a foto falhar, cai nestas iniciais
            }
            var vazio = chip.querySelector('.resp-avatar-vazio:not(.exp-resp-vazio)');
            if (vazio) {
                vazio.classList.add('exp-resp-vazio');
                vazio.textContent = '?';
            }
        });
    }

    /* Depois de cada desenho: servidor, troca por AJAX (filtro, página, marcador) e o redesenho do chip
       ao escolher um responsável (_resp_script). Só lista de filhos: as mudanças daqui não realimentam. */
    var agendado = 0;
    function aplicar() {
        agendado = 0;
        marcarFiltros();
        ajustarResponsaveis();
    }
    function agendar() {
        if (!agendado) { agendado = window.requestAnimationFrame(aplicar); }
    }
    new MutationObserver(agendar).observe(document.body, { childList: true, subtree: true });
    agendar();
}());

/* =============================================================================
   E12. O resto do desenho 1.1 online (spec E12-LISTA-INTEGRAL.md):
   - dicas do desenho (dc L.386, `_ov`): azul-claras, só quando o texto está cortado ou não está escrito;
   - Situação, Responsável e Prioridade em menus próprios (dc L.123-147). O <select> continua sendo o campo do
     formulário (FormData, estado salvo, "Limpar"); o menu só escolhe o valor e avisa com `change`;
   - "Limpar todos os filtros" em ícone (dc L.149), título do acervo que volta ao início (dc L.120);
   - "Pastas por página" (dc L.159-164); menu de colunas com CSV (dc L.179, L.280-293);
   - menu MARCADOR da linha (dc L.216-227); sobreposição da BlueJus I.A. (dc L.102-117, evento `bj-ai-fx`).
   ============================================================================= */
(function () {
    'use strict';

    function aviso(t) { if (window.ExpAviso) { window.ExpAviso(t); } }
    function form() { return document.getElementById('formFiltrosPasta'); }
    function ini2(nome) { return String(nome || '').trim().split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0); }).join('').toUpperCase(); }
    function el(tag, classe, texto) {
        var e = document.createElement(tag);
        if (classe) { e.className = classe; }
        if (texto !== undefined && texto !== null) { e.textContent = texto; }
        return e;
    }
    function icone(classe) { var i = el('i', 'bi ' + classe); i.setAttribute('aria-hidden', 'true'); return i; }

    /* ── Dicas ─────────────────────────────────────────────────────────── */
    var dica = null;
    function fecharDica() { if (dica) { dica.remove(); dica = null; } }
    function cortado(alvo) {
        return [alvo].concat(Array.prototype.slice.call(alvo.querySelectorAll('*'))).some(function (n) { return n.scrollWidth > n.clientWidth + 1; });
    }
    document.addEventListener('mouseover', function (e) {
        if (!document.body.classList.contains('exp-body')) { return; }
        var alvo = e.target.closest && e.target.closest('[data-tip]');
        var menuAberto = window.ExpMenu && window.ExpMenu.aberto();
        // dc `_ov`: com um menu aberto (filtro, responsável da linha ou contexto), nenhuma dica fora dele.
        if (!alvo || (menuAberto && !menuAberto.contains(alvo)) || document.querySelector('.exp-pop, #pastaRespMenu:not([hidden])')) { fecharDica(); return; }
        var t = alvo.getAttribute('data-tip');
        if (!t) { fecharDica(); return; }
        var texto = (alvo.textContent || '').replace(/\s+\d+x$/, '').replace(/\s+/g, ' ').trim();
        if (texto && texto.indexOf(t.trim()) === 0 && !cortado(alvo)) { fecharDica(); return; }
        if (dica && dica.textContent === t) { return; }
        fecharDica();
        dica = el('div', 'exp-dica', t);
        dica.setAttribute('role', 'tooltip');
        document.body.appendChild(dica);
        var r = alvo.getBoundingClientRect();
        dica.style.left = Math.min(window.innerWidth - 330, Math.max(8, r.left)) + 'px';
        dica.style.top = (r.bottom + 8 > window.innerHeight - 60 ? r.top - dica.offsetHeight - 8 : r.bottom + 8) + 'px';
    });
    document.addEventListener('scroll', fecharDica, true);
    document.addEventListener('mousedown', fecharDica, true);

    /* ── Menus suspensos do desenho (Situação, Responsável, Prioridade, Marcador) ── */
    var pop = null;
    var popAncora = null;
    function fecharPop(devolver) {
        if (!pop) { return; }
        pop.remove();
        pop = null;
        if (devolver && popAncora && popAncora.focus) { popAncora.focus(); }
        popAncora = null;
    }
    /* o: { titulo, icone, largura, busca (placeholder), itens: [{ rotulo, desc, ponto, bandeira, avatar, pasta, nivel, marcado, acao }],
       rodape: { rotulo, icone, acao }, vazio } */
    function abrirPop(ancora, o) {
        fecharPop(false);
        popAncora = ancora;
        pop = el('div', 'exp-pop' + (o.busca ? ' exp-pop--busca' : ''));
        pop.setAttribute('role', 'dialog');
        pop.setAttribute('aria-label', o.titulo || o.busca);
        pop.style.width = (o.largura || 290) + 'px';
        var cab = el('div', 'exp-pop-cab');
        var fecharB = el('button', 'exp-pop-x');
        fecharB.type = 'button';
        fecharB.setAttribute('aria-label', BJ_T.t('expediente.js.lista.fechar'));
        fecharB.setAttribute('data-tip', BJ_T.t('expediente.js.lista.fechar'));
        fecharB.appendChild(icone('bi-x-lg'));
        fecharB.addEventListener('click', function () { fecharPop(true); });
        var campo = null;
        if (o.busca) {
            var rot = el('label', 'exp-pop-busca');
            rot.appendChild(icone('bi-search'));
            campo = el('input');
            campo.type = 'text';
            campo.placeholder = o.busca;
            campo.setAttribute('aria-label', o.busca);
            rot.appendChild(campo);
            cab.appendChild(rot);
        } else {
            cab.appendChild(icone(o.icone));
            cab.appendChild(el('span', 'exp-pop-titulo', o.titulo));
        }
        cab.appendChild(fecharB);
        pop.appendChild(cab);
        var corpo = el('div', 'exp-pop-corpo');
        corpo.setAttribute('role', 'listbox');
        pop.appendChild(corpo);
        var vazio = el('div', 'exp-pop-vazio', o.vazio || BJ_T.t('expediente.js.lista.nada_encontrado'));
        vazio.hidden = true;

        o.itens.forEach(function (it) {
            var b = el('button', 'exp-pop-item' + (it.marcado ? ' is-marcado' : '') + (it.nivel ? ' is-sub' : ''));
            b.type = 'button';
            b.setAttribute('role', 'option');
            b.setAttribute('aria-selected', it.marcado ? 'true' : 'false');
            b.setAttribute('data-busca', (it.rotulo || '').toLowerCase());
            if (it.ponto) {
                var p = el('span', 'exp-pop-ponto');
                p.style.background = it.ponto; p.style.color = it.ponto;
                if (it.atraso) { p.style.animationDelay = it.atraso; }
                b.appendChild(p);
            }
            if (it.bandeira) { var f = icone('bi-flag-fill exp-pop-bandeira'); f.style.color = it.bandeira; b.appendChild(f); }
            if (it.pasta) { var pa = icone('bi-folder-fill'); pa.style.color = it.pasta; b.appendChild(pa); }
            if (it.avatar !== undefined) {
                var av = el('span', 'exp-pop-av' + (it.avatar === null ? ' is-vazio' : ''));
                if (it.avatar === null) { av.appendChild(icone('bi-person-dash')); } else { av.textContent = it.avatar; }
                if (it.foto) { fotoNoAvatar(av, it.foto); }
                b.appendChild(av);
            }
            var tx = el('span', 'exp-pop-texto');
            tx.appendChild(el('span', 'exp-pop-rotulo', it.rotulo));
            if (it.desc) { tx.appendChild(el('span', 'exp-pop-desc', it.desc)); }
            b.appendChild(tx);
            b.appendChild(icone(it.marcado ? 'bi-check-lg exp-pop-check' : 'exp-pop-check'));
            b.addEventListener('click', function (e) { e.stopPropagation(); fecharPop(false); it.acao(); });
            corpo.appendChild(b);
        });
        corpo.appendChild(vazio);
        if (o.rodape) {
            pop.appendChild(el('div', 'exp-pop-sep'));
            var rb = el('button', 'exp-pop-rodape');
            rb.type = 'button';
            rb.appendChild(icone(o.rodape.icone));
            rb.appendChild(document.createTextNode(o.rodape.rotulo));
            rb.addEventListener('click', function (e) { e.stopPropagation(); fecharPop(false); o.rodape.acao(); });
            pop.appendChild(rb);
        }
        if (campo) {
            campo.addEventListener('input', function () {
                var q = campo.value.trim().toLowerCase();
                var algum = false;
                corpo.querySelectorAll('.exp-pop-item').forEach(function (b) {
                    var sim = !q || b.getAttribute('data-busca').indexOf(q) !== -1;
                    b.hidden = !sim;
                    algum = algum || sim;
                });
                vazio.hidden = algum;
            });
        }
        document.body.appendChild(pop);
        var r = ancora.getBoundingClientRect();
        var largura = pop.offsetWidth;
        var esq = o.alinhar === 'direita' ? r.right - largura : (o.alinhar === 'centro' ? r.left + r.width / 2 - largura / 2 : r.left);
        pop.style.left = Math.max(8, Math.min(esq, window.innerWidth - largura - 8)) + 'px';
        var topo = r.bottom + 6;
        if (topo + pop.offsetHeight > window.innerHeight - 8) { topo = Math.max(8, r.top - pop.offsetHeight - 6); }
        pop.style.top = topo + 'px';
        var foco = campo || corpo.querySelector('.is-marcado') || corpo.querySelector('.exp-pop-item');
        if (foco) { foco.focus(); }
    }
    document.addEventListener('mousedown', function (e) {
        if (pop && !pop.contains(e.target) && !(popAncora && popAncora.contains(e.target))) { fecharPop(false); }
    }, true);
    document.addEventListener('keydown', function (e) {
        if (!pop) { return; }
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); fecharPop(true); return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            var lista = Array.prototype.filter.call(pop.querySelectorAll('.exp-pop-item, .exp-pop-rodape'), function (b) { return !b.hidden; });
            if (!lista.length) { return; }
            e.preventDefault();
            var i = lista.indexOf(document.activeElement);
            lista[(i + (e.key === 'ArrowDown' ? 1 : -1) + lista.length) % lista.length].focus();
        }
    }, true);
    window.addEventListener('resize', function () { fecharPop(false); });

    function escolherNoSelect(select, valor) {
        select.value = valor;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    var SITUACOES = [['', BJ_T.t('expediente.js.lista.situacao.todas'), BJ_T.t('expediente.js.lista.situacao.todas_desc'), '#0a6fa0'], ['ativo', BJ_T.t('expediente.js.lista.situacao.ativo'), BJ_T.t('expediente.js.lista.situacao.ativo_desc'), '#2fa35f'], ['suspenso', BJ_T.t('expediente.js.lista.situacao.suspenso'), BJ_T.t('expediente.js.lista.situacao.suspenso_desc'), '#d18a12'],
        ['cancelado', BJ_T.t('expediente.js.lista.situacao.cancelado'), BJ_T.t('expediente.js.lista.situacao.cancelado_desc'), '#c0392b'], ['arquivado', BJ_T.t('expediente.js.lista.situacao.arquivado'), BJ_T.t('expediente.js.lista.situacao.arquivado_desc'), '#62798a']];
    var PRIORIDADES = [['', BJ_T.t('expediente.js.lista.prioridade.todas'), BJ_T.t('expediente.js.lista.prioridade.todas_desc'), '#0a6fa0'], ['normal', BJ_T.t('expediente.js.lista.prioridade.normal'), BJ_T.t('expediente.js.lista.prioridade.normal_desc'), '#5f7684'],
        ['prioridade', BJ_T.t('expediente.js.lista.prioridade.alta'), BJ_T.t('expediente.js.lista.prioridade.alta_desc'), '#d18a12'], ['urgente', BJ_T.t('expediente.js.lista.prioridade.urgente'), BJ_T.t('expediente.js.lista.prioridade.urgente_desc'), '#c0392b']];

    function popSituacao(caixa) {
        var sel = caixa.querySelector('select');
        abrirPop(caixa, { titulo: BJ_T.t('expediente.js.lista.situacao.titulo'), icone: 'bi-toggles', largura: 290, alinhar: 'direita',
            itens: SITUACOES.map(function (s, i) {
                return { rotulo: s[1], desc: s[2], ponto: s[3], atraso: (i * 0.25) + 's', marcado: sel.value === s[0], acao: function () { escolherNoSelect(sel, s[0]); } };
            }) });
    }
    function popPrioridade(caixa) {
        var sel = caixa.querySelector('select');
        abrirPop(caixa, { titulo: BJ_T.t('expediente.js.lista.prioridade.titulo'), icone: 'bi-flag', largura: 250,
            itens: PRIORIDADES.map(function (p) {
                return { rotulo: p[1], desc: p[2], bandeira: p[3], marcado: sel.value === p[0], acao: function () { escolherNoSelect(sel, p[0]); } };
            }) });
    }
    function popResponsavel(caixa) {
        var sel = caixa.querySelector('select');
        var itens = Array.prototype.filter.call(sel.options, function (op) { return op.value !== '' && !op.hasAttribute('data-equipe'); }).map(function (op) {
            var sem = op.value === 'sem';
            return { rotulo: op.textContent.trim(), avatar: sem ? null : ini2(op.textContent), foto: op.getAttribute('data-foto') || '', marcado: sel.value === op.value,
                acao: function () { escolherNoSelect(sel, sel.value === op.value ? '' : op.value); } };
        });
        abrirPop(caixa, { busca: BJ_T.t('expediente.js.lista.responsavel.filtrar'), largura: 300, itens: itens, vazio: BJ_T.t('expediente.js.lista.responsavel.nenhum') });
    }

    /* dc `avBg` (L.458): com foto, o círculo é a foto (fundo, sem as iniciais por cima); a URL é a rota do servidor. */
    function fotoNoAvatar(av, url) {
        if (!/^\/[\w\-./]*$/.test(url)) { return; }
        av.textContent = '';
        av.style.background = '#0a6fa0 url("' + url + '") center/cover no-repeat';
    }

    /* Rótulos dos campos como o desenho: Responsável com as duas primeiras palavras e avatar; Prioridade "Alta". */
    function pintarCampos() {
        var f = form();
        if (!f) { return; }
        var resp = f.querySelector('select[name="responsavel"]');
        var caixaR = resp ? resp.closest('.exp-filtro') : null;
        if (caixaR) {
            var rot = caixaR.querySelector('.exp-filtro-rotulo');
            if (!rot) { rot = el('span', 'exp-filtro-rotulo'); caixaR.insertBefore(rot, resp); }
            var op = resp.options[resp.selectedIndex];
            // `equipe` / `equipe:<cargo>` (Total de Pastas concluídas do Dashboard): o rótulo inteiro, sem avatar de pessoa.
            var ehEquipe = !!op && op.hasAttribute('data-equipe');
            var nome = resp.value === '' ? BJ_T.t('expediente.js.lista.responsavel.rotulo') : (resp.value === 'sem' ? BJ_T.t('expediente.js.lista.responsavel.sem') : (ehEquipe ? op.textContent.trim() : op.textContent.trim().split(/\s+/).slice(0, 2).join(' ')));
            rot.textContent = '';
            if (resp.value !== '' && resp.value !== 'sem' && !ehEquipe) {
                var avR = el('span', 'exp-filtro-av', ini2(op.textContent));
                if (op.getAttribute('data-foto')) { fotoNoAvatar(avR, op.getAttribute('data-foto')); }
                rot.appendChild(avR);
            }
            rot.appendChild(el('span', 'exp-filtro-nome', nome));
        }
        var prio = f.querySelector('select[name="prioridade"]');
        var caixaP = prio ? prio.closest('.exp-filtro') : null;
        if (caixaP) {
            var rp = caixaP.querySelector('.exp-filtro-rotulo');
            if (!rp) { rp = el('span', 'exp-filtro-rotulo'); caixaP.insertBefore(rp, prio); }
            var achado = PRIORIDADES.filter(function (p) { return p[0] === prio.value; })[0];
            rp.textContent = prio.value === '' ? BJ_T.t('expediente.js.lista.prioridade.rotulo') : achado[1];
        }
        var sit = document.querySelector('.exp-situacao select[name="status"]');
        var caixaS = sit ? sit.closest('.exp-situacao') : null;
        if (caixaS) {
            var rs = caixaS.querySelector('.exp-situacao-rotulo');
            if (!rs) { rs = el('span', 'exp-situacao-rotulo'); caixaS.insertBefore(rs, sit); }
            var s = SITUACOES.filter(function (x) { return x[0] === sit.value; })[0] || SITUACOES[0];
            rs.textContent = s[1];
        }
        // "Limpar todos os filtros" só aparece com algum filtro (dc `temFiltro`: busca, responsável, prioridade, datas).
        var limpar = f.querySelector('.exp-limpar');
        if (limpar) {
            var tem = ['busca', 'responsavel', 'prioridade', 'data_de', 'data_ate'].some(function (n) { var c = f.elements.namedItem(n); return c && c.value.trim() !== ''; });
            limpar.hidden = !tem;
        }
    }

    document.addEventListener('click', function (e) {
        var caixa = e.target.closest('.exp-body .exp-filtro--resp, .exp-body .exp-filtro--prio, .exp-body .exp-situacao');
        if (caixa && !e.target.closest('.exp-pop')) {
            e.preventDefault();
            if (pop && popAncora === caixa) { fecharPop(true); return; }
            if (caixa.classList.contains('exp-situacao')) { popSituacao(caixa); } else if (caixa.classList.contains('exp-filtro--resp')) { popResponsavel(caixa); } else { popPrioridade(caixa); }
            return;
        }
        // Título do acervo: volta ao acervo geral, sem filtros (dc `irPadrao`).
        if (e.target.closest('.exp-body .exp-barra-titulo')) {
            if (window.ExpTrilho) { window.ExpTrilho.abrirTodas(true); } else if (window.ExpPainel && window.ExpPainel.irPadrao) { window.ExpPainel.irPadrao(); }   // Demandas: sem trilho
            return;
        }
        var pp = e.target.closest('.js-por-pagina');
        if (pp) {
            e.preventDefault();
            var f = form();
            var campo = f ? f.querySelector('input[name="por_pagina"]') : null;
            if (campo && window.ExpPainel) {
                campo.value = pp.getAttribute('data-n');
                try { localStorage.setItem('expediente_por_pagina', campo.value); } catch (x) { /* só não lembra */ }
                window.ExpPainel.recarregarResultados({ page: 1 });
            }
        }
    });
    document.addEventListener('keydown', function (e) {
        var caixa = e.target.closest && e.target.closest('.exp-body .exp-filtro--resp, .exp-body .exp-filtro--prio, .exp-body .exp-situacao');
        if (caixa && (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown')) { e.preventDefault(); caixa.click(); }
        var titulo = e.target.closest && e.target.closest('.exp-body .exp-barra-titulo');
        if (titulo && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); titulo.click(); }
    });

    /* ── Colunas da tabela (dc `colVis`, `colItens`, `colCsv`) ─────────── */
    var COLUNAS = [['nup', BJ_T.t('expediente.js.lista.coluna.nup')], ['cliente', BJ_T.t('expediente.js.lista.coluna.cliente')], ['reu', BJ_T.t('expediente.js.lista.coluna.reu')], ['acao', BJ_T.t('expediente.js.lista.coluna.acao')], ['prio', BJ_T.t('expediente.js.lista.coluna.prio')], ['resp', BJ_T.t('expediente.js.lista.coluna.resp')], ['metas', BJ_T.t('expediente.js.lista.coluna.metas')], ['marc', BJ_T.t('expediente.js.lista.coluna.marc')], ['sit', BJ_T.t('expediente.js.lista.coluna.sit')]];
    var ESSENCIAIS = { nup: true, cliente: true, reu: false, acao: true, prio: false, resp: true, metas: false, marc: false, sit: true };
    /* Ajuste só de quem escolheu (dc `colKey`: conta + usuário), guardado no SERVIDOR por escritório e usuário:
       a tabela traz o ajuste em `data-colunas` (`null` = padrão) e a gravação é POST em `data-url-colunas`, com o
       token da Lista no cabeçalho. A chave local da conta e do usuário (`data-chave-colunas`) só serve para trazer,
       uma vez, o ajuste antigo deste navegador para o servidor; depois é apagada. */
    var PREFIXO_COLUNAS_LOCAL = 'expediente_colunas:';
    var colunasEmVoo = null;
    var filaColunas = Promise.resolve(); // um POST por vez, na ordem dos cliques: o servidor fica com o último
    var trazendoAjusteLocal = false;
    function tabelaDaLista() { return document.getElementById('tabelaPastas'); }
    function chaveColunasLocal() {
        var t = tabelaDaLista();
        var dono = t ? t.getAttribute('data-chave-colunas') : '';
        // As Minhas Demandas (desenho 15, dc `colKey` = `bj-dem-cols`) guardam o ajuste antigo em chave própria.
        var prefixo = (t && t.getAttribute('data-prefixo-colunas-local')) || PREFIXO_COLUNAS_LOCAL;

        return dono ? prefixo + dono : '';
    }
    function mapaCompleto(v) {
        var out = {};
        COLUNAS.forEach(function (c) { out[c[0]] = v[c[0]] !== false; });
        out.nup = true;
        return out;
    }
    /* O que vale agora: o que acabou de ser escolhido (enquanto o POST não volta) ou o que o servidor mandou. */
    function colunasSalvas() {
        if (colunasEmVoo) { return colunasEmVoo.mapa; }
        var t = tabelaDaLista();
        if (!t) { return null; }
        var v = null;
        try { v = JSON.parse(t.dataset.colunas || 'null'); } catch (x) { v = null; }
        return v && typeof v === 'object' ? v : null;
    }
    /* Sem ajuste salvo vale o padrão da tela: o Expediente mostra todas; as Demandas trazem o delas em
       `data-colunas-padrao` (desenho 15, dc `COL_PADRAO`). */
    function colunasPadrao() {
        var t = tabelaDaLista();
        var p = null;
        try { p = JSON.parse((t && t.getAttribute('data-colunas-padrao')) || 'null'); } catch (x) { p = null; }
        return p && typeof p === 'object' ? p : {};
    }
    function colunasVisiveis() {
        return mapaCompleto(colunasSalvas() || colunasPadrao());
    }
    /* `null` volta ao padrão (o servidor apaga o ajuste). Sem conexão, avisa e a tela fica como a pessoa deixou. */
    function gravarColunas(v, silencioso) {
        var t = tabelaDaLista();
        var mapa = v ? mapaCompleto(v) : null;
        var pedido = { mapa: mapa };
        colunasEmVoo = pedido;
        if (t) { t.dataset.colunas = JSON.stringify(mapa); }
        aplicarColunas();
        if (!t || !t.getAttribute('data-url-colunas')) { colunasEmVoo = null; return Promise.resolve(false); }

        filaColunas = filaColunas.then(function () {
            return fetch(t.getAttribute('data-url-colunas'), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json', 'X-CSRF-Token': t.getAttribute('data-csrf-lista') || '' },
                body: JSON.stringify({ colunas: mapa })
            }).then(function (r) { return r.ok; }, function () { return false; });
        });

        return filaColunas.then(function (ok) {
            if (colunasEmVoo === pedido) { colunasEmVoo = null; }
            if (!ok && !silencioso) { aviso(BJ_T.t('expediente.js.lista.colunas.falha_gravar')); }
            return ok;
        });
    }
    function esquecerAjusteLocal(chave) {
        try { localStorage.removeItem(chave); } catch (x) { /* nada a apagar */ }
    }
    /* Migração única: servidor sem ajuste e este navegador com o ajuste da PRÓPRIA pessoa → grava no servidor e
       apaga a chave local. A chave antiga sem usuário não é lida (era de quem usou o navegador antes). */
    function trazerAjusteLocal() {
        var chave = chaveColunasLocal();
        if (trazendoAjusteLocal || !chave) { return; }
        if (colunasSalvas() !== null) { esquecerAjusteLocal(chave); return; } // o servidor já manda: a cópia local sobra
        var local = null;
        try { local = JSON.parse(localStorage.getItem(chave) || 'null'); } catch (x) { local = null; }
        if (!local || typeof local !== 'object' || Array.isArray(local)) { esquecerAjusteLocal(chave); return; }
        trazendoAjusteLocal = true;
        gravarColunas(local, true).then(function (ok) {
            trazendoAjusteLocal = false;
            if (ok) { esquecerAjusteLocal(chave); }
        });
    }
    function aplicarColunas() {
        var v = colunasVisiveis();
        var estilo = document.getElementById('expColunasEstilo');
        if (!estilo) { estilo = el('style'); estilo.id = 'expColunasEstilo'; document.head.appendChild(estilo); }
        estilo.textContent = COLUNAS.filter(function (c) { return !v[c[0]]; })
            .map(function (c) { return '.exp-body #tabelaPastas [data-col="' + c[0] + '"] { display: none !important; }'; }).join('\n');
    }
    function urlCsv(botao) {
        var f = form();
        var params = new URLSearchParams(f ? new FormData(f) : undefined);
        params.delete('page');
        var v = colunasVisiveis();
        params.set('colunas', COLUNAS.filter(function (c) { return v[c[0]] && c[0] !== 'metas'; }).map(function (c) { return c[0]; }).join(','));
        var painel = document.getElementById('painel-marcador-wrapper');
        if (painel) { params.set('marcador', painel.getAttribute('data-marcador-id')); }

        return botao.getAttribute('data-url-csv') + '?' + params.toString();
    }
    function menuColunas(botao, x, y) {
        var v = colunasVisiveis();
        var n = COLUNAS.filter(function (c) { return v[c[0]]; }).length;
        var lista = COLUNAS.map(function (c) {
            var fixo = c[0] === 'nup';
            return { rotulo: c[1], icone: v[c[0]] ? 'bi-check-square-fill' : 'bi-square', corIcone: fixo ? '#9aabb7' : (v[c[0]] ? '#0a6fa0' : '#8aa0ae'),
                dica: fixo ? BJ_T.t('expediente.js.lista.colunas.nup_fixo') : '', marcado: v[c[0]], manter: true, acao: function () {
                    if (fixo) { return; }
                    var atual = colunasVisiveis();
                    atual[c[0]] = !atual[c[0]];
                    if (!COLUNAS.some(function (k) { return k[0] !== 'nup' && atual[k[0]]; })) { return; }
                    gravarColunas(atual);
                    menuColunas(botao, x, y);
                } };
        });
        lista.push('-');
        lista.push({ rotulo: BJ_T.t('expediente.js.lista.colunas.mostrar_todas'), icone: 'bi-eye', manter: true, acao: function () { gravarColunas({}); menuColunas(botao, x, y); } });
        lista.push({ rotulo: BJ_T.t('expediente.js.lista.colunas.essenciais'), icone: 'bi-funnel', manter: true, acao: function () { gravarColunas(ESSENCIAIS); menuColunas(botao, x, y); } });
        lista.push({ rotulo: BJ_T.t('expediente.js.lista.colunas.restaurar'), icone: 'bi-arrow-counterclockwise', acao: function () { esquecerAjusteLocal(chaveColunasLocal()); gravarColunas(null); } });
        lista.push({ rotulo: BJ_T.t('expediente.js.lista.colunas.exportar_csv'), icone: 'bi-filetype-csv', acao: function () { window.location.href = urlCsv(botao); } });
        // Subfrase do dc (L.288): o ajuste é por usuário.
        window.ExpMenu.abrir(BJ_T.t('expediente.js.lista.colunas.titulo', {n: n}), lista, x, y, botao, 'colunas',
            { icone: 'bi-person-lock', texto: BJ_T.t('expediente.js.lista.colunas.ajuste_seu') });
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.js-colunas');
        if (!b) { return; }
        e.preventDefault();
        var r = b.getBoundingClientRect();
        menuColunas(b, Math.max(8, Math.min(r.right - 250, window.innerWidth - 258)), Math.min(r.bottom + 4, window.innerHeight - 420));   // dc `colAbrir`
    });
    document.addEventListener('contextmenu', function (e) {
        var b = e.target.closest('.js-colunas');
        if (!b) { return; }
        e.preventDefault();
        var r = b.getBoundingClientRect();
        menuColunas(b, Math.max(8, Math.min(r.right - 250, window.innerWidth - 258)), Math.min(r.bottom + 4, window.innerHeight - 420));   // dc `colAbrir`
    });

    /* ── MARCADOR da linha (dc L.216-227): lista dos marcadores do trilho com a marca nos da pasta. Como a pasta
       pode ter vários, escolher liga ou desliga aquele marcador; "Remover marcador" tira todos. ── */
    function popMarcador(botao) {
        var cel = botao.closest('.pasta-marcadores-col');
        var dados = cel ? cel.querySelector('[data-csrf-token]') : null;
        if (!dados) { return; }
        var ativos = (dados.getAttribute('data-marcadores-ativos') || '').split(',').filter(Boolean);
        var gravarMarc = function (ids) {
            var corpo = new URLSearchParams({ _token: dados.getAttribute('data-csrf-token') });
            ids.forEach(function (id) { corpo.append('marcadores[]', id); });
            fetch(dados.getAttribute('data-url'), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: corpo.toString()
            })
                .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (res) {
                    if (!res.ok) { aviso(res.j.erro || BJ_T.t('expediente.js.lista.marcador.erro')); return; }
                    if (window.ExpPainel) { window.ExpPainel.recarregarResultados({ page: window.ExpPainel.paginaAtual ? window.ExpPainel.paginaAtual() : 1 }); }
                    if (window.ExpTrilho) { window.ExpTrilho.recarregar(); }
                })
                .catch(function () { aviso(BJ_T.t('expediente.js.lista.marcador.falha_conexao')); });
        };
        var itens = Array.prototype.map.call(document.querySelectorAll('#expTrilho .exp-tri-marcador'), function (m) {
            var id = m.getAttribute('data-marcador-id');
            var tem = ativos.indexOf(id) !== -1;
            return { rotulo: m.getAttribute('data-nome'), pasta: m.getAttribute('data-cor-tela'), nivel: m.getAttribute('data-pai-id') ? 1 : 0, marcado: tem,
                acao: function () { gravarMarc(tem ? ativos.filter(function (x) { return x !== id; }) : ativos.concat([id])); } };
        });
        abrirPop(botao, { titulo: BJ_T.t('expediente.js.lista.marcador.titulo'), icone: 'bi-tags', largura: 220, alinhar: 'centro', itens: itens, vazio: BJ_T.t('expediente.js.lista.marcador.nenhum'),
            rodape: ativos.length ? { rotulo: BJ_T.t('expediente.js.lista.marcador.remover'), icone: 'bi-x-circle', acao: function () { gravarMarc([]); } } : null });
        if (pop) { pop.classList.add('exp-pop--marcador'); }
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.js-marc-pop');
        if (!b) { return; }
        e.preventDefault();
        e.stopPropagation();
        if (pop && popAncora === b) { fecharPop(true); return; }
        popMarcador(b);
    });

    /* ── BlueJus I.A. (dc `aiFx`): quem chama dispara `bj-ai-fx` com { nome, manter }; `bj-ai-fx-fim` encerra. ── */
    var fx = null;
    var fxTimer = 0;
    function fimFx() { clearTimeout(fxTimer); if (fx) { fx.remove(); fx = null; } }
    window.addEventListener('bj-ai-fx', function (ev) {
        var d = ev.detail || {};
        fimFx();
        fx = el('div', 'exp-ai-fx' + (d.manter ? ' is-manter' : ''));
        fx.setAttribute('aria-hidden', 'true');
        fx.appendChild(el('div', 'exp-ai-fx-luz'));
        var orbe = el('div', 'exp-ai-fx-orbe');
        [0, 1, 2, 3].forEach(function () { orbe.appendChild(el('i')); });
        var nucleo = el('div', 'exp-ai-fx-nucleo');
        nucleo.appendChild(icone('bi-stars'));
        orbe.appendChild(nucleo);
        fx.appendChild(orbe);
        var rot = el('div', 'exp-ai-fx-rotulo');
        rot.appendChild(el('span', null, BJ_T.t('expediente.js.lista.ia.rotulo', {nome: d.nome || BJ_T.t('expediente.js.lista.ia.assistente')})));
        var barra = el('span', 'exp-ai-barra');
        barra.appendChild(el('span'));
        rot.appendChild(barra);
        fx.appendChild(rot);
        document.body.appendChild(fx);
        if (!d.manter) { fxTimer = setTimeout(fimFx, 1500); }
    });
    window.addEventListener('bj-ai-fx-fim', fimFx);

    /* Reaplica depois de cada desenho do painel (servidor, filtro, página, marcador). */
    var agendado = 0;
    function aplicar() { agendado = 0; pintarCampos(); trazerAjusteLocal(); aplicarColunas(); }
    function agendar() { if (!agendado) { agendado = window.requestAnimationFrame(aplicar); } }
    new MutationObserver(agendar).observe(document.body, { childList: true, subtree: true });
    document.addEventListener('change', agendar, true);
    document.addEventListener('input', agendar, true);
    agendar();
}());

/* =============================================================================
   E12. Nova Pasta do desenho 1.1 (dc L.265-279, `abrirNovaFn`/`salvarNovaFn`): o próximo número (só
   informativo), sugestões de cliente com "já cadastrado em N pasta(s)", a ação liberada depois do cliente, o
   Salvar só com cliente e o arrastar pelo cabeçalho. O envio é o do modal de sempre (modal_nova_pasta).
   ============================================================================= */
(function () {
    'use strict';

    var modal = document.querySelector('#modalNovaPasta.exp-nova');
    if (!modal) { return; }
    var cliente = modal.querySelector('#modal_nome_cliente');
    var acao = modal.querySelector('#modal_nome_acao');
    var salvar = modal.querySelector('.js-nova-salvar');
    var prox = modal.querySelector('.js-nova-prox');
    var existe = modal.querySelector('.js-nova-existe');
    var lista = modal.querySelector('#expNovaClientes');
    var timer = 0;
    var ultimos = {};

    function buscar(q) {
        return fetch(modal.getAttribute('data-url-sugestoes') + '?q=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; });
    }

    function pintar() {
        var v = cliente.value.trim();
        var tem = v !== '';
        acao.disabled = !tem;
        acao.placeholder = tem ? BJ_T.t('expediente.js.lista.nova.acao_exemplo') : BJ_T.t('expediente.js.lista.nova.acao_sem_cliente');
        acao.closest('.exp-nova-campo').classList.toggle('is-desligado', !tem);
        salvar.disabled = !tem;
        cliente.classList.toggle('is-preenchido', tem);
        var n = ultimos[v.toUpperCase()];
        existe.hidden = !n;
        /* A segunda frase só quando o servidor vai mesmo ligar a pasta ao cadastro (um cadastro só por trás do nome). */
        if (n) { existe.querySelector('span').textContent = BJ_T.t('expediente.js.lista.nova.cliente_existe', {total: n.total}) + (n.cadastro ? ' ' + BJ_T.t('expediente.js.lista.nova.cliente_vinculo') : ''); }
    }

    modal.addEventListener('show.bs.modal', function () {
        cliente.value = '';
        acao.value = '';
        ultimos = {};
        while (lista.firstChild) { lista.removeChild(lista.firstChild); }
        var dlg = modal.querySelector('.modal-dialog');
        dlg.style.transform = '';
        prox.textContent = '';
        pintar();
        buscar('').then(function (d) { if (d && d.proximoNumero) { prox.textContent = BJ_T.t('expediente.js.lista.nova.proximo_numero', {numero: d.proximoNumero}); } });
    });
    modal.addEventListener('shown.bs.modal', function () { cliente.focus(); });

    cliente.addEventListener('input', function () {
        pintar();
        clearTimeout(timer);
        var q = cliente.value.trim();
        if (q.length < 2) { return; }
        timer = setTimeout(function () {
            buscar(q).then(function (d) {
                if (!d) { return; }
                while (lista.firstChild) { lista.removeChild(lista.firstChild); }
                d.clientes.forEach(function (c) {
                    ultimos[c.nome.toUpperCase()] = { total: c.total, cadastro: !!c.cadastro };
                    var op = document.createElement('option');
                    op.value = c.nome;
                    lista.appendChild(op);
                });
                pintar();
            });
        }, 250);
    });

    /* Arrastar pelo cabeçalho (dc `arrastarNova`), sem sair da tela. */
    var cab = modal.querySelector('.js-nova-arrastar');
    cab.addEventListener('mousedown', function (e) {
        if (e.button !== 0 || e.target.closest('button')) { return; }
        var dlg = modal.querySelector('.modal-dialog');
        var m = /translate\((-?\d+)px, (-?\d+)px\)/.exec(dlg.style.transform || '') || [0, 0, 0];
        var x0 = e.clientX - (+m[1]);
        var y0 = e.clientY - (+m[2]);
        e.preventDefault();
        var mover = function (ev) { dlg.style.transform = 'translate(' + (ev.clientX - x0) + 'px, ' + (ev.clientY - y0) + 'px)'; };
        var soltar = function () { document.removeEventListener('mousemove', mover); document.removeEventListener('mouseup', soltar); };
        document.addEventListener('mousemove', mover);
        document.addEventListener('mouseup', soltar);
    });
}());
