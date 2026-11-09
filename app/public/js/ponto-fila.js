/**
 * Fila sem internet da tela do ponto (E14, P20 de `docs/specs/ponto-desenho-0811.md`; desenho 16, caixa L127 e
 * lógica L438 e L546). Ponto eletrônico: risco ALTO.
 *
 * A batida feita sem internet fica guardada NESTE aparelho e é enviada quando a internet volta. Vale a hora do
 * aparelho no toque (`horaAparelho`); o identificador do envio (`idEnvio`, E13) torna seguro mandar de novo: o
 * servidor não grava duas vezes o mesmo e responde que a batida já tinha sido registrada.
 *
 * 🔑 Cifrada, mas só contra leitura casual. A chave AES-GCM de 256 bits é gerada uma vez, não extraível
 * (`extractable: false`), e fica no IndexedDB junto das entradas, cada uma cifrada com um IV aleatório próprio. Quem
 * abre o armazenamento do navegador vê bytes, não horários nem posições; mas o dono do aparelho (ou qualquer script
 * desta origem) pode USAR a chave para decifrar. Isto protege de leitura casual, não do dono do aparelho.
 *
 * Nada aqui lê ou escreve na tela: devolve contagens, entradas e mensagens para a tela mostrar. O envio é o de
 * sempre (`PontoBatida.enviarBatida`, que tem prazo próprio), com a marca `semInternet` e a pessoa e o escritório
 * guardados junto da batida; a tela só pede o envio das entradas da pessoa e do escritório da página, e as de outra
 * pessoa nunca saem daqui.
 *
 * Exposto em `window.PontoFila`. Carregado só pela tela do ponto, depois de `ponto-batida.js`.
 */
(function () {
    'use strict';

    const BANCO = 'bj-ponto-fila';
    // Versão 2: a loja das recusas (`LOJA_RECUSAS`). O banco da versão 1 ganha a loja nova e mantém a chave e as
    // entradas; outra aba aberta com a versão 1 fecha o banco dela (`onversionchange`) para não travar a troca.
    const VERSAO_DO_BANCO = 2;
    const LOJA_CHAVE = 'chave';
    const LOJA_ENTRADAS = 'entradas';
    // Recusas definitivas ainda não mostradas na tela (ver `recusar`). Cifradas como as entradas; sem posição.
    const LOJA_RECUSAS = 'recusas';
    // Dado autenticado da recusa: `recusa:` antes do identificador do envio. Um registro levado da loja das entradas
    // para a das recusas (ou o contrário) não abre.
    const PREFIXO_DA_RECUSA = 'recusa:';
    const ID_DA_CHAVE = 'aes-gcm-1';
    // IV de 12 bytes, o tamanho recomendado para o AES-GCM. Novo a cada entrada: repetir o IV com a mesma chave
    // quebra o sigilo do AES-GCM.
    const TAMANHO_DO_IV = 12;
    const CAMPOS_DA_POSICAO = ['latitude', 'longitude', 'precisaoGps'];
    // Transação de escrita que só termina (`oncomplete`) depois de o navegador gravar no disco: a batida guardada não
    // pode sumir num desligamento logo depois do aviso de que ficou guardada. Navegador que não conhece a opção ignora
    // o terceiro argumento e grava como sempre.
    const ESCRITA_DURAVEL = { durability: 'strict' };
    // Limpeza na carga (ver `limpar`): uma semana. O servidor já recusa a batida guardada há mais de 72 h; a folga até 7
    // dias deixa a recusa chegar à pessoa (com a mensagem do servidor) antes de a entrada sumir sem aviso.
    const IDADE_MAXIMA_MS = 7 * 24 * 60 * 60 * 1000;

    let bancoAberto = null;   // promessa do banco, aberta uma vez por página
    let chaveAberta = null;   // promessa da CryptoKey, lida (ou gerada) uma vez por página
    let envioEmAndamento = false;
    // Entradas que voltaram 409 nesta página (guardadas em outra conta ou escritório): ficam na fila, mas não são
    // mandadas de novo a cada minuto. Na próxima abertura da tela (talvez no escritório certo), voltam a ser tentadas.
    const retidasNestaPagina = new Set();

    /**
     * Fila utilizável neste navegador. Falso sem IndexedDB ou sem `crypto.subtle` (página fora de contexto seguro,
     * como o http://127.0.0.1.nip.io do dev), e sem o identificador do envio do módulo da batida (módulo antigo no
     * cache): sem ele, mandar de novo não seria seguro. Falso aqui, a tela faz o que fazia antes (aviso de sem rede).
     */
    function disponivel() {
        try {
            return typeof indexedDB !== 'undefined' && indexedDB !== null
                && typeof crypto !== 'undefined' && !!crypto.subtle
                && typeof crypto.getRandomValues === 'function'
                && !!window.PontoBatida
                && typeof window.PontoBatida.novoIdDeEnvio === 'function'
                && typeof window.PontoBatida.enviarBatida === 'function';
        } catch (erro) {
            // Navegador que lança só de olhar o `indexedDB` (quadro com armazenamento bloqueado).
            return false;
        }
    }

    function abrirBanco() {
        if (!bancoAberto) {
            bancoAberto = new Promise((resolve, reject) => {
                const pedido = indexedDB.open(BANCO, VERSAO_DO_BANCO);
                pedido.onupgradeneeded = () => {
                    const banco = pedido.result;
                    if (!banco.objectStoreNames.contains(LOJA_CHAVE)) {
                        banco.createObjectStore(LOJA_CHAVE);
                    }
                    if (!banco.objectStoreNames.contains(LOJA_ENTRADAS)) {
                        banco.createObjectStore(LOJA_ENTRADAS, { keyPath: 'idEnvio' });
                    }
                    if (!banco.objectStoreNames.contains(LOJA_RECUSAS)) {
                        banco.createObjectStore(LOJA_RECUSAS, { keyPath: 'idEnvio' });
                    }
                };
                pedido.onsuccess = () => {
                    const banco = pedido.result;
                    // Outra aba pedindo uma versão nova do banco: esta fecha para não travar a atualização dela.
                    banco.onversionchange = () => {
                        banco.close();
                        bancoAberto = null;
                    };
                    resolve(banco);
                };
                pedido.onerror = () => reject(pedido.error);
                pedido.onblocked = () => reject(new Error('O banco da fila está preso por outra aba.'));
            });
            bancoAberto.catch(() => { bancoAberto = null; });
        }

        return bancoAberto;
    }

    /**
     * Roda `trabalho(loja)` numa transação e resolve com o resultado do pedido que ele devolveu quando a transação
     * TERMINA (o que foi gravado está gravado). Rejeita com o erro da transação.
     */
    async function naLoja(nome, modo, trabalho) {
        const banco = await abrirBanco();

        return new Promise((resolve, reject) => {
            const transacao = modo === 'readwrite' ? banco.transaction(nome, modo, ESCRITA_DURAVEL) : banco.transaction(nome, modo);
            let resultado;
            const pedido = trabalho(transacao.objectStore(nome));
            if (pedido) {
                pedido.onsuccess = () => { resultado = pedido.result; };
            }
            transacao.oncomplete = () => resolve(resultado);
            transacao.onerror = () => reject(transacao.error);
            transacao.onabort = () => reject(transacao.error || new Error('A transação da fila foi desfeita.'));
        });
    }

    /**
     * Grava a chave nova só se ainda não houver uma, na MESMA transação da leitura, e devolve a que ficou. Duas abas
     * podem gerar a chave ao mesmo tempo: sem isto a segunda sobrescreveria a primeira, e as entradas cifradas com ela
     * não abririam mais.
     */
    async function gravarChaveSeNaoHouver(nova) {
        const banco = await abrirBanco();

        return new Promise((resolve, reject) => {
            const transacao = banco.transaction(LOJA_CHAVE, 'readwrite', ESCRITA_DURAVEL);
            const loja = transacao.objectStore(LOJA_CHAVE);
            let final = nova;
            const leitura = loja.get(ID_DA_CHAVE);
            leitura.onsuccess = () => {
                if (leitura.result) {
                    final = leitura.result;
                    return;
                }
                loja.add(nova, ID_DA_CHAVE);
            };
            transacao.oncomplete = () => resolve(final);
            transacao.onerror = () => reject(transacao.error);
            transacao.onabort = () => reject(transacao.error || new Error('A transação da chave foi desfeita.'));
        });
    }

    /** A chave da fila: a guardada ou, na primeira vez, uma nova, não extraível. */
    function obterChave() {
        if (!chaveAberta) {
            chaveAberta = (async () => {
                const guardada = await naLoja(LOJA_CHAVE, 'readonly', (loja) => loja.get(ID_DA_CHAVE));
                if (guardada) {
                    return guardada;
                }
                // `false`: a chave não sai do navegador (não dá para exportá-la); só cifra e decifra aqui.
                const nova = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);

                return gravarChaveSeNaoHouver(nova);
            })();
            chaveAberta.catch(() => { chaveAberta = null; });
        }

        return chaveAberta;
    }

    function bytesDoTexto(texto) {
        return new TextEncoder().encode(texto);
    }

    /**
     * Cifra uma entrada com um IV aleatório próprio. O identificador do envio fica de fora, em claro, como chave da
     * loja (é aleatório e não diz nada), e entra como dado autenticado: uma entrada trocada de lugar não abre.
     */
    async function cifrar(entrada) {
        const chave = await obterChave();
        const iv = crypto.getRandomValues(new Uint8Array(TAMANHO_DO_IV));
        const dados = await crypto.subtle.encrypt(
            { name: 'AES-GCM', iv, additionalData: bytesDoTexto(entrada.idEnvio) },
            chave,
            bytesDoTexto(JSON.stringify(entrada))
        );

        return { idEnvio: entrada.idEnvio, iv, dados };
    }

    async function decifrarCom(chave, registro) {
        const claro = await crypto.subtle.decrypt(
            { name: 'AES-GCM', iv: registro.iv, additionalData: bytesDoTexto(registro.idEnvio) },
            chave,
            registro.dados
        );
        const entrada = JSON.parse(new TextDecoder().decode(claro));

        return entrada && entrada.idEnvio === registro.idEnvio ? entrada : null;
    }

    async function decifrar(registro) {
        return decifrarCom(await obterChave(), registro);
    }

    /** A recusa, cifrada como a entrada (IV próprio, identificador em claro), com o dado autenticado da recusa. */
    async function cifrarRecusa(recusa) {
        const chave = await obterChave();
        const iv = crypto.getRandomValues(new Uint8Array(TAMANHO_DO_IV));
        const dados = await crypto.subtle.encrypt(
            { name: 'AES-GCM', iv, additionalData: bytesDoTexto(PREFIXO_DA_RECUSA + recusa.idEnvio) },
            chave,
            bytesDoTexto(JSON.stringify(recusa))
        );

        return { idEnvio: recusa.idEnvio, iv, dados };
    }

    /** A recusa guardada, ou `null` se o que abriu não é uma recusa completa. Lança quando não abre. */
    async function decifrarRecusaCom(chave, registro) {
        const claro = await crypto.subtle.decrypt(
            { name: 'AES-GCM', iv: registro.iv, additionalData: bytesDoTexto(PREFIXO_DA_RECUSA + registro.idEnvio) },
            chave,
            registro.dados
        );
        const recusa = normalizarRecusa(JSON.parse(new TextDecoder().decode(claro)));

        return recusa && recusa.idEnvio === registro.idEnvio ? recusa : null;
    }

    /**
     * Só os campos da recusa: `{ idEnvio, tipo, horaAparelho, mensagem, usuarioId, escritorioId }`. Nada de posição:
     * a recusa só precisa dizer à pessoa qual batida não entrou e por quê. Null quando falta o essencial.
     */
    function normalizarRecusa(recusa) {
        const base = normalizar(recusa);
        if (!base || typeof recusa.mensagem !== 'string') {
            return null;
        }

        return {
            idEnvio: base.idEnvio,
            tipo: base.tipo,
            horaAparelho: base.horaAparelho,
            mensagem: recusa.mensagem,
            usuarioId: base.usuarioId,
            escritorioId: base.escritorioId,
        };
    }

    /**
     * Só os campos do contrato, com os tipos dele: `{ idEnvio, tipo, horaAparelho, latitude?, longitude?,
     * precisaoGps?, usuarioId, escritorioId }`. Nada da tela entra por acaso. Null quando falta o essencial.
     */
    function normalizar(entrada) {
        if (!entrada || typeof entrada.idEnvio !== 'string' || entrada.idEnvio === '' || typeof entrada.tipo !== 'string' || entrada.tipo === '') {
            return null;
        }
        const horaAparelho = Number(entrada.horaAparelho);
        const usuarioId = Number(entrada.usuarioId);
        const escritorioId = Number(entrada.escritorioId);
        if (!Number.isFinite(horaAparelho) || !Number.isInteger(usuarioId) || usuarioId <= 0 || !Number.isInteger(escritorioId) || escritorioId <= 0) {
            return null;
        }
        const limpa = { idEnvio: entrada.idEnvio, tipo: entrada.tipo, horaAparelho: Math.round(horaAparelho), usuarioId, escritorioId };
        CAMPOS_DA_POSICAO.forEach((campo) => {
            if (entrada[campo] !== undefined && entrada[campo] !== null) {
                limpa[campo] = entrada[campo];
            }
        });

        return limpa;
    }

    /** Todas as entradas que abrem, da mais antiga para a mais nova (a ordem em que as batidas aconteceram). */
    async function todas() {
        return abrirEntradas(await naLoja(LOJA_ENTRADAS, 'readonly', (loja) => loja.getAll()));
    }

    async function abrirEntradas(registros) {
        const entradas = [];
        for (const registro of registros || []) {
            try {
                const entrada = await decifrar(registro);
                if (entrada) {
                    entradas.push(entrada);
                }
            } catch (erro) {
                // Entrada que não abre (chave apagada pelo navegador, dado corrompido): não há como enviá-la.
                console.warn('Batida guardada que não pôde ser lida:', registro && registro.idEnvio);
            }
        }

        return entradas.sort((a, b) => a.horaAparelho - b.horaAparelho);
    }

    /** As recusas que abrem, da batida mais antiga para a mais nova. A que não abre fica para a limpeza da carga. */
    async function abrirRecusas(registros) {
        const chave = await obterChave();
        const recusas = [];
        for (const registro of registros || []) {
            try {
                const recusa = await decifrarRecusaCom(chave, registro);
                if (recusa) {
                    recusas.push(recusa);
                }
            } catch (erro) {
                console.warn('Recusa guardada que não pôde ser lida:', registro && registro.idEnvio);
            }
        }

        return recusas.sort((a, b) => a.horaAparelho - b.horaAparelho);
    }

    /**
     * As entradas e as recusas numa só transação de leitura: as duas listas são do mesmo instante. Lidas em duas
     * vezes, a recusa gravada por outra aba entre uma e outra podia ficar de fora das duas (a entrada já tinha saído
     * e a recusa ainda não estava lá).
     */
    async function lerFilaERecusas() {
        const banco = await abrirBanco();

        return new Promise((resolve, reject) => {
            const transacao = banco.transaction([LOJA_ENTRADAS, LOJA_RECUSAS], 'readonly');
            const lido = { entradas: [], recusas: [] };
            const entradas = transacao.objectStore(LOJA_ENTRADAS).getAll();
            const recusas = transacao.objectStore(LOJA_RECUSAS).getAll();
            entradas.onsuccess = () => { lido.entradas = entradas.result || []; };
            recusas.onsuccess = () => { lido.recusas = recusas.result || []; };
            transacao.oncomplete = () => resolve(lido);
            transacao.onerror = () => reject(transacao.error);
            transacao.onabort = () => reject(transacao.error || new Error('A leitura da fila foi desfeita.'));
        });
    }

    /**
     * 🔑 Recusa definitiva: tira a entrada da fila e grava a recusa dela na loja das recusas, na MESMA transação. Ou as
     * duas coisas acontecem, ou nenhuma. A recusa guardada só na memória da página sumia numa recarga no meio da
     * rodada (F5, "Atualizar e conferir", a recarga depois de uma batida), com a entrada já apagada: a pessoa nunca
     * sabia que a batida não entrou. Daqui ela só sai depois de a tela mostrá-la (`apagarRecusas`) ou pela limpeza.
     *
     * A recusa é cifrada antes de a transação abrir: uma espera fora do IndexedDB no meio dela a encerraria.
     * Resolve com `true` se as duas mudaram; nunca rejeita (`false`: nada mudou, a entrada continua na fila).
     */
    async function recusar(entrada, mensagem) {
        try {
            const recusa = normalizarRecusa(Object.assign({}, entrada, { mensagem: String(mensagem) }));
            if (!recusa) {
                return false;
            }
            const registro = await cifrarRecusa(recusa);
            const banco = await abrirBanco();
            await new Promise((resolve, reject) => {
                const transacao = banco.transaction([LOJA_ENTRADAS, LOJA_RECUSAS], 'readwrite', ESCRITA_DURAVEL);
                transacao.objectStore(LOJA_ENTRADAS).delete(entrada.idEnvio);
                transacao.objectStore(LOJA_RECUSAS).put(registro);
                transacao.oncomplete = () => resolve();
                transacao.onerror = () => reject(transacao.error);
                transacao.onabort = () => reject(transacao.error || new Error('A transação da recusa foi desfeita.'));
            });

            return true;
        } catch (erro) {
            console.error('Não foi possível guardar a recusa da batida:', erro);

            return false;
        }
    }

    function daPessoaEDoEscritorio(entrada, usuarioId, escritorioId) {
        return entrada.usuarioId === usuarioId && entrada.escritorioId === escritorioId;
    }

    /**
     * Contagem para a caixa da fila. `pendentes`: as desta pessoa neste escritório, que esta página envia.
     * `emOutroEscritorio`: as desta pessoa guardadas em outro escritório, mais as que voltaram 409 aqui; ficam na
     * fila e a tela avisa, para não ficarem sem ninguém saber. As de outra pessoa nunca contam.
     */
    function contagemDe(entradas, usuarioId, escritorioId) {
        let pendentes = 0;
        let emOutroEscritorio = 0;
        for (const entrada of entradas) {
            if (entrada.usuarioId !== usuarioId) {
                continue;
            }
            if (entrada.escritorioId === escritorioId && !retidasNestaPagina.has(entrada.idEnvio)) {
                pendentes++;
            } else {
                emOutroEscritorio++;
            }
        }

        return { pendentes, emOutroEscritorio };
    }

    /** Guarda a entrada. Resolve com `true` se gravou; nunca rejeita (`false` em qualquer falha). */
    async function guardar(entrada) {
        try {
            const limpa = disponivel() ? normalizar(entrada) : null;
            if (!limpa) {
                return false;
            }
            const registro = await cifrar(limpa);
            await naLoja(LOJA_ENTRADAS, 'readwrite', (loja) => loja.put(registro));

            return true;
        } catch (erro) {
            console.error('Não foi possível guardar a batida neste aparelho:', erro);

            return false;
        }
    }

    /**
     * O estado da fila para a tela, de UMA leitura nova do IndexedDB: `{ entradas, pendentes, emOutroEscritorio,
     * recusas }`. `entradas`: as desta pessoa neste escritório (inclusive as retidas por 409), da mais antiga para a
     * mais nova; as contagens são as de `contagemDe`; `recusas`: as recusas ainda não mostradas desta pessoa neste
     * escritório (a de outro escritório espera a pessoa abrir aquele). A tela monta tudo dela a partir daqui, depois de
     * cada rodada e de cada gravação, e nunca do retrato que uma rodada tirou no começo (uma batida guardada no meio
     * dela ficaria de fora). Nunca rejeita: `null` quando não deu para ler.
     */
    async function estado(usuarioId, escritorioId) {
        try {
            if (!disponivel()) {
                return null;
            }
            const lido = await lerFilaERecusas();
            const guardadas = await abrirEntradas(lido.entradas);
            const recusas = await abrirRecusas(lido.recusas);

            return Object.assign(
                { entradas: guardadas.filter((entrada) => daPessoaEDoEscritorio(entrada, usuarioId, escritorioId)) },
                contagemDe(guardadas, usuarioId, escritorioId),
                { recusas: recusas.filter((recusa) => daPessoaEDoEscritorio(recusa, usuarioId, escritorioId)) }
            );
        } catch (erro) {
            console.error('Não foi possível ler as batidas guardadas:', erro);

            return null;
        }
    }

    /**
     * Limpeza na carga da tela (LGPD, aparelho compartilhado): apaga as entradas que não abrem com a chave (chave
     * trocada ou dado corrompido: ninguém consegue mais enviá-las) e as guardadas há mais de 7 dias (`IDADE_MAXIMA_MS`),
     * de QUALQUER pessoa. Uma entrada guarda a hora e a posição de alguém; num aparelho que várias pessoas usam, ela não
     * pode ficar esquecida para sempre só porque o dono dela não voltou a abrir a tela aqui. O servidor já recusa acima
     * de 72 h, então nada que ainda poderia ser registrado é apagado. Vale igual para as recusas (`LOJA_RECUSAS`): a que
     * não abre e a de batida de mais de 7 dias saem, de qualquer pessoa.
     *
     * `agoraMs` é o agora do SERVIDOR (a tela passa `agoraNoServidorMs()`): o relógio do aparelho pulando para a frente
     * apagaria batida de menos de 72 h. Sem ele (`null`), não apaga por idade, só as que não abrem. Sem a chave (falha
     * ao abrir o banco), não apaga nada. Resolve com quantas apagou; nunca rejeita.
     */
    async function limpar(agoraMs) {
        try {
            if (!disponivel()) {
                return 0;
            }
            const porIdade = typeof agoraMs === 'number' && Number.isFinite(agoraMs);
            const chave = await obterChave();
            let apagadas = 0;
            for (const [nomeDaLoja, abrir] of [[LOJA_ENTRADAS, decifrarCom], [LOJA_RECUSAS, decifrarRecusaCom]]) {
                const registros = await naLoja(nomeDaLoja, 'readonly', (loja) => loja.getAll());
                for (const registro of registros || []) {
                    let guardado = null;
                    try {
                        guardado = await abrir(chave, registro);
                    } catch (erro) {
                        guardado = null;   // não abre: apagar
                    }
                    if (guardado && (!porIdade || agoraMs - Number(guardado.horaAparelho) <= IDADE_MAXIMA_MS)) {
                        continue;
                    }
                    if (await removerDa(nomeDaLoja, [registro.idEnvio])) {
                        apagadas++;
                    }
                }
            }

            return apagadas;
        } catch (erro) {
            console.error('Não foi possível limpar as batidas guardadas:', erro);

            return 0;
        }
    }

    /** Apaga os registros da loja numa transação. Resolve com `true` se apagou; nunca rejeita. */
    async function removerDa(nomeDaLoja, idsEnvio) {
        try {
            await naLoja(nomeDaLoja, 'readwrite', (loja) => {
                idsEnvio.forEach((idEnvio) => loja.delete(idEnvio));
            });

            return true;
        } catch (erro) {
            console.error('Não foi possível apagar da fila:', erro);

            return false;
        }
    }

    /** Tira a entrada da fila. Resolve com `true` se tirou; nunca rejeita. */
    function remover(idEnvio) {
        return removerDa(LOJA_ENTRADAS, [idEnvio]);
    }

    /**
     * Apaga as recusas que a tela JÁ mostrou (`idsEnvio`); antes disso, nunca. Resolve com `true` se apagou; nunca
     * rejeita. Não apagou: ela aparece de novo na próxima carga, em vez de sumir sem ninguém ver.
     */
    function apagarRecusas(idsEnvio) {
        if (!Array.isArray(idsEnvio) || idsEnvio.length === 0 || !disponivel()) {
            return Promise.resolve(false);
        }

        return removerDa(LOJA_RECUSAS, idsEnvio.map(String));
    }

    function payloadDe(entrada) {
        const payload = {
            tipo: entrada.tipo,
            idEnvio: entrada.idEnvio,
            horaAparelho: entrada.horaAparelho,
            semInternet: true,
            usuarioId: entrada.usuarioId,
            escritorioId: entrada.escritorioId,
        };
        CAMPOS_DA_POSICAO.forEach((campo) => {
            if (campo in entrada) {
                payload[campo] = entrada[campo];
            }
        });

        return payload;
    }

    /**
     * O que fazer com a entrada depois da resposta, pela tabela do contrato da E14, na ordem em que decide:
     * - `enviada`: o servidor gravou, ou já tinha gravado o mesmo `idEnvio` (200 com `jaRegistrada`). Sai da fila;
     * - `tentar_depois`: nenhuma resposta (rede, prazo), sessão (401, 403 sem mensagem, redirecionamento), servidor
     *   fora (5xx, 408, 429) ou corpo que não é JSON. Fica, e as seguintes esperam para não chegarem fora de ordem;
     * - `retida`: 409, guardada em outra conta ou escritório. Fica, e a tela avisa;
     * - `recusada`: recusa definitiva com mensagem (422: mais de 72 h guardada, relógio adiantado, cerca, repouso; 403
     *   com mensagem). Sai da fila, e a tela mostra a mensagem do servidor.
     *
     * O 401 fica mesmo com mensagem: é a sessão, e o servidor barrou antes de olhar a batida.
     */
    function destinoDaResposta(resultado) {
        if (!resultado) {
            return 'tentar_depois';
        }
        if (resultado.tipo === 'sucesso') {
            return 'enviada';
        }
        if (resultado.falhaDeRede) {
            return 'tentar_depois';
        }
        if (resultado.status === 409) {
            return 'retida';
        }
        if (resultado.status === 401 || resultado.status === 408 || resultado.status === 429) {
            return 'tentar_depois';
        }
        if (typeof resultado.status === 'number' && resultado.status >= 500) {
            return 'tentar_depois';
        }
        if (resultado.tipo === 'recusa' && resultado.mensagem) {
            return 'recusada';
        }

        return 'tentar_depois';
    }

    async function enviarAgora(url, usuarioId, escritorioId) {
        // Só o que esta rodada fez, em números. O estado da fila NÃO sai daqui: `guardadas` é o retrato do começo da
        // rodada, e uma batida guardada no meio dela ficaria de fora (ver `estado`). As recusas também não: ficam na loja
        // delas (`recusar`), e a tela as lê de lá.
        const resultado = { enviadas: 0, recusadas: 0 };
        const guardadas = await todas();

        for (const entrada of guardadas) {
            if (!daPessoaEDoEscritorio(entrada, usuarioId, escritorioId) || retidasNestaPagina.has(entrada.idEnvio)) {
                continue;   // de outra pessoa ou de outro escritório: não sai daqui
            }
            // `performance.now()` do próprio envio: a guarda de 60 s do atraso é da batida normal, que vale pela hora
            // de chegada. Aqui vale a hora do aparelho no toque, que vai no payload.
            const resposta = await window.PontoBatida.enviarBatida(url, payloadDe(entrada), performance.now());
            const destino = destinoDaResposta(resposta);

            if (destino === 'enviada') {
                await remover(entrada.idEnvio);
                resultado.enviadas++;
            } else if (destino === 'recusada') {
                // A entrada sai da fila e a recusa entra na loja dela, na mesma transação. Não deu: nada mudou, e a
                // entrada volta na próxima rodada (o servidor recusa de novo e a recusa é gravada então).
                if (!(await recusar(entrada, resposta.mensagem))) {
                    break;
                }
                resultado.recusadas++;
            } else if (destino === 'retida') {
                retidasNestaPagina.add(entrada.idEnvio);
            } else {
                break;   // tentar depois: a próxima rodada recomeça desta
            }
        }

        return resultado;
    }

    // Trava entre abas do mesmo navegador, quando há (`navigator.locks`): outra aba enviando, esta não espera nem
    // repete. Sem a API, duas abas podem mandar a mesma entrada, e o `idEnvio` faz o servidor gravar uma vez só.
    function comTravaEntreAbas(trabalho) {
        if (!navigator.locks || typeof navigator.locks.request !== 'function') {
            return trabalho();
        }

        return navigator.locks.request(BANCO, { ifAvailable: true }, (trava) => (trava ? trabalho() : null));
    }

    /**
     * Envia as entradas desta pessoa neste escritório, uma por vez, da mais antiga para a mais nova. Uma execução por
     * vez: o segundo disparo enquanto o primeiro corre (o evento `online` junto do relógio de um minuto) volta com
     * `null` sem mandar nada, e a mesma entrada nunca sai duas vezes desta página.
     *
     * Resolve com o que a rodada fez, em números (`{ enviadas, recusadas }`), ou `null` quando não rodou; nunca com o
     * estado da fila nem com as recusas, que a tela lê de novo depois (`estado`). Nunca rejeita.
     */
    async function enviarPendentes(url, usuarioId, escritorioId) {
        if (envioEmAndamento) {
            return null;
        }
        envioEmAndamento = true;
        try {
            if (!disponivel()) {
                return null;
            }

            return await comTravaEntreAbas(() => enviarAgora(url, usuarioId, escritorioId));
        } catch (erro) {
            console.error('Falha ao enviar as batidas guardadas:', erro);

            return null;
        } finally {
            envioEmAndamento = false;
        }
    }

    window.PontoFila = Object.freeze({
        disponivel,
        guardar,
        estado,
        remover,
        limpar,
        enviarPendentes,
        apagarRecusas,
    });
})();
