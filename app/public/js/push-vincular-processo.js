/* Modal "Vincular processo" do Push (desenho 11): buscar processo existente ou cadastrar novo (CNJ). */
(function () {
    'use strict';
    var raiz = document.querySelector('[data-pp-vp]');
    if (!raiz) { return; }

    var abrir = document.querySelectorAll('[data-push-cadastrar-processo]');
    var painelBusca = raiz.querySelector('[data-pp-vp-painel="busca"]');
    var formNovo = raiz.querySelector('[data-pp-vp-form-novo]');
    var formBusca = raiz.querySelector('[data-pp-vp-form-busca]');
    var erro = raiz.querySelector('[data-pp-vp-erro]');
    var campoQ = raiz.querySelector('[data-pp-vp-q]');
    var lista = raiz.querySelector('[data-pp-vp-lista]');
    var numero = raiz.querySelector('[data-pp-vp-numero]');
    var dica = raiz.querySelector('[data-pp-vp-numero-dica]');
    var botaoCnj = raiz.querySelector('[data-pp-vp-cnj]');
    var partes = raiz.querySelector('[data-pp-vp-partes]');
    var estado = { modo: 'busca', escolhido: '', buscando: false, temporizador: null };

    function t(chave, trocas) {
        var v = raiz.getAttribute('data-t-' + chave) || '';
        Object.keys(trocas || {}).forEach(function (k) { v = v.split('%' + k + '%').join(trocas[k]); });
        return v;
    }

    function mascara(v) {
        var n = String(v).replace(/\D/g, '').slice(0, 20);
        return n.slice(0, 7) + (n.length > 7 ? '-' + n.slice(7, 9) : '') + (n.length > 9 ? '.' + n.slice(9, 13) : '')
            + (n.length > 13 ? '.' + n.slice(13, 14) : '') + (n.length > 14 ? '.' + n.slice(14, 16) : '')
            + (n.length > 16 ? '.' + n.slice(16, 20) : '');
    }

    function numeroOk() { return /^\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}$/.test(numero.value); }
    function mostrarErro(msg) { erro.textContent = msg || ''; erro.hidden = !msg; }

    function modo(m) {
        estado.modo = m;
        raiz.querySelectorAll('[data-pp-vp-modo]').forEach(function (b) { b.classList.toggle('is-on', b.dataset.ppVpModo === m); });
        painelBusca.hidden = m !== 'busca';
        formNovo.hidden = m !== 'novo';
        mostrarErro('');
        if (m === 'busca') { buscar(); }
    }

    function aba(a) {
        raiz.querySelectorAll('[data-pp-vp-aba]').forEach(function (b) { b.classList.toggle('is-on', b.dataset.ppVpAba === a); });
        raiz.querySelectorAll('[data-pp-vp-aba-painel]').forEach(function (p) { p.hidden = p.dataset.ppVpAbaPainel !== a; });
    }

    function abrirModal() {
        formNovo.reset();
        partes.textContent = '';
        raiz.querySelector('[data-pp-vp-movs]').textContent = '';
        raiz.querySelector('[data-pp-vp-mov-vazio]').hidden = false;
        raiz.querySelector('[data-pp-vp-tribunal]').value = '';
        estado.escolhido = '';
        campoQ.value = '';
        aba('info');
        validarNumero();
        raiz.hidden = false;
        modo('busca');
        campoQ.focus();
    }

    function fechar() { raiz.hidden = true; }

    function buscar() {
        window.fetch(raiz.dataset.urlBusca + '?q=' + encodeURIComponent(campoQ.value), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : []; })
            .catch(function () { return []; })
            .then(function (itens) {
                lista.textContent = '';
                if (!itens.length) {
                    var vazio = document.createElement('div');
                    vazio.className = 'pp-vp__vazio';
                    vazio.textContent = t('vazio');
                    lista.appendChild(vazio);
                    return;
                }
                itens.forEach(function (i) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'pp-vp__item' + (estado.escolhido === i.numero ? ' is-sel' : '');
                    b.innerHTML = '<i class="bi bi-folder2"></i><span><b></b><em></em></span>';
                    b.querySelector('b').textContent = mascara(i.numero);
                    b.querySelector('em').textContent = (i.pasta ? t('pasta-n', { n: i.pasta }) + ' · ' : '') + i.tribunal;
                    b.addEventListener('click', function () { estado.escolhido = i.numero; mostrarErro(''); buscar(); });
                    lista.appendChild(b);
                });
            });
    }

    function validarNumero() {
        var tem = numero.value !== '';
        var ruim = tem && !numeroOk();
        numero.classList.toggle('is-erro', ruim);
        dica.classList.toggle('is-erro', ruim);
        dica.textContent = ruim ? t('formato-erro') : t('formato');
    }

    function campo(nome) { return formNovo.elements[nome]; }

    function linhaDeParte(dados) {
        var i = partes.children.length + '-' + Date.now();
        var div = document.createElement('div');
        div.className = 'pp-vp__parte';
        div.innerHTML = '<select data-n="tipo"><option value="PARTE" data-polo="ativo"></option><option value="PARTE" data-polo="passivo"></option><option value="TERCEIRO"></option></select>'
            + '<input type="text" data-n="nome"><input type="text" data-n="documento"><button type="button" data-remover><i class="bi bi-trash3"></i></button>';
        var sel = div.querySelector('select');
        var rot = [raiz.dataset.tPoloAtivo, raiz.dataset.tPoloPassivo, raiz.dataset.tTerceiro];
        Array.prototype.forEach.call(sel.options, function (o, k) { o.textContent = rot[k] || o.textContent; });
        div.querySelector('[data-n="nome"]').placeholder = raiz.dataset.tNomePh || '';
        div.querySelector('[data-n="documento"]').placeholder = raiz.dataset.tDocPh || '';
        div.querySelector('[data-remover]').addEventListener('click', function () { div.remove(); renumerar(); });
        partes.appendChild(div);
        if (dados) {
            div.querySelector('[data-n="nome"]').value = dados.nome || '';
            div.querySelector('[data-n="documento"]').value = dados.documento || '';
            sel.selectedIndex = dados.papel && /passiv|réu|reu|requerid/i.test(dados.papel) ? 1 : (dados.tipo === 'TERCEIRO' ? 2 : 0);
        }
        renumerar();
    }

    // Os nomes seguem partes[n][campo]; "papel" leva o polo para o cadastro.
    function renumerar() {
        Array.prototype.forEach.call(partes.children, function (div, n) {
            var sel = div.querySelector('select');
            var papel = sel.selectedIndex === 0 ? 'Polo ativo' : (sel.selectedIndex === 1 ? 'Polo passivo' : '');
            sel.name = 'partes[' + n + '][tipo]';
            sel.onchange = renumerar;
            div.querySelector('[data-n="nome"]').name = 'partes[' + n + '][nome]';
            div.querySelector('[data-n="documento"]').name = 'partes[' + n + '][documento]';
            var oculto = div.querySelector('input[type="hidden"]');
            if (!oculto) { oculto = document.createElement('input'); oculto.type = 'hidden'; div.appendChild(oculto); }
            oculto.name = 'partes[' + n + '][papel]';
            oculto.value = papel;
            sel.options[sel.selectedIndex].value = sel.options[sel.selectedIndex].dataset.polo ? 'PARTE' : sel.options[sel.selectedIndex].value;
        });
    }

    function preencherDoCnj(d) {
        var mapa = { orgaoJulgador: 'orgaoJulgador', classeProcessual: 'classeProcessual', situacaoProcesso: 'situacaoProcesso', instancia: 'instancia', dataDistribuicao: 'dataDistribuicao' };
        Object.keys(mapa).forEach(function (k) { if (d[k] && campo(mapa[k])) { campo(mapa[k]).value = d[k]; } });
        var assuntos = (d.assuntos || []).map(function (a) { return a.nome; }).filter(Boolean);
        campo('assuntoProcessual').value = assuntos.length ? assuntos.join(', ') : (d.assuntoProcessual || '');
        raiz.querySelector('[data-pp-vp-tribunal]').value = d.siglaTribunal || '';
        partes.textContent = '';
        (d.partes || []).forEach(function (p) { linhaDeParte({ nome: p.nome, documento: p.documento, papel: p.papel, tipo: p.tipo }); });
        var movs = raiz.querySelector('[data-pp-vp-movs]');
        movs.textContent = '';
        (d.movimentacoes || []).forEach(function (m, n) {
            var linha = document.createElement('div');
            linha.className = 'pp-vp__mov';
            var data = m.dataMovimentacao ? m.dataMovimentacao.split('-').reverse().join('/') : '';
            linha.innerHTML = '<b></b><span></span>';
            linha.querySelector('b').textContent = data;
            linha.querySelector('span').textContent = m.descricao || '';
            movs.appendChild(linha);
            [['dataMovimentacao', m.dataMovimentacao], ['descricao', m.descricao], ['tipo', m.tipo], ['orgao', m.orgao]].forEach(function (par) {
                var h = document.createElement('input');
                h.type = 'hidden';
                h.name = 'movimentacoes[' + n + '][' + par[0] + ']';
                h.value = par[1] || '';
                linha.appendChild(h);
            });
        });
        raiz.querySelector('[data-pp-vp-mov-vazio]').hidden = (d.movimentacoes || []).length > 0;
    }

    function consultarCnj() {
        if (!numeroOk()) { mostrarErro(t('formato-antes-de-buscar')); return; }
        if (estado.buscando) { return; }
        estado.buscando = true;
        botaoCnj.querySelector('span').textContent = t('buscando');
        botaoCnj.querySelector('i').className = 'bi bi-hourglass-split';
        mostrarErro('');
        window.fetch(raiz.dataset.urlCnj, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ numeroProcesso: numero.value })
        })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (res.ok && res.j.data) { preencherDoCnj(res.j.data); } else { mostrarErro(res.j.error || t('falha-cnj')); }
            })
            .catch(function () { mostrarErro(t('falha-cnj')); })
            .then(function () {
                estado.buscando = false;
                botaoCnj.querySelector('span').textContent = t('buscar-cnj');
                botaoCnj.querySelector('i').className = 'bi bi-search';
            });
    }

    function vincular() {
        if (estado.modo === 'busca') {
            if (!estado.escolhido) { mostrarErro(t('sem-selecao')); return; }
            formBusca.elements.numero.value = estado.escolhido;
            formBusca.submit();
            return;
        }
        if (!numeroOk()) { aba('info'); mostrarErro(t('numero-invalido')); return; }
        var falta = [['orgaoJulgador', 'órgão julgador'], ['classeProcessual', 'classe processual'], ['assuntoProcessual', 'assunto processual']]
            .filter(function (c) { return !String(campo(c[0]).value).trim(); }).map(function (c) { return c[1]; });
        if (falta.length) { aba('info'); mostrarErro(t('preencha', { campos: falta.join(', ') })); return; }
        renumerar();
        formNovo.submit();
    }

    function localizarPasta() {
        var n = estado.modo === 'novo' ? numero.value : estado.escolhido;
        if (String(n).replace(/\D/g, '').length === 0) { mostrarErro(t('sem-numero-pasta')); return; }
        var digitos = String(n).replace(/\D/g, '');
        fechar();
        var evento = new CustomEvent('pp:localizar-pasta', { cancelable: true, detail: { numero: digitos, numeroExibicao: mascara(digitos) } });
        if (document.dispatchEvent(evento) && window.ppToast) { window.ppToast(t('localizar-fora-da-lista')); }
    }

    abrir.forEach(function (b) { b.addEventListener('click', abrirModal); });
    raiz.addEventListener('click', function (e) {
        if (e.target.closest('[data-pp-vp-fechar]')) { fechar(); return; }
        var m = e.target.closest('[data-pp-vp-modo]');
        if (m) { modo(m.dataset.ppVpModo); return; }
        var a = e.target.closest('[data-pp-vp-aba]');
        if (a) { aba(a.dataset.ppVpAba); return; }
        if (e.target.closest('[data-pp-vp-add-parte]')) { linhaDeParte(); return; }
        if (e.target.closest('[data-pp-vp-cnj]')) { consultarCnj(); return; }
        if (e.target.closest('[data-pp-vp-vincular]')) { vincular(); return; }
        if (e.target.closest('[data-pp-vp-localizar]')) { localizarPasta(); }
    });
    numero.addEventListener('input', function () { numero.value = mascara(numero.value); mostrarErro(''); validarNumero(); });
    campoQ.addEventListener('input', function () { window.clearTimeout(estado.temporizador); estado.temporizador = window.setTimeout(buscar, 250); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !raiz.hidden) { fechar(); } });
}());
