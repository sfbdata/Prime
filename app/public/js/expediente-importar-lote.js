/* "Importar pastas em lote" do Expediente (desenho 03 de 30/10, `montar`/`importar` do dc; spec
   docs/specs/importar-pastas-em-lote.md). Abre pelo evento `bj:importar-lote`, que o botão do modal
   Nova Pasta dispara. Cada subpasta escolhida vira uma pasta (rota expediente_importar_lote_pasta) e
   os arquivos sobem um a um pela rota de upload da aba Documentos, na categoria Demais documentos. */
(function () {
    'use strict';

    var raiz = document.getElementById('il-raiz');
    if (!raiz) { return; }

    var IGNORAR = /^(\.|thumbs\.db$|desktop\.ini$)/i;
    // Só estes disparam ação; o rótulo dentro do botão também tem data-il e não pode interceptar o clique.
    var ACOES = '[data-il="fechar"], [data-il="trocar"], [data-il="importar"]';
    var el = function (nome) { return raiz.querySelector('[data-il="' + nome + '"]'); };
    var s = { lote: null, base: null, rodando: false, criou: false, fim: '', erro: '' };

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
        s = { lote: null, base: null, rodando: false, criou: false, fim: '', erro: '' };
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
    }

    function montar(files, solta) {
        var grupos = {};
        Array.prototype.forEach.call(files, function (fl) {
            var partes = String(fl.webkitRelativePath || fl.name).split('/');
            if (IGNORAR.test(partes[partes.length - 1])) { return; }
            var k = solta ? 'NOVA PASTA' : (partes.length > 2 ? partes[1] : partes.length === 2 ? partes[0] : 'NOVA PASTA');
            (grupos[k] = grupos[k] || []).push(fl);
        });
        var lote = Object.keys(grupos).sort(function (a, b) { return a.localeCompare(b, 'pt-BR'); }).map(function (k) {
            return { cli: k.replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim().toUpperCase(), files: grupos[k], on: true, feita: false, st: '', nup: '' };
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
            cli.addEventListener('input', function () { p.cli = cli.value.toUpperCase(); });

            var qtd = document.createElement('span');
            qtd.className = 'il-dir il-qtd';
            qtd.textContent = p.files.length + ' · ' + tamanho(p.files.reduce(function (a, f) { return a + f.size; }, 0));

            var st = document.createElement('span');

            [marca, nup, cli, qtd, st].forEach(function (n) { linha.appendChild(n); });
            caixa.appendChild(linha);
            p.ref = { linha: linha, nup: nup, cli: cli, st: st };
            atualizarLinha(p);
        });
    }

    function render() {
        var sel = pendentes();
        el('vazio').hidden = !!s.lote;
        el('tem').hidden = !s.lote;
        if (s.lote) {
            el('resumo').textContent = resumo();
            renderLinhas();
        }
        el('progresso').hidden = !s.rodando;
        el('fim').hidden = !s.fim;
        el('fim-txt').textContent = s.fim;
        el('erro').hidden = !s.erro;
        el('erro').textContent = s.erro;
        el('importar').hidden = !(s.lote && !s.fim && sel.length > 0);
        el('btn-txt').textContent = s.rodando ? 'Importando…' : 'Importar ' + sel.length + ' pasta(s)';
        raiz.querySelector('.il-cancelar').textContent = s.fim ? 'Concluir' : 'Cancelar';
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
                    recusados.push(p.files[j].name + ': ' + (r.error || r.mensagem || 'não foi possível enviar'));
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
                + (recusados.length ? '. ' + recusados.length + ' arquivo(s) não foram anexados (' + recusados[0] + (recusados.length > 1 ? ' e outros' : '') + ')' : '')
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
        if (acao === 'trocar' && !s.rodando) { s.lote = null; s.fim = ''; s.erro = ''; render(); }
        if (acao === 'importar') { importar(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !raiz.hidden) { fechar(); }
    });
    el('ler-pasta').addEventListener('change', function (e) { montar(e.target.files, false); e.target.value = ''; });
    el('ler-arquivos').addEventListener('change', function (e) { montar(e.target.files, true); e.target.value = ''; });

    document.addEventListener('bj:importar-lote', abrir);
})();
