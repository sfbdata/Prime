/* Janela "Como devemos tratar você?" (spec genero-no-cadastro, regra 3), no molde do aviso de espaço: aparece 1,7 s
   depois de abrir a tela, espera os modais que vêm antes dela e fica abaixo do aviso de espaço. Salvar grava no
   perfil e some; o X fecha só nesta sessão (o servidor guarda a marca na sessão; no próximo login a janela volta). */
(function () {
    'use strict';

    const janela = document.querySelector('[data-janela-genero]');
    if (!janela) {
        return;
    }
    const form = janela.querySelector('form');
    const erro = janela.querySelector('[data-erro-janela-genero]');
    const t = (chave) => (window.BJ_T ? window.BJ_T.t(chave) : chave);

    /* Com o aviso de espaço aberto no topo (mesmo lugar), a janela entra logo abaixo dele, sem cobrir. Ele aparece em
       1,5 s; a janela espera um pouco mais para já encontrá-lo na tela. */
    function empilharAbaixoDoAvisoDeEspaco() {
        const aviso = document.querySelector('[data-aviso-espaco]');
        if (aviso && !aviso.hidden) {
            janela.style.top = Math.round(aviso.getBoundingClientRect().bottom + 8) + 'px';
        }
    }

    function mostrar() {
        if (document.querySelector('.modal.show')) {
            document.addEventListener('hidden.bs.modal', mostrar, { once: true });

            return;
        }
        empilharAbaixoDoAvisoDeEspaco();
        janela.hidden = false;
    }
    setTimeout(mostrar, 1700);

    function mostrarErro(texto) {
        erro.textContent = texto;
        erro.hidden = texto === '';
    }

    /* Só é sucesso a resposta 2xx, sem redirecionamento, com o JSON {ok: true}. Um redirecionamento (para a seleção
       de escritório, para o login) chega como 200 com HTML: não gravou nada, e a janela tem de ficar. */
    function postar(url, dados) {
        return fetch(url, {
            method: 'POST',
            body: dados,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        }).then((r) => r.json().catch(() => ({})).then((corpo) => ({
            ok: r.ok && !r.redirected && corpo !== null && corpo.ok === true,
            corpo: corpo || {},
        })));
    }

    form.addEventListener('change', () => mostrarErro(''));

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        if (!form.querySelector('input[name="genero"]:checked')) {
            mostrarErro(t('moldura.js.genero.informe'));

            return;
        }
        const botao = form.querySelector('button[type="submit"]');
        botao.disabled = true;
        postar(form.action, new FormData(form))
            .then((r) => {
                if (r.ok) {
                    janela.remove();

                    return;
                }
                mostrarErro(typeof r.corpo.erro === 'string' && r.corpo.erro !== '' ? r.corpo.erro : t('moldura.js.genero.falha'));
            })
            .catch(() => mostrarErro(t('moldura.js.genero.falha')))
            .finally(() => { botao.disabled = false; });
    });

    /* O X só some com a janela quando o servidor marcou a sessão; senão ela fica, com o aviso. */
    janela.querySelector('[data-fechar-janela-genero]').addEventListener('click', () => {
        const dados = new FormData();
        dados.append('_token', form.querySelector('input[name="_token"]').value);
        postar(form.dataset.acaoFechar, dados)
            .then((r) => {
                if (r.ok) {
                    janela.remove();

                    return;
                }
                mostrarErro(t('moldura.js.genero.falha'));
            })
            .catch(() => mostrarErro(t('moldura.js.genero.falha')));
    });
})();
