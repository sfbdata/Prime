# Cobertura do isolamento multi-tenant — fechar os furos do `TenantFilter`

**Data:** 2026-08-12
**Risco:** **MÉDIO/ALTO** — toca `TenantRole`/`Permission`/`Sede`/`Cargo` (MÉDIO) e entidades de
ponto eletrônico (ALTO). Exige spec (este documento), revisão contra a spec e, no domínio Ponto,
re-revisão antes de seguir.
**Status:** fatias 1 e 2 entregues (a 1 revisada; a 2 **aguarda revisão**). Fatias 3–8 pendentes.

**Origem:** avaliação de risco do desenho [MCP remoto com OAuth](mcp-remoto-oauth.md). O MCP não é
pré-requisito nem consequência deste trabalho — os furos abaixo **já existem hoje** e valem para o
navegador. O desenho do MCP só os tornou visíveis, porque ele seria o primeiro caminho a depender
do filtro como garantia única.

---

## 1. O defeito, em uma frase

`TenantFilter` só age em entidades que implementam `TenantAware`
([`app/src/Shared/Doctrine/Filter/TenantFilter.php`](../../app/src/Shared/Doctrine/Filter/TenantFilter.php)).
São 83 entidades mapeadas; **um conjunto delas ficou sem a etiqueta e ninguém percebeu**, porque
nada no projeto verifica cobertura. O `Kanban` inteiro está fora.

O contrato [`TenantAware`](../../app/src/Shared/Contract/TenantAware.php) **já manda** o que
deveria valer:

> *"Toda entidade de negócio escopada por escritório deve implementá-la e ter uma relação
> ManyToOne(Tenant) com JoinColumn(nullable: false)."*

A regra estava escrita. Só não estava sendo cobrada.

### 1.1 Por que os testes existentes não pegaram

Existem vários testes de isolamento por domínio — `PontoIsolamentoRepositoryTest`,
`DeleteSedeCrossTenantTest`, `NotificacaoIsolamentoRepositoryTest`,
`PerfilAdminIsolamentoControllerTest` e outros. **Todos passam.** Eles provam que *a ferramenta
testada* isola; nenhum prova que *o perímetro* cobre. Um domínio que ninguém pensou em testar —
Kanban — não aparece em teste nenhum e não quebra nada.

**Consequência de projeto:** o item nº 1 é o teste de cobertura, não a correção de nenhum domínio.
Sem ele, corrigir um domínio por vez não tem alvo objetivo e a próxima entidade escapa igual.

---

## 2. Fatos medidos (2026-08-12, dev)

Sonda executada com o filtro ligado apontando para o tenant 2, sobre dado do tenant 1:

| Caminho | Resultado |
|---|---|
| DQL em `Cliente` / `ClientePF` (herança JOINED) | filtro aplicado ✅ |
| DQL em `Processo` | filtro aplicado ✅ |
| `find()` por PK, EntityManager limpa | **bloqueado** ✅ |
| `findOneBy(['id' => …])` | bloqueado ✅ |
| `getReference()` + acesso | bloqueado (`EntityNotFoundException`) ✅ |
| **DQL em `KanbanBoard`** | **filtro NÃO aplicado** 🔴 |
| **`find()` por PK sem `clear()` entre chamadas** | **vazou pelo identity map** 🔴 |

**Duas premissas do próprio código caíram:**

1. **21 repositories** (medido: `grep -rn "filtro SQL do Doctrine" app/src --include=*.php`)
   comentam *"o filtro SQL do Doctrine NÃO se aplica a `find()` por PK (risco cross-tenant)"*.
   **Medido: aplica**, no Doctrine ORM 3.x com a EntityManager limpa. Os métodos
   `…DoTenant` continuam certos como defesa em profundidade, mas **o motivo registrado está errado
   e esconde o motivo verdadeiro**, que é o identity map. Corrigir os comentários faz parte do
   trabalho (fatia 6) — um comentário falso faz o próximo leitor relaxar a guarda certa.
2. A herança JOINED de `Cliente` **é** coberta: `ReflectionClass::implementsInterface()` enxerga a
   interface herdada do pai. `ClientePF`/`ClientePJ` não precisam de nada.

---

## 3. Inventário

### 3.1 Categoria A — a coluna existe, falta a etiqueta

Sem migration. Basta `implements TenantAware` + `getTenant()`.

| Tabela | Domínio | `tenant_id` | Risco |
|---|---|---|---|
| `kanban_board` | Kanban | `NOT NULL` | BAIXO |
| `sede` | Tenant | `NOT NULL` | MÉDIO |
| `cargo` | Tenant | `NOT NULL` | MÉDIO |
| `lotacao` | Tenant | `NOT NULL` | MÉDIO |
| `tenant_role` | Permission | `NOT NULL` | MÉDIO |
| `aceite_termo` | Termo | nullable — **7 de 10 linhas nulas** | ver §5 |
| `audit_log` | Auditoria | nullable — **42.760 de 54.434 linhas nulas** (~79%) | ver §5 |

### 3.2 Categoria B — sem coluna nenhuma; hoje só o pai delimita

**Decisão do dono (12/08/2026): denormalizar.** Criar `tenant_id NOT NULL` em todas, com backfill
derivado do pai, e implementar `TenantAware`.

| Tabela | Domínio | Linhas (dev) | Origem do backfill | Risco |
|---|---|---|---|---|
| `kanban_coluna` | Kanban | 28 | `board` | BAIXO |
| `kanban_card` | Kanban | 6 | `coluna → board` | BAIXO |
| `kanban_comentario` | Kanban | 0 | `card → coluna → board` | BAIXO |
| `kanban_checklist` | Kanban | 0 | `card → …` | BAIXO |
| `kanban_checklist_item` | Kanban | 0 | `checklist → …` | BAIXO |
| `kanban_anexo` | Kanban | 0 | `card → …` | BAIXO |
| `kanban_marcador` | Kanban | 0 | `board` | BAIXO |
| `chamado_interacao` | ServiceDesk | 0 | `chamado` | BAIXO |
| `chamado_anexo` | ServiceDesk | 0 | `chamado` | BAIXO |
| `pasta_processo` | Pasta | 75 | `pasta` | MÉDIO |
| `invitation`\* | Auth | 10 | já tem coluna, nullable — **0 nulos** | MÉDIO |
| `tenant_role_permission` | Permission | 154 | `tenant_role` | MÉDIO |
| `bloco_jornada` | Ponto | 1 | a confirmar | **ALTO** |
| `jornada_colaborador` | Ponto | 8 | a confirmar | **ALTO** |
| `bloco_jornada_colaborador` | Ponto | 9 | a confirmar | **ALTO** |

\* `invitation` está na tabela por ser furo da mesma fatia, mas **não** é Categoria B: a coluna já
existe e **não tem nulo nenhum** (0 de 10). O trabalho lá é só pôr a etiqueta e trancar a coluna.

### 3.2.1 ⚠️ Os volumes acima vieram do banco ERRADO na primeira medição

O `CLAUDE.md` do projeto documenta `docker exec jusprime_db_dev psql -U symfony -d saas`, mas
**o app roda em `saas_ux`** (`php bin/console dbal:run-sql "SELECT current_database()"`). A primeira
versão desta spec mediu tudo em `saas`, que é um banco antigo parado no tempo. A revisão da fatia 1
conferiu os números — contra o mesmo banco errado — e os deu por corretos.

**O que NÃO mudou:** os fatos estruturais. Quais tabelas têm `tenant_id`, e a nullability de cada
uma, são idênticos nos dois bancos. Logo a classificação (Categoria A × B × fora do escopo), o
inventário e todas as conclusões desta spec seguem de pé.

**O que mudou:** todo volume e toda contagem de nulo. `audit_log` era "19.390 de 29.791" e é
42.760 de 54.434; `pasta_processo` era 39 e é 75; `tenant_role_permission` era 127 e é 154;
`kanban_coluna` 20 → 28; `kanban_card` 3 → 6. E `invitation`, que a spec dizia ter nulos a decidir,
**não tem nenhum**.

**Regra que fica: medir sempre em `saas_ux`**, ou melhor, perguntar ao framework
(`dbal:run-sql`) em vez de escolher o banco na mão. Os volumes de Ponto (§3.2, marcados
"a confirmar") ainda são do banco errado — re-medir ao abrir a fatia 6.

**Volumes são do dev.** Antes de cada migration em produção, conferir o volume real pelo MCP
`jusprime-prod` — o dev não é cópia fiel de prod.

**Invariante nova que a denormalização cria:** o `tenant` do filho tem que ser sempre igual ao do
pai. Ela precisa ser garantida na escrita (o construtor do filho deriva o tenant do pai, não
recebe por parâmetro) e provada por teste. Sem isso, a denormalização troca um furo por outro:
uma linha filha com tenant divergente fica invisível para o dono e visível para o vizinho.

### 3.3 Fora do escopo do filtro, e está correto

| Entidade | Por quê |
|---|---|
| `User`, `RedefinicaoSenha`, `CadastroPendente` | identidade — existem antes e acima do vínculo com escritório |
| `UserTenant` | é o **próprio vínculo**. Filtrar aqui seria circular e quebraria a troca de escritório: `TenantContext::setCurrentTenant` precisa enxergar o vínculo do tenant **de destino**, que por definição não é o ativo. Compensação verificada: os 5 métodos de `UserTenantRepository` recebem `User` e/ou `Tenant` explícitos — não há listagem sem escopo |
| `Tenant` | é o próprio eixo |
| `Permission` | catálogo global de permissões (o vínculo por escritório é `TenantRolePermission`) |
| `IndiceMonetario` | tabela global de referência do BCB, sem dado de escritório |
| `UserProfile` | o perfil é do **usuário**, não do escritório — quem atua em dois escritórios tem um perfil só. Garantia **estrutural**, não empírica: `uniq_6bbd6130a76ed395 UNIQUE btree (user_id)`, do `#[ORM\OneToOne]` |
| `ClientePF`, `ClientePJ` | herdam `TenantAware` do pai; medido como coberto |

São **8 entidades** — o mesmo número de `FORA_DO_ESCOPO` no teste (`ClientePF`/`ClientePJ` não
entram na lista: passam por herança, não por exceção). Com as 22 da §3.1 + §3.2, fecham as 30.

⚠️ **`Invitation` NÃO está aqui, e a primeira versão deste documento errou nisso.** Ela é escopada
por escritório por construção (`tenant_id`, `tenant_role_id`, `cargo_id`, `lotacao_id`); convite
vazado expõe e-mail de outro escritório. É **furo**, endereçado na fatia 8. O erro foi pego na
revisão da fatia 1 — e era o achado mais grave justamente porque absolvia: quem executasse a
fatia 8 lendo esta tabela "fecharia" a fatia movendo `Invitation` para cá, e o teste aceitaria.

---

## 4. Fatias

Cada fatia entrega software revisável sozinho. **Uma por vez**, com a suíte verde entre elas.

| # | Fatia | Entrega | Risco |
|---|---|---|---|
| 1 | **Rede de segurança** ✅ | `TenantAwareCoberturaTest` espelhando `AuditavelCoberturaTest` — ver §4.1. | BAIXO |
| 2 | **Kanban** ✅ | Entregue — ver §4.3. | BAIXO |
| 3 | **ServiceDesk** | `chamado_interacao` e `chamado_anexo`. Zero linhas — backfill trivial. | BAIXO |
| 4 | **Tenant** | `sede`, `cargo`, `lotacao`. Ver §5.2 — mexe em tela de administração. | MÉDIO |
| 5 | **Permission** | `tenant_role` (etiqueta) e `tenant_role_permission` (coluna + backfill). | MÉDIO |
| 6 | **Ponto** | `bloco_jornada`, `jornada_colaborador`, `bloco_jornada_colaborador`. **Re-revisão obrigatória.** Inclui corrigir os **21** comentários errados sobre `find()` por PK (§2). | **ALTO** |
| 7 | **Auditoria e Termo** | decisão dos nulos (§5.1) e execução. | MÉDIO |
| 8 | **Pasta e Auth** | `pasta_processo` e `invitation` — ver §4.2. | MÉDIO |

Fatias 2 e 3 são independentes entre si. Da 4 em diante, sequencial — cada uma toca tela ou dado
sensível e o custo de errar cresce.

### 4.1 Como a fatia 1 ficou, e por que difere do que esta spec dizia antes

O plano original mandava o teste **nascer vermelho**, listando os furos. Foi trocado durante a
implementação, deliberadamente: a suíte é o portão de todo o resto do repositório, e um teste
vermelho por semanas — que é o prazo real de "um domínio por vez" — treina todo mundo a ignorar
falha vermelha. A rede perderia a função justamente durante o período em que ela é necessária.

O teste ([`app/tests/Shared/Functional/TenantAwareCoberturaTest.php`](../../app/tests/Shared/Functional/TenantAwareCoberturaTest.php))
usa **duas listas**, **dois tetos** e **cinco asserções**:

- `FORA_DO_ESCOPO` — as 8 entidades da §3.3, cada uma com o motivo escrito ao lado. Permanente.
- `PENDENTE_DE_CORRECAO` — os 22 furos, cada um marcado com a fatia que o resolve.
- `MAX_PENDENTE` / `MAX_FORA_DO_ESCOPO` — os tetos que fazem "só encolhe" ser **cobrado**, não
  prometido em comentário. Baixe-os ao fechar cada fatia.

| Asserção | O que cobra |
|---|---|
| `testTodaEntidadeEstaClassificada` | entidade em nenhuma das listas e sem `TenantAware` quebra a suíte — é a propriedade que faltava e deixou o Kanban passar |
| `testPendenciaNaoTemEntradaObsoleta` | entidade corrigida (ou desmapeada) sai de `PENDENTE`, senão a lista apodrece e para de refletir a dívida |
| `testForaDoEscopoNaoTemEntradaObsoleta` | o mesmo para `FORA_DO_ESCOPO` |
| `testTetosNaoSobem` | a dívida só encolhe — ver §4.1.1 |
| `testListasNaoSeSobrepoem` | classificação ambígua é recusada |

#### 4.1.1 O buraco que a revisão encontrou nesta decisão

A primeira versão desta seção afirmava que *"a proteção contra esquecer está inteira"*. Está — mas
**a proteção contra estacionar não existia**, e a seção não admitia isso.

Caminho concreto, sem má-fé: alguém cria entidade nova sem a etiqueta → `testTodaEntidade…` fica
vermelho → o conserto mais barato é **uma linha**, apendar a classe em `PENDENTE_DE_CORRECAO` →
verde. Furo novo entra com a rede ligada. Pior em `FORA_DO_ESCOPO`, que é permanente.

Daí os tetos. Eles não tornam a saída impossível — tornam-na **visível**: subir o teto tem de
acontecer no mesmo diff, e vira decisão revisada em vez de descuido.

**O que a troca de vermelho por verde custa, agora dito por inteiro:** a dívida fica verde, e some
a pressão de urgência que o vermelho daria. Os tetos cobrem o risco de *crescer*; nada cobre o
risco de *demorar*. Esse continua sendo do dono do cronograma.

#### 4.1.2 A prova, e o erro de método na primeira

A primeira prova rodou cada mutação com `--filter`, isolando a asserção esperada. **Isso é prova
incompleta por construção**: `--filter` esconde qual *outra* asserção também caiu. Foi assim que
uma asserção redundante passou por independente — `testPendenciaCorrigidaSaiDaLista` era
logicamente subsumida pela varredura que percorria as duas listas, e não existia mutação capaz de
derrubá-la sozinha. Eram 3 propriedades independentes vendidas como 4.

Corrigido: cada lista passou a ter seu próprio teste, e a re-prova (12/08/2026) rodou a **classe
inteira** em cada mutação, com o resultado das 5 visível:

| Mutação | Asserção que caiu |
|---|---|
| `KanbanBoard` fora das duas listas | `testTodaEntidadeEstaClassificada`, só ela |
| `Pasta` (já correta) em `PENDENTE` | `testPendenciaNaoTemEntradaObsoleta`, só ela |
| `Pasta` em `FORA_DO_ESCOPO` | `testForaDoEscopoNaoTemEntradaObsoleta`, só ela |
| entrada duplicada em `PENDENTE` | `testTetosNaoSobem`, só ela |
| `Tenant` nas duas listas | `testListasNaoSeSobrepoem`, só ela |

Arquivo conferido idêntico ao original depois das provas.

**Regra que fica:** prova por mutação roda a **classe inteira**, nunca com `--filter`. O que
interessa não é ver a asserção esperada cair — é ver que nenhuma outra caiu junto.

### 4.3 Fatia 2 — Kanban (entregue 12/08/2026)

`KanbanBoard` ganhou só a etiqueta (a coluna já existia, `NOT NULL`). As 7 filhas ganharam
`tenant_id NOT NULL` pela `Version20260812185900`, com backfill pela cadeia do pai.

**A invariante ficou estrutural, não documental:** nenhum construtor de filha recebe tenant por
parâmetro — todos derivam do pai, que eles já recebiam. Não existe caminho no código para criar
filha com tenant divergente. `KanbanCard` é o único com **dois** pais e por isso o único que podia
receber pais discordantes; ganhou guarda explícita (`coluna->getBoard() !== board` é recusado).

**Backfill conferido:** zero linha com tenant divergente do pai, nas 7 tabelas.

**Provado por mutação** (classe inteira, sem `--filter`): tirar a etiqueta de `KanbanColuna` faz a
coluna vazar entre escritórios de novo — o defeito original volta e o teste o pega. Remover a
guarda do card derruba só o teste da invariante.

**Efeito colateral que outra rede pegou:** `PurgaCoberturaSchemaTest` falhou na hora, porque toda
tabela com `tenant_id` precisa estar coberta pela purga de escritório. As 7 entraram como cobertas
por cascata — e a cascata foi **provada no grafo de FKs do banco** (`delete_rule = CASCADE` em cada
elo até `kanban_board`, que já está na `ORDEM_DELECAO`), não aceita pelo comentário existente.
`PurgarEscritorioUseCaseTest` também precisou de ajuste: ele insere por SQL cru, que não passa
pelos construtores e por isso não herdava o tenant.

### 4.2 Entidades que só apareceram com a medição

O levantamento por `grep` classificou errado dois arquivos — `PastaProcesso` e `CadastroPendente`
apenas **mencionam** `TenantAware` num comentário e foram contados como cobertos. A lista
autoritativa veio do metadata do Doctrine (`getAllMetadata()` + `is_a()`), e é a que o teste usa.

Daí a fatia 8:

- **`PastaProcesso`** — associação Pasta↔Processo, hoje isolada só pela Pasta dona. Mesmo padrão
  da Categoria B.
- **`Invitation`** — tem `tenant_id` nullable. Convite vazado expõe e-mail de outro escritório.

`CadastroPendente` é exceção legítima (pré-conta) e entrou em `FORA_DO_ESCOPO`.

**Lição para as próximas fatias:** não classificar entidade por `grep`. A pergunta é para o
framework (`doctrine:mapping:info`, `getAllMetadata()`), como manda o CLAUDE.md.

---

## 5. Decisões que ainda faltam

### 5.1 Os nulos de `audit_log` e `aceite_termo` — antes da fatia 7

`audit_log` tem **79% das linhas com `tenant_id` nulo** (42.760 de 54.434). Pôr a etiqueta faria dois terços da
trilha de auditoria sumirem de toda consulta, silenciosamente — exatamente o modo de falha que
esta spec existe para eliminar, invertido. Três saídas, e nenhuma é óbvia:

- **backfill** dos nulos a partir do `User` que gerou o registro (se derivável);
- **manter fora do filtro**, na lista de exceções, com o escopo garantido no
  `AuditLogRepository` (que já usa SQL nativo e já filtra à mão);
- **backfill parcial** + coluna que continua nullable, com o filtro aceitando nulo.

A mesma pergunta vale para `aceite_termo` (7 de 10 nulos), com peso menor. **Decisão do dono, não
do plano.** Fica na fatia 7 justamente para não travar as anteriores.

### 5.2 Regressão em tela de administração — verificar na fatia 4

Ligar o filtro em `sede`/`cargo`/`lotacao` muda **toda** query existente dessas entidades. A
navegação do super-admin de plataforma parece segura (`TenantContextValidatorListener` deixa
seguir sem tenant, e sem tenant o filtro fica inerte — [`TenantContextValidatorListener.php:60`](../../app/src/EventListener/TenantContextValidatorListener.php)),
mas **isso é leitura, não medição**. A fatia 4 começa por um teste que exercite as telas de
administração antes da mudança.

---

## 6. O que esta spec NÃO cobre

- **Identity map em processo longo** (§2). Sob php-fpm cada requisição tem EntityManager nova e o
  problema não existe. Ele só aparece com worker persistente (FrankenPHP worker mode, RoadRunner,
  Swoole) ou com várias operações de tenants diferentes na mesma requisição. **Não é problema de
  hoje** — é restrição a registrar para quem for construir o MCP remoto ou trocar o modelo de
  execução. Fica documentado aqui e citado na spec do MCP.
- Os 179 métodos de repository sem parâmetro `Tenant` explícito. Com a cobertura fechada, o filtro
  passa a protegê-los de fato; forçar tenant explícito em todos é defesa em profundidade, não
  correção de defeito, e seria um trabalho maior que este. **Fora de escopo, deliberadamente.**
- SQL nativo e DQL de UPDATE/DELETE em massa, que não passam pelo filtro por natureza do Doctrine.
  Os pontos existentes já filtram à mão e documentam o porquê.
- Qualquer coisa do MCP remoto.

---

## 7. Testes que sustentam o trabalho

Cada um provado reintroduzindo o defeito — teste verde não prova nada por si.

1. **Cobertura** (fatia 1): toda entidade ORM implementa `TenantAware` ou consta na lista de
   exceções. É o teste que sustenta a spec inteira.
2. **Cross-tenant por domínio corrigido**: usuário do escritório A pede recurso de B e recebe
   negativa — não lista vazia por acaso, negativa.
3. **Invariante pai↔filho** (Categoria B): criar um filho cujo pai é de outro tenant é recusado.
4. **Backfill**: depois da migration, zero linhas com `tenant_id` divergente do pai. Consulta de
   conferência rodada em dev **e** em prod antes de considerar a fatia fechada.
5. **Não-regressão de tela** (fatia 4): as telas de administração continuam listando o que
   listavam.
