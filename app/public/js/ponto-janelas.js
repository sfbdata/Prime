/**
 * Janelas da E4 do Ponto (desenho 16, L269 a L318; docs/specs/ponto-desenho-0811.md, P44 a P47): "Justificar falta",
 * "Registro de esquecimento" e "Editar justificativa". A moldura (abrir, Esc fecha, clicar fora não fecha, foco preso,
 * linha de erro que some ao editar) é a das janelas da E3, em js/ponto-abas.js (`window.PontoAbas`), que vem ANTES
 * deste arquivo no index. Aqui fica só o que é de cada janela: preencher ao abrir, o calendário de vários dias, as
 * trocas do tipo e do abono parcial e a conferência antes do envio, com a frase na linha de erro do desenho.
 *
 * O envio é o formulário normal (POST e volta para a /ponto): as recusas que só o servidor faz (dia futuro, batida já
 * registrada) chegam no aviso do topo da página. As frases daqui são as mesmas do servidor e do desenho.
 *
 * Tudo por delegação no documento: o "+" da folha e o lápis da aba são trocados depois da batida.
 */
(function () {
    'use strict';

    const MESES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    const SEMANA = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    const TIPO_ESQUECIMENTO = 'esquecimento_registro';
    const TIPO_NAO_JUSTIFICADA = 'falta_nao_justificada';

    const ERRO_DATA_E_MOTIVO = 'Informe a data e o motivo.';
    const ERRO_HORARIO_E_MOTIVO = 'Informe o horário e o motivo.';
    const ERRO_MOTIVO = 'Informe o motivo.';
    const ERRO_TIPO = 'Selecione o tipo de justificativa.';
    const ERRO_ESQUECIDO = 'Informe o tipo e o horário do registro esquecido.';
    const ERRO_ESQUECIDO_UMA_DATA = 'Esquecimento de Registro só pode ser aplicado a uma única data.';
    const ERRO_ABONO_UMA_DATA = 'Abono parcial só pode ser aplicado a uma única data.';
    const ERRO_ABONO_HORARIOS = 'Informe os horários de saída e retorno corretamente para o abono parcial.';

    // Calendário da janela "Justificar falta": o mês à vista e os dias escolhidos (AAAA-MM-DD).
    const calendario = { ano: 0, mes: 0, hoje: '', escolhidos: new Set() };

    function moldura() {
        return window.PontoAbas;
    }

    function doisDigitos(n) {
        return String(n).padStart(2, '0');
    }

    function chaveDoDia(ano, mes, dia) {
        return ano + '-' + doisDigitos(mes + 1) + '-' + doisDigitos(dia);
    }

    function formatarData(chave) {
        const partes = chave.split('-');

        return partes[2] + '/' + partes[1] + '/' + partes[0];
    }

    // Janela aberta de novo (ou volta pelo "Voltar" do navegador): o botão e o fechar voltam a funcionar.
    function liberar(fundo) {
        delete fundo.dataset.ocupada;
        fundo.querySelectorAll('[data-janela-enviar]').forEach(function (botao) { botao.disabled = false; });
    }

    function recusar(evento, form, texto, campo) {
        evento.preventDefault();
        moldura().mostrarErro(form.closest('.pe-janela-fundo'), texto);
        if (campo) {
            campo.focus();
        }
    }

    function enviar(form) {
        const fundo = form.closest('.pe-janela-fundo');
        form.querySelector('[data-janela-enviar]').disabled = true;
        fundo.dataset.ocupada = '1';
    }

    function motivoEmBranco(form) {
        return form.querySelector('[data-janela-motivo]').value.trim() === '';
    }

    // ---- Justificar falta (P44 e P45) ----

    function formJustificar() {
        return document.querySelector('[data-janela-form="justificar"]');
    }

    function datasEscolhidas() {
        return Array.from(calendario.escolhidos).sort();
    }

    function desenharCalendario(form) {
        const raiz = form.querySelector('[data-just-calendario]');
        const grade = raiz.querySelector('[data-cal-grade]');
        const ultimo = new Date(calendario.ano, calendario.mes + 1, 0).getDate();
        const vazios = new Date(calendario.ano, calendario.mes, 1).getDay();
        const pedacos = [];

        SEMANA.forEach(function (nome) { pedacos.push('<span class="pe-cal-sem" aria-hidden="true">' + nome + '</span>'); });
        for (let i = 0; i < vazios; i++) {
            pedacos.push('<span class="pe-cal-vazio" aria-hidden="true"></span>');
        }
        for (let dia = 1; dia <= ultimo; dia++) {
            const chave = chaveDoDia(calendario.ano, calendario.mes, dia);
            const semana = new Date(calendario.ano, calendario.mes, dia).getDay();
            const classes = ['pe-cal-dia'];
            if (semana === 0 || semana === 6) {
                classes.push('pe-cal-dia--fim');
            }
            if (chave === calendario.hoje) {
                classes.push('pe-cal-dia--hoje');
            }
            pedacos.push('<button type="button" class="' + classes.join(' ') + '" data-cal-dia="' + chave + '"'
                + ' aria-label="' + formatarData(chave) + '" aria-pressed="' + (calendario.escolhidos.has(chave) ? 'true' : 'false') + '"'
                + (chave > calendario.hoje ? ' disabled' : '') + '>' + dia + '</button>');
        }
        grade.innerHTML = pedacos.join('');
        raiz.querySelector('[data-cal-mes]').textContent = MESES[calendario.mes] + ' ' + calendario.ano;
        // Mês futuro não tem dia a justificar.
        raiz.querySelector('[data-cal-seguinte]').disabled = chaveDoDia(calendario.ano, calendario.mes, 1) >= calendario.hoje.slice(0, 8) + '01';
        atualizarEscolhidos(form);
    }

    function atualizarEscolhidos(form) {
        const datas = datasEscolhidas();
        form.querySelector('[data-just-datas]').value = datas.join(',');
        form.querySelector('[data-cal-escolhidos]').textContent = datas.length === 0 ? 'nenhum' : datas.map(formatarData).join(', ');
    }

    function andarMes(form, passo) {
        const alvo = new Date(calendario.ano, calendario.mes + passo, 1);
        if (passo > 0 && chaveDoDia(alvo.getFullYear(), alvo.getMonth(), 1) > calendario.hoje) {
            return;
        }
        calendario.ano = alvo.getFullYear();
        calendario.mes = alvo.getMonth();
        desenharCalendario(form);
    }

    function trocarDia(botao) {
        const form = botao.closest('form');
        const chave = botao.dataset.calDia;
        if (calendario.escolhidos.has(chave)) {
            calendario.escolhidos.delete(chave);
        } else {
            calendario.escolhidos.add(chave);
        }
        botao.setAttribute('aria-pressed', calendario.escolhidos.has(chave) ? 'true' : 'false');
        atualizarEscolhidos(form);
        moldura().mostrarErro(form.closest('.pe-janela-fundo'), '');
    }

    // Os mesmos blocos que o tipo liga e desliga na ficha do gestor (_justificativa_campos).
    function aplicarTipoJustificar(form) {
        const tipo = form.querySelector('[data-just-tipo]').value;
        const naoJustificada = tipo === TIPO_NAO_JUSTIFICADA;
        const esquecimento = tipo === TIPO_ESQUECIMENTO;
        form.querySelector('[data-just-nao-justificada]').hidden = !naoJustificada;
        form.querySelector('[data-just-abono]').hidden = naoJustificada || esquecimento;
        form.querySelector('[data-just-esquecido]').hidden = !esquecimento;
        if (naoJustificada || esquecimento) {
            form.querySelector('[data-just-abono-marca]').checked = false;
            form.querySelector('[data-just-abono-horario]').hidden = true;
        }
        if (!esquecimento) {
            form.querySelector('[data-just-esquecido-tipo]').value = '';
            form.querySelector('[data-just-esquecido-hora]').value = '';
        }
    }

    // Abono parcial só para um dia: com mais de um escolhido, a marca não fica e a frase vai para a linha de erro.
    function trocarAbonoJustificar(marca) {
        const form = marca.closest('form');
        const horario = form.querySelector('[data-just-abono-horario]');
        if (marca.checked && datasEscolhidas().length > 1) {
            marca.checked = false;
            horario.hidden = true;
            moldura().mostrarErro(form.closest('.pe-janela-fundo'), ERRO_ABONO_UMA_DATA + ' Desmarque as datas extras no calendário.');
            return;
        }
        horario.hidden = !marca.checked;
    }

    // Abre com hoje já marcado (o desenho começa com a data de hoje), no mês de hoje.
    function abrirJustificar(botao) {
        const form = formJustificar();
        const fundo = form ? moldura().abrirJanela('ponto-janela-justificar', botao) : null;
        if (!fundo) {
            return;
        }
        liberar(fundo);
        form.reset();
        calendario.hoje = form.querySelector('[data-just-calendario]').dataset.hoje;
        calendario.ano = Number(calendario.hoje.slice(0, 4));
        calendario.mes = Number(calendario.hoje.slice(5, 7)) - 1;
        calendario.escolhidos = new Set([calendario.hoje]);
        form.querySelector('[data-just-abono-horario]').hidden = true;
        aplicarTipoJustificar(form);
        desenharCalendario(form);
        (form.querySelector('[data-cal-dia][aria-pressed="true"]') || form.querySelector('[data-just-tipo]')).focus();
    }

    function conferirJustificar(evento, form) {
        const datas = datasEscolhidas();
        const tipo = form.querySelector('[data-just-tipo]');
        const abono = form.querySelector('[data-just-abono-marca]').checked;
        const inicio = form.querySelector('[data-just-abono-inicio]');
        const fim = form.querySelector('[data-just-abono-fim]');
        const esquecido = form.querySelector('[data-just-esquecido-tipo]');
        const hora = form.querySelector('[data-just-esquecido-hora]');

        if (datas.length === 0 || motivoEmBranco(form)) {
            return recusar(evento, form, ERRO_DATA_E_MOTIVO, datas.length === 0 ? null : form.querySelector('[data-janela-motivo]'));
        }
        if (tipo.value === '') {
            return recusar(evento, form, ERRO_TIPO, tipo);
        }
        if (tipo.value === TIPO_ESQUECIMENTO && datas.length > 1) {
            return recusar(evento, form, ERRO_ESQUECIDO_UMA_DATA, null);
        }
        if (tipo.value === TIPO_ESQUECIMENTO && (esquecido.value === '' || hora.value === '')) {
            return recusar(evento, form, ERRO_ESQUECIDO, esquecido.value === '' ? esquecido : hora);
        }
        if (abono && datas.length > 1) {
            return recusar(evento, form, ERRO_ABONO_UMA_DATA, null);
        }
        if (abono && (inicio.value === '' || fim.value === '' || inicio.value >= fim.value)) {
            return recusar(evento, form, ERRO_ABONO_HORARIOS, inicio);
        }
        enviar(form);
    }

    // ---- Registro de esquecimento (P46) ----

    // O "+" da folha (e quem o clica: "Enviar registro de esquecimento" da aba, "Regularizar" da faixa e o
    // `?esquecimento=` do cabeçalho). Data e batida vêm do "+"; a pessoa escreve o horário e o motivo.
    function abrirEsquecimento(botao) {
        const fundo = moldura().abrirJanela('ponto-janela-esquecimento', botao);
        if (!fundo) {
            return;
        }
        const form = fundo.querySelector('form');
        liberar(fundo);
        form.querySelector('#esquecimentoData').value = botao.dataset.esquecimentoDia;
        form.querySelector('#esquecimentoCampo').value = botao.dataset.esquecimentoCampo;
        form.querySelector('#esquecimentoHora').value = '';
        form.querySelector('[data-janela-motivo]').value = '';
        fundo.querySelector('[data-janela-info]').textContent = formatarData(botao.dataset.esquecimentoDia) + ' · ' + (botao.dataset.esquecimentoRotulo || '');
        form.querySelector('#esquecimentoHora').focus();
    }

    function conferirEsquecimento(evento, form) {
        const hora = form.querySelector('#esquecimentoHora');
        if (hora.value === '' || motivoEmBranco(form)) {
            return recusar(evento, form, ERRO_HORARIO_E_MOTIVO, hora.value === '' ? hora : form.querySelector('[data-janela-motivo]'));
        }
        enviar(form);
    }

    // ---- Editar justificativa (P47) ----

    function aplicarTipoEdicao(form, limpar) {
        const esquecimento = form.querySelector('#editarJustificativaTipo').value === TIPO_ESQUECIMENTO;
        form.querySelector('#editarBlocoRegistroEsquecido').hidden = !esquecimento;
        form.querySelector('#editarBlocoAbonoParcial').hidden = esquecimento;
        if (esquecimento) {
            form.querySelector('#editarAbonoParcial').checked = false;
        }
        if (esquecimento && limpar) {
            form.querySelector('#editarTipoRegEsquecido').value = '';
            form.querySelector('#editarHoraRegEsquecido').value = '';
        }
        form.querySelector('#editarBlocoHorario').hidden = !form.querySelector('#editarAbonoParcial').checked;
    }

    // O lápis da linha em análise traz os dados dela. Lote já analisado não troca de atestado: o campo fica
    // `disabled`, e não só escondido, senão um arquivo escolhido numa abertura anterior iria junto no POST.
    function abrirEdicao(botao) {
        const fundo = moldura().abrirJanela('ponto-janela-editar', botao);
        if (!fundo) {
            return;
        }
        const form = fundo.querySelector('form');
        const dados = botao.dataset;
        const esquecimento = (dados.tipo || '') === TIPO_ESQUECIMENTO;
        const podeTrocarAnexo = dados.podeTrocarAnexo === '1';
        const anexo = form.querySelector('#editarAnexo');
        liberar(fundo);
        form.action = dados.url;
        form.querySelector('#editarJustificativaToken').value = dados.csrf;
        form.querySelector('#editarJustificativaTipo').value = dados.tipo || '';
        form.querySelector('#editarTipoRegEsquecido').value = dados.tipoRegEsquecido || '';
        form.querySelector('#editarHoraRegEsquecido').value = dados.horaRegEsquecido || '';
        form.querySelector('#editarAbonoParcial').checked = dados.abonoParcial === '1' && !esquecimento;
        form.querySelector('#editarHoraInicio').value = dados.horaInicio || '';
        form.querySelector('#editarHoraFim').value = dados.horaFim || '';
        form.querySelector('[data-janela-motivo]').value = dados.motivo || '';
        anexo.value = '';
        anexo.disabled = !podeTrocarAnexo;
        form.querySelector('#editarBlocoAnexo').hidden = !podeTrocarAnexo;
        form.querySelector('#editarAvisoAnexoAnalisado').hidden = podeTrocarAnexo;
        aplicarTipoEdicao(form, false);
        form.querySelector('#editarJustificativaTipo').focus();
    }

    function conferirEdicao(evento, form) {
        const motivo = form.querySelector('[data-janela-motivo]');
        const esquecimento = form.querySelector('#editarJustificativaTipo').value === TIPO_ESQUECIMENTO;
        const esquecido = form.querySelector('#editarTipoRegEsquecido');
        const hora = form.querySelector('#editarHoraRegEsquecido');
        const inicio = form.querySelector('#editarHoraInicio');
        const fim = form.querySelector('#editarHoraFim');

        if (motivo.value.trim() === '') {
            return recusar(evento, form, ERRO_MOTIVO, motivo);
        }
        if (esquecimento && (esquecido.value === '' || hora.value === '')) {
            return recusar(evento, form, ERRO_ESQUECIDO, esquecido.value === '' ? esquecido : hora);
        }
        if (form.querySelector('#editarAbonoParcial').checked && (inicio.value === '' || fim.value === '' || inicio.value >= fim.value)) {
            return recusar(evento, form, ERRO_ABONO_HORARIOS, inicio);
        }
        enviar(form);
    }

    // ---- Delegação ----

    const ACOES_DO_CLIQUE = [
        ['[data-janela-justificar]', abrirJustificar],
        ['[data-esquecimento-dia]', abrirEsquecimento],
        ['[data-justificativa-editar]', abrirEdicao],
        ['[data-cal-dia]', trocarDia],
        ['[data-cal-anterior]', function (el) { andarMes(el.closest('form'), -1); }],
        ['[data-cal-seguinte]', function (el) { andarMes(el.closest('form'), 1); }],
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

    document.addEventListener('change', function (evento) {
        const alvo = evento.target;
        if (alvo.matches('[data-just-tipo]')) {
            aplicarTipoJustificar(alvo.closest('form'));
        } else if (alvo.matches('[data-just-abono-marca]')) {
            trocarAbonoJustificar(alvo);
        } else if (alvo.matches('#editarJustificativaTipo')) {
            aplicarTipoEdicao(alvo.closest('form'), true);
        } else if (alvo.matches('#editarAbonoParcial')) {
            alvo.closest('form').querySelector('#editarBlocoHorario').hidden = !alvo.checked;
        }
    });

    const CONFERENCIAS = {
        justificar: conferirJustificar,
        esquecimento: conferirEsquecimento,
        editar: conferirEdicao,
    };

    document.addEventListener('submit', function (evento) {
        const form = evento.target;
        const conferir = CONFERENCIAS[form.dataset.janelaForm];
        if (conferir) {
            conferir(evento, form);
        }
    });

    // Voltou pelo "Voltar" do navegador com a página guardada: a janela que estava enviando não fica travada.
    window.addEventListener('pageshow', function (evento) {
        if (evento.persisted) {
            document.querySelectorAll('.pe-janela-fundo[data-ocupada]').forEach(liberar);
        }
    });
})();
