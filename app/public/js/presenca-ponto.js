/* =============================================================================
   presenca-ponto.js: atalho de ponto na sua linha do painel de presença (desenho 03 VS 18.10.26, dc `pontoDe` e
   `baterPonto`, L.674-681; spec docs/specs/presenca-e-ponto-no-cabecalho.md §3). Ponto eletrônico: risco ALTO.

   Não há regra nova aqui. O estado vem de GET /ponto/estado-do-dia (só leitura, a escolha da folha) e a batida é o
   POST /ponto/batida de sempre, que decide tudo no servidor (hora, GPS, cerca, home office, interjornada). A posição,
   a cerca permissiva, o limite de atraso, o envio e a leitura da resposta são os da tela do ponto, num lugar só:
   public/js/ponto-batida.js (window.PontoBatida). DOM só com textContent.
   ============================================================================= */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-presenca-cabecalho][data-ponto-estado]');
    if (!raiz || !window.PontoBatida) { return; }
    var urlEstado = raiz.getAttribute('data-ponto-estado');
    var urlBatida = raiz.getAttribute('data-ponto-batida');
    var urlPonto = raiz.getAttribute('data-ponto-index');
    var PB = window.PontoBatida;

    // A releitura do estado no clique tem prazo próprio (spec F1): espera nova no fluxo da batida sem prazo travaria o
    // item em "registrando". A posição, 10 s (o teto de BatidaNaoTravaNoGpsTest): o atalho não tem posição prévia.
    var RELEITURA_PRAZO_MS = 5000;
    var POSICAO_PRAZO_MS = 10000;
    var AVISO_SEM_POSICAO = 'Não foi possível obter a sua localização; permita a localização e tente de novo.';
    var AVISO_MUDOU = 'O ponto mudou em outra tela; confira antes de bater.';
    var AVISO_SEM_CONFERIR = 'Não deu para conferir o seu ponto agora. Nada foi enviado; tente de novo em instantes.';
    var AVISO_NAO_ENTROU = 'A batida não foi registrada: o servidor recusou o pedido antes de gravar. Atualize a página, entre de novo se precisar, e repita.';
    var AVISO_SEM_CONFIRMACAO = 'Não recebemos a confirmação do servidor e a batida PODE ter sido registrada. Abra o ponto e confira ANTES de bater de novo.';
    var AVISO_SEM_REDE = 'Você está sem internet e não dá para saber se a batida saiu. Quando a conexão voltar, abra o ponto e confira antes de bater de novo.';
    var AVISO_ATRASADO = 'Demorou demais para enviar e a batida não saiu, para não gravar a hora errada. Tente de novo.';

    var estado = null;            // a última resposta de /ponto/estado-do-dia
    var envioEmAndamento = false; // trava do clique: cobre a posição, a releitura e o POST (spec F2)
    var aoMudar = function () {};

    function avisar(texto) {
        if (window.bjAviso) { window.bjAviso(texto); return; }
        if (window.ExpAviso) { window.ExpAviso(texto); }
    }

    function lerEstado(prazoMs) {
        var aborto = new AbortController();
        var prazo = setTimeout(function () { aborto.abort(); }, prazoMs);
        return fetch(urlEstado, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal: aborto.signal })
            .then(function (r) {
                if (!r.ok || r.redirected || (r.headers.get('Content-Type') || '').indexOf('application/json') === -1) { return null; }
                return r.json();
            })
            .catch(function () { return null; })
            .finally(function () { clearTimeout(prazo); });
    }

    /* dc `pontoDe`, mais o intervalo que o dc não tem (decisão Q1). O "Regularizar ponto de ontem" do dc não é mais
       estado da linha: esquecimento nunca trava (spec ponto-esquecimento-nao-trava R3), e ele vem em `aviso()`. */
    function linha() { return linhaDe(estado); }
    function linhaDe(e) {
        if (!e) { return null; }
        var h = e.hoje || {};
        switch (e.estado) {
            case 'bater_entrada':
                return { texto: 'Bater ponto', cor: '#0a6fa0', icone: 'bi-fingerprint', acao: 'entrada', dica: 'Clique para registrar a entrada' };
            case 'em_intervalo':
                return { texto: 'Em intervalo desde ' + h.repouso, cor: '#5b7383', icone: 'bi-pause-circle', acao: '', dica: '' };
            case 'em_jornada':
                return { texto: 'Em jornada desde ' + h.entrada, cor: '#1e7b45', icone: 'bi-play-circle', acao: 'saida', dica: 'Clique para registrar a saída' };
            case 'jornada_concluida':
                return { texto: 'Jornada ' + h.entrada + ' às ' + h.saida, cor: '#5b7383', icone: 'bi-check2-circle', acao: '', dica: '' };
            default:
                return null;
        }
    }

    /* O aviso de ontem (R3): fica ao lado da linha, que continua oferecendo a batida, e leva ao pedido de
       esquecimento. Null sem pendência de ontem. */
    function aviso() {
        if (!estado || estado.aviso !== 'regularizar_ontem' || !estado.ontemSemSaida) { return null; }

        return { texto: 'Regularizar ponto de ontem', cor: '#c76a12', icone: 'bi-exclamation-triangle', dica: 'Clique para informar a saída de ontem' };
    }

    function atualizar() {
        return lerEstado(RELEITURA_PRAZO_MS).then(function (novo) {
            if (novo) { estado = novo; aoMudar(); }
        });
    }

    /* Os avisos que a batida aceita trouxe (R6): seguem a confirmação, no mesmo aviso, sem virar erro. */
    function avisosDe(resultado) {
        var avisos = resultado && resultado.data && resultado.data.avisos;
        if (!Array.isArray(avisos)) { return ''; }

        return avisos.map(function (a) { return a && a.texto ? ' ' + a.texto : ''; }).join('');
    }

    function horaDe(resultado) {
        var hora = resultado && resultado.data && resultado.data.data && resultado.data.data.hora;
        return hora ? String(hora).slice(0, 5) : '';
    }

    /* A frase do dc e, com a validação de interjornada ligada no escritório, o descanso que o servidor vai cobrar na
       próxima entrada, com o valor configurado (decisão do dono, 19/10). */
    function descanso(min) {
        var h = Math.floor(min / 60);
        var m = min % 60;

        return (h ? h + ' h' : '') + (h && m ? ' ' : '') + (m ? m + ' min' : '');
    }
    function confirmarSaida(base) {
        var texto = 'Registrar a saída agora (' + new Date().toTimeString().slice(0, 5) + ')?';
        if (base.interjornadaMinimaMin) { texto += ' A próxima entrada só será aceita depois de ' + descanso(base.interjornadaMinimaMin) + ' de descanso.'; }

        return window.confirm(texto);
    }

    /* "Regularizar ponto de ontem": leva à folha do mês de ontem, que abre o "+" da saída daquele dia (o pedido de
       justificativa de esquecimento que já existe, aprovado pelo gestor). Nenhuma batida sai daqui. */
    function regularizarOntem(dia) {
        window.location.href = urlPonto + '?competencia=' + dia.slice(0, 7) + '&esquecimento=' + dia + ':saida';
    }

    function mensagemDaCerca(onde) {
        var nome = onde.sede && onde.sede.nome ? onde.sede.nome : 'mais próxima';

        return 'Não registrou: você está a ' + Math.round(onde.distancia) + ' m da sede ' + nome
            + ', fora da área permitida. Se hoje é trabalho fora da sede, peça a liberação do dia ao gestor.';
    }

    /* A sequência do clique, nesta ordem (spec F2). `base` é o retrato do estado no clique: a ação, o home office,
       as sedes e a comparação da releitura saem dele, nunca do `estado`, que o relógio de fundo reescreve no meio do
       clique (senão a releitura compararia o novo com o novo e uma batida velha sairia). */
    async function bater() {
        var base = estado;
        var atual = linhaDe(base);
        if (envioEmAndamento || !atual || !atual.acao) { return; }
        envioEmAndamento = true;   // (1)
        var sucesso = false;
        try {
            aoMudar();   // mostra o item ocupado
            if (atual.acao === 'saida' && !confirmarSaida(base)) { return; }   // (2) cancelar destrava no finally
            var momentoDoToque = performance.now();                        // (3) depois da confirmação
            var payload = { tipo: atual.acao };
            if (!base.homeOffice) {
                var position = await PB.lerPosicaoComPrazo(POSICAO_PRAZO_MS, function () {});   // (4)
                if (!position) { avisar(AVISO_SEM_POSICAO); return; }
                payload.latitude = position.coords.latitude;
                payload.longitude = position.coords.longitude;
                payload.precisaoGps = position.coords.accuracy;
                var onde = PB.avaliarPosicao(                                                    // (5)
                    { latitude: payload.latitude, longitude: payload.longitude, precisaoGps: payload.precisaoGps },
                    base.sedes || []
                );
                if (onde.situacao === 'fora') { avisar(mensagemDaCerca(onde)); return; }
            }
            var conferido = await lerEstado(RELEITURA_PRAZO_MS);                                 // (6)
            if (!conferido) { avisar(AVISO_SEM_CONFERIR); return; }
            if (conferido.estado !== base.estado || conferido.dia !== base.dia) {
                estado = conferido;
                aoMudar();
                avisar(AVISO_MUDOU);
                return;
            }
            var resultado = await PB.enviarBatida(urlBatida, payload, momentoDoToque);          // (7) atraso e (8) POST
            if (resultado.tipo === 'sucesso') {
                sucesso = true;
                var extra = avisosDe(resultado);
                avisar((atual.acao === 'entrada' ? 'Ponto de entrada registrado às ' + horaDe(resultado) : 'Saída registrada às ' + horaDe(resultado)) + (extra ? '.' + extra : ''));
                return;
            }
            avisar({
                recusa: resultado.mensagem || AVISO_SEM_CONFIRMACAO,
                nao_entrou: AVISO_NAO_ENTROU,
                sem_confirmacao: AVISO_SEM_CONFIRMACAO,
                sem_rede: AVISO_SEM_REDE,
                atrasado: AVISO_ATRASADO,
            }[resultado.tipo] || AVISO_SEM_CONFIRMACAO);
        } catch (erro) {
            // Erro do próprio atalho nunca vira silêncio, e o aviso nunca afirma que a batida não entrou.
            if (window.console) { window.console.error('Falha no atalho de ponto:', erro); }
            avisar(AVISO_SEM_CONFIRMACAO);
        } finally {
            // (9) No sucesso, a trava só sai com o estado novo desenhado: antes disso o item ainda ofereceria a
            // ação velha e um segundo clique sairia como batida repetida.
            // A trava sai mesmo se a releitura ou o redesenho falharem (senão o item ficaria ocupado até recarregar).
            try { if (sucesso) { await atualizar(); } } catch (e) { /* a próxima releitura corrige */ }
            envioEmAndamento = false;
            try { aoMudar(); } catch (e) { /* idem */ }
        }
    }

    window.PresencaPonto = {
        linha: linha,
        aviso: aviso,
        regularizar: function () { if (aviso()) { regularizarOntem(estado.ontemSemSaida); } },
        ocupado: function () { return envioEmAndamento; },
        atualizar: atualizar,
        bater: bater,
        aoMudar: function (fn) { aoMudar = fn; },
    };
}());
