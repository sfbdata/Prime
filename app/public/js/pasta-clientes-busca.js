/* =============================================================================
   pasta-clientes-busca.js — busca inline do cartão Clientes (trilho da aba Dados)
   Desenho aprovado: docs/design/claude-design-2026-10-05 (1)/
   ("02 - EXPEDIENTES 1.2.3.dc.html", cartão L.1357-1372, regra L.3304).

   1. O "+" do cabeçalho abre/fecha o campo "Nome ou CPF do cliente" no próprio
      cartão (Esc fecha; Enter escolhe o primeiro resultado).
   2. Cada resultado: nome, documento (MASCARADO pelo servidor — só vem inteiro
      quando o termo é o documento inteiro) e o selo Completo / Incompleto /
      Já vinculado. Clicar vincula pelo `pasta_cliente_vincular` (mesmo token do
      modal); "Já vinculado" só fecha.
   3. Nada encontrado: "Cliente ainda não cadastrado." + "Cadastrar cliente", que
      abre o modal #modalAdicionarCliente (Bootstrap, data-bs-toggle) já com o
      CPF/CNPJ ou o nome digitado nos campos do cadastro.

   Depois de vincular a página recarrega: a linha do cliente, a estrela do
   principal e a Média por CPF são montadas pelo servidor, e o JS que sabe
   montá-las sem recarregar mora fechado dentro do show.html.twig.
   ============================================================================= */
(function () {
    'use strict';

    var painel = document.getElementById('psVincCliente');
    if (!painel) { return; }

    var botaoAbrir = document.querySelector('.js-ps-vinc-abrir[aria-controls="psVincCliente"]');
    var input      = painel.querySelector('.ps-vinc-input');
    var lista      = painel.querySelector('.ps-vinc-resultados');
    var vazio      = painel.querySelector('.ps-vinc-vazio');
    var erro       = painel.querySelector('.ps-vinc-erro');
    var cadastrar  = painel.querySelector('.js-ps-vinc-cadastrar');

    var urlBusca    = painel.getAttribute('data-busca-url') || '';
    var urlVincular = painel.getAttribute('data-vincular-url') || '';
    var token       = painel.getAttribute('data-vincular-token') || '';

    var timer      = null;
    var sequencia  = 0;
    var resultados = [];
    var vinculando = false;

    var SELO = {
        completo:     { texto: 'Completo',     classe: 'ps-vinc-selo--completo' },
        incompleto:   { texto: 'Incompleto',   classe: 'ps-vinc-selo--incompleto' },
        ja_vinculado: { texto: 'Já vinculado', classe: 'ps-vinc-selo--vinculado' }
    };

    function soDigitos(v) { return String(v || '').replace(/\D/g, ''); }

    function mostrarErro(texto) {
        erro.textContent = texto;
        erro.hidden = texto === '';
    }

    function limpar() {
        lista.innerHTML = '';
        resultados = [];
        vazio.hidden = true;
        mostrarErro('');
    }

    function abrir() {
        painel.hidden = false;
        if (botaoAbrir) { botaoAbrir.setAttribute('aria-expanded', 'true'); }
        input.focus();
    }

    function fechar() {
        painel.hidden = true;
        if (botaoAbrir) { botaoAbrir.setAttribute('aria-expanded', 'false'); }
        clearTimeout(timer);
        sequencia++;
        input.value = '';
        limpar();
    }

    function montarItem(c) {
        var selo = SELO[c.selo] || SELO.incompleto;

        var item = document.createElement('button');
        item.type = 'button';
        item.className = 'ps-vinc-item';
        item.setAttribute('role', 'option');
        item.setAttribute('data-cliente-id', String(c.id));
        item.setAttribute('data-selo', c.selo || '');

        var corpo = document.createElement('span');
        corpo.className = 'ps-vinc-item-corpo';

        var nome = document.createElement('span');
        nome.className = 'ps-vinc-item-nome';
        nome.textContent = c.nome || '';
        corpo.appendChild(nome);

        if (c.documento) {
            var doc = document.createElement('span');
            doc.className = 'ps-vinc-item-doc';
            doc.textContent = (c.documentoRotulo || 'Documento') + ' ' + c.documento;
            corpo.appendChild(doc);
        }

        var tag = document.createElement('span');
        tag.className = 'ps-vinc-selo ' + selo.classe;
        tag.textContent = selo.texto;

        item.appendChild(corpo);
        item.appendChild(tag);
        item.addEventListener('click', function () { escolher(c); });

        return item;
    }

    function buscar(termo) {
        var minha = ++sequencia;
        var url = urlBusca + (urlBusca.indexOf('?') === -1 ? '?' : '&')
            + 'q=' + encodeURIComponent(termo) + '&incluirVinculados=1';

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (resp) {
                if (!resp.ok) { throw new Error('busca'); }

                return resp.json();
            })
            .then(function (dados) {
                if (minha !== sequencia) { return; } // resposta atrasada de um termo antigo
                limpar();
                resultados = Array.isArray(dados) ? dados.slice(0, 6) : [];
                resultados.forEach(function (c) { lista.appendChild(montarItem(c)); });
                vazio.hidden = resultados.length > 0;
            })
            .catch(function () {
                if (minha !== sequencia) { return; }
                limpar();
                mostrarErro('Não foi possível buscar agora. Tente de novo.');
            });
    }

    function escolher(c) {
        if (c.jaVinculado || c.selo === 'ja_vinculado') { fechar(); return; }
        if (vinculando) { return; }
        vinculando = true;
        mostrarErro('');

        var corpo = new FormData();
        corpo.append('_token', token);
        corpo.append('cliente_id', String(c.id));

        fetch(urlVincular, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: corpo
        })
            .then(function (resp) {
                return resp.json().catch(function () { return {}; }).then(function (dados) {
                    return { ok: resp.ok, dados: dados };
                });
            })
            .then(function (r) {
                if (!r.ok || !r.dados.sucesso) {
                    vinculando = false;
                    mostrarErro(r.dados.erro || 'Não foi possível vincular o cliente.');
                    return;
                }
                window.location.reload();
            })
            .catch(function () {
                vinculando = false;
                mostrarErro('Não foi possível vincular o cliente.');
            });
    }

    /* "Cadastrar cliente": leva o que foi digitado para o cadastro do modal —
       documento (6+ dígitos, como no desenho) ou nome. O modal abre pelo
       data-bs-toggle do próprio botão; aqui só se preenche. */
    function preencherCadastro() {
        var modal = document.getElementById('modalAdicionarCliente');
        var termo = input.value.trim();
        if (modal && termo !== '') {
            var d = soDigitos(termo);
            var campos = d.length >= 6
                ? (d.length === 14 ? { cnpj: d } : { cpf: d })
                : { nomeCompleto: termo, razaoSocial: termo };
            Object.keys(campos).forEach(function (nome) {
                var campo = modal.querySelector('[name="' + nome + '"]');
                if (campo) { campo.value = campos[nome]; }
            });
        }
        fechar();
    }

    if (botaoAbrir) {
        botaoAbrir.addEventListener('click', function () {
            if (painel.hidden) { abrir(); } else { fechar(); }
        });
    }

    if (cadastrar) { cadastrar.addEventListener('click', preencherCadastro); }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        var termo = input.value.trim();
        if (termo.length < 2) {
            sequencia++;
            limpar();
            return;
        }
        timer = setTimeout(function () { buscar(termo); }, 250);
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            fechar();
            if (botaoAbrir) { botaoAbrir.focus(); }
            return;
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            if (resultados[0]) { escolher(resultados[0]); }
        }
    });
})();
