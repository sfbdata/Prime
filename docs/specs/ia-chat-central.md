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
| 3b | **Feito (tela).** Item "BlueJus IA" fixo no topo da lista, cabeçalho setor · função, bolha `deIa` com selo IA, "digitando..." enquanto a pergunta está pendente (um GET de status por ciclo do polling), estado vazio com 3 sugestões (a do resumo da pasta veio no 4b), aviso fixo de resposta por IA, textos em `central.ia.*` por `data-ia-*`. Pendente: corrida real e o "digitando" após recarregar a página (a lista não expõe a pergunta em andamento). |
| 4 | Contexto da tela e D12 ("todas as pastas do cliente"). **4a feito (backend):** `pastaId` na pergunta, validação na solicitação e releitura no worker, prompt `chat-central-v2`. **4b feito (tela):** barra "Pasta N" e sugestão do resumo, `GET /central/ia/pasta/{pastaId}`. 4c (D12) pendente. |
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

## PR 4: contexto da tela (decisões de 27/10)

Divisão: **4a** backend (pergunta com `pastaId`, contexto da pasta no worker, sem tela), **4b** tela (barra "PASTA N · cliente"
do desenho L6001 e a sugestão "Faça um resumo desta pasta", só com pasta aberta), **4c** cliente (D12), só depois do ok do dono.

Regras do 4a:
1. O cliente manda só `pastaId` (inteiro positivo, opcional); nunca texto de contexto. O servidor revalida: pasta por id + escritório
   (404 igual a "não existe" para pasta de fora ou que a pessoa não vê, `AcessoAPasta::podeVer`), segredo de justiça (409
   `contexto_bloqueado`, mensagem fixa existente), financeiro (`VisibilidadeDoFinanceiroDaPasta`) e abas ocultas decididos na
   solicitação e gravados em `contexto_resumo` (ids e flags, nunca texto nem NUP).
2. O worker relê a pasta por id + escritório e reconfere vínculo, `podeVer`, financeiro e abas; se algo mudou, `falhou` sem chamar o provedor.
3. Contexto: `MontadorDeContextoDaPasta::para` com `Agente::Gestor`; prompt novo `chat-central-v2` com bloco `<pasta>` em
   `NeutralizadorDeConteudo::DELIMITADORES` e a mesma regra de "dados não confiáveis". Sem `pastaId`, o fluxo e o prompt v1 seguem iguais.
4. Histórico: pergunta COM pasta vai sem respostas anteriores da IA (só as perguntas da própria pessoa); a resposta antiga pode ter lido outra
   pasta ou financeiro. Aceito: a conversa é particular e a pessoa já viu a resposta.
5. Módulo `inteligencia` segue exigido (`DisponibilidadeDeInteligencia::para`).
6. 4c (D12): cruza pastas de TODOS os clientes da pasta aberta, no mesmo escritório, só as que a pessoa vê, teto de pastas e orçamento menor por pasta;
   pasta com segredo some em silêncio ("N pastas não puderam ser consultadas").

Regras do 4b (tela):
1. A janela leva `data-ia-pasta="{id}"` só em `/pasta/{id}` (`_janela.html.twig`; o `base.html.twig` não mudou). É dica de UI: o servidor revalida.
2. Ao abrir a conversa IA com a dica, o JS busca o rótulo UMA vez em `GET /central/ia/pasta/{pastaId}` (`central_ia_pasta`,
   `DescreverPastaDoContextoDaIaUseCase`): vínculo ativo (403 sem), pasta por id + escritório, não excluída, `podeVer`; qualquer falha de pasta
   = 404 `pasta_nao_encontrada` com a mensagem fixa, sem confirmar existência. Devolve só `{pastaId, nup, cliente, url}`, `Cache-Control: private, no-store`.
   Segredo de justiça NÃO é recusado aqui (a barra mostra qual pasta está em jogo); quem recusa é a pergunta (409).
3. 404 no rótulo: sem barra, sem `pastaId`, sem aviso. Com a barra (desenho L6001-6006, "Abrir pasta" é link para `/pasta/{id}`) o POST de pergunta leva `pastaId`;
   a sugestão "Faça um resumo desta pasta" (L397) entra no estado vazio só com a barra. 409 `contexto_bloqueado` e 404 `pasta_nao_encontrada` na pergunta:
   `avisar()` com a mensagem do servidor e, no 404, a barra sai.
4. Desvio consciente do desenho: o botão "Abrir pasta" é `<a>` (teclado e Ctrl+clique); na janela destacada abre em aba nova, como o chip do despacho.

## Pendências do dono (cada uma em PR próprio, só depois do ok)

- `@IA` em grupo: risco de vazamento lateral (a resposta da IA ficaria visível a quem não pediu).
- IA lendo equipe e produtividade: LGPD art. 20 (decisão automatizada sobre pessoa).
