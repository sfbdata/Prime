/**
 * Partes puras da batida do ponto, num lugar só.
 *
 * Saíram de `templates/ponto/index.html.twig` para que a tela do ponto e o atalho do cabeçalho
 * usem a MESMA avaliação de cerca, o MESMO leitor de posição com prazo e a MESMA classificação da
 * resposta. Nada aqui lê ou escreve na tela: quem chama passa a posição, as sedes, a URL e o que
 * fazer quando a posição chega, e traduz a classificação devolvida nos próprios avisos.
 *
 * Specs: `docs/specs/ponto-batida-nao-se-perde-no-navegador.md`,
 * `docs/specs/ponto-batida-que-responde-e-conta-certa.md` e
 * `docs/specs/presenca-e-ponto-no-cabecalho.md` (§3, "Envio, com a lógica da tela do ponto num
 * lugar só").
 *
 * Exposto em `window.PontoBatida`.
 */
(function () {
    'use strict';

    // Prazo próprio para atualizar a posição antes de enviar a batida.
    //
    // `getCurrentPosition` resolve apenas pelos callbacks do navegador, e existe um estado em que
    // ele não chama nenhum dos dois: com o documento oculto (tela apagada, troca de aplicativo,
    // celular no bolso) o Android SUSPENDE a geolocalização — e aí o `timeout` da própria API
    // também não corre, porque o relógio dele é do pedido de posição. Um `await` direto nessa
    // promessa trava para sempre e a batida NUNCA chega a ser enviada: sem erro, sem aviso e sem
    // linha no banco. Foi o defeito que originou esta frente
    // (`docs/specs/ponto-batida-nao-se-perde-no-navegador.md`).
    //
    // 🔑 O ganho é sobre a SUSPENSÃO, não sobre o relógio: `setTimeout` também é do navegador, e
    // aba oculta o estrangula (e página congelada nem roda JS). A diferença é que timer estrangulado
    // volta a disparar quando a página volta, enquanto a geolocalização suspensa pode não chamar
    // callback nenhum. Por isso o invariante abaixo vale em tempo de PÁGINA RODANDO, não em tempo
    // de relógio de parede — e é por isso que existe o `LIMITE_ATRASO_ENVIO_MS` lá no envio.
    //
    // Invariante: `lerPosicaoComPrazo` NUNCA rejeita e, com a página rodando, NUNCA demora mais que
    // `prazoMs`. É o que garante que o `fetch` da batida sempre acontece. Devolve `null` quando não
    // deu para atualizar a posição — e aí vale a que já está na mão, mesmo comportamento do antigo
    // ramo de erro.
    const GPS_PRAZO_ATUALIZACAO_MS = 5000;

    // Teto entre o toque e o envio. O caminho normal gasta no máximo o prazo do GPS acima; só
    // estoura quando a aba ficou congelada no meio. Ver a guarda em `enviarBatida`.
    const LIMITE_ATRASO_ENVIO_MS = 60000;

    // Teto do envio em si. Sem ele, conexão pendurada deixa o botão em "Registrando..." para
    // sempre — o MESMO sintoma que o prazo do GPS resolveu, por outro caminho. `fetch` só
    // desiste sozinho quando o sistema operacional decide, o que pode não acontecer nunca.
    const ENVIO_PRAZO_MS = 15000;

    // Teto da busca da tela do ponto depois de uma batida com sucesso. Mesmo motivo do prazo do
    // envio: sem ele, uma conexão pendurada deixaria o botão travado para sempre esperando a tela
    // atualizar. Estourou: quem chama trata como falha e cai no plano B (recarregar).
    const TELA_PRAZO_MS = 15000;

    function distanciaEmMetros(lat1, lon1, lat2, lon2) {
        const raioTerra = 6371000;
        const rad = (g) => g * Math.PI / 180;
        const dLat = rad(lat2 - lat1);
        const dLon = rad(lon2 - lon1);
        const a = Math.sin(dLat / 2) ** 2
            + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(dLon / 2) ** 2;

        return raioTerra * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    /**
     * Onde a pessoa está, em relação às sedes.
     *
     * `posicao` é `{ latitude, longitude, precisaoGps }` (ou `null`); `sedes` é a lista
     * `{ nome, latitude, longitude, raio }` do escritório ativo.
     *
     * 🔑 Três situações, não duas. `indeterminado` existe porque precisão de GPS não é enfeite: com
     * 120 m de erro e 70 m de distância o aparelho LITERALMENTE não sabe dizer se está dentro. A
     * tela trata isso como "pode tentar" e deixa o servidor decidir.
     *
     * 🔑 Esta avaliação é de propósito MAIS PERMISSIVA que a do servidor: só acusa `fora` quando a
     * distância supera o raio mesmo descontando a margem de precisão. A regra continua sendo a do
     * servidor, e divergir para o lado permissivo é seguro — divergir para o rígido transformaria
     * imprecisão de GPS em falta, que é o que não pode acontecer.
     */
    function avaliarPosicao(posicao, sedes) {
        if (!posicao || sedes.length === 0) {
            return { situacao: 'indeterminado', distancia: null, sede: null };
        }

        let maisProxima = null;
        let menorDistancia = Infinity;
        let menorExcedente = Infinity;
        let decisiva = null;
        let distanciaDecisiva = null;
        let dentro = false;

        for (const sede of sedes) {
            const distancia = distanciaEmMetros(
                posicao.latitude, posicao.longitude, sede.latitude, sede.longitude
            );
            // Coordenada que não vira número devolve NaN, e `NaN < x` é falso: sem esta guarda a
            // sede mais próxima ficaria null e o `.raio` lá embaixo lançaria, deixando o botão
            // desabilitado desde a carga sem explicação nenhuma.
            if (!Number.isFinite(distancia)) {
                continue;
            }
            if (distancia < menorDistancia) {
                menorDistancia = distancia;
                maisProxima = sede;
            }
            // 🔑 O que decide "fora" é o MENOR excedente, não a sede mais próxima. Com raios
            // diferentes, a sede mais próxima pode ter raio pequeno enquanto outra, mais distante,
            // tem raio grande o bastante para a pessoa estar dentro. Olhar só a mais próxima
            // bloquearia quem o servidor aceitaria — a tela ficaria mais rígida que a regra.
            //
            // A sede CITADA na mensagem e a decisiva, nao a mais perto em metros: mandar a
            // pessoa olhar para a sede errada e conselho ruim, e com raios diferentes elas
            // divergem. Medido por fuzz: aconteceria em 10% dos bloqueios.
            if ((distancia - sede.raio) < menorExcedente) {
                menorExcedente = distancia - sede.raio;
                decisiva = sede;
                distanciaDecisiva = distancia;
            }
            if (distancia <= sede.raio) {
                dentro = true;
            }
        }

        if (maisProxima === null) {
            return { situacao: 'indeterminado', distancia: null, sede: null };
        }

        if (dentro) {
            return { situacao: 'dentro', distancia: menorDistancia, sede: maisProxima };
        }

        // `Math.max(..., 0)` porque precisão negativa somaria à distância e ENDURECERIA a tela.
        const margem = Math.max(Number(posicao.precisaoGps) || 0, 0);
        const certamenteFora = (menorExcedente - margem) > 0;

        return {
            situacao: certamenteFora ? 'fora' : 'indeterminado',
            distancia: distanciaDecisiva,
            sede: decisiva,
        };
    }

    /**
     * Pede uma posição nova com prazo próprio. Responde com a `position` do navegador ou com
     * `null`; nunca rejeita (ver o comentário de `GPS_PRAZO_ATUALIZACAO_MS`).
     *
     * `aoChegar(position)` é chamado em TODA chegada de posição, dentro ou fora do prazo, sempre
     * DEPOIS de responder e dentro de um `try`: quem chama decide o que guardar dela.
     */
    function lerPosicaoComPrazo(prazoMs, aoChegar) {
        return new Promise((resolve) => {
            let jaRespondeu = false;
            const responder = (valor) => {
                if (jaRespondeu) return;
                jaRespondeu = true;
                resolve(valor);
            };

            const prazo = setTimeout(() => responder(null), prazoMs);

            // Posição que chega DEPOIS do prazo não serve mais para esta batida (já respondemos),
            // mas serve para a próxima: entregá-la a `aoChegar` evita a página aberta o dia todo
            // ficar presa na posição da carga. Na tela do ponto, `aoChegar` é `guardarPosicao`, que
            // também acerta o aviso de precisão — sem isso a tela seguiria anunciando 12 m enquanto
            // a próxima batida sairia com 800 m.
            //
            // 🔑 Ordem importa: responde ANTES de entregar. Nada depois de `responder` pode impedir
            // o envio — se `aoChegar` lançasse antes dele, com o prazo já cancelado, a promessa
            // nunca resolveria e o travamento voltaria pela porta dos fundos.
            const guardarEResponder = (position) => {
                clearTimeout(prazo);
                responder(position);
                try {
                    aoChegar(position);
                } catch (erro) {
                    console.log('Não consegui guardar a posição para a próxima batida:', erro);
                }
            };

            try {
                if (!("geolocation" in navigator)) {
                    clearTimeout(prazo);
                    responder(null);
                    return;
                }

                navigator.geolocation.getCurrentPosition(
                    guardarEResponder,
                    () => { clearTimeout(prazo); responder(null); },
                    { enableHighAccuracy: true, timeout: prazoMs }
                );
            } catch (erro) {
                // Lançamento SÍNCRONO (extensão de privacidade que stuba a API, WebView de MDM):
                // sem este catch a promessa rejeitaria e o botão travaria — o sintoma original.
                clearTimeout(prazo);
                responder(null);
            }
        });
    }

    /**
     * Identificador único do envio de uma batida (E13): UUID v4 em minúsculas, feito UMA vez no toque e repetido em
     * toda nova tentativa daquela batida (a fila sem internet da tela do ponto, E14). O servidor não grava duas
     * batidas com o mesmo identificador: a segunda resposta diz que ela já tinha sido registrada.
     *
     * `crypto.randomUUID` só existe em contexto seguro (https ou localhost); fora dele (o http://127.0.0.1.nip.io do
     * dev) o v4 é montado com `crypto.getRandomValues`, que existe em qualquer página. Nunca lança: sem nenhum dos dois
     * (navegador muito antigo), os bytes saem do `Math.random`, o que ainda basta para não repetir na mesma pessoa.
     */
    function novoIdDeEnvio() {
        try {
            if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
                return String(crypto.randomUUID()).toLowerCase();
            }
        } catch (erro) {
            // Segue para a montagem do v4 abaixo.
        }

        const bytes = new Uint8Array(16);
        try {
            crypto.getRandomValues(bytes);
        } catch (erro) {
            for (let i = 0; i < bytes.length; i++) {
                bytes[i] = Math.floor(Math.random() * 256);
            }
        }
        bytes[6] = (bytes[6] & 0x0f) | 0x40;   // versão 4
        bytes[8] = (bytes[8] & 0x3f) | 0x80;   // variante RFC 4122
        const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }

    /**
     * Envia a batida e CLASSIFICA o que aconteceu, sem mexer na tela.
     *
     * Resolve com `{ tipo, mensagem, data, status, falhaDeRede }`. `status` é o código HTTP da resposta (`null`
     * quando nenhuma resposta voltou); `falhaDeRede` é `true` só quando o próprio envio falhou (erro de rede ou prazo
     * estourado, o `catch` do `fetch`), que é o caso em que a tela do ponto guarda a batida na fila (E14). O `tipo` é:
     * - `atrasado`: passou de `LIMITE_ATRASO_ENVIO_MS` desde o toque e NADA foi enviado;
     * - `sucesso`: o servidor confirmou (`mensagem` é a dele);
     * - `recusa`: o servidor respondeu com mensagem e não gravou (`mensagem` é a dele);
     * - `nao_entrou`: 401, 403 ou redirecionamento sem mensagem; o servidor barrou antes de gravar;
     * - `sem_confirmacao`: qualquer outra resposta, inclusive 500 com HTML ou corpo que não é JSON,
     *   e erro de envio com rede; a batida PODE ter entrado;
     * - `sem_rede`: erro de envio com o aparelho sabidamente sem rede; a batida PODE ter entrado.
     *
     * `data` é o JSON da resposta quando ela veio e pôde ser lido; `null` nos outros casos.
     */
    async function enviarBatida(url, payload, momentoDoToque) {
        // O servidor carimba a batida com a hora de CHEGADA da requisição
        // (`PontoController::batida` → `setDataHora(new \DateTime())`); a hora do aparelho que o
        // payload leva desde a E13 (`horaAparelho`) fica guardada só como informação. Se a aba foi
        // congelada e o envio só sair minutos depois, a batida
        // entraria com a hora errada e com cara de certa. Num módulo de risco ALTO, dado errado
        // silencioso é pior que ausência visível — então aqui NÃO se envia: quem chama devolve o
        // botão e manda repetir, que é o comportamento que a pessoa já conhecia.
        if (performance.now() - momentoDoToque > LIMITE_ATRASO_ENVIO_MS) {
            return { tipo: 'atrasado', mensagem: null, data: null, status: null, falhaDeRede: false };
        }

        const abortoDoEnvio = new AbortController();
        const prazoDoEnvio  = setTimeout(() => abortoDoEnvio.abort(), ENVIO_PRAZO_MS);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload),
                signal: abortoDoEnvio.signal
            });

            // Resposta que não é JSON (sessão expirada devolve a tela de login, erro do servidor
            // devolve HTML) quebrava no `.json()` e caía no catch como "erro de conexão", que é
            // diagnóstico errado e manda a pessoa tentar de novo sem sair do lugar.
            let data = null;
            try {
                data = await response.json();
            } catch (erroDeLeitura) {
                data = null;
            }
            // E13: o código vai junto da classificação, para a fila sem internet (E14) separar o 409 (batida de
            // outra conta ou escritório, que fica guardada) das outras recusas. A classificação não muda.
            const status = response.status;

            if (response.ok && data && data.success) {
                return { tipo: 'sucesso', mensagem: data.message, data, status, falhaDeRede: false };
            } else if (data && data.message) {
                return { tipo: 'recusa', mensagem: data.message, data, status, falhaDeRede: false };
            } else if (response.status === 401 || response.status === 403 || response.redirected) {
                // Recusa de regra vem com JSON e foi tratada no ramo acima. Chegar aqui com 401/403
                // é sessão expirada ou token de segurança inválido: nesses o servidor barra ANTES
                // de gravar, então dá para afirmar que a batida não entrou.
                return { tipo: 'nao_entrou', mensagem: null, data, status, falhaDeRede: false };
            } else {
                return { tipo: 'sem_confirmacao', mensagem: null, data, status, falhaDeRede: false };
            }
        } catch (error) {
            // 🔑 Aqui o navegador NÃO SABE se a batida entrou. O aborto cancela só o lado de cá: a
            // requisição já saiu e o servidor pode ter gravado antes de a resposta se perder. Dizer
            // "não foi registrada" e mandar repetir produzia batida duplicada, e o servidor não tem
            // guarda de duplicata (`PontoController::batida` só valida repouso e interjornada).
            // Por isso o aviso manda CONFERIR o bloco de hoje, que mostra inclusive quantos
            // registros existem de cada tipo.
            // `navigator.onLine` só é confiável quando diz FALSO (o sistema sabe que não há rota).
            // Usá-lo para ESCONDER o botão de atualizar é seguro; usá-lo para afirmar que há
            // internet não seria.
            const semRede = navigator.onLine === false;
            console.error('Erro ao registrar ponto:', error);
            // `falhaDeRede` só aqui: nenhuma resposta voltou. Com o mesmo `idEnvio`, mandar de novo é seguro (E13).
            return { tipo: semRede ? 'sem_rede' : 'sem_confirmacao', mensagem: null, data: null, status: null, falhaDeRede: true };
        } finally {
            clearTimeout(prazoDoEnvio);
        }
    }

    /**
     * Busca o HTML atual da tela do ponto (mesma URL, `GET`, sem cache) e devolve o documento
     * lido, para quem chama trocar só as regiões que mudaram com a batida, sem recarregar a página.
     * Recarregar zerava a liberação de áudio do navegador e o toque seguinte do alerta do ponto
     * ficava mudo (`docs/specs/ponto-atualiza-sem-recarregar.md`).
     *
     * Rejeita quando a resposta não é 200, veio de redirecionamento (sessão caiu, troca de
     * escritório) ou estourou `TELA_PRAZO_MS`. Não toca na tela.
     */
    async function buscarTelaDoPonto(url) {
        const aborto = new AbortController();
        const prazo  = setTimeout(() => aborto.abort(), TELA_PRAZO_MS);
        try {
            const resposta = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'text/html' },
                signal: aborto.signal,
            });
            if (!resposta.ok || resposta.redirected) {
                throw new Error('Tela do ponto respondeu ' + resposta.status
                    + (resposta.redirected ? ' (redirecionada)' : ''));
            }
            const html = await resposta.text();
            return new DOMParser().parseFromString(html, 'text/html');
        } finally {
            clearTimeout(prazo);
        }
    }

    /**
     * Pede a permissão de notificação do sistema (a "janelinha" do alerta do ponto, que aparece
     * mesmo com o navegador minimizado), só se a pessoa ainda não respondeu (`default`).
     *
     * Precisa ser chamada DENTRO do clique da pessoa, antes de qualquer `await`: pedida sem gesto
     * (como o aviso fazia ao abrir sozinho), o Chrome esconde o pedido e a notificação nunca sai.
     * Não espera a resposta e nunca lança: a batida segue igual, com ou sem permissão.
     */
    function pedirPermissaoDeNotificacao() {
        try {
            if (!('Notification' in window) || Notification.permission !== 'default') {
                return;
            }
            const pedido = Notification.requestPermission();
            if (pedido && typeof pedido.catch === 'function') {
                pedido.catch(() => {});
            }
        } catch (erro) {
            // Navegador sem a API ou que recusa o pedido: fica só o aviso dentro da página.
        }
    }

    window.PontoBatida = Object.freeze({
        GPS_PRAZO_ATUALIZACAO_MS,
        LIMITE_ATRASO_ENVIO_MS,
        ENVIO_PRAZO_MS,
        TELA_PRAZO_MS,
        distanciaEmMetros,
        avaliarPosicao,
        lerPosicaoComPrazo,
        novoIdDeEnvio,
        enviarBatida,
        buscarTelaDoPonto,
        pedirPermissaoDeNotificacao,
    });
})();
