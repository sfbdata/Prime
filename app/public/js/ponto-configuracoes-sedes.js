/**
 * Sedes e feriados na aba "Configurações" da tela do Ponto (E6 do desenho, docs/specs/ponto-desenho-0811.md; desenho
 * 16 L253 a L258). O painel chega depois (js/ponto-paineis.js) e pode ser buscado de novo: tudo por delegação no
 * documento, nada preso a um elemento do painel.
 *
 * - Sedes: cada linha grava ao sair do campo (a linha inteira). Uma gravação por linha de cada vez; mudou de novo
 *   durante o envio, grava outra vez no fim (a "Nova sede" não vira duas). O erro aparece na linha de baixo.
 * - Criação da "Nova sede": enquanto vai ao servidor, o lixo da linha fica desligado. Sem resposta no prazo, o servidor
 *   pode ter gravado: a linha mostra o erro, para de gravar e o painel é buscado de novo
 *   (`window.PontoPaineis.recarregar`), para não nascer uma sede duplicada na mudança seguinte.
 * - "Usar minha posição": a posição vem do leitor com prazo do módulo da batida (`PontoBatida.lerPosicaoComPrazo`),
 *   com 6 casas; sem posição, o aviso do desenho.
 * - Feriados: sem data ou nome, o aviso do desenho; gravado, a lista volta do servidor e o formulário limpa.
 * - Remover sede e remover feriado abrem a janela de confirmação (D4); quem remove é o botão da janela. Com a janela
 *   aberta, o foco não sai dela: Tab circula dentro e o foco que cair fora (depois de um clique no fundo) volta.
 *
 * Atributos próprios desta seção: `data-pc-sede-*` e `data-pc-sedes*` (sedes), `data-pc-feriado-*` e
 * `data-pc-feriados*` (feriados), `data-pc-sf-*` (as duas janelas). O `ponto-configuracoes.js` (E7) é de outra etapa.
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
    const CRIACAO_INCERTA = 'Sem resposta do servidor. A lista de sedes vai ser buscada de novo: confira se a sede foi criada.';
    const SEM_POSICAO = 'Localização indisponível neste aparelho.';
    const FALTA_FERIADO = 'Informe a data e o nome do feriado.';
    const CAMPOS_DA_SEDE = ['nome', 'latitude', 'longitude', 'raio'];
    const FOCAVEIS = 'button:not([disabled]), [href], input:not([type="hidden"]), select';

    let janelaAberta = null;
    let focoAntes = null;
    let alvoDaJanela = null;
    let aviso = null;
    let avisoPrazo = null;

    // POST com prazo. Nunca rejeita: devolve se deu certo e o JSON do servidor (ou a frase da falta de resposta, com
    // `semResposta`: o pedido pode ter chegado ao servidor).
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

            return { ok: resposta.ok && dados.ok === true, semResposta: false, dados: dados };
        } catch (erro) {
            return { ok: false, semResposta: true, dados: { mensagem: SEM_RESPOSTA } };
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
        if (abaixo && abaixo.matches('[data-pc-sede-erro]')) {
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
        linha.dataset.pcSedeUrlSalvar = sede.urlSalvar;
        linha.dataset.pcSedeUrlRemover = sede.urlRemover;
        CAMPOS_DA_SEDE.forEach(function (campo) {
            const entrada = linha.querySelector('[name="' + campo + '"]');
            if (entrada !== document.activeElement && linha.dataset.deNovo !== '1') {
                entrada.value = String(sede[campo]);
            }
        });
    }

    // A criação sem resposta pode ter gravado: a linha para de gravar e o painel volta do servidor com a sede, se ela
    // foi criada (sem isso, a mudança seguinte criaria outra).
    function criacaoIncerta(linha) {
        linha.dataset.incerta = '1';
        erroDaLinha(linha, CRIACAO_INCERTA, []);
        avisar(CRIACAO_INCERTA, true);
        const painel = linha.closest('[data-carregar]');
        if (painel && window.PontoPaineis && window.PontoPaineis.recarregar) {
            window.PontoPaineis.recarregar(painel);
        }
    }

    async function gravarSedeUmaVez(linha) {
        const corpo = new URLSearchParams();
        CAMPOS_DA_SEDE.forEach(function (campo) {
            corpo.set(campo, linha.querySelector('[name="' + campo + '"]').value);
        });
        const secao = linha.closest('[data-pc-sedes]');
        const criando = !linha.dataset.pcSedeUrlSalvar;
        // Durante a criação, o lixo fica desligado: tirar a linha agora deixaria a sede gravada sem linha na tela.
        const remover = linha.querySelector('[data-pc-sede-remover]');
        if (criando) {
            remover.disabled = true;
        }
        const resultado = await enviar(linha.dataset.pcSedeUrlSalvar || secao.dataset.pcSedeUrlCriar, corpo);
        if (criando && resultado.semResposta) {
            criacaoIncerta(linha);
            return;
        }
        if (criando) {
            remover.disabled = false;
        }
        if (resultado.ok) {
            erroDaLinha(linha, '', []);
            mostrarGravada(linha, resultado.dados.sede);
            return;
        }
        erroDaLinha(linha, frase(resultado.dados, 'Não foi possível gravar a sede.'), Object.keys(resultado.dados.erros || {}));
    }

    async function gravarSede(linha) {
        if (linha.dataset.incerta === '1') {
            return;
        }
        if (linha.dataset.gravando === '1') {
            linha.dataset.deNovo = '1';
            return;
        }
        linha.dataset.gravando = '1';
        do {
            delete linha.dataset.deNovo;
            await gravarSedeUmaVez(linha);
        } while (linha.dataset.deNovo === '1' && linha.dataset.incerta !== '1' && document.contains(linha));
        delete linha.dataset.gravando;
    }

    // "Nova sede" com o raio da sede nova (o modelo vem do servidor). Vai para o banco na primeira gravação.
    function adicionarSede(botao) {
        const secao = botao.closest('[data-pc-sedes]');
        const modelo = secao.querySelector('template[data-pc-sede-modelo]');
        secao.querySelector('[data-pc-sedes-corpo]').appendChild(modelo.content.cloneNode(true));
        const linhas = secao.querySelectorAll('[data-pc-sede-linha]');
        const nome = linhas[linhas.length - 1].querySelector('[name="nome"]');
        nome.focus();
        nome.select();
    }

    async function usarPosicao(botao) {
        const linha = botao.closest('[data-pc-sede-linha]');
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
        if (abaixo && abaixo.matches('[data-pc-sede-erro]')) {
            abaixo.remove();
        }
        linha.remove();
    }

    // A "Nova sede" que nunca foi gravada sai sem perguntar: não há nada no banco (durante a criação, o lixo está
    // desligado).
    function pedirRemoverSede(botao) {
        const linha = botao.closest('[data-pc-sede-linha]');
        if (botao.disabled) {
            return;
        }
        if (!linha.dataset.pcSedeUrlRemover) {
            tirarLinha(linha);
            return;
        }
        abrirJanela('ponto-janela-remover-sede', botao, linha.querySelector('[name="nome"]').value, linha.dataset.pcSedeUrlRemover, linha);
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
        trocarLista(form.closest('[data-pc-feriados]'), resultado.dados.lista);
        form.reset();
    }

    function trocarLista(secao, html) {
        const lista = secao ? secao.querySelector('[data-pc-feriados-lista]') : null;
        if (lista && typeof html === 'string') {
            lista.innerHTML = html;
        }
    }

    function pedirRemoverFeriado(botao) {
        abrirJanela('ponto-janela-remover-feriado', botao, botao.dataset.pcFeriadoInfo || '', botao.dataset.pcFeriadoUrl, botao.closest('[data-pc-feriados]'));
    }

    // --------------------------------------------------------------- janelas

    function erroDaJanela(fundo, texto) {
        const linha = fundo.querySelector('[data-pc-sf-erro]');
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
        fundo.querySelector('[data-pc-sf-info]').textContent = info;
        const confirmar = fundo.querySelector('[data-pc-sf-confirmar]');
        confirmar.dataset.pcSfUrl = url;
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

    // O foco que cai fora da janela aberta (Tab depois de um clique no fundo, por exemplo) volta ao primeiro botão dela.
    function devolverFoco(evento) {
        if (!janelaAberta || !(evento.target instanceof Node) || janelaAberta.contains(evento.target)) {
            return;
        }
        const primeiro = janelaAberta.querySelector(FOCAVEIS);
        if (primeiro) {
            primeiro.focus();
        }
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
    // Sem janela aberta (ou com o envio em curso), não faz nada.
    async function confirmarRemocao(botao) {
        const fundo = janelaAberta;
        const alvo = alvoDaJanela;
        if (!fundo || !fundo.contains(botao) || fundo.dataset.ocupada === '1') {
            return;
        }
        botao.disabled = true;
        fundo.dataset.ocupada = '1';
        erroDaJanela(fundo, '');
        const resultado = await enviar(botao.dataset.pcSfUrl, new URLSearchParams());
        delete fundo.dataset.ocupada;
        botao.disabled = false;
        if (!resultado.ok) {
            erroDaJanela(fundo, frase(resultado.dados, 'Não foi possível remover. Atualize a página e tente de novo.'));
            return;
        }
        if (botao.dataset.pcSfConfirmar === 'sede') {
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
        ['[data-pc-sede-adicionar]', adicionarSede],
        ['[data-pc-sede-posicao]', usarPosicao],
        ['[data-pc-sede-remover]', pedirRemoverSede],
        ['[data-pc-feriado-remover]', pedirRemoverFeriado],
        ['[data-pc-sf-confirmar]', confirmarRemocao],
        ['[data-pc-sf-fechar]', fecharJanela],
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
        const linha = evento.target instanceof Element ? evento.target.closest('[data-pc-sede-linha]') : null;
        if (linha && evento.target.matches('input')) {
            gravarSede(linha);
        }
    });

    document.addEventListener('submit', function (evento) {
        if (evento.target instanceof Element && evento.target.matches('[data-pc-feriado-form]')) {
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

    document.addEventListener('focusin', devolverFoco);

    window.PontoConfiguracoesSedes = { GRAVAR_PRAZO_MS, POSICAO_PRAZO_MS, AVISO_MS };
})();
