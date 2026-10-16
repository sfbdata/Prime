/* Janela "Cadastro e qualificação do cliente" (desenho 1.2.3, `cadAbrir`/`cadVals`/`cadSalvar`; E12f fatia 3a).
 *
 * Abre pelo botão Cadastro da pasta (`.js-cad-abrir[data-url]`), busca o fragmento `cliente/_cadastro.html.twig` e
 * controla: abas, máscaras (CPF, CNPJ, CEP, telefone), dígito verificador do CPF/CNPJ, aviso de e-mail, pendências
 * recalculadas a cada tecla (as regras vêm do servidor em data-*), qualificação ao vivo, busca de CEP no ViaCEP,
 * aviso "Este CPF já possui cadastro no sistema." ao sair do campo, Salvar (Ctrl/Cmd+S), Fechar com confirmação
 * quando há alteração (Esc também fecha) e Copiar qualificação. Salvou: ao fechar a tela recarrega, para o cabeçalho e o
 * Registro mostrarem o cadastro novo. */
(function () {
    'use strict';

    var CIVIL = { SOLTEIRO: 'solteiro(a)', CASADO: 'casado(a)', DIVORCIADO: 'divorciado(a)', SEPARADO: 'separado(a)', VIUVO: 'viúvo(a)', UNIAO_ESTAVEL: 'união estável' };

    function so(v) { return String(v || '').replace(/\D/g, ''); }
    function mascarar(tipo, v) {
        var d = so(v);
        if (tipo === 'cpf') { d = d.slice(0, 11); return d.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2'); }
        if (tipo === 'cnpj') { d = d.slice(0, 14); return d.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d{1,2})$/, '$1-$2'); }
        if (tipo === 'cep') { d = d.slice(0, 8); return d.length > 5 ? d.slice(0, 5) + '-' + d.slice(5) : d; }
        if (tipo === 'tel') {
            d = d.slice(0, 11);
            if (d.length <= 2) { return d.length ? '(' + d : ''; }
            var corpo = d.slice(2), meio = d.length === 11 ? 5 : 4;
            return '(' + d.slice(0, 2) + ') ' + (corpo.length > meio ? corpo.slice(0, meio) + '-' + corpo.slice(meio) : corpo);
        }
        return v;
    }
    function cpfValido(v) {
        var d = so(v); if (d.length !== 11 || /^(\d)\1{10}$/.test(d)) { return false; }
        for (var n = 9; n <= 10; n++) { var s = 0; for (var i = 0; i < n; i++) { s += +d[i] * (n + 1 - i); } if ((s * 10) % 11 % 10 !== +d[n]) { return false; } }
        return true;
    }
    function cnpjValido(v) {
        var d = so(v); if (d.length !== 14 || /^(\d)\1{13}$/.test(d)) { return false; }
        for (var n = 12; n <= 13; n++) { var s = 0, p = n - 7; for (var i = 0; i < n; i++) { s += +d[i] * p--; if (p < 2) { p = 9; } } var dv = s % 11 < 2 ? 0 : 11 - (s % 11); if (dv !== +d[n]) { return false; } }
        return true;
    }

    function abrir(url) {
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
            .then(function (html) {
                var caixa = document.createElement('div');
                caixa.innerHTML = html.trim();
                var veu = caixa.firstElementChild;
                document.body.appendChild(veu);
                montar(veu);
            })
            .catch(function () { window.alert('Não foi possível abrir o cadastro.'); });
    }

    function montar(veu) {
        var form = veu.querySelector('form');
        var pf = veu.dataset.tipo === 'pf';
        var docsPend = []; try { docsPend = JSON.parse(veu.dataset.pendDocs || '[]'); } catch (x) { docsPend = []; }
        var msg = veu.querySelector('.js-cad-msg');
        var salvou = false, confirmarFechar = false, tempoFechar = null, enviando = false;

        function campo(n) { return form.elements.namedItem(n); }
        function valor(n) { var c = campo(n); return c ? String(c.value || '').trim() : ''; }
        function serial() { return new URLSearchParams(new FormData(form)).toString(); }
        var original = serial();
        function sujo() { return serial() !== original; }

        function aviso(texto, tom) {
            msg.textContent = texto || '';
            msg.className = 'cad-msg js-cad-msg' + (tom ? ' cad-msg--' + tom : '');
        }

        /* Abas. */
        function irPara(k) {
            var painel = veu.querySelector('[data-cad-painel="' + k + '"]');
            if (!painel) { return; }
            veu.querySelectorAll('[data-cad-painel]').forEach(function (p) { p.hidden = p !== painel; });
            veu.querySelectorAll('[data-cad-aba]').forEach(function (b) { var on = b.dataset.cadAba === k; b.classList.toggle('is-ativa', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
            veu.querySelector('.js-cad-pend').dataset.naQual = k === 'qual' ? '1' : '';
            atualizar();
        }
        veu.querySelectorAll('[data-cad-aba]').forEach(function (b) { b.addEventListener('click', function () { irPara(b.dataset.cadAba); }); });

        /* Pendências: regras dos data-* (servidor) recalculadas no que está digitado. */
        function pendencias() {
            var L = [];
            form.querySelectorAll('[data-obrig]').forEach(function (c) { if (!String(c.value || '').trim()) { L.push({ texto: c.dataset.obrig, aba: c.dataset.aba }); } });
            if (!valor('telefone') && !valor('whatsapp') && !valor('email') && veu.dataset.temFixo !== '1') { L.push({ texto: 'Nenhum contato informado', aba: 'contato' }); }
            return L.concat(docsPend);
        }
        function atualizar() {
            var L = pendencias();
            var st = veu.querySelector('.js-cad-status');
            st.classList.toggle('is-completo', L.length === 0);
            veu.querySelector('.js-cad-status-texto').textContent = L.length === 0 ? 'Cadastro completo'
                : 'Cadastro incompleto · ' + L.length + (L.length === 1 ? ' pendência' : ' pendências');
            veu.querySelectorAll('[data-cad-aba]').forEach(function (b) {
                b.querySelector('.cad-aba-ponto').hidden = !L.some(function (p) { return p.aba === b.dataset.cadAba; });
            });
            var caixa = veu.querySelector('.js-cad-pend'), lista = veu.querySelector('.js-cad-pend-lista');
            lista.textContent = '';
            L.forEach(function (p) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'cad-pend-item';
                b.innerHTML = '<i class="bi bi-x-circle-fill" aria-hidden="true"></i>';
                b.appendChild(document.createTextNode(p.texto));
                if (veu.querySelector('[data-cad-painel="' + p.aba + '"]')) { b.addEventListener('click', function () { irPara(p.aba); }); } else { b.disabled = true; }
                lista.appendChild(b);
            });
            caixa.hidden = L.length === 0 || caixa.dataset.naQual === '1';
            veu.querySelector('.js-cad-qual').textContent = qualificacao();
            var salvar = veu.querySelector('.js-cad-salvar');
            if (salvar) { salvar.classList.toggle('is-pronto', sujo()); salvar.disabled = !sujo() || enviando; }
            if (sujo() && !msg.classList.contains('cad-msg--erro')) { aviso('Alterações não salvas', 'pendente'); } else if (!sujo() && msg.classList.contains('cad-msg--pendente')) { aviso(''); }
        }

        /* Qualificação ao vivo, com o modelo do servidor (QualificacaoDoCliente). */
        function qualificacao() {
            var P = [], up = function (s) { return s.toUpperCase(); }, low = function (s) { return s.toLowerCase(); };
            if (pf) {
                if (valor('nome')) { P.push(up(valor('nome').replace(/\s+/g, ' '))); }
                if (valor('nacionalidade')) { P.push(low(valor('nacionalidade'))); }
                if (valor('estado_civil')) { P.push(CIVIL[valor('estado_civil')] || low(valor('estado_civil'))); }
                if (valor('profissao')) { P.push(low(valor('profissao'))); }
                if (valor('nascimento')) { P.push('nascido(a) em ' + valor('nascimento').split('-').reverse().join('/')); }
                if (valor('rg')) { P.push('portador(a) do RG nº ' + up(valor('rg')) + (valor('rg_orgao') ? ' ' + up(valor('rg_orgao')) : '')); }
                if (valor('cpf')) { P.push('inscrito(a) no CPF nº ' + mascarar('cpf', valor('cpf'))); }
            } else {
                if (valor('razao_social')) { P.push(up(valor('razao_social'))); }
                if (valor('cnpj')) { P.push('inscrita no CNPJ nº ' + mascarar('cnpj', valor('cnpj'))); }
            }
            var E = [];
            if (valor('logradouro')) { E.push('na ' + up(valor('logradouro')) + (valor('numero') ? ', nº ' + up(valor('numero')) : '') + (valor('complemento') ? ', ' + up(valor('complemento')) : '')); }
            if (valor('bairro')) { E.push('Bairro ' + up(valor('bairro'))); }
            if (valor('cidade')) { E.push(up(valor('cidade')) + (valor('uf') ? '/' + up(valor('uf')) : '')); }
            if (valor('cep')) { E.push('CEP ' + valor('cep')); }
            if (E.length) { P.push((pf ? 'residente e domiciliado(a) ' : 'com sede ') + E.join(', ')); }
            var C = [], tel = valor('telefone');
            if (tel) { C.push('telefone ' + tel); }
            if (veu.dataset.fixo && veu.dataset.fixo !== tel) { C.push('telefone ' + veu.dataset.fixo); }
            if (valor('whatsapp') && valor('whatsapp') !== tel) { C.push('WhatsApp ' + valor('whatsapp')); }
            if (valor('email')) { C.push('e-mail ' + low(valor('email'))); }
            if (C.length) { P.push(C.join(', ')); }
            if (!pf && valor('representante_legal')) {
                P.push('neste ato representada por ' + up(valor('representante_legal')) + (valor('representante_cargo') ? ', ' + low(valor('representante_cargo')) : '')
                    + (valor('representante_cpf') ? ', inscrito(a) no CPF nº ' + mascarar('cpf', valor('representante_cpf')) : ''));
            }
            var t = P.length ? P.join(', ') + '.' : '';
            if (pf && valor('genero') === 'MASCULINO') { t = t.split('(a)').join(''); }
            if (pf && valor('genero') === 'FEMININO') { t = t.split('o(a)').join('a').split('(a)').join('a'); }
            return t;
        }

        /* Máscaras, avisos e recálculo a cada tecla. */
        form.addEventListener('input', function (e) {
            var c = e.target;
            if (c.dataset && c.dataset.mascara) { c.value = mascarar(c.dataset.mascara, c.value); }
            if (c.classList.contains('js-cad-cpf')) {
                var d = so(c.value), tam = pf ? 11 : 14, ok = pf ? cpfValido(d) : cnpjValido(d);
                var ruim = d.length === tam && !ok;
                c.classList.toggle('is-invalido', ruim);
                c.parentNode.querySelector('.js-cad-cpf-aviso').hidden = !ruim;
            }
            if (c.classList.contains('js-cad-email')) {
                var malfeito = c.value.trim() !== '' && !/^\S+@\S+\.\S+$/.test(c.value.trim());
                c.classList.toggle('is-invalido', malfeito);
                veu.querySelector('.js-cad-email-aviso').hidden = !malfeito;
            }
            if (msg.classList.contains('cad-msg--erro') || msg.classList.contains('cad-msg--ok')) { aviso(''); }
            atualizar();
        });
        form.addEventListener('change', atualizar);

        /* CPF/CNPJ de outro cadastro: aviso ao sair do campo. */
        var docInicial = so((form.querySelector('.js-cad-cpf') || {}).value);
        var existente = null;
        function mostrarExistente(ex) {
            existente = ex;
            var box = veu.querySelector('.js-cad-existente');
            if (!ex) { box.hidden = true; return; }
            veu.querySelector('.js-cad-existente-quem').textContent = ex.nome + ' · ' + ex.documento + ' · ' + (ex.completo ? 'cadastro completo' : 'cadastro incompleto');
            box.hidden = false;
        }
        var cpfCampo = form.querySelector('.js-cad-cpf');
        if (cpfCampo) {
            cpfCampo.addEventListener('blur', function () {
                var d = so(cpfCampo.value);
                if (d === docInicial || !(pf ? cpfValido(d) : cnpjValido(d))) { return; }
                fetch(veu.dataset.urlDocumento + '?doc=' + d, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); }).then(function (j) { mostrarExistente(j.existente); }).catch(function () {});
            });
        }
        veu.querySelector('.js-cad-existente-cancelar').addEventListener('click', function () {
            if (cpfCampo) { cpfCampo.value = mascarar(pf ? 'cpf' : 'cnpj', docInicial); }
            mostrarExistente(null); atualizar();
        });
        veu.querySelector('.js-cad-existente-abrir').addEventListener('click', function () {
            if (!existente) { return; }
            var url = veu.dataset.urlCadastro.replace('/clientes/0/', '/clientes/' + existente.id + '/') + (veu.dataset.pasta ? '?pasta=' + veu.dataset.pasta : '');
            fechar(true); abrir(url);
        });
        var usar = veu.querySelector('.js-cad-existente-usar');
        if (usar) {
            usar.addEventListener('click', function () {
                if (!existente) { return; }
                var corpo = new URLSearchParams({ _token: veu.dataset.csrfVincular, cliente_id: existente.id });
                fetch(veu.dataset.urlVincular, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: corpo })
                    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                    .then(function (res) {
                        if (!res.ok) { aviso(res.j.erro || 'Não foi possível usar esse cadastro.', 'erro'); return null; }
                        var p = new URLSearchParams({ _token: res.j.cliente.csrfTokenPrincipal });
                        return fetch(veu.dataset.urlPrincipal.replace(/\/0\/principal$/, '/' + existente.id + '/principal'), { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: p });
                    })
                    .then(function (r) {
                        if (!r) { return; }
                        if (!r.ok) { aviso('O cadastro foi vinculado, mas não virou o principal da pasta.', 'erro'); salvou = true; return; }
                        aviso('Usando o cadastro existente de ' + existente.nome.split(' ')[0] + '.', 'ok');
                        salvou = true; original = serial();
                        setTimeout(function () { fechar(true); }, 900);
                    })
                    .catch(function () { aviso('Erro de comunicação com o servidor.', 'erro'); });
            });
        }

        /* CEP: com 8 dígitos e logradouro vazio, ViaCEP preenche só os campos vazios (desenho). */
        var cep = campo('cep');
        if (cep && !cep.disabled) {
            cep.addEventListener('blur', function () {
                var d = so(cep.value);
                if (d.length !== 8 || valor('logradouro')) { return; }
                fetch('https://viacep.com.br/ws/' + d + '/json/').then(function (r) { return r.json(); }).then(function (j) {
                    if (!j || j.erro) { return; }
                    [['logradouro', j.logradouro], ['bairro', j.bairro], ['cidade', j.localidade], ['uf', j.uf]].forEach(function (par) {
                        var c = campo(par[0]); if (c && !String(c.value).trim() && par[1]) { c.value = par[1]; }
                    });
                    veu.querySelector('.js-cad-cep-nota').hidden = false;
                    atualizar();
                }).catch(function () {});
            });
        }

        /* Salvar. */
        function salvar() {
            var btn = veu.querySelector('.js-cad-salvar');
            if (!btn || enviando) { return; }
            enviando = true; btn.disabled = true;
            var corpo = new URLSearchParams(new FormData(form));
            corpo.set('_token', veu.dataset.csrf);
            if (veu.dataset.pasta) { corpo.set('pasta', veu.dataset.pasta); }
            fetch(veu.dataset.urlSalvar, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: corpo })
                .then(function (r) { return r.json().then(function (j) { return { status: r.status, j: j }; }); })
                .then(function (res) {
                    if (res.status === 200) {
                        original = serial(); salvou = salvou || res.j.mudou;
                        veu.querySelector('.js-cad-nome').textContent = (valor(pf ? 'nome' : 'razao_social') || '').toUpperCase();
                        aviso(res.j.mensagem, 'ok');
                        atualizar();
                        return;
                    }
                    if (res.status === 409) { mostrarExistente(res.j.existente); aviso(res.j.erro, 'erro'); irPara('pessoais'); return; }
                    if (res.j.aba) { irPara(res.j.aba); }
                    aviso(res.j.erro || 'Não foi possível salvar.', 'erro');
                })
                .catch(function () { aviso('Erro de comunicação com o servidor.', 'erro'); })
                .finally(function () { enviando = false; btn.disabled = !sujo(); });
        }
        var bSalvar = veu.querySelector('.js-cad-salvar');
        if (bSalvar) { bSalvar.addEventListener('click', salvar); }

        /* Copiar qualificação. */
        veu.querySelector('.js-cad-copiar').addEventListener('click', function () {
            var t = qualificacao();
            (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { aviso('Qualificação copiada.', 'ok'); })
                .catch(function () { aviso('Não foi possível copiar.', 'erro'); });
        });

        /* Fechar: com alteração não salva, o primeiro clique só avisa (4 s). */
        function fechar(forcar) {
            if (!forcar && sujo() && !confirmarFechar) {
                confirmarFechar = true;
                aviso('Há alterações não salvas. Clique em Fechar de novo para descartar.', 'pendente');
                clearTimeout(tempoFechar);
                tempoFechar = setTimeout(function () { confirmarFechar = false; }, 4000);
                return;
            }
            document.removeEventListener('keydown', teclas, true);
            veu.remove();
            if (salvou) { window.location.reload(); }
        }
        veu.querySelectorAll('.js-cad-fechar').forEach(function (b) { b.addEventListener('click', function () { fechar(false); }); });
        veu.addEventListener('mousedown', function (e) { if (e.target === veu) { fechar(false); } });
        function teclas(e) {
            if (e.key === 'Escape') { e.preventDefault(); fechar(false); }
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); salvar(); }
        }
        document.addEventListener('keydown', teclas, true);

        atualizar();
        var primeiro = form.querySelector('.cad-input:not([disabled])');
        if (primeiro) { primeiro.focus(); }
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('.js-cad-abrir[data-url]');
        if (!b) { return; }
        e.preventDefault();
        abrir(b.dataset.url);
    });
}());
