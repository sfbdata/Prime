/* Avisos de espaço (desenho Contribua 14 e 15/11, bj-plano.js): barra do escritório, barra do Master com pedidos pendentes,
   pop-up dos 97% e os modais "Solicitar mais espaço" / "Pedido de mais espaço".
   Entram 1,5 s depois de abrir a tela e esperam os modais que vêm antes deles (alerta do ponto, instalar no iPhone): nunca
   abrem por cima de um. Prioridade, como no desenho: barra do Master, depois pop-up dos 97%, depois a barra do escritório.
   Uma vez por dia, no localStorage (a 100% o pop-up volta a cada carga). Contrato com a seção #espaco da Contribua:
   qualquer elemento com `data-espaco-solicitar` abre o modal certo; `data-espaco-ver-pedido` abre "Pedido em análise". */
(function () {
    'use strict';

    const CHAVE_BARRA = 'bj-aviso-espaco';
    const CHAVE_MASTER = 'bj-aviso-pedm';
    const CHAVE_POPUP = 'bj-popup-97';
    const hoje = new Date().toLocaleDateString('sv-SE');

    const barra = document.querySelector('[data-aviso-espaco]');
    const barraMaster = document.querySelector('[data-aviso-pedidos-espaco]');
    const raiz = document.querySelector('[data-espaco-raiz]');
    const popup = raiz ? raiz.querySelector('[data-es-modal="popup"]') : null;
    if (!barra && !barraMaster && !raiz) {
        return;
    }

    function vistoHoje(chave) {
        try { return localStorage.getItem(chave) === hoje; } catch (e) { return false; /* sem storage: mostra */ }
    }
    function marcarHoje(chave) {
        try { localStorage.setItem(chave, hoje); } catch (e) { /* sem storage: só fecha */ }
    }

    function mostrar(elemento, aoMostrar) {
        // A janela do alerta de horário do ponto não é modal do Bootstrap: entra no seletor (ela dispara `hidden.bs.modal`).
        if (document.querySelector('.modal.show, #modalAlertaPonto.show')) {
            document.addEventListener('hidden.bs.modal', () => mostrar(elemento, aoMostrar), { once: true });

            return;
        }
        elemento.hidden = false;
        if (aoMostrar) {
            aoMostrar();
        }
    }

    function iniciar() {
        if (barraMaster && !vistoHoje(CHAVE_MASTER)) {
            mostrar(barraMaster);

            return;
        }
        if (popup && !(vistoHoje(CHAVE_POPUP) && Number(popup.dataset.percentual) < 100)) {
            mostrar(popup, () => marcarHoje(CHAVE_POPUP));

            return;
        }
        if (barra && !vistoHoje(CHAVE_BARRA)) {
            mostrar(barra);
        }
    }
    setTimeout(iniciar, 1500);

    function aoFecharBarra(elemento, seletor, chave) {
        const botao = elemento ? elemento.querySelector(seletor) : null;
        if (botao) {
            botao.addEventListener('click', () => {
                marcarHoje(chave);
                elemento.hidden = true;
            });
        }
    }
    aoFecharBarra(barra, '[data-fechar-aviso-espaco]', CHAVE_BARRA);
    aoFecharBarra(barraMaster, '[data-fechar-aviso-pedidos]', CHAVE_MASTER);

    if (!raiz) {
        return;
    }

    // Modais: o estado do escritório (nenhum, pendente, liberado) decide o que "Solicitar mais espaço" abre.
    let estado = raiz.dataset.estado;
    const urlOpcoes = raiz.dataset.urlOpcoes;
    const naContribua = location.pathname === new URL(urlOpcoes, location.href).pathname;
    const modalSolicitar = raiz.querySelector('[data-es-modal="solicitar"]');
    const modalPedido = raiz.querySelector('[data-es-modal="pedido"]');

    function fechar(overlay) {
        overlay.hidden = true;
        if (overlay.dataset.recarregar === '1') {
            location.reload();
        }
    }

    function irParaOpcoes() {
        const alvo = document.getElementById('espaco');
        if (naContribua && alvo) {
            alvo.scrollIntoView({ behavior: 'smooth', block: 'start' });

            return;
        }
        location.href = urlOpcoes;
    }

    function abrirSolicitar() {
        if (estado === 'liberado') {
            irParaOpcoes();
        } else if (estado === 'pendente') {
            modalPedido.hidden = false;
        } else {
            modalSolicitar.hidden = false;
        }
    }

    document.addEventListener('click', (e) => {
        if (e.target.classList && e.target.classList.contains('es-ov')) {
            fechar(e.target);

            return;
        }
        const alvo = e.target.closest ? e.target.closest('[data-espaco-solicitar], [data-espaco-ver-pedido], [data-es-fechar]') : null;
        if (!alvo) {
            return;
        }
        if (alvo.matches('[data-es-fechar]')) {
            fechar(alvo.closest('.es-ov'));

            return;
        }
        e.preventDefault();
        abrirSolicitar();
    });

    function mostrarErro(texto) {
        const erro = modalSolicitar.querySelector('[data-es-erro]');
        erro.textContent = texto;
        erro.hidden = false;
    }

    function aoEnviarComSucesso() {
        estado = 'pendente';
        modalSolicitar.querySelector('[data-es-etapa="form"]').hidden = true;
        modalSolicitar.querySelector('[data-es-etapa="sucesso"]').hidden = false;
        const enviado = modalPedido.querySelector('[data-es-enviado]');
        const quando = new Date().toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        enviado.textContent = enviado.dataset.modelo.replace('{quando}', quando);
        if (barra) {
            barra.hidden = true;
        }
        if (naContribua) {
            modalSolicitar.dataset.recarregar = '1';
        }
    }

    const formulario = modalSolicitar.querySelector('[data-es-form]');
    formulario.addEventListener('submit', (e) => {
        e.preventDefault();
        const enviar = formulario.querySelector('[data-es-enviar]');
        const dados = new FormData(formulario);
        dados.set('motivo', String(dados.get('motivo') || '').trim().slice(0, 300));
        dados.set('_token', raiz.dataset.token);
        modalSolicitar.querySelector('[data-es-erro]').hidden = true;
        enviar.disabled = true;
        fetch(raiz.dataset.urlSolicitar, {
            method: 'POST',
            body: dados,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        })
            .then((resposta) => resposta.json().catch(() => null))
            .then((json) => {
                if (json && json.ok) {
                    aoEnviarComSucesso();
                } else {
                    mostrarErro((json && json.erro) || raiz.dataset.erroGenerico);
                }
            })
            .catch(() => mostrarErro(raiz.dataset.erroGenerico))
            .finally(() => { enviar.disabled = false; });
    });
})();
