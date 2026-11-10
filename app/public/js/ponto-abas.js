/**
 * Cartão de abas da tela do Ponto (E3 do desenho, docs/specs/ponto-desenho-0811.md): a troca entre "Pendências" e
 * "Minhas Justificativas", o botão "Enviar registro de esquecimento" da pendência e as janelas de excluir (P41 e D4)
 * e de abonar ou recusar a própria justificativa (P43 a). A moldura das janelas (abrir, Esc, foco preso, linha de erro)
 * serve também às janelas da E4 ("Justificar falta", "Registro de esquecimento" e "Editar justificativa"), que moram em
 * js/ponto-janelas.js e chamam `abrirJanela`, `fecharJanela` e `mostrarErro` daqui.
 *
 * Mora fora do index porque a tela não tem `fetch` sem prazo (BatidaNaoTravaNoGpsTest): a exclusão tem prazo próprio.
 * Tudo por delegação no documento: o painel das pendências e a aba dele são trocados depois da batida
 * (REGIOES_TROCADAS no index) e perderiam um ouvinte preso a eles.
 *
 * Exposto em `window.PontoAbas` (este arquivo vem antes do js/ponto-janelas.js no index).
 */
(function () {
    'use strict';

    // Prazo da exclusão: sem resposta até aqui, a janela avisa e deixa tentar de novo.
    const EXCLUIR_PRAZO_MS = 15000;
    // A lista que ficou vazia depois de excluir: a mesma frase do servidor (_abas_justificativas.html.twig).
    const TEXTO_SEM_JUSTIFICATIVAS = 'Nenhuma justificativa enviada nesta competência.';
    // A aba que abre ao entrar (P37) não vai para o endereço.
    const ABA_PADRAO = 'pendencias';
    // Campo dentro de bloco escondido (o abono parcial fechado, por exemplo) não entra na volta do Tab.
    const FOCAVEIS = 'button:not([disabled]), select:not([disabled]), textarea:not([disabled]), [href], input:not([type="hidden"]):not([disabled])';

    let janelaAberta = null;
    let focoAntes = null;
    // A linha da lixeira que abriu a janela de excluir: é ela que sai quando o servidor confirma.
    let linhaDaExclusao = null;

    function cartao() {
        return document.querySelector('section.pe-abas');
    }

    function abasDoCartao() {
        const sec = cartao();
        return sec ? Array.from(sec.querySelectorAll('[role="tab"]')) : [];
    }

    // Grava a aba no endereço e no formulário do mês: a tela buscada depois da batida e a troca do mês voltam nela.
    function gravarAba(nome) {
        const url = new URL(window.location.href);
        if (nome === ABA_PADRAO) {
            url.searchParams.delete('aba');
        } else {
            url.searchParams.set('aba', nome);
        }
        window.history.replaceState(window.history.state, '', url.toString());
        /* As janelas de esquecimento e de justificativa voltam para a aba aberta (desenho L768). */
        document.querySelectorAll('input[data-aba-da-volta]').forEach(function (campo) { campo.value = nome; });

        const form = document.getElementById('competencia-ponto-index-form');
        if (!form) {
            return;
        }
        let campo = form.querySelector('input[name="aba"]');
        if (nome === ABA_PADRAO) {
            if (campo) {
                campo.remove();
            }
            return;
        }
        if (!campo) {
            campo = document.createElement('input');
            campo.type = 'hidden';
            campo.name = 'aba';
            form.appendChild(campo);
        }
        campo.value = nome;
    }

    function abrirAba(nome, focar) {
        abasDoCartao().forEach(function (aba) {
            const escolhida = aba.dataset.aba === nome;
            aba.setAttribute('aria-selected', escolhida ? 'true' : 'false');
            aba.tabIndex = escolhida ? 0 : -1;
            const painel = document.getElementById(aba.getAttribute('aria-controls'));
            if (painel) {
                painel.hidden = !escolhida;
            }
            if (escolhida && focar) {
                aba.focus();
            }
        });
        gravarAba(nome);
        avisarAbaAberta(nome);
    }

    // As abas de gestão (Equipe, Configurações) buscam o painel na primeira vez que abrem, e a Auditoria a cada vez:
    // js/ponto-paineis.js ouve.
    function avisarAbaAberta(nome) {
        const aba = abasDoCartao().find(function (el) { return el.dataset.aba === nome; });
        const painel = aba ? document.getElementById(aba.getAttribute('aria-controls')) : null;
        if (painel) {
            document.dispatchEvent(new CustomEvent('ponto:aba-aberta', { detail: { nome: nome, painel: painel } }));
        }
    }

    // Setas, Home e End na barra de abas (padrão de abas do WAI-ARIA).
    function navegarNasAbas(evento) {
        const abas = abasDoCartao();
        const atual = abas.indexOf(evento.target);
        const destino = {
            ArrowRight: atual + 1,
            ArrowLeft: atual - 1,
            Home: 0,
            End: abas.length - 1,
        }[evento.key];
        if (atual < 0 || destino === undefined) {
            return;
        }
        evento.preventDefault();
        abrirAba(abas[(destino + abas.length) % abas.length].dataset.aba, true);
    }

    // Mesmo caminho do "Regularizar" da faixa (P14): com o mês da pendência aberto, o "+" da folha; senão, o link.
    function enviarPendencia(botao) {
        const alvo = document.querySelector('[data-esquecimento-dia="' + botao.dataset.dia + '"][data-esquecimento-campo="' + botao.dataset.tipo + '"]');
        if (alvo) {
            alvo.scrollIntoView({ block: 'center' });
            alvo.click();
            return;
        }
        window.location.href = botao.dataset.url;
    }

    function mostrarErro(fundo, texto) {
        const linha = fundo.querySelector('[data-janela-erro]');
        linha.textContent = texto;
        linha.hidden = texto === '';
    }

    function abrirJanela(id, origem) {
        const fundo = document.getElementById(id);
        if (!fundo) {
            return null;
        }
        fundo.hidden = false;
        janelaAberta = fundo;
        focoAntes = origem;
        mostrarErro(fundo, '');
        const info = fundo.querySelector('[data-janela-info]');
        if (info) {
            info.textContent = (origem && origem.dataset.info) || '';
        }

        return fundo;
    }

    // Clicar fora não fecha (o desenho não fecha); Esc, o "x" e "Cancelar" fecham. Durante o envio, nada fecha.
    function fecharJanela() {
        if (!janelaAberta || janelaAberta.dataset.ocupada === '1') {
            return;
        }
        janelaAberta.hidden = true;
        janelaAberta = null;
        if (focoAntes && document.contains(focoAntes)) {
            focoAntes.focus();
        }
        focoAntes = null;
    }

    // O foco não sai da janela aberta com Tab (aria-modal).
    function prenderFoco(evento) {
        const focaveis = Array.from(janelaAberta.querySelectorAll(FOCAVEIS)).filter(function (el) { return !el.closest('[hidden]'); });
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

    function abrirExclusao(botao) {
        const fundo = abrirJanela('ponto-janela-excluir', botao);
        if (!fundo) {
            return;
        }
        linhaDaExclusao = botao.closest('tr.pe-just-lin');
        const confirmar = fundo.querySelector('[data-excluir-confirmar]');
        confirmar.dataset.url = botao.dataset.excluirUrl;
        confirmar.disabled = false;
        confirmar.focus();
    }

    // Só para leitor de tela (`[data-abas-aviso]`, aria-live), no molde do aviso da troca de escala da E5.
    function anunciar(texto) {
        const sec = cartao();
        const voz = sec ? sec.querySelector('[data-abas-aviso]') : null;
        if (voz) {
            voz.textContent = texto;
        }
    }

    // O número da aba (o `span.pe-aba-num` do botão) acompanha a lista; no zero some, como o servidor faz.
    function baixarNumeroDaAba(aba, valor) {
        const numero = aba ? aba.querySelector('.pe-aba-num') : null;
        if (!numero) {
            return;
        }
        if (valor > 0) {
            numero.textContent = String(valor);
        } else {
            numero.remove();
        }
    }

    // A justificativa em análise de quem pode analisar a própria (a linha tem "Abonar" e "Recusar", P43 a) conta no
    // número da aba Equipe (JustificativasAAnalisarDaEquipe). O painel da Equipe já buscado fica velho: é buscado de
    // novo quando a aba abrir (js/ponto-paineis.js busca o painel sem `data-estado`).
    function tirarDaEquipe() {
        const aba = document.getElementById('ponto-aba-equipe');
        const numero = aba ? aba.querySelector('.pe-aba-num') : null;
        if (numero) {
            baixarNumeroDaAba(aba, (parseInt(numero.textContent, 10) || 0) - 1);
        }
        const painel = document.getElementById('ponto-painel-equipe');
        if (painel && painel.dataset.estado === 'pronto') {
            delete painel.dataset.estado;
        }
    }

    // Excluída (P41; spec E3, "sem recarregar"): a linha sai na hora e o número da aba acompanha; sem nenhuma, a frase
    // da lista vazia, a mesma do servidor. O leitor de tela ouve "Justificativa de dd/mm/aaaa excluída." (o flash de antes). A Folha (o selo do dia e o "+" do esquecimento) e as pendências mudam junto:
    // o index as busca pelo caminho de depois da batida (`ponto:justificativa-excluida`), sem recarregar a página.
    function tirarLinhaExcluida(linha) {
        const contavaNaEquipe = linha.querySelector('[data-analisar]') !== null;
        const data = linha.querySelector('.pe-just-data');
        const corpo = linha.parentElement;
        linha.remove();
        const restantes = corpo.querySelectorAll('tr.pe-just-lin').length;
        baixarNumeroDaAba(document.getElementById('ponto-aba-justificativas'), restantes);
        if (restantes === 0) {
            const vazio = document.createElement('div');
            vazio.className = 'pe-abas-vazio';
            vazio.textContent = TEXTO_SEM_JUSTIFICATIVAS;
            corpo.closest('.pe-abas-rolagem').replaceWith(vazio);
        }
        if (contavaNaEquipe) {
            tirarDaEquipe();
        }
        anunciar(data ? 'Justificativa de ' + data.textContent.trim() + ' excluída.' : 'Justificativa excluída.');
        document.dispatchEvent(new CustomEvent('ponto:justificativa-excluida'));
    }

    // POST com o token da página (o base.html.twig põe o X-CSRF-Token). Deu certo: a janela fecha e a linha sai, sem
    // recarregar (a rota não deixa flash); sem a linha na tela, a aba abre de novo do servidor.
    // Recusa (403, 404, 422) ou falta de resposta: a frase fica na linha de erro da janela.
    async function confirmarExclusao(botao) {
        const fundo = janelaAberta;
        const linha = linhaDaExclusao;
        const aborto = new AbortController();
        const prazo = setTimeout(function () { aborto.abort(); }, EXCLUIR_PRAZO_MS);
        botao.disabled = true;
        fundo.dataset.ocupada = '1';
        mostrarErro(fundo, '');
        let falha = 'Sem resposta do servidor. Confira a conexão e tente de novo.';
        try {
            const resposta = await fetch(botao.dataset.url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: aborto.signal,
            });
            const dados = await resposta.json().catch(function () { return {}; });
            if (resposta.ok && dados.ok === true) {
                if (!linha || !document.contains(linha)) {
                    window.location.assign(cartao().dataset.urlVolta);
                    return;
                }
                delete fundo.dataset.ocupada;
                // O foco volta para a aba das justificativas: a lixeira que abriu a janela saiu com a linha.
                focoAntes = document.getElementById('ponto-aba-justificativas');
                fecharJanela();
                tirarLinhaExcluida(linha);
                return;
            }
            falha = dados.mensagem || 'Não foi possível excluir a justificativa. Atualize a página e tente de novo.';
        } catch (erro) {
            // sem rede ou prazo estourado: a frase padrão acima
        } finally {
            clearTimeout(prazo);
        }
        delete fundo.dataset.ocupada;
        botao.disabled = false;
        mostrarErro(fundo, falha);
    }

    function abrirAnalise(botao) {
        const fundo = abrirJanela('ponto-janela-analise', botao);
        if (!fundo) {
            return;
        }
        const form = fundo.querySelector('[data-analise-form]');
        const recusar = botao.dataset.analisar === 'recusar';
        const titulo = recusar ? 'Recusar justificativa' : 'Abonar justificativa';
        form.action = botao.dataset.url;
        form.dataset.recusar = recusar ? '1' : '0';
        form.setAttribute('aria-label', titulo);
        form.querySelector('input[name="_token"]').value = botao.dataset.token;
        form.querySelector('[data-analise-titulo]').textContent = titulo;
        const acao = form.querySelector('[data-analise-acao]');
        acao.textContent = recusar ? 'Recusar' : 'Abonar';
        acao.disabled = false;
        const campo = form.querySelector('textarea');
        campo.value = '';
        campo.focus();
    }

    // Recusar sem motivo não sai da janela (a ficha do gestor e o servidor pedem o mesmo).
    function enviarAnalise(evento) {
        const form = evento.target;
        const fundo = form.closest('.pe-janela-fundo');
        if (form.dataset.recusar === '1' && form.querySelector('textarea').value.trim() === '') {
            evento.preventDefault();
            mostrarErro(fundo, 'Informe o motivo da recusa.');
            form.querySelector('textarea').focus();
            return;
        }
        form.querySelector('[data-analise-acao]').disabled = true;
        fundo.dataset.ocupada = '1';
    }

    const ACOES_DO_CLIQUE = [
        ['section.pe-abas [role="tab"]', function (el) { abrirAba(el.dataset.aba, false); }],
        ['[data-pendencia-enviar]', enviarPendencia],
        ['[data-excluir-url]', abrirExclusao],
        ['[data-analisar]', abrirAnalise],
        ['[data-excluir-confirmar]', confirmarExclusao],
        ['[data-janela-fechar]', fecharJanela],
    ];

    document.addEventListener('click', function (evento) {
        for (const [seletor, acao] of ACOES_DO_CLIQUE) {
            const el = evento.target.closest(seletor);
            if (el) {
                acao(el);
                return;
            }
        }
    });

    document.addEventListener('keydown', function (evento) {
        if (janelaAberta && evento.key === 'Escape') {
            fecharJanela();
        } else if (janelaAberta && evento.key === 'Tab') {
            prenderFoco(evento);
        } else if (evento.target.closest && evento.target.closest('section.pe-abas [role="tablist"]')) {
            navegarNasAbas(evento);
        }
    });

    document.addEventListener('submit', function (evento) {
        if (evento.target.matches('[data-analise-form]')) {
            enviarAnalise(evento);
        }
    });

    // Como no desenho (o `setF` apaga o `fErro`): escreveu ou mudou um campo da janela aberta, a frase de erro sai.
    function apagarErroAoEditar(evento) {
        if (janelaAberta && janelaAberta.contains(evento.target) && janelaAberta.dataset.ocupada !== '1') {
            mostrarErro(janelaAberta, '');
        }
    }
    document.addEventListener('input', apagarErroAoEditar);
    document.addEventListener('change', apagarErroAoEditar);

    // Entrou com `?aba=justificativas` (volta da análise, ou o plano B da exclusão): o formulário do mês leva a aba junto.
    function iniciar() {
        const escolhida = abasDoCartao().find(function (aba) { return aba.getAttribute('aria-selected') === 'true'; });
        if (escolhida) {
            gravarAba(escolhida.dataset.aba);
            avisarAbaAberta(escolhida.dataset.aba);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }

    window.PontoAbas = { EXCLUIR_PRAZO_MS, abrirAba, abrirJanela, fecharJanela, mostrarErro };
})();
