# Desfazer da auditoria: as regras de ESTADO da tela (Segurança D)

Risco MÉDIO (autorização). Pedido do coordenador (24/10/2026). Continua `desfazer-guarda-da-tela.md`, que deixou "Fora
desta frente" as regras de estado: o desfazer conferia QUEM pode (a guarda da tela), mas não SE a tela deixaria fazer
a mesma mudança no estado atual do registro. Sem migration.

## Estado da frente

- 24/10: spec; implementação na worktree `seguranca-d-desfazer-estado`, commit local, sem push.
- 24/10: implementado. Testes: `DesfazerSegueOEstadoDaTelaTest` (21), unit das regras extraídas; os helpers comuns do
  desfazer foram para o trait `tests/Auditoria/Functional/ApoioDoDesfazer.php`. Dados de testes antigos ajustados ao
  `to` real (maiúsculas do setter, fuso do servidor, criação da meta antes da data de conclusão). Suítes Auditoria,
  Tarefa, Pasta e Arquitetura verdes (5423); `scripts/qa.sh` limpo.
- 24/10: revisão: a regra c olha o status de depois do desfazer; `_truncated` no `to` recusa como irrecuperável;
  `executar` dividido (até 40 linhas, saiu da lista de tolerados). `DesfazerSegueOEstadoDaTelaTest` (24).

## O problema

O `DesfazerAlteracaoAuditLogUseCase` grava o `from` do log direto pelo setter. A tela, para a mesma mudança, confere o
estado: não reabre meta que seguiu adiante, não reconclui com data impossível, não mexe na etapa de meta concluída nem
em pasta excluída, não renomeia o cliente montado pela carteira, e muda a situação da pasta com efeitos (registro,
andamento) que o setter não faz. Além disso, o desfazer gravava por cima de uma mudança posterior ao log.

## Desenho

Serviço novo `App\Auditoria\Service\EstadoDaTelaNoDesfazer`, método
`recusa(object $entidade, array $diff, \DateTimeImmutable $agora): ?string` (null = pode; texto = a recusa).
O `executar` o chama depois do `find` e da conferência de tenant e antes da fase 1. A recusa volta como
`DesfazerResultado(false, msg)`: flash de erro e 302 para a auditoria, igual a `MENSAGEM_ITEM_NA_LIXEIRA`; não é 403
(quem desfaz tem a guarda; é o estado que não deixa). Na meta, a conferência e a gravação rodam numa transação, com a
meta travada e relida (`TravaDaMeta::travarERecarregar`), como na tela.

As regras são CHAMADAS, não copiadas: onde a regra da tela estava inline ou privada, ela foi extraída para um método
público e a tela passou a chamá-lo também.

## Regras

Todas as entidades reversíveis:

- **Mudou depois.** O valor atual de cada campo do diff tem de ser igual ao `to` do log (pela forma normalizada do
  `AuditLogSubscriber`; data pelo instante; associação pelo id). Senão: "O registro mudou depois desta alteração. Nada
  foi alterado." Campo que não é coluna nem associação a um não entra aqui (a fase 1 o recusa como antes). Lista
  que o subscriber cortou (`_truncated` no `to`) não se compara: recusa com `MENSAGEM_VALOR_IRRECUPERAVEL`.

Tarefa (meta):

- **a)** Não reabrir meta que seguiu adiante: `ReabrirMetaUseCase::seguiuAdiante()`
  (`app/src/Tarefa/UseCase/ReabrirMetaUseCase.php:61`). Vale quando o `from` do `status` é aberto e o atual é
  concluído. Mensagem: a chave `metas.passagem.ja_seguiu_adiante`, traduzida.
- **b)** Data de conclusão válida ao reconcluir: extraída de `DesfazerReaberturaDaMetaUseCase.php:48-54` (nula, no
  futuro ou antes da criação) para `DesfazerReaberturaDaMetaUseCase::motivoParaRecusarAData()` (static público), usado
  pelo UseCase da tela e pelo desfazer, com as mesmas mensagens. Vale quando a meta fica concluída e `dataConclusao`
  está no diff.
- **c)** `etapa` e `etapasSaltadas` só mudam em meta aberta e com a pasta não excluída:
  `MontarEtapaDaMetaUseCase::podeMexerNaEtapa(Tarefa)` (static público, extraído de
  `MontarEtapaDaMetaUseCase.php:51`; é o `$mexe` que mostra ou esconde Concluir e Devolver). Os UseCases de passar e
  devolver já recusam a meta concluída (`PassagemRecusadaException::jaConcluida`) e a pasta excluída é barrada pelo
  `PastaSomenteLeituraListener`; a mensagem do desfazer é a de `jaConcluida`.
  Vale o status que a meta TERÁ depois do desfazer: com `status` no diff, o `from`; sem ele, o atual.
  Diferença com o `DevolverMetaUseCase`: a devolução só aceita meta `pendente` (recusa `em_revisao` com
  `devolucaoForaDePendente`), e o `podeMexerNaEtapa` aceita qualquer status aberto, inclusive `em_revisao` (o Concluir
  e passar da tela também aceita). O desfazer da etapa segue o `podeMexerNaEtapa`; a devolução não muda `etapa` nem
  `etapasSaltadas`, então não há regra dela a herdar aqui.
- **e)** Coerência: depois da reversão, concluída exige `dataConclusao` não nula e aberta exige nula. Senão: "A meta
  ficaria concluída sem data de conclusão, ou aberta com data de conclusão. Nada foi alterado." (nenhuma tela tem essa
  frase: nenhuma tela chega a esse estado).
  Em produção (consulta de 24/10 pelo MCP `jusprime-prod`, informada pelo coordenador) as metas estão coerentes: 374
  concluídas, todas com data; 180 abertas (`pendente` ou `em_revisao`), todas sem data. A regra não trava nenhuma
  meta existente.

Pasta e filhas (inclusive a meta e a mensagem da pasta):

- **g)** Pasta excluída (lápide) é somente leitura: `Pasta::estaExcluida()`. Mensagem:
  `PastaSomenteLeituraListener::MENSAGEM` (era `private const`, `PastaSomenteLeituraListener.php:85`; vira `public`).
- **h)** Nome do cliente montado pela carteira: extraída de `RenomearClienteDaPastaUseCase.php:47-50` para
  `RenomearClienteDaPastaUseCase::nomeEMontadoPelaCarteira(Pasta)` e a mensagem
  `MENSAGEM_NOME_MONTADO_PELA_CARTEIRA`. Vale para `nomeCliente` no diff da Pasta.
- **i)** `situacao` e `situacaoContrato` da Pasta têm efeitos (registro, andamento) que o setter não faz: o desfazer
  recusa com "Esta alteração só pode ser desfeita pela tela da pasta."

Bug à parte:

- `etapasSaltadas` é gravada no log como `list<string>`, mas `Tarefa::setEtapasSaltadas` espera `EtapaDaPasta` (dava
  Error, 500). O desfazer converte cada valor para o enum; valor que não é etapa recusa com
  `MENSAGEM_VALOR_IRRECUPERAVEL`.

Ordem no `recusa`: g, i, mudou depois, h, a, c, b, e.

## Testes

- Functional `tests/Auditoria/Functional/DesfazerSegueOEstadoDaTelaTest.php`: positivo e negativo para "mudou
  depois", a, b, c, e, g (meta, pasta, filha e mensagem), h, i e o bug das etapas saltadas.
- Unit: `DesfazerReaberturaDaMetaUseCase::motivoParaRecusarAData`, `MontarEtapaDaMetaUseCase::podeMexerNaEtapa`.
- O `DesfazerAlteracaoAuditLogUseCaseTest` passa a montar o serviço novo; os dados dos casos antigos seguem o `to`.
- O `DesfazerExigeAGuardaDaTelaTest` continua verde (a meta de teste nasce antes da data de conclusão do log).
- As telas originais (`tests/Tarefa`, `tests/Pasta`) continuam verdes.

## Fora desta frente

- Os demais campos da Pasta com efeito colateral além de `situacao` e `situacaoContrato` (não levantados aqui).
- Regras de estado das entidades fora de meta e pasta (chamado, evento, cadastros do escritório).
- `PassarEtapaDaMetaUseCase` e `DevolverMetaUseCase` não passam a chamar `podeMexerNaEtapa`: a recusa deles tem
  mensagens próprias e a pasta excluída já é barrada pelo listener; trocar mudaria o comportamento da tela.
