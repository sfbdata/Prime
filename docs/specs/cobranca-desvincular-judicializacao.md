# Desvincular judicialização + travar pasta duplicada

**Data:** 2026-09-08 · **Domínio:** Cobrança (`App\Cobranca`) + Pasta (`App\Pasta`)

## 1. Problema

O dono relatou dois defeitos reais no fluxo "Judicializar" da Cobrança, confirmados por
investigação de código e por dados reais de produção (consulta somente leitura via
`mcp__jusprime-prod`):

### 1.1 — Pasta excluída trava o caso como judicializado para sempre

`CasoCobranca::estaJudicializado()` só olha o enum `status`. `ExcluirPastaUseCase` nunca toca em
`CasoCobranca` — não há listener nem evento entre os dois domínios. Se a pasta vinculada é excluída
(lápide ou remoção física), o FK `pasta_judicial_id` pode zerar (`ON DELETE SET NULL`), mas
`status` continua `Judicializado` — e não existia nenhum UseCase de desvincular/reabrir: a spec
original (`cobranca-judicializar-cria-pasta.md`) chamou a judicialização de "transição única".

Medido em produção: caso do Jerônimo Aparecido Borges Roriz tinha `pasta_judicial_id = null` e
`status = 'judicializado'` — exatamente esse travamento.

### 1.2 — A mesma pasta vinculada a dois `CasoCobranca`

O modo "vincular pasta existente" só conferia id + tenant — nenhuma checagem de que a pasta já
estivesse em uso por outro caso, nem no UseCase, nem no banco (índice comum, sem `UNIQUE`), nem na
busca da tela. Agravado por `ComporNomeDaPastaJudicial::paraCaso` não incluir a unidade — duas
pastas do mesmo devedor em unidades diferentes saem com nome idêntico na busca.

Dois casos reais confirmados em produção, ambos pelo modo "vincular":
- pasta NUP 1254 (id 1221): casos #19 (unidade 03-08) e #20 (unidade 03-09), mesma pessoa
  (Abinadabe Almeida de Sousa) — #20 vinculou primeiro (evento 2026-09-01 11:05:41), #19 depois
  (2026-09-02 11:22:08).
- pasta NUP 1279 (id 1250): casos #35 (Jeremias da Silva Dutra, 08-04) e #36 (Tadeu Henrique
  Queiroz da Silva, 08-04A) — pessoas DIFERENTES. #35 criou a pasta (2026-09-02 14:50:21), #36
  vinculou-se depois (2026-09-03 06:41:44).

## 2. Decisões do dono (2026-09-08)

1. **O cancelamento de judicialização fica disponível SEMPRE** que o caso estiver judicializado —
   não só quando a pasta estiver excluída. Reabre de propósito a "transição única" da spec
   original.
2. **Os 2 casos de dados já duplicados são corrigidos como parte desta entrega**, usando o mesmo
   fluxo de negócio (UseCase + Command), não SQL cru direto no banco.

## 3. O que foi implementado

### 3.1 — `CancelarJudicializacaoUseCase` (novo)

`app/src/Cobranca/UseCase/CancelarJudicializacaoUseCase.php`. Limpa `pastaJudicial` (mesmo que já
esteja `null`) e devolve `status` para `Ativo`. Guarda única: `!$caso->estaJudicializado()` →
`CasoNaoJudicializadoException` (um caso `Encerrado` já não é `estaJudicializado()`, o enum é de
valor único). Motivo obrigatório, registrado em `cobranca_evento_historico` com o novo tipo
`TipoEventoHistorico::JudicializacaoCancelada`.

Rota `POST /cobrancas/casos/{id}/cancelar-judicializacao`
(`cobranca_caso_cancelar_judicializacao`), mesmo gate de `judicializar()`
(`resources.cobranca.gerenciar` + módulo `pastas`). Botão "Cancelar judicialização" no cabeçalho do
objeto, no lugar de "Judicializar", gated por `caso.judicializado` (STATUS, não mais
`pastaJudicialId` — esse era parte do próprio defeito 1.1).

### 3.2 — Guarda contra duplicidade em `JudicializarCasoUseCase`

`pastaExistenteDoTenant()` agora chama `CasoCobrancaRepository::outroCasoComPastaJudicial()` antes
de devolver a pasta; se outro caso já a usa, lança `PastaJaVinculadaAOutroCasoException`. A busca de
"vincular pasta existente" (`PastaRepository::buscarParaVinculo`, parâmetro novo `$idsExcluidos`)
também para de OFERECER pastas já judicializadas por outro caso —
`CasoCobrancaRepository::pastaIdsJudicializadosDoTenant()` alimenta o filtro em
`CasoController::buscarPastas()`.

### 3.3 — Índice único parcial (defesa em profundidade)

Migration `Version20260908175331`: `CREATE UNIQUE INDEX uniq_cobranca_caso_pasta_judicial ON
cobranca_caso (pasta_judicial_id) WHERE pasta_judicial_id IS NOT NULL`. Índice funcional, fora do
mapeamento Doctrine (mesmo padrão de `uniq_cobranca_obrigacao_ref_competencia`).

⚠️ **Ordem obrigatória em produção:** aplicar essa migration SÓ DEPOIS de rodar
`app:cobranca:corrigir-pasta-judicial-duplicada --aplicar` — a criação do índice falha com
violação de unicidade enquanto os 2 casos duplicados existirem.

### 3.4 — Correção dos 2 casos duplicados

`CorrigirPastaJudicialDuplicadaUseCase` (`prever`/`confirmar`, padrão de
`ReconciliarDuplaContagemUseCase`) + comando `app:cobranca:corrigir-pasta-judicial-duplicada`
(`--tenant-id`, `--aplicar`, `--usuario-id`). Por grupo de casos que compartilham pasta, o caso com
o evento `Judicializacao`/`VinculoPasta` mais ANTIGO (não `criadoEm`, que não reflete a ordem real
do vínculo) fica com a pasta; os demais recebem pasta nova — com o nome desambiguado incluindo a
unidade (`<pessoa> (unidade <identificação>)`), já que `ComporNomeDaPastaJudicial::paraCaso()` puro
produziria o MESMO nome para os dois casos do par. Tenta reaproveitar pasta órfã compatível antes
de criar uma nova (nenhum dos 2 casos reais medidos tinha órfã disponível).

### 3.5 — `NormalizadorDePastaJudicial` (extraído)

`app/src/Cobranca/Service/NormalizadorDePastaJudicial.php` — as três ações de normalizar a pasta
(nome, ação, cliente principal), extraídas de `JudicializarCasoUseCase::normalizarPastaJudicial()`
(mesmo comportamento) para serem reusadas pela correção de duplicidade sem duplicar a regra.

## 4. O que NÃO foi feito

- **Não** se alterou o padrão de nome `ComporNomeDaPastaJudicial::paraCaso()` (continua sem a
  unidade) — só a correção de duplicidade usa nome com unidade, como caso especial. Incluir a
  unidade no padrão normal reduziria a ambiguidade na busca, mas é mudança de UX que não foi pedida
  e merece pergunta própria ao dono.
- **Não** se corrigiu o sintoma colateral em `unidadeCobradaDaPasta()` além de um `orderBy`
  defensivo — depois da migration + correção de dados, a ambiguidade que o método tolerava deixa de
  existir na prática.

## 5. Verificação

- Suíte completa de Cobrança: 2012 testes, verde.
- `lint:container`, `lint:twig`, `debug:router` conferidos.
- Comando rodado em modo simulação no dev (dataset diferente do de produção no momento — não achou
  duplicidade lá, o que é esperado e não invalida a correção, escrita e testada contra os 2 casos
  reais medidos em produção).
- **Pendente do dono:** rodar o comando (`--aplicar`) e a migration em produção, nessa ordem;
  smoke na tela do caso do Jerônimo Aparecido Borges Roriz (pasta excluída) confirmando que
  "Cancelar judicialização" aparece e funciona, e que "Judicializar" volta a funcionar depois.
