/* =============================================================================
   "Avisar cliente" da aba Push (spec push-avisar-cliente §2 e §3; desenho de 18/10,
   dc N:2765-2873 e `mailVals` N:5249-5293).

     1. cartão: abre/fecha o formulário, escolhe o rótulo, cadastra (POST), estrela,
        switch (o ESTADO DESEJADO), remover (com a pergunta do desenho) e o histórico.
        Cada POST devolve o cartão inteiro (HTML do servidor, já escapado) e o aviso
        da tela (`avisarPs`);
     2. "Enviar ao cliente" no cartão da Análise IA: sem e-mail nenhum, abre o
        formulário com "Cadastre um e-mail para enviar a análise."; senão abre o modal
        com o assunto e a mensagem padrão do botão e os e-mails do cartão;
     3. modal: chips do "Para", assunto, mensagem (editar desmarca o checkbox),
        "Revisei e corrigi", as validações na ordem do desenho e o envio (POST);
     4. "Envio automático" (P4d): os três switches do cliente (sem e-mail, abrem o
        formulário de cadastro).

   Dado do usuário só entra por `textContent`/`value`; o único innerHTML é o do
   cartão que o servidor renderizou (Twig com autoescape), como na lista da IA.
   ============================================================================= */
(function () {
    'use strict';

    var TXT_SEM_EMAIL = 'Cadastre um e-mail para enviar a análise.';

    function cartao() { return document.querySelector('[data-push-cliente]'); }

    function avisar(texto, tipo) {
        if (window.avisarPs) { window.avisarPs(texto, tipo); return; }
        var msg = cartao() && cartao().querySelector('[data-push-cliente-msg]');
        if (msg) { msg.textContent = texto || ''; msg.hidden = !texto; }
    }

    function postar(url, dados) {
        return fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: dados
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, status: r.status, dados: d }; });
        });
    }

    function trocarCartao(html) {
        var atual = cartao();
        if (!atual || !html) { return; }
        var molde = document.createElement('div');
        molde.innerHTML = html.trim();
        if (molde.firstElementChild) { atual.replaceWith(molde.firstElementChild); }
        atualizarDicasDasAnalises();
    }

    /* A dica do botão da análise acompanha os e-mails do cartão (dc N:5245 `envDica`). */
    function atualizarDicasDasAnalises() {
        var c = cartao();
        if (!c) { return; }
        document.querySelectorAll('[data-cliente-enviar]').forEach(function (botao) {
            if (botao.getAttribute('aria-disabled') !== 'true') { botao.title = c.dataset.dicaEnvio || ''; }
        });
    }

    /* ── 1. Cartão ─────────────────────────────────────────────────────────── */
    function formulario() { var c = cartao(); return c && c.querySelector('[data-push-cliente-form]'); }

    function erroDoFormulario(texto) {
        var form = formulario();
        var erro = form && form.querySelector('[data-push-cliente-erro]');
        if (!erro) { return; }
        erro.querySelector('span').textContent = texto || '';
        erro.hidden = !texto;
    }

    function abrirFormulario(erro) {
        var form = formulario();
        if (!form) { avisar(erro || 'Sem permissão para cadastrar e-mail.', 'erro'); return; }
        form.hidden = false;
        erroDoFormulario(erro || '');
        var campo = form.querySelector('[data-push-cliente-campo]');
        form.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        if (campo) { campo.focus(); }
    }

    function fecharFormulario() {
        var form = formulario();
        if (!form) { return; }
        form.hidden = true;
        form.reset();
        erroDoFormulario('');
    }

    function cadastrar(form) {
        var c = cartao();
        var ativo = form.querySelector('[data-push-cliente-rotulo].is-ativo');
        var dados = new FormData();
        dados.append('_token', c.dataset.token);
        dados.append('email', form.querySelector('[data-push-cliente-campo]').value);
        dados.append('rotulo', ativo ? ativo.dataset.pushClienteRotulo : 'pessoal');
        postar(c.dataset.cadastrarUrl, dados).then(function (res) {
            if (!res.ok || !res.dados.sucesso) { erroDoFormulario(res.dados.erro || 'Não foi possível cadastrar o e-mail.'); return; }
            trocarCartao(res.dados.html);
            if (res.dados.mensagem) { avisar(res.dados.mensagem); }
        }).catch(function () { erroDoFormulario('Não foi possível cadastrar o e-mail.'); });
    }

    function urlDoEmail(c, id, acao) {
        return c.dataset.emailUrl.replace('__ID__', id).replace('__ACAO__', acao);
    }

    function agirNoEmail(botao) {
        var c = cartao();
        var item = botao.closest('[data-push-cliente-email]');
        var acao = botao.dataset.pushClienteAcao;
        var dados = new FormData();
        dados.append('_token', c.dataset.token);
        if (acao === 'avisos') { dados.append('ligado', botao.getAttribute('aria-checked') === 'true' ? '0' : '1'); }
        botao.disabled = true;
        postar(urlDoEmail(c, item.dataset.pushClienteEmail, acao), dados).then(function (res) {
            if (!res.ok || !res.dados.sucesso) { throw new Error(res.dados.erro || 'Não foi possível alterar o e-mail.'); }
            trocarCartao(res.dados.html);
            if (res.dados.mensagem) { avisar(res.dados.mensagem); }
        }).catch(function (e) {
            botao.disabled = false;
            avisar(e.message, 'erro');
        });
    }

    /* Envio automático (spec push-envio-automatico §2; dc N:5270 `autos.alternar`): sem e-mail
       nenhum, o clique abre o formulário; senão grava o ESTADO DESEJADO da chave do cliente. */
    function alternarAutomatico(botao) {
        if (botao.classList.contains('is-sem-email')) { abrirFormulario(''); return; }
        var c = cartao();
        var dados = new FormData();
        dados.append('_token', c.dataset.token);
        dados.append('chave', botao.dataset.pushClienteAuto);
        dados.append('ligado', botao.getAttribute('aria-checked') === 'true' ? '0' : '1');
        botao.disabled = true;
        postar(c.dataset.automaticoUrl, dados).then(function (res) {
            if (!res.ok || !res.dados.sucesso) { throw new Error(res.dados.erro || 'Não foi possível alterar o envio automático.'); }
            trocarCartao(res.dados.html);
        }).catch(function (e) {
            botao.disabled = false;
            avisar(e.message, 'erro');
        });
    }

    function perguntarERemover(botao) {
        var item = botao.closest('[data-push-cliente-email]');
        var pergunta = 'Remover ' + item.dataset.email + ' dos avisos ao cliente?';
        var resposta = window.confirmarUIPs ? window.confirmarUIPs(pergunta, true) : Promise.resolve(window.confirm(pergunta));
        resposta.then(function (sim) { if (sim) { agirNoEmail(botao); } });
    }

    function alternarHistorico(botao) {
        var lista = cartao().querySelector('[data-push-cliente-hist-lista]');
        var abrir = lista.hidden;
        lista.hidden = !abrir;
        botao.setAttribute('aria-expanded', abrir ? 'true' : 'false');
        botao.querySelector('span').textContent = abrir ? botao.dataset.txtOcultar : botao.dataset.txtVer;
    }

    function escolherRotulo(chip) {
        chip.parentElement.querySelectorAll('[data-push-cliente-rotulo]').forEach(function (outro) {
            var ativo = outro === chip;
            outro.classList.toggle('is-ativo', ativo);
            outro.setAttribute('aria-pressed', ativo ? 'true' : 'false');
        });
    }

    function cliqueNoCartao(e) {
        var alvo = e.target.closest('button');
        if (!alvo || !alvo.closest('[data-push-cliente]') || alvo.disabled) { return; }
        if (alvo.hasAttribute('data-push-cliente-abrir')) {
            if (formulario() && !formulario().hidden) { fecharFormulario(); } else { abrirFormulario(''); }
        } else if (alvo.hasAttribute('data-push-cliente-fechar')) {
            fecharFormulario();
        } else if (alvo.hasAttribute('data-push-cliente-rotulo')) {
            escolherRotulo(alvo);
        } else if (alvo.hasAttribute('data-push-cliente-hist')) {
            alternarHistorico(alvo);
        } else if (alvo.hasAttribute('data-push-cliente-auto')) {
            alternarAutomatico(alvo);
        } else if (alvo.dataset.pushClienteAcao === 'remover') {
            perguntarERemover(alvo);
        } else if (alvo.dataset.pushClienteAcao) {
            agirNoEmail(alvo);
        }
    }

    /* ── 2 e 3. Modal "Enviar ao cliente" ──────────────────────────────────── */
    var envio = null; // { botao, analiseId, para: [], ok, enviando }

    function modal() { return document.querySelector('[data-push-envio]'); }

    function erroDoEnvio(texto) {
        var erro = modal().querySelector('[data-push-envio-erro]');
        erro.querySelector('span').textContent = texto || '';
        erro.hidden = !texto;
    }

    function desenharEstado() {
        var m = modal();
        var revisei = m.querySelector('[data-push-envio-revisei]');
        revisei.setAttribute('aria-checked', envio.ok ? 'true' : 'false');
        revisei.querySelector('i').className = 'bi ' + (envio.ok ? 'bi-check-square-fill' : 'bi-square');
        m.querySelector('[data-push-envio-confirmar]').classList.toggle('is-pronto', envio.ok && envio.para.length > 0);
        m.querySelector('[data-push-envio-confirmar-txt]').textContent = envio.enviando ? 'Enviando…' : 'Enviar e-mail';
        m.querySelectorAll('[data-push-envio-chip]').forEach(function (chip) {
            var on = envio.para.indexOf(chip.dataset.pushEnvioChip) !== -1;
            chip.classList.toggle('is-on', on);
            chip.setAttribute('aria-pressed', on ? 'true' : 'false');
            chip.querySelector('i').className = 'bi ' + (on ? 'bi-check-circle-fill' : 'bi-circle');
        });
    }

    /* Os chips do "Para": os e-mails do cartão que o cliente não descadastrou (dc N:5274). */
    function montarChips(itens) {
        var caixa = modal().querySelector('[data-push-envio-para]');
        caixa.textContent = '';
        itens.forEach(function (item) {
            var chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'ps-push-envio-para';
            chip.dataset.pushEnvioChip = item.dataset.email;
            chip.appendChild(document.createElement('i'));
            chip.appendChild(document.createTextNode(item.dataset.email));
            caixa.appendChild(chip);
        });
    }

    function abrirEnvio(botao) {
        if (botao.getAttribute('aria-disabled') === 'true') { avisar(botao.title, 'erro'); return; }
        var c = cartao();
        if (!c || !modal()) { avisar('Sem permissão para enviar ao cliente.', 'erro'); return; }
        if (c.querySelectorAll('[data-push-cliente-email]').length === 0) { abrirFormulario(TXT_SEM_EMAIL); return; }

        var itens = Array.prototype.filter.call(c.querySelectorAll('[data-push-cliente-email]'), function (i) { return i.dataset.descadastrado !== '1'; });
        envio = {
            botao: botao,
            analiseId: botao.dataset.clienteEnviar,
            para: itens.filter(function (i) { return i.dataset.recebendo === '1'; }).map(function (i) { return i.dataset.email; }),
            ok: false,
            enviando: false
        };
        var m = modal();
        montarChips(itens);
        m.querySelector('[data-push-envio-sub]').textContent = botao.dataset.sub || '';
        m.querySelector('[data-push-envio-assunto]').value = botao.dataset.assunto || '';
        m.querySelector('[data-push-envio-corpo]').value = botao.dataset.corpo || '';
        erroDoEnvio('');
        desenharEstado();
        m.hidden = false;
        m.querySelector('[data-push-envio-assunto]').focus();
    }

    function fecharEnvio() {
        if (!envio || envio.enviando) { return; }
        modal().hidden = true;
        envio.botao.focus();
        envio = null;
    }

    /* As validações do desenho, na ordem dele (dc N:5281-5283); o servidor repete. */
    function validarEnvio(assunto, corpo) {
        if (!envio.para.length) { return 'Escolha ao menos um destinatário.'; }
        if (!corpo.trim() || !assunto.trim()) { return 'Assunto e mensagem não podem ficar vazios.'; }
        if (!envio.ok) { return 'Confirme que revisou o conteúdo antes de enviar.'; }

        return '';
    }

    function marcarEnviada(dados) {
        var cartaoDaAnalise = envio.botao.closest('footer') || envio.botao.parentElement;
        var txt = envio.botao.querySelector('[data-cliente-enviar-txt]');
        if (txt) { txt.textContent = 'Reenviar ao cliente'; }
        var selo = cartaoDaAnalise.querySelector('[data-cliente-enviado]');
        if (selo) {
            selo.querySelector('[data-cliente-enviado-txt]').textContent = dados.enviado || '';
            selo.title = dados.dica || '';
            selo.hidden = false;
        }
    }

    function confirmarEnvio() {
        if (!envio || envio.enviando) { return; }
        var m = modal();
        var assunto = m.querySelector('[data-push-envio-assunto]').value;
        var corpo = m.querySelector('[data-push-envio-corpo]').value;
        var erro = validarEnvio(assunto, corpo);
        if (erro) { erroDoEnvio(erro); return; }

        var dados = new FormData();
        dados.append('_token', m.dataset.token);
        envio.para.forEach(function (email) { dados.append('para[]', email); });
        dados.append('assunto', assunto);
        dados.append('corpo', corpo);
        dados.append('revisado', '1');
        envio.enviando = true;
        erroDoEnvio('');
        desenharEstado();
        postar(m.dataset.envioUrl.replace('__ID__', envio.analiseId), dados).then(function (res) {
            envio.enviando = false;
            if (!res.ok || !res.dados.sucesso) {
                // Os que já saíram antes da falha não vão de novo no "tente de novo".
                (res.dados.enviados || []).forEach(function (email) { envio.para = envio.para.filter(function (p) { return p !== email; }); });
                if ((res.dados.enviados || []).length) { marcarEnviada({}); }
                desenharEstado();
                erroDoEnvio(res.dados.erro || 'Falha no envio. O texto foi mantido; tente de novo.');
                return;
            }
            marcarEnviada(res.dados);
            trocarCartao(res.dados.html);
            fecharEnvio();
            avisar(res.dados.mensagem);
        }).catch(function () {
            envio.enviando = false;
            desenharEstado();
            erroDoEnvio('Falha no envio. O texto foi mantido; tente de novo.');
        });
    }

    function cliqueNoModal(e) {
        var m = modal();
        if (!envio || !m || !m.contains(e.target)) { return; }
        if (e.target === m) { fecharEnvio(); return; }
        var alvo = e.target.closest('button');
        if (!alvo) { return; }
        if (alvo.hasAttribute('data-push-envio-fechar')) { fecharEnvio(); return; }
        if (alvo.hasAttribute('data-push-envio-confirmar')) { confirmarEnvio(); return; }
        if (alvo.hasAttribute('data-push-envio-revisei')) { envio.ok = !envio.ok; erroDoEnvio(''); desenharEstado(); return; }
        if (alvo.dataset.pushEnvioChip) {
            var email = alvo.dataset.pushEnvioChip;
            envio.para = envio.para.indexOf(email) !== -1 ? envio.para.filter(function (p) { return p !== email; }) : envio.para.concat([email]);
            erroDoEnvio('');
            desenharEstado();
        }
    }

    function iniciar() {
        document.addEventListener('click', function (e) {
            var enviar = e.target.closest('[data-cliente-enviar]');
            if (enviar) { e.preventDefault(); abrirEnvio(enviar); return; }
            cliqueNoModal(e);
            cliqueNoCartao(e);
        });
        document.addEventListener('submit', function (e) {
            var form = e.target.closest('[data-push-cliente-form]');
            if (form) { e.preventDefault(); cadastrar(form); }
        });
        document.addEventListener('input', function (e) {
            if (e.target.matches('[data-push-cliente-campo]')) { erroDoFormulario(''); }
            if (envio && e.target.matches('[data-push-envio-corpo]')) { envio.ok = false; desenharEstado(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') { return; }
            if (envio) { fecharEnvio(); return; }
            if (e.target.matches && e.target.matches('[data-push-cliente-campo]')) { fecharFormulario(); }
        });
        atualizarDicasDasAnalises();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
