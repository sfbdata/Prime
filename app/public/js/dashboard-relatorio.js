/**
 * Dashboard: modal "Ver relatório de X" da coluna Relatório (spec dashboard-parte-1, PR-D; desenho
 * "01 - Dashboard - VS 25.10.26", `comVer`).
 *
 * Abre pelo clique no número da coluna Relatório (`[data-db-relatorio-url]`) ou pelo item "Ver
 * relatório" do menu da célula (dashboard-zerar.js chama `window.DbRelatorio.abrir`). Busca em
 * `dashboard_relatorio` (JSON) os registros da pessoa, agrupados por pasta, e mostra pasta, data e
 * hora de cada um. O total do cabeçalho e do rodapé é o número da célula (mesmo critério no servidor).
 *
 * Nada de HTML montado por string: tudo que vem do servidor (nome, cliente, trecho do registro) entra
 * por textContent. A única URL usada vem do servidor e só vira link se for caminho do próprio site.
 */
(function () {
    'use strict';

    var aberto = null;

    function t(chave, vars) {
        return window.BJ_T.t(chave, vars);
    }

    function el(tag, classe, texto) {
        var n = document.createElement(tag);
        if (classe) { n.className = classe; }
        if (texto !== undefined && texto !== null) { n.textContent = String(texto); }

        return n;
    }

    function icone(classe) {
        var i = el('i', 'bi ' + classe);
        i.setAttribute('aria-hidden', 'true');

        return i;
    }

    function qtdRegistros(n) {
        return n === 1 ? t('dashboard.relatorio.js.registro_um') : t('dashboard.relatorio.js.registros', { n: n });
    }

    function qtdPastas(n) {
        return n === 1 ? t('dashboard.relatorio.js.pasta_uma') : t('dashboard.relatorio.js.pastas', { n: n });
    }

    function quando(iso) {
        var d = new Date(iso);
        if (isNaN(d.getTime())) { return ''; }
        var dois = function (n) { return String(n).padStart(2, '0'); };

        return dois(d.getDate()) + '/' + dois(d.getMonth() + 1) + ' ' + dois(d.getHours()) + ':' + dois(d.getMinutes());
    }

    function resumo(dados) {
        var registros = qtdRegistros(dados.total);
        if (dados.restantes === 0 && dados.grupos.length > 0) {
            registros = t('dashboard.relatorio.js.resumo_pastas', { registros: registros, pastas: qtdPastas(dados.grupos.length) });
        }

        return dados.corte
            ? t('dashboard.relatorio.js.resumo_corte', { registros: registros, quando: quando(dados.corte) })
            : t('dashboard.relatorio.js.resumo_completo', { registros: registros });
    }

    function fechar() {
        if (!aberto) { return; }
        var origem = aberto.origem;
        aberto.fundo.remove();
        document.removeEventListener('keydown', aberto.teclas);
        aberto = null;
        if (origem && origem.focus && document.body.contains(origem)) { origem.focus(); }
    }

    function rotuloDaAba(aba) {
        if (aba === 'financeiro') { return t('dashboard.relatorio.js.aba_financeiro'); }
        if (aba === 'detalhes') { return t('dashboard.relatorio.js.aba_detalhes'); }

        return t('dashboard.relatorio.js.aba_dados');
    }

    function caminhoSeguro(url) {
        return typeof url === 'string' && /^\/[\w\-./#]*$/.test(url);
    }

    function cartaoDoGrupo(g) {
        var cartao = el('div', 'db-rel-grupo');
        var cab = el(caminhoSeguro(g.url) ? 'a' : 'div', 'db-rel-grupo-cab');
        if (cab.tagName === 'A') {
            cab.href = g.url;
            cab.title = t('dashboard.relatorio.js.abrir_pasta');
        }
        var titulo = g.titulo ? t('dashboard.relatorio.js.pasta_titulo', { numero: g.titulo }) : t('dashboard.relatorio.js.pasta_sem_numero');
        var txt = el('span', 'db-rel-grupo-txt');
        txt.append(el('span', 'db-rel-grupo-titulo', titulo), el('span', 'db-rel-grupo-cliente', g.cliente || ''));
        cab.append(icone('bi-folder2-open db-rel-grupo-ico'), txt, el('span', 'db-rel-grupo-qtd', qtdRegistros(g.itens.length)), icone('bi-chevron-right db-rel-grupo-seta'));
        cartao.appendChild(cab);

        g.itens.forEach(function (x) {
            var linha = el('div', 'db-rel-item');
            var quandoCol = el('span', 'db-rel-item-quando');
            quandoCol.append(el('span', 'db-rel-item-data', x.data), el('span', 'db-rel-item-hora', x.hora));
            var corpo = el('span', 'db-rel-item-corpo');
            var aba = x.aba === 'financeiro' || x.aba === 'detalhes' ? x.aba : 'dados';
            corpo.append(el('span', 'db-rel-aba db-rel-aba--' + aba, rotuloDaAba(aba)), el('span', 'db-rel-item-trecho', x.oculto ? t('dashboard.relatorio.js.aba_oculta') : (x.trecho || t('dashboard.relatorio.js.sem_texto'))));
            linha.append(quandoCol, corpo);
            cartao.appendChild(linha);
        });

        return cartao;
    }

    function preencher(modal, dados) {
        modal.sub.textContent = resumo(dados);
        modal.corpo.textContent = '';
        if (dados.grupos.length === 0) {
            modal.corpo.appendChild(el('div', 'db-rel-vazio', t('dashboard.relatorio.js.vazio')));
        }
        dados.grupos.forEach(function (g) { modal.corpo.appendChild(cartaoDoGrupo(g)); });

        modal.rodape.textContent = '';
        modal.rodape.appendChild(el('strong', 'db-rel-rodape-total', t('dashboard.relatorio.js.rodape_total', { registros: qtdRegistros(dados.total) })));
        if (dados.restantes > 0) {
            var mostrados = dados.total - dados.restantes;
            modal.rodape.appendChild(el('span', 'db-rel-rodape-resto', t('dashboard.relatorio.js.rodape_restantes', { n: mostrados, restantes: dados.restantes })));
        }
        modal.rodape.hidden = false;
    }

    function erro(modal) {
        modal.sub.textContent = '';
        modal.corpo.textContent = '';
        modal.corpo.appendChild(el('div', 'db-rel-vazio', t('dashboard.relatorio.js.erro')));
    }

    function abrir(url, nome, origem) {
        fechar();
        var fundo = el('div', 'db-rel-fundo');
        var caixa = el('div', 'db-rel-dialogo');
        caixa.setAttribute('role', 'dialog');
        caixa.setAttribute('aria-modal', 'true');
        caixa.setAttribute('aria-label', t('dashboard.relatorio.js.aria'));

        var cab = el('div', 'db-rel-cab');
        var txt = el('div', 'db-rel-cab-txt');
        var sub = el('span', 'db-rel-cab-sub', t('dashboard.relatorio.js.carregando'));
        txt.append(el('span', 'db-rel-cab-titulo', t('dashboard.relatorio.js.titulo', { nome: nome })), sub);
        var botao = el('button', 'db-rel-fechar');
        botao.type = 'button';
        botao.setAttribute('aria-label', t('dashboard.relatorio.js.fechar'));
        botao.appendChild(icone('bi-x-lg'));
        cab.append(icone('bi-journal-text db-rel-cab-ico'), txt, botao);

        var corpo = el('div', 'db-rel-corpo');
        var rodape = el('div', 'db-rel-rodape');
        rodape.hidden = true;
        caixa.append(cab, corpo, rodape);
        fundo.appendChild(caixa);
        document.body.appendChild(fundo);

        var modal = { sub: sub, corpo: corpo, rodape: rodape };
        var teclas = function (e) { if (e.key === 'Escape') { fechar(); } };
        document.addEventListener('keydown', teclas);
        aberto = { fundo: fundo, teclas: teclas, origem: origem || null };

        fundo.addEventListener('mousedown', function (e) { if (e.target === fundo) { fechar(); } });
        botao.addEventListener('click', fechar);
        botao.focus();

        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
            .then(function (dados) { if (aberto && aberto.fundo === fundo) { preencher(modal, dados); } })
            .catch(function () { if (aberto && aberto.fundo === fundo) { erro(modal); } });
    }

    window.DbRelatorio = { abrir: abrir };

    document.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-db-relatorio-url]');
        if (!b) { return; }
        e.preventDefault();
        abrir(b.getAttribute('data-db-relatorio-url'), b.getAttribute('data-db-relatorio-nome') || '', b);
    });
})();
