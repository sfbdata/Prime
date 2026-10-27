# Chat I.A na Central (F3): conversa "BlueJus IA"

Risco MÉDIO. Fonte: `docs/specs/ia-anthropic-ligar.md` (§3 itens 13 e 14, §8 F3) e `docs/specs/designer-central-ia.md`.
Desenho: `docs/design/redesign-23-10-26/bluejus-central.js` linhas 298-304 (conversa fixa `ia`, setor "Assistente",
membros = [eu, ia]).

## Plano de PRs

| PR | Entrega |
|---|---|
| 1 | Modelo: `TipoDeConversa::Ia`, `Mensagem.de_ia`, `AbrirConversaComAIaUseCase`. Invisível: sem tela, sem rota, sem resposta da IA. |
| 2a | **Feito.** Fluxo da IA sem pasta no worker: `chat_central`, `FluxoDeAnaliseSemPasta`/`FluxoDoChatDaCentral`, `PromptDoChatDaCentral` (`chat-central-v1`), `InterpretadorDeRespostaDoChat`, porta `ConversaDaIaNaCentral`. Sem controller, rota nem UseCase de pergunta. |
| 2b | **Feito.** `PerguntarABlueJusIaUseCase` (sem controller nem rota): vínculo, texto 1..2000, conversa da pessoa, disponibilidade da IA, trava `FOR UPDATE` da conversa, devolve a pergunta em andamento ou grava a mensagem + análise `pendente` com `contexto_resumo.mensagemId` e enfileira depois do commit. Retenção: `ConversaDaIaNaCentral::apagarMensagensDaIaAntesDe` apaga as DUAS pontas (pergunta e resposta) das conversas `ia`, com o mesmo limite da expiração. |
| 3a | **Feito (backend).** A conversa IA fica visível ao dono (lista, histórico, não lidas, novidades), a lista a traz sempre, o envio comum nela é recusado, rotas `POST /central/ia/perguntas` e `GET /central/ia/perguntas/{analiseId}`, saída do pendente preso (10 min). Sem permissão de módulo da IA por ora (só vínculo ativo). |
| 3b | **Feito (tela).** Item "BlueJus IA" fixo no topo da lista, cabeçalho setor · função, bolha `deIa` com selo IA, "digitando..." enquanto a pergunta está pendente (um GET de status por ciclo do polling), estado vazio com 3 sugestões (sem "Faça um resumo desta pasta": depende do contexto da tela, PR 4), aviso fixo de resposta por IA, textos em `central.ia.*` por `data-ia-*`. Pendente: corrida real e o "digitando" após recarregar a página (a lista não expõe a pergunta em andamento). |
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
3. Invisibilidade provisória (encerrada no PR 3a): toda consulta que lista conversas, conta não lidas, devolve novidades ou
   resolve conversa por id para a tela excluía `tipo = ia`. O PR 3a a incluiu para o DONO (único participante); a Equipe
   (`naoLidasPorUsuario(semIa: true)`) e os destinos do Encaminhar (`doUsuario(semIa: true)`) seguem sem ela.
4. `AbrirConversaComAIaUseCase`: idempotente, tenant e usuário explícitos, exige vínculo ativo (`AcessoACentral`), sem
   permissão de módulo da IA por ora.
5. Central é privada: as três tabelas seguem fora da auditoria e nada de texto em log. Purga de escritório já cobre
   `central_mensagem`, `central_participante` e `central_conversa`. Vínculo encerrado: `exigirVinculoAtivo` barra a
   pessoa, então a conversa IA fica inacessível (regras 1 a 9 da Central).

Se a pessoa sair do escritório ou da conversa durante a chamada ao provedor, a fala não é gravada e a análise termina `falhou`; o provedor já cobrou, o que é aceito.

## TODO

- ~~PR 2: mensagens da IA entram na expiração~~ Feito no 2b: o retorno do `ExpirarConteudoDaInteligenciaUseCase` ganhou
  `mensagens`; o comando mostra e registra só números.

- ~~Pergunta presa em `pendente`/`processando`~~ Feito no 3a: mais velha que 10 minutos não conta como em andamento
  e a próxima pergunta a encerra como `falhou` (`findPendenteDoAlvo(..., criadaApos)`).
- Corrida da primeira abertura: o flush que perde o índice único fecha o EntityManager, então `ListarConversasUseCase` não
  repete a busca na mesma requisição; `/central/conversas` responde 409 e a tela repete UMA vez (feito no 3b, `carregarConversas`).
- `DisponibilidadeDeInteligencia` ainda confere o módulo da IA (`sem_permissao`, 409) na pergunta, apesar de a Central
  não exigir permissão de módulo: decidir no 3b se isso fica.
- Testes de corrida real (duas conexões) e de falha entre os dois flushes: ainda a cobrir (adiados no 3b).
- Recarregar a página com a pergunta em andamento: o "digitando..." só volta quando a próxima tentativa recebe 202 (a lista não traz o `analiseId` pendente). Expor na lista no PR 4, se o dono quiser.

## Pendências do dono (cada uma em PR próprio, só depois do ok)

- `@IA` em grupo: risco de vazamento lateral (a resposta da IA ficaria visível a quem não pediu).
- IA lendo equipe e produtividade: LGPD art. 20 (decisão automatizada sobre pessoa).
