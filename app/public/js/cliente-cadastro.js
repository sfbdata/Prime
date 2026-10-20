/* Janela "Cadastro e qualificação do cliente" (desenho 1.2.3, `cadAbrir`/`cadVals`/`cadSalvar`; E12f fatia 3a).
 *
 * Abre pelo botão Cadastro da pasta (`.js-cad-abrir[data-url]`), busca o fragmento `cliente/_cadastro.html.twig` e
 * controla: abas, máscaras (CPF, CNPJ, CEP, telefone), dígito verificador do CPF/CNPJ, aviso de e-mail, pendências
 * recalculadas a cada tecla (as regras vêm do servidor em data-*), qualificação ao vivo, busca de CEP no ViaCEP,
 * aviso "Este CPF já possui cadastro no sistema." ao sair do campo, Salvar (Ctrl/Cmd+S), Fechar com confirmação
 * quando há alteração (Esc também fecha), Copiar qualificação e a aba Histórico (relida do servidor depois de
 * salvar). Salvou: ao fechar a tela recarrega, para o cabeçalho e o Registro mostrarem o cadastro novo. */
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

    /* `herdado.salvou`: a janela foi trocada depois de mexer em documento; ao fechar, a tela ainda recarrega. */
    function abrir(url, herdado) {
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
            .then(function (html) {
                var caixa = document.createElement('div');
                caixa.innerHTML = html.trim();
                var veu = caixa.firstElementChild;
                document.body.appendChild(veu);
                montar(veu, herdado || {});
                return veu;
            })
            .catch(function () { window.alert('Não foi possível abrir o cadastro.'); });
    }

    function montar(veu, herdado) {
        var form = veu.querySelector('form');
        var pf = veu.dataset.tipo === 'pf';
        var docsPend = []; try { docsPend = JSON.parse(veu.dataset.pendDocs || '[]'); } catch (x) { docsPend = []; }
        var msg = veu.querySelector('.js-cad-msg');
        var salvou = !!herdado.salvou, confirmarFechar = false, tempoFechar = null, enviando = false;

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
            semPendencias(k);
            atualizar();
            if (k === 'docs') { carregarInteligencia(); }
        }
        /* O bloco Pendências não aparece na Qualificação nem no Histórico (desenho: `temPend` some em 'hist'). */
        function semPendencias(k) { veu.querySelector('.js-cad-pend').dataset.naQual = k === 'qual' || k === 'hist' ? '1' : ''; }
        veu.querySelectorAll('[data-cad-aba]').forEach(function (b) { b.addEventListener('click', function () { irPara(b.dataset.cadAba); }); });
        semPendencias((veu.querySelector('[data-cad-aba].is-ativa') || { dataset: {} }).dataset.cadAba);

        /* Histórico (E12 3d): depois de salvar, a aba é relida do servidor (a linha nova é gravada lá). */
        function recarregarHistorico() {
            var painel = veu.querySelector('[data-cad-painel="hist"]');
            if (!painel || !veu.dataset.urlJanela) { return; }
            fetch(veu.dataset.urlJanela, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
                .then(function (html) {
                    var novo = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-cad-painel="hist"]');
                    if (novo) { painel.innerHTML = novo.innerHTML; }
                })
                .catch(function () {});
        }

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
                    if (res.status === 201 && res.j.urlJanela) {
                        // Novo cliente criado (E12i): a janela vira a do cadastro recém-criado, já salvo.
                        trocar(res.j.urlJanela, true);
                        return;
                    }
                    if (res.status === 200) {
                        original = serial(); salvou = salvou || res.j.mudou;
                        if (res.j.mudou) { recarregarHistorico(); }
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

        /* Aba Documentos: anexar, substituir e excluir pelas rotas de documento de cliente; depois a janela é
           trocada pela mesma, já na aba Documentos (a pendência e o cartão se refazem no servidor). */
        var arquivo = veu.querySelector('.js-cad-doc-arquivo');
        var alvo = null;
        function reabrir(extra) {
            document.removeEventListener('keydown', teclas, true);
            veu.remove();
            return abrir(veu.dataset.urlJanela + (veu.dataset.urlJanela.indexOf('?') >= 0 ? '&' : '?') + 'aba=docs' + (extra || ''), { salvou: true });
        }
        function postar(url, corpo) {
            return fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: corpo }).then(function (r) {
                if (!r.ok) { throw new Error(r.status); }
                return r;
            });
        }
        veu.querySelectorAll('.js-cad-doc-anexar').forEach(function (b) {
            b.addEventListener('click', function () {
                if (sujo()) { aviso('Salve as alterações antes de mexer nos documentos.', 'pendente'); return; }
                var card = b.closest('[data-cad-doc]');
                alvo = { cat: card.dataset.cadDoc, antigo: b.dataset.substituir ? card : null };
                arquivo.value = '';
                arquivo.click();
            });
        });
        if (arquivo) {
            arquivo.addEventListener('change', function () {
                if (!arquivo.files.length || !alvo) { return; }
                var fd = new FormData();
                fd.append('_token', veu.dataset.csrfUpload);
                fd.append('arquivos[]', arquivo.files[0]);
                fd.append('categorias[]', alvo.cat);
                aviso('Enviando o arquivo...', 'pendente');
                var antigo = alvo.antigo, cat = alvo.cat, idAntes = antigo ? antigo.dataset.docId : null;
                // A rota de envio responde redirecionando até quando recusa o arquivo (tipo ou tamanho): só a
                // janela relida diz se o anexo entrou. Sem anexo novo, o antigo NÃO é excluído.
                postar(veu.dataset.urlUpload, fd)
                    .then(function () { return fetch(veu.dataset.urlJanela, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }); })
                    .then(function (r) { return r.text(); })
                    .then(function (html) {
                        var card = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-cad-doc="' + cat + '"]');
                        var idDepois = card ? card.dataset.docId : null;
                        if (!idDepois || idDepois === idAntes) { throw new Error('recusado'); }
                        if (!antigo) { return null; }
                        var ex = antigo.querySelector('.js-cad-doc-excluir');
                        return postar(ex.dataset.url, new URLSearchParams({ _token: ex.dataset.csrf }));
                    })
                    .then(function () { return reabrir(cat === 'IDENTIFICACAO' ? '&ocr=1' : ''); })
                    .catch(function (e) {
                        aviso(e && e.message === 'recusado' ? 'O arquivo não foi aceito: envie PDF ou imagem dentro do limite de tamanho.' : 'Não foi possível anexar o arquivo.', 'erro');
                    });
            });
        }
        veu.querySelectorAll('.js-cad-doc-excluir').forEach(function (b) {
            b.addEventListener('click', function () { b.closest('[data-cad-doc]').querySelector('.js-cad-doc-confirma').hidden = false; });
        });
        veu.querySelectorAll('.js-cad-doc-excluir-nao').forEach(function (b) {
            b.addEventListener('click', function () { b.closest('.js-cad-doc-confirma').hidden = true; });
        });
        veu.querySelectorAll('.js-cad-doc-excluir-sim').forEach(function (b) {
            b.addEventListener('click', function () {
                var ex = b.closest('[data-cad-doc]').querySelector('.js-cad-doc-excluir');
                postar(ex.dataset.url, new URLSearchParams({ _token: ex.dataset.csrf }))
                    .then(function () { return reabrir(''); })
                    .catch(function () { aviso('Não foi possível excluir o documento.', 'erro'); });
            });
        });

        /* Inteligência cadastral (desenho 02, l.740 a 791; F-A): carregada ao abrir a aba Documentos, uma vez por janela.
           Cabeçalho, alertas, "Encontrados no menu Documentos" com "Vincular ao cadastro" e a nota. Os textos vêm do
           servidor (RotulosDaInteligenciaCadastral, `rotulos` do JSON); aqui não há texto próprio, só desenho por textContent. */
        var caixaIi = veu.querySelector('.js-cad-ii');
        var iiCarregada = false;
        var iiRotulos = {};
        function elemento(tag, cls, txt) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (txt !== undefined) { e.textContent = txt; } return e; }
        function icone(cls) { var i = elemento('i', cls); i.setAttribute('aria-hidden', 'true'); return i; }
        function carregarInteligencia() {
            if (!caixaIi || iiCarregada) { return; }
            iiCarregada = true;
            fetch(caixaIi.dataset.url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
                .then(desenharInteligencia)
                .catch(function () { iiCarregada = false; });
        }
        function cabecalhoIi(ii) {
            var topo = elemento('div', 'cad-ii-topo');
            var ic = elemento('span', 'cad-ii-icone'); ic.appendChild(icone(iiRotulos.icone));
            var txt = elemento('div', 'cad-ii-texto');
            txt.appendChild(elemento('div', 'cad-ii-titulo', ii.titulo));
            txt.appendChild(elemento('div', 'cad-ii-sub', ii.subtitulo));
            topo.appendChild(ic); topo.appendChild(txt);
            return topo;
        }
        function alertaIi(a) {
            var al = elemento('div', 'cad-ii-alerta'); al.dataset.tipo = a.tipo;
            var corpo = elemento('span');
            corpo.appendChild(elemento('b', null, a.negrito));
            corpo.appendChild(document.createTextNode(' ' + a.texto));
            al.appendChild(icone(a.ic)); al.appendChild(corpo);
            return al;
        }
        function encontradoIi(e) {
            var linha = elemento('div', 'cad-ii-doc'); linha.dataset.documentoId = e.id;
            var nome = elemento('span', 'cad-ii-doc-nome');
            nome.appendChild(elemento('b', null, e.arquivo));
            nome.appendChild(document.createTextNode(' · ' + e.categoriaRotulo + ' · ' + e.pasta + ' · ' + e.data));
            linha.appendChild(icone('bi bi-file-earmark-check')); linha.appendChild(nome);
            if (e.podeVincular) {
                var b = elemento('button', 'cad-ii-vincular js-cad-ii-vincular'); b.type = 'button';
                b.appendChild(icone('bi bi-link-45deg')); b.appendChild(document.createTextNode(iiRotulos.vincular));
                b.addEventListener('click', function () { vincularIi(e.id, b); });
                linha.appendChild(b);
            }
            return linha;
        }
        function desenharInteligencia(ii) {
            caixaIi.textContent = '';
            if (!ii || !ii.on) { caixaIi.hidden = true; return; }
            iiRotulos = ii.rotulos || {};
            caixaIi.appendChild(cabecalhoIi(ii));
            (ii.alertas || []).forEach(function (a) { caixaIi.appendChild(alertaIi(a)); });
            if ((ii.encontrados || []).length) {
                var lista = elemento('div', 'cad-ii-encontrados');
                lista.appendChild(elemento('div', 'cad-ii-rotulo', iiRotulos.encontrados));
                ii.encontrados.forEach(function (e) { lista.appendChild(encontradoIi(e)); });
                caixaIi.appendChild(lista);
            }
            caixaIi.appendChild(elemento('div', 'cad-ii-nota', ii.nota));
            caixaIi.hidden = false;
        }
        function vincularIi(documentoId, botao) {
            if (sujo()) { aviso(iiRotulos.salvarAntes, 'pendente'); return; }
            botao.disabled = true;
            fetch(caixaIi.dataset.urlVincular.replace(/\/0$/, '/' + documentoId), { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': veu.dataset.csrf } })
                .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
                .then(function () { return reabrir(''); })
                .catch(function () { botao.disabled = false; aviso(iiRotulos.vincularErro, 'erro'); });
        }
        if (veu.querySelector('[data-cad-aba="docs"].is-ativa')) { carregarInteligencia(); }

        /* Aba Réu: lista da pasta, grava na hora (rotas pasta_reu_*); a resposta traz a lista inteira. */
        var painelReu = veu.querySelector('[data-cad-painel="reu"]');
        if (painelReu) {
            var ulReus = painelReu.querySelector('.js-cad-reus');
            var podeReu = painelReu.dataset.podeReu === '1';
            var avisoReu = painelReu.querySelector('.js-cad-reu-aviso');
            var iniciais = function (n) { return n.split(/\s+/).filter(Boolean).slice(0, 2).map(function (p) { return p[0]; }).join(''); };
            var el = function (tag, cls, txt) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (txt !== undefined) { e.textContent = txt; } return e; };
            var desenharReus = function (lista) {
                ulReus.textContent = '';
                if (!lista.length) { ulReus.appendChild(el('li', 'cad-reus-vazio', 'Nenhum réu cadastrado nesta pasta.')); return; }
                lista.forEach(function (r, i) {
                    var li = el('li', 'cad-reu');
                    li.appendChild(el('span', 'cad-reu-ini', iniciais(r.nome)));
                    var corpo = el('div', 'cad-reu-corpo');
                    var nome = el('div', 'cad-reu-nome', r.nome);
                    if (i === 0) { nome.appendChild(el('span', 'cad-reu-principal', 'Principal')); }
                    corpo.appendChild(nome);
                    corpo.appendChild(el('div', 'cad-reu-doc', r.documento));
                    li.appendChild(corpo);
                    if (podeReu) {
                        var acoes = el('div', 'cad-reu-acoes');
                        if (i > 0) {
                            var up = el('button', 'cad-reu-btn'); up.type = 'button'; up.title = 'Tornar principal / subir'; up.setAttribute('aria-label', 'Tornar principal / subir');
                            up.innerHTML = '<i class="bi bi-arrow-up" aria-hidden="true"></i>';
                            up.addEventListener('click', function () { acaoReu(painelReu.dataset.urlReu + '/' + r.id + '/subir', {}); });
                            acoes.appendChild(up);
                        }
                        var lixo = el('button', 'cad-reu-btn'); lixo.type = 'button'; lixo.title = 'Remover réu'; lixo.setAttribute('aria-label', 'Remover réu');
                        lixo.innerHTML = '<i class="bi bi-trash" aria-hidden="true"></i>';
                        acoes.appendChild(lixo);
                        li.appendChild(acoes);
                        var conf = el('div', 'cad-reu-confirma'); conf.hidden = true;
                        conf.appendChild(el('span', '', 'Remover ' + r.nome + ' dos réus desta pasta?'));
                        var sim = el('button', 'cad-btn cad-btn--perigo', 'Remover'); sim.type = 'button';
                        var nao = el('button', 'cad-btn cad-btn--link', 'Cancelar'); nao.type = 'button';
                        sim.addEventListener('click', function () { acaoReu(painelReu.dataset.urlReu + '/' + r.id + '/remover', {}); });
                        nao.addEventListener('click', function () { conf.hidden = true; });
                        lixo.addEventListener('click', function () { conf.hidden = false; });
                        conf.appendChild(sim); conf.appendChild(nao);
                        li.appendChild(conf);
                    }
                    ulReus.appendChild(li);
                });
            };
            var acaoReu = function (url, dados) {
                var corpo = new URLSearchParams(dados); corpo.set('_token', painelReu.dataset.csrfReu);
                return fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: corpo })
                    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                    .then(function (res) {
                        if (!res.ok) { if (avisoReu) { avisoReu.textContent = res.j.erro || res.j.mensagem || 'Não foi possível alterar os réus.'; avisoReu.hidden = false; } return false; }
                        if (avisoReu) { avisoReu.hidden = true; }
                        desenharReus(res.j.reus);
                        return true;
                    })
                    .catch(function () { if (avisoReu) { avisoReu.textContent = 'Erro de comunicação com o servidor.'; avisoReu.hidden = false; } return false; });
            };
            var inNome = painelReu.querySelector('.js-cad-reu-nome'), inDoc = painelReu.querySelector('.js-cad-reu-doc');
            var bAddReu = painelReu.querySelector('.js-cad-reu-add'), adicionando = false;
            var adicionarReu = function () {
                if (adicionando) { return; }
                adicionando = true; bAddReu.disabled = true;
                acaoReu(painelReu.dataset.urlReu, { nome: inNome.value, documento: inDoc.value }).then(function (ok) {
                    adicionando = false; bAddReu.disabled = false;
                    if (ok) { inNome.value = ''; inDoc.value = ''; inNome.focus(); }
                });
            };
            if (inNome) {
                bAddReu.addEventListener('click', adicionarReu);
                inNome.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); adicionarReu(); } });
                inDoc.addEventListener('input', function () { inDoc.value = mascarar(so(inDoc.value).length > 11 ? 'cnpj' : 'cpf', inDoc.value); });
            }
            var listaInicial = []; try { listaInicial = JSON.parse(painelReu.dataset.reus || '[]'); } catch (x) { listaInicial = []; }
            desenharReus(listaInicial);
        }

        /* Trocar a janela por outra (outro autor, tipo do novo cadastro, cadastro recém-criado) sem perder o "salvou". */
        function trocar(url, criou) {
            document.removeEventListener('keydown', teclas, true);
            veu.remove();
            abrir(url, { salvou: salvou || !!criou });
        }
        veu.querySelectorAll('.js-cad-autor, .js-cad-tipo').forEach(function (b) {
            b.addEventListener('click', function () {
                if (b.classList.contains('is-ativo')) { return; }
                if (sujo()) { aviso('Salve as alterações antes de trocar.', 'pendente'); return; }
                trocar(b.dataset.url);
            });
        });

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
