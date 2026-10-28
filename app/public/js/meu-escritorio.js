/* BlueJus · Meu Escritório (desenho 13 VS 28.10.26, docs/specs/meu-escritorio.md).
   Alterna a edição, copia, avisa (toast do desenho), busca CEP (viacep) e CNPJ (brasilapi) no navegador, grava por
   fetch JSON com X-CSRF-Token, envia a logo (multipart) e confere a exclusão. Quem pode editar/excluir é decidido
   no servidor; aqui só há o que o servidor liberou na marcação. Dado entra por textContent/value. */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-me-pagina]');
    if (!raiz) { return; }

    var PREFIXO_T = 'tenant.meu_escritorio.js.';
    var T = function (chave, params) { return (window.BJ_T ? window.BJ_T.t(PREFIXO_T + chave, params) : chave); };
    var q = function (sel) { return raiz.querySelector(sel); };
    var qa = function (sel) { return Array.prototype.slice.call(raiz.querySelectorAll(sel)); };
    var csrf = raiz.dataset.csrf || '';
    var toastEl = document.querySelector('[data-me-toast]');
    var toastTimer = null;

    function so(v) { return String(v || '').replace(/\D/g, ''); }

    /* ---------- Aviso (toast do desenho: fixo embaixo, some em 3,2 s) ---------- */
    function avisar(texto) {
        if (!toastEl) { return; }
        clearTimeout(toastTimer);
        toastEl.textContent = texto;
        toastEl.hidden = false;
        toastTimer = setTimeout(function () { toastEl.hidden = true; }, 3200);
    }

    /* ---------- Requisição ---------- */
    function enviar(url, opcoes) {
        opcoes = opcoes || {};
        var cab = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-Token': csrf };
        if (opcoes.json) { cab['Content-Type'] = 'application/json'; }
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: cab,
            body: opcoes.json ? JSON.stringify(opcoes.json) : (opcoes.corpo || null)
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) { return { http: r.ok, dados: j || {} }; });
        });
    }

    /* ---------- Linhas da vista ---------- */
    function linha(chave) { return q('[data-me-linha="' + chave + '"]'); }
    function defineValor(chave, texto) {
        var l = linha(chave);
        if (!l) { return; }
        var v = l.querySelector('[data-me-valor]');
        var vazio = !texto;
        v.textContent = vazio ? T('nao_informado') : texto;
        v.classList.toggle('me-valor--vazio', vazio);
        var c = l.querySelector('[data-me-copiar]');
        if (c) { c.hidden = vazio; }
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function dataHora(valor) {
        if (!valor) { return ''; }
        if (typeof valor === 'object' && valor.date) { valor = valor.date; }
        if (/^\d{2}\/\d{2}\/\d{4}/.test(String(valor))) { return String(valor); } /* o servidor já manda dd/mm/aaaa hh:mm */
        var d = new Date(String(valor).replace(' ', 'T'));
        if (isNaN(d.getTime())) { return String(valor); }
        return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    function atualizaRodape(e) {
        var el = q('[data-me-alterado]');
        if (!el || !e) { return; }
        el.textContent = e.alteradoEm
            ? T('alterado', { nome: e.alteradoPor || '', data: dataHora(e.alteradoEm) })
            : T('sem_alteracoes');
    }
    function aplicaEscritorio(e) {
        if (!e) { return; }
        defineValor('nome', e.nome);
        defineValor('doc', e.docFormatado || e.doc);
        defineValor('oab', e.oab);
        defineValor('email', e.email);
        defineValor('telefone', e.telefone);
        defineValor('endereco', [e.endereco, e.cidadeUf, e.cep ? 'CEP ' + e.cep : ''].filter(Boolean).join(' · '));
        var mapa = { nome: e.nome, doc: e.doc, oab: e.oab, email: e.email, telefone: e.telefone, cep: e.cep, endereco: e.endereco, cidadeUf: e.cidadeUf };
        qa('[data-me-campo]').forEach(function (i) {
            var v = mapa[i.dataset.meCampo];
            i.value = v == null ? '' : v;
            i.dataset.original = i.value;
        });
        raiz.dataset.nome = e.nome || '';
        var nomeExcl = q('[data-me-exclusao-nome]');
        if (nomeExcl) { nomeExcl.textContent = e.nome || ''; }
        atualizaRodape(e);
        confereExclusao();
    }

    /* ---------- Copiar ---------- */
    function copiar(texto) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(texto).catch(function () {});
        }
        var t = document.createElement('textarea');
        t.value = texto; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
        document.body.appendChild(t); t.select();
        try { document.execCommand('copy'); } catch (x) { /* sem permissão: segue sem copiar */ }
        document.body.removeChild(t);
        return Promise.resolve();
    }
    qa('[data-me-copiar]').forEach(function (b) {
        b.addEventListener('click', function (ev) {
            ev.stopPropagation();
            var v = b.parentNode.querySelector('[data-me-valor]');
            copiar(v ? v.textContent : '');
            avisar(T('copiado', { rotulo: b.dataset.meCopiar }));
        });
    });

    /* ---------- Edição ---------- */
    var vista = q('[data-me-vista]');
    var edicao = q('[data-me-edicao]');
    var acoesVista = q('[data-me-acoes-vista]');
    var acoesEdicao = q('[data-me-acoes-edicao]');
    var erroEl = q('[data-me-erro]');
    var salvando = false;

    function campo(chave) { return q('[data-me-campo="' + chave + '"]'); }
    function erro(texto) {
        if (!erroEl) { return; }
        erroEl.textContent = texto || '';
        erroEl.hidden = !texto;
    }
    function modoEdicao(on) {
        if (!edicao) { return; }
        vista.hidden = on;
        edicao.hidden = !on;
        acoesVista.hidden = on;
        acoesEdicao.hidden = !on;
        erro('');
        if (on) { var n = campo('nome'); if (n) { n.focus(); } }
    }
    function restaura() {
        qa('[data-me-campo]').forEach(function (i) { i.value = i.dataset.original || ''; });
    }

    /* CNPJ com dígito verificador (igual ao desenho). */
    function cnpjOk(n) {
        if (!/^\d{14}$/.test(n) || /^(\d)\1+$/.test(n)) { return false; }
        function dv(b) {
            var s = 0, p = b - 7;
            for (var i = 0; i < b; i++) { s += +n[i] * p--; if (p < 2) { p = 9; } }
            var r = s % 11;
            return r < 2 ? 0 : 11 - r;
        }
        return dv(12) === +n[12] && dv(13) === +n[13];
    }

    function buscaCep(digitos) {
        fetch('https://viacep.com.br/ws/' + digitos + '/json/').then(function (r) { return r.json(); }).then(function (j) {
            if (!j || j.erro) { erro(T('erro_cep')); return; }
            var end = campo('endereco'), cid = campo('cidadeUf');
            if (end) { end.value = [j.logradouro, j.bairro].filter(Boolean).join(', '); }
            if (cid) { cid.value = (j.localidade || '') + '/' + (j.uf || ''); }
            erro('');
            avisar(T('cep_preenchido'));
        }).catch(function () { /* sem rede: o usuário preenche à mão */ });
    }
    function buscaCnpj(digitos) {
        fetch('https://brasilapi.com.br/api/cnpj/v1/' + digitos).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
            if (!j) { return; }
            var nome = campo('nome'), cep = campo('cep'), end = campo('endereco'), cid = campo('cidadeUf');
            if (nome && (!nome.value || nome.value === nome.dataset.original) && j.razao_social) {
                nome.value = String(j.razao_social).toLowerCase().replace(/(^|\s)\S/g, function (m) { return m.toUpperCase(); });
            }
            if (cep && !cep.value && j.cep) { cep.value = String(j.cep); }
            if (end && !end.value && j.logradouro) {
                end.value = [j.descricao_tipo_de_logradouro, j.logradouro, j.numero, j.complemento].filter(Boolean).join(' ');
            }
            if (cid && !cid.value && j.municipio) { cid.value = j.municipio + '/' + j.uf; }
            erro('');
            avisar(T('receita_preenchida'));
        }).catch(function () { /* sem rede: segue sem preencher */ });
    }

    qa('[data-me-campo]').forEach(function (i) {
        i.addEventListener('input', function () {
            erro('');
            var n = so(i.value);
            if (i.dataset.meCampo === 'cep' && n.length === 8) { buscaCep(n); }
            if (i.dataset.meCampo === 'doc' && n.length === 14) {
                if (!cnpjOk(n)) { erro(T('erro_cnpj')); return; }
                buscaCnpj(n);
            }
        });
    });

    function valida() {
        var nome = campo('nome'), doc = so(campo('doc').value), email = campo('email').value.trim();
        if (!nome.value.trim()) { return T('erro_nome'); }
        if (doc && doc.length !== 11 && doc.length !== 14) { return T('erro_doc_tamanho'); }
        if (doc.length === 14 && !cnpjOk(doc)) { return T('erro_cnpj'); }
        if (email && !/^\S+@\S+\.\S+$/.test(email)) { return T('erro_email'); }
        return '';
    }
    function salvar() {
        if (salvando) { return; }
        var msg = valida();
        if (msg) { erro(msg); return; }
        var corpo = {};
        qa('[data-me-campo]').forEach(function (i) { corpo[i.dataset.meCampo] = i.value.trim(); });
        var btn = q('[data-me-salvar]');
        salvando = true; btn.disabled = true;
        enviar(raiz.dataset.urlSalvar, { json: corpo }).then(function (r) {
            salvando = false; btn.disabled = false;
            if (!r.http || r.dados.ok !== true) { erro(r.dados.erro || T('erro_salvar')); return; }
            aplicaEscritorio(r.dados.escritorio);
            modoEdicao(false);
            avisar(T('salvo'));
        }, function () {
            salvando = false; btn.disabled = false;
            erro(T('erro_comunicacao'));
        });
    }

    var bEditar = q('[data-me-editar]');
    if (bEditar) { bEditar.addEventListener('click', function () { restaura(); modoEdicao(true); }); }
    var bCancelar = q('[data-me-cancelar]');
    if (bCancelar) { bCancelar.addEventListener('click', function () { restaura(); modoEdicao(false); }); }
    var bSalvar = q('[data-me-salvar]');
    if (bSalvar) { bSalvar.addEventListener('click', salvar); }

    /* ---------- Logo ---------- */
    var quadro = q('[data-me-logo-quadro]');
    var arquivo = q('[data-me-logo-arquivo]');
    var lixeira = q('[data-me-logo-remover]');
    var rotuloLogo = q('[data-me-logo-rotulo]');

    function mostraLogo(on) {
        var img = quadro.querySelector('img');
        var icone = quadro.querySelector('[data-me-logo-icone]');
        if (on) {
            if (!img) { img = document.createElement('img'); img.alt = ''; quadro.appendChild(img); }
            img.src = raiz.dataset.urlLogo + '?v=' + Date.now();
            if (icone) { icone.remove(); }
        } else {
            if (img) { img.remove(); }
            if (!icone) {
                icone = document.createElement('i');
                icone.className = 'bi bi-image';
                icone.setAttribute('data-me-logo-icone', '');
                quadro.appendChild(icone);
            }
        }
        if (lixeira) { lixeira.hidden = !on; }
        if (rotuloLogo) { rotuloLogo.textContent = T(on ? 'logo_trocar' : 'logo_enviar'); }
    }
    if (arquivo) {
        arquivo.addEventListener('change', function () {
            var f = arquivo.files && arquivo.files[0];
            if (!f) { return; }
            var corpo = new FormData();
            corpo.append('logo', f);
            corpo.append('_token', csrf);
            enviar(raiz.dataset.urlLogoEnviar, { corpo: corpo }).then(function (r) {
                if (!r.http || r.dados.ok !== true) { avisar(r.dados.erro || T('erro_logo')); return; }
                mostraLogo(true);
                atualizaRodape(r.dados.escritorio);
                avisar(T('logo_atualizada'));
            }, function () { avisar(T('erro_comunicacao')); });
            arquivo.value = '';
        });
    }
    if (lixeira) {
        lixeira.addEventListener('click', function () {
            if (!window.confirm(T('logo_remover_confirmar'))) { return; }
            enviar(raiz.dataset.urlLogoRemover, {}).then(function (r) {
                if (!r.http || r.dados.ok !== true) { avisar(r.dados.erro || T('erro_logo')); return; }
                mostraLogo(false);
                atualizaRodape(r.dados.escritorio);
                avisar(T('logo_removida'));
            }, function () { avisar(T('erro_comunicacao')); });
        });
    }

    /* ---------- Excluir conta ---------- */
    var exFechado = q('[data-me-exclusao-fechado]');
    var exAberto = q('[data-me-exclusao-aberto]');
    var exSenha = q('[data-me-exclusao-senha]');
    var exConf = q('[data-me-exclusao-conf]');
    var exBotao = q('[data-me-exclusao-excluir]');

    function confereExclusao() {
        if (!exBotao) { return; }
        exBotao.disabled = !(exSenha.value.length && exConf.value.trim() === (raiz.dataset.nome || ''));
    }
    if (exBotao) {
        qa('[data-me-exclusao-alternar]').forEach(function (b) {
            b.addEventListener('click', function () {
                var abrir = exAberto.hidden;
                exAberto.hidden = !abrir;
                exFechado.hidden = abrir;
                exConf.value = '';
                confereExclusao();
            });
        });
        exSenha.addEventListener('input', confereExclusao);
        exConf.addEventListener('input', confereExclusao);
        exBotao.addEventListener('click', function () {
            var nome = raiz.dataset.nome || '';
            if (exConf.value.trim() !== nome) { return; }
            if (!exSenha.value.length) { avisar(T('erro_senha')); return; }
            if (!window.confirm(T('exclusao_confirmar', { nome: nome }))) { return; }
            exBotao.disabled = true;
            enviar(raiz.dataset.urlExclusaoConferir, { json: { senha: exSenha.value, nome: exConf.value.trim() } }).then(function (r) {
                exSenha.value = '';
                if (!r.http || r.dados.ok !== true) {
                    confereExclusao();
                    avisar(r.dados.erro || T('erro_exclusao'));
                    return;
                }
                window.location.assign(raiz.dataset.urlExclusaoFluxo);
            }, function () {
                confereExclusao();
                avisar(T('erro_comunicacao'));
            });
        });
    }
})();
