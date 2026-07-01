# Spec — Self-service de Escritórios & Identidade Multi-escritório

> **Risco:** ALTO (identidade User/Tenant, multi-tenancy)
> **Data:** 2026-06-25
> **Status:** Desenho aprovado — pronto para plano de implementação
> **Domínios tocados:** Auth (User, Invitation), Tenant (legado), Sessão (TenantContext)

---

## 📋 Visão Geral

**Problema.** Hoje só o `ROLE_SUPER_ADMIN` cria escritórios (`Tenant`) e contas (`User`)
nascem exclusivamente por convite. Não existe cadastro público nem self-service. A dor
concreta: um advogado que já colabora num escritório e quer **abrir a própria banca** não
tem caminho — e a dúvida "ele precisa de uma conta nova?" expõe a falta do fluxo.

**Solução.** Permitir que um advogado **crie a própria conta e o primeiro escritório**
(self-service, livre e instantâneo após confirmar e-mail), participe de **vários escritórios
com uma única conta** (uns como dono, outros como colaborador convidado) e **alterne entre
eles por um dropdown no topo**. Criar escritório adicional, sair e excluir também ficam
disponíveis de dentro do app.

**Resposta à dúvida original:** **Não há conta nova.** Uma pessoa = um `User` global. O
advogado-colaborador vira **dono** de um novo `Tenant` mantendo o mesmo login; ganha mais
um vínculo `UserTenant`. Mesmo e-mail, mesma OAB, dois chapéus.

**A fundação já existe.** O modelo `UserTenant` (N:N), o `TenantContext` (escritório ativo
na sessão), o `TenantContextValidatorListener` e os fluxos de `Invitation` já estão prontos.
O trabalho é **destravar** a criação e **dar UX** à alternância e ao onboarding.

---

## 🧱 Estado atual (o que já existe) vs. Gap (o que falta)

| Capacidade | Hoje | Gap |
|---|---|---|
| 1 e-mail = 1 conta global | ✅ `User.email` UNIQUE | — |
| Pessoa em N escritórios | ✅ `UserTenant` UNIQUE(user_id, tenant_id) | — |
| Escritório ativo na sessão | ✅ `TenantContext` (`current_tenant_id`) | — |
| Trocar de escritório | ⚠️ Tela `tenant_selecionar` (sai do contexto) | Dropdown no topo |
| Seed ao criar escritório | ✅ `TenantBootstrapService::bootstrap()` | Reusar fora do SuperAdmin |
| Convite de colaborador | ✅ `Invitation` (office/platform) + UseCases de aceite | — |
| **Criar escritório** | ❌ `app_tenant_new` travado em `ROLE_SUPER_ADMIN` | Self-service c/ OAB |
| **Cadastro público** | ❌ Conta só por convite | Página pública + confirmação e-mail |
| **Estado vazio (0 escritórios)** | ⚠️ Cai em `tenant_selecionar` vazio | Tela com "criar" + convites pendentes |
| **Sair / Excluir escritório** | ⚠️ `app_tenant_delete` = hard delete, SuperAdmin-only | Sair (colaborador) + soft delete (dono) |

**Arquivos-âncora de referência:**
- `app/src/Entity/Auth/User.php` — `email` (UNIQUE), `oabNumero`, `oabUf`, `isActive`
- `app/src/Entity/Tenant/Tenant.php` — `isActive`, `criadoPor`
- `app/src/Entity/Auth/UserTenant.php` — UNIQUE(user_id, tenant_id), `isActive`, `tenantRole`
- `app/src/Service/Tenant/TenantContext.php` — `setCurrentTenant()`, `getCurrentTenant()`
- `app/src/EventListener/TenantContextValidatorListener.php` — limpa sessão se vínculo inativo
- `app/src/Service/TenantBootstrapService.php` — cria role admin (`isSystem`) + `UserTenant` + seed
- `app/src/Controller/TenantController.php` — `app_tenant_new` (L92), `app_tenant_show` (L162), `app_tenant_delete` (L289)
- `app/src/Controller/Tenant/TenantSelecaoController.php` — rota `tenant_selecionar`
- `app/src/Security/UserAuthenticator.php` — roteia pós-login por nº de vínculos

---

## 🚀 Jornadas do Usuário

### A) Advogado novo, sem convite — "abrir a própria banca"
1. Acessa página pública **"Crie sua conta e seu escritório"**.
2. Informa: nome, e-mail, senha, **OAB (número + UF, obrigatória)**, nome do escritório, aceite de termos.
3. Sistema persiste o cadastro como **pendente** e envia **e-mail de confirmação** (token com expiração).
4. Ao clicar no link, numa **transação única**: cria `User` (ativo) + `Tenant` + `UserTenant`
   (dono, `TenantRole` `isSystem`) + seed via `TenantBootstrapService`.
5. Loga e cai no expediente já dentro do escritório recém-criado.

### B) Colaborador (advogado ou não) — entra por convite
- Fluxo de `Invitation` (type `office`) **já existe**. Aceite **não exige OAB**.
- Se o e-mail já tem conta → `AceitarConviteEscritorioComContaUseCase` cria/reativa só o vínculo.
- Se não tem → `AceitarConviteEscritorioSemContaUseCase` cria `User` + vínculo.

### C) Já está dentro — criar mais um escritório
- No **dropdown do topo** → "＋ Criar escritório" → mesmo UseCase de criação.
- Não reconfirma e-mail (já autenticado).
- **Se o `User` não tiver OAB cadastrada** (entrou como colaborador) → o fluxo **exige a OAB neste momento**.
- Respeita o **limite de escritórios próprios** (ver RN08).

### D) Alternar de escritório
- Dropdown lista os escritórios com vínculo `isActive`.
- Clica → `TenantContext::setCurrentTenant()` (valida vínculo) → recarrega no contexto escolhido.

### E) Sair de um escritório
- Acessível pelo **dropdown** (ex.: "⋯ / Sair") — porque o colaborador comum não acessa `app_tenant_show`.
- Desativa o **próprio** `UserTenant` (`isActive = false`).
- **Último admin não pode sair** (RN06) — precisa transferir titularidade ou excluir o escritório.
- Se sair do escritório ativo → `TenantContextValidatorListener` limpa a sessão e leva ao estado vazio/seleção.

### F) Excluir um escritório (dono/admin)
- Botão em **`app_tenant_show`** (já é admin-gated), com confirmação forte (digitar o nome) + CSRF.
- **Soft delete:** `Tenant.isActive = false` + marca de quarentena (data de exclusão).
- Job futuro purga em definitivo após período de carência (ver RN09 / Fase futura).

---

## 🛠 Regras de Negócio (RN) & Regras de Sistema (RS)

### Identidade & criação
- **RN01 — Conta única por e-mail.** 1 e-mail = 1 `User` global. Nunca duplicar conta por pessoa.
- **RN02 — Criação livre.** Criar escritório é livre e instantâneo: sem pagamento, sem aprovação humana.
- **RN03 — OAB obrigatória para criar.** Criar escritório exige OAB (número + UF) **válida em formato**.
  Vale no cadastro público **e** no "criar +1" via dropdown.
- **RN04 — Criador vira dono.** Quem cria recebe vínculo com `TenantRole` `isSystem` (acesso total naquele tenant).
- **RN05 — Não-advogado não cria.** Sem OAB no `User`, não cria escritório — só entra por convite (aceite não exige OAB).
- **RN06 — Último admin não sai.** Um escritório nunca pode ficar sem nenhum admin ativo. O último admin
  deve transferir titularidade ou excluir o escritório, não "sair".
- **RN07 — Confirmação de e-mail.** No cadastro **público**, a conta só ativa e o primeiro escritório só
  nasce **após** confirmar o e-mail. (Criação por dentro, já logado, não reconfirma.)
- **RN08 — Limite de escritórios próprios.** Um `User` pode ser **dono** de no máximo **N escritórios**
  (padrão **3**), **configurável** sem deploy de código. O limite conta **apenas escritórios onde é dono**
  (criador / `TenantRole` `isSystem`); escritórios onde entrou por convite **não contam**.
- **RN09 — Exclusão é soft + purga.** "Excluir" desativa (`isActive = false`) e entra em quarentena;
  dados ficam recuperáveis. Purga definitiva ocorre por job após período de carência (ex.: 30 dias).

### Comportamento de sistema
- **RS01 — Transação atômica.** Criação de `User` + `Tenant` + `UserTenant` + seed roda numa única
  transação; qualquer falha = rollback total (sem escritório órfão nem usuário sem vínculo).
- **RS02 — Validação na troca.** O dropdown só lista vínculos `isActive`. `setCurrentTenant()` valida o
  vínculo antes de trocar (reusa a checagem existente; lança `AccessDeniedException` se inválido).
- **RS03 — OAB só por formato.** Validação de OAB é de formato (número + UF), sem consulta à API da OAB nesta entrega.
- **RS04 — Expiração do cadastro pendente.** Token de confirmação expira (reusar padrão de `Invitation`, 24–48h).
  Expirado → tela de reenvio.
- **RS05 — Limite parametrizável.** `N` (RN08) vem de configuração (parâmetro de container / env), injetada
  no UseCase de criação. Trocar o valor não exige alterar código.
- **RS06 — Soft delete some das listagens.** `Tenant.isActive = false` remove o escritório do dropdown, da
  seleção e do acesso; nenhuma query de uso deve retornar tenant inativo.
- **RS07 — Sessão coerente pós-saída/exclusão.** Sair/excluir o escritório ativo limpa `current_tenant_id`
  e redireciona ao estado vazio/seleção via `TenantContextValidatorListener`.
- **RS08 — Substituir o hard delete atual.** `app_tenant_delete` deixa de fazer `entityManager->remove()`;
  passa a soft delete (RN09). Hard delete real fica só na purga (job).

---

## ⚠️ Casos de Borda & Tratamento de Erros

| Cenário | Tratamento |
|---|---|
| E-mail já existe no cadastro público | Não recria. Mensagem + CTA de login ("já tem conta? entre e crie o escritório por dentro"). |
| Token de confirmação expirado/usado | Tela de reenvio do e-mail de confirmação. |
| Concorrência: 2 cadastros simultâneos, mesmo e-mail | UNIQUE protege; o segundo recebe erro amigável (não 500). |
| Pessoa sem nenhum escritório (recusou todos / só tem convite pendente) | Estado vazio: "Criar escritório" + lista de convites pendentes (em vez de seleção vazia). |
| Advogado sem OAB tenta criar pelo dropdown | Fluxo exige a OAB antes de concluir (RN03). |
| Limite de escritórios próprios atingido | Bloqueia criação com mensagem clara (RN08); botão "criar" desabilitado/avisa. |
| Último admin tenta sair | Bloqueia com explicação (RN06): transferir titularidade ou excluir. |
| Excluir o escritório atualmente ativo | Soft delete + limpa sessão + redireciona (RS07). |
| Reativação pós-soft-delete | Só SuperAdmin reativa enquanto em quarentena; após purga, irreversível. |
| OAB em formato inválido | Erro de validação no form, sem persistir nada. |

---

## 📌 Critérios de Aceite (BDD)

```gherkin
Funcionalidade: Cadastro público combinado (conta + primeiro escritório)

  Cenário: Advogado cria conta e escritório com sucesso
    Dado que não existe conta com o e-mail "ana@adv.com"
    Quando ela preenche nome, e-mail, senha, OAB válida e nome do escritório
    Então o sistema cria um cadastro pendente
    E envia um e-mail de confirmação
    E nenhum Tenant é criado ainda

  Cenário: Confirmação ativa conta e cria o escritório
    Dado um cadastro pendente válido para "ana@adv.com"
    Quando ela clica no link de confirmação
    Então são criados User (ativo), Tenant e UserTenant (dono, role isSystem) numa transação
    E ela é logada dentro do novo escritório

  Cenário: E-mail já cadastrado
    Dado que já existe conta com "ana@adv.com"
    Quando alguém tenta o cadastro público com esse e-mail
    Então o cadastro é recusado com mensagem e CTA de login
    E nenhuma conta ou escritório é criado

  Cenário: OAB ausente bloqueia criação
    Dado um formulário de cadastro sem OAB
    Quando o usuário envia
    Então recebe erro de validação
    E nada é persistido

Funcionalidade: Criar escritório adicional (usuário logado)

  Cenário: Colaborador sem OAB cria o próprio escritório
    Dado um usuário logado cujo User não tem OAB cadastrada
    Quando ele clica em "Criar escritório" no dropdown
    Então o sistema exige a OAB antes de concluir
    E ao informar OAB válida, cria o Tenant e o vínculo de dono

  Cenário: Limite de escritórios próprios atingido
    Dado um usuário que já é dono de 3 escritórios
    E o limite configurado é 3
    Quando ele tenta criar mais um
    Então a criação é bloqueada com mensagem clara

  Cenário: Convidado em muitas bancas ainda pode criar a própria
    Dado um usuário convidado para 4 escritórios e dono de nenhum
    Quando ele cria o próprio escritório
    Então a criação é permitida (convites não contam no limite)

Funcionalidade: Alternar entre escritórios

  Cenário: Troca pelo dropdown
    Dado um usuário com vínculo ativo em "Silva" e "Costa"
    E o escritório ativo é "Silva"
    Quando ele seleciona "Costa" no dropdown
    Então o TenantContext passa a apontar para "Costa"
    E a navegação recarrega no contexto de "Costa"

Funcionalidade: Sair e Excluir escritório

  Cenário: Colaborador sai de um escritório
    Dado um colaborador (não-admin) com vínculo ativo em "Silva"
    Quando ele escolhe "Sair" no dropdown
    Então seu UserTenant em "Silva" fica isActive=false
    E "Silva" some do seu dropdown

  Cenário: Último admin não pode sair
    Dado um usuário que é o único admin ativo de "Silva"
    Quando ele tenta sair
    Então a ação é bloqueada com orientação para transferir ou excluir

  Cenário: Dono exclui (soft delete) o escritório
    Dado um admin de "Silva" na página do escritório
    Quando ele confirma a exclusão digitando o nome do escritório
    Então Tenant.isActive vira false e o escritório entra em quarentena
    E os dados permanecem recuperáveis (não há hard delete imediato)
    E se "Silva" era o ativo, a sessão é limpa e ele vai ao estado vazio/seleção
```

---

## 🗺 Faseamento (cada fase entrega valor isolado)

| Fase | Escopo | Risco | Valor |
|---|---|---|---|
| **1 — Switcher** | Dropdown de escritórios no topo + "Sair" + estado vazio decente | Baixo (UI/sessão) | Imediato p/ quem já é multi-escritório |
| **2a — Criar por dentro** ✅ | "＋ Criar escritório" no dropdown + estado vazio (logado) + guard de OAB + limite configurável | Médio/Alto | Advogado abre banca adicional |
| **2b — Soft delete** ✅ | `app_tenant_delete` vira soft delete (RS08) + botão em `app_tenant_show` (dono, confirma digitando o nome) + **RS06/RS07** (tenant inativo não vaza em switcher/seleção/`setCurrentTenant`/login **e** o `TenantContextValidatorListener` derruba a sessão de quem já estava no escritório excluído por outro admin) + fecha **I3** (`encontrarPendentesPorEmail` filtra `tenant.isActive`) | Alto | Excluir com segurança |
| **3 — Cadastro público** | Página pública + confirmação de e-mail + criação na confirmação | Alto | Funil self-service de aquisição |
| **Futura** | Job de purga pós-quarentena; transferência de titularidade; badge multi-escritório agregado | — | Higiene e UX avançada |

---

## 🔒 Notas de Segurança / Multi-tenancy (risco ALTO)

- Criação de `Tenant` deixa de exigir `ROLE_SUPER_ADMIN`; a nova autorização é "usuário autenticado +
  OAB + dentro do limite". Revisar que **nenhuma rota administrativa de tenant** herde essa abertura
  indevidamente (só a criação self-service).
- Soft delete não pode vazar: garantir que listagens, dropdown, seleção e `TenantContext` ignorem
  `isActive = false` (RS06). Atenção: o isolamento é **manual por repository** (sem Doctrine Filter).
- A transação de criação roda fora do contexto de tenant existente (o tenant ainda não existe);
  validar que o seed (`TenantBootstrapService`) associa o vínculo ao usuário correto.
- "Sair"/"Excluir" devem checar posse real do vínculo/role — nunca confiar em ID de rota sem validar.

---

## 🔗 Dependências & coordenação com `isolamento-tenant-sistemico.md`

Outra frente trabalha o **isolamento de tenant sistêmico** (Doctrine SQL Filter global). Os escopos
se tocam pouco, mas há pontos a coordenar:

- **Sem colisão de modelo:** a spec de isolamento declara que `User`, `Tenant`, `UserTenant`,
  `Permission`, `TenantRole` **não** são tenant-aware — o filtro não os toca. Toda a minha camada
  estrutural está fora do filtro deles. Migrations também não colidem (eles mexem em
  Cliente/Processo/Agenda/Ponto).
- **Fase 1 (Switcher) é independente** do filtro — pode ser implementada em paralelo. Só compartilha o
  ciclo de request do `TenantContextValidatorListener` (ordenação de listener, não conflito de lógica).
- **Fase 2/3 dependem do filtro:** criar um Tenant novo roda dentro de um request cujo filtro pode estar
  escopado no tenant atual. O seed (`TenantBootstrapService`) **escreve** sem problema (o filtro só afeta
  SELECT), mas qualquer leitura de entidade de negócio durante a criação sairia escopada errada.
  → Implementar Fase 2/3 **após** o filtro aterrissar, ou validar explicitamente o comportamento do
  bootstrap sob filtro ativo.
- **`TenantController`:** eles adicionam guards `garantirRecursoDoTenant` em rotas `{id}`; eu altero
  `app_tenant_new` (autorização) e `app_tenant_delete` (soft delete). Métodos distintos no mesmo arquivo —
  coordenar o merge.
- **Soft delete (RS06) é responsabilidade desta spec:** o filtro deles é por `tenant_id`, não por
  `isActive`. Esconder tenant inativo de listagens/dropdown/seleção **não** é coberto pelo mecanismo deles.
- **Dívida conhecida da Fase 1 → fechar na Fase 2:** `InvitationRepository::encontrarPendentesPorEmail`
  (usado no estado vazio para listar convites) **não filtra `tenant.isActive`**. Hoje não vaza (soft delete
  de tenant só nasce na Fase 2), mas ao implementar o soft delete é obrigatório filtrar tenant inativo
  nessa query — senão um convite para escritório excluído reaparece na seleção (viola RS06).

## ✅ Decisões registradas (todas confirmadas)

1. Criar escritório: **livre e instantâneo** (sem pagamento/aprovação).
2. Alternância: **dropdown no topo**, com "＋ Criar escritório".
3. Onboarding novo: **cadastro público combinado** (conta + 1º escritório).
4. Cadastro: **mínimo + OAB obrigatória**; dono é advogado; staff entra por convite.
5. **Confirmação de e-mail antes de ativar** conta/escritório (cadastro público).
6. Anti-abuso: **máx. 3 escritórios próprios**, **configurável**; conta só os que a pessoa é dona.
7. Excluir: **soft delete agora + purga após quarentena** (job futuro).
8. "Sair" mora no **dropdown**; "Excluir" mora em **`app_tenant_show`**.

## ⏭ Fora de escopo (defaults aprovados)

- Notificações agregadas entre escritórios (MVP é por escritório ativo).
- Validação real na API da OAB (só formato por enquanto).
- Dados do escritório (CNPJ, endereço, sede) no cadastro — preenchidos depois, nas configurações.
- Transferência de titularidade e job de purga ficam para fase futura.
