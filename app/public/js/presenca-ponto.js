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
    // E7: com a tela do Ponto desligada pelo Master, o atalho vem sem `data-ponto-batida` (_presenca.html.twig). A
    // linha segue contando o estado do dia, mas sem botão de registrar nem o "Regularizar", que levam à tela fechada.
    var podeBater = !!urlBatida;
    var PB = window.PontoBatida;

    // A releitura do estado no clique tem prazo próprio (spec F1): espera nova no fluxo da batida sem prazo travaria o
    // item em "registrando". A posição, 10 s (o teto de BatidaNaoTravaNoGpsTest): o atalho não tem posição prévia.
    var RELEITURA_PRAZO_MS = 5000;
    var POSICAO_PRAZO_MS = 10000;
    // Textos lidos na hora do uso (idioma da tela): funções, nunca constantes avaliadas no carregamento.
    function textoSemPosicao() { return BJ_T.t('ponto_cartao.js.presenca.sem_posicao'); }
    function textoMudou() { return BJ_T.t('ponto_cartao.js.presenca.mudou'); }
    function textoSemConferir() { return BJ_T.t('ponto_cartao.js.presenca.sem_conferir'); }
    function textoNaoEntrou() { return BJ_T.t('ponto_cartao.js.presenca.nao_entrou'); }
    function textoSemConfirmacao() { return BJ_T.t('ponto_cartao.js.presenca.sem_confirmacao'); }
    function textoSemRede() { return BJ_T.t('ponto_cartao.js.presenca.sem_rede'); }
    function textoAtrasado() { return BJ_T.t('ponto_cartao.js.presenca.atrasado'); }
    // E11 (P21): a frase fixa da janela "Antes de registrar" da tela do ponto (desenho 16, L288).
    function textoNotaAlerta() { return BJ_T.t('ponto_cartao.js.presenca.nota_alerta'); }

    var estado = null;            // a última resposta de /ponto/estado-do-dia
    var envioEmAndamento = false; // trava do clique: cobre a posição, a releitura e o POST (spec F2)
    var aoMudar = function () {};
    var avisosDaBatida = [];      // os avisos da última batida aceita (R6), para o cabeçalho desenhar com o link

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
                if (!podeBater) { return null; }   // "Bater ponto" sem a batida seria um botão que não faz nada
                return { texto: BJ_T.t('ponto_cartao.js.presenca.bater_ponto'), cor: '#0a6fa0', icone: 'bi-fingerprint', acao: 'entrada', dica: BJ_T.t('ponto_cartao.js.presenca.dica_entrada') };
            case 'em_intervalo':
                return { texto: BJ_T.t('ponto_cartao.js.presenca.em_intervalo', { hora: h.repouso }), cor: '#5b7383', icone: 'bi-pause-circle', acao: '', dica: '' };
            case 'em_jornada':
                if (!podeBater) { return { texto: BJ_T.t('ponto_cartao.js.presenca.em_jornada', { hora: h.entrada }), cor: '#1e7b45', icone: 'bi-play-circle', acao: '', dica: '' }; }
                return { texto: BJ_T.t('ponto_cartao.js.presenca.em_jornada', { hora: h.entrada }), cor: '#1e7b45', icone: 'bi-play-circle', acao: 'saida', dica: BJ_T.t('ponto_cartao.js.presenca.dica_saida') };
            case 'jornada_concluida':
                return { texto: BJ_T.t('ponto_cartao.js.presenca.jornada', { entrada: h.entrada, saida: h.saida }), cor: '#5b7383', icone: 'bi-check2-circle', acao: '', dica: '' };
            default:
                return null;
        }
    }

    /* O aviso de ontem (R3): fica ao lado da linha, que continua oferecendo a batida, e leva ao pedido de
       esquecimento. Null sem pendência de ontem. */
    function aviso() {
        if (!podeBater || !estado || estado.aviso !== 'regularizar_ontem' || !estado.ontemSemSaida) { return null; }

        return { texto: BJ_T.t('ponto_cartao.js.presenca.regularizar_ontem'), cor: '#c76a12', icone: 'bi-exclamation-triangle', dica: BJ_T.t('ponto_cartao.js.presenca.dica_regularizar') };
    }

    function atualizar() {
        return lerEstado(RELEITURA_PRAZO_MS).then(function (novo) {
            if (novo) { estado = novo; aoMudar(); }
        });
    }

    /* Os avisos que a batida aceita trouxe (R6), sem virar erro. Só texto e, quando há, o link INTERNO e o rótulo
       dele: o cabeçalho monta o link por DOM (presenca-cabecalho.js), só com textContent. */
    function avisosDe(resultado) {
        var avisos = resultado && resultado.data && resultado.data.avisos;
        if (!Array.isArray(avisos)) { return []; }

        return avisos.filter(function (a) { return a && a.texto; }).map(function (a) {
            var interno = typeof a.link === 'string' && a.link.charAt(0) === '/' && a.link.charAt(1) !== '/' && a.rotulo;
            return { texto: String(a.texto), link: interno ? a.link : null, rotulo: interno ? String(a.rotulo) : null };
        });
    }

    function horaDe(resultado) {
        var hora = resultado && resultado.data && resultado.data.data && resultado.data.data.hora;
        return hora ? String(hora).slice(0, 5) : '';
    }

    /* A frase do dc e, com a validação de interjornada ligada no escritório, o descanso mínimo da próxima entrada, com
       o valor configurado (decisão do dono, 19/10), no formato dos alertas da E11 ("11h", "6h30", "45min"). Desde a
       E11 nada recusa a entrada: com os alertas ligados ela avisa antes de registrar; desligados, só se informa o
       mínimo. Estado sem `alertasLigados` (servidor antigo) fica com a frase dos alertas ligados, o padrão (P96). */
    function descanso(min) {
        var h = Math.floor(min / 60);
        var m = min % 60;
        if (!h) { return m + 'min'; }

        return h + 'h' + (m ? (m < 10 ? '0' : '') + m : '');
    }
    function confirmarSaida(base) {
        var texto = BJ_T.t('ponto_cartao.js.presenca.confirmar_saida', { hora: new Date().toTimeString().slice(0, 5) });
        if (base.interjornadaMinimaMin) {
            texto += base.alertasLigados === false
                ? ' ' + BJ_T.t('ponto_cartao.js.presenca.descanso_minimo', { descanso: descanso(base.interjornadaMinimaMin) })
                : ' ' + BJ_T.t('ponto_cartao.js.presenca.descanso_aviso', { descanso: descanso(base.interjornadaMinimaMin) });
        }

        return window.confirm(texto);
    }

    /* "Regularizar ponto de ontem": leva à folha do mês de ontem, que abre o "+" da saída daquele dia (o pedido de
       justificativa de esquecimento que já existe, aprovado pelo gestor). Nenhuma batida sai daqui. */
    function regularizarOntem(dia) {
        window.location.href = urlPonto + '?competencia=' + dia.slice(0, 7) + '&esquecimento=' + dia + ':saida';
    }

    /* E11 (P21): a batida saiu da regra de intervalo (interjornada ou descanso semanal) e o servidor não gravou. Fora
       disso, null. */
    function alertaDe(resultado) {
        return resultado.tipo === 'recusa' && resultado.data && typeof resultado.data.alerta === 'string' ? resultado.data.alerta : null;
    }

    function mensagemDaCerca(onde) {
        var nome = onde.sede && onde.sede.nome ? onde.sede.nome : BJ_T.t('ponto_cartao.js.presenca.sede_mais_proxima');

        return BJ_T.t('ponto_cartao.js.presenca.fora_da_cerca', { distancia: Math.round(onde.distancia), nome: nome });
    }

    /* A sequência do clique, nesta ordem (spec F2). `base` é o retrato do estado no clique: a ação, o home office,
       as sedes e a comparação da releitura saem dele, nunca do `estado`, que o relógio de fundo reescreve no meio do
       clique (senão a releitura compararia o novo com o novo e uma batida velha sairia). */
    async function bater() {
        var base = estado;
        var atual = linhaDe(base);
        if (envioEmAndamento || !atual || !atual.acao) { return; }
        PB.pedirPermissaoDeNotificacao();   // ainda no gesto: a janelinha do alerta precisa da permissão
        envioEmAndamento = true;   // (1)
        avisosDaBatida = [];
        var sucesso = false;
        try {
            aoMudar();   // mostra o item ocupado
            if (atual.acao === 'saida' && !confirmarSaida(base)) { return; }   // (2) cancelar destrava no finally
            var momentoDoToque = performance.now();                        // (3) depois da confirmação
            // E13: a hora do aparelho no toque (o servidor guarda só como informação) e o identificador único do
            // envio. Sem a função (módulo antigo no cache), a batida sai sem ele, como antes. Fila sem internet não
            // há aqui: é só da tela do ponto (E14); o atalho sem rede segue com o aviso de sempre.
            var horaAparelho = Date.now();
            var payload = { tipo: atual.acao, idEnvio: typeof PB.novoIdDeEnvio === 'function' ? PB.novoIdDeEnvio() : undefined, horaAparelho: horaAparelho };
            // E11: a volta do laço é a do "Registrar assim mesmo". A batida com alerta não gravou; confirmada, passa de
            // novo pela posição, pela cerca e pela releitura do estado (comparada com `base`, o retrato do clique)
            // antes do segundo envio, com a hora da confirmação. Se o estado mudou nesse meio, não envia.
            var confirmouAlerta = false;
            var resultado;
            for (;;) {
                // E7: com a geolocalização desligada (Master ou escritório) não pede a posição nem avalia a cerca,
                // como o servidor. `!== false`: um estado sem o campo continua pedindo a posição.
                if (!base.homeOffice && base.geoLigado !== false) {
                    var position = await PB.lerPosicaoComPrazo(POSICAO_PRAZO_MS, function () {});   // (4)
                    if (!position) { avisar(textoSemPosicao()); return; }
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
                if (!conferido) { avisar(textoSemConferir()); return; }
                if (conferido.estado !== base.estado || conferido.dia !== base.dia) {
                    estado = conferido;
                    aoMudar();
                    avisar(textoMudou());
                    return;
                }
                resultado = await PB.enviarBatida(urlBatida, payload, momentoDoToque);          // (7) atraso e (8) POST
                // E11: o alerta não impede o registro. O atalho pergunta como já pergunta na saída e, confirmado, refaz
                // a MESMA batida (o mesmo `idEnvio`, nada foi gravado) com a confirmação.
                var alerta = confirmouAlerta ? null : alertaDe(resultado);
                if (alerta === null) { break; }
                if (!window.confirm(alerta + ' ' + textoNotaAlerta() + ' ' + BJ_T.t('ponto_cartao.js.presenca.registrar_assim_mesmo'))) { return; }
                confirmouAlerta = true;
                momentoDoToque = performance.now();
                payload.horaAparelho = Date.now();
                payload.confirmarAlerta = true;
            }
            if (resultado.tipo === 'sucesso') {
                sucesso = true;
                avisosDaBatida = avisosDe(resultado);
                var extra = avisosDaBatida.map(function (a) { return ' ' + a.texto; }).join('');
                avisar((atual.acao === 'entrada' ? BJ_T.t('ponto_cartao.js.presenca.entrada_registrada', { hora: horaDe(resultado) }) : BJ_T.t('ponto_cartao.js.presenca.saida_registrada', { hora: horaDe(resultado) })) + (extra ? '.' + extra : ''));
                return;
            }
            avisar({
                recusa: resultado.mensagem || textoSemConfirmacao(),
                nao_entrou: textoNaoEntrou(),
                sem_confirmacao: textoSemConfirmacao(),
                sem_rede: textoSemRede(),
                atrasado: textoAtrasado(),
            }[resultado.tipo] || textoSemConfirmacao());
        } catch (erro) {
            // Erro do próprio atalho nunca vira silêncio, e o aviso nunca afirma que a batida não entrou.
            if (window.console) { window.console.error('Falha no atalho de ponto:', erro); }
            avisar(textoSemConfirmacao());
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
        avisosDaBatida: function () { return avisosDaBatida; },
        regularizar: function () { if (aviso()) { regularizarOntem(estado.ontemSemSaida); } },
        ocupado: function () { return envioEmAndamento; },
        atualizar: atualizar,
        bater: bater,
        aoMudar: function (fn) { aoMudar = fn; },
    };
}());
