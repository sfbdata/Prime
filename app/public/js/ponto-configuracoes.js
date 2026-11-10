/**
 * Comportamento do painel da aba "Configurações" da tela do Ponto (docs/specs/ponto-desenho-0811.md). O painel chega
 * depois (js/ponto-paineis.js): tudo por delegação no documento; `ponto:painel-carregado` avisa quando ele chega.
 *
 * Funções (E7, templates/ponto/_config_funcoes.html.twig; desenho 16 L235 a L245, `tgG` e `tgE` na L761):
 * - a chave "Escritório" grava por POST em `data-config-escritorio`, com `{ligada}` (o token `ajax` vem do
 *   interceptador do base.html.twig). Travada (Global desligado), não responde. Recusada porque o Master desligou o
 *   Global depois que a aba carregou (422 com `global: false`), a chave trava na hora, com a frase da trava;
 * - a chave "Global", só do Master, chama a rota do catálogo do Master com `{ativo}` e o token `master_recursos`; ao
 *   voltar, trava ou destrava a do escritório e avisa como o desenho ("liberado/desativado para todos"), no aviso
 *   flutuante do Ponto (`window.PontoAvisoFlutuante`, da E8; L266 e `avisar` L468). Sem ele na página, o aviso do
 *   sistema (`window.bjAviso`) fica de reserva para o texto não se perder.
 * O servidor decide: a tela só redesenha o que a resposta trouxe. Falha vira a frase da linha, nunca silêncio.
 */
(function () {
    'use strict';

    // Sem resposta até aqui, a gravação desiste e avisa (a tela não tem `fetch` sem prazo).
    const GRAVAR_PRAZO_MS = 15000;
    const TEXTO_FALHA = 'Não foi possível salvar agora. Tente de novo.';

    function elemento(alvo, seletor) {
        return alvo instanceof Element ? alvo.closest(seletor) : null;
    }

    function mostrarErro(linha, texto) {
        const erro = linha.querySelector('[data-config-erro]');
        if (!erro) {
            return;
        }
        erro.textContent = texto || '';
        erro.hidden = !texto;
    }

    // Estado da chave do escritório: travada aparece desligada, mas o valor guardado (`data-ligada`) fica.
    function desenharEscritorio(linha, liberadaPeloMaster) {
        const chave = linha.querySelector('[data-config-escritorio]');
        const trava = linha.querySelector('[data-config-trava]');
        if (!chave) {
            return;
        }
        chave.disabled = !liberadaPeloMaster;
        chave.setAttribute('aria-checked', liberadaPeloMaster && chave.dataset.ligada === '1' ? 'true' : 'false');
        if (trava) {
            trava.hidden = liberadaPeloMaster;
        }
    }

    // O servidor recusou porque o Global está desligado: trava a chave do escritório (a frase da trava, a mesma da
    // recusa, aparece no lugar do erro, para não sair duas vezes) e, na tela do Master, mostra o Global desligado
    // também. A linha fica igual à do servidor sem recarregar a aba.
    function travarPeloGlobal(linha) {
        const global = linha.querySelector('[data-config-global]');
        if (global) {
            global.setAttribute('aria-checked', 'false');
        }
        desenharEscritorio(linha, false);
    }

    async function enviar(url, corpo, token) {
        const cabecalhos = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (token) {
            cabecalhos['X-CSRF-Token'] = token;
        }
        const aborto = new AbortController();
        const prazo = setTimeout(function () { aborto.abort(); }, GRAVAR_PRAZO_MS);
        try {
            const resposta = await fetch(url, {
                method: 'POST',
                headers: cabecalhos,
                credentials: 'same-origin',
                body: JSON.stringify(corpo),
                signal: aborto.signal,
            });
            let dados = null;
            try {
                dados = await resposta.json();
            } catch (e) {
                dados = null;
            }
            if (!resposta.ok || !dados) {
                return { ok: false, erro: dados && typeof dados.erro === 'string' ? dados.erro : TEXTO_FALHA, dados: dados };
            }

            return { ok: true, dados: dados };
        } catch (erro) {
            return { ok: false, erro: TEXTO_FALHA };
        } finally {
            clearTimeout(prazo);
        }
    }

    async function alternarEscritorio(botao, linha) {
        const ligar = botao.dataset.ligada !== '1';
        botao.setAttribute('aria-busy', 'true');
        mostrarErro(linha, '');
        const resultado = await enviar(botao.dataset.configEscritorio, { ligada: ligar }, null);
        botao.removeAttribute('aria-busy');
        if (resultado.ok === false && resultado.dados && resultado.dados.global === false) {
            travarPeloGlobal(linha);

            return;
        }
        if (!resultado.ok) {
            mostrarErro(linha, resultado.erro);

            return;
        }
        botao.dataset.ligada = resultado.dados.escritorio ? '1' : '0';
        desenharEscritorio(linha, resultado.dados.global !== false);
    }

    async function alternarGlobal(botao, linha) {
        const liberar = botao.getAttribute('aria-checked') !== 'true';
        botao.setAttribute('aria-busy', 'true');
        mostrarErro(linha, '');
        const resultado = await enviar(botao.dataset.configGlobal, { ativo: liberar }, botao.dataset.token);
        botao.removeAttribute('aria-busy');
        if (!resultado.ok) {
            mostrarErro(linha, resultado.erro);

            return;
        }
        const ativo = resultado.dados.ativo === true;
        botao.setAttribute('aria-checked', ativo ? 'true' : 'false');
        desenharEscritorio(linha, ativo);
        const aviso = (linha.dataset.titulo || '') + (ativo ? ' liberado para todos os escritórios.' : ' desativado para todos os escritórios.');
        if (window.PontoAvisoFlutuante) {
            window.PontoAvisoFlutuante.mostrar(aviso, 'ok');
        } else if (window.bjAviso) {
            window.bjAviso(aviso);
        }
    }

    document.addEventListener('click', function (evento) {
        const linha = elemento(evento.target, '[data-config-funcao]');
        if (!linha) {
            return;
        }
        const escritorio = elemento(evento.target, '[data-config-escritorio]');
        const global = elemento(evento.target, '[data-config-global]');
        const botao = escritorio || global;
        if (!botao || botao.disabled || botao.getAttribute('aria-busy') === 'true') {
            return;
        }
        if (escritorio) {
            alternarEscritorio(escritorio, linha);

            return;
        }
        alternarGlobal(global, linha);
    });
})();
