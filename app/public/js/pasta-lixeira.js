/* BlueJus · O modal da lixeira de UMA pasta (L7, D7). Saiu do pasta-explorador.js (03/11/2026) para abrir
   igual em dois lugares: na aba Documentos da pasta e na Lixeira do Expediente (pedido do Samuel: o mesmo
   modal, sem sair da página). Quem abre passa as rotas da pasta; nenhuma regra nova aqui: quem decide é o
   servidor da pasta.

   O desenho é omisso (no protótipo a exclusão é só memória): modal Bootstrap montado AQUI e pendurado no
   <body>, fora do painel animado da aba, pelo mesmo motivo dos outros modais do explorador. Tudo por
   createElement/textContent: nome é dado do usuário. Os itens vêm de `urlLixeira` (GET) e o que foi excluído
   DENTRO de uma pasta que também está na lixeira (`paiNaLixeira`/`secaoNaLixeira`) aparece agrupado embaixo
   dela. Restaurar (um ou os selecionados) vai a `urlRestaurar` com o csrfLote. "Excluir de vez" (um ou os
   selecionados, 03/11/2026) vai a `urlExcluirDeVez` com o mesmo csrfLote, sempre depois de uma confirmação;
   o botão vermelho segue o "Excluir definitivamente" da lixeira do chat (desenho 06 - Trabalho Externo, :596).
   Abrir (03/11/2026): o arquivo excluído abre numa aba nova pela rota `urlLixeira/<id>` (só leitura, a mesma
   permissão da lista), para conferir antes de restaurar ou excluir de vez.

   API: `BJ_LixeiraDaPasta.abrir(opcoes)` e `BJ_LixeiraDaPasta.ler(urlLixeira)`. `opcoes`: urlLixeira,
   urlRestaurar, urlExcluirDeVez, csrfLote, pastaExcluida, titulo (opcional), teto (itens por pedido),
   acimaDoTeto(n) (opcional; devolve true para barrar), aoLer(itens) (a cada leitura da lista),
   aoFechar(restaurou), listaAoFechar (o aviso de restaurado diz que a lista da pasta é atualizada ao fechar). */
(function () {
    'use strict';
    if (window.BJ_LixeiraDaPasta) return;

    const TIPOS_DE_ARQUIVO = window.BJ_TiposDeArquivo;
    let cfg = {};                    // as opções da abertura atual
    let lixeiraModal = null;         // { raiz, bs, titulo, lista, vazio, aviso, somenteVer, restaurarSel, excluirSel }
    let lixeiraItens = [];
    const lixeiraMarcados = new Set();
    let lixeiraRestaurou = false;    // algo voltou: quem abriu recarrega o que mostra ao fechar
    let lixeiraOcupada = false;
    let geracao = 0;                 // cada abertura é uma geração: resposta de abertura anterior é descartada

    // ------------------------------------------------------------- utils ----
    function h(tag, attrs, filhos) {
        const n = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                const v = attrs[k];
                if (v === null || v === undefined || v === false) return;
                if (k === 'class') n.className = v;
                else if (k === 'text') n.textContent = v;
                else n.setAttribute(k, v === true ? '' : String(v));
            });
        }
        (filhos || []).forEach(function (f) {
            if (f === null || f === undefined || f === false) return;
            n.appendChild(typeof f === 'string' ? document.createTextNode(f) : f);
        });
        return n;
    }
    function icone(classes) { return h('i', { class: 'bi ' + classes, 'aria-hidden': 'true' }); }
    function formatarBytes(b) {
        b = Number(b) || 0;
        if (b <= 0) return '0 B';
        if (b < 1024) return b + ' B';
        if (b < 1024 * 1024) return (b / 1024).toFixed(1).replace('.', ',') + ' KB';
        if (b < 1024 * 1024 * 1024) return (b / 1024 / 1024).toFixed(1).replace('.', ',') + ' MB';
        return (b / 1024 / 1024 / 1024).toFixed(2).replace('.', ',') + ' GB';
    }
    function formatarInteiro(n) { return Number(n).toLocaleString('pt-BR'); }
    function formatarDataHora(s) {
        const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
        if (!m) return '';
        return m[3] + '/' + m[2] + '/' + m[1] + (m[4] ? ' ' + m[4] + ':' + m[5] : '');
    }
    function lerResposta(r) {
        return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; });
    }
    function postJson(url, corpo) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(corpo),
        }).then(lerResposta);
    }
    // Divide chaves em ids de documentos e de pastas, como o corpo do lote espera.
    function separarChaves(chaves) {
        const docs = [], secs = [];
        chaves.forEach(function (k) {
            const partes = k.split(':');
            const id = Number(partes[1]);
            if (!id) return;
            if (partes[0] === 'pasta') secs.push(id); else docs.push(id);
        });
        return { documentos: docs, secoes: secs };
    }
    function acimaDoTeto(n) {
        if (typeof cfg.acimaDoTeto === 'function') return cfg.acimaDoTeto(n);
        const teto = Number(cfg.teto) || 2000;
        if (n <= teto) return false;
        avisarNaLixeira(BJ_T.t('pasta_scripts.js.explorador.acima_do_teto', { teto: formatarInteiro(teto) }), true);
        return true;
    }
    // O ícone pequeno do tipo (o mesmo do explorador abaixo do tamanho "Office").
    function iconeArquivo(nome) {
        const t = TIPOS_DE_ARQUIVO.tipoDe(nome);
        return icone(t[0] + ' pex-ico-' + t[1]);
    }

    // ------------------------------------------------------------ lixeira ---
    function podeEscreverNaLixeira() { return !cfg.pastaExcluida && !!cfg.urlRestaurar && !!cfg.csrfLote; }
    function podeExcluirDeVez() { return podeEscreverNaLixeira() && !!cfg.urlExcluirDeVez; }
    function chaveDaLixeira(it) { return (it.tipo === 'pasta' ? 'pasta' : 'arquivo') + ':' + Number(it.id); }
    // O arquivo excluído abre pela lixeira da própria pasta: `urlLixeira/<id>` (só leitura).
    function urlParaAbrir(it) { return String(cfg.urlLixeira).replace(/\/+$/, '') + '/' + Number(it.id); }

    function ler(url) {
        return fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }).then(lerResposta).then(function (res) {
            if (!res.ok || !res.j.ok || !Array.isArray(res.j.itens)) throw new Error((res.j && res.j.erro) || BJ_T.t('pasta_scripts.js.explorador.lix_erro_abrir'));
            return res.j.itens;
        });
    }
    function lerLixeira() {
        const minha = geracao;
        return ler(cfg.urlLixeira).then(function (itens) {
            if (minha !== geracao) throw new Error('');      // o modal foi reaberto para outra pasta
            if (typeof cfg.aoLer === 'function') cfg.aoLer(itens);
            return itens;
        });
    }

    function montarModalDaLixeira() {
        if (lixeiraModal) return lixeiraModal;
        const titulo       = h('span', { text: ' ' + BJ_T.t('pasta_scripts.js.explorador.lixeira') });
        const lista        = h('ul', { class: 'pex-lix-lista', 'aria-label': BJ_T.t('pasta_scripts.js.explorador.lix_itens') });
        const vazio        = h('div', { class: 'pex-lix-vazio', hidden: true }, [icone('bi-trash3'), h('span', { text: BJ_T.t('pasta_scripts.js.explorador.lix_vazia') })]);
        const aviso        = h('p', { class: 'pex-lix-aviso', role: 'status', 'aria-live': 'polite', hidden: true });
        const nota         = h('p', { class: 'pex-lix-nota' }, [icone('bi-clock-history'), h('span', { text: BJ_T.t('pasta_scripts.js.explorador.lix_nota') })]);
        const somenteVer   = h('p', { class: 'pex-lix-nota pex-lix-nota--lapide', hidden: true }, [icone('bi-lock'), h('span', { text: BJ_T.t('pasta_scripts.js.explorador.lix_lapide') })]);
        const restaurarSel = h('button', { type: 'button', class: 'btn btn-primary', id: 'pexLixeiraRestaurarSel', disabled: true, hidden: !podeEscreverNaLixeira() }, [icone('bi-arrow-counterclockwise'), h('span', { text: ' ' + BJ_T.t('pasta_scripts.js.explorador.lix_restaurar_selecionados') })]);
        const excluirSel   = h('button', { type: 'button', class: 'btn pex-lix-excluir-de-vez', id: 'pexLixeiraExcluirDeVezSel', disabled: true, hidden: !podeExcluirDeVez() }, [icone('bi-x-octagon'), h('span', { text: ' ' + BJ_T.t('pasta_scripts.js.explorador.lix_excluir_de_vez_sel') })]);
        const fechar       = h('button', { type: 'button', class: 'btn btn-outline-secondary', 'data-bs-dismiss': 'modal', text: BJ_T.t('pasta_scripts.js.explorador.fechar') });
        const raizModal = h('div', { class: 'modal fade pex-modal pex-lix', id: 'pexLixeiraModal', tabindex: '-1', 'aria-labelledby': 'pexLixeiraTitulo', 'aria-hidden': 'true' }, [
            h('div', { class: 'modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg' }, [
                h('div', { class: 'modal-content' }, [
                    h('div', { class: 'modal-header' }, [
                        h('h5', { class: 'modal-title', id: 'pexLixeiraTitulo' }, [icone('bi-trash3 me-1'), titulo]),
                        h('button', { type: 'button', class: 'btn-close', 'data-bs-dismiss': 'modal', 'aria-label': BJ_T.t('pasta_scripts.js.explorador.fechar') }),
                    ]),
                    h('div', { class: 'modal-body' }, [nota, somenteVer, aviso, vazio, lista]),
                    h('div', { class: 'modal-footer' }, [fechar, excluirSel, restaurarSel]),
                ]),
            ]),
        ]);
        document.body.appendChild(raizModal);
        lixeiraModal = { raiz: raizModal, bs: window.bootstrap ? bootstrap.Modal.getOrCreateInstance(raizModal) : null, titulo: titulo, lista: lista, vazio: vazio, aviso: aviso, somenteVer: somenteVer, restaurarSel: restaurarSel, excluirSel: excluirSel };

        lista.addEventListener('change', function (e) {
            const cx = e.target.closest('input[data-pex-lix]');
            if (!cx) return;
            if (cx.checked) lixeiraMarcados.add(cx.dataset.pexLix); else lixeiraMarcados.delete(cx.dataset.pexLix);
            atualizarBotaoRestaurarSelecionados();
        });
        lista.addEventListener('click', function (e) {
            const x = e.target.closest('button[data-pex-lix-excluir-de-vez]');
            if (x) { if (!x.disabled) excluirDeVezDaLixeira([x.dataset.pexLixExcluirDeVez]); return; }
            const b = e.target.closest('button[data-pex-lix-restaurar]');
            if (!b || b.disabled) return;
            restaurarDaLixeira([b.dataset.pexLixRestaurar]);
        });
        restaurarSel.addEventListener('click', function () {
            if (restaurarSel.disabled) return;
            restaurarDaLixeira(Array.from(lixeiraMarcados));
        });
        excluirSel.addEventListener('click', function () {
            if (excluirSel.disabled) return;
            excluirDeVezDaLixeira(Array.from(lixeiraMarcados));
        });
        // Quem abriu decide o que fazer ao fechar (recarregar a lista da pasta, devolver o foco...).
        raizModal.addEventListener('hidden.bs.modal', function () {
            const restaurou = lixeiraRestaurou;
            lixeiraRestaurou = false;
            if (typeof cfg.aoFechar === 'function') cfg.aoFechar(restaurou);
        });
        return lixeiraModal;
    }

    function abrir(opcoes) {
        if (!opcoes || !opcoes.urlLixeira || !window.bootstrap) return false;
        cfg = opcoes;
        geracao++;
        lixeiraOcupada = false;
        lixeiraRestaurou = false;
        const m = montarModalDaLixeira();
        lixeiraItens = [];
        lixeiraMarcados.clear();
        m.titulo.textContent = ' ' + (cfg.titulo || BJ_T.t('pasta_scripts.js.explorador.lixeira'));
        m.somenteVer.hidden = !cfg.pastaExcluida;
        m.restaurarSel.hidden = !podeEscreverNaLixeira();
        m.excluirSel.hidden = !podeExcluirDeVez();
        m.lista.textContent = '';
        m.vazio.hidden = true;
        avisarNaLixeira(BJ_T.t('pasta_scripts.js.explorador.carregando'));
        atualizarBotaoRestaurarSelecionados();
        if (m.bs) m.bs.show();
        recarregarLixeira();
        return true;
    }
    function recarregarLixeira() {
        const minha = geracao;
        lerLixeira().then(function (itens) {
            lixeiraItens = itens;
            avisarNaLixeira('');
            renderizarLixeira();
        }).catch(function (err) {
            if (minha !== geracao) return;
            avisarNaLixeira(err.message || BJ_T.t('pasta_scripts.js.explorador.erro_comunicacao'), true);
        });
    }
    function avisarNaLixeira(texto, erro) {
        if (!lixeiraModal) return;
        lixeiraModal.aviso.hidden = !texto;
        lixeiraModal.aviso.textContent = texto || '';
        lixeiraModal.aviso.classList.toggle('pex-lix-aviso--erro', !!erro);
    }
    function atualizarBotaoRestaurarSelecionados() {
        if (!lixeiraModal) return;
        const n = lixeiraMarcados.size;
        const b = lixeiraModal.restaurarSel;
        b.disabled = lixeiraOcupada || n === 0 || !podeEscreverNaLixeira();
        b.lastChild.textContent = ' ' + (n ? BJ_T.t('pasta_scripts.js.explorador.lix_restaurar_selecionados_n', { n: n }) : BJ_T.t('pasta_scripts.js.explorador.lix_restaurar_selecionados'));
        const x = lixeiraModal.excluirSel;
        x.disabled = lixeiraOcupada || n === 0 || !podeExcluirDeVez();
        x.lastChild.textContent = ' ' + (n ? BJ_T.t('pasta_scripts.js.explorador.lix_excluir_de_vez_sel_n', { n: n }) : BJ_T.t('pasta_scripts.js.explorador.lix_excluir_de_vez_sel'));
    }

    /* Agrupamento: o item excluído dentro de uma pasta que TAMBÉM está na lixeira fica embaixo
       dela (pai/seção presente na lista). O resto é raiz da lista, na ordem do servidor (do mais
       recente ao mais antigo). Ciclo gravado no banco não trava: cada id entra uma vez. */
    function arvoreDaLixeira(itens) {
        const pastasNaLista = {};
        itens.forEach(function (it) { if (it.tipo === 'pasta') pastasNaLista[Number(it.id)] = it; });
        const filhos = {};
        const raizes = [];
        itens.forEach(function (it) {
            const pai = it.tipo === 'pasta'
                ? (it.paiNaLixeira && it.paiId != null ? Number(it.paiId) : null)
                : (it.secaoNaLixeira && it.secaoId != null ? Number(it.secaoId) : null);
            if (pai != null && pastasNaLista[pai] && !(it.tipo === 'pasta' && pai === Number(it.id))) (filhos[pai] = filhos[pai] || []).push(it);
            else raizes.push(it);
        });
        return { raizes: raizes, filhos: filhos };
    }
    function renderizarLixeira() {
        if (!lixeiraModal) return;
        const m = lixeiraModal;
        const arvore = arvoreDaLixeira(lixeiraItens);
        const frag = document.createDocumentFragment();
        const vistos = {};
        const escrever = podeEscreverNaLixeira();
        const montar = function (it, nivel, pai) {
            const chave = chaveDaLixeira(it);
            if (vistos[chave] || nivel > 50) return;
            vistos[chave] = true;
            frag.appendChild(linhaDaLixeira(it, nivel, pai, escrever));
            if (it.tipo === 'pasta') (arvore.filhos[Number(it.id)] || []).forEach(function (f) { montar(f, nivel + 1, it); });
        };
        arvore.raizes.forEach(function (it) { montar(it, 0, null); });
        // O que sobrou de um ciclo (nenhuma raiz o alcança) entra no nível de cima.
        lixeiraItens.forEach(function (it) { if (!vistos[chaveDaLixeira(it)]) montar(it, 0, null); });
        m.lista.textContent = '';
        m.lista.appendChild(frag);
        m.vazio.hidden = lixeiraItens.length > 0;
        atualizarBotaoRestaurarSelecionados();
    }
    function linhaDaLixeira(it, nivel, pai, escrever) {
        const chave = chaveDaLixeira(it);
        const ehPasta = it.tipo === 'pasta';
        const junto = !!pai && pai.excluidoEm != null && pai.excluidoEm === it.excluidoEm;
        const partes = [ehPasta ? TIPOS_DE_ARQUIVO.TIPO_PASTA[2] : TIPOS_DE_ARQUIVO.tipoDe(it.nome)[2]];
        if (!ehPasta && it.tamanho != null) partes.push(formatarBytes(it.tamanho));
        const dataExclusao = formatarDataHora(it.excluidoEm);
        const excluido = dataExclusao ? BJ_T.t('pasta_scripts.js.explorador.lix_excluido_em', { data: dataExclusao }) : BJ_T.t('pasta_scripts.js.explorador.lix_excluido_sem_data');
        const quando = it.excluidoPor && it.excluidoPor.nome ? BJ_T.t('pasta_scripts.js.explorador.lix_excluido_por', { quando: excluido, nome: it.excluidoPor.nome }) : excluido;
        const origem = junto
            ? BJ_T.t('pasta_scripts.js.explorador.lix_junto_com', { nome: pai.nome })
            : (pai ? BJ_T.t('pasta_scripts.js.explorador.lix_pai_na_lixeira', { nome: pai.nome }) : BJ_T.t('pasta_scripts.js.explorador.lix_local_original', { local: it.caminho || BJ_T.t('pasta_scripts.js.explorador.raiz') }));
        const nomeId = 'pex-lix-' + chave.replace(':', '-');
        // Arquivo: o nome é o link que abre numa aba nova (só leitura); pasta não abre.
        const nome = ehPasta
            ? h('span', { class: 'pex-lix-nome', id: nomeId, text: it.nome, title: it.nome })
            : h('a', { class: 'pex-lix-nome pex-lix-nome--link', id: nomeId, href: urlParaAbrir(it), target: '_blank', rel: 'noopener', text: it.nome, title: BJ_T.t('pasta_scripts.js.explorador.lix_abrir_titulo', { nome: it.nome }) });
        return h('li', { class: 'pex-lix-item' + (nivel ? ' pex-lix-item--dentro' : ''), style: '--pex-lix-nivel:' + Math.min(nivel, 6), 'data-pex-lix-item': chave }, [
            escrever ? h('input', { type: 'checkbox', class: 'form-check-input pex-lix-marcar', 'data-pex-lix': chave, 'aria-labelledby': nomeId, checked: lixeiraMarcados.has(chave) }) : null,
            ehPasta ? icone(TIPOS_DE_ARQUIVO.TIPO_PASTA[0] + ' pex-lix-ico pex-ico-pasta') : h('span', { class: 'pex-lix-ico' }, [iconeArquivo(it.nome)]),
            h('div', { class: 'pex-lix-txt' }, [
                nome,
                h('span', { class: 'pex-lix-sub', text: partes.join(' · ') + ' · ' + quando }),
                h('span', { class: 'pex-lix-sub', text: origem }),
            ]),
            ehPasta ? null : h('a', {
                class: 'btn btn-sm btn-outline-secondary pex-lix-abrir',
                href: urlParaAbrir(it),
                target: '_blank',
                rel: 'noopener',
                'data-pex-lix-abrir': chave,
                title: BJ_T.t('pasta_scripts.js.explorador.lix_abrir_titulo', { nome: it.nome }),
            }, [icone('bi-box-arrow-up-right'), h('span', { text: ' ' + BJ_T.t('pasta_scripts.js.explorador.lix_abrir') })]),
            escrever ? h('button', {
                type: 'button',
                class: 'btn btn-sm btn-outline-primary pex-lix-restaurar',
                'data-pex-lix-restaurar': chave,
                title: pai ? BJ_T.t('pasta_scripts.js.explorador.lix_volta_raiz') : BJ_T.t('pasta_scripts.js.explorador.lix_volta_para', { local: it.caminho || BJ_T.t('pasta_scripts.js.explorador.raiz') }),
                disabled: lixeiraOcupada,
            }, [icone('bi-arrow-counterclockwise'), h('span', { text: ' ' + BJ_T.t('pasta_scripts.js.explorador.lix_restaurar') })]) : null,
            escrever && podeExcluirDeVez() ? h('button', {
                type: 'button',
                class: 'btn btn-sm pex-lix-excluir-de-vez',
                'data-pex-lix-excluir-de-vez': chave,
                title: BJ_T.t('pasta_scripts.js.explorador.lix_excluir_de_vez_titulo'),
                disabled: lixeiraOcupada,
            }, [icone('bi-x-octagon'), h('span', { text: ' ' + BJ_T.t('pasta_scripts.js.explorador.lix_excluir_de_vez') })]) : null,
        ]);
    }
    function restaurarDaLixeira(chaves) {
        if (!chaves.length || lixeiraOcupada || !podeEscreverNaLixeira()) return;
        const lote = separarChaves(chaves);
        if (acimaDoTeto(lote.documentos.length + lote.secoes.length)) return;
        const minha = geracao;
        lixeiraOcupada = true;
        travarBotoesDaLixeira();
        avisarNaLixeira(BJ_T.t('pasta_scripts.js.explorador.restaurando'));
        postJson(cfg.urlRestaurar, { _token: cfg.csrfLote, documentos: lote.documentos, secoes: lote.secoes }).then(function (res) {
            if (!res.ok || !res.j.ok) throw new Error((res.j && res.j.erro) || BJ_T.t('pasta_scripts.js.explorador.lix_erro_restaurar'));
            lixeiraRestaurou = true;
            const r = res.j.restaurados || {};
            const n = (Number(r.documentos) || 0) + (Number(r.secoes) || 0);
            const naRaiz = Number(res.j.paraARaiz) || 0;
            chaves.forEach(function (k) { lixeiraMarcados.delete(k); });
            const restaurados = n === 1 ? BJ_T.t('pasta_scripts.js.explorador.lix_restaurado_um') : BJ_T.t('pasta_scripts.js.explorador.lix_restaurados_varios', { n: formatarInteiro(n) });
            const texto = naRaiz ? BJ_T.t('pasta_scripts.js.explorador.lix_restaurado_na_raiz', { texto: restaurados, n: formatarInteiro(naRaiz) }) : restaurados;
            avisarNaLixeira(cfg.listaAoFechar ? BJ_T.t('pasta_scripts.js.explorador.lix_restaurado_fim', { texto: texto }) : texto);
            return lerLixeira().then(function (itens) { lixeiraItens = itens; });
        }).catch(function (err) {
            if (minha === geracao) avisarNaLixeira(err.message || BJ_T.t('pasta_scripts.js.explorador.erro_comunicacao'), true);
        }).then(function () {
            if (minha !== geracao) return;      // o modal foi reaberto para outra pasta no meio do pedido
            lixeiraOcupada = false;
            Array.from(lixeiraMarcados).forEach(function (k) {
                if (!lixeiraItens.some(function (it) { return chaveDaLixeira(it) === k; })) lixeiraMarcados.delete(k);
            });
            renderizarLixeira();
        });
    }
    function travarBotoesDaLixeira() {
        lixeiraModal.lista.querySelectorAll('button[data-pex-lix-restaurar], button[data-pex-lix-excluir-de-vez]').forEach(function (b) { b.disabled = true; });
        atualizarBotaoRestaurarSelecionados();
    }
    /* Excluir de vez (03/11/2026): um ou os selecionados, SEMPRE com a confirmação antes (a janela
       vermelha da tela, que já sabe perguntar com um modal aberto; sem ela, o confirm do
       navegador). O pedido só sai depois do "sim". Depois, como o restaurar: relê a lista (e quem
       abriu recebe a contagem nova por `aoLer`). */
    function excluirDeVezDaLixeira(chaves) {
        if (!chaves.length || lixeiraOcupada || !podeExcluirDeVez()) return;
        const lote = separarChaves(chaves);
        if (acimaDoTeto(lote.documentos.length + lote.secoes.length)) return;
        const pergunta = BJ_T.t('pasta_scripts.js.explorador.lix_confirmar_excluir_de_vez', { n: formatarInteiro(chaves.length) });
        const resposta = window.confirmarUIPs ? window.confirmarUIPs(pergunta, true) : Promise.resolve(confirm(pergunta));
        resposta.then(function (sim) { if (sim) excluirDeVezConfirmado(); });
        function excluirDeVezConfirmado() {
            if (lixeiraOcupada) return;
            const minha = geracao;
            lixeiraOcupada = true;
            travarBotoesDaLixeira();
            avisarNaLixeira(BJ_T.t('pasta_scripts.js.explorador.lix_excluindo_de_vez'));
            postJson(cfg.urlExcluirDeVez, { _token: cfg.csrfLote, documentos: lote.documentos, secoes: lote.secoes }).then(function (res) {
                if (!res.ok || !res.j.ok) throw new Error((res.j && res.j.erro) || BJ_T.t('pasta_scripts.js.explorador.lix_erro_excluir_de_vez'));
                const r = res.j.excluidos || {};
                const p = res.j.pulados || {};
                const n = (Number(r.documentos) || 0) + (Number(r.secoes) || 0);
                const pulados = (Number(p.documentos) || 0) + (Number(p.secoes) || 0);
                chaves.forEach(function (k) { lixeiraMarcados.delete(k); });
                const texto = n === 1 ? BJ_T.t('pasta_scripts.js.explorador.lix_excluido_de_vez_um') : BJ_T.t('pasta_scripts.js.explorador.lix_excluidos_de_vez_varios', { n: formatarInteiro(n) });
                avisarNaLixeira(pulados ? BJ_T.t('pasta_scripts.js.explorador.lix_excluido_de_vez_pulados', { texto: texto, n: formatarInteiro(pulados) }) : texto);
                return lerLixeira().then(function (itens) { lixeiraItens = itens; });
            }).catch(function (err) {
                if (minha === geracao) avisarNaLixeira(err.message || BJ_T.t('pasta_scripts.js.explorador.erro_comunicacao'), true);
            }).then(function () {
                if (minha !== geracao) return;
                lixeiraOcupada = false;
                Array.from(lixeiraMarcados).forEach(function (k) {
                    if (!lixeiraItens.some(function (it) { return chaveDaLixeira(it) === k; })) lixeiraMarcados.delete(k);
                });
                renderizarLixeira();
            });
        }
    }

    window.BJ_LixeiraDaPasta = { abrir: abrir, ler: ler };
})();
