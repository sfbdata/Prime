/**
 * Sedes e feriados na aba "Configurações" da tela do Ponto (E6 do desenho, docs/specs/ponto-desenho-0811.md; desenho
 * 16 L253 a L258). O painel chega depois (js/ponto-paineis.js) e pode ser buscado de novo: tudo por delegação no
 * documento, nada preso a um elemento do painel.
 *
 * - Sedes: cada linha grava ao sair do campo (a linha inteira). Uma gravação por linha de cada vez; mudou de novo
 *   durante o envio, grava outra vez no fim (a "Nova sede" não vira duas). O erro aparece na linha de baixo.
 * - "Usar minha posição": a posição vem do leitor com prazo do módulo da batida (`PontoBatida.lerPosicaoComPrazo`),
 *   com 6 casas; sem posição, o aviso do desenho.
 * - Feriados: sem data ou nome, o aviso do desenho; gravado, a lista volta do servidor e o formulário limpa.
 * - Remover sede e remover feriado abrem a janela de confirmação (D4); quem remove é o botão da janela.
 *
 * Todo envio tem prazo próprio (a tela não tem `fetch` sem prazo, BatidaNaoTravaNoGpsTest). O token da página vai no
 * cabeçalho pelo base.html.twig. Exposto em `window.PontoConfiguracoesSedes`.
 */
(function () {
    'use strict';

    // Sem resposta até aqui, a linha (ou a janela) avisa e deixa tentar de novo.
    const GRAVAR_PRAZO_MS = 15000;
    // Teto para o aparelho dar a posição do "Usar minha posição".
    const POSICAO_PRAZO_MS = 10000;
    // O aviso flutuante some sozinho (desenho, `avisar` L468); um por vez.
    const AVISO_MS = 3400;
    const CASAS_DA_POSICAO = 6;
    const SEM_RESPOSTA = 'Sem resposta do servidor. Confira a conexão e tente de novo.';
    const SEM_POSICAO = 'Localização indisponível neste aparelho.';
    const FALTA_FERIADO = 'Informe a data e o nome do feriado.';
    const CAMPOS_DA_SEDE = ['nome', 'latitude', 'longitude', 'raio'];
    const FOCAVEIS = 'button:not([disabled]), [href], input:not([type="hidden"]), select';

    let janelaAberta = null;
    let focoAntes = null;
    let alvoDaJanela = null;
    let aviso = null;
    let avisoPrazo = null;

    // POST com prazo. Nunca rejeita: devolve se deu certo e o JSON do servidor (ou a frase da falta de resposta).
    async function enviar(url, corpo) {
        const aborto = new AbortController();
        const prazo = setTimeout(function () { aborto.abort(); }, GRAVAR_PRAZO_MS);
        try {
            const resposta = await fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: corpo,
                signal: aborto.signal,
            });
            const dados = await resposta.json().catch(function () { return {}; });

            return { ok: resposta.ok && dados.ok === true, dados: dados };
        } catch (erro) {
            return { ok: false, dados: { mensagem: SEM_RESPOSTA } };
        } finally {
            clearTimeout(prazo);
        }
    }

    function frase(dados, padrao) {
        const erros = Object.values(dados.erros || {});

        return erros.length > 0 ? erros.join(' ') : (dados.mensagem || padrao);
    }

    // Aviso flutuante do desenho (§2.13): verde no que deu certo, vermelho no erro.
    function avisar(texto, erro) {
        if (aviso) {
            aviso.remove();
            clearTimeout(avisoPrazo);
        }
        aviso = document.createElement('div');
        aviso.className = 'pe-sf-aviso' + (erro ? ' pe-sf-aviso--erro' : '');
        aviso.setAttribute('role', 'status');
        aviso.textContent = texto;
        (document.querySelector('.pe-page') || document.body).appendChild(aviso);
        avisoPrazo = setTimeout(function () {
            if (aviso) {
                aviso.remove();
                aviso = null;
            }
        }, AVISO_MS);
    }

    // ---------------------------------------------------------------- sedes

    function erroDaLinha(linha, texto, campos) {
        const abaixo = linha.nextElementSibling;
        if (abaixo && abaixo.matches('[data-sede-erro]')) {
            abaixo.firstElementChild.textContent = texto;
            abaixo.hidden = texto === '';
        }
        CAMPOS_DA_SEDE.forEach(function (campo) {
            const entrada = linha.querySelector('[name="' + campo + '"]');
            if (campos.indexOf(campo) >= 0) {
                entrada.setAttribute('aria-invalid', 'true');
            } else {
                entrada.removeAttribute('aria-invalid');
            }
        });
    }

    // O servidor devolve a coordenada como gravou (vírgula vira ponto); o campo que a pessoa está editando fica.
    function mostrarGravada(linha, sede) {
        linha.dataset.urlSalvar = sede.urlSalvar;
        linha.dataset.urlRemover = sede.urlRemover;
        CAMPOS_DA_SEDE.forEach(function (campo) {
            const entrada = linha.querySelector('[name="' + campo + '"]');
            if (entrada !== document.activeElement && linha.dataset.deNovo !== '1') {
                entrada.value = String(sede[campo]);
            }
        });
    }

    async function gravarSedeUmaVez(linha) {
        const corpo = new URLSearchParams();
        CAMPOS_DA_SEDE.forEach(function (campo) {
            corpo.set(campo, linha.querySelector('[name="' + campo + '"]').value);
        });
        const secao = linha.closest('[data-sedes]');
        const resultado = await enviar(linha.dataset.urlSalvar || secao.dataset.urlCriar, corpo);
        if (resultado.ok) {
            erroDaLinha(linha, '', []);
            mostrarGravada(linha, resultado.dados.sede);
            return;
        }
        erroDaLinha(linha, frase(resultado.dados, 'Não foi possível gravar a sede.'), Object.keys(resultado.dados.erros || {}));
    }

    async function gravarSede(linha) {
        if (linha.dataset.gravando === '1') {
            linha.dataset.deNovo = '1';
            return;
        }
        linha.dataset.gravando = '1';
        do {
            delete linha.dataset.deNovo;
            await gravarSedeUmaVez(linha);
        } while (linha.dataset.deNovo === '1' && document.contains(linha));
        delete linha.dataset.gravando;
    }

    // "Nova sede" com o raio da sede nova (o modelo vem do servidor). Vai para o banco na primeira gravação.
    function adicionarSede(botao) {
        const secao = botao.closest('[data-sedes]');
        const modelo = secao.querySelector('template[data-sede-modelo]');
        secao.querySelector('[data-sedes-corpo]').appendChild(modelo.content.cloneNode(true));
        const linhas = secao.querySelectorAll('[data-sede-linha]');
        const nome = linhas[linhas.length - 1].querySelector('[name="nome"]');
        nome.focus();
        nome.select();
    }

    async function usarPosicao(botao) {
        const linha = botao.closest('[data-sede-linha]');
        const leitor = window.PontoBatida && window.PontoBatida.lerPosicaoComPrazo;
        botao.disabled = true;
        const posicao = leitor ? await leitor(POSICAO_PRAZO_MS, function () {}) : null;
        botao.disabled = false;
        if (!posicao || !posicao.coords) {
            avisar(SEM_POSICAO, true);
            return;
        }
        linha.querySelector('[name="latitude"]').value = posicao.coords.latitude.toFixed(CASAS_DA_POSICAO);
        linha.querySelector('[name="longitude"]').value = posicao.coords.longitude.toFixed(CASAS_DA_POSICAO);
        gravarSede(linha);
    }

    function tirarLinha(linha) {
        const abaixo = linha.nextElementSibling;
        if (abaixo && abaixo.matches('[data-sede-erro]')) {
            abaixo.remove();
        }
        linha.remove();
    }

    // A "Nova sede" que nunca foi gravada sai sem perguntar: não há nada no banco.
    function pedirRemoverSede(botao) {
        const linha = botao.closest('[data-sede-linha]');
        if (!linha.dataset.urlRemover) {
            tirarLinha(linha);
            return;
        }
        abrirJanela('ponto-janela-remover-sede', botao, linha.querySelector('[name="nome"]').value, linha.dataset.urlRemover, linha);
    }

    // -------------------------------------------------------------- feriados

    async function cadastrarFeriado(form) {
        const dados = new FormData(form);
        if (String(dados.get('data') || '') === '' || String(dados.get('nome') || '').trim() === '') {
            avisar(FALTA_FERIADO, true);
            return;
        }
        const botao = form.querySelector('button[type="submit"]');
        botao.disabled = true;
        const resultado = await enviar(form.action, dados);
        botao.disabled = false;
        if (!resultado.ok) {
            avisar(frase(resultado.dados, 'Não foi possível cadastrar o feriado.'), true);
            return;
        }
        trocarLista(form.closest('[data-feriados]'), resultado.dados.lista);
        form.reset();
    }

    function trocarLista(secao, html) {
        const lista = secao ? secao.querySelector('[data-feriados-lista]') : null;
        if (lista && typeof html === 'string') {
            lista.innerHTML = html;
        }
    }

    function pedirRemoverFeriado(botao) {
        abrirJanela('ponto-janela-remover-feriado', botao, botao.dataset.info || '', botao.dataset.url, botao.closest('[data-feriados]'));
    }

    // --------------------------------------------------------------- janelas

    function erroDaJanela(fundo, texto) {
        const linha = fundo.querySelector('[data-config-erro]');
        linha.textContent = texto;
        linha.hidden = texto === '';
    }

    function abrirJanela(id, origem, info, url, alvo) {
        const fundo = document.getElementById(id);
        if (!fundo || !url) {
            return;
        }
        fundo.hidden = false;
        janelaAberta = fundo;
        focoAntes = origem;
        alvoDaJanela = alvo;
        erroDaJanela(fundo, '');
        fundo.querySelector('[data-config-info]').textContent = info;
        const confirmar = fundo.querySelector('[data-config-confirmar]');
        confirmar.dataset.url = url;
        confirmar.disabled = false;
        confirmar.focus();
    }

    // Clicar fora não fecha (o desenho não fecha); Esc, o "x" e "Cancelar" fecham. Durante o envio, nada fecha.
    function fecharJanela() {
        if (!janelaAberta || janelaAberta.dataset.ocupada === '1') {
            return;
        }
        janelaAberta.hidden = true;
        janelaAberta = null;
        alvoDaJanela = null;
        if (focoAntes && document.contains(focoAntes)) {
            focoAntes.focus();
        }
        focoAntes = null;
    }

    function prenderFoco(evento) {
        const focaveis = Array.from(janelaAberta.querySelectorAll(FOCAVEIS));
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

    // Removeu: a sede sai da tabela, a lista de feriados volta do servidor, e o aviso diz o que aconteceu.
    async function confirmarRemocao(botao) {
        const fundo = janelaAberta;
        const alvo = alvoDaJanela;
        botao.disabled = true;
        fundo.dataset.ocupada = '1';
        erroDaJanela(fundo, '');
        const resultado = await enviar(botao.dataset.url, new URLSearchParams());
        delete fundo.dataset.ocupada;
        botao.disabled = false;
        if (!resultado.ok) {
            erroDaJanela(fundo, frase(resultado.dados, 'Não foi possível remover. Atualize a página e tente de novo.'));
            return;
        }
        if (botao.dataset.configConfirmar === 'sede') {
            tirarLinha(alvo);
        } else {
            trocarLista(alvo, resultado.dados.lista);
        }
        focoAntes = null;
        fecharJanela();
        avisar(resultado.dados.mensagem || 'Removido.', false);
    }

    // ------------------------------------------------------------ delegação

    const ACOES_DO_CLIQUE = [
        ['[data-sede-adicionar]', adicionarSede],
        ['[data-sede-posicao]', usarPosicao],
        ['[data-sede-remover]', pedirRemoverSede],
        ['[data-feriado-remover]', pedirRemoverFeriado],
        ['[data-config-confirmar]', confirmarRemocao],
        ['[data-config-fechar]', fecharJanela],
    ];

    document.addEventListener('click', function (evento) {
        if (!(evento.target instanceof Element)) {
            return;
        }
        for (const [seletor, acao] of ACOES_DO_CLIQUE) {
            const el = evento.target.closest(seletor);
            if (el) {
                acao(el);
                return;
            }
        }
    });

    document.addEventListener('change', function (evento) {
        const linha = evento.target instanceof Element ? evento.target.closest('[data-sede-linha]') : null;
        if (linha && evento.target.matches('input')) {
            gravarSede(linha);
        }
    });

    document.addEventListener('submit', function (evento) {
        if (evento.target instanceof Element && evento.target.matches('[data-feriado-form]')) {
            evento.preventDefault();
            cadastrarFeriado(evento.target);
        }
    });

    document.addEventListener('keydown', function (evento) {
        if (janelaAberta && evento.key === 'Escape') {
            fecharJanela();
        } else if (janelaAberta && evento.key === 'Tab') {
            prenderFoco(evento);
        }
    });

    window.PontoConfiguracoesSedes = { GRAVAR_PRAZO_MS, POSICAO_PRAZO_MS, AVISO_MS };
})();
