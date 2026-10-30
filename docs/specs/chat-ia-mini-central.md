# Mini chat da Central no motor do Chat I.A (item B)

Risco **MÉDIO** (leva a IA da Central para o motor novo; mexe em arquivo quente `central.js`). Decisões do dono, via Samuel e
Coordenador, 29/10/2026. Origem: `docs/specs/ia-chat-central.md` (F3) e `docs/specs/chat-pagina-inteira.md` (#542).

## O que muda

A janela "BlueJus IA" da Central deixa de usar a fila (`POST /central/ia/perguntas`, worker, `chat_central`) e passa a falar
com o Chat I.A: as MESMAS rotas e o MESMO SSE de `/chat-ia/*`. A Central vira só uma tela menor (mini) do mesmo chat.

1. **Mini = tela.** Nenhum motor novo. O mini usa `GET/POST /chat-ia/conversas`, `GET /chat-ia/conversas/{id}` e
   `POST /chat-ia/conversas/{id}/mensagens` (SSE `delta`/`fim`/`erro`/`proposta`). Permissão, posse, teto de custo, anexo e proposta
   de cadastro valem como na página (regras 1 a 11 de `app/src/ChatIa/CLAUDE.md`).
2. **Lista.** O mini mostra as ConversaDeChat da própria pessoa (as mesmas da página), mais recentes primeiro, com a conversa
   aberta por último em cima; "Nova conversa" cria uma. Botão **Abrir em página inteira** leva a `/chat-ia/{id}`.
3. **Entrada com pergunta preenchida.** O evento `bj-central:perguntar-ia` (disparado por Minhas Metas, `minhas-metas-ia.js`, e
   por `window.BJCentral.perguntarIa`) continua: abre o mini numa conversa NOVA com o texto no campo, SEM enviar. O contrato de
   `central-flutuante.js` (`bj-central:perguntar-ia` → `bj-central:janela-perguntar-ia`) não muda.
4. **Pasta.** "Faça um resumo desta pasta" e a barra da pasta (`data-ia-pasta`) passam `pastaId` ao envio do Chat I.A (o servidor
   relê, como na página). A opção "outras pastas do cliente" (4c, `escopo: clientes`) vira opção da pasta citada no Chat I.A.
5. **Histórico antigo.** O conteúdo de `central_mensagem` da conversa tipo `ia` NÃO é tocado nem apagado. Uma importação
   única e idempotente por pessoa cria UMA ConversaDeChat "Conversa anterior com a BlueJus IA" (`origem = importada`): fala
   `de_ia` → assistente, as demais → usuário, tokens nulos, só do dono. Roda quando o mini abre (`POST /chat-ia/importar-central`);
   repetir não duplica (existe ConversaDeChat `importada` da pessoa = nada a fazer). Não passa o texto por log nem auditoria.
6. **Rotas e worker antigos ficam** (`/central/ia/*`, `chat_central`, `ProcessarAnaliseDeInteligenciaHandler`) até o dono validar o mini;
   a remoção é um PR separado. O mini deixa de chamá-los.

## Banco

Migration `Version20261028190000`: `chat_ia_conversa.origem VARCHAR(16) NOT NULL DEFAULT 'pagina'` (`pagina`, `central`,
`importada`). Só ADD COLUMN com default; `down` faz DROP COLUMN. A entidade `ConversaDeChat` ganha `origem` (enum
`OrigemDaConversa`); `origem` não é texto livre (entra no diff de auditoria como valor fixo).

## Desvio do desenho aprovado

O lado da tela vai num arquivo novo `public/js/central-ia-chat.js` (carregado pela janela da Central), e `central.js` só o aciona no
lugar do caminho `ehIa` antigo. Motivo: `central.js` tem mais de 2600 linhas e é arquivo quente; trocar o motor dentro dele misturaria
as duas coisas. O comportamento visível é o aprovado (UI só na Central, mesmo SSE).

## Regras que seguem valendo (Central)

`app/src/Central/CLAUDE.md`: texto da IA fora de log e audit; o JS só usa `textContent`/`createElement`; o lançador flutuante não
marca nada como lido com a janela fechada; a conversa IA continua fora de Encaminhar e Equipe (`semIa`).

## Testes

Unit: `OrigemDaConversa`, importação (idempotente, mapeia `de_ia`, só do dono). Functional: `POST /chat-ia/importar-central` (duas
chamadas = uma conversa; outro usuário não vê), criar conversa com `origem = central`, guarda de JS do mini (sem `innerHTML`,
evento `bj-central:perguntar-ia` preservado). Os testes `EncaminharNaJanelaJsTest` seguem verdes (listener intacto).

## Ajustes do teste do dono (30/10)

1. **Painel de Minhas Metas responde no painel.** `minhas-metas-ia.js` monta o mesmo mini (`BJMiniIaChat.montar`, `compacto: true`,
   `contexto: 'metas'`) na área `data-mm-ia-resposta`, abaixo das sugestões; sem barra e sem rodapé do mini (o campo é o do painel).
   Link discreto "Abrir em página inteira" para `/chat-ia/{id}`. O evento `bj-central:perguntar-ia` segue existindo (reserva se o mini não
   carregar, e para outros emissores).
2. **Contexto de metas no servidor.** O corpo do envio ganha `contexto: "metas"` (SINAL; o cliente nunca manda texto). `ContextoDasMetasNoChat`
   exige o aceite do escritório, usa só as metas da própria pessoa (mesmos recortes da tela: cadeado, `findPrioridadesDoDia`,
   `findAtrasadasParaAtencao`, novo `findAguardandoRevisao`) e entrega UM bloco `<metas>` (delimitador já em `NeutralizadorDeConteudo::DELIMITADORES`),
   com cada valor por `ProtecaoDoTextoDoChat::rotulo`. Prompt vira `chat-livre-v4` (parágrafo sobre o bloco de metas).
3. **Markdown no mini.** O renderizador seguro saiu de `chat-ia.js` para `public/js/chat-ia-markdown.js` (`window.BJMarkdown.criar`), usado pela
   página inteira e pelo mini (Central e painel de Metas). Mesmas garantias: só `createElement`/`textContent`, links http/https/mailto.

