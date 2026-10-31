/* =============================================================================
   pasta-controladoria.js: botão "Controladoria" e painel da pasta (desenho 02 VS 31.10.26): abas Alertas e
   Cadastro da pasta. Busca os alertas ao abrir a pasta (para o contador do botão) e mostra o painel ao clicar.
   ============================================================================= */
(function () {
    'use strict';

    var botao = document.querySelector('.js-ctl-abrir');
    var fundo = document.querySelector('.js-ctl-fundo');
    if (!botao || !fundo) { return; }
    var lista = fundo.querySelector('.js-ctl-lista');
    var total = fundo.querySelector('.js-ctl-total');
    var contador = botao.querySelector('.ctl-btn-n');
    var form = fundo.querySelector('.js-ctl-form');
    var status = form.querySelector('.js-ctl-status');
    var salvar = form.querySelector('.js-ctl-salvar');
    var CAMPOS = { reu: 'reu', cpf: 'cpf', qualificacao: 'qualificacao', email: 'email', endereco: 'endereco', contrato: 'contrato', unidade: 'unidade', titular_extrato: 'titularExtrato', prescricao: 'prescricao' };
    var enviando = false;

    function criar(tag, classe, texto) {
        var el = document.createElement(tag);
        if (classe) { el.className = classe; }
        if (texto) { el.textContent = texto; }
        return el;
    }

    function aviso(texto, erro) { if (window.avisarPs) { window.avisarPs(texto, erro ? 'erro' : undefined); } }

    function item(a) {
        var caixa = criar('div', 'ctl-item');
        var topo = criar('div', 'ctl-item-topo');
        caixa.setAttribute('data-nivel', a.nivel);
        topo.append(criar('b', '', a.titulo), criar('span', 'ctl-item-nivel', lista.getAttribute('data-' + a.nivel)));
        caixa.append(topo, criar('div', 'ctl-item-detalhe', a.detalhe), criar('div', 'ctl-item-sugestao', '→ ' + a.sugestao));
        return caixa;
    }

    function mostrarAlertas(dados) {
        lista.replaceChildren();
        if (!dados.alertas.length) {
            var vazio = criar('div', 'ctl-vazio', lista.getAttribute('data-vazio'));
            vazio.prepend(criar('i', 'bi bi-check2-circle'));
            lista.append(vazio);
        } else {
            dados.alertas.forEach(function (a) { lista.append(item(a)); });
        }
        total.textContent = String(dados.alertas.length);
        total.hidden = !dados.alertas.length;
        contador.textContent = dados.urgentes ? String(dados.urgentes) : '';
        contador.hidden = !dados.urgentes;
    }

    function atualizarStatus(completo) {
        status.textContent = form.getAttribute(completo ? 'data-completo' : 'data-pendente');
        status.classList.toggle('is-completo', completo);
    }

    function preencher(dados) {
        var c = dados.cadastro;
        Object.keys(CAMPOS).forEach(function (nome) {
            var campo = form.elements[nome];
            campo.value = c[CAMPOS[nome]] || '';
            campo.readOnly = !dados.podeEditar;
        });
        form.querySelector('[name="unidade"]').parentNode.querySelector('.ctl-sugestao').hidden = !c.unidadeDaCobranca;
        form.querySelector('[name="titular_extrato"]').parentNode.querySelector('.ctl-sugestao').hidden = !c.titularDaCobranca;
        salvar.hidden = !dados.podeEditar;
        atualizarStatus(c.completo);
    }

    function carregar() {
        return fetch(botao.getAttribute('data-url'), { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.json(); })
            .then(function (dados) { mostrarAlertas(dados); preencher(dados); })
            .catch(function () { lista.textContent = lista.getAttribute('data-erro'); });
    }

    function irPara(aba) {
        fundo.querySelectorAll('.js-ctl-aba').forEach(function (b) { b.classList.toggle('is-ativa', b.getAttribute('data-aba') === aba); });
        fundo.querySelectorAll('.js-ctl-sec').forEach(function (s) { s.hidden = s.getAttribute('data-sec') !== aba; });
    }

    function gravar(e) {
        e.preventDefault();
        if (enviando) { return; }
        enviando = true;
        var corpo = new URLSearchParams({ _token: botao.getAttribute('data-csrf') });
        Object.keys(CAMPOS).forEach(function (nome) { corpo.set(nome, form.elements[nome].value); });
        fetch(botao.getAttribute('data-url-salvar'), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: corpo.toString()
        })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (!res.ok) { aviso(res.j.erro || form.getAttribute('data-erro'), true); return null; }
                aviso(form.getAttribute(res.j.completo ? 'data-salvo-completo' : 'data-salvo-pendente'));
                return carregar().then(function () { irPara('alertas'); });
            }, function () { aviso(form.getAttribute('data-erro'), true); })
            .then(function () { enviando = false; });
    }

    function fechar() { fundo.hidden = true; }
    botao.addEventListener('click', function () { irPara('alertas'); fundo.hidden = false; carregar(); });
    fundo.addEventListener('click', function (e) {
        if (e.target === fundo || e.target.closest('.js-ctl-fechar')) { fechar(); return; }
        var aba = e.target.closest('.js-ctl-aba');
        if (aba) { irPara(aba.getAttribute('data-aba')); }
    });
    form.addEventListener('submit', gravar);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !fundo.hidden) { fechar(); } });
    carregar();
}());
