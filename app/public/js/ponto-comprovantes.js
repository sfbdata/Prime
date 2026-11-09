/**
 * Janela "Validar comprovante" da aba Comprovantes do Ponto (E15, P50; desenho 16 L297 a L302 e L811): o código
 * digitado é conferido no servidor (a mesma rota pública do QR Code, com `Accept: application/json`), que responde
 * "autêntico" só quando o código existe e o selo confere. A frase do resultado vem do servidor; nada vira HTML.
 *
 * Moldura e comportamento das outras janelas da tela: Esc, o "x" e "Fechar" fecham; clicar fora não fecha; o foco
 * não sai da janela com Tab. Tudo por delegação no documento. Sem resposta em 15 s, a janela avisa.
 */
(function () {
    'use strict';

    const PRAZO_MS = 15000;
    const FOCAVEIS = 'button:not([disabled]), [href], input:not([type="hidden"])';
    const SEM_RESPOSTA = 'Sem resposta do servidor. Confira a conexão e tente de novo.';

    let focoAntes = null;

    function janela() {
        return document.getElementById('ponto-janela-validar');
    }

    function aberta() {
        const fundo = janela();

        return fundo && !fundo.hidden ? fundo : null;
    }

    function mostrar(fundo, resultado, texto) {
        fundo.querySelector('[data-validar-ok]').hidden = resultado !== 'ok';
        fundo.querySelector('[data-validar-erro]').hidden = resultado !== 'erro';
        fundo.querySelector('[data-validar-texto]').textContent = resultado === 'ok' ? texto : '';
        const falha = fundo.querySelector('[data-validar-falha]');
        falha.textContent = resultado === 'falha' ? texto : '';
        falha.hidden = resultado !== 'falha';
    }

    function abrir(botao) {
        const fundo = janela();
        if (!fundo) {
            return;
        }
        focoAntes = botao;
        fundo.querySelector('[data-validar-codigo]').value = '';
        mostrar(fundo, '', '');
        fundo.hidden = false;
        fundo.querySelector('[data-validar-codigo]').focus();
    }

    function fechar() {
        const fundo = aberta();
        if (!fundo || fundo.dataset.ocupada === '1') {
            return;
        }
        fundo.hidden = true;
        if (focoAntes && document.contains(focoAntes)) {
            focoAntes.focus();
        }
        focoAntes = null;
    }

    function prenderFoco(evento, fundo) {
        const focaveis = Array.from(fundo.querySelectorAll(FOCAVEIS));
        if (focaveis.length === 0) {
            return;
        }
        const primeiro = focaveis[0];
        const ultimo = focaveis[focaveis.length - 1];
        if (evento.shiftKey && document.activeElement === primeiro) {
            evento.preventDefault();
            ultimo.focus();
        } else if (!evento.shiftKey && document.activeElement === ultimo) {
            evento.preventDefault();
            primeiro.focus();
        }
    }

    async function consultar(codigo, url) {
        const aborto = new AbortController();
        const prazo = setTimeout(function () { aborto.abort(); }, PRAZO_MS);
        try {
            const resposta = await fetch(url + '/' + encodeURIComponent(codigo), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: aborto.signal,
            });
            const dados = await resposta.json().catch(function () { return {}; });
            if (resposta.ok && dados.autentico === true) {
                return ['ok', String(dados.texto || '')];
            }

            return resposta.ok ? ['erro', ''] : ['falha', dados.erro || SEM_RESPOSTA];
        } catch (erro) {
            return ['falha', SEM_RESPOSTA];
        } finally {
            clearTimeout(prazo);
        }
    }

    async function validar(evento) {
        evento.preventDefault();
        const form = evento.target;
        const fundo = janela();
        const codigo = form.querySelector('[data-validar-codigo]').value.trim().toUpperCase();
        if (codigo === '') {
            mostrar(fundo, 'erro', '');
            return;
        }
        const botao = form.querySelector('[data-validar-acao]');
        botao.disabled = true;
        fundo.dataset.ocupada = '1';
        const [resultado, texto] = await consultar(codigo, form.dataset.validarUrl);
        delete fundo.dataset.ocupada;
        botao.disabled = false;
        mostrar(fundo, resultado, texto);
    }

    document.addEventListener('click', function (evento) {
        if (evento.target.closest('[data-validar-abrir]')) {
            abrir(evento.target.closest('[data-validar-abrir]'));
        } else if (evento.target.closest('[data-validar-fechar]')) {
            fechar();
        }
    });

    document.addEventListener('keydown', function (evento) {
        const fundo = aberta();
        if (fundo && evento.key === 'Escape') {
            fechar();
        } else if (fundo && evento.key === 'Tab') {
            prenderFoco(evento, fundo);
        }
    });

    document.addEventListener('submit', function (evento) {
        if (evento.target.matches('[data-validar-form]')) {
            validar(evento);
        }
    });

    // Digitar outro código apaga o resultado da conferência anterior (desenho L767 e L802: o `setF` do `setCod` zera `valR`).
    document.addEventListener('input', function (evento) {
        const fundo = aberta();
        if (fundo && evento.target.matches('[data-validar-codigo]')) {
            mostrar(fundo, '', '');
        }
    });
})();
