/* =============================================================================
   pasta-prazos.js: "Próximos prazos" do trilho da Pasta no desenho 1.2.3 (E12b).
   "+" abre "Novo prazo ou audiência"; no ⋮ dos prazos manuais, Editar abre a mesma janela preenchida, e
   "Marcar como cumprido" e "Excluir" gravam na hora, como o desenho (sem confirmação). Depois de gravar a página
   recarrega na aba Dados, com o aviso do desenho guardado para depois da recarga.
   Só textContent e atributos; nada monta HTML com dado.
   ============================================================================= */
(function () {
    'use strict';

    var CHAVE_AVISO = 'ps-prazo-aviso';
    var cartao = document.querySelector('[data-trilho="prazos"][data-url-prazos]');
    var janela = document.getElementById('psPrazoJanela');
    var tipo = 'prazo';
    var editando = null;

    function avisar(t, erro) { if (window.avisarPs) { window.avisarPs(t, erro ? 'erro' : null); } }
    try { var guardado = sessionStorage.getItem(CHAVE_AVISO); if (guardado) { sessionStorage.removeItem(CHAVE_AVISO); setTimeout(function () { avisar(guardado); }, 300); } } catch (e) { /* sem armazenamento */ }
    if (!cartao) { return; }

    function postar(url, dados) {
        var corpo = new URLSearchParams(dados || {});
        corpo.append('_token', cartao.getAttribute('data-csrf-prazos'));
        return fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: corpo.toString()
        }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); });
    }

    function recarregar(aviso) {
        try { sessionStorage.setItem(CHAVE_AVISO, aviso); } catch (e) { /* sem armazenamento */ }
        window.location.reload();
    }

    function pintarTipo(t) {
        tipo = t === 'audiencia' ? 'audiencia' : 'prazo';
        janela.querySelectorAll('.js-pz-tipo').forEach(function (b) {
            var sim = b.getAttribute('data-tipo') === tipo;
            b.classList.toggle('is-ativo', sim);
            b.setAttribute('aria-checked', sim ? 'true' : 'false');
        });
        // "HORA (opcional)" no prazo; na audiência, só "HORA" (dc `pzHoraOpc`). A hora não é exigida em nenhum.
        janela.querySelector('.js-pz-hora-opc').hidden = tipo === 'audiencia';
    }

    function abrir(dados) {
        if (!janela) { return; }
        editando = dados && dados.id ? dados.id : null;
        document.getElementById('psPrazoTitulo').textContent = editando ? 'Editar prazo' : 'Novo prazo ou audiência';
        document.getElementById('psPzTitulo').value = (dados && dados.titulo) || '';
        document.getElementById('psPzData').value = (dados && dados.data) || '';
        document.getElementById('psPzHora').value = (dados && dados.hora) || '';
        document.getElementById('psPzResp').value = (dados && dados.responsavel) || '';
        document.getElementById('psPzErro').textContent = '';
        pintarTipo((dados && dados.tipo) || 'prazo');
        janela.hidden = false;
        setTimeout(function () { document.getElementById('psPzTitulo').focus(); }, 30);
    }

    function fechar() { if (janela) { janela.hidden = true; } editando = null; }

    function salvar() {
        var titulo = document.getElementById('psPzTitulo').value.trim();
        var data = document.getElementById('psPzData').value;
        var erro = document.getElementById('psPzErro');
        if (!titulo || !data) { erro.textContent = 'Informe a descrição e a data'; return; }
        var botao = janela.querySelector('.js-pz-salvar');
        botao.disabled = true;
        postar(cartao.getAttribute('data-url-prazos'), {
            prazo_id: editando || '', tipo: tipo, titulo: titulo, data: data,
            hora: document.getElementById('psPzHora').value, responsavel: document.getElementById('psPzResp').value.trim()
        }).then(function (r) {
            botao.disabled = false;
            if (!r.ok) { erro.textContent = r.j.erro || 'Não foi possível salvar'; return; }
            recarregar(r.j.aviso || 'Prazo registrado');
        }, function () { botao.disabled = false; erro.textContent = 'Falha de conexão. Tente de novo.'; });
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('.js-prazo-novo')) { e.preventDefault(); abrir(null); return; }
        var ed = e.target.closest('.js-prazo-editar');
        if (ed) {
            e.preventDefault();
            abrir({ id: ed.getAttribute('data-prazo-id'), tipo: ed.getAttribute('data-tipo'), titulo: ed.getAttribute('data-titulo'),
                data: ed.getAttribute('data-data'), hora: ed.getAttribute('data-hora'), responsavel: ed.getAttribute('data-responsavel') });
            return;
        }
        var acao = e.target.closest('.js-prazo-cumprir, .js-prazo-excluir');
        if (acao) {
            e.preventDefault();
            postar(acao.getAttribute('data-url')).then(function (r) {
                if (!r.ok) { avisar(r.j.erro || 'Não foi possível alterar o prazo', true); return; }
                recarregar(r.j.aviso);
            }, function () { avisar('Falha de conexão. Tente de novo.', true); });
            return;
        }
        if (!janela || janela.hidden) { return; }
        // Clique no fundo fecha, como o desenho (`pzFechar`).
        if (e.target === janela || e.target.closest('.js-pz-fechar')) { fechar(); return; }
        var t = e.target.closest('.js-pz-tipo');
        if (t) { pintarTipo(t.getAttribute('data-tipo')); return; }
        if (e.target.closest('.js-pz-salvar')) { salvar(); }
    });
    document.addEventListener('keydown', function (e) {
        if (!janela || janela.hidden) { return; }
        if (e.key === 'Escape') { e.preventDefault(); fechar(); }
        if (e.key === 'Enter' && e.target.matches('#psPzTitulo, #psPzResp')) { e.preventDefault(); salvar(); }
    });
}());
