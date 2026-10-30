# Autorização — Estado Atual do Sistema

> Documento descritivo do modelo de autorização **como está implementado hoje**.
> Falhas são marcadas como falhas. Nada aqui é como "deveria ser".
> Cada afirmação técnica cita o arquivo de origem.
> **Leia este documento antes de mexer em qualquer coisa de permissão.**

---

## 1. Visão Geral

O sistema tem quatro camadas de autorização, todas implementadas via
`app/src/Service/PermissionChecker.php`:

| Camada | Mecanismo | Status |
|---|---|---|
| **Módulos** | `modules.{x}.view` no TenantRole | Ativo |
| **Admin** | `admin.{x}.{y}` no TenantRole | Ativo |
| **Recursos-tipo** | `resources.{x}.{y}` no TenantRole | `canActOnResource` é código morto; alguns códigos são checados direto por `hasPermission` (ex.: `resources.agenda.ver_prazos`) |
| **Recursos-item** | Tabela `resource_access` | Ativo |

As três primeiras convergem no mesmo mecanismo de armazenamento: o
`TenantRole` de um usuário acumula `TenantRolePermission`s, cada uma
apontando para um `code` na tabela `permission`. A leitura é sempre
um scan linear em `PermissionChecker::hasPermissionFromUserTenant()`
(`app/src/Service/PermissionChecker.php`, linhas ~95–103).

A camada Recursos-item é a única com tabela própria (`resource_access`),
independente do TenantRole.

---

## 2. Bypasses do PermissionChecker

Dois caminhos curto-circuitam qualquer checagem de permissão.
Ambos estão declarados como os primeiros passos em **todos** os
métodos públicos de `PermissionChecker.php`:

### 2a. ROLE_SUPER_ADMIN (global)

```php
// PermissionChecker.php — isGlobalSuperAdmin(), chamado em todo método público
if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
    return true;
}
```

Qualquer usuário com a role Symfony `ROLE_SUPER_ADMIN` passa em
qualquer checagem, independente de tenant ou permissão. É uma role
atribuída diretamente no `User` (campo `roles`, JSONB), fora do
sistema de `TenantRole`.

**Master = `ROLE_SUPER_ADMIN`: um primário e, se ele quiser, secundários** (spec
`docs/specs/perfis-1.2-masters.md`, que substitui a D2 "Master é único" de `perfis-master-1.0.md`). Na
interface o papel se chama "Master" (Painel do Master, selo MASTER). Os nomes dos papéis moram em
`App\Master\Service\RegraDosMasters`.

- **Master primário** = `ROLE_SUPER_ADMIN` + `ROLE_MASTER_PRIMARIO` (um só, o criador do BlueJus). O
  `ROLE_MASTER_PRIMARIO` só marca quem é o primário; sozinho não dá acesso a nada.
- **Master secundário** = `ROLE_SUPER_ADMIN` dado pelo primário na aba Permissões de `/master`
  (`PromoverMasterUseCase`): só um usuário secundário com vínculo ativo no escritório da sessão do primário.
  Tem o MESMO bypass total acima (falha conhecida, aceita pelo desenho 1.2). Só o primário promove e remove
  (`RemoverMasterUseCase`, que tira só o `ROLE_SUPER_ADMIN`); o primário nunca é removido pela tela.
  Promoção e remoção ficam na auditoria (`User` é `Auditavel`).

Por CLI, rodado pelo dono:

- `app:master:definir <email> [--confirmar]` (`app/src/Command/DefinirMasterCommand.php`): o do e-mail
  fica Master primário e TODOS os outros Masters (primário anterior e secundários) perdem os dois papéis.
  Sem `--confirmar` só mostra quem ganha e quem perde; com `--confirmar` aplica numa transação. É o
  caminho para TROCAR o Master primário.
- `app:create-super-admin` (`app/src/Command/CreateSuperAdminCommand.php`): só cria o primeiro Master, já
  primário, quando ainda não existe nenhum; havendo um, falha e aponta o `app:master:definir`.

Não é atribuída automaticamente no bootstrap de tenant.

**A conta do Master só o próprio Master mexe** (`RegraDosPrimarios::podeAlterarConta()`). Nenhum
outro usuário, nem o primário do escritório onde o Master tem vínculo (como sócio ou secundário), pode,
sobre um usuário Master:

- trocar o perfil no escritório (edit-role: o select só oferece o perfil atual);
- trocar a senha (edit-role);
- editar o nome (`edit-name`) ou os dados pessoais (`TenantUserProfileController`);
- removê-lo do escritório (`RemoverColaboradorDoEscritorioUseCase`);
- promovê-lo a primário ou rebaixá-lo pela tela de Perfis (`PromoverPrimarioSocioUseCase` /
  `RemoverPrimarioSocioUseCase`, que recebem o ator no Input). Na edição do primário o Master aparece
  sem lixeira para os outros e não entra no select de promover.

Vale também em escritório com `criado_por` nulo. **Não** é garantido o resto: os dados do VÍNCULO do
Master com o escritório (código do colaborador, cargo, lotação, data de admissão, ponto, jornada e
acessos item a item) continuam editáveis por quem administra usuários daquele escritório
(`admin.users.manage`), como os de qualquer colaborador. Além do `PermissionChecker`, essa role é verificada
em outros pontos:

- `app/src/Security/UserAuthenticator.php` (l. 72): usuário sem tenant
  ativo e com `ROLE_SUPER_ADMIN` é redirecionado para `admin_platform_dashboard`
- `app/src/EventListener/TenantContextValidatorListener.php` (l. 50):
  sem tenant context ativo, permite acesso sem validar tenant
- `app/config/packages/security.yaml` (l. 37): rota `/tenant/new`
  requer `ROLE_SUPER_ADMIN`

### 2b. TenantRole.isSystem() (por tenant)

```php
// PermissionChecker.php — segundo passo em todos os métodos
if ($userTenant?->getTenantRole()?->isSystem() === true) {
    return true;
}
```

Se o `TenantRole` do usuário no tenant atual tem `isSystem = true`,
o usuário passa em qualquer checagem **dentro desse tenant**. É o
equivalente a "administrador total do escritório".

**Onde esse role é criado:** `app/src/Service/TenantBootstrapService.php`,
método `findOrCreateAdminRole()` (~l. 140), executado no bootstrap de
cada novo tenant:

```php
$adminRole = new TenantRole();
$adminRole->setName('Administrador do Escritório');
$adminRole->setDescription('Perfil padrão com acesso total ao escritório. Não pode ser excluído.');
$adminRole->setIsSystem(true);
$this->entityManager->persist($adminRole);
$this->attachAllPermissions($adminRole); // recebe TODAS as permissões do catálogo
```

O role resultante recebe todas as permissões cadastradas via
`attachAllPermissions()`. Qualquer usuário vinculado a esse role
bypassa todas as verificações dentro do tenant.

O nome gravado ("Administrador do Escritório") não pode mudar: o `TenantBootstrapService` acha o
perfil pelo nome. Na tela ele aparece como **"Usuário Primário"** (só exibição), por um ponto único:
`TenantRole::getNomeExibido()` (`role.nomeExibido` no Twig), usado na lista e edição de perfis, no
select do edit-role, no select de convite e na lista de convites pendentes (`ConviteAdminOutput`), na
lista de colaboradores, no seletor de escritório e na
tela de seleção de escritório.

#### Primários: titular e sócios (spec perfis-master, D3/D4/D5)

- **Primário** = vínculo ativo, de usuário ativo, com o perfil `isSystem`. As caixas de permissão
  desse perfil aparecem marcadas e travadas: o bypass acima ignora as caixas (D5).
- **Titular** = `Tenant.criadoPor`, quem abriu a conta. **Sócio** = qualquer outro primário.
- **Só primário ou Master gere primários.** A regra mora num lugar só,
  `App\Tenant\Service\RegraDosPrimarios::podeGerirPrimarios()` / `garantirQuePodeGerirPrimarios()`,
  e vale em todas as portas que dão ou tiram o perfil de sistema:
  - Perfis (`TenantRoleController`): promover sócio (`PromoverPrimarioSocioUseCase`) e remover sócio
    (`RemoverPrimarioSocioUseCase`, que exige um perfil secundário de destino);
  - edit-role (`TenantController::editUserRole`): para quem não gere primários, o perfil de sistema
    não aparece no select e o POST forjado é recusado, tanto para promover quanto para rebaixar;
  - convite (`GerenciarConvitesController` + `CriarConviteEscritorioUseCase`): convidar com o perfil
    de sistema. No ACEITE (`AceitarConviteEscritorioComContaUseCase` / `SemContaUseCase`) a regra é
    revalidada (`garantirQueConviteDePrimarioSegueValido()`): se quem convidou já não pode gerir
    primários naquele escritório (rebaixado, desligado ou com conta desativada), o aceite é recusado
    e nenhum vínculo é criado;
  - remover colaborador (`RemoverColaboradorDoEscritorioUseCase`, porta do painel): tirar um
    primário (sócio) do escritório também é gerir primários;
  - senha (`editUserRole`, campo `newPassword`): senha de sócio só por primário ou Master; senha do
    titular só pelo próprio titular ou pelo Master (`RegraDosPrimarios::podeTrocarSenhaDe()`).

  Uma permissão de secundário (`admin.roles.manage`, `admin.users.manage`, `admin.users.invite`) NÃO
  basta: com ela um secundário se promoveria, ou promoveria um cúmplice, ao acesso total.
- **Guarda do titular e do último primário** (`RegraDosPrimarios::garantirQuePodeDeixarDeSerPrimario()`):
  o titular nunca deixa de ser primário e o escritório nunca fica sem primário ativo. Vale na remoção
  de sócio, no edit-role e na remoção de colaborador. **Conta desativada** (`User.isActive=false`) não
  é contada como primário: a trava do último primário não se aplica a ela, que pode ser rebaixada ou
  removida por primário/Master. O titular segue intocável mesmo com a conta desativada.
- **O titular não sai do escritório** pela porta "Sair do escritório" (`OrigemRemocao::Saida` no
  `RemoverColaboradorDoEscritorioUseCase`): "O titular do escritório não pode sair. Fale com o suporte
  do BlueJus." (D4: o titular nunca é rebaixado nem removido). A barra superior nem mostra o botão
  "Sair deste escritório" para o titular do escritório atual (`base.html.twig`).
- **Hierarquia de perfis** (spec `docs/specs/perfis-1.2-permissoes-e-hierarquia.md`, parte C): a regra mora
  num lugar só, `App\Tenant\Service\HierarquiaDePerfis`, que usa a `RegraDosMasters` (nível 3, Master) e a
  `RegraDosPrimarios::podeGerirPrimarios()` (nível 2, primário ativo); os demais são nível 1. O perfil de
  sistema é nível 2; os secundários, nível 1. Só mexe em perfil quem tem nível MAIOR que o dele, no servidor:
  - lista (`TenantRoleController::index`): primário, Master ou `admin.roles.manage`; mostra só perfis de nível
    até o do ator (o secundário não vê o perfil de sistema). Quem não tem acesso recebe a tela "Acesso
    restrito" (403) com "Pedir acesso", que avisa os primários ativos pela Central (tipo
    `perfis_pedido_acesso`), um pedido por pessoa por dia (o teto é o próprio aviso: o link dele é a lista de
    Perfis com `?pedido=<id de quem pediu>`). Vale também no GET de `new` e `edit`. Primário de outro escritório
    não recebe;
  - criar (`new`): só nível >= 2 (o perfil novo é sempre secundário);
  - editar (`edit`) e excluir (`delete`): só perfil de nível abaixo do ator. Na prática, o secundário com
    `admin.roles.manage` fica só com a leitura da lista de secundários: não edita nem exclui nenhum perfil,
    nem o próprio. A tela do perfil de sistema continua sendo a gestão de primários (`podeGerirPrimarios`,
    sem mudança). Recusa: 403 com "Seu perfil não pode editar um perfil do mesmo nível ou acima.";
  - **subconjunto** no edit-role (`TenantController::editUserRole`, `admin.users.manage`) e no convite
    (`GerenciarConvitesController` + `CriarConviteEscritorioUseCase`, `admin.users.invite`): quem não é
    primário nem Master não troca o próprio perfil e só atribui perfil cujas permissões estão TODAS no perfil
    dele (`HierarquiaDePerfis::podeAtribuirPerfil`). Sem vínculo ativo ou com perfil nulo, só o perfil vazio
    cabe. Manter o perfil atual do alvo não é atribuir. No edit-role, também não mexe em quem tem o perfil atual
    FORA do subconjunto dele (`podeMexerNoAlvo`): não troca o perfil (nem rebaixa, nem deixa sem perfil) e não troca
    a senha ("Seu perfil não pode mexer em quem tem permissões que ele não tem."); alvo sem perfil passa. A senha
    soma essa barreira à `RegraDosPrimarios::podeTrocarSenhaDe()`, sem mudá-la (`HierarquiaDePerfis::podeTrocarSenha`).
    A mesma barreira vale ao remover do escritório (`TenantController::removerColaborador`, rota
    `app_tenant_user_remover`): flash com a mensagem e nada muda.
  - **senha de quem usa outro escritório** (C8, `HierarquiaDePerfis::recusaDaSenha`): a senha é da CONTA (`User`) e
    vale em todos os escritórios, mas o edit-role só olha o vínculo do escritório da URL. Quem NÃO é Master
    (secundário ou primário) não troca, pelo edit-role, a senha de quem tem vínculo em outro escritório
    (qualquer perfil, com o escritório no ar ou não): "Esta pessoa também usa outro escritório. A senha só pode
    ser trocada por ela, em Meu Perfil, ou pelo suporte." Sem isso, um gestor de A trocaria a senha de quem é
    primário em B e entraria em B. O vínculo suspenso ou em análise também bloqueia (pode voltar a valer; o removido some da tabela); a própria pessoa e o Master
    passam. Primário e Master atribuem
    qualquer secundário e trocam senhas como antes; o perfil
    de sistema segue a regra dos primários acima. O select esconde o que não pode e o POST forjado é recusado.
    **Não cobre:** o aceite do convite não revalida o subconjunto (só a regra dos primários); o convite do
    Trabalho Externo é outro fluxo.
- **Excluir perfil secundário em uso** (`TenantRoleController::delete`) solta antes todos os vínculos
  (ativos e inativos) que o usam: eles ficam com perfil nulo, e **perfil nulo nega toda permissão de perfil** no
  `PermissionChecker` (módulos, admin e `resources.*` por tipo) até alguém atribuir outro. Só
  continuam valendo os acessos item a item (`ResourceAccess`) já concedidos à pessoa. Perfil `isSystem` não pode ser excluído.

---

## 3. Camada Módulos

### Como funciona

```php
// PermissionChecker.php — canAccessModule()
return $this->hasPermissionFromUserTenant(
    $userTenant,
    sprintf('modules.%s.view', $module)
);
```

`canAccessModule(User, ?Tenant, string $module)` constrói a string
`modules.{module}.view` e verifica se o TenantRole do usuário tem
essa permissão. Retorna `bool`.

### Onde é chamado

Em **listagens e ações de criação** de cada controller de módulo.
Nunca em ações sobre itens específicos (show, edit, delete) — ver
Falha F1.

Exemplos confirmados:
- `ClienteController::index()` e `::newPF()` — `canAccessModule('clientes')`
  (`app/src/Cliente/Controller/ClienteController.php`, linhas ~115 e ~145)
- `ExpedienteController::assertAccess()` — `canAccessModule('expediente')`
  (`app/src/Expediente/Controller/ExpedienteController.php`, l. 262)

Exceção registrada (spec `processo-cadastro-pela-pasta`, a4, 21/10/2026): o "Buscar no CNJ"
(`ProcessoController::datajudSearch`, `POST /processos/api/search`) exige o módulo `processos`, **ou** chega
encaminhado pela pasta: `POST /pasta/{id}/processo/buscar-cnj` exige EDIT na pasta (`canAccessResource`, com o
cadeado) e faz `forward` com o atributo `_cnj_pela_pasta`, que só nasce no servidor. Quem pode vincular processo
na pasta consulta o CNJ por ela, mesmo sem o módulo; fora da pasta, nada muda.

### Twig — função de template

`can_access_module('chave')` em `app/templates/_sidebar.html.twig`.
Wrapper Twig do mesmo `canAccessModule`.

### Módulos cadastrados no catálogo

Fonte: `app/src/DataFixtures/PermissionFixture.php` e
`app/migrations/Version20260401130000.php`.

| Chave | Permissão | Guard na sidebar | Guard no controller | Situação |
|---|---|---|---|---|
| `expediente` | `modules.expediente.view` | **nenhum** — item aparece para todos no menu | ✅ `assertAccess()` bloqueia sem permissão (l. 262) | Ativo — ver nota abaixo |
| `clientes` | `modules.clientes.view` | `can_access_module('clientes')` | ✅ `index()`/`new()` | Ativo — label na sidebar é "Financeiro" (inconsistência) |
| `processos` | `modules.processos.view` | `can_access_module('processos')` | ✅ | Ativo |
| `tarefas` | `modules.tarefas.view` | `can_access_module('tarefas')` | ✅ | Ativo |
| `agenda` | `modules.agenda.view` | `can_access_module('agenda')` | ✅ | Ativo |
| `servicedesk` | `modules.servicedesk.view` | `can_access_module('servicedesk')` | ✅ | Ativo |
| `ponto` | `modules.ponto.view` | `can_access_module('ponto')` | ✅ | Ativo |
| `kanban` | `modules.kanban.view` | `can_access_module('kanban')` | ✅ | Ativo |
| `pastas` | `modules.pastas.view` | **nenhum** — sem item de menu próprio | não verificado | "Expediente" e "Demandas" são as entradas do domínio Pasta |
| `financeiro` | `modules.financeiro.view` | **nenhum** | não verificado | Marcado como "futuro" na fixture; sem tela |
| `bi` | `modules.bi.view` | **nenhum** | não verificado | Marcado como "futuro" na fixture; sem tela |
| `calculo` | `modules.calculo.view` | `can_access_module('calculo')` | `assertAcesso()` em todas as rotas `calculo_*` | Ativo (Cálculo de atualização monetária); migration concede aos perfis que já têm `pastas` |

**Nota sobre Expediente:** o item aparece sem guard no menu Twig (sidebar),
então todos os usuários autenticados o veem. Porém o controller
`ExpedienteController::assertAccess()` (l. 262) ainda chama
`canAccessModule('expediente')` e lança `AccessDeniedException` se a
permissão não estiver no perfil. O acesso funciona na prática porque
a permissão `modules.expediente.view` foi concedida manualmente a
todos os perfis via painel como contorno do Bug B — o código não foi
alterado, o cadastro de dados foi. O guard está ativo.

**Nota sobre Demandas:** `DemandasController::index()`
(`app/src/Pasta/Controller/DemandasController.php`, 39 linhas no total)
não tem nenhum guard de permissão — só `$this->getUser()`. A rota
`demandas_index` é livre para qualquer usuário autenticado, sem
verificação de módulo. Não há guard na sidebar tampouco. Intencional
ou esquecimento — não está documentado.

---

## 4. Camada Admin

### Como funciona

```php
// PermissionChecker.php — canAdminister()
return $this->hasPermissionFromUserTenant($userTenant, $permission);
// $permission já vem como string completa: 'admin.roles.manage'
```

`canAdminister(User, ?Tenant, string $permission)` passa o code
diretamente para `hasPermissionFromUserTenant`, sem construção de
string. A lógica dos 5 passos é **byte-a-byte idêntica** à de
`canAccessModule`. A única diferença está em como o permission code
é montado: módulos usam `sprintf('modules.%s.view', $module)`, admin
recebe o code completo.

Equivalências exatas:
```php
canAdminister($u, $t, 'admin.roles.manage')  == hasPermission($u, $t, 'admin.roles.manage')
canAccessModule($u, $t, 'clientes')          == hasPermission($u, $t, 'modules.clientes.view')
```

### Permissões admin e onde são checadas

| Permissão | Controller | Tipo de proteção | Observação |
|---|---|---|---|
| `admin.roles.manage` | `TenantRoleController` via `HierarquiaDePerfis::podeVerALista()` | Só a leitura da lista de secundários | Criar, editar e excluir exigem nível acima do perfil (primário ou Master); ver §2b, Hierarquia de perfis |
| `admin.users.manage` | `TenantController::listUsers()` (l. 329) + `TenantUserProfileController` (l. 54) + ações em `TenantController` | Listagem + ações de gestão | Mistura "ver lista" e "executar ação" numa permissão só |
| `admin.users.invite` | `GerenciarConvitesController::assertAccess()` | Listagem + CRUD de convites | — |
| `admin.access_requests.approve` | `AccessRequestController` (l. 50) | Listagem + aprovação | — |
| `admin.tenant.settings.manage` | `TenantController` (l. 80, 174, 200) + `FeriadoController` (l. 39) | Cobre tenant index, show, edit e feriados | Uma permissão protege 4 telas e 2 itens de sidebar distintos |
| `admin.servicedesk.manage` | `ServiceDeskController` (l. 74, 209, 246, 286, 333) | Dashboard + 4 ações | — |
| `admin.ponto.manage` | `TenantController` (l. 1516, 1570, 1636) | Criar/editar/excluir sede — sem listagem protegida | — |
| `admin.audit.view` | `AuditLogController` (l. 38) | Listagem e desfazer | Desfazer Sede exige também `admin.ponto.manage`; Cargo e Lotação, `admin.tenant.settings.manage`; Chamado, anexo e interação, `admin.servicedesk.manage`; Evento, `admin.users.manage` ou ser o criador; LegendaCor não se desfaz (achado de auditoria interna F19). Meta e pasta pedem a guarda da tela (`GuardaDaTelaNoDesfazer`: na meta, módulo `tarefas`, `AcessoAMeta` e a barreira do cadeado para qualquer campo, e por campo o que a rota pede (prazo pelo criador; conclusão e reabertura por quem cuida e com edição da pasta; etapa por quem cuida; título com edição da pasta); `AcessoAPasta::podeEditar` na pasta-mãe, e a observação dos Detalhes também pelo `EditarObservacaoDetalhesUseCase::podeEditar`; mensagem da pasta: ver a pasta, cadeado em `Editar` e `EditarMensagemPastaUseCase::podeEditar`); TarefaMensagem e Notificacao não se desfazem |
| `admin.tarefas.manage` | **NENHUM** | — | **Permissão fantasma** — existe no catálogo e na sidebar mas nenhum controller a verifica |

### Seção admin da sidebar

`app/templates/_sidebar.html.twig` declara a seção admin com separador
visual e usa `can_administer('admin.*.*')` em vez de `can_access_module`.
A visibilidade da seção inteira é o OR de todas as permissões admin
(linhas ~65–75 do arquivo). Dois itens compartilham a mesma permissão
`admin.tenant.settings.manage` (Meu Escritório e Feriados e Folgas).

---

## 5. Camada Recursos

### 5a. Recursos-tipo (código morto)

```php
// PermissionChecker.php — canActOnResource()
return $this->hasPermissionFromUserTenant(
    $userTenant,
    sprintf('resources.%s.%s', $resourceType, $action)
);
```

O método `canActOnResource(User, ?Tenant, string $resourceType, string $action)`
existe em `PermissionChecker.php` mas **não é chamado por nenhum
controller ou serviço** do projeto. É código morto.

As permissões `resources.{tipo}.{ação}` no TenantRole existem no
banco (fixture e migration), aparecem no painel de perfis e podem ser
atribuídas — mas nunca são testadas diretamente. Funcionam apenas como
**fallback interno** de `canAccessResource` (item 5b abaixo).

### 5b. Recursos-item (ativo)

```php
// PermissionChecker.php — canAccessResource()
// Passo 4 — item específico tem prioridade
$resourceAccess = $this->resourceAccessRepository
    ->findForUserAndResource($user, $resourceType, $resourceId);
if ($resourceAccess !== null && $resourceAccess->allows($action)) {
    return true;
}
// Passo 5 — fallback: permissão de tipo no TenantRole
return $this->hasPermissionFromUserTenant(
    $userTenant,
    sprintf('resources.%s.%s', $resourceType, $action)
);
```

`canAccessResource(User, ?Tenant, string $resourceType, int $resourceId, string $action)`
verifica primeiro se existe um `ResourceAccess` para aquele item
específico; se não, cai para a permissão de tipo no TenantRole.

**51 chamadas reais** no código, todas via
`ResourceAccessTrait::denyResourceAccessUnlessGranted()`
(`app/src/Controller/Trait/ResourceAccessTrait.php`), sempre em ações
sobre item específico (show, edit, delete, upload, download).

### Cobertura real por tipo de recurso

| Recurso | Catálogo | Código (`canAccessResource`) |
|---|---|---|
| `pasta` | ✅ view/edit/delete | ✅ 20+ chamadas em `PastaController`, `PastaSecaoController`, `PeticionarController` |
| `cliente` | ✅ view/edit/delete | ✅ 8+ chamadas em `ClienteController` |
| `processo` | ✅ view/edit/delete | ✅ show/edit/delete em `ProcessoController` (l. 168/139/219, via `AccessRequest::RESOURCE_PROCESSO`) |

Fora desses três (e das capacidades de cobrança), só a Agenda tem uma permissão `resources.*`:
`resources.agenda.ver_prazos` ("Ver prazos na agenda", 20/10/2026), checada direto por
`PermissionChecker::hasPermission` em `App\Agenda\Service\PermissoesDaAgenda`. Ela decide se os prazos e
audiências das pastas aparecem na Agenda (grade, lembretes, push e "Avisar no Chat"); o cadeado da pasta vale
por cima. Nasceu concedida a todo perfil com `modules.pastas.view` e aos perfis de sistema
(`Version20261020134100`). Ponto, kanban, tarefas, servicedesk e expediente não têm `resources.*` no catálogo.

### Entidade ResourceAccess

**Arquivo:** `app/src/Entity/Permission/ResourceAccess.php`

Campos: `user`, `resourceType`, `resourceId`, `canView`, `canEdit`,
`canDelete`, `grantedAt`, `grantedBy`.

Constraint único em `(user_id, resource_type, resource_id)` — um
registro por usuário+item. Representa acesso a **um item específico**,
diferente de `resources.cliente.view` no TenantRole (que cobriria
todos os clientes de uma vez).

---

## 6. Fluxo de Solicitação de Acesso por Item

### Entidade AccessRequest

**Arquivo:** `app/src/Entity/Permission/AccessRequest.php`

Campos: `user`, `resourceType`, `resourceId`, `action`, `status`
(`pending`/`approved`/`denied`), `requestedAt`, `description`,
`decidedAt`, `decidedBy`.

Unicidade de pendentes via partial index no banco (`WHERE status = 'pending'`),
não via `UniqueConstraint` do Doctrine. Criado em
`app/migrations/Version20260331120000.php`.

### Fluxo completo

```
1. Usuário acessa /pasta/42 (GET)

2. PastaController::show() chama:
   denyResourceAccessUnlessGranted(permissionChecker, tenant,
     'pasta', 42, 'view', 'pasta_index', 'NUP da Pasta')
   (app/src/Controller/Trait/ResourceAccessTrait.php)

3. canAccessResource() retorna false

4. Trait redireciona para:
   /pasta/?requestAccess=1&resourceType=pasta&resourceId=42
          &action=view&resourceLabel=NUP+da+Pasta

5. Template pasta/index.html.twig inclui:
   {{ include('_partials/modal_access_request.html.twig') }}
   JS detecta query param requestAccess=1 e abre o modal.

6. Usuário preenche justificativa e submete.
   AJAX POST /access-requests/submit
   AccessRequestController::submit() valida, verifica duplicata
   pendente, cria AccessRequest(status='pending').

7. Admin vê em /access-requests — painel lista pendentes do tenant
   via AccessRequestRepository::findPendingByTenant().

8. Admin clica "Aprovar". Modal exibe checkboxes canView/canEdit/canDelete.
   POST /access-requests/{id}/approve
   AccessRequestController::approve() busca ou cria ResourceAccess,
   atualiza os três booleans, registra grantedBy + decidedAt,
   marca AccessRequest como 'approved'.

9. Na próxima tentativa, canAccessResource() encontra o ResourceAccess
   e allows('view') retorna true. Acesso concedido.
```

---

## 7. Modelo decidido: módulo e recurso são eixos PARALELOS

> **Decisão do dono (2026-06-30, achado M6 da auditoria pós-remediação).** Antes
> este documento registrava a *intenção* de um modelo **sequencial** (módulo como
> pré-requisito do recurso). Essa intenção foi **revista**: o modelo é, por
> decisão, **paralelo** — e o código já o implementa.

Módulo e recurso-item são **dois eixos independentes**, cada um com seu papel:

| Eixo | Papel | Onde é checado |
|---|---|---|
| **Módulo** (`canAccessModule`) | **Descoberta**: quem vê a listagem e cria itens | index (listagem) e new (criação) |
| **Recurso-item** (`canAccessResource`) | **Autorização do item**: quem abre/edita/exclui aquele item específico | show / edit / delete e demais ações por id |

| Ação | O que é checado |
|---|---|
| index (listagem) | apenas `canAccessModule` |
| new (criação) | apenas `canAccessModule` |
| show (item) | apenas `canAccessResource` |
| edit (item) | apenas `canAccessResource` |
| delete (item) | apenas `canAccessResource` |

**Não há dupla checagem, e isso é intencional.** Um `ResourceAccess` é concedido
**somente** por um admin (`admin.access_requests.approve`) e **somente** sobre um
recurso do próprio tenant (validado em `AccessRequestController::submit`/`approve`
via `recursoPertenceAoTenant`; `ResourceAccess` é `TenantAware`). Logo, **o grant
explícito JÁ é a autorização daquele item** — não faz sentido exigir também o
módulo, que governa apenas a descoberta/listagem. O caso de uso suportado é
"compartilhar um item isolado com quem não tem o módulo inteiro" (ex.: colaborador
que deve ver só uma pasta). Ver Falha F1 (rebaixada a comportamento aceito).

### Pasta excluída (lápide): somente leitura por estado

Independente das camadas acima, `PastaSomenteLeituraListener`
(`app/src/Pasta/EventListener/`, em `kernel.controller_arguments`) recusa toda
requisição não-segura (POST/PUT/PATCH/DELETE) sobre pasta com `excluida_em`
preenchido: 403 JSON (`status: erro`) em XHR, redirect para `pasta_show` com flash
nos demais. Ele acha a pasta nos argumentos da action (`Pasta` ou entidade com
`getPasta()`) ou, quando a rota recebe só um `int`, pelo atributo declarado na
action — `#[PastaPelaFilha(entidade, argumento)]` para id de filha (documento,
seção) e `#[PastaPorId(argumento)]` para id da própria pasta
(`expediente_pasta_marcadores`, `tarefa_criar_para_pasta`); nesses dois casos a
busca é por id **e** tenant da sessão, e pasta de outro escritório não recebe a
recusa (a action responde o próprio 404). Exceções, com motivo no listener:
`ROTAS_LIBERADAS` (`pasta_restaurar`; `pasta_documentos_zip`, leitura por POST) e
`ROTAS_SEM_PASTA_EXISTENTE` (`pasta_new`). O alcance é travado por
`app/tests/Arquitetura/PastaSomenteLeituraRotasArquiteturaTest.php`, que percorre
o router e falha se uma rota de escrita que envolva pasta (nome `pasta_*`,
`/pasta/` no path, variável `pastaId`/`pasta_id`/`pasta`, ou action que recebe a
pasta ou filha) não for alcançada nem for exceção. Rota com o id da pasta só no
corpo da requisição não é vista por nenhum dos dois.

O desfazer da auditoria (`audit_log_desfazer`) não recebe a pasta nos argumentos, e o
listener não o vê. Quem recusa ali é a `App\Auditoria\Service\EstadoDaTelaNoDesfazer`
(spec `docs/specs/seguranca-d-desfazer-estado.md`), com a mesma frase
(`PastaSomenteLeituraListener::MENSAGEM`), para a pasta, as filhas, a meta e a mensagem
da pasta excluída. O mesmo serviço aplica as demais regras de estado da tela (registro
que mudou depois do log, meta que seguiu adiante, data de conclusão, etapa, nome montado
pela carteira, situação da pasta). A recusa é aviso de erro e redirect, não 403.

### Cadeado da pasta: teto por pasta (spec `docs/specs/pasta-cadeado.md`)

O cadeado é uma **restrição** (decisão D3, 20/10/2026): ele tira acesso de quem teria pelas camadas
acima e nunca dá acesso a ninguém. O ponto único é `App\Pasta\Service\AcessoAPasta`:

- `nivel(User, Pasta)` devolve `Oculto`, `Visualizar` ou `Editar` (`NivelNoCadeado`): Master ou
  primário ativo do escritório da pasta → `Editar`; sem vínculo ativo → `Oculto`; pasta sem cadeado
  → `Editar`; cadeado `Todos` → `Oculto`; `PorPessoa` → o nível gravado da pessoa, ou `Oculto` (quem
  não está na lista, inclusive quem entrar depois);
- `podeVer()`/`podeEditar()` são o `canAccessResource` completo (`view`/`edit`) da pasta, com o teto
  dentro: vê = não `Oculto` + permissão de ver a pasta;
- para listas e buscas: `niveisEmLote()` (uma consulta para N pastas), `restringirAoVisivel()` (só
  as visíveis) e `criterioDeVisivel()` (o critério em DQL, para combinar: a pasta oculta continua na
  busca da Lista, mas só casa por número e cliente).

**Quem configura** (abre, altera, remove o cadeado e decide os pedidos): o Master e o primário com
vínculo ativo, pelo mesmo critério de `RegraDosPrimarios::podeGerirPrimarios()`. Quem configura nunca
é restringido. O antigo `PermissionChecker::isAdministradorDoEscritorio()` saiu: a Lista do
Expediente pergunta ao `AcessoAPasta::podeConfigurar()`.

**Onde o teto vale:**

1. `PermissionChecker::canAccessResource()` com `resourceType = 'pasta'`: **depois** dos bypasses
   (2a e 2b, que já são `Editar` no cadeado) e **antes** do `ResourceAccess` e do fallback por tipo
   (5b). `view` exige pelo menos `Visualizar`; `edit` e `delete` exigem `Editar`. Por isso um
   `AccessRequest` aprovado (seção 6) nunca fura o cadeado. Para o pedido de acesso há
   `canAccessResourceIgnoringPastaLock()`: a permissão sem o teto, só para saber se a pessoa veria a
   pasta livre (quem não veria nem livre não tem o que pedir).
2. `BarreiraDoCadeadoListener` (`kernel.controller_arguments`), em TODA rota de pasta, de leitura ou
   escrita: `Oculto` recebe 403 em tudo menos `pasta_show` (que desenha a tela "Pasta trancada") e o
   pedido de acesso; `Visualizar` recebe 403 nos métodos não seguros, menos as preferências pessoais
   e as leituras por POST (`ROTAS_DO_VISUALIZAR`, com o motivo de cada uma). A pasta sai do mesmo
   resolvedor da lápide e da aba oculta, `App\Pasta\Service\PastaDaRota` (argumento `Pasta`, filha
   com `getPasta()`, neta pela meta, `#[PastaPelaFilha]`, `#[PastaPorId]`; entrega também a filha).
   Pasta de outro escritório passa reto: a action responde o 404 dela. O alcance é travado por
   `app/tests/Arquitetura/BarreiraDoCadeadoRotasArquiteturaTest.php`, que olha todas as rotas de
   pasta (de qualquer método).
3. Fora da pasta (spec §5):
   - a Lista, a busca, o CSV e a janela do cliente mostram da pasta oculta só o número e o cliente,
     sem prioridade nem situação; ela casa na busca só por número e cliente, e fica FORA do resultado
     com qualquer filtro de atributo (responsável, situação, prioridade, criador, datas, painéis do
     Expediente, ação, marcador), também nas contagens do trilho (D1). A busca de pastas do agente de
     IA segue a mesma regra (D2);
   - o processo cujas pastas vinculadas são TODAS ocultas para a pessoa não abre (403 no
     `processo_show` e recusa no `canAccessResource` de `processo`, o que fecha também as notas
     técnicas) e some da lista de processos; com pelo menos uma pasta visível, abre, e a pasta oculta
     aparece nele só com o número, o cliente e o cadeado (K3);
   - as Minhas Metas (lista, abas, KPIs, trilho), as metas da equipe e a Agenda (prazos e audiências
     da pasta) não mostram nem contam o que é dela; o Push global esconde o processo cujas pastas são
     todas ocultas (a regra do processo); os avisos da meta não vão para quem está oculto, e o sino e
     "Ver todas" não mostram nem contam aviso ligado a pasta oculta, mesmo antigo; qualquer cadeado
     desliga o link externo do Push (D3);
   - a auditoria do escritório mostra sem o conteúdo e sem "desfazer" as linhas da pasta, das
     entidades dela (`App\Pasta\Entity\*`), da meta, da resposta da meta e das análises da BlueJus IA
     da pasta (D4);
   - a imagem embutida em peça dela é recusada (D5); pasta com cadeado sai do sync do Drive (D6);
   - na Cobrança, a busca de pasta para judicializar só oferece pasta que a pessoa edita pelo cadeado,
     e vincular um caso a uma pasta em que ela não edita é recusado.

**Precedência com o "ocultar aba"** (#68): são dois eixos e os dois só subtraem. O cadeado é o teto da
pasta inteira; a aba oculta é **organização** e tira abas dentro do que o cadeado permite. Com
`Oculto`, a aba não importa (a pasta toda está fechada); com `Visualizar`, as abas visíveis ficam em
leitura; com `Editar`, vale só o que a aba oculta tirar. Quem configura os dois é o mesmo grupo.

**Ainda não cobre:** na auditoria, `CasoCobranca`, `NotaTecnica` e `Notificacao` (que citam pasta mas são
da Cobrança, do processo e da pessoa); a janela do cliente (`ClienteResumoController`) segue escondendo
inteira a pasta que a pessoa não pode abrir.

---

## 8. Falhas Conhecidas

### F1 — show/edit/delete não verificam o módulo (COMPORTAMENTO ACEITO, não é falha)

> **Status: aceito por design** (decisão do dono, 2026-06-30 — achado M6 da
> auditoria pós-remediação). Antes classificado "crítico"; **rebaixado** após a
> decisão do modelo paralelo (seção 7). Mantido aqui para registro técnico.

**Afeta: Cliente, Pasta e Processo (os recursos com `canAccessResource` ativo).**

`ClienteController::show()` (l. 204), `::edit()` (l. 229), `::delete()` (l. 272);
`PastaController::show()` (l. 185), `::editar()` (l. 824), `::delete()` (l. 952);
`ProcessoController::show()` (l. 168), `::edit()` (l. 139), `::delete()` (l. 219)
chamam apenas `canAccessResource`, sem chamar `canAccessModule`.

Efeito: um usuário com `ResourceAccess` para `pasta#42` mas sem
`modules.pastas.view` consegue acessar `/pasta/42` diretamente pela URL. Ele não
vê a listagem (que bloqueia), mas acessa o item.

**Por que isso é aceito e não um vazamento:** o `ResourceAccess` só existe porque
um **admin o concedeu** (`AccessRequestController::approve`), e **só** sobre um
recurso do próprio tenant (`recursoPertenceAoTenant`; `ResourceAccess` é
`TenantAware`). O acesso é exatamente o que o admin autorizou para aquele item — o
módulo governa só a descoberta/listagem, não a autorização do item concedido.
Cross-tenant continua fechado pelo `TenantFilter` (Cliente/Pasta/Processo são
TenantAware → `find()` por id de outro tenant → 404). Ver seção 7.

### F2 — canActOnResource é código morto

`PermissionChecker::canActOnResource()` existe e constrói
`resources.{tipo}.{ação}`, mas nenhum controller ou serviço o chama.
As permissões de tipo no TenantRole só funcionam como fallback interno
de `canAccessResource`. O painel de perfis permite atribuí-las a roles,
mas atribuir `resources.pasta.view` a um role não garante acesso a
nenhuma pasta específica — é apenas o fallback genérico.

### F3 — resources.processo.* fantasma → CORRIGIDO (Processo agora é wired)

> **Desatualizado.** Quando este doc foi escrito, não havia chamada de
> `canAccessResource(..., 'processo', ...)`. **Hoje há:** `ProcessoController`
> show/edit/delete (l. 168/139/219) usam `canAccessResource` com
> `AccessRequest::RESOURCE_PROCESSO` (`= 'processo'`). Logo o item-level
> (`ResourceAccess` de processo) funciona, e a permissão de tipo
> `resources.processo.*` no TenantRole passa a ter efeito como **fallback** de
> `canAccessResource` (passo 5). Processo deixou de ser fantasma; equipara-se a
> Cliente/Pasta.

### F4 — admin.tarefas.manage é permissão fantasma

`admin.tarefas.manage` está no catálogo (`PermissionFixture.php`) e
na condição de visibilidade da seção admin da sidebar
(`_sidebar.html.twig`, linhas ~65–75). Nenhum controller verifica
essa permissão. Atribuí-la não libera nenhuma funcionalidade.

### F5 — admin e módulo são tecnicamente a mesma coisa

`canAdminister` e `canAccessModule` têm lógica interna idêntica.
A diferença está apenas no como o permission code é construído
(sprintf com prefixo vs string direta). Não há separação de mecanismo,
entidade ou tabela. A distinção existe só como convenção de nomenclatura
e prefixo de string.

### F6 — Divergência fixture vs migration

`PermissionFixture.php` inclui `modules.expediente.view` e
`modules.kanban.view`, mas `Version20260401130000.php` não os inclui.
A migration inclui `modules.precadastros.view`, que não está na fixture.
Em produção (migration) e em desenvolvimento (fixtures), o catálogo
é diferente.

### F7 — Label "Financeiro" com chave `clientes`

Na sidebar, o item "Financeiro" usa `can_access_module('clientes')` e
aponta para `cliente_index`. O label exibido é "Financeiro" mas a
chave de permissão e a rota são do módulo `clientes`.

### L1. Posição da batida informada pelo navegador (limitação conhecida)

A numeração desta seção é do documento; o achado citado é o da auditoria interna.

Achado de auditoria interna F17: a cerca do ponto decide pela posição informada pelo navegador; o
servidor não confirma a posição; alcance limitado à batida do próprio funcionário; mitigação futura
possível: IP da batida (exige migration e decisão do dono). Spec `docs/specs/seguranca-b-ponto.md` §1.4.

---

## 9. Onde Vive Cada Coisa

| Camada | Método PHP | Tabela(s) | Arquivo principal |
|---|---|---|---|
| Módulos | `canAccessModule()` | `permission` (`modules.*`), `tenant_role_permission`, `tenant_role` | `app/src/Service/PermissionChecker.php` |
| Admin | `canAdminister()` | `permission` (`admin.*`), `tenant_role_permission`, `tenant_role` | `app/src/Service/PermissionChecker.php` |
| Recursos-tipo | `canActOnResource()` *(morto)* | `permission` (`resources.*`), `tenant_role_permission` | `app/src/Service/PermissionChecker.php` |
| Recursos-item | `canAccessResource()` | `resource_access` (+ fallback `resources.*`) | `app/src/Service/PermissionChecker.php`, `app/src/Entity/Permission/ResourceAccess.php` |
| Solicitação de acesso | `AccessRequestController` | `access_request` | `app/src/Controller/AccessRequestController.php`, `app/src/Entity/Permission/AccessRequest.php` |
| Catálogo de permissões | — | `permission` | `app/src/DataFixtures/PermissionFixture.php`, `app/migrations/Version20260401130000.php` |
| Bypass global | `isGlobalSuperAdmin()` | `user` (coluna `roles`, JSONB) | `app/src/Command/CreateSuperAdminCommand.php`, `app/src/Service/PermissionChecker.php` |
| Bypass por tenant | `TenantRole::isSystem()` | `tenant_role` (coluna `is_system`) | `app/src/Service/TenantBootstrapService.php`, `app/src/Entity/Auth/TenantRole.php` |
