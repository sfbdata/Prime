/* BlueJus · Lixeira do escritório no Expediente (pedido do Samuel, 03/11/2026; sem desenho).
   O botão "Lixeira" do cabeçalho abre a janela #expLixeira (expediente/_lixeira_modal.html.twig). A lista vem de
   `expediente_lixeira` (JSON, só leitura), uma pasta por grupo, da exclusão mais recente para a mais antiga, só as
   pastas em que quem olha pode restaurar. Cada grupo traz as URLs e o token de lote das rotas que JÁ existem na
   lixeira da pasta; as ações "Restaurar tudo" e "Excluir de vez tudo" leem os itens pela lixeira da própria pasta
   (`urlLixeira`, que já tira o contrato de honorários oculto) e mandam os ids por ela, em lotes de até `tetoPorLote`.
   Nenhuma regra nova aqui: quem decide é o servidor da pasta. Tudo por createElement/textContent: nome de pasta e
   de pessoa é dado do usuário. */
(function () {
    'use strict';

    const janela = document.getElementById('expLixeira');
    const abrirBtn = document.getElementById('expLixeiraAbrir');
    if (!janela || !abrirBtn) return;

    const el = {
        lista: document.getElementById('expLixeiraLista'),
        vazia: document.getElementById('expLixeiraVazia'),
        aviso: document.getElementById('expLixeiraAviso'),
        mais: document.getElementById('expLixeiraMais'),
    };
    const MINIMO_NA_TELA = 20;          // abaixo disso, a próxima página vem sozinha
    const PAGINAS_SOZINHAS = 4;         // ... até este tanto de páginas seguidas por pedido
    let proximo = null;                 // '' = primeira página; "data|id" = continua depois dela; null = acabou
    let geracao = 0;                    // cada abertura é uma geração: resposta de abertura anterior é descartada
    let teto = 2000;
    let lendo = false;
    let ocupado = false;
    let focoDeVolta = null;
    const vistas = new Set();

    // ------------------------------------------------------------ utilidades ---
    function h(tag, attrs, filhos) {
        const n = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            const v = attrs[k];
            if (v === null || v === undefined || v === false) return;
            if (k === 'text') n.textContent = v;
            else if (k === 'class') n.className = v;
            else if (v === true) n.setAttribute(k, '');
            else n.setAttribute(k, v);
        });
        (filhos || []).forEach(function (f) { if (f) n.appendChild(f); });
        return n;
    }
    function icone(classe) { return h('i', { class: 'bi ' + classe, 'aria-hidden': 'true' }); }
    function inteiro(n) { return Number(n || 0).toLocaleString('pt-BR'); }
    function dataHora(iso) {
        const d = iso ? new Date(iso) : null;
        if (!d || isNaN(d.getTime())) return '';
        return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    }
    function lerJson(r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j || {} }; }); }
    function getJson(url) {
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }).then(lerJson);
    }
    function postJson(url, corpo) {
        return fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(corpo),
        }).then(lerJson);
    }
    function avisar(texto, erro) {
        el.aviso.hidden = !texto;
        el.aviso.textContent = texto || '';
        el.aviso.classList.toggle('exp-lix-aviso--erro', !!erro);
    }
    function confirmar(pergunta, perigo) {
        return window.confirmarUIPs ? window.confirmarUIPs(pergunta, perigo) : Promise.resolve(window.confirm(pergunta));
    }

    // --------------------------------------------------------------- abrir ---
    function abrir() {
        focoDeVolta = document.activeElement;
        janela.hidden = false;
        el.lista.textContent = '';
        vistas.clear();
        geracao++;
        lendo = false;
        proximo = '';
        el.vazia.hidden = true;
        el.mais.hidden = true;
        avisar(BJ_T.t('expediente.js.lixeira.carregando'));
        const x = janela.querySelector('.exp-modal-x');
        if (x) x.focus();
        lerPagina(PAGINAS_SOZINHAS);
    }
    function fechar() {
        if (janela.hidden) return;
        janela.hidden = true;
        if (focoDeVolta && focoDeVolta.focus) focoDeVolta.focus();
    }
    function confirmacaoAberta() {
        const cf = document.getElementById('psConfirmar');
        return !!cf && !cf.hidden;
    }
    abrirBtn.addEventListener('click', abrir);
    janela.addEventListener('click', function (e) {
        if (e.target === janela || e.target.closest('.js-lix-fechar')) fechar();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !janela.hidden && !confirmacaoAberta()) { e.preventDefault(); fechar(); }
    });
    el.mais.addEventListener('click', function () { lerPagina(PAGINAS_SOZINHAS); });

    /* Uma página por vez. Pasta sem permissão não vem: com poucas na tela, a próxima página vem sozinha
       (até `sozinhas` páginas), para quem pode restaurar em poucas pastas não ter de clicar à toa. */
    function lerPagina(sozinhas) {
        if (lendo || proximo === null) return;
        lendo = true;
        el.mais.disabled = true;
        const minha = geracao;
        const url = new URL(janela.dataset.url, window.location.href);
        if (proximo) url.searchParams.set('apos', proximo);
        getJson(url.toString()).then(function (res) {
            if (minha !== geracao) return;      // a janela foi reaberta: esta resposta é da lista velha
            if (!res.ok || !res.j.ok) throw new Error(res.j.erro || BJ_T.t('expediente.js.lixeira.erro_carregar'));
            teto = Number(res.j.tetoPorLote) || teto;
            proximo = typeof res.j.proximo === 'string' && res.j.proximo !== '' ? res.j.proximo : null;
            (res.j.grupos || []).forEach(function (g) {
                if (vistas.has(g.pastaId)) return;
                vistas.add(g.pastaId);
                el.lista.appendChild(linhaDoGrupo(g));
            });
            avisar('');
            lendo = false;
            if (proximo !== null && sozinhas > 1 && el.lista.children.length < MINIMO_NA_TELA) { lerPagina(sozinhas - 1); return; }
            atualizarRodape();
        }).catch(function (err) {
            if (minha !== geracao) return;
            lendo = false;
            avisar(err.message || BJ_T.t('expediente.js.lixeira.erro_comunicacao'), true);
            atualizarRodape();
        });
    }
    function atualizarRodape() {
        el.mais.hidden = proximo === null;
        el.mais.disabled = false;
        el.vazia.hidden = el.lista.children.length > 0 || proximo !== null;
    }

    // --------------------------------------------------------------- grupo ---
    function quantidade(docs, subs) {
        const a = docs === 1 ? BJ_T.t('expediente.js.lixeira.documentos_um') : BJ_T.t('expediente.js.lixeira.documentos_n', { n: inteiro(docs) });
        const b = subs === 1 ? BJ_T.t('expediente.js.lixeira.subpastas_um') : BJ_T.t('expediente.js.lixeira.subpastas_n', { n: inteiro(subs) });
        if (docs && subs) return BJ_T.t('expediente.js.lixeira.conjunto', { a: a, b: b });
        return subs ? b : a;
    }
    function quando(g) {
        const antiga = dataHora(g.maisAntiga);
        const recente = dataHora(g.maisRecente);
        return antiga === recente ? BJ_T.t('expediente.js.lixeira.excluido_em', { data: recente }) : BJ_T.t('expediente.js.lixeira.excluidos_entre', { antiga: antiga, recente: recente });
    }
    function quem(g) {
        return g.excluidoPor && g.excluidoPor.length ? BJ_T.t('expediente.js.lixeira.por', { nomes: g.excluidoPor.join(', ') }) : BJ_T.t('expediente.js.lixeira.sem_registro');
    }
    function linhaDoGrupo(g) {
        const li = h('li', { class: 'exp-lix-grupo', 'data-lix-pasta': String(g.pastaId) });
        li._grupo = g;
        montarGrupo(li);
        return li;
    }
    function montarGrupo(li) {
        const g = li._grupo;
        const titulo = BJ_T.t('expediente.js.lixeira.pasta', { nup: g.nup }) + ' · ' + (g.nome || BJ_T.t('expediente.js.lixeira.sem_nome'));
        const escreve = !g.pastaExcluida && g.urlRestaurar && g.csrfLote;
        li.textContent = '';
        li.appendChild(icone('bi-folder2 exp-lix-ico'));
        li.appendChild(h('div', { class: 'exp-lix-txt' }, [
            h('span', { class: 'exp-lix-titulo', text: titulo }),
            h('span', { class: 'exp-lix-sub', text: quantidade(g.documentos, g.subpastas) + ' · ' + quando(g) }),
            h('span', { class: 'exp-lix-sub', text: quem(g) }),
            g.pastaExcluida ? h('span', { class: 'exp-lix-lapide', text: BJ_T.t('expediente.js.lixeira.lapide') }) : null,
            h('a', { class: 'exp-lix-link', href: g.urlPasta, text: BJ_T.t('expediente.js.lixeira.abrir') }),
        ]));
        if (!escreve) return;
        li.appendChild(h('div', { class: 'exp-lix-acoes' }, [
            h('button', { type: 'button', class: 'exp-btn exp-lix-restaurar', 'data-lix-acao': 'restaurar', 'data-tip': BJ_T.t('expediente.js.lixeira.restaurar_tudo_titulo'), disabled: ocupado }, [icone('bi-arrow-counterclockwise'), h('span', { text: BJ_T.t('expediente.js.lixeira.restaurar_tudo') })]),
            g.urlExcluirDeVez ? h('button', { type: 'button', class: 'exp-btn exp-lix-excluir-de-vez', 'data-lix-acao': 'excluir', 'data-tip': BJ_T.t('expediente.js.lixeira.excluir_de_vez_titulo'), disabled: ocupado }, [icone('bi-x-octagon'), h('span', { text: BJ_T.t('expediente.js.lixeira.excluir_de_vez_tudo') })]) : null,
        ]));
    }
    function travar(sim) {
        ocupado = sim;
        el.lista.querySelectorAll('button[data-lix-acao]').forEach(function (b) { b.disabled = sim; });
    }
    el.lista.addEventListener('click', function (e) {
        const b = e.target.closest('button[data-lix-acao]');
        if (!b || ocupado) return;
        const li = b.closest('li[data-lix-pasta]');
        if (!li || !li._grupo) return;
        if (b.dataset.lixAcao === 'restaurar') restaurarTudo(li);
        else excluirDeVezTudo(li);
    });

    // ------------------------------------------------------------- ações ---
    function itensDaPasta(g) {
        return getJson(g.urlLixeira).then(function (res) {
            if (!res.ok || !res.j.ok) throw new Error(res.j.erro || BJ_T.t('expediente.js.lixeira.erro_carregar'));
            return res.j.itens || [];
        });
    }
    /* A raiz de cada bloco, pelo mesmo critério da lixeira da pasta (`arvoreDaLixeira` em pasta-explorador.js):
       o item está DENTRO quando o pai (subpasta) ou a seção (arquivo) também está na lixeira e aparece na lista.
       Só as raízes vão ao servidor: a subpasta já leva a subárvore (restaurar devolve o que saiu junto com ela;
       excluir de vez apaga tudo de dentro), e mandar num lote seguinte um id que já voltou ou já saiu daria 404
       no lote inteiro. Raízes não dependem umas das outras: podem ir em lotes separados, em qualquer ordem. */
    function raizesDaLixeira(itens) {
        const pastasNaLista = {};
        itens.forEach(function (it) { if (it.tipo === 'pasta') pastasNaLista[Number(it.id)] = true; });
        return itens.filter(function (it) {
            const pai = it.tipo === 'pasta'
                ? (it.paiNaLixeira && it.paiId != null ? Number(it.paiId) : null)
                : (it.secaoNaLixeira && it.secaoId != null ? Number(it.secaoId) : null);
            return !(pai != null && pastasNaLista[pai] && !(it.tipo === 'pasta' && pai === Number(it.id)));
        });
    }
    function lotes(itens) {
        const saida = [];
        for (let i = 0; i < itens.length; i += teto) {
            const fatia = itens.slice(i, i + teto);
            saida.push({
                documentos: fatia.filter(function (it) { return it.tipo !== 'pasta'; }).map(function (it) { return Number(it.id); }),
                secoes: fatia.filter(function (it) { return it.tipo === 'pasta'; }).map(function (it) { return Number(it.id); }),
            });
        }
        return saida;
    }
    // Os lotes um depois do outro; a soma das contagens que o servidor devolve. Erro para no lote que falhou.
    function enviarEmLotes(url, token, listaDeLotes, somar, inicio) {
        return listaDeLotes.reduce(function (anterior, lote) {
            return anterior.then(function (total) {
                return postJson(url, { _token: token, documentos: lote.documentos, secoes: lote.secoes }).then(function (res) {
                    if (!res.ok || !res.j.ok) throw new Error(res.j.erro || '');
                    return somar(total, res.j);
                });
            });
        }, Promise.resolve(inicio));
    }
    function contar(par) { return (Number((par || {}).documentos) || 0) + (Number((par || {}).secoes) || 0); }
    /* Rodadas: manda as raízes, relê a lixeira da pasta e repete com as raízes novas (o que estava dentro de uma
       subpasta com outro carimbo fica na lixeira quando ela volta, e vira raiz). Para quando a lista esvazia, quando
       uma rodada não muda nada (tudo pulado) ou depois de RODADAS rodadas. */
    const RODADAS = 10;
    function emRodadas(g, url, somar, itens) {
        function rodada(n, total, atuais) {
            const raizes = raizesDaLixeira(atuais);
            if (!raizes.length || n >= RODADAS) return Promise.resolve(total);
            return enviarEmLotes(url, g.csrfLote, lotes(raizes), somar, total).then(function (t) {
                // Nada mudou: o que sobrou foi pulado. Depois da 1ª rodada, os pulados já foram contados nela.
                if (t.n === total.n) return n === 0 ? t : total;
                return itensDaPasta(g).then(function (novos) { return rodada(n + 1, t, novos); });
            });
        }
        return rodada(0, { n: 0, pulados: 0 }, itens);
    }

    function restaurarTudo(li) {
        const g = li._grupo;
        confirmar(BJ_T.t('expediente.js.lixeira.confirmar_restaurar', { nup: g.nup, n: inteiro(g.documentos + g.subpastas) }), false).then(function (sim) {
            if (!sim) return;
            executar(li, BJ_T.t('expediente.js.lixeira.restaurando'), BJ_T.t('expediente.js.lixeira.erro_restaurar'), function (itens) {
                return emRodadas(g, g.urlRestaurar, function (t, j) { return { n: t.n + contar(j.restaurados), pulados: 0 }; }, itens)
                    .then(function (t) { return BJ_T.t('expediente.js.lixeira.restaurados', { n: inteiro(t.n), nup: g.nup }); });
            });
        });
    }
    function excluirDeVezTudo(li) {
        const g = li._grupo;
        confirmar(BJ_T.t('expediente.js.lixeira.confirmar_excluir_de_vez', { n: inteiro(g.documentos + g.subpastas) }), true).then(function (sim) {
            if (!sim) return;
            executar(li, BJ_T.t('expediente.js.lixeira.excluindo_de_vez'), BJ_T.t('expediente.js.lixeira.erro_excluir_de_vez'), function (itens) {
                return emRodadas(g, g.urlExcluirDeVez, function (t, j) { return { n: t.n + contar(j.excluidos), pulados: t.pulados + contar(j.pulados) }; }, itens)
                    .then(function (t) {
                        const texto = BJ_T.t('expediente.js.lixeira.excluidos_de_vez', { n: inteiro(t.n), nup: g.nup });
                        return t.pulados ? BJ_T.t('expediente.js.lixeira.pulados', { texto: texto, n: inteiro(t.pulados) }) : texto;
                    });
            });
        });
    }
    /* O passo comum: lê os itens da lixeira da pasta, faz a ação e relê, para o grupo mostrar o que sobrou (ou
       sair da lista). Um erro no meio deixa o grupo como o servidor o vê depois. */
    function executar(li, texto, textoDeErro, acao) {
        if (ocupado) return;
        travar(true);
        avisar(texto);
        itensDaPasta(li._grupo).then(function (itens) {
            if (!itens.length) return BJ_T.t('expediente.js.lixeira.ja_vazia');
            return acao(itens);
        }).then(function (fim) {
            avisar(fim);
        }, function (err) {
            avisar(err.message || textoDeErro, true);
        }).then(function () {
            return itensDaPasta(li._grupo).then(function (itens) { atualizarGrupo(li, itens); }, function () { /* fica como estava */ });
        }).then(function () { travar(false); });
    }
    function atualizarGrupo(li, itens) {
        if (!itens.length) {
            li.remove();
            atualizarRodape();
            return;
        }
        const g = li._grupo;
        const datas = itens.map(function (it) { return it.excluidoEm; }).filter(Boolean).sort();
        const nomes = [];
        itens.forEach(function (it) { const n = it.excluidoPor && it.excluidoPor.nome; if (n && nomes.indexOf(n) === -1) nomes.push(n); });
        nomes.sort(function (a, b) { return a.localeCompare(b, 'pt-BR'); });
        g.documentos = itens.filter(function (it) { return it.tipo !== 'pasta'; }).length;
        g.subpastas = itens.length - g.documentos;
        if (datas.length) { g.maisAntiga = datas[0]; g.maisRecente = datas[datas.length - 1]; }
        g.excluidoPor = nomes;
        montarGrupo(li);
    }
})();
