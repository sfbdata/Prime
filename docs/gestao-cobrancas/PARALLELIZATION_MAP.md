# Mapa de Paralelização — Gestão de Cobranças

> Companheiro da `FEATURE_GESTAO_COBRANCAS_SPEC_FINAL.md` (SPEC) e do `PLAN.md`. Define **quando** paralelizar a implementação e **quando não**. Regra-mãe: **mais agentes ≠ mais velocidade** — só paralelizar onde há independência real e o ganho supera o custo de integração.

## Princípios (do workflow do projeto)

- O **agente principal (orquestrador)** decompõe, define contratos, delega, **integra** (um por vez) e valida. Só ele faz a integração final e o Git (que é manual/humano).
- **Subagentes read-only** (`Explore`, `feature-review-agent`): investigam e revisam; nunca escrevem.
- **Subagentes implementadores** (`feature-implementer`): escrevem, mas só em **escopo exclusivo**, com **contratos já committados**, em **worktree isolada**, e **não** rodam testes que dependem do container `jusprime_php_dev` (o container monta o checkout principal, não a worktree) — apenas escrevem os testes; **o orquestrador executa os testes após integrar**.
- **Pré-requisito de fan-out:** worktrees ramificam do **HEAD committado** (`worktree.baseRef=head`). Logo, os contratos compartilhados (entidades, interfaces, enums, migration) **precisam estar committados antes** de delegar em paralelo. Uncommitted não chega ao implementador isolado.
- Regra de conflito: **dois agentes nunca editam o mesmo arquivo**. Quando inevitável → tarefas **SEQUENCIAIS**.
- Na dúvida sobre independência → **sequencial**.

### Consequência prática para esta feature

> **Estado (2026-07-09):** Etapas **0–4 CONCLUÍDAS e committadas** na branch `gestao-cobrancas` (HEAD `ccfa2c6`; código estável `269cc6a`). O fluxo de fan-out abaixo já foi aplicado com sucesso nas Etapas 2/3/4; a **Etapa 5** é a próxima (paralelização **Alta**, ~3 agentes — ver a tabela do §1). Detalhe operacional em `AUTONOMOUS_EXECUTION_PROTOCOL.md`.

O fan-out em worktree só é aplicável **depois** que os contratos da etapa (skeletons de entidade/enum/interface + migration + serviços centrais) estão **committados** — worktrees ramificam do HEAD committado, uncommitted não chega ao implementador isolado. Por isso cada etapa: o **orquestrador** cria o "andaime de contratos" e **committa** (localmente, autonomamente — o commit do andaime deixou de ser passo do humano com o `AUTONOMOUS_EXECUTION_PROTOCOL`); só então o paralelismo real por subagente começa. Etapas de alto acoplamento (0, 2) ou artefato central único (migration/serviço) rodam sequenciais no orquestrador.

---

## 1. Mapa geral (Etapas 0 → 9)

Legenda de paralelização: **Nula** (1 agente, sequencial) · **Baixa** (1–2) · **Média** (2–3) · **Alta** (3–4).

| Etapa | Conteúdo | Paralelização | Máx. agentes úteis | Depende de | Gargalo / porquê |
|---|---|---|---|---|---|
| **0** | Fundação: esqueleto do domínio, mapeamento Doctrine, permissões | **Nula** | **1 (orquestrador)** | — | Poucos arquivos centrais e compartilhados (doctrine.yaml, PermissionFixture); tarefas minúsculas e interdependentes. Delegar custaria mais que faz. |
| **1** | Cadastro: `Carteira`, `ObjetoCobranca`, `Pessoa`, `VinculoPessoaObjeto` + repos + UseCases | **Média** | **2** (fan-out) | 0 | Entidades + **1 migration + factories compartilhadas** = andaime sequencial do orquestrador (commit). Depois, 2 clusters coesos independentes: {Carteira+Objeto} × {Pessoa+Vínculo}. **Detalhe completo em §3.** |
| **2** | Núcleo: `CasoCobranca`, `Obrigacao`, `EventoHistorico`, `CalculadoraSaldo` | **Baixa** | **1–2** | 1 | Agregado central altamente acoplado; `CalculadoraSaldo` é dependência de quase tudo adiante. Fan-out cria mais conflito que ganho. |
| **3** | `Pagamento`+`AlocacaoPagamento`, `Liquidacao`, `CalculadoraHonorarios` | **Média** | **2** | 2 | `Pagamento` e `Liquidacao` são áreas separáveis; honorários dependem do saldo. 2 agentes após o andaime. |
| **4** | `Acordo` | **Baixa** | **1** | 2 (pode sobrepor a 3) | Um agregado só; substitui/gera obrigações. Sequencial. Pode correr em paralelo com a Etapa 3 (áreas distintas) se ambas partirem do núcleo committado. |
| **5** | Estados/Judicialização/Encerramento, `ProximaAcao`, `RevisaoPessoaCobrada`, `AlertasCobranca` | **Alta** | **3** | 2 | Sub-features genuinamente independentes (ação × revisão × alertas × judicialização) → boas fatias paralelas após o andaime. |
| **6** | Documentos do Caso (`CobrancaDocumento`/`CobrancaSecao`) | **Baixa** | **1–2** | 2 | Só precisa do `CasoCobranca` (Etapa 2). **Trilha paralela candidata** às Etapas 3/4/5 (áreas de arquivo distintas). |
| **7** | Importação em massa | **Nula** | **1** | 1–6 (núcleo pronto) | Pipeline iterativo, calibrado com dado real; reusa UseCases. Não paraleliza bem. |
| **8** | Telas operacionais + UX | **Alta** | **3–4** | 2–6 | Cada cluster de tela/controller é área separável → alta paralelização, **desde que** base/menu/rotas compartilhados sejam committados primeiro. |
| **9** | Alertas na UI + Dashboard | **Baixa** | **1–2** | 5, 8 | Poucas telas, muita agregação de leitura. |

**Dependências entre etapas (resumo):** `0 → 1 → 2` é uma espinha estritamente sequencial. A partir do núcleo committado (fim da 2), abrem-se **trilhas paralelas**: `{3, 4}` e `6` podem correr como frentes distintas; `5` depende só da 2. `7` exige o núcleo. `8` e `9` fecham. O paralelismo **mais valioso** da feature é **entre etapas** (trilhas 3/4/6 e o leque de telas na 8), não dentro da Etapa 0.

**Onde paralelizar seria erro:** Etapas 0, 2, 4, 7 — acoplamento alto ou artefato central compartilhado (migration única, `CalculadoraSaldo`, pipeline). Forçar agentes aí gera conflito de merge e retrabalho maior que o ganho.

---

## 2. Mapa detalhado — Etapa 0 (Fundação do domínio e do módulo)

**Objetivo:** deixar o domínio `App\Cobranca` reconhecido pela aplicação e o módulo `cobrancas` no catálogo de permissões — **sem** entidades, migrations, controllers, telas ou código especulativo de etapas futuras.

**Conclusão de paralelização:** **1 agente (o orquestrador), sequencial.** As tarefas tocam arquivos centrais compartilhados (`doctrine.yaml`, `PermissionFixture.php`), são de poucos minutos e têm verificação global única. O custo de isolar em worktree + revisar + integrar excede qualquer ganho. Além disso, **nada está committado** → fan-out em worktree nem seria aplicável. Este é o exemplo canônico de "não forçar agentes".

### Tarefas atômicas

```text
T0.1 — Criar esqueleto de diretórios do domínio
Etapa do PLAN: 0
Tipo: SEQUENCIAL
Dependências: nenhuma
Arquivos/área: app/src/Cobranca/{Controller,UseCase,Entity,Repository,DTO,Form,Enum,Service,Exception}/.gitkeep
Conflito: alto com qualquer tarefa que crie arquivos no mesmo domínio (por isso é a primeira)
Agente: orquestrador
Resultado: layout de domínio padrão presente em disco e versionável
Critério de conclusão: as 9 subpastas existem com .gitkeep; `find app/src/Cobranca -type d` lista o layout
```

```text
T0.2 — Registrar mapeamento Doctrine do domínio (AppCobranca)
Etapa do PLAN: 0
Tipo: DEPENDE DE [T0.1]
Dependências: T0.1 (o dir Entity/ precisa existir — o driver de atributos exige diretório existente)
Arquivos/área: app/config/packages/doctrine.yaml (bloco orm.mappings) — arquivo CENTRAL compartilhado
Conflito: alto — arquivo central; jamais editar em paralelo com outra tarefa
Agente: orquestrador
Resultado: entrada AppCobranca → src/Cobranca/Entity registrada (espelha AppDjen/AppPasta)
Critério de conclusão: `php bin/console cache:clear` sem erro com a nova entrada
Risco conhecido: mapeamento de atributos sobre diretório VAZIO. Se o cache:clear falhar por ausência
  de classes mapeadas, o mapeamento é ADIADO para a Etapa 1 (quando existir a 1ª entidade) e isso é
  documentado — não se cria entidade especulativa para "preencher" o diretório.
```

```text
T0.3 — Adicionar permissões do módulo cobrancas ao catálogo
Etapa do PLAN: 0
Tipo: PARALELA (em relação a T0.2 — arquivos diferentes), executada pelo orquestrador junto de T0.2
Dependências: nenhuma técnica sobre T0.1/T0.2 (arquivo distinto)
Arquivos/área: app/src/DataFixtures/PermissionFixture.php (array PERMISSIONS)
Conflito: baixo com T0.2 (arquivo diferente); alto com qualquer outra edição do mesmo fixture
Agente: orquestrador
Resultado: 4 capacidades da SPEC §22 no catálogo, padrão idempotente (upsert por code)
Critério de conclusão: `php -l` do fixture OK; as 4 permissões presentes no array; nomes coerentes com AUTORIZACAO.md
```

> **Nota sobre T0.3 (por que fica no orquestrador e não vira 2ª worktree):** T0.2 e T0.3 são
> tecnicamente **independentes** (arquivos diferentes) e, num mundo de tarefas grandes, seriam uma
> onda de 2 agentes. Aqui são **duas edições de poucas linhas** cada — o orquestrador faz as duas na
> mesma onda, sem isolar. Classificá-las como "PARALELA" descreve a **independência**, não uma
> recomendação de gastar 2 agentes.

```text
T0.4 — Verificações da fundação (sem tocar dados)
Etapa do PLAN: 0
Tipo: SEQUENCIAL
Dependências: T0.1, T0.2, T0.3
Arquivos/área: nenhum (só comandos de verificação no container)
Conflito: nenhum
Agente: orquestrador
Resultado: fundação validada sem alterar o banco de dados
Critério de conclusão:
  - `php bin/console cache:clear` sem erro;
  - `php bin/console doctrine:mapping:info` sem erro (domínio vazio não quebra);
  - `php -l` do PermissionFixture OK;
  - NÃO rodar `doctrine:fixtures:load` (purga o dataset real do dev) nem migrations.
```

### Ondas de execução — Etapa 0

```text
ONDA 1  (1 agente — orquestrador)
└── T0.1  Criar esqueleto de diretórios            [SEQUENCIAL, base de tudo]

↓ SINCRONIZAÇÃO: confirmar que src/Cobranca/Entity existe

ONDA 2  (1 agente — orquestrador; T0.2 e T0.3 são independentes entre si)
├── T0.2  Mapeamento Doctrine (doctrine.yaml)       [DEPENDE DE T0.1]
└── T0.3  Permissões (PermissionFixture.php)         [PARALELA a T0.2, arquivos distintos]

↓ SINCRONIZAÇÃO E REVISÃO: conferir os dois diffs; nenhum toca o mesmo arquivo

ONDA 3  (1 agente — orquestrador)
└── T0.4  Verificações (cache:clear, mapping:info, php -l)  [SEQUENCIAL]
```

| Onda | Agentes ideais | Tarefas | Por que juntas / separadas | Sincronização | Verificação antes de avançar |
|---|---|---|---|---|---|
| 1 | 1 | T0.1 | Base física do domínio; tudo depende dela | Dir `Entity/` existe | `find` lista as 9 subpastas |
| 2 | 1 | T0.2 + T0.3 | Arquivos diferentes (independentes), mas pequenos demais para isolar | Dois diffs em arquivos distintos, sem sobreposição | T0.2: `cache:clear` OK · T0.3: `php -l` OK |
| 3 | 1 | T0.4 | Verificação global única | — | Todos os critérios de T0.4 verdes |

### Pontos que NÃO entram na Etapa 0 (evitar escopo/especulação)

- **Item de menu na sidebar** — exige rota (`app_cobranca_*`) inexistente até a Etapa 8; `path()` para rota inexistente quebra o Twig. Fica na Etapa 8. A Etapa 0 registra apenas a identidade do módulo (`modules.cobrancas.view`).
- **Migration** — não há entidades ainda; nenhuma migration na Etapa 0 (PLAN).
- **`cobrancas_uploads_dir` em services.yaml** — pertence à Etapa 6 (Documentos).
- **Qualquer entidade/enum/UseCase/controller** — Etapa 1+.

### Follow-up registrado (não executar agora)

- **Permissões em produção (divergência F6 do AUTORIZACAO.md):** o catálogo de prod vem de **migration** (`Version20260401130000.php`), não do fixture. Antes do deploy da feature, as permissões `cobrancas` precisarão de uma **data-migration** equivalente. Não se cria agora (a regra proíbe migrations antecipadas e o deploy é no fim); fica anotado como pendência de deploy.

---

## 3. Mapa detalhado — Etapa 1 (Núcleo de cadastro: Carteira, Objeto, Pessoa, Vínculo)

**Escopo (PLAN §8/Etapa 1):** entidades `Carteira`, `ObjetoCobranca`, `Pessoa`, `VinculoPessoaObjeto`; enums `ModoCarteira`, `TipoVinculo`, `FormaHonorarios`; embeddable `RegraHonorarios`; repositories com filtro de tenant; UseCases `CriarCarteira`, `EditarConfiguracaoCarteira`, `CriarObjeto`, `CriarPessoa`, `SugerirPessoasDuplicadas`, `VincularPessoaAObjeto`, `EncerrarVinculo`; testes unit dos UseCases + testes de repositório (filtro de tenant) + **cross-tenant** (dedup de Pessoa nunca atravessa tenant); **1 migration** criando as 4 tabelas.

**Risco:** MÉDIO — novas entidades tenant-scoped; isolamento multi-tenant é inegociável (teste cross-tenant obrigatório). Sem dinheiro nesta etapa.

### 3.1 Grafo de dependências (por que o andaime é sequencial)

```
enums (ModoCarteira, TipoVinculo, FormaHonorarios)   ← (nada)
RegraHonorarios (embeddable)                          ← FormaHonorarios
Carteira            ← Cliente(existe) + Tenant(existe) + RegraHonorarios + ModoCarteira + TipoVinculo
ObjetoCobranca      ← Carteira
Pessoa              ← Tenant(existe)
VinculoPessoaObjeto ← Pessoa + ObjetoCobranca + TipoVinculo
Migration (1 arquivo ÚNICO)  ← as 4 entidades
Foundry factories (4)        ← as 4 entidades
```

As 4 entidades se cruzam por FK e **convergem numa única migration e num conjunto de factories** que os testes de vários clusters consomem. É o oposto de trabalho paralelizável ("ambas precisam conhecer a implementação final da outra" + "editam o mesmo arquivo central"). **Logo o andaime é 100% do orquestrador, sequencial** — não se ganha nada dividindo-o entre agentes, e se perde em conflito de migration.

### 3.2 Contratos compartilhados (criados PRIMEIRO pelo orquestrador, antes de qualquer fan-out)

Estes são a fundação que as tarefas paralelas consomem e **não** editam:

1. **Enums** — `ModoCarteira` (unico/multiplo), `TipoVinculo` (proprietário/…/outro), `FormaHonorarios` (acrescido_divida/retido_recuperado/cobrado_separado/sem_percentual). `string` + `label()`.
2. **Embeddable `RegraHonorarios`** — `forma` (FormaHonorarios) + `percentual?`.
3. **4 entidades** — `Carteira` (FK Cliente + Tenant, embute RegraHonorarios, campos modo/toleranciaAtrasoDias/tipoVinculoPreferido/rotuloObjeto), `ObjetoCobranca` (FK Carteira + Tenant, referenciaExterna?), `Pessoa` (Tenant, nome/cpf?/cnpj?/contatos), `VinculoPessoaObjeto` (FK Pessoa + Objeto + Tenant, tipoVinculo/dataInicio/dataFim?/motivoEncerramento?/observacao?). Todas `TenantAware` + `Auditavel`, com getters/setters. **Assinaturas de campo/FK são o contrato.**
4. **4 repositórios-stub** — `CarteiraRepository`, `ObjetoCobrancaRepository`, `PessoaRepository`, `VinculoPessoaObjetoRepository` (extends `ServiceEntityRepository`, vazios), para as entidades referenciarem `repositoryClass` e o DI autoconfigurar.
5. **Migration** — cria `cobranca_carteira`, `cobranca_objeto`, `cobranca_pessoa`, `cobranca_vinculo_pessoa_objeto` (+ colunas do embeddable e enums). Aplicada em dev/test.
6. **4 Foundry factories** — `CarteiraFactory`, `ObjetoCobrancaFactory`, `PessoaFactory`, `VinculoPessoaObjetoFactory`. **Ficam no contrato de propósito**: o cluster Vínculo precisa das factories de Pessoa e Objeto; centralizá-las evita duplicação/conflito entre worktrees.

> **Nota de conteúdo dos stubs:** repositório-stub = classe vazia. Se uma *query* for necessária a **mais de um** cluster, ela entra no stub (contrato); queries usadas por **um só** cluster ficam no repositório daquele cluster (que passa a ser seu dono). Em Etapa 1, `VincularPessoaAObjeto` só usa `find()` (id + filtro de tenant), então **nenhuma query cross-cluster é necessária** — cada repositório fica com um dono único.

### 3.3 Checkpoint de commit manual (o ponto exato)

**Depois de T1.0g validar o andaime e ANTES da Onda 2 (fan-out).** Motivo técnico duro: worktrees ramificam do **HEAD committado**; entidades/enums/embeddable/stubs/factories **uncommitted não chegam** às worktrees dos implementadores. Sem esse commit, o fan-out não compila nem consegue mockar/testar. É o único checkpoint de commit **obrigatório** no meio da etapa (o commit final vem após a Onda 3).

### 3.4 Tarefas atômicas

**Andaime (orquestrador) — todas SEQUENCIAL, dependência em cadeia:**

```text
T1.0a — Enums (ModoCarteira, TipoVinculo, FormaHonorarios)
  Tipo: SEQUENCIAL · Dep: nenhuma · Área: src/Cobranca/Enum/*
  Conflito: base de tudo · Agente: orquestrador
  Critério: php -l OK; usados pelas entidades

T1.0b — Embeddable RegraHonorarios
  Tipo: DEPENDE DE [T1.0a] · Área: src/Cobranca/Entity/RegraHonorarios.php

T1.0c — 4 entidades (TenantAware + Auditavel)
  Tipo: DEPENDE DE [T1.0a, T1.0b] · Área: src/Cobranca/Entity/{Carteira,ObjetoCobranca,Pessoa,VinculoPessoaObjeto}.php
  Conflito: ALTO — arquivos-contrato; congelados após commit

T1.0d — 4 repositórios-stub
  Tipo: DEPENDE DE [T1.0c] · Área: src/Cobranca/Repository/*Repository.php

T1.0e — Migration das 4 tabelas + aplicar dev/test
  Tipo: DEPENDE DE [T1.0c] · Área: app/migrations/VersionYYYYMMDDHHMMSS.php (ARQUIVO ÚNICO)
  Conflito: ALTO — só o orquestrador toca

T1.0f — 4 Foundry factories
  Tipo: DEPENDE DE [T1.0c] · Área: tests/Cobranca/Factory/*Factory.php

T1.0g — Validação do andaime
  Tipo: DEPENDE DE [T1.0c–f] · Área: nenhuma (comandos)
  Critério: cache:clear OK; doctrine:schema:validate OK; migration up (e down) aplica em dev/test; php -l dos novos arquivos
  ↓↓↓  CHECKPOINT: COMMIT MANUAL DO ANDAIME  ↓↓↓
```

**Fan-out (implementadores em worktree) — PARALELA entre si, cada uma DEPENDE DE o commit do andaime:**

```text
T1.A — Cluster "Carteira & Objeto"
  Tipo: PARALELA · Dep: commit do andaime
  Área EXCLUSIVA: Repository/{Carteira,ObjetoCobranca}Repository.php (queries próprias),
    UseCase/{CriarCarteira,EditarConfiguracaoCarteira,CriarObjeto}.php, DTO/ desses UseCases,
    tests/Cobranca/Unit/ desses UseCases + tests de repositório (filtro de tenant) desses 2 repos
  Não pode alterar: entidades, enums, embeddable, migration, factories, arquivos do cluster B
  Contrato a respeitar: assinaturas das entidades/factories; fluxo Controller→…→UseCase (aqui só UseCase+DTO)
  Agente: feature-implementer
  Critério: UseCases implementados com storytelling; testes unit escritos (mock de repo); tenant atribuído do usuário; informar arquivos alterados

T1.B — Cluster "Pessoa & Vínculo"
  Tipo: PARALELA · Dep: commit do andaime
  Área EXCLUSIVA: Repository/{Pessoa,VinculoPessoaObjeto}Repository.php (incl. query de dedup por CPF/CNPJ intra-tenant),
    UseCase/{CriarPessoa,SugerirPessoasDuplicadas,VincularPessoaAObjeto,EncerrarVinculo}.php, DTO/ desses,
    tests/Cobranca/Unit/ desses + repo tests + **teste cross-tenant** (dedup não atravessa tenant; vínculo encerrado preserva histórico)
  Não pode alterar: entidades, enums, embeddable, migration, factories, arquivos do cluster A
  Agente: feature-implementer
  Critério: idem T1.A + cobre invariáveis 7/9/10/11/23/24 e a regra de vigência do vínculo
```

**Integração (orquestrador) — SEQUENCIAL:**

```text
T1.R — Revisão + integração + verificação
  Tipo: SEQUENCIAL · Dep: T1.A, T1.B
  Passos: (1) feature-review-agent revisa cada bloco (read-only, isolado);
          (2) integra UM bloco por vez no checkout principal;
          (3) após cada integração roda os testes direcionados no container;
          (4) ao fim da onda: suíte completa + verificação cross-tenant + doctrine:schema:validate
  Critério (PLAN): suíte verde; migration aplica; dedup só intra-tenant; vínculo encerrado preserva histórico
```

### 3.5 Ondas de execução — Etapa 1

```text
ONDA 1  (1 agente — orquestrador)          ANDAIME DE CONTRATOS
├── T1.0a Enums
├── T1.0b RegraHonorarios
├── T1.0c 4 entidades
├── T1.0d 4 repositórios-stub
├── T1.0e Migration (única) + aplicar dev/test
├── T1.0f 4 factories
└── T1.0g Validação

↓↓↓  CHECKPOINT: COMMIT MANUAL DO ANDAIME (obrigatório antes do fan-out)  ↓↓↓

ONDA 2  (2 agentes — feature-implementer, em worktrees isoladas)   FAN-OUT
├── Agente A → T1.A  Cluster "Carteira & Objeto"
└── Agente B → T1.B  Cluster "Pessoa & Vínculo"

↓  SINCRONIZAÇÃO E REVISÃO (feature-review-agent por bloco; áreas de arquivo disjuntas)

ONDA 3  (1 agente — orquestrador)          INTEGRAÇÃO + VALIDAÇÃO
└── T1.R  Integra 1-a-1 → testes direcionados → suíte completa → cross-tenant → commit final
```

| Onda | Agentes ideais | Tarefas | Por que juntas/separadas | Sincronização | Verificação antes de avançar |
|---|---|---|---|---|---|
| 1 | **1** (orquestrador) | T1.0a–g | Cadeia de dependências + migration/factories únicas — indivisível | Andaime compila e migra | `cache:clear`, `doctrine:schema:validate`, migration up/down em dev/test |
| 2 | **2** (implementadores) | T1.A, T1.B | 2 sub-domínios coesos, **áreas de arquivo disjuntas**, dependem só do andaime committado | Dois diffs sem sobreposição | cada bloco: `php -l` + testes escritos (rodados só na Onda 3) |
| 3 | **1** (orquestrador) | T1.R | Integração é serial por natureza; validação global única | — | suíte completa verde + cross-tenant OK |

**Por que 2 agentes no fan-out (e não 3–4):** as áreas são independentes, mas os blocos são pequenos-médios e a **integração é serial**. Agrupar por sub-domínio coeso — {Carteira→Objeto} e {Pessoa→Vínculo} — dá dois blocos equilibrados, corta pela metade os ciclos de revisão/integração e reduz o risco de uma lacuna de contrato aparecer atravessando dois agentes. Dividir em 4 (um por agregado) só valeria se os blocos fossem grandes; aqui `ObjetoCobranca` (1 UseCase) e o vínculo colam naturalmente em seus vizinhos. É "número por consequência de dependência", não por enfeite.

### 3.6 Regras de exclusividade / principais riscos de conflito

| Risco | Onde | Mitigação |
|---|---|---|
| **Migration única editada por 2 agentes** | `app/migrations/Version*.php` | Toda a migração é do andaime (Onda 1). Nenhum cluster cria/edita migration. Falta de coluna → PARAR e devolver ao orquestrador. |
| **Factories cross-cluster** (Vínculo precisa de Pessoa+Objeto) | `tests/Cobranca/Factory/*` | Factories no contrato (Onda 1), committadas. Clusters só consomem. |
| **Query em repositório de outro cluster** | `Repository/*` | Cada repo tem dono único; query compartilhada iria ao stub do contrato. Em Etapa 1 não há query cross-cluster (`VincularPessoa` usa `find()`). |
| **Entidade precisa de campo novo durante o fan-out** | `Entity/*` (congelado) | Contrato congelado após commit; implementador **para e devolve ao orquestrador** (regra do workflow: não alterar contrato compartilhado). |
| **DI / services.yaml** | `app/config/services.yaml` | Repositórios (`ServiceEntityRepository`) e UseCases são autoconfigurados/autowired; **nenhuma edição** de services.yaml na Etapa 1 → sem conflito. |
| **Isolamento multi-tenant** (não é conflito de merge, é o risco do domínio) | todos os repos/UseCases | `TenantAware` + FK nn (no contrato) + atribuição do tenant do usuário nos UseCases + teste cross-tenant no cluster B; `tenant-safety-review` antes do commit final. |

---

## 4. Teste piloto de fan-out — Etapa 1

**Objetivo:** validar na prática o ciclo autônomo `worktrees → impl. paralela → commit por implementador → revisão read-only → cherry-pick individual → teste direcionado → estabilização → próximo → suíte`. Escopo reduzido: **2 feature-implementers**, uma tarefa pequena cada. **Não** é a Onda 2 completa.

**Infra confirmada:** `.claude/settings.json` → `worktree.baseRef=head`; hook `block-git-writes.py` (commit `228c294`) **permite** `git add`/`commit`/`worktree add`/`cherry-pick` de **um único commit** (`--continue`/`--abort`), e **bloqueia** push/pull/merge/rebase/reset e cherry-pick com range/múltiplos. Andaime da Onda 1 no HEAD (`46afae5`); working tree limpo.

**Tarefa A — `CriarCarteira`**
- Arquivos previstos: `app/src/Cobranca/DTO/CriarCarteiraInput.php`, `app/src/Cobranca/UseCase/CriarCarteiraUseCase.php`, `app/tests/Cobranca/Unit/CriarCarteiraUseCaseTest.php` (+ Exception própria, se necessário).
- Depende de: andaime (`Carteira`, `CarteiraRepository`, enums); lê `Cliente` via `ClienteRepository::findOneBy` (sem editar).
- Não pode tocar: entidades, enums, migration, repositórios-stub, factories, arquivos da tarefa B.

**Tarefa B — `CriarPessoa`**
- Arquivos previstos: `app/src/Cobranca/DTO/CriarPessoaInput.php`, `app/src/Cobranca/UseCase/CriarPessoaUseCase.php`, `app/tests/Cobranca/Unit/CriarPessoaUseCaseTest.php`.
- Depende de: andaime (`Pessoa`, `PessoaRepository`).
- Não pode tocar: entidades, enums, migration, repositórios-stub, factories, arquivos da tarefa A.

**Dependências:** A e B dependem só do andaime committado; **não** dependem uma da outra.

**Risco de conflito:** nenhum arquivo compartilhado é editado. Ambos criam arquivos **novos e distintos** em `DTO/`, `UseCase/` e `tests/Cobranca/Unit/` (mesmos diretórios, arquivos diferentes → cherry-picks não colidem). Nenhum edita contrato congelado.

**Critério de sucesso:** 2 commits autocontidos (um por implementador); cherry-pick individual sem conflito; testes direcionados de cada UseCase verdes após cada integração; suíte da Etapa 1 verde ao final; diff consolidado sem alteração de contrato compartilhado. Resultado real registrado em `EXECUTION_STATUS.md`.

**Independência:** CONFIRMADA — sem sobreposição real; teste piloto segue com A e B.

### Resultado real do piloto — ✅ pipeline APROVADO

- **Agentes:** 2 `feature-implementer` (worktrees isoladas, `isolation: worktree`, ramificadas do HEAD `228c294`) + 2 `feature-review-agent` (read-only).
- **Commits:** A `761ffd1` → cherry-pick `454bbf2`; B `b42f11b` → cherry-pick `f6362f0`. Um commit autocontido por implementador; **integração serial** (A depois B), sem conflito.
- **Testes (no container, pós-integração):** A 2/17 (inclui rejeição cross-tenant do credor), B 2/21, `tests/Cobranca` 4/38 — todos verdes.
- **Diff consolidado:** 7 arquivos novos (400 inserções); nenhum contrato congelado alterado.
- **Conflitos de merge:** nenhum. **Atrito de infra:** hook bloqueou `cherry-pick … 2>&1` (redirecionamento vira 2º arg) → regra: cherry-pick recebe **só o hash**.
- **Veredito:** ciclo `worktree → impl. paralela → commit → revisão read-only → cherry-pick individual → teste direcionado → estabilização → próximo → suíte` **validado ponta a ponta e aprovado** para uso autônomo prolongado. Detalhes em `EXECUTION_STATUS.md`.
