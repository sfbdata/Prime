/* Janela "Como devemos tratar você?" (spec genero-no-cadastro, regra 3), no molde do aviso de espaço: aparece 1,5 s
   depois de abrir a tela e espera os modais que vêm antes dela. Salvar grava no perfil e some; o X fecha só nesta
   sessão (o servidor guarda a marca na sessão; no próximo login a janela volta). */
(function () {
    'use strict';

    const janela = document.querySelector('[data-janela-genero]');
    if (!janela) {
        return;
    }
    const form = janela.querySelector('form');
    const erro = janela.querySelector('[data-erro-janela-genero]');
    const t = (chave) => (window.BJ_T ? window.BJ_T.t(chave) : chave);

    function mostrar() {
        if (document.querySelector('.modal.show')) {
            document.addEventListener('hidden.bs.modal', mostrar, { once: true });

            return;
        }
        janela.hidden = false;
    }
    setTimeout(mostrar, 1500);

    function mostrarErro(texto) {
        erro.textContent = texto;
        erro.hidden = texto === '';
    }

    function postar(url, dados) {
        return fetch(url, {
            method: 'POST',
            body: dados,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        }).then((r) => r.json().catch(() => ({})).then((corpo) => ({ ok: r.ok, corpo: corpo })));
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
                mostrarErro(r.corpo.erro || t('moldura.js.genero.falha'));
            })
            .catch(() => mostrarErro(t('moldura.js.genero.falha')))
            .finally(() => { botao.disabled = false; });
    });

    janela.querySelector('[data-fechar-janela-genero]').addEventListener('click', () => {
        janela.hidden = true;
        const dados = new FormData();
        dados.append('_token', form.querySelector('input[name="_token"]').value);
        postar(form.dataset.acaoFechar, dados).catch(() => { /* sem rede: fecha só nesta tela */ });
    });
})();
