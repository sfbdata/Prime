# Chat I.A na Central (F3): conversa "BlueJus IA"

Risco MÉDIO. Fonte: `docs/specs/ia-anthropic-ligar.md` (§3 itens 13 e 14, §8 F3) e `docs/specs/designer-central-ia.md`.
Desenho: `docs/design/redesign-23-10-26/bluejus-central.js` linhas 298-304 (conversa fixa `ia`, setor "Assistente",
membros = [eu, ia]).

## Plano de PRs

| PR | Entrega |
|---|---|
| 1 | Modelo: `TipoDeConversa::Ia`, `Mensagem.de_ia`, `AbrirConversaComAIaUseCase`. Invisível: sem tela, sem rota, sem resposta da IA. |
| 2a | **Feito.** Fluxo da IA sem pasta no worker: `chat_central`, `FluxoDeAnaliseSemPasta`/`FluxoDoChatDaCentral`, `PromptDoChatDaCentral` (`chat-central-v1`), `InterpretadorDeRespostaDoChat`, porta `ConversaDaIaNaCentral`. Sem controller, rota nem UseCase de pergunta. |
| 2b | UseCase/endpoint da pergunta (grava a mensagem, cria a análise `pendente` com `contexto_resumo.mensagemId`, enfileira) e a expiração das falas da IA. |
| 3 | Interface: a conversa aparece na janela da Central; permissão de módulo da IA entra aqui. |
| 4 | Contexto da tela e D12 ("todas as pastas do cliente"). |
| 5 | Comandos "/" classe (a) (ver `designer-central-ia-comandos.md`). |

## Decisões do PR 1

1. Uma conversa IA por (escritório, usuário). Participante: só o próprio usuário (uma linha em `central_participante`).
   A unicidade existente `(tenant, usuario_menor, usuario_maior)` basta: a IA grava menor = maior = o usuário
   (`Conversa::comAIa`), e conversa individual nunca tem os dois iguais, então os tipos não colidem. Grupo tem par nulo
   e não entra. A corrida de dois cliques cai no índice (`ConversaCriadaAoMesmoTempoException`).
2. `central_mensagem.de_ia` boolean NOT NULL default false. O autor segue sendo o usuário dono; `de_ia` marca a fala da
   IA (`Mensagem::daIA`). "Não lida" = mensagem de OUTRA pessoa: `de_ia = true` conta como de outra pessoa nas
   consultas de não lidas.
3. Invisibilidade provisória: toda consulta que lista conversas, conta não lidas, devolve novidades ou resolve conversa
   por id para a tela exclui `tipo = ia`. Abrir uma conversa IA por id num endpoint existente dá 404. O PR 3 é quem
   passa a incluí-la.
4. `AbrirConversaComAIaUseCase`: idempotente, tenant e usuário explícitos, exige vínculo ativo (`AcessoACentral`), sem
   permissão de módulo da IA por ora.
5. Central é privada: as três tabelas seguem fora da auditoria e nada de texto em log. Purga de escritório já cobre
   `central_mensagem`, `central_participante` e `central_conversa`. Vínculo encerrado: `exigirVinculoAtivo` barra a
   pessoa, então a conversa IA fica inacessível (regras 1 a 9 da Central).

## TODO

- PR 2: mensagens da IA entram na expiração de 12 meses do `ExpirarConteudoDaInteligenciaUseCase` (hoje não há
  mensagem de IA para expirar).

## Pendências do dono (cada uma em PR próprio, só depois do ok)

- `@IA` em grupo: risco de vazamento lateral (a resposta da IA ficaria visível a quem não pediu).
- IA lendo equipe e produtividade: LGPD art. 20 (decisão automatizada sobre pessoa).
