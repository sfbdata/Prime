/* Service Desk (desenho 12): filtro e busca da lista, extrato, modais e ações. */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-service-desk]');
    if (!raiz) { return; }

    var $ = function (seletor, base) { return (base || raiz).querySelector(seletor); };
    var $$ = function (seletor, base) { return Array.prototype.slice.call((base || raiz).querySelectorAll(seletor)); };

    var fundo = $('[data-sd-fundo]');
    var area = $('[data-sd-area]');
    var toast = $('[data-sd-toast]');
    var botaoEnviar = $('[data-sd-enviar]');
    var botaoPaguei = $('[data-sd-paguei]');
    var botaoCancelar = $('.sd-rod-sec');
    var temporizadorDoToast = null;
    var tipo = 'suporte';
    var painelAtual = null;
    var pixAtual = null;
    var pedidoDoPix = 0;
    var valorEscolhido = 20;
    var filtro = '';

    function avisar(texto) {
        clearTimeout(temporizadorDoToast);
        toast.textContent = texto;
        toast.hidden = false;
        temporizadorDoToast = setTimeout(function () { toast.hidden = true; }, 3200);
    }

    function enviar(url, dados) {
        var corpo = new FormData();
        corpo.append('_token', raiz.dataset.csrf);
        Object.keys(dados).forEach(function (chave) { corpo.append(chave, dados[chave]); });
        return fetch(url, { method: 'POST', body: corpo, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .catch(function () { return { ok: false, erro: 'Não foi possível concluir agora. Tente novamente.' }; });
    }

    // Lista: filtro por status + busca, sem recarregar.
    function aplicarLista() {
        var termo = $('[data-sd-busca]').value.trim().toLowerCase();
        var visiveis = 0;
        $$('[data-sd-linha]').forEach(function (linha) {
            var mostra = (filtro === '' || linha.dataset.status === filtro) && (termo === '' || linha.dataset.busca.indexOf(termo) !== -1);
            linha.hidden = !mostra;
            if (mostra) { visiveis++; }
        });
        var ativo = $('[data-sd-filtro].ativo');
        $('[data-sd-titulo]').textContent = (ativo ? ativo.dataset.rotulo : 'Todos os chamados') + ' (' + visiveis + ')';
    }

    $$('[data-sd-filtro]').forEach(function (botao) {
        botao.addEventListener('click', function () {
            filtro = botao.dataset.sdFiltro;
            $$('[data-sd-filtro]').forEach(function (b) { b.classList.toggle('ativo', b === botao); });
            aplicarLista();
        });
    });
    $('[data-sd-busca]').addEventListener('input', aplicarLista);

    function alternarExtrato(mostrar) {
        var lista = $('[data-sd-extrato-lista]');
        lista.hidden = !mostrar;
        $('[data-sd-extrato-rotulo]').textContent = mostrar ? 'Ocultar extrato' : 'Ver extrato';
    }
    $('[data-sd-extrato]').addEventListener('click', function () {
        alternarExtrato($('[data-sd-extrato-lista]').hidden);
    });

    // Modal
    function abrirModal(painel, titulo, icone) {
        painelAtual = painel;
        $$('[data-sd-painel]').forEach(function (p) { p.hidden = p.dataset.sdPainel !== painel; });
        $('[data-sd-modal-titulo]').textContent = titulo;
        $('[data-sd-modal-icone]').className = 'bi ' + icone;
        botaoEnviar.hidden = painel !== 'novo';
        botaoPaguei.hidden = painel !== 'recarga';
        botaoCancelar.textContent = painel === 'novo' ? 'Cancelar' : 'Fechar';
        fundo.hidden = false;
        area.hidden = false;
    }

    function fecharModal() {
        fundo.hidden = true;
        area.hidden = true;
        painelAtual = null;
    }

    $$('[data-sd-fechar]').forEach(function (b) { b.addEventListener('click', fecharModal); });
    fundo.addEventListener('click', fecharModal);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && painelAtual) { fecharModal(); } });

    // Abrir chamado
    function mostrarErro(texto) {
        var caixa = $('[data-sd-erro]');
        caixa.textContent = texto || '';
        caixa.hidden = !texto;
    }

    function escolherTipo(botao) {
        tipo = botao.dataset.sdTipo;
        $$('[data-sd-tipo]').forEach(function (b) { b.classList.toggle('ativo', b === botao); });
        $$('[data-sd-agenda]').forEach(function (c) { c.hidden = botao.dataset.agenda !== '1'; });
    }
    $$('[data-sd-tipo]').forEach(function (b) { b.addEventListener('click', function () { escolherTipo(b); }); });

    $$('[data-sd-abrir-novo]').forEach(function (b) {
        b.addEventListener('click', function () {
            mostrarErro('');
            escolherTipo($('[data-sd-tipo="suporte"]'));
            $('[data-sd-assunto]').value = '';
            $('[data-sd-descricao]').value = '';
            $('[data-sd-prioridade]').value = 'media';
            $('[data-sd-data]').value = '';
            $('[data-sd-hora]').value = '';
            abrirModal('novo', 'Abrir chamado', 'bi-headset');
        });
    });

    botaoEnviar.addEventListener('click', function () {
        mostrarErro('');
        if (raiz.dataset.saldoOk !== '1') { return mostrarErro('Saldo insuficiente para abrir o chamado.'); }
        if ($('[data-sd-assunto]').value.trim() === '') { return mostrarErro('Informe o assunto.'); }
        var data = $('[data-sd-data]').value;
        var hora = $('[data-sd-hora]').value;
        if (tipo === 'agenda' && (!data || !hora)) { return mostrarErro('Escolha a data e o horário do agendamento.'); }

        botaoEnviar.disabled = true;
        enviar(raiz.dataset.urlAbrir, {
            tipo: tipo,
            prioridade: $('[data-sd-prioridade]').value,
            assunto: $('[data-sd-assunto]').value,
            descricao: $('[data-sd-descricao]').value,
            data: tipo === 'agenda' ? data : '',
            hora: tipo === 'agenda' ? hora : ''
        }).then(function (r) {
            botaoEnviar.disabled = false;
            if (!r.ok) { return mostrarErro(r.erro); }
            try { sessionStorage.setItem('sd-toast', r.mensagem); } catch (e) { /* sem sessionStorage */ }
            window.location.reload();
        });
    });

    // Adicionar saldo (PIX)
    function limparPix() {
        $('[data-sd-qr]').innerHTML = '';
        $('[data-sd-pix-texto]').hidden = true;
        $('[data-sd-copiar]').hidden = true;
        $('[data-sd-erro-recarga]').hidden = true;
    }

    function mostrarPix(r, valor) {
        $('[data-sd-qr]').innerHTML = r.qrSvg;
        $('[data-sd-pix-valor]').textContent = 'R$ ' + valor + ',00';
        $('[data-sd-pix-id]').textContent = r.referencia;
        $('[data-sd-pix-texto]').hidden = false;
        $('[data-sd-copiar]').hidden = false;
        $('[data-sd-copiar-rotulo]').textContent = 'Copiar PIX copia e cola';
    }

    // O PIX é só mostrado (nada é gravado); o pendente nasce em "Já paguei", com a referência e a assinatura deste PIX.
    function escolherValor(valor) {
        valorEscolhido = valor;
        pixAtual = null;
        botaoPaguei.disabled = true;
        $$('[data-sd-valor]').forEach(function (b) { b.classList.toggle('ativo', Number(b.dataset.sdValor) === valor); });
        limparPix();
        var pedido = ++pedidoDoPix;
        enviar(raiz.dataset.urlPix, { valor: valor }).then(function (r) {
            if (pedido !== pedidoDoPix) { return; }
            if (!r.ok) {
                var caixa = $('[data-sd-erro-recarga]');
                caixa.textContent = r.erro;
                caixa.hidden = false;
                return;
            }
            pixAtual = r;
            botaoPaguei.disabled = false;
            mostrarPix(r, valor);
        });
    }

    $$('[data-sd-abrir-recarga]').forEach(function (b) {
        b.addEventListener('click', function () {
            abrirModal('recarga', 'Adicionar saldo', 'bi-wallet2');
            escolherValor(valorEscolhido);
        });
    });
    $$('[data-sd-valor]').forEach(function (b) {
        b.addEventListener('click', function () { escolherValor(Number(b.dataset.sdValor)); });
    });

    $('[data-sd-copiar]').addEventListener('click', function () {
        var r = pixAtual;
        if (!r) { return; }
        var concluir = function () { $('[data-sd-copiar-rotulo]').textContent = 'Código copiado'; };
        var copiarSelecionando = function () {
                var campo = document.createElement('textarea');
            campo.value = r.copiaECola;
            document.body.appendChild(campo);
            campo.select();
            document.execCommand('copy');
            document.body.removeChild(campo);
            concluir();
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(r.copiaECola).then(concluir, copiarSelecionando);
            return;
        }
        copiarSelecionando();
    });

    botaoPaguei.addEventListener('click', function () {
        if (!pixAtual) { return; }
        botaoPaguei.disabled = true;
        enviar(raiz.dataset.urlRecarga, { valor: valorEscolhido, referencia: pixAtual.referencia, assinatura: pixAtual.assinatura }).then(function (r) {
            if (!r.ok) {
                botaoPaguei.disabled = false;
                var caixa = $('[data-sd-erro-recarga]');
                caixa.textContent = r.erro;
                caixa.hidden = false;
                return;
            }
            try {
                sessionStorage.setItem('sd-toast', 'Pagamento informado. O saldo é liberado assim que a equipe confirmar.');
                sessionStorage.setItem('sd-extrato', '1');
            } catch (e) { /* sem sessionStorage */ }
            window.location.reload();
        });
    });

    // Ver chamado
    $$('[data-sd-linha]').forEach(function (linha) {
        linha.addEventListener('click', function () {
            var url = raiz.dataset.urlChamado.replace(/0$/, linha.dataset.id);
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (r) {
                    if (!r.ok) { return avisar(r.erro); }
                    $('[data-sd-painel="ver"]').innerHTML = r.html;
                    abrirModal('ver', r.titulo, 'bi-headset');
                })
                .catch(function () { avisar('Não foi possível abrir o chamado agora.'); });
        });
    });

    try {
        var guardado = sessionStorage.getItem('sd-toast');
        if (guardado) {
            sessionStorage.removeItem('sd-toast');
            avisar(guardado);
        }
        if (sessionStorage.getItem('sd-extrato')) {
            sessionStorage.removeItem('sd-extrato');
            alternarExtrato(true);
        }
    } catch (e) { /* sem sessionStorage */ }
}());
