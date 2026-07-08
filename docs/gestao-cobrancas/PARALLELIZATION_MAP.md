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

O Git é **manual (humano)** neste projeto e nada da feature está committado ainda. Enquanto os contratos de uma etapa não forem committados, o fan-out em worktree **não é aplicável** — a etapa roda no orquestrador. Por isso o paralelismo real por subagente só começa a valer a partir da **Etapa 1** e, mesmo assim, **depois** de o orquestrador criar e o humano committar o "andaime de contratos" (skeletons de entidade/enum/interface + migration) da etapa.

---

## 1. Mapa geral (Etapas 0 → 9)

Legenda de paralelização: **Nula** (1 agente, sequencial) · **Baixa** (1–2) · **Média** (2–3) · **Alta** (3–4).

| Etapa | Conteúdo | Paralelização | Máx. agentes úteis | Depende de | Gargalo / porquê |
|---|---|---|---|---|---|
| **0** | Fundação: esqueleto do domínio, mapeamento Doctrine, permissões | **Nula** | **1 (orquestrador)** | — | Poucos arquivos centrais e compartilhados (doctrine.yaml, PermissionFixture); tarefas minúsculas e interdependentes. Delegar custaria mais que faz. |
| **1** | Cadastro: `Carteira`, `ObjetoCobranca`, `Pessoa`, `VinculoPessoaObjeto` + repos + UseCases | **Média** | **2–3** | 0 | Entidades + **1 migration compartilhada** = andaime sequencial (orquestrador). Depois, UseCases são arquivos independentes → paralelizáveis (ex.: cluster Pessoa/Vínculo × cluster Carteira/Objeto). |
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
