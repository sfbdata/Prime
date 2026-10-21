/*
 * Página pública de vagas (desenho `Vagas Publicas.dc.html`): troca entre "Procuro vaga" e "Quero
 * contratar", perguntas frequentes e a inscrição em "Receba vagas da sua área por e-mail".
 * Os textos e as mensagens de erro são os do desenho; quem valida de verdade é o servidor.
 */
(function () {
    'use strict';

    var pagina = document.getElementById('vpPagina');
    if (!pagina) {
        return;
    }

    /* ------------------------------------------------ Procuro vaga | Quero contratar (`publicos`) */
    pagina.querySelectorAll('.vp-publicos [data-publico]').forEach(function (b) {
        b.addEventListener('click', function () { pagina.setAttribute('data-publico', b.getAttribute('data-publico')); });
    });

    /* ------------------------------------------------ clique em "Criar conta" (spec VS 18.10.26 §4.9) */
    /*
     * Contador anônimo do KPI "ALERTAS POR E-MAIL": todo link de cadastro com `data-url-cta` avisa
     * `POST /vagas-juridicas/cta` com o token do link (ou nada, sem token). sendBeacon não segura a
     * navegação; sem ele, fetch com keepalive. Qualquer falha é ignorada: o link segue sempre.
     */
    function tokenDaPagina(a) {
        var alertas = document.getElementById('alertas');
        return a.getAttribute('data-token') || pagina.getAttribute('data-token') || (alertas && alertas.getAttribute('data-token')) || null;
    }
    function avisarCta(a) {
        var url = a.getAttribute('data-url-cta');
        if (!url) {
            return;
        }
        var corpo = JSON.stringify({ token: tokenDaPagina(a) });
        try {
            if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([corpo], { type: 'application/json' }))) {
                return;
            }
        } catch (e) {
            // Cai no fetch abaixo.
        }
        try {
            fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', keepalive: true, body: corpo })
                .catch(function () { /* contador: falha não importa */ });
        } catch (e) {
            // Contador: falha não importa.
        }
    }
    // Clique normal e clique do meio (nova aba), sem preventDefault: a navegação nunca espera.
    ['click', 'auxclick'].forEach(function (tipo) {
        document.addEventListener(tipo, function (e) {
            if (tipo === 'auxclick' && e.button !== 1) {
                return;
            }
            var a = e.target && e.target.closest ? e.target.closest('[data-url-cta]') : null;
            if (a) {
                avisarCta(a);
            }
        }, true);
    });

    /* ------------------------------------------------ perguntas frequentes: uma aberta por vez */
    var itens = Array.prototype.slice.call(pagina.querySelectorAll('.vp-faq-item'));
    itens.forEach(function (item) {
        item.querySelector('button').addEventListener('click', function () {
            var abrir = item.querySelector('.vp-faq-r').hidden;
            itens.forEach(function (x) {
                x.querySelector('.vp-faq-r').hidden = true;
                x.querySelector('button').setAttribute('aria-expanded', 'false');
                x.querySelector('button .bi').className = 'bi bi-plus-lg';
            });
            if (abrir) {
                item.querySelector('.vp-faq-r').hidden = false;
                item.querySelector('button').setAttribute('aria-expanded', 'true');
                item.querySelector('button .bi').className = 'bi bi-dash-lg';
            }
        });
    });

    /* ------------------------------------------------ alertas por e-mail */
    var caixa = document.getElementById('alertas');
    var areas = [];
    try {
        areas = JSON.parse(caixa.getAttribute('data-areas-sugeridas') || '[]') || [];
    } catch (e) {
        areas = [];
    }
    var botoesArea = Array.prototype.slice.call(caixa.querySelectorAll('[data-area]'));
    function pintarAreas() {
        botoesArea.forEach(function (b) { b.classList.toggle('is-on', areas.indexOf(b.getAttribute('data-area')) >= 0); });
    }
    botoesArea.forEach(function (b) {
        b.addEventListener('click', function () {
            var a = b.getAttribute('data-area');
            areas = areas.indexOf(a) >= 0 ? areas.filter(function (x) { return x !== a; }) : areas.concat([a]);
            pintarAreas();
        });
    });
    pintarAreas();

    var erro = document.getElementById('vpErro');
    function mostrarErro(txt) {
        erro.querySelector('span').textContent = txt || '';
        erro.hidden = !txt;
    }
    ['vpEmail', 'vpLgpd'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', function () { mostrarErro(''); });
    });

    function textoDoAviso(email, uf) {
        var alvo = areas.length ? 'de ' + areas.slice(0, 3).join(', ') + (uf ? ' em ' + uf : '') : (uf ? 'em ' + uf : 'nova');
        return 'Enviaremos para ' + email + ' quando surgir vaga ' + alvo + '. Para se candidatar, crie sua conta.';
    }

    var botao = document.getElementById('vpInscrever');
    botao.addEventListener('click', function () {
        var email = document.getElementById('vpEmail').value.trim();
        var uf = document.getElementById('vpUf').value;
        var lgpd = document.getElementById('vpLgpd').checked;
        if (!/^\S+@\S+\.\S+$/.test(email)) {
            mostrarErro('Informe um e-mail válido.');
            return;
        }
        if (!lgpd) {
            mostrarErro('Marque a autorização para receber os e-mails.');
            return;
        }
        botao.disabled = true;
        fetch(caixa.getAttribute('data-url'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ email: email, uf: uf, areas: areas, lgpd: lgpd, token: caixa.getAttribute('data-token') || null, _token: caixa.getAttribute('data-csrf') })
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false }; });
        }).then(function (r) {
            botao.disabled = false;
            if (!r.ok) {
                mostrarErro(r.mensagem || 'Não foi possível concluir agora. Tente de novo.');
                return;
            }
            document.getElementById('vpLeadTxt').textContent = textoDoAviso(email, uf);
            caixa.querySelector('.vp-lead-form').hidden = true;
            caixa.querySelector('.vp-lead-ok').hidden = false;
        }, function () {
            botao.disabled = false;
            mostrarErro('Não foi possível concluir agora. Tente de novo.');
        });
    });
})();
