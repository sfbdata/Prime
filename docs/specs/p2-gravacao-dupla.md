# Spec — P2: gravação dupla (disco da VPS + Cloudflare R2)

> **Risco: ALTO** — guarda dos documentos de todos os escritórios, em todos os módulos.
> **Frente:** `r2-gravacao-dupla` (worktree própria, base `origin/master` @ `a3919b5c`).
> **Autorizado em 15/10/2026:** só **P2.0** (esta spec) e **P2.1** (E2.7 + E2.8). Da P2.2 em diante,
> cada etapa exige autorização explícita do dono.

## 0. A regra que não se negocia nesta fase

> **Nenhuma exclusão remota está autorizada na P2.** Nenhum código da P2 apaga, sobrescreve
> por limpeza, expira ou move objeto no R2 — nem no bucket de produção, nem no de backup.
> Exclusão local continua acontecendo como hoje; a remota é **registrada**, nunca executada.
> Liberar exclusão remota é decisão futura do dono, fora da P2, com spec própria.

E as outras quatro, igualmente fixas durante toda a P2:

1. **O disco local é a fonte de verdade e a única origem de leitura.** Nenhuma rota, comando ou
   worker lê conteúdo do R2 para servir, comprimir, exportar ou sincronizar. O R2 é espelho.
2. **P0 e P0b ficam intactas.** O bucket `bluejus-backup-uploads` não é conhecido pela aplicação.
3. **Nada é normalizado nem deduplicado.** Nome, extensão, maiúsculas, espaço, ponto final e
   Unicode vão para o R2 byte a byte, como estão no banco e no disco.
4. **Falha do R2 nunca vira falha do usuário.** Upload, edição e exclusão locais não dependem de o
   R2 responder.

## 1. Estado medido (15/10/2026)

| Fato | Valor | Onde se mediu |
|---|---|---|
| Código em produção | `a530a73a` = conteúdo do master (0 commits de diferença) | VPS, `git log` |
| Abstração de storage | E2.1–E2.6 em produção; faltam E2.7 (Tarefa) e E2.8 (shim) | `docs/specs/e2-abstracao-de-storage.md` |
| Implementações do núcleo | **uma**: `ArmazenamentoLocal` (autowiring, sem alias explícito) | `app/src/Shared/Armazenamento/` |
| Correção do download do Drive (DT-8) | em produção (`43cc8be0` é ancestral de `a530a73a`) | git |
| Acervo | 22.792 arquivos, 26.052.381.306 bytes | P0 (22.727) + P0b (65) |
| Backup | P0 `bluejus-backup-uploads/p0-20261008/` e P0b `…/p0b-20261015/`, ambos verificados por download e SHA-256 | relatórios em `/root/bluejus-backup-p0*/` |
| Referências do banco sem arquivo | **0** | P1, dump `db_20261015_023001` |
| Documentos com conteúdo de erro 401 do Drive | 90 (pastas 583 e 871, importados em 13/07) | P1 |
| Arquivos sem referência | 73 sem rastro + 5 só no `audit_log` + 2 `import-tmp` + 18 de lápide | P1 |
| Conteúdo repetido | 11.290 cópias extras, ~12 GB | P1 |
| Chaves fora do padrão hash | 160 sem extensão, 147 com extensão maiúscula, 19 com texto livre/Unicode, 19 de Tarefa com caminho completo | P1 |
| Tenants com arquivo | só o tenant 1 | P1 |

🪤 **O rclone grava `／` (U+FF0F) como `‛／` no S3** (encoding padrão `Slash` do backend). Na P0,
2 objetos têm chave diferente do nome original. A P0 restaura certo **pelo rclone**; um cliente S3
qualquer não acha essas duas chaves pelo nome. Consequência: a P0 **não** serve de semente para o
prefixo `core/` por cópia crua de chave (§8).

## 2. Layout de chaves no R2 (decisão ratificada)

Bucket de produção: **`bluejus-arquivos`** (privado). Chave = função **pura** de `ChaveDeArquivo`
(escopo, categoria, nome), sem consulta a banco e sem estado:

| Categoria | Escopo | Chave remota |
|---|---|---|
| `PASTA_DOCUMENTO` | tenant | `core/t/{t}/uploads/pastas/{nome}` |
| `PASTA_IMAGEM_EDITOR` | tenant | `core/t/{t}/uploads/pastas/{t}/{nome}` |
| `CLIENTE_DOCUMENTO` | tenant | `core/t/{t}/uploads/clientes/{nome}` |
| `CHAMADO_ANEXO` | tenant | `core/t/{t}/uploads/chamados/{nome}` |
| `JUSTIFICATIVA_ANEXO` | tenant | `core/t/{t}/uploads/justificativas/{nome}` |
| `COBRANCA_DOCUMENTO` | tenant | `core/t/{t}/uploads/cobrancas/{t}/{nome}` |
| `KANBAN_ANEXO` | tenant | `core/t/{t}/uploads/kanban/{nome}` |
| `TAREFA_ANEXO` | tenant | `core/t/{t}/uploads/tarefas/chat/{nome}` (P2.1 torna endereçável) |
| `FOTO_PERFIL` | **global** | `core/g/uploads/perfil/{nome}` |

**Regra:** o trecho depois de `uploads/` é **exatamente** o caminho relativo do arquivo no volume
`jusprime_uploads_prod`. Por isso o tenant aparece duas vezes nas duas categorias que já isolam o
escritório em disco: o espelho fica verificável contra o inventário da P0 linha a linha.

**Fora do layout** (não replicados na P2): `cobrancas/import-tmp/*` (temporário, D3 da E2) e os
temporários privados fora do volume (`jusprime-upload-*`, `-copia-*`, `-gs-*`, `-export-*`).

**Codificação:**
- o nome entra **byte a byte** (UTF-8 como está no banco; sem NFC/NFD, sem `trim`, sem escapar
  `／`, sem remover ponto final); a única transformação é a de transporte (percent-encoding da
  requisição, feito pelo cliente S3);
- teto do S3: 1.024 bytes de chave — o maior nome medido tem 57 caracteres (Tarefa); a P2.2 mede
  o maior do acervo e trava em teste;
- o tenant vem do **escopo da chave** (nunca do request, nunca do caminho). R1 da E2: no disco o
  tenant errado é invisível; no R2 vira 404 ou cruzamento. A prova de isolamento continua nos
  testes das fábricas de chave contra a entidade.

## 3. Arquitetura

```
Controller/UseCase ── ChaveDeArquivo ──▶ ArmazenamentoEspelhado  (alias das 3 interfaces, por flag)
                                          │
                                          ├─▶ ArmazenamentoLocal          (fonte de verdade; leitura)
                                          │
                                          └─▶ outbox: armazenamento_replicacao  (linha por gravação)
                                                     │
                                          worker (Messenger, fila própria)
                                                     │
                                                     └─▶ EspelhoRemoto (R2)  — só PUT e HEAD
```

### 3.1 `ArmazenamentoEspelhado` (decorador)

Implementa `ArmazenamentoDeArquivos`, `MaterializadorDeArquivo` e `ArmazenamentoComPrefixo`, e
delega **tudo** ao `ArmazenamentoLocal`. Acrescenta só isto:

| Operação | Comportamento |
|---|---|
| `gravar` (novo ou sobrescrita) | depois do sucesso local, mede SHA-256 e tamanho do arquivo **já publicado** e grava uma linha na outbox (`operacao = enviar`). Falha ao gravar a outbox → log de erro + métrica, **sem** derrubar a gravação local (a reconciliação a recupera) |
| `excluir` | exclui local; grava linha `operacao = exclusao_registrada`. **Nada sai para o R2** |
| `excluirPrefixo` (purga) | exclui local; grava uma linha `exclusao_registrada` por chave removida |
| `abrir`, `ler`, `existe`, `metadados`, `paraLeitura`, `copiaGravavel`, `listar` | **só local**, sem efeito colateral |

A flag é por categoria (`ARMAZENAMENTO_ESPELHO_CATEGORIAS`, lista; vazia = desligado). Com a flag
vazia o decorador é um repasse puro — é o estado do deploy da P2.3.

### 3.2 Outbox persistente (`armazenamento_replicacao`, 1 migration aditiva na P2.3)

Colunas: `id`, `operacao` (`enviar` | `exclusao_registrada`), `escopo_tenant_id` (nulo = global),
`categoria`, `nome` (bytes do nome), `chave_remota`, `sha256`, `tamanho_bytes`, `estado`
(`pendente` | `enviado` | `verificado` | `falhou` | `registrado`), `tentativas`, `ultimo_erro`
(sem segredo), `criado_em`, `atualizado_em`. Índices: `(estado, criado_em)`; `(chave_remota)`.

**Por que a linha é gravada fora da transação do domínio:** o storage não conhece a transação do
UseCase, e acoplá-lo a ela quebraria INV-6 da E2. Gravar em auto-commit, logo depois da gravação
local, garante que **nenhum arquivo gravado fica sem linha**; o preço é que arquivo de transação
desfeita também é replicado. Isso é aceito: vira órfão no R2, idêntico ao órfão local (DT-10 da
E2), e a reconciliação o classifica. **Perder** um arquivo comprometido seria pior que copiar um
órfão.

### 3.3 Worker e idempotência

- Mensagem `ReplicarArquivo(id)`, numa fila **própria** do Messenger (não disputa com a fila atual).
- O worker relê a linha, abre o arquivo **local** pela chave e:
  1. `HEAD` na chave remota; se existe com o mesmo `sha256` (metadado `x-amz-meta-sha256`) e o mesmo
     tamanho → `verificado`, sem PUT;
  2. senão, `PUT` com `x-amz-meta-sha256`, `Content-Type` do metadado local e checksum de integridade
     do transporte; depois `HEAD` de novo → `verificado`;
  3. se o arquivo local mudou desde a linha (SHA diferente) → a linha vira `falhou` com motivo
     "conteúdo local mudou"; a linha mais nova da mesma chave é quem vale;
  4. se o arquivo local sumiu → `falhou` ("ausente no local"); **nada é apagado no R2**.
- Repetição com backoff limitado (ex.: 1 min, 5 min, 30 min, 2 h, 12 h), depois `falhou`, que fica
  visível no relatório da reconciliação. Reentrega da mesma mensagem é inofensiva (passo 1).
- Concorrência baixa e banda limitada por configuração; o worker nunca roda na requisição HTTP.

### 3.4 Reconciliação (`armazenamento:reconciliar-espelho`)

Somente leitura no R2 (`HEAD`/`LIST`). Parte do **banco** (as mesmas fontes da P1: 8 colunas de
arquivo mais as imagens do editor citadas no HTML das peças), monta a chave esperada e classifica:
presente e igual · ausente no R2 · tamanho/SHA divergente · objeto remoto sem referência ·
pendente/falhou na outbox · **suspeito conhecido** (os 90 de conteúdo de erro do Drive, por SHA
`0a93453f…` e pela lista da P1). Saída: relatório em arquivo + resumo no log; `--json` para alerta.
Roda primeiro em dry-run, depois diariamente pelo cron, fora do horário dos backups (02:30–03:50 UTC).

### 3.5 Carga do acervo existente (backfill, P2.6)

Um comando do próprio app, com **a mesma função de chave** do decorador (fonte única), que
enfileira na outbox cada arquivo referenciado e cada arquivo do inventário. Lê **só do disco
local** — nunca da P0 (por causa do `‛／` e para não dar ao app acesso ao bucket de backup).
Idempotente (passo 1 do worker), com limite de banda e de concorrência, retomável.

Os 73 sem rastro, os 5 só no `audit_log` e os 18 de lápide também vão (espelho fiel do volume); os
90 suspeitos vão **como estão**, marcados na reconciliação — nem apagados, nem tratados como válidos.

## 4. Contratos (P2.2 entregou; P2.3 consome)

```
App\Shared\EspelhoRemoto\LayoutDeChaveRemota::chaveDe(ChaveDeArquivo): string   // pura; §2
App\Shared\EspelhoRemoto\EspelhoRemoto (interface):
    enviar(ChaveDeArquivo, resource $conteudo, int $tamanho, string $sha256, string $mime): void
    consultar(ChaveDeArquivo): ?MetadadosRemotos           // HEAD; null = não existe
    existe(ChaveDeArquivo): bool
App\Shared\EspelhoRemoto\R2\EspelhoRemotoR2               // async-aws/s3: só PutObject e HeadObject
App\Shared\EspelhoRemoto\R2\ClienteR2::criar(endpoint, chave, segredo, ?http, ?logger): S3Client
```

**Mudança em relação ao rascunho da P2.0:** o espelho recebe `ChaveDeArquivo`, não a chave remota
em string — quem chama não escolhe a chave, e não há como gravar fora de `core/` nem no prefixo de
outro escritório por engano. O conteúdo é um **stream** (o worker da P2.3 o obtém por
`ArmazenamentoDeArquivos::abrir()`), então o adaptador nunca abre arquivo do disco. `listar()` não
entrou na P2.2 (não foi pedido); a reconciliação da P2.3 decide se precisa dele.

**`EspelhoRemoto` não tem método de exclusão.** É a primeira barreira contra a regra 0: não há o
que chamar. A segunda é um teste de arquitetura que proíbe `DeleteObject`, `deleteObject`,
`DeleteObjects` e `->delete(` em todo `src/`. A terceira é o token (§5).

O núcleo (`App\Shared\Armazenamento`) continua sem saber que o R2 existe (teste
`testNucleoNaoConheceOBackendRemoto`). O adaptador mora fora dele, em `App\Shared\EspelhoRemoto\`
(decidido na P2.2); o SDK fica confinado a `App\Shared\EspelhoRemoto\R2\`
(`EspelhoRemotoArquiteturaTest`).

**Biblioteca:** candidata `async-aws/s3`, sujeita à validação na P2.2 de: endpoint customizado do
R2 com path-style, SigV4 com chave UTF-8 (as 19 com Unicode e as 160 terminadas em `.`), metadados
`x-amz-meta-*`, upload em stream sem carregar em memória (vídeos de até ~100 MB), e compatibilidade
com PHP 8.2 / Symfony 7.4 sem deprecation (a suíte falha em deprecation).

## 5. Segurança

| Ameaça | Barreira |
|---|---|
| Exclusão remota indevida | regra 0; `EspelhoRemoto` sem `delete`; teste de arquitetura; purga e exclusões só registram |
| Token vazado apaga tudo | token **só** do `bluejus-arquivos`; o R2 não separa "escrever" de "apagar" num token de objeto, por isso a barreira real é o código. P0/P0b ficam num bucket que esse token não alcança — **provar o 403** na criação do token (P2.4) |
| Exposição de documento | bucket privado, sem domínio público e sem `r2.dev`; na P2 **nada** é servido do R2 — download segue `Controller → autorização → ChaveDeArquivo → EntregaDeArquivo → disco` |
| Credencial em log/argv/repo | variáveis de ambiente em `.env.prod` (guarda de segredos S1); nunca em `services.yaml`; `ultimo_erro` da outbox guarda só código e mensagem do S3, sem cabeçalho assinado; atenção: `debug:container --env-vars` imprime segredo |
| Cruzamento entre escritórios no R2 | tenant só do escopo da chave; teste de layout por categoria × tenant; reconciliação acusa objeto em prefixo de tenant sem referência daquele tenant |

**URL pré-assinada (E4, não P2):** só depois da checagem de permissão e do registro de auditoria;
TTL ≤ 60 s; `response-content-disposition` e `response-content-type` fixados pelo servidor;
reservada a arquivo grande e vídeo (258 vídeos, 2,8 GB). **Sempre por proxy:** imagem do editor e
foto de perfil — as rotas ainda não checam permissão de módulo (DT-4 da E2) e uma URL assinada
vazaria acesso — e todo tipo que precise de `Content-Security-Policy: sandbox` (SEC-003), porque a
resposta do R2 não leva a CSP da aplicação.

## 6. Drive: nenhuma resposta de erro vira documento de novo

- **Em produção desde DT-8:** só a resposta final 200 grava; o destino da falha é descartado; o
  token renovado é exigido (`GoogleDriveClient`).
- **Decisão do dono (15/10):** download com tamanho incompatível com o `size` informado pelo Drive
  passa a ser **rejeitado** (hoje D16 só avisa), **preservando rastreabilidade**: o item não vira
  documento, e fica registrado com `drive_file_id`, tamanho esperado, tamanho recebido e SHA do
  recebido, contado como erro da rodada. Etapa própria (P2.5a), com teste que reintroduz o defeito.
- **Os 90 existentes:** preservados (nenhum arquivo apagado, nenhum registro alterado nesta
  migração), replicados como estão, **marcados como suspeitos** na reconciliação. O destino deles
  é decisão de dono em frente própria.

## 7. P0 e P0b intactas

- Bucket separado; a aplicação não recebe o nome nem credencial dele.
- O token de migração (`/root/.config/bluejus-r2/migracao.env`) **não** é usado pela aplicação e
  deve sair do disco da VPS ao fim da migração.
- Antes de cada fase que liga escrita (P2.5, P2.6): cópia complementar `p0c-AAAAMMDD/` no mesmo
  padrão da P0b (`--immutable`, seleção por diferença de inventário, verificação por download).
- ⏳ Confirmar no painel a bucket lock rule do `bluejus-backup-uploads` (pendente desde 24/09).

## 8. Tratamento de falhas

| Falha | Efeito para o usuário | Efeito no espelho |
|---|---|---|
| R2 fora do ar / 5xx / timeout | nenhum | linha `pendente`, repetida com backoff |
| Credencial inválida (403) | nenhum | todas `falhou`; alerta pela reconciliação |
| Outbox não gravou (banco indisponível no instante) | nenhum além do que já haveria | arquivo sem linha; a reconciliação o acha e enfileira |
| Arquivo local mudou antes do envio | nenhum | linha antiga `falhou` por SHA; a nova vale |
| Arquivo local apagado antes do envio | nenhum | `falhou` ("ausente no local"); R2 não é tocado |
| Worker morto no meio do PUT | nenhum | reentrega; passo 1 torna idempotente |
| Exclusão local | como hoje | `exclusao_registrada`; objeto remoto **fica** |

## 9. Implantação progressiva e rollback

| Etapa | Conteúdo | Liga algo? | Rollback |
|---|---|---|---|
| **P2.0** | esta spec | — | — |
| **P2.1** | E2.7 (Tarefa na abstração) + E2.8 (remove o shim) | não muda comportamento visível (§10) | reverter commits |
| **P2.2** | `LayoutDeChaveRemota`, `EspelhoRemoto` + adaptador `async-aws/s3`, contra S3 falso **sem rede**; teste das 326 chaves legadas | não | reverter commits |
| **P2.3** | decorador, outbox (1 migration aditiva), worker, reconciliação em dry-run; deploy com flag **vazia** | não | flag já vazia; migration aditiva fica |
| **P2.4** | dono cria/confirma `bluejus-arquivos` e o token restrito; prova do 403 no bucket de backup; P0c | não | — |
| **P2.5a** | rejeição do download do Drive com tamanho incompatível (§6) | sim (sync) | reverter commit |
| **P2.5** | flag por categoria: `foto_perfil` → `cliente_documento` → `justificativa_anexo` → `cobranca_documento` → `tarefa_anexo` → `kanban_anexo` → `chamado_anexo` → `pasta_imagem_editor` → `pasta_documento` | sim (escrita extra) | **esvaziar a flag = só local na hora**; objetos no R2 ficam, sem efeito |
| **P2.6** | backfill do acervo; reconciliação a 100% | sim (envio) | parar o comando |
| **P2.7** | reconciliação diária pelo cron + critério de saída | — | tirar do cron |

**Critério de saída da P2** (pré-requisito da E3/E4, leitura pelo R2): reconciliação diária com 0
ausentes e 0 divergentes por N dias seguidos (N decidido pelo dono), outbox sem `falhou` aberto.

## 10. P2.1 — o que entra agora (E2.7 + E2.8)

### 10.1 E2.7 — anexo de Tarefa na abstração

**Medido em produção (P0/P0b + P1):** as 19 referências de `tarefa_mensagem.arquivo_anexo` estão
todas em `/uploads/tarefas/chat/`; `tarefas/admin/` tem 2 arquivos e **nenhuma referência**.

**Como fica, sem alterar dado e sem relaxar `ChaveDeArquivo` (D5 da E2 continua valendo):**

- **`ChavesDeTarefa`** (fábrica do domínio, `App\Tarefa\Armazenamento\`) traduz o valor da coluna
  na chave. Aceita só `^/uploads/tarefas/chat/<nome>$`, com `<nome>` começando por alfanumérico e
  sem `/`; o `<nome>` vira o `nome` da chave. O escopo é o tenant **da mensagem** (a coluna é
  `NOT NULL`; sem ele a fábrica recusa, sem fallback), nunca o da sessão. Arquivo novo: escopo da
  **tarefa**, de quem a mensagem copia o escritório. Valor fora do formato → `ChaveDeArquivoInvalida`.
- O formato da coluna **não muda**: continua `/uploads/tarefas/chat/<nome>`, porque é o que a
  timeline e o template leem (`item.arquivoAnexo|split('/')|last`).
- **`ResolvedorDeCaminhoLocal`** ganha o oitavo diretório (`tarefas_uploads_dir`,
  `%kernel.project_dir%/public/uploads/tarefas`) e resolve `TAREFA_ANEXO` para `<dir>/chat/<nome>`
  — o mesmo caminho físico de hoje. Em teste aponta para `var/uploads-test/tarefas`, como os outros.
- **Upload** passa por `FonteDeUploadHttp` + `NovoArquivo` (D8 da E2): o nome deixa de ser
  `uniqid('arquivo_', true)` — previsível pelo relógio — e passa a ser o nome opaco de 128 bits do
  storage. A extensão segue a mesma regra (`guessExtension() ?? 'bin'`).
- **Visualizar e baixar** passam pela `EntregaDeArquivo`, com o nome para download igual ao de
  hoje (último segmento do valor). A visualização já usava a mesma política (nosniff + sandbox por
  `exigeSandbox()`); o download ganha `X-Content-Type-Options: nosniff`, que hoje não mandava.
- `CaminhoDeAnexoDeTarefa` sai: seus testes de travessia migram para a fábrica (o ataque é ao
  valor, não à rota — o roteador normaliza `../` antes do controller).
- ⚠️ **Perda consciente:** o `CaminhoDeAnexoDeTarefa` confinava por `realpath()` e recusava um
  **symlink dentro de `chat/`** apontando para fora. O `ArmazenamentoLocal` segue link, como nas
  outras oito categorias — Tarefa passa a valer a regra comum. Explorar exige escrever no volume de
  uploads (o mesmo pré-requisito da DT-12 da E2); em 24/09 o volume tinha 0 links. Recusar link na
  leitura do backend local é endurecimento **geral** (as 9 categorias), registrado como pendência —
  fazê-lo aqui mudaria o núcleo para todos os módulos.

**D10 em Tarefa — onde a E2 e a Tarefa diferem, e por quê.** (Nome com `..` em qualquer posição
conta como "fora do formato": 404, não 500.) A E2 registrou que, nas rotas em que o
nome vem do banco, chave recusada **propaga (500)**. A Tarefa separa dois casos:
- **valor fora do formato** (`admin/`, prefixo desconhecido, travessia) → **404**. É o que a rota
  já respondia antes (o `CaminhoDeAnexoDeTarefa` devolvia `null` e a rota dava 404), e é o que a
  DP-9 pede para `admin/`. A pergunta é `ChavesDeTarefa::temAnexoEnderecavel()`, feita antes de
  montar a chave;
- **valor no formato mas sem escritório** (dado corrompido) → a `ChaveDeArquivoInvalida` **propaga
  (500)**, como na E2 — não é "arquivo ausente".
`FalhaDeArmazenamento` nunca vira 404 (a entrega só converte `ArquivoNaoEncontrado`).

**Decisão tomada pela medição (ratificar):** valor legado em `/uploads/tarefas/admin/` deixa de ser
servível (404). Hoje ele seria servido; em produção não existe nenhum. A alternativa — uma décima
categoria só para isso — alargaria o contrato e o layout remoto por zero referências.

**O que NÃO muda na P2.1:** a purga continua só **listando** os anexos de Tarefa, sem apagá-los
(passar a apagá-los é mudança de comportamento com perda de arquivo, e a purga é tocada por outra
frente). Fica registrado como pendência — e com ela um texto que ficou **falso**:
`PurgarEscritorioUseCase.php` (docblocks nas linhas ~70, ~292, ~656 e a mensagem de log da linha
~412, que sai no log de produção) e `PurgarDadosExpiradosCommand.php:150-153` dizem "fora da
abstração até a E2.7". A E2.7 aconteceu; o que mantém a Tarefa fora da purga agora é a DP-12.
Corrigir esses textos vai junto com a decisão da DP-12.

**Modo do diretório:** o `uploadFiles()` antigo criava `chat/` com `0775`; o backend local cria
com `0755`. Só pesa num volume novo em que `chat/` ainda não exista (homologação) — em produção o
diretório existe.

**Histórico de commits:** o commit da E2.7 (`e625774c`) sozinho deixa
`FabricasDeArquivoNovoTest::testInventarioDasFabricas` vermelho; o commit seguinte (`6d886343`) o
corrige. Integrar os dois juntos (ou juntá-los, decisão do dono — é reescrita de histórico).

### 10.2 E2.8 — remover o shim

`ArquivoStorageService` e `ArquivoStorageInterface` saem **depois de provar** que não há consumidor:
zero referência fora de comentário em `src/`, `config/` e `templates/`, e `lint:container` verde
sem eles. O `EntregaDeArquivoTest`, que usava o `servir()` antigo como gabarito de cabeçalhos,
passa a afirmar os valores fixos que aquele gabarito produzia (D33 da E2). As allowlists datadas
dos testes de arquitetura (`TarefaController` até a E2.7, `ArquivoStorageService` até a E2.8)
saem; os docblocks que citam o shim (DT-5) são atualizados.

### 10.3 Testes exigidos da P2.1

- unidade de `ChavesDeTarefa`: formato aceito; travessia (`../`, `%2e%2e`, `/`, byte nulo,
  `admin/`, outro prefixo, vazio) recusada; escopo = tenant da mensagem, não da sessão;
- mapa de chave → caminho: `TAREFA_ANEXO` resolve para `<tarefas>/chat/<nome>` (o teste que
  afirmava "lança" passa a afirmar o caminho);
- funcional: upload grava no diretório certo e a coluna fica `/uploads/tarefas/chat/<nome>`;
  visualizar (HTML em sandbox, PDF sem) e baixar (attachment + nosniff); outro escritório → 404;
  arquivo ausente → 404;
- arquitetura: nenhuma referência ao shim; `TarefaController` sem `BinaryFileResponse`, sem
  `->move(` e sem `mkdir`; acesso direto ao disco (funções de arquivo, `Filesystem`, `ZipArchive`,
  `Process`, `exec`, GD gravando em caminho, `->openFile(`, e a criação dos escritores de
  PhpWord/PhpSpreadsheet por `IOFactory::createWriter` ou `new Xlsx(`) só nos arquivos da lista
  justificada. `->save(` sozinho fica fora (casaria com repositórios Doctrine): um escritor criado
  de outro jeito (`new Word2007(`, `new Csv(`, `Imagick::writeImage`) escapa do regex.

**Limite honesto do teste de arquitetura:** regex não faz análise de fluxo. Um arquivo da lista
pode, em tese, escrever no caminho emprestado por `paraLeitura()` — `ArquivoEmprestado` entrega
`caminho()`, uma string que qualquer primitivo de escrita aceita; o tipo só impede que **ele**
apague (D9). O que barra a escrita é a lista exata (arquivo novo que toca disco derruba o teste) e a
revisão de quem entra nela — não o tipo nem o regex sozinhos.

## 11. Coordenação com outras frentes

- Worktree e banco próprios (`saas_testr2-gravacao-dupla`); a suíte da frente roda **sozinha**
  (duas suítes simultâneas quebram o Ghostscript por `/tmp` compartilhado); no máximo 2 agentes.
- Integração, push, merge e deploy são do dono.
- **Colisões medidas em 15/10:** `app/config/services.yaml` (tocado por `designer-central-ia`,
  `demo-expediente-central`, `seguranca-s1-publicacao`) — a P2.1 acrescenta só 3 linhas
  (parâmetro, bind, override de teste); da P2.3 em diante a config do espelho vai num arquivo
  próprio (`config/packages/armazenamento_espelho.yaml`). `worktree-agent-l8-zip` tem 1 commit em
  `EntregaDeArquivoGerado` (a P2.1 não toca esse arquivo). `cobranca-acompanhamento-canonico` e a
  purga: a P2.1 não toca `PurgarEscritorioUseCase`.
- O Engineering Loop não toca `Shared/Armazenamento` nem Tarefa.

## 12. Decisões

### Ratificadas pelo dono (15/10/2026)

| # | Decisão |
|---|---|
| DP-1 | Chaves `core/t/{tenant}/uploads/{caminho-original}`; globais `core/g/uploads/{caminho-original}` |
| DP-2 | Biblioteca candidata `async-aws/s3`, após validar compatibilidade (P2.2) |
| DP-3 | Espelhamento assíncrono, com outbox persistente, idempotência e reconciliação |
| DP-4 | Na P2, disco local é fonte de verdade e única origem de leitura |
| DP-5 | Nenhuma exclusão no R2 |
| DP-6 | Rejeitar download do Drive com tamanho incompatível, preservando rastreabilidade |
| DP-7 | Os 90 suspeitos: preservados, nem apagados nem tratados como válidos |
| DP-8 | Sem deduplicação e sem normalização de nomes existentes |

### Tomadas por medição nesta spec (ratificar)

| # | Decisão | Por quê |
|---|---|---|
| DP-9 | `tarefas/admin/` deixa de ser servível | 0 referências em produção (§10.1) |
| DP-10 | Linha da outbox gravada em auto-commit, fora da transação do domínio | nunca perder arquivo comprometido; órfão replicado é aceito (§3.2) |
| DP-11 | Backfill a partir do disco local, nunca da P0 | `‛／` do rclone e isolamento do bucket de backup (§1, §3.5) |
| DP-12 | Tarefa na purga continua só listada | apagar é mudança com perda de arquivo, fora da P2.1 |

### Pendentes do dono

1. N dias de reconciliação limpa para sair da P2 (§9).
2. Prazo de retenção antes de qualquer exclusão remota futura (fora da P2).
3. Purga passar a apagar anexos de Tarefa (DP-12) — e, junto, corrigir os textos da purga que ainda dizem "até a E2.7".
4. Destino dos 90 suspeitos e dos 73 sem rastro (retenção/LGPD).
6. Endurecimento geral: o backend local recusar symlink na leitura (as 9 categorias).
7. **Antes de qualquer leitura remota (E4):** provar R1 de Tarefa pela rota com o dublê
   `ArmazenamentoEmMemoria` (que materializa o escopo) — a escrita usa o escritório da tarefa e a
   leitura o da mensagem; no disco local isso é invisível, em `core/t/{tenant}` não.

## 13. P2.2 — adaptador R2 (entregue em 15/10/2026, sem ligar nada)

**Biblioteca validada:** `async-aws/s3` 3.5.0 + `async-aws/core` 1.30.0 (MIT) — PHP ^8.2,
`symfony/http-client` ^7 (o projeto tem 7.4.5), extensões `dom`/`filter`/`hash`/`simplexml` presentes
na imagem `php:8.2-fpm` de dev e de produção; 2 pacotes novos, sem conflito; `composer audit` sem
advisory nesses pacotes. Configuração toda explícita (`ClienteR2`): endpoint, `region=auto`,
`pathStyleEndpoint`, `sendChunkedBody=false` — nada lido de `AWS_*` nem de `~/.aws` (provado em teste).

**O que está provado (sem rede, contra `tests/Shared/Doubles/S3Simulado`):**
- layout das 9 categorias = caminho relativo do disco de produção, categoria a categoria;
- os **326 nomes fora do padrão da P1** atravessam o cliente S3 e chegam ao destino com a chave
  byte a byte do layout — Unicode, espaço nas bordas e `／`, sem `‛`;
- só `PUT` e `HEAD` saem pelo fio; nenhuma exclusão;
- o PUT leva `x-amz-checksum-sha256`, o SHA como metadado e o MIME; o envio só conta depois que o
  HEAD confere tamanho e SHA;
- 404 do HEAD é "não existe"; 5xx, recusa e rede viram `FalhaNoEspelhoRemoto`, com status e código
  S3, sem credencial, assinatura nem corpo;
- o dublê `EspelhoRemotoEmMemoria` cumpre o mesmo contrato (para a P2.3).

**O fixture dos 326 é anonimizado na origem** (`app/tests/Shared/Unit/EspelhoRemoto/legados-p1-anonimizados.json`,
gerado na VPS a partir do banco de auditoria isolado): os nomes reais contêm nome de pessoa (LGPD) e
os hashes reais são chaves de produção (D8). Mesma forma — mesmo comprimento em caracteres e em
bytes, mesmas classes (maiúsculas, Unicode de 2/3 bytes, espaço, pontuação, `／`) —, conteúdo
trocado. Como o layout é concatenação de bytes, a forma é o que importa.

**O que só se prova contra o R2 de verdade (P2.4, bucket de teste, com autorização):**
assinatura SigV4 aceita para chave com Unicode, espaço e `／`; R2 aceitar `x-amz-checksum-sha256`
(se recusar, cai para conferência só pelo HEAD); 404 (e não 403) no HEAD de chave ausente com o
escopo do token; metadado `x-amz-meta-sha256` devolvido intacto.

**Nada ligado:** nenhum consumidor em `src/`, nenhuma linha em `config/`, alias de armazenamento
inalterado (`EspelhoRemotoArquiteturaTest` cai no dia em que alguém ligar).

## 14. Fora de escopo da P2 inteira

Leitura pelo R2 · URL pré-assinada · `X-Accel-Redirect` · coluna `storage_backend`/`storage_key` ·
exclusão, expiração ou lifecycle no R2 · deduplicação · normalização de chave · correção dos 90
suspeitos · limpeza dos 73 sem rastro · alteração do bucket de backup · outros sistemas BlueJus
(cada um terá prefixo próprio no layout — `core/` é só do BlueJus Core).
