/* "Importar pastas em lote" do Expediente (desenho 03 de 30/10, `montar`/`importar` do dc; spec
   docs/specs/importar-pastas-em-lote.md). Abre pelo evento `bj:importar-lote`, que o botão do modal
   Nova Pasta dispara. Cada subpasta escolhida vira uma pasta (rota expediente_importar_lote_pasta) e
   os arquivos sobem um a um pela rota de upload da aba Documentos, na categoria Demais documentos. */
(function () {
    'use strict';

    var raiz = document.getElementById('il-raiz');
    if (!raiz) { return; }

    var IGNORAR = /^(\.|thumbs\.db$|desktop\.ini$)/i;
    /* Arquivo de lixo ou oculto, ou arquivo dentro de PASTA oculta em qualquer nível abaixo da escolhida
       (".ocultos/rascunho.pdf", "ANA/.git/config"). A própria pasta escolhida (partes[0]) não conta. */
    function ignorado(partes) {
        if (IGNORAR.test(partes[partes.length - 1])) { return true; }

        return partes.slice(1, -1).some(function (pasta) { return pasta.charAt(0) === '.'; });
    }
    // Só estes disparam ação; o rótulo dentro do botão também tem data-il e não pode interceptar o clique.
    var ACOES = '[data-il="fechar"], [data-il="trocar"], [data-il="importar"], [data-il="sim"], [data-il="nao"], [data-il="sugerir"], [data-il="voltar-regra"]';
    var FALHA_IA = 'A IA não conseguiu sugerir agora. O agrupamento por pasta continua valendo.';
    var el = function (nome) { return raiz.querySelector('[data-il="' + nome + '"]'); };
    /* Etapa 2 (IA): `origem` guarda os arquivos e a regra do último `montar`, para a IA e o "Voltar"; `modo` é
       'regra' ou 'ia'; `iniciou` some com os botões da IA assim que o Importar começa. */
    function estadoInicial() {
        return { lote: null, base: null, rodando: false, criou: false, fim: '', erro: '', pergunta: null,
            origem: null, modo: 'regra', aviso: '', analisando: false, iniciou: false, vezIa: 0 };
    }
    var s = estadoInicial();

    function tamanho(b) {
        return b > 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
    }

    /* Marcadas e ainda não criadas: depois de uma interrupção, o botão retoma só estas (D6). */
    function pendentes() {
        return s.lote ? s.lote.filter(function (p) { return p.on && !p.feita; }) : [];
    }

    function totalDeArquivos(lista) {
        return lista.reduce(function (a, p) { return a + p.files.length; }, 0);
    }

    function abrir() {
        s = estadoInicial();
        raiz.hidden = false;
        render();
        raiz.querySelector('.il-x').focus();
        // A coluna Nº PASTA é previsão; o número gravado é sempre o do servidor (D5).
        fetch(raiz.dataset.urlProximo + '?q=', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                var n = d ? parseInt(d.proximoNumero, 10) : NaN;
                if (!isNaN(n)) { s.base = n; render(); }
            })
            .catch(function () {});
    }

    function fechar() {
        if (s.rodando) { return; }
        // A lista é do servidor: se alguma pasta foi criada, recarrega para ela aparecer (D8).
        if (s.criou) { window.location.reload(); return; }
        raiz.hidden = true;
        s.lote = null;
        s.pergunta = null;
    }

    function partesDe(fl) {
        return String(fl.webkitRelativePath || fl.name).split('/');
    }

    /* Caminho do arquivo dentro da pasta escolhida, só quando está numa subpasta ("Processo/Inicial.pdf"):
       vai na descrição do documento quando a pasta é de um cliente só (D11). */
    function caminhoNaSubpasta(fl) {
        var partes = partesDe(fl);

        return partes.length > 2 ? partes.slice(1).join('/') : '';
    }

    /* Descrição do documento: na linha sugerida pela IA, o caminho abaixo da pasta do caso quando o arquivo está numa
       subpasta dela; na regra, o caminho da subpasta quando a pasta é de um cliente só (D11). */
    function descricaoDo(p, fl) {
        if (p.pastaIa) {
            var resto = partesDe(fl).join('/').slice(p.pastaIa.length + 1);

            return resto.indexOf('/') >= 0 ? resto : '';
        }

        return p.comCaminho ? caminhoNaSubpasta(fl) : '';
    }

    /* D11 (04/11/2026, pedido do Farlei): a pasta escolhida tem subpasta com arquivo. Aí não dá para saber se é a
       pasta de um cliente (com subpastas de organização, como Documentos e Processo) ou a pasta de vários clientes:
       pergunta sempre. Devolve as subpastas (em ordem) e quantos arquivos estão soltos na raiz. */
    function estruturaDa(files) {
        var subpastas = {}, soltos = 0;
        Array.prototype.forEach.call(files, function (fl) {
            var partes = partesDe(fl);
            if (ignorado(partes)) { return; }
            if (partes.length === 2) { soltos++; }
            if (partes.length > 2) { subpastas[partes[1]] = true; }
        });

        return { subpastas: Object.keys(subpastas).sort(function (a, b) { return a.localeCompare(b, 'pt-BR'); }), soltos: soltos };
    }

    function plural(n, um, varios) {
        return n + ' ' + (n === 1 ? um : varios);
    }

    /* "A pasta "X" tem 2 subpastas (Documentos, Processo). Criar 2 pastas, uma por subpasta, ou 1 pasta só, "X", com
       tudo?" Com arquivo solto na raiz, a regra cria mais uma pasta com o nome da escolhida para eles: o texto conta. */
    function textoDaPergunta(nome, e) {
        var n = e.subpastas.length, MAX = 5;
        var nomes = e.subpastas.slice(0, MAX).join(', ') + (n > MAX ? ', ...' : '');
        var pastas = n + (e.soltos > 0 ? 1 : 0);
        var comSoltos = e.soltos > 0 ? ' e ' + plural(e.soltos, 'arquivo solto', 'arquivos soltos') : '';
        var criar = 'Criar ' + plural(pastas, 'pasta', 'pastas') + (n === 1 ? ', uma para a subpasta' : ', uma por subpasta')
            + (e.soltos > 0 ? ' e uma "' + nome + '" com ' + (e.soltos === 1 ? 'o arquivo solto' : 'os arquivos soltos') : '');

        return {
            texto: 'A pasta "' + nome + '" tem ' + plural(n, 'subpasta', 'subpastas') + ' (' + nomes + ')' + comSoltos + '. '
                + criar + ', ou 1 pasta só, "' + nome + '", com tudo?',
            nao: 'Criar ' + plural(pastas, 'pasta', 'pastas'),
            sim: '1 pasta só, "' + nome + '"'
        };
    }

    function lerPasta(files) {
        var e = estruturaDa(files);
        if (e.subpastas.length === 0) { montar(files, false, false); return; }
        var lista = Array.prototype.slice.call(files);
        var nome = partesDe(lista[0])[0];
        var t = textoDaPergunta(nome, e);
        s.pergunta = { files: lista };
        s.erro = '';
        // Pasta = caso: o mesmo cliente pode ter várias pastas. "1 pasta só" leva o caminho da subpasta na descrição.
        el('pergunta-txt').textContent = t.texto;
        el('nao-txt').textContent = t.nao;
        el('sim-txt').textContent = t.sim;
        render();
    }

    /* O botão da IA na pergunta: monta pela regra (uma pasta por subpasta) e já pede a sugestão. */
    function sugerirDaPergunta() {
        if (!s.pergunta || s.analisando) { return; }
        var files = s.pergunta.files;
        s.pergunta = null;
        montar(files, false, false);
        sugerir();
    }

    function responder(umCliente) {
        var files = s.pergunta.files;
        s.pergunta = null;
        montar(files, false, umCliente);
    }

    /* "001 - Fulano", "01_Fulano", "1. Fulano" viram "Fulano": só o número da frente seguido de separador
       (espaço, -, _, ., ) ou º) e com nome depois. Nome que é só número ("2024") fica como está. */
    function semNumeroNaFrente(nome) {
        return nome.replace(/^\s*\d+(?:\s*[-_.)ºª°]+\s*|\s+)(?=[^\s\-_.)ºª°])/, '');
    }

    function montar(files, solta, umCliente) {
        s.origem = { files: Array.prototype.slice.call(files), solta: !!solta, umCliente: !!umCliente };
        s.modo = 'regra';
        s.aviso = '';
        var grupos = {};
        Array.prototype.forEach.call(files, function (fl) {
            var partes = partesDe(fl);
            if (ignorado(partes)) { return; }
            var k = solta ? 'NOVA PASTA' : umCliente ? partes[0] : (partes.length > 2 ? partes[1] : partes.length === 2 ? partes[0] : 'NOVA PASTA');
            (grupos[k] = grupos[k] || []).push(fl);
        });
        var lote = Object.keys(grupos).sort(function (a, b) { return a.localeCompare(b, 'pt-BR'); }).map(function (k) {
            return { cli: semNumeroNaFrente(k).replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim().toUpperCase(), files: grupos[k], on: true, feita: false, st: '', nup: '', comCaminho: !!umCliente };
        });
        if (!lote.length) {
            s.erro = 'Nenhum arquivo encontrado na pasta escolhida.';
            render();

            return;
        }
        s.lote = lote;
        s.erro = '';
        s.fim = '';
        render();
    }

    function resumo() {
        var sel = s.lote.filter(function (p) { return p.on; });

        return sel.length + ' pasta(s) · ' + totalDeArquivos(sel) + ' arquivo(s)'
            + (s.base !== null ? ' · numeração a partir de ' + s.base : '');
    }

    function situacao(p) {
        if (p.st === 'Importada') { return ['Importada', 'importada']; }
        if (p.st) { return [p.st, 'andando']; }
        if (p.ia && p.on) { return ['Sugerida pela IA', '']; }

        return p.on ? ['Pronta', ''] : ['Ignorada', 'ignorada'];
    }

    /* Atualiza só a linha da pasta, sem recriar os campos: quem digita o nome de outra linha não perde o foco. */
    function atualizarLinha(p) {
        var sit = situacao(p);
        p.ref.linha.className = 'il-linha' + (p.st === 'Importada' ? ' importada' : '');
        p.ref.nup.textContent = p.nup || p.previsto;
        p.ref.st.className = 'il-dir il-sit ' + sit[1];
        p.ref.st.textContent = sit[0];
        p.ref.cli.disabled = p.feita;
    }

    function renderLinhas() {
        var caixa = el('linhas');
        caixa.textContent = '';
        var k = 0;
        s.lote.forEach(function (p) {
            p.previsto = p.on && !p.feita && s.base !== null ? s.base + (k++) : '';
            var linha = document.createElement('div');

            var marca = document.createElement('input');
            marca.type = 'checkbox';
            marca.checked = p.on;
            marca.addEventListener('change', function () {
                if (s.rodando || p.feita) { marca.checked = p.on; return; }
                p.on = marca.checked;
                render();
            });

            var nup = document.createElement('b');
            nup.className = 'il-nup';

            var cli = document.createElement('input');
            cli.className = 'il-cli';
            cli.value = p.cli;
            cli.maxLength = 255;
            if (!p.cli) { cli.placeholder = 'Nome do cliente'; }
            cli.addEventListener('input', function () { p.cli = cli.value.toUpperCase(); });

            /* Linha da IA: "mesmo cliente que ..." ou "Arquivos sem caso" em texto pequeno sob o nome. */
            var nome = cli;
            if (p.sub) {
                nome = document.createElement('div');
                nome.className = 'il-nome';
                var sub = document.createElement('small');
                sub.className = 'il-mesmo';
                sub.textContent = p.sub;
                nome.appendChild(cli);
                nome.appendChild(sub);
            }

            var qtd = document.createElement('span');
            qtd.className = 'il-dir il-qtd';
            qtd.textContent = p.files.length + ' · ' + tamanho(p.files.reduce(function (a, f) { return a + f.size; }, 0));

            var st = document.createElement('span');

            [marca, nup, nome, qtd, st].forEach(function (n) { linha.appendChild(n); });
            caixa.appendChild(linha);
            p.ref = { linha: linha, nup: nup, cli: cli, st: st };
            atualizarLinha(p);
        });
    }

    function render() {
        var sel = pendentes();
        el('vazio').hidden = !!s.lote || !!s.pergunta;
        el('pergunta').hidden = !s.pergunta;
        el('tem').hidden = !s.lote;
        if (s.lote) {
            el('resumo').textContent = resumo();
            renderLinhas();
        }
        el('progresso').hidden = !s.rodando;
        el('fim').hidden = !s.fim;
        el('fim-txt').textContent = s.fim;
        renderIa();
        el('erro').hidden = !s.erro;
        el('erro').textContent = s.erro;
        el('importar').hidden = !(s.lote && !s.fim && sel.length > 0);
        el('btn-txt').textContent = s.rodando ? 'Importando…' : 'Importar ' + sel.length + ' pasta(s)';
        raiz.querySelector('.il-cancelar').textContent = s.fim ? 'Concluir' : 'Cancelar';
    }

    /* Os botões da IA só existem quando a BlueJus IA está disponível (o Twig não os renderiza fora disso). Só para
       lote de pasta, nunca para arquivos soltos, e somem quando o Importar começa. */
    function renderIa() {
        var sugerir = el('sugerir'), voltar = el('voltar-regra');
        var pode = !!(s.lote && s.origem && !s.origem.solta && !s.iniciou && !s.fim);
        if (sugerir) {
            sugerir.hidden = !(pode && s.modo === 'regra');
            sugerir.disabled = s.analisando;
            el('sugerir-txt').textContent = s.analisando ? 'Analisando...' : 'Sugerir agrupamento com IA';
        }
        var daPergunta = el('sugerir-pergunta');
        if (daPergunta) { daPergunta.hidden = !s.pergunta; }
        if (voltar) { voltar.hidden = !(pode && s.modo === 'ia') || s.analisando; }
        el('aviso-ia').hidden = !(s.lote && s.modo === 'ia' && s.aviso);
        el('aviso-ia').textContent = s.aviso;
    }

    function caminhoDe(fl) {
        return partesDe(fl).join('/');
    }

    /* Só os arquivos que a regra aceitou (sem lixo nem oculto), os mesmos do "Voltar". */
    function arquivosDaOrigem() {
        return s.origem.files.filter(function (fl) { return !ignorado(partesDe(fl)); });
    }

    function nomeDaPasta(caminho) {
        var partes = caminho.split('/');

        return partes[partes.length - 1];
    }

    /* A sugestão só troca as linhas: um caso por pasta do computador, com os arquivos de caminho que começa com
       `pasta + '/'`. O que não caiu em caso vai para "Arquivos sem caso", desmarcada. Nada some. */
    function aplicarSugestao(d) {
        var arquivos = arquivosDaOrigem(), usados = [], lote = [];
        (d.casos || []).forEach(function (c) {
            var prefixo = c.pasta + '/';
            var files = arquivos.filter(function (fl, i) {
                if (usados[i] || caminhoDe(fl).indexOf(prefixo) !== 0) { return false; }
                usados[i] = true;

                return true;
            });
            if (!files.length) { return; }
            lote.push({ cli: String(c.cliente || '').toUpperCase(), files: files, on: true, feita: false, st: '', nup: '',
                comCaminho: false, ia: true, pastaIa: c.pasta, sub: c.mesmoClienteQue ? 'mesmo cliente que a pasta ' + nomeDaPasta(c.mesmoClienteQue) : '' });
        });
        var resto = arquivos.filter(function (fl, i) { return !usados[i]; });
        if (resto.length) {
            lote.push({ cli: '', files: resto, on: false, feita: false, st: '', nup: '', comCaminho: true, ia: true, pastaIa: '',
                sub: 'Arquivos sem caso' });
        }
        s.lote = lote;
        s.modo = 'ia';
        s.aviso = 'Sugestão da IA: ela pode errar, confira antes de importar.'
            + (d.truncado ? ' A IA olhou só os primeiros 2.000 itens (pastas e arquivos). Confira os arquivos de cada caso e a linha Arquivos sem caso.' : '');
        s.erro = '';
    }

    function sugerir() {
        if (!s.origem || s.origem.solta || s.analisando || s.rodando || s.iniciou) { return; }
        var origem = s.origem, vez = ++s.vezIa;
        s.analisando = true;
        s.erro = '';
        render();
        fetch(raiz.dataset.urlSugerir, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ _token: raiz.dataset.token, caminhos: arquivosDaOrigem().map(caminhoDe) })
        })
            .then(function (r) { return r.json().catch(function () { return { status: 'erro', mensagem: FALHA_IA }; }); })
            .catch(function () { return { status: 'erro', mensagem: FALHA_IA }; })
            .then(function (d) {
                /* Trocou de pasta, voltou ou começou a importar enquanto a IA pensava: a resposta não vale mais. */
                if (vez !== s.vezIa || s.origem !== origem) { return; }
                s.analisando = false;
                if (s.iniciou || s.rodando) { render(); return; }
                if (d && d.status === 'ok') {
                    aplicarSugestao(d);
                } else {
                    s.erro = (d && d.mensagem) || FALHA_IA;
                }
                render();
            });
    }

    function voltarARegra() {
        if (!s.origem || s.rodando || s.iniciou) { return; }
        s.vezIa++;
        s.analisando = false;
        montar(s.origem.files, s.origem.solta, s.origem.umCliente);
    }

    function progresso(texto, fracao) {
        if (texto !== null) { el('prog-txt').textContent = texto; }
        var pct = Math.round(fracao * 100) + '%';
        el('pct').textContent = pct;
        el('barra').style.width = pct;
    }

    function postar(url, dados) {
        return fetch(url, { method: 'POST', body: dados, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                return r.json().catch(function () { return { status: 'erro', error: 'não foi possível enviar' }; });
            });
    }

    /* Motivo curto da recusa do upload, sem repetir o nome do arquivo (o servidor manda '"nome": excede ...'). */
    function motivoCurto(erro) {
        var limite = /excede o limite de (\d+) MB/.exec(erro);
        if (limite) { return 'passa de ' + limite[1] + ' MB'; }
        if (/Tipo de arquivo não permitido/i.test(erro)) { return 'tipo não aceito'; }
        var pacote = /não é um arquivo (\.\w+) válido/.exec(erro);
        if (pacote) { return 'arquivo ' + pacote[1] + ' inválido'; }
        var resto = erro.replace(/^"[^"]*":\s*/, '').replace(/\.\s*$/, '').trim();

        return resto ? resto.charAt(0).toLowerCase() + resto.slice(1) : 'não foi possível enviar';
    }

    /* "2 arquivos não foram anexados: a.jpg (passa de 3 MB) e b.exe (tipo não aceito)". Até 5 pelo nome;
       passou disso, "e mais N". */
    function fraseDosRecusados(lista) {
        var MAX = 5;
        var itens = lista.slice(0, MAX).map(function (x) { return x.nome + ' (' + x.motivo + ')'; });
        var resto = lista.length - itens.length;
        var juntos = resto > 0
            ? itens.join(', ') + ' e mais ' + resto
            : itens.length > 1 ? itens.slice(0, -1).join(', ') + ' e ' + itens[itens.length - 1] : itens[0];

        return (lista.length === 1 ? '1 arquivo não foi anexado: ' : lista.length + ' arquivos não foram anexados: ') + juntos;
    }

    async function importar() {
        var sel = pendentes();
        if (!sel.length || s.rodando) { return; }
        if (sel.some(function (p) { return !p.cli.trim(); })) {
            s.erro = 'Todas as pastas marcadas precisam de um nome de cliente.';
            render();

            return;
        }

        var tot = totalDeArquivos(sel), feitos = 0, anexados = 0, iguais = 0, recusados = [], novas = [];
        s.rodando = true;
        s.iniciou = true;
        s.analisando = false;
        s.vezIa++;
        s.erro = '';
        render();
        progresso('Preparando…', 0);

        lote: for (var i = 0; i < sel.length; i++) {
            var p = sel[i];
            var dados = new FormData();
            dados.append('_token', raiz.dataset.token);
            dados.append('nome_cliente', p.cli.trim());
            var criada;
            try {
                criada = await postar(raiz.dataset.urlPasta, dados);
            } catch (e) {
                criada = { status: 'erro', mensagem: 'Falha de conexão ao criar a pasta de ' + p.cli + '.' };
            }
            if (criada.status !== 'ok') {
                s.erro = criada.mensagem || 'Não foi possível criar a pasta de ' + p.cli + '.';
                break;
            }
            // Criada é criada: daqui em diante a linha não volta para a fila, nem se o lote parar no meio.
            p.feita = true;
            p.nup = criada.nup;
            novas.push(criada.nup);
            s.criou = true;
            progresso('Pasta ' + criada.nup + ' · ' + p.cli, tot ? feitos / tot : 0);

            if (!criada.uploadUrl) {
                // Pasta criada, mas a pessoa não pode anexar nela (D2).
                p.st = 'Sem permissão para anexar';
                feitos += p.files.length;
                progresso(null, tot ? feitos / tot : 1);
                atualizarLinha(p);
                continue;
            }

            for (var j = 0; j < p.files.length; j++) {
                var arq = new FormData();
                arq.append('_token', criada.uploadToken);
                arq.append('arquivo', p.files[j]);
                arq.append('categoria', 'DEMAIS');
                if (descricaoDo(p, p.files[j])) { arq.append('descricao', descricaoDo(p, p.files[j])); }
                var r;
                try {
                    r = await postar(criada.uploadUrl, arq);
                } catch (e) {
                    r = { success: false, error: 'falha de conexão' };
                }
                if (r.success) {
                    anexados++;
                    if (r.duplicadoDe && r.duplicadoDe.length) { iguais++; }
                } else if (r.codigo === 'espaco_esgotado') {
                    // Cota do plano acabou: os próximos arquivos falhariam todos (D6).
                    s.erro = r.mensagem || r.error;
                    p.st = 'Incompleta';
                    atualizarLinha(p);
                    break lote;
                } else {
                    recusados.push({ nome: p.files[j].name, motivo: motivoCurto(r.error || r.mensagem || '') });
                }
                feitos++;
                progresso(null, feitos / tot);
            }
            p.st = 'Importada';
            atualizarLinha(p);
        }

        s.rodando = false;
        var frase = novas.length
            ? novas.length + ' pasta(s) criada(s) (nº ' + novas[0] + (novas.length > 1 ? ' a ' + novas[novas.length - 1] : '') + ') com ' + anexados + ' documento(s) anexado(s)'
                + (iguais ? '. ' + iguais + ' arquivo(s) iguais a documentos de outras pastas' : '')
                + (recusados.length ? '. ' + fraseDosRecusados(recusados) : '')
                + '.'
            : '';
        if (s.erro) {
            // Interrompido: o que já foi criado fica dito junto do erro, e o botão retoma o resto.
            s.erro += frase ? ' ' + frase : '';
        } else {
            s.fim = frase;
        }
        render();
    }

    raiz.addEventListener('click', function (e) {
        var alvo = e.target.closest(ACOES);
        if (!alvo) { return; }
        var acao = alvo.getAttribute('data-il');
        if (acao === 'fechar') { fechar(); }
        if (acao === 'trocar' && !s.rodando) {
            s.lote = null; s.pergunta = null; s.fim = ''; s.erro = ''; s.origem = null; s.modo = 'regra'; s.aviso = '';
            s.analisando = false; s.iniciou = false; s.vezIa++;
            render();
        }
        if (acao === 'sugerir') { sugerir(); }
        if (acao === 'sugerir-pergunta') { sugerirDaPergunta(); }
        if (acao === 'voltar-regra') { voltarARegra(); }
        if ((acao === 'sim' || acao === 'nao') && s.pergunta) { responder(acao === 'sim'); }
        if (acao === 'importar') { importar(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !raiz.hidden) { fechar(); }
    });
    el('ler-pasta').addEventListener('change', function (e) { lerPasta(e.target.files); e.target.value = ''; });
    el('ler-arquivos').addEventListener('change', function (e) { montar(e.target.files, true, false); e.target.value = ''; });

    document.addEventListener('bj:importar-lote', abrir);
})();
