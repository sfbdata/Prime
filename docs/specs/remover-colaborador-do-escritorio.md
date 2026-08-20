# Remover colaborador do escritório

> **Risco: ALTO** (identidade `User`/`Tenant`, e encosta em ponto eletrônico).
> Spec escrita em 2026-08-19, antes de qualquer linha de código, após revisão adversarial
> (`feature-review-agent`) das medições que a sustentam.

## 1. O problema

Hoje não existe forma de tirar uma pessoa do escritório. As duas ações que existem apenas
marcam o vínculo como inativo — a linha nunca some, e a pessoa continua listada:

- **Demitir** (`app_tenant_user_demitir`): marca `isActive = false` e grava `demitidoEm`.
  A pessoa migra para a seção recolhível "Colaboradores desligados".
- **Sair do escritório** (`escritorio_sair`): marca `isActive = false` sem `demitidoEm`.

O dono pediu outra coisa: **remover a pessoa dos colaboradores e desfazer o vínculo**. Não é
apagar a conta. A pessoa continua existindo, pode criar escritório, ser convidada para outro,
e ser convidada de volta para este.

## 2. Decisões do dono (2026-08-19)

| # | Decisão |
|---|---|
| D1 | A linha de `user_tenant` é **deletada** (hard delete). O que a pessoa gerou (ponto, tarefas, auditoria) permanece no banco. |
| D2 | Isso **substitui o "demitir"**. O conceito de demissão sai do sistema. |
| D3 | A saída por conta própria (`escritorio_sair`) passa a fazer **a mesma coisa**. |
| D4 | Saem junto com o vínculo: permissões item a item, pedidos de acesso pendentes, e as responsabilidades são transferidas a um substituto ou desatribuídas — **incluindo Kanban**, que o demitir de hoje não cobre. |
| D5 | `home_office_config` (liberação de bater ponto fora da geocerca) **entra na limpeza**. |
| D6 | Quem sai por conta própria **desatribui tudo**, sem substituto. |
| D7 | A trava real é **"não deixar o escritório sem administrador ativo"**, e ela vale **também para super-admin**. Não se depende de `tenant.criado_por`, que está vazio no escritório principal. |
| D8 | Os **3 vínculos de demitidos que existem em produção são apagados** na mesma entrega. Perde-se código de funcionário, data de admissão e data de desligamento dessas passagens; ponto e auditoria delas ficam. |

## 3. Comportamento

Uma ação, **"Remover do escritório"**, com duas portas de entrada. Ambas passam pelo mesmo
UseCase e fazem a mesma limpeza.

### 3.1 Porta do painel (admin remove outra pessoa)

Ocupa o lugar do bloco "Demitir" em `templates/tenant/edit_user_role.html.twig`, mantendo o
campo **opcional** de substituto.

### 3.2 Porta da saída própria (a pessoa sai sozinha)

`escritorio_sair` continua onde está, sem UI de substituto. Passa a chamar o mesmo UseCase com
`substituto = null` — ou seja, **desatribui** (D6).

### 3.3 Sequência, em uma transação

1. **Valida as travas** (§4). Falhou, nada é tocado.
2. **Passa o bastão** — com substituto, transfere; sem substituto, desatribui:
   - `Pasta.responsavel`, `Chamado.responsavel` (DQL, escopado por tenant)
   - `tarefa_responsaveis`, `evento_participante` (SQL, subselect no tenant da tarefa/evento)
   - `kanban_card_responsavel`, `kanban_board_participante` (SQL, subselect em `kanban_card.tenant_id` / `kanban_board.tenant_id`)
   - `kanban_board.criado_por`: vai para o substituto; sem substituto, para o executor; na
     porta da saída própria, para o administrador ativo de vínculo mais antigo
     (`user_tenant.created_at`, desempate pelo menor `id`).
3. **Corta o acesso** — apaga, sempre com `WHERE tenant` explícito (nenhuma dessas queries
   herda o `TenantFilter`):
   - `resource_access` (user + tenant)
   - `access_request` (user + tenant)
   - `home_office_config` (user + tenant) — D5
   - `notificacao` (usuario + tenant)
4. **Grava o registro de auditoria** da remoção **com o tenant vindo da rota** (§6.3).
5. **`$em->remove($vinculo)` + flush.**

### 3.4 Depois

A lista de colaboradores (`app_tenant_users`) passa a carregar **só vínculos ativos**, e a
seção "Colaboradores desligados" sai do template. A sessão da pessoa removida cai no request
seguinte — `TenantContextValidatorListener` já trata `$userTenant === null`.

Se ela for convidada de volta: `AceitarConviteEscritorioComContaUseCase` não encontra linha,
cria uma nova, e não há colisão com `uq_user_tenant`. Ela volta **sem** cargo, lotação, código
de funcionário, permissões pontuais ou liberação de geocerca.

## 4. Travas

| Trava | Situação |
|---|---|
| Não deixar o escritório **sem administrador ativo** — vale nas duas portas e **também para super-admin** | **nova** no painel; hoje só o "sair" checa |
| Não remover a si mesmo pela porta do painel (para isso existe o "sair") | já existe no demitir |
| Não remover o criador do escritório | já existe; mantida como cinto secundário, **sabidamente inerte** onde `criado_por` é nulo |
| Permissão `admin.users.manage` + token CSRF | já existe |
| Alvo precisa ter vínculo ativo com o escritório da **rota** (padrão B-route: lookup por repositório, nunca `getCurrentTenant()`) | já existe |
| Substituto validado **pelo vínculo ativo**, dentro do UseCase — não pelo `User::isActive` (flag global da conta) | **corrigido**; hoje o UseCase testa o flag errado |

## 5. O que sai e o que fica

**Sai** (com `WHERE tenant`): `user_tenant`, `resource_access`, `access_request`,
`home_office_config`, `notificacao`.

**Fica**: `registro_ponto`, `justificativa_ponto`, `ponto_lancamento_horas_pagas`,
`aceite_termo`, `audit_log`, `user_profiles`, a conta `User`, e `jornada_colaborador` — esta
última porque **não tem escritório** (é do usuário; ver §8).

**Consequência aceita:** se a pessoa voltar por convite, o ponto e as justificativas antigas
dela **naquele escritório** reaparecem, porque apontam para `user_id + tenant_id`.

## 6. Superfície de código

### 6.1 Nasce

- `App\Tenant\UseCase\RemoverColaboradorDoEscritorioUseCase` — nasce do `DemitirFuncionarioUseCase`
- `App\Tenant\DTO\RemoverColaboradorInput` — `executor`, `colaborador`, `tenant`, `?substituto`, `origem` (`painel` \| `saida`)
- Rota `app_tenant_user_remover` → `POST /tenant/{tenantId}/user/{userId}/remover`, CSRF `remover_<userId>`

### 6.2 Morre

`DemitirFuncionarioUseCase`, `DemitirFuncionarioInput`, a rota `app_tenant_user_demitir` e seu
método no `TenantController`, o bloco de demissão em `edit_user_role.html.twig`, a seção
"Colaboradores desligados" em `users.html.twig`, e `UserTenant::demitir()` / `::sair()`.

`SairDoEscritorioUseCase` passa a delegar ao UseCase novo com `origem = saida`.

**Superfície medida:** o conceito de demissão vive em **6 arquivos de produção e 8 de teste**.

### 6.3 Auditoria

O `AuditLogSubscriber` grava o `tenant_id` a partir da **sessão**, não da rota — no dev já
existem 3 registros de `UserTenant` com `tenant_id` NULL, invisíveis na trilha do escritório.
Por isso o UseCase grava um registro **explícito** da remoção com o tenant da rota. Se o
subscriber gravar um segundo com tenant nulo, ele fica fora da tela do escritório — aceito;
consertar o subscriber é frente própria.

O payload do registro carrega o nome completo da pessoa (PII) dentro de `audit_log.changes`.
É intencional: é o rastro de quem esteve ali.

### 6.4 Filtro da trilha de auditoria

`UserRepository::findAuditFilterOptions` lista só quem tem vínculo. Com o hard delete, a pessoa
removida sumiria do filtro e a trilha dela ficaria inalcançável pela tela. O método passa a
incluir também os `actor_user_id` distintos presentes no `audit_log` **daquele escritório** — a
consulta continua escopada por `tenant_id`, sob pena de vazar nome de gente de outro escritório
para dentro de um filtro.

### 6.5 Limpeza dos 3 legados (D8)

Migration que apaga os vínculos com `is_active = false`. São 3 em produção e 3 no dev. Já são
inúteis hoje: `app_tenant_user_edit_role` exige vínculo **ativo**, então o botão "Ver" da lista
de desligados dá 404. A limpeza fecha a regra única — *vínculo existe = colaborador* — e tira os
nomes-fantasma dos seletores de participante do Kanban, responsável de Tarefa e Agenda, que
fazem JOIN sem `isActive`.

### 6.6 O que acontece com as colunas do vínculo

- **`demitido_em` é dropada** na mesma migration da §6.5. Depois da §6.2 nada mais lê nem escreve
  esse campo, e as 3 linhas que o preenchiam deixam de existir por D8.
- **`is_active` permanece**, e passa a ser sempre `true`. Ela é lida em ~15 pontos do sistema
  (`existeVinculoAtivo`, `findActiveByUser`, os JOINs de `UserRepository`, `PermissionChecker`,
  `NotificacaoService`, `ChamadoType`, Djen). Removê-la é refatoração de escopo próprio e sem
  ganho aqui: com o hard delete, *existir* e *estar ativo* passam a ser a mesma coisa, e as
  consultas continuam corretas sem tocar em nenhuma delas.
- **`UserTenant::reativar()` e o ramo de reativação do aceite de convite ficam**, mesmo virando
  inalcançáveis depois da limpeza. São a rede caso alguma linha inativa apareça por caminho não
  previsto — deletar esse ramo mexeria no fluxo de convite sem necessidade.

## 7. Provas

Antes da implementação (o projeto manda escrever o teste primeiro):

1. **Unit do UseCase** — transferência com substituto; desatribuição sem substituto; deleção do
   vínculo; limpeza de cada uma das 5 tabelas; cada trava do §4, uma a uma.
2. **Isolamento entre escritórios** — adaptar `DemitirFuncionarioIsolamentoTest`: remover no
   escritório A não pode tocar em nada do B, incluindo Kanban.
3. **Funcional do controller** — permissão, CSRF, B-route, 404 para alvo sem vínculo.
4. **O teste que fecha o pedido do dono** — remover → convidar de volta → a pessoa entra **sem**
   `resource_access`, **sem** `home_office_config` e **sem** acesso aos boards que criou.
5. **Trava do último admin** — nas duas portas e com super-admin, provada reintroduzindo o
   defeito (remover o penúltimo admin funciona; o último é recusado).

## 8. Limitações conhecidas, declaradas e fora deste escopo

- **`jornada_colaborador` não tem escritório** (`OneToOne` com `User`, sem tenant). A pessoa leva
  a jornada configurada aqui para o próximo escritório, e o admin de lá pode alterá-la —
  mudando o cálculo do banco de horas do **histórico daqui**. Defeito pré-existente de ponto,
  com escopo próprio.
- **`AuditLogSubscriber` lê o tenant da sessão.** Contornado nesta frente (§6.3), não consertado.
- **`KanbanBoardRepository` não tem visão de admin** — o acesso é por criador ou participante.
  Tratamos a transferência do `criado_por` para o board não ficar órfão, mas a ausência de visão
  administrativa continua.

## 9. Medições que sustentam esta spec

| Fato | Onde | Quando |
|---|---|---|
| Nenhuma FK aponta para `user_tenant` | prod **e** dev `saas_ux` | 2026-08-19 |
| Prod: 14 vínculos — 11 ativos, 3 demitidos, 0 saídas voluntárias | prod (MCP somente leitura) | 2026-08-19 |
| Prod: tenant 1 tem `criado_por_id` **NULL**, 10 vínculos ativos e **2 admins** | prod | 2026-08-19 |
| `app/src/Ponto/` não tem nenhuma ocorrência de "demitid" | dev | 2026-08-19 |
| Admin de escritório **já não** emite folha de desligado (`existeVinculoAtivo` no PDF e no XLSX); só super-admin passa | dev | 2026-08-19 |
| `resource_access` e `access_request` têm **0 linhas** hoje — a limpeza é preventiva, não conserta dado existente | dev | 2026-08-19 |
| `PermissionChecker` barra por vínculo **antes** de olhar `resource_access` → não há vazamento imediato; o risco é só no re-convite | dev | 2026-08-19 |
| 5 dos 7 boards de Kanban do dev têm **zero participantes** → remover o criador esconderia o board de todo o escritório | dev | 2026-08-19 |

## 10. Defeito pré-existente encontrado no caminho

`users.html.twig` separa ativos de desligados por `demitidoEm`, e a coluna Status usa
`user.isActive` — o flag **global da conta**, não o do vínculo. Quem sai por conta própria
aparece hoje na tabela principal **com badge verde "Ativo"** e botão que dá 404. Em produção
está latente (0 saídas voluntárias). Esta frente mata o defeito de lado, ao passar a listar só
vínculos ativos e remover a seção de desligados.
