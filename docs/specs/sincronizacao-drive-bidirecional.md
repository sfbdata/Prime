# Spec — Sincronização bidirecional Drive ↔ Sistema (pastas + arquivos)

> Documento-mãe do programa de sincronização contínua entre o Google Drive do
> escritório e o jusprime. Cobre a **visão completa do programa** (4 fases) e
> detalha em nível de implementação a **primeira frente: Fase 0 (Fundação) +
> Fase 1 (Motor de Reconciliação)**. As Fases 2 e 3 ficam aqui em alto nível e
> ganharão specs próprias quando chegar a vez.

**Risco:** ALTO. Toca dado real de produção do domínio Pasta, remove uma
constraint recém-criada, integra serviço externo com credencial, e mexe na
identidade de sincronização do acervo. Exige spec (este documento), revisão
adversarial (`feature-review-agent`) antes de cada merge, e smoke manual.

**Data:** 2026-06-26 · **Status:** em brainstorming/design (não implementado)

---

## 1. Motivo

O acervo é **vivo**: o escritório cria e renomeia pastas todo dia, **nos dois
lados** (Drive e sistema), sem espelhamento. Hoje os dois divergem
(`PENDENCIAS.md` § Importação do acervo): pastas nascem no Drive e não aparecem
no sistema (e vice-versa), nomes divergem por caracteres visualmente idênticos
(en-dash `–` vs hífen `-`), não há comando de reconciliação sob demanda.

O P.O. decidiu resolver isso com **sincronização bruta e contínua**, idealmente
**bidirecional** (pasta criada num lado nasce no outro), preferindo a **API do
Google** a soluções offline como o rclone.

## 2. Decisões travadas (restrições do design)

Decididas no brainstorming de 2026-06-26. São o contrato deste documento.

| # | Eixo | Decisão |
|---|---|---|
| D1 | **Conta/local do Drive** | Google **Workspace + Shared Drive** (Drive Compartilhado). Autenticação por **service account** adicionada como membro (Gerente de Conteúdo) — server-to-server, sem OAuth de usuário, sem domain-wide delegation. |
| D2 | **Escopo** | **Pastas E arquivos**, **bidirecional**. Tratado como **programa em fases**, não uma entrega única. |
| D3 | **Latência (destino final)** | **Quase instantânea** — orientada a eventos + reconciliação periódica como rede de segurança. |
| D4 | **Ordem de construção** | **Reconciliação primeiro**: correção (consistência eventual) antes de velocidade. A baixa latência é construída por cima depois. Nada é descartável. |
| D5 | **Exclusões** | A sincronização **NUNCA propaga exclusão**. Só cria e atualiza vínculo. Excluir num lado não toca no outro. |
| D6 | **Multi-tenant** | Esta frente cobre **só o tenant 1 (Dr. Farlei)**, único em prod. Shared Drive vem de config (env var) e é parâmetro do client/motor → generalizar depois é trivial, sem retrabalho. |
| D7 | **Não-destruição** | Os dados existentes (941 pastas, ~10.560 arquivos, 468 seções, + metas/observações/mensagens/marcadores) **não podem ser perdidos**. Backfill **vincula**, não reconstrói. |
| D8 | **Arquivos grandes** | Sincronizados **intactos** via upload resumável (sem limite de 65 MB, **sem recompressão**). Preserva o original de prova e a assinatura digital. Compressão continua opt-in e separada do sync. |
| D9 | **Pasta do Drive sem NUP extraível** | **Pular + relatório** (não cria automaticamente). Tratamento manual com o Dr. Farlei, como na Fase 0 do acervo. |
| D10 | **Divergência de nome entre os lados** | **Só reporta** (vínculo é por ID, divergência é cosmética). Sem renomeação automática nesta frente. |
| D11 | **Fase 3 (Drive→sistema instantâneo)** | **Opcional e por último.** Só será considerada depois que Fases 0–2 estiverem prontas; a decisão de implementá-la (ou não) é **posterior**. Deferir não quebra a bidirecionalidade — a reconciliação (Fase 1) já cobre Drive→sistema em latência periódica. |
| D12 | **Carga inicial manual antes do automático** | A primeira convergência dos dois lados é **manual e supervisionada** (Fase 1a), com a disciplina da carga de maio (backup → dry-run → amostra → conferência → lote). O cron (automático, Fase 1b) **só liga depois** que a carga manual provar que está tudo alinhado. |

## 3. Visão do programa (4 fases)

Todas as fases chegam ao mesmo destino de D3 (bidirecional, pastas+arquivos,
quase instantâneo). A ordem segue D4.

| Fase | Entrega | Infra nova |
|---|---|---|
| **Fase 0 — Fundação** | Service account + `GoogleDriveClient` + vínculo nas entidades + backfill não-destrutivo das 941 pastas e ~10.560 arquivos. | dependência `google/apiclient`, secret montado, migration |
| **Fase 1a — Reconciliação manual** | Comando `app:sync:reconciliar` rodado **à mão, supervisionado** (dry-run → amostra → lote), converge os dois lados uma vez. **Primeiro sync funcional.** | — (usa o motor; sem cron ainda) |
| **Fase 1b — Reconciliação automática** | Mesmo comando, agora **agendado por cron**. Só liga depois da 1a provar (D12). | cron na VPS, lock de execução |
| **Fase 2 — Baixa latência sistema→Drive** | Hook assíncrono na criação/upload → reflete no Drive em segundos. | Symfony Messenger + container worker em prod |
| **Fase 3 — Baixa latência Drive→sistema** *(opcional, por último — D11)* | Push notification (watch channel) do Drive → reflete no sistema em segundos. **Só se decidido depois.** | endpoint webhook público, renovação de canal |

**Esta spec implementa Fase 0 + Fase 1 (1a manual e 1b automática).** Fases 2 e 3 estão em §15.

**Estado final realista (sem a Fase 3):** após Fases 0–2, sistema→Drive fica
instantâneo (segundos) e Drive→sistema fica periódico (~15 min, via
reconciliação). É o perfil "misto". A Fase 3 só existe se, mais tarde, o
Drive→sistema instantâneo se provar necessário.

## 4. Princípio nº 1 — Não-destruição (D7)

A garantia de que nada existente se perde não é sorte; é uma propriedade do
design, sustentada por três pilares:

1. **O backfill vincula, não reconstrói.** As 941 pastas já existem com tudo
   dentro. A fundação só escreve **uma coluna nova** (`drive_folder_id`) em cada
   Pasta existente, via `mapeamento.csv` (drive_id ↔ pasta_id). É um
   `UPDATE pasta SET drive_folder_id = ... WHERE id = ...`: não recria pasta, não
   apaga nada, não toca em arquivo nem em campo algum além da coluna de vínculo.

2. **Dados que só existem no sistema são intocáveis pelo Drive — por natureza.**
   O Drive só conhece **pastas e arquivos**. Não tem o conceito de meta
   (checklist), observação (detalhes/financeira), mensagem, marcador,
   responsável, prioridade ou situação. O motor de sync nunca lê nem escreve
   esses dados → não há de onde o Drive sobrescrevê-los. Esse acervo de
   informação rica é 100% exclusivo do sistema e fica fora do alcance do sync.

3. **O sync é aditivo e nunca apaga (D5).** Só cria o que falta de um lado e liga
   o que já existe.

### 4.1 Risco real: duplicação de arquivos (e como é eliminado)

Os ~10.560 arquivos já foram copiados do Drive em maio/2026. Sem cuidado, a
primeira rodada do motor veria "Drive tem arquivo X; sistema tem X mas sem
`drive_file_id`" e **criaria uma cópia duplicada**.

Por isso a Fase 0 inclui um **backfill em nível de arquivo**, executado *antes*
de o motor ligar: para cada Pasta vinculada, lista os arquivos da pasta
correspondente no Drive e casa cada `PastaDocumento` existente por
`nome_original` (mesma chave de idempotência da carga de maio —
`CopiarArquivosAcervoCommand`), gravando o `drive_file_id`. Com o vínculo no
lugar, o motor reconhece "já sincronizado" e não duplica. **Sem este passo, a
primeira rodada poluiria o acervo** — é item obrigatório e testável.

### 4.2 Fluxo de preservação

```
Estado atual: 941 pastas, 10.560 arquivos, 468 seções, + metas/observações/...
        │
        ▼
Backfill de pastas   → grava drive_folder_id nas 941 (UPDATE de 1 coluna)
Backfill de arquivos → grava drive_file_id casando por nome_original (anti-duplicação)
        │
        ▼
Motor de reconciliação (aditivo, nunca apaga):
   • Pasta sem par no Drive   → cria pasta no Drive
   • Pasta do Drive sem par   → cria Pasta no sistema (NÃO toca nas existentes)
   • Arquivo sem par          → copia para o lado que falta
   • metas/observações/etc.   → IGNORADOS (Drive não os conhece → impossível perder)
```

## 5. Escopo desta spec (Fase 0 + Fase 1)

**Dentro:**
- Pré-requisitos de ops da service account + Shared Drive (§6).
- Vínculo `drive_folder_id` / `drive_file_id` nas entidades (§7).
- Remoção da unicidade de NUP, preservando o isolamento de tenant (§7.2).
- Ajuste do `CriarPastaUseCase` (§7.3).
- `GoogleDriveClient` + interface (§8).
- Backfill não-destrutivo de pastas e arquivos (§9).
- Motor de reconciliação por cron, bidirecional, aditivo (§10).
- Políticas de borda D8/D9/D10 (§11).

**Fora:** ver a lista canônica de não-objetivos em **§14**.

## 6. Pré-requisitos de ops (tarefas humanas, fora do código)

Bloqueiam a Fase 0; precisam ser feitos antes de qualquer execução em prod.

1. **Projeto no Google Cloud** + habilitar a **Google Drive API**.
2. **Service account** criada; gerar **chave JSON**.
3. **Shared Drive** "GRUPO PRIME" → adicionar a service account como **Gerente de
   Conteúdo** (Content Manager) do Drive Compartilhado.
4. **Capturar o `driveId`** do Shared Drive (o ID raiz) → vai numa env var.
5. **Montar a chave JSON** como arquivo read-only no container de prod (ex.
   `/run/secrets/google-drive-sa.json`), **fora do git** (o repositório é
   público). Caminho exposto via env var.
6. **Cota do Shared Drive:** confirmar espaço suficiente para o acervo
   (~15–24 GB + crescimento).
7. **Backup de uploads + disco (BLOQUEANTE da Fase 1a):** o backup de uploads
   está desligado desde jun/2026 por **disco subdimensionado** (pendência CRÍTICA
   em `PENDENCIAS.md`). O pull-down Drive→sistema cresce o volume, então **disco e
   backup precisam ser resolvidos juntos antes do passo 1 da Fase 1a** (§10.6). É
   frente de ops, não código desta feature; pode correr em paralelo à construção
   da Fase 0, mas **trava a execução da carga manual**.

> Os itens 1–5 destravam a **construção** da Fase 0. Os itens 6–7 destravam a
> **execução** da carga manual (Fase 1a). As duas frentes podem correr em paralelo.

## 7. Modelo de dados

### 7.1 Novos vínculos (a identidade de sincronização)

- `Pasta.driveFolderId` — `?string`, nullable, **índice único**. ID da pasta no Drive.
- `Pasta.driveSyncedAt` — `?DateTimeImmutable`, nullable. Último sync bem-sucedido
  (observabilidade; opcional mas recomendado).
- `PastaDocumento.driveFileId` — `?string`, nullable, **índice único**. ID do arquivo no Drive.

> Único + nullable é seguro em PostgreSQL (múltiplos NULL são permitidos).
> A **identidade de sincronização passa a ser o `drive_folder_id` / `drive_file_id`**,
> nunca mais o NUP.

### 7.2 Remoção da unicidade de NUP — preservando o isolamento de tenant

- **Hoje:** constraint `uniq_pasta_tenant_nup` UNIQUE `(tenant_id, nup)` (criada em
  `Version20260625203433`, spec `pasta-expediente-isolamento-tenant.md`).
- **Mudança:** **dropar o UNIQUE**. Manter `nup` como coluna comum + **índice
  não-único** `(tenant_id, nup)` para busca rápida.
- **Garantia explícita:** isso **NÃO desfaz o isolamento multi-tenant**. O
  isolamento é a coluna `tenant_id` + o filtro Doctrine de tenant — ambos
  **permanecem**. Cai apenas a *unicidade*, não o *escopo*.
- **Por que é seguro:** com identidade por `drive_folder_id`, dois NUPs iguais (no
  Drive ou no sistema) viram pastas distintas sem conflito — que é justamente o
  pré-requisito do P.O.

### 7.3 Mudança no `CriarPastaUseCase` (e no `EditarPastaUseCase`)

Hoje lança `InvalidArgumentException` se o NUP já existe no tenant
(`app/src/Pasta/UseCase/CriarPastaUseCase.php:34`). Com NUP repetível, **essa
checagem sai**. **A mesma barreira existia no `EditarPastaUseCase` (linha 32) e
também foi removida** — descoberto na implementação; a redação original desta
seção citava só o Criar. Ambos os testes foram ajustados junto.

Consequências documentadas:
- O teste unitário do UseCase muda junto (o caso "NUP duplicado lança exceção"
  deixa de valer).
- O `ImportarAcervoCommand` dependia desse throw para idempotência por NUP. Com a
  unicidade removida, re-executá-lo cria duplicatas → **risco de produção R2 no
  registro de riscos (§17)**. Comportamento: a dedup do futuro é por
  `drive_folder_id` (feita pelo motor); o `ImportarAcervoCommand` é legado de uma
  frente concluída. Documentar aviso no cabeçalho do comando.

### 7.4 Migration

Uma nova `Version…`:
- `up()`: drop do UNIQUE `uniq_pasta_tenant_nup`; cria índice não-único
  `(tenant_id, nup)`; adiciona `pasta.drive_folder_id` (+ índice único) e
  `pasta.drive_synced_at`; adiciona `pasta_documento.drive_file_id` (+ índice único).
- `down()`: reversível (remove colunas/índices novos; recria o UNIQUE — ciente de
  que o `down` falha se já houver NUP duplicado, comportamento aceitável de rollback).

## 8. `GoogleDriveClient` (a fronteira de integração)

- **Domínio:** novo `App\Sync\` (`Service/`, `Command/`). Mantém a feature coesa e
  fora do legado; o vínculo continua nas entidades de Pasta.
- **Biblioteca:** `google/apiclient` (SDK oficial). Resolve JWT da service
  account, refresh de token e **upload/download resumável** (essencial para
  arquivos grandes — D8). Custo: +1 dependência, aceitável. Alternativa
  rejeitada: reimplementar OAuth2 JWT + resumable na mão sobre `http-client` é
  fonte clássica de bug sutil.
- **Auth:** chave JSON da service account via arquivo montado (path por env var).
  Padrão de secret-via-env já usado pelo `DatajudClient`.
- **Config:** env vars `GOOGLE_DRIVE_CREDENTIALS` (path do JSON) e
  `GOOGLE_DRIVE_SHARED_DRIVE_ID` (o `driveId`), injetadas por bind em
  `services.yaml` (mesmo padrão de `DATAJUD_API_KEY`).

### 8.1 Interface `GoogleDriveClientInterface`

Permite *fake* nos testes (nenhum teste toca o Drive real). Métodos:

| Método | Retorno | Uso |
|---|---|---|
| `criarPasta(string $nome, string $parentId): string` | drive folder id | sistema→Drive |
| `renomearPasta(string $folderId, string $novoNome): void` | — | futuro (D10 hoje só reporta) |
| `listarSubpastas(string $parentId): array` | `[{id, nome}]` | Drive→sistema |
| `listarArquivos(string $folderId): array` | `[{id, nome, tamanho, mime, modificadoEm}]` | reconciliação de arquivos |
| `enviarArquivo(string $folderId, string $nome, string $path, string $mime): string` | drive file id | sistema→Drive (resumável) |
| `baixarArquivo(string $fileId, string $destinoPath): void` | — | Drive→sistema (streaming) |
| `listarMudancas(string $pageToken): array` | `{mudancas, novoToken}` | preparado p/ Fase 3 (changes feed) |

Todas as chamadas encapsulam os parâmetros de Shared Drive
(`supportsAllDrives=true`, `includeItemsFromAllDrives=true`, `corpora=drive`,
`driveId`), de modo que os chamadores não precisam conhecê-los.

## 9. Backfill não-destrutivo (Fase 0)

Comandos one-time, com `--dry-run` e **relatório de reconciliação** (quantas
vinculadas, quantas já tinham vínculo, quantas órfãs de cada lado). Seguem o
padrão dos comandos de acervo existentes.

1. **`app:sync:backfill-pastas --tenant-id=1 [--dry-run]`**
   - Lê `mapeamento.csv` (drive_id ↔ pasta_id).
   - `UPDATE pasta SET drive_folder_id = :driveId WHERE id = :pastaId AND tenant_id = 1`.
   - Idempotente (pula quem já tem vínculo). Relatório ao fim.

2. **`app:sync:backfill-arquivos --tenant-id=1 [--dry-run] [--pasta-id=N]`**
   - Para cada Pasta com `drive_folder_id`: `listarArquivos` no Drive, casa cada
     `PastaDocumento` por `nome_original`, grava `drive_file_id`.
   - Relata: vinculados, sistema-sem-par-no-Drive, Drive-sem-par-no-sistema
     (estes últimos serão criados depois pelo motor, não aqui).

**Conferência humana obrigatória** entre o backfill e o primeiro `reconciliar`
real: revisar o relatório (vínculos esperados ≈ 941 pastas; órfãos dentro do
esperado pelas pendências conhecidas). Sem isso, o motor pode agir sobre uma base
mal-vinculada.

## 10. Motor de reconciliação (Fase 1)

### 10.1 Como roda

- Comando **`app:sync:reconciliar --tenant-id=1 [--dry-run] [--pasta-id=N] [--limit=N]`**.
- Agendado por **cron na VPS** (reaproveita o padrão de `scripts/backup.sh`).
- `--dry-run` com transação revertida (padrão `ImportarAcervoCommand`).
- **Lock de execução** (flock) para duas rodadas não se sobreporem.
- **Consistência eventual** (periódica). A baixa latência vem nas Fases 2/3.

### 10.2 Identidade e idempotência

Sempre por **ID guardado** (`drive_folder_id` / `drive_file_id`), **nunca por
NUP**. NUP é apenas metadado e pode repetir.

### 10.3 Algoritmo (aditivo — nunca apaga)

Por rodada, dentro do tenant 1 e do Shared Drive configurado:

1. **Pastas sistema→Drive:** cada `Pasta` sem `drive_folder_id` → `criarPasta` no
   Shared Drive (nome pela convenção §11.1), grava o id retornado e `drive_synced_at`.
2. **Pastas Drive→sistema:** `listarSubpastas` da raiz; para cada subpasta cujo id
   **não** é `drive_folder_id` de nenhuma Pasta → extrai NUP/cliente do nome
   (`AcervoNomesParser`) e cria uma `Pasta` nova, gravando o vínculo. **Não toca
   nas pastas existentes.** Se o NUP não for extraível → §11.2 (pula + relatório).
3. **Arquivos (por Pasta vinculada):**
   a. **sistema→Drive:** cada `PastaDocumento` sem `drive_file_id` →
      `enviarArquivo` (resumável, intacto, sem compressão — D8), grava o id.
   b. **Drive→sistema:** cada arquivo do Drive cujo id **não** é `drive_file_id`
      no sistema → `baixarArquivo` (streaming) e cria `PastaDocumento`
      (`categoria=DEMAIS`; subpasta→seção, sub-subpasta achatada — mesmas regras
      de `CopiarArquivosAcervoCommand`).
4. **Divergência de nome:** se o nome da Pasta e o nome da pasta no Drive
   divergirem → **só registra no relatório** (D10). Não renomeia.

### 10.4 Robustez

- Erros por item são logados e **não abortam** a rodada (padrão da carga de maio).
- `flush()` + `em->clear()` por pasta — evita OOM (lição do
  `CopiarArquivosAcervoCommand`, fix pós-OOM de 30/05).
- Backoff/retry em erro transitório da API; respeitar rate limit do Drive.
- **Relatório de reconciliação** ao fim: criadas (cada via), vinculadas, puladas,
  divergências de nome, arquivos grandes, erros — tabela no padrão dos comandos
  de acervo.

### 10.5 Por que não duplica nem perde nada

Identidade por ID guardado + backfill prévio (§4.1) + nunca apaga (D5). NUP
repetido no Drive vira duas Pastas distintas (cada uma com seu
`drive_folder_id`), sem conflito.

### 10.6 Rollout supervisionado — Fase 1a (manual) antes da 1b (cron) — D12

A primeira convergência é a rodada mais perigosa (maior volume; remove o UNIQUE
de NUP; primeiro pull-down em massa). Por isso ela é **manual e supervisionada**,
repetindo a disciplina que deu certo na carga de maio (0 erros). Só depois de
provar é que o cron (Fase 1b) liga.

🔒 = portão humano bloqueante (STOP — exige aprovação explícita para prosseguir).

```
🔒 PRÉ: backup de uploads + disco resolvidos (§6 item 7) · service account pronta (§6)
   1. Backup (banco + uploads)
   2. Backfill pastas + arquivos      → dry-run, depois real (vincula o que já existe; §9)
🔒 3. Conferir relatório do backfill  → ~941 vínculos esperados; órfãos dentro do previsto
🔒 4. reconciliar --dry-run           → aprovar o que SERÁ criado nos dois lados
🔒 5. reconciliar --limit=N           → amostra; conferência visual no bluejus
🔒 6. reconciliar (lote completo)     → conferência final
        │  (só depois que todos os portões 🔒 passarem)
   7. Ligar o cron                    → Fase 1b (sincronização automática)
```

O passo 2 (backfill **antes** de qualquer criação) é inegociável: é o que impede a
duplicação dos ~10.560 arquivos (§4.1, risco R1). Cada portão 🔒 é um ponto onde a
execução **para** até aprovação humana — nenhum passo seguinte roda sem o anterior
ter sido conferido.

## 11. Políticas de borda

### 11.1 Convenção de nome (sistema→Drive)

Pasta criada no sistema gera pasta no Drive nomeada **`<NUP> - <nomeCliente>`**
(e `- <nomeAcao>` se houver). Espelha a convenção do acervo original e garante que
o `AcervoNomesParser` consiga re-extrair o NUP num round-trip Drive→sistema.

### 11.2 Pasta do Drive sem NUP extraível (D9)

**Não cria** automaticamente. Registra num relatório de pendências para
tratamento manual com o Dr. Farlei (como na Fase 0 do acervo).

**NUP extraível** = o primeiro segmento do nome casa `^\d+[A-Za-z]?$` — dígitos com
**sufixo opcional de uma letra** (ver §11.5). "10", "10A", "10B" são válidos;
qualquer outro primeiro segmento cai aqui (pula + reporta). Implementado em
`ReconciliarCommand::ehNupValido`. O regex aceita letra minúscula por tolerância,
mas `Pasta::setNup` normaliza para maiúsculo — recomenda-se padronizar **maiúsculo
no Drive** para não criar "10a" e "10A" como pastas distintas.

### 11.3 Divergência de nome (D10)

**Só reporta.** Vínculo é por ID; divergência é cosmética. Renomeação automática
fica para fase futura.

### 11.4 Arquivos grandes (D8)

Sincronizados **intactos** via upload/download resumável. Sem limite de 65 MB
(aquele era do upload via navegador/PHP, irrelevante server-to-server). **Sem
recompressão** — preserva original de prova e assinatura digital. Resolve de
quebra a pendência dos 19 arquivos > 65 MB de maio.

### 11.5 NUP repetido

Permitido por design (§7.2). Cada pasta tem identidade própria por
`drive_folder_id`.

**Desambiguação por letra (decisão do P.O.):** números repetidos são resolvidos
**no Drive** anexando uma letra maiúscula ao número — `10A`, `10B` (dois casos
distintos que compartilhavam o `10`). O sistema **aceita letra no NUP**: coluna
`nup` é `VARCHAR(255)` (input validado por `Assert\Length(max: 50)`), `setNup` sobe
pra maiúsculo, o motor cria a Pasta (§11.2) e as **três listas paginadas de pasta do
Expediente** (`PastaRepository::findByFilters`, `findPorMarcador`,
`findByFiltrosEMarcador`) ordenam de forma **natural** (`10 → 10A → 10B → 11`) via
`CAST_INT_PREFIXO` (prefixo numérico + `p.nup` como desempate). Isso é o que garante
que `10A`/`10B` caiam na **mesma página** que `10` (a paginação é server-side); o
sort client-side dessas telas (`parseFloat`) reordena dentro da página preservando o
desempate. Fora do escopo: "Minhas Demandas" (ordena por prioridade/data) e o
`<select>` de filtro de NUP mantêm ordenação lexicográfica pré-existente. A mudança
converte os pares de `nup_repetido` do relatório de pendências em pastas
bem-formadas, importadas automaticamente pela reconciliação.

> **Follow-up (pendente):** a tela **"Minhas Demandas"**
> (`demandas.html.twig` → `findAtivasPorResponsavel`, ordena por prioridade/data)
> reordena por NUP no cliente via `localeCompare` puro, então NÃO segue a ordem
> natural (mostra 9, 11, 10B, 10A, 10). Decisão do P.O.: tratar depois para também
> ficar natural. Não bloqueia esta frente. (Outro follow-up opcional: endurecer o
> `AcervoNomesParser` para o caso raro "10A - VAZIA", hoje não filtrado como vazia.)

### 11.6 Hierarquia de pastas no Drive (declaração canônica)

Mapeamento de níveis entre o Shared Drive e o sistema (referenciado por §10.3):

| Nível no Drive | Vira no sistema |
|---|---|
| Raiz do Shared Drive | — (contêiner das pastas de caso) |
| Pasta filha da raiz | **`Pasta`** (caso jurídico; NUP no nome) |
| Subpasta da pasta de caso | **`PastaSecao`** |
| Sub-subpasta (e mais fundo) | achatada → arquivos vão para a **seção avó** |
| Arquivo na raiz da pasta de caso | `PastaDocumento` **sem seção** (Documentação Geral) |

Reaproveita as regras da carga de maio (`CopiarArquivosAcervoCommand`).

## 12. Transversais

### 12.1 Multi-tenant (D6)

Só tenant 1. `driveId` vem de env var e é parâmetro do client/motor →
generalização futura (campo `driveSharedDriveId` por Tenant + varredura de todos
os tenants) é trivial. Isolamento de tenant preservado (§7.2).

### 12.2 Segurança

- Chave da service account **fora do git** (repo público).
- **Menor privilégio:** a SA só enxerga o único Shared Drive compartilhado com ela.
- **Escopo de tenant:** as queries/criações do motor são escopadas ao `--tenant-id`. Porém,
  na Fase 0/1, o **Shared Drive é único/global** (uma env var), então o motor **não** amarra
  o tenant a um Drive próprio — rodar `--tenant-id` diferente do dono do Drive é **erro de
  operador** (dívida D6, aceita: só tenant 1 em prod). Mitigação: o comando **loga tenant +
  Shared Drive no início** para conferência supervisionada. Amarrar Drive por tenant fica para
  quando o multi-tenant for generalizado (campo `driveSharedDriveId` por Tenant).
- **`--usuario-id` sem validação de pertencimento ao tenant** (mesma dívida aceita do
  `ImportarAcervoCommand`): o `criadoPor` das pastas nascidas no Drive pode não pertencer ao
  tenant. Aceito para operação manual supervisionada com IDs conhecidos.

### 12.3 Pré-condição de risco — backup de uploads

A via Drive→sistema **baixa arquivos e faz o volume crescer**, e os uploads estão
**sem backup desde jun/2026** por **disco subdimensionado** (pendência CRÍTICA em
`PENDENCIAS.md`). Disco e backup se gatilham e precisam ser resolvidos juntos.
**Bloqueante da execução da Fase 1a** (pré-requisito §6 item 7, passo PRÉ do
§10.6), não da construção da Fase 0.

## 13. Testes

- **Unit do motor** com `GoogleDriveClientInterface` *fake* (Drive em memória) +
  Foundry para Pastas/Documentos. Provar: aditivo, idempotente, **não duplica**,
  **não apaga**, respeita vínculo por ID, NUP repetido vira pastas distintas.
- **Unit dos backfills** com fixtures + fake do Drive: vincula corretamente, é
  idempotente, não toca campos além do vínculo.
- **Unit ajustado do `CriarPastaUseCase`:** remove o caso "NUP duplicado lança".
- **`GoogleDriveClient` real:** thin; testado manualmente contra um Shared Drive
  de teste, fora do CI.
- Segue `app/tests/CLAUDE.md` (DAMA, Foundry v2, PHPUnit attributes, sem mock de
  EntityManager).

## 14. Não-objetivos (explícitos)

- Eventos / baixa latência / Messenger / webhooks → Fases 2 e 3.
- Renomeação automática entre lados (D10).
- Compressão dentro do sync (D8).
- Propagação de exclusão (D5).
- Multi-tenant generalizado (D6).
- Sync de metas, observações, mensagens, marcadores (não existem no Drive).
- Resolução de conflito de *conteúdo* de arquivo (mesmo nome, conteúdos
  diferentes nos dois lados) — nesta frente, identidade é por ID; arquivo novo num
  lado é criado no outro, não há merge de versões. Tratar em fase futura se surgir.

## 15. Fases futuras (alto nível)

### Fase 2 — Baixa latência sistema→Drive
- Configurar **Symfony Messenger** (transport doctrine) + **container worker** em
  prod (`messenger:consume`) no `docker-compose.prod.yml` + no deploy.
- Hook na criação/upload/rename de Pasta enfileira mensagem → handler chama o
  `GoogleDriveClient`. Não bloqueia a request; falha do Drive não derruba o
  usuário (retry pela fila). O motor de reconciliação continua como rede de segurança.

### Fase 3 — Baixa latência Drive→sistema *(opcional, por último — D11)*
- **Deferida por decisão (D11):** só será considerada depois que Fases 0–2
  estiverem prontas, e a decisão de implementá-la é posterior. Enquanto não
  existir, Drive→sistema continua funcionando em latência periódica pela Fase 1.
- Se implementada: **push notification** do Drive (watch channel) → endpoint
  webhook público HTTPS → enfileira import. Renovação periódica do canal (expira).
  Usa o `listarMudancas` (changes feed) já previsto no client. Reconciliação
  periódica permanece como rede de segurança (webhooks falham/perdem eventos).

## 16. A confirmar na implementação (não bloqueiam a spec)

- Caminho exato e mecanismo de montagem do secret no container de prod.
- Periodicidade do cron de reconciliação (sugestão inicial: a cada 15 min).
- Formato/local do relatório de reconciliação (stdout + arquivo? persistir
  resumo da última rodada?).
- Confirmar o `driveId` raiz correto do Shared Drive "GRUPO PRIME".

## 17. Registro de riscos consolidado

| # | Risco | Mitigação | Residual |
|---|---|---|---|
| **R1** | Duplicação dos ~10.560 arquivos na 1ª reconciliação (sistema tem o arquivo, mas sem `drive_file_id`). | Backfill por `nome_original` **antes** do motor (§4.1, §9). | Arquivos renomeados no Drive desde maio podem não casar e duplicar → o portão 🔒 de conferência do backfill (§9/§10.6) detecta antes do lote. |
| **R2** | Re-executar `ImportarAcervoCommand` cria duplicatas (NUP não é mais único) (§7.3). | Comando é legado de frente concluída; aviso no cabeçalho; dedup futura por `drive_folder_id`. | Erro humano rodando o comando legado. Mitigar com guarda/aviso explícito no comando. |
| **R3** | Backup de uploads ausente + disco subdimensionado durante o pull-down Drive→sistema (§12.3). | Pré-requisito bloqueante §6 item 7; portão 🔒 PRÉ do §10.6. | Eliminado enquanto o portão PRÉ for respeitado (carga não roda sem backup+disco). |
| **R4** | `down()` da migration falha se já houver NUP duplicado (§7.4). | Rollback documentado como condicional; backup pré-migration. | Rollback de schema indisponível após existirem duplicatas — aceito (raro em prod; backup cobre a recuperação). |
| **R5** | Remoção do UNIQUE de NUP afeta buscas "por NUP" na UI (podem retornar mais de uma pasta). | Identidade de sync por `drive_folder_id`; isolamento de tenant preservado (§7.2). | Telas que assumem NUP único podem precisar de ajuste — **fora do escopo desta spec**; registrado como follow-up. |
| **R6** | Rate limit / indisponibilidade da API do Drive na carga grande. | Backoff/retry; erros por item não abortam a rodada; idempotência permite re-rodar. | Carga longa pode exigir múltiplas passadas — aceitável. |

## 18. Critério de aceite por fase (DoD)

**Fase 0 — Fundação — pronta quando:**
- Migration gerada por `diff` e **provada (up/down) no banco de teste isolado** com
  `schema:validate` OK: UNIQUE de NUP removido; `drive_folder_id`, `drive_synced_at`,
  `drive_file_id` + índices criados; `down()` reversível. (Aplicação no dev/prod fica
  para o deploy supervisionado da Fase 1a — não se toca o `saas` compartilhado.)
- `GoogleDriveClient` + `GoogleDriveClientInterface` implementados, com *fake* para testes.
- `CriarPastaUseCase` **e `EditarPastaUseCase`** ajustados (barreira de NUP duplicado
  removida) + testes atualizados verdes.
- Comandos `backfill-pastas` e `backfill-arquivos` existem, com `--dry-run` e relatório
  dos dois lados (sistema↔Drive).
- Suíte verde (fake, backfills, UseCases).
- `GoogleDriveClient` validado manualmente contra um Shared Drive de teste.

> O comando `reconciliar` (motor de reconciliação) **não** é da Fase 0 — é da Fase 1
> (§3). Terá plano/DoD próprios.

**Fase 1a — Reconciliação manual — pronta quando:**
- Pré-requisitos §6 (incl. backup + disco, item 7) verdes.
- Backfill executado em prod; relatório conferido (≈941 vínculos de pasta;
  vínculos de arquivo dentro do previsto) — **portão 🔒**.
- `reconciliar --dry-run` revisado e aprovado — **portão 🔒**.
- Amostra (`--limit=N`) com 0 erros e conferência visual no bluejus — **portão 🔒**.
- Lote completo com 0 erros e conferência final — **portão 🔒**.
- Nenhuma duplicação de pasta/arquivo detectada.

**Fase 1b — Automático (cron) — pronta quando:**
- Cron configurado com lock (flock).
- Rodadas em regime estáveis (deltas pequenos, 0 erros) por período acordado.
- Relatório de cada rodada acessível.

**Fases 2 e 3:** DoD definido nas specs próprias.

## 19. Acompanhamento (estado por fase)

> Tracker específico deste programa. O `PENDENCIAS.md` continua sendo o tracker
> global do projeto. **Atualizar esta tabela no mesmo commit da mudança que ela
> descreve.** Legenda: ✅ concluída · ◐ em curso · ⬜ não iniciada · ⏸ adiada.

| Item | Estado | Nota |
|---|---|---|
| Design / spec | ✅ | 2026-06-26 (este documento) |
| Pré-requisitos de ops (§6) | ◐ | **Auth resolvida por OAuth** (conta do rclone; app `bluejus-sync`, commit `acc44c1`) em vez de service account/Shared Drive; leitura validada (1051 pastas). Falta: re-auth com escopo de escrita + token durável, e **backup+disco** (bloqueia a execução da Fase 1a) |
| Fase 0 — Fundação | ✅ | 2026-07-01 — implementada e revisada (Tasks 1–5); suíte 876/876; migration provada no banco de teste isolado (aplicação real na Fase 1a) |
| Fase 1 — motor (código): PASTAS | ✅ | 2026-07-01 — `app:sync:reconciliar` bidirecional de pastas, revisado; suíte 886/886 |
| NUP alfanumérico (10A/10B) | ✅ | 2026-07-01 — desambiguação de repetidos; motor aceita `^\d+[A-Za-z]?$` (`ehNupValido`), ordenação natural via `CAST_INT_PREFIXO`; commits `7c16e8e`+`a2671be`; sem migration |
| Saneamento do Drive: nº repetidos | ✅ | 2026-07-01 — 40 pastas → `10A`/`10B` renomeadas via rclone (ID preservado, conteúdo intacto); nup_repetido 40→0. Vazias/sem-cliente/descartáveis (25) aguardam P.O. |
| Fase 1 — motor (código): ARQUIVOS | ✅ | 2026-07-09 — `app:sync:reconciliar` estendido para arquivos por pasta vinculada: **sistema→Drive** (doc sem `drive_file_id` → `enviarArquivo`; seção vira **subpasta-espelho por nome** no Drive) e **Drive→sistema** (varredura recursiva §11.6, sub-subpasta achatada p/ a seção-avó; cria `PastaDocumento`). Google-native pulado; **flush+clear por item** (idempotente por `drive_file_id`, nunca re-duplica na re-execução); guards de nome>255, tamanho INT4 e UNIQUE global de `drive_file_id`. Suíte **900/900**; revisado 2× pelo `feature-review-agent` (10 furos + 1 regressão corrigidos). **D8/upload-download resumável adiado p/ a Fase 1a** (Fork 2): client real segue in-memory com TODO; motor já é path-based |
| Fase 1a — Reconciliação manual (execução) | ⬜ | depende do motor + pré-requisitos ops |
| Fase 1b — Automático (cron) | ⬜ | só após 1a provar (D12) |
| Fase 2 — Baixa latência sistema→Drive | ⬜ | spec própria |
| Fase 3 — Baixa latência Drive→sistema | ⏸ | adiada/opcional (D11) |

## 20. Estado de retomada (handoff para nova sessão) — 2026-07-01

**Onde o trabalho vive**
- Branch: **`sincronizacao-drive`**. Worktree isolado: **`/home/prime/projetos/jusprime/.worktrees/sincronizacao-drive`** (a `master` fica livre para outra frente). Trabalhar SEMPRE dentro do worktree.
- Banco de teste **isolado** do worktree: `saas_testwt` (via `app/.env.test.local` com `TEST_TOKEN=wt`, gitignored). `GOOGLE_DRIVE_SHARED_DRIVE_ID=ROOT` está no `app/.env.test` (commitado, fixture dos testes).
- Rodar tudo no container apontando pro worktree:
  `docker exec jusprime_php_dev bash -c 'cd /var/www/.worktrees/sincronizacao-drive/app && php bin/phpunit'` (suíte atual: **886/886**). HEAD da branch: **`a2671be`**. O **main repo está em `gestao-cobrancas`** (frente ATIVA em paralelo) — NÃO tocar; ver isolamento verificado abaixo.

**O que já está pronto (código na branch)**
- **Fase 0 (Fundação):** `Pasta.driveFolderId`/`driveSyncedAt`, `PastaDocumento.driveFileId`; UNIQUE de NUP removido (índice não-único `(tenant_id, nup)`; isolamento de tenant intacto); migration `Version20260701144054` (**provada up/down só no banco de teste isolado — NÃO aplicada no `saas` compartilhado**; aplicação real fica pra Fase 1a); `App\Sync\Service\GoogleDriveClient` + `GoogleDriveClientInterface` + `FakeGoogleDriveClient`; comandos `app:sync:backfill-pastas` e `app:sync:backfill-arquivos`; barreira de NUP duplicado removida em `CriarPastaUseCase` e `EditarPastaUseCase`.
- **Fase 1 — motor de PASTAS:** `app:sync:reconciliar` bidirecional (aditivo, nunca apaga), com dry-run, lock (flock), `--limit`/`--pasta-id`, D9 (sem NUP → pula) e D10 (divergência → só reporta). Revisado.
- **Fase 1 — motor de ARQUIVOS (2026-07-09):** mesmo comando `app:sync:reconciliar`, agora reconcilia arquivos por pasta vinculada. **sistema→Drive:** doc sem `drive_file_id` → `enviarArquivo`; se o doc tem `PastaSecao`, o arquivo vai para uma **subpasta-espelho por nome** no Drive (find-or-create — Fork 1). **Drive→sistema:** varre a árvore da pasta de caso **recursivamente** (`listarSubpastas`+`listarArquivos`, sem mudar a interface), pula Google-native, e cria `PastaDocumento` (§11.6: raiz→sem seção, subpasta de 1º nível→seção, sub-subpasta→achatada p/ a seção-avó, materializada preguiçosamente). **flush+clear por item** (o vínculo de cada upload/download é durável antes do próximo → idempotente por `drive_file_id`, não re-duplica na re-execução). Guards: nome>255, tamanho>INT4, e UNIQUE global de `drive_file_id` (arquivo multi-parent não fecha o EM). Suíte 900/900; revisado 2× (10 furos + 1 regressão corrigidos). **Fork 2 (D8 resumável/streaming) ADIADO p/ a Fase 1a:** `GoogleDriveClient` real segue in-memory (multipart/`file_get_contents`) com TODO; o motor já é path-based (sem retrabalho). Resíduos aceitos: R1 (OOM em arquivo grande no download in-memory — cortado pela carga supervisionada + streaming da Fase 1a) e o dry-run não prevê a contagem de seções criadas (número principal, arquivos baixados, é previsto).
- **NUP alfanumérico (10A/10B) — commits `7c16e8e`+`a2671be`:** motor aceita `^\d+[A-Za-z]?$` (`ReconciliarCommand::ehNupValido`); ordenação natural nas 3 listas do Expediente via nova DQL `CAST_INT_PREFIXO` (§11.5). Sem migration. Revisado (feature-review-agent).
- **Drive saneado (ação real, via rclone):** os 20 números repetidos → 40 pastas `10A`/`10B` renomeadas no Drive real (ID preservado, conteúdo intacto). Parser: nup_repetido 40→0. Rollback `tmp/acervo/rename_map_20260701.json`. Pendências restantes no Drive (aguardam P.O.): 15 vazias, 7 sem cliente, 3 descartáveis.
- **Conexão OAuth PROVADA + `GoogleDriveClient` OAuth (2026-07-10, commit `acc44c1`):** decisão de arquitetura — **cada escritório conecta a PRÓPRIA conta** (OAuth "login de pessoa"), não um robô único (isolamento + custódia); conta do rclone é a ponte agora, mesmo mecanismo do futuro "Conectar meu Drive". `GoogleDriveClient` ganhou **modo OAuth** (`GOOGLE_DRIVE_OAUTH_CLIENT_ID/SECRET/REFRESH_TOKEN`, precedência sobre service account) + listagens com `corpora=allDrives` (sem driveId — cobre pasta compartilhada no My Drive). App OAuth criado no Google Cloud (`bluejus-sync`, Externo/Teste); **validado contra o acervo real: o client PHP listou 1051 pastas** (escopo readonly). A "raiz" = folder id `1WBY-…` (em `GOOGLE_DRIVE_SHARED_DRIVE_ID`, agora um folder id). Segredos fora do git.

**Isolamento vs `gestao-cobrancas` (verificado):** COMPARTILHAM containers dev (projeto `jusprime`), dev DB `saas`, `.git` common dir, token rclone. ISOLADOS: working trees, banco de teste (`saas_testwt`≠`saas_test`), `var/cache`, uploads. NÃO: migration/DDL no `saas`, `docker compose down/build`, `git worktree prune`/`gc`/`branch -D`, phpunit fora do path do worktree.

**Próximo passo (retomar aqui): Fase 1a — EXECUÇÃO supervisionada (ou Fase 2)**
- O motor de código (pastas + arquivos) e o `GoogleDriveClient` OAuth estão **completos e a leitura está validada** contra o acervo real. O que falta na Fase 1 é a **execução** da carga manual supervisionada (§10.6).
- **Auth:** a conexão já usa a **conta do rclone via OAuth** (não mais service account/Shared Drive). Para escrever, **re-autorizar com escopo `drive` completo** (hoje o refresh_token é `drive.readonly`) e tornar o token **durável** (o app está em modo Teste → refresh_token expira ~7 dias; publicar o app OU regenerar pelo link). Segredos vão em env fora do git (`GOOGLE_DRIVE_OAUTH_*`), e `GOOGLE_DRIVE_SHARED_DRIVE_ID` = folder id `1WBY-…`.
- **Bloqueio de ops que resta:** backup de uploads + disco (§6 item 7, §12.3) antes do pull-down.
- Ao ligar a escrita: fechar o **Fork 2 (D8)** — trocar o `enviarArquivo`/`baixarArquivo` in-memory por **upload resumável + download streaming** (procurar o `TODO (Fork 2 / D8)` em `ReconciliarCommand` e nos métodos do client). Elimina o risco R1 de OOM em arquivo grande.
- Fase 2 (baixa latência sistema→Drive, Messenger) é a próxima frente de código, se priorizada.

**Convenções/gotchas desta frente**
- Git: o Claude **pode criar commits LOCAIS scoped** (autorizado); **nunca push/merge/rebase**. Commitar SEMPRE via `git -C <worktree>` p/ cair na branch `sincronizacao-drive` (o main repo está noutra branch). `git add` cirúrgico por arquivo, sem arrastar os `plano*.md`.
- Testes: `KernelTestCase` + `use Factories;` — **nunca `ResetDatabase`** (o projeto usa DAMA; misturar quebra a suíte). Rodar SÓ pelo path do worktree (usa `saas_testwt`).
- O `GoogleDriveClient` **real** é fronteira de validação manual (não entra no CI); testes usam o fake. **Leitura já validada** (OAuth, conta do rclone) — o client PHP listou 1051 pastas do acervo. rclone (host, remote `gdrive:`, folder ID `1WBY-…`) segue como ferramenta de escrita direta — ver memória `reference_acervo_report_rclone`.
- Arquivos temporários **untracked** no worktree: `plano.md`, `plano-fase1-pastas.md`, `plano-nup-alfanumerico.md`, `plano-fase1-arquivos.md`. Não commitar.

**Bloqueios de ops (não-código; destravam a Fase 1a de EXECUÇÃO)**
1. Backup de uploads + disco (§6 item 7, §12.3) — **o que resta**. 2. ~~Service account + Shared Drive~~ → **resolvido por OAuth (conta do rclone)**; falta só re-autorizar com escopo de escrita + token durável (app em modo Teste). 3. ~~Validar o `GoogleDriveClient` real~~ → **FEITO (leitura, 1051 pastas)**. 4. `composer audit` das deps do google/apiclient.
