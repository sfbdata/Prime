# Spec — Validação real de OAB (CNA/SOAP oficial)

> **Risco:** ALTO (identidade `User` — nova coluna de status; gate de criação de escritório)
> **Data:** 2026-07-01 · **Status:** 📝 **DESENHO APROVADO — aguardando revisão da spec + plano de implementação**
> **Origem:** dívida #3 da `self-service-escritorios.md` (RS03/RN03/RN05). **Fecha junto a dívida #4** (validação de OAB duplicada em 3 UseCases).
> **Domínios tocados:** Auth (`User`, `CadastroPendente`, UseCases de cadastro/convite), Tenant (`CriarEscritorioUseCase`), Profile, Admin (super-admin)

---

## 🧭 Handoff / estado (leia primeiro)

Feature nova, **nada implementado**. Desenho fechado via brainstorming com o dono (2026-07-01).
Substitui a validação de OAB **só de formato** (regex) por **verificação real contra o Cadastro Nacional
de Advogados (CNA)**, e reformula a regra: **OAB deixa de ser obrigatória para cadastrar** — passa a ser
**gate apenas para criar escritório** (só quem tem OAB `confirmada` cria).

### Decisões do dono (2026-07-01, confirmadas)

| # | Decisão | Valor |
|---|---|---|
| 1 | Fonte de dados | **SOAP oficial da OAB** (`www5.oab.org.br/cnaws/service.asmx`, `ConsultaAdvogado`), grátis, sem terceiro |
| 2 | Disponibilidade | **Fail-open**: serviço fora/timeout → não bloqueia; marca `nao_verificada` |
| 3 | OAB no cadastro | **Opcional em todos os fluxos**; a conta é sempre criada |
| 4 | Gate | **Criar escritório exige `oabStatus == confirmada`** |
| 5 | Rigor (serviço no ar) | Bloqueio de criação só faz sentido via gate; **não existe → `divergente`**; **existe mas nome diverge / situação irregular → `divergente`**; **existe + nome bate + Regular → `confirmada`** |
| 6 | Matcher de nome | **Lenient**: normaliza acento/caixa/espaço; "bater minimamente" (tokens do nome mais curto ⊆ oficial) |
| 7 | Backfill de existentes | **Dono de ≥1 escritório → `confirmada`** (grandfather); demais com OAB → `nao_verificada`; sem OAB → nulo |
| 8 | Revalidação | **Admin (tela de revisão) + botão do próprio usuário** (no perfil), sob demanda com rate-limit |
| 9 | Entry point do usuário | **Perfil (`/perfil`)** — ver status + informar/editar OAB + botão "verificar". Único ponto sempre acessível (colaborador sem escritório próprio) |

**Fora de escopo:** verificação por CPF; job batch/cron de revalidação (a revalidação é sob demanda).

---

## 📋 Problema & objetivo

Hoje a OAB é validada **só no formato** (`^\d+$` + `^[A-Z]{2}$`) — OAB inventada mas bem-formatada
passa e cria escritório (RS03, buraco conhecido). E a lógica está **duplicada em 3 UseCases**
(`CriarEscritorioUseCase`, `AceitarConvitePlataformaUseCase`, `IniciarCadastroPublicoUseCase`).

**Objetivo:** verificar a OAB de verdade contra o CNA, sem travar o cadastro (fail-open), gastar zero
(sem API paga), e **impedir que uma OAB não confirmada abra um escritório** — mantendo o advogado real
com um caminho claro (informar/validar no perfil, ou entrar por convite).

---

## 🔁 Modelo reformulado

- **OAB opcional em todo cadastro.** A conta (`User`) é sempre criada, com ou sem OAB.
- **`oabStatus`** (nullable): `null` (sem OAB) · `nao_verificada` · `divergente` · `confirmada`.
- **Único gate: `CriarEscritorioUseCase` exige `oabStatus == confirmada`.**
- Quem não é `confirmada`: cria conta normalmente, **não vê "criar escritório"**, e pode
  **(a)** entrar por convite (colaborador — não exige OAB) ou **(b)** informar/validar a OAB no perfil.
- **RN03** vira "OAB **validada (confirmada)** obrigatória para criar escritório". **RN05** mantém o
  espírito (não-advogado não abre banca), mas agora **tem conta**.
- **Consequência nos formulários:** `CadastroPublicoType` e o aceite de convite passam a ter **OAB
  opcional** (campo não-obrigatório); no cadastro público o `nomeEscritorio` também vira opcional/condicional
  (só usado se a OAB virar `confirmada`). O `CriarEscritorioType` mantém a OAB (é o caminho de verificação
  inline de quem ainda não é `confirmada`).

---

## 🏗 Arquitetura & componentes

Fecha a #4: a validação vira um **serviço único** que os 3 UseCases (+ perfil + admin) chamam.

- **`App\Auth\Service\OabWebServiceClientInterface`** + impl **`OabWebServiceClient`**
  Encapsula o SOAP `ConsultaAdvogado(inscricao, uf, nome)` (via `SoapClient` com timeout de contexto
  **4s**). URL/WSDL por env (default o endpoint da OAB); se vazio/desabilitado → comporta-se como
  indisponível (fail-open). Retorna **`ConsultaOabResultado`** (`existe: bool`, `nomeOficial: ?string`,
  `situacao: ?string`) ou lança `OabIndisponivelException` (transporte/timeout/XML inválido). A interface
  permite **stub nos testes** (sem rede).

- **`App\Auth\Service\ValidadorOab`** — ponto único:
  - `validarFormato(?string $numero, ?string $uf): void` — OAB ausente/vazia = **ok (opcional)**; se
    presente, aplica o regex de formato (o de hoje). Lança `\InvalidArgumentException` em formato inválido.
  - `verificar(string $numero, string $uf, string $nome): ResultadoVerificacaoOab` — chama o client,
    aplica o rigor, **fail-open** (indisponível → `nao_verificada`, sem lançar). Retorna
    `ResultadoVerificacaoOab` (`status: StatusOab`, `nomeOficial: ?string`, `situacao: ?string`).

- **`App\Auth\Enum\StatusOab`** (enum backed string): `nao_verificada` · `divergente` · `confirmada`.
  (Ausência de OAB é representada por `oabStatus === null`, não por valor de enum.)

- **`App\Auth\DTO\ConsultaOabResultado`** e **`ResultadoVerificacaoOab`** — DTOs de saída.

**Nada de bloqueio por exceção nos fluxos de cadastro** — o único ponto que "barra" é o gate de
`CriarEscritorioUseCase` (lança `\DomainException` se `oabStatus !== confirmada`).

---

## 🧮 Regras de decisão (`verificar`)

Serviço **no ar** responde `ConsultaAdvogado`:
- **não existe** (número+UF não retorna advogado) → `divergente` (com `nomeOficial = null` — distingue
  "não existe" de "existe mas diverge" para o admin).
- existe + **nome bate** (matcher lenient) + **situação "Regular"** → `confirmada`.
- existe + (**nome diverge** OU **situação não-Regular**: licenciado/suspenso/cancelado) → `divergente`
  (grava `nomeOficial` + `situacao`).

Serviço **indisponível** (timeout/erro/XML inválido) → `nao_verificada` (**fail-open**).

**Matcher de nome (lenient):** normaliza ambos (uppercase, remove acentos, colapsa espaços, remove
pontuação) e considera "bate" se **todos os tokens do nome mais curto estão contidos no nome oficial**
(tolera nome do meio omitido, sobrenome de casada parcial). Como `divergente` **não** bloqueia (só o gate
bloqueia), um matcher lenient reduz falso-positivo de flag sem risco de travar advogado real.

---

## 🔄 Fluxos por caso de uso

Todos chamam `validarFormato` (sempre) e `verificar` (quando há OAB). Nenhum bloqueia a **conta**.

| Fluxo | Comportamento |
|---|---|
| **`CriarEscritorioUseCase`** (gate) | Se `criador.oabStatus === confirmada` → cria. Senão: se o form trouxe OAB → `validarFormato`+`verificar`, grava status; se virou `confirmada` → cria; senão **`\DomainException`** ("valide sua OAB para criar um escritório"). Sem OAB e sem `confirmada` → mesma exceção. |
| **`AceitarConvitePlataformaUseCase`** | OAB opcional; se presente, `verificar` e grava `oabStatus` no `User`. Nunca bloqueia (conta criada). |
| **`IniciarCadastroPublicoUseCase`** | OAB opcional; se presente, `verificar` e grava `oabStatus`+`oabNomeOficial` no `CadastroPendente`. Nunca bloqueia. |
| **`ConfirmarCadastroUseCase`** | Cria o `User` (sempre), copiando `oabStatus`/`oabNomeOficial` do `CadastroPendente`. Cria o `Tenant`+vínculo **só se `confirmada`**; senão cria só a conta (cai no estado vazio). |
| **Perfil (`/perfil`)** | Ver status + informar/editar número+UF + botão **"verificar"** (roda `verificar`, grava status). Entry point universal. |
| **Estado vazio / dropdown** | "criar escritório" só aparece se `confirmada`; senão mostra o status + atalho para validar no perfil + convites pendentes. |
| **Admin (`/admin/platform/oab`)** | Lista `User`s `divergente`/`nao_verificada`; ações **re-verificar** e **marcar confirmada** (override). |

---

## 🗄 Migrations (risco ALTO — identidade)

- **`user`**: `+ oab_status` (varchar/enum, **nullable**), `+ oab_nome_oficial` (varchar nullable),
  `+ oab_verificada_em` (timestamp nullable).
  **Backfill:** `oab_status = 'confirmada'` para todo user que é `tenant.criado_por` de algum tenant
  (dono grandfathered); `'nao_verificada'` para os demais com `oab_numero` não-nulo; `null` para sem OAB.
- **`cadastro_pendente`**: `+ oab_status` (nullable), `+ oab_nome_oficial` (nullable) — carrega
  Iniciar→Confirmar. (Nota: purga de `cadastro_pendente` já existe — o job não muda.)

---

## ⚙️ Configuração

`app/config/services.yaml` + `app/config/packages/framework.yaml`:
- `oab_ws_url` = `%env(default:default_oab_ws_url:OAB_WS_URL)%` (default: endpoint da OAB); vazio = desabilitado (fail-open).
- `oab_ws_timeout` (segundos, default 4).
- Rate limiter `oab_verificar` (ex.: 10/hora por usuário) para o botão "verificar" do perfil.
- Em `when@test`: URL vazia / client stub — sem rede nos testes.

---

## 🔒 Segurança / multi-tenancy

- **`oabStatus` é da identidade global do `User`** (não é tenant-aware) — a tela de revisão é
  **super-admin / platform-level** (`ROLE_SUPER_ADMIN`), não vaza por tenant, e opera sobre `User` global.
- **Gate server-side**: esconder o CTA "criar escritório" no front é UX; a barreira real é o
  `\DomainException` no `CriarEscritorioUseCase` (nunca confiar só no front).
- **Rate-limit** no botão "verificar" (perfil) para não martelar o serviço da OAB nem virar oráculo de
  enumeração de OAB.
- Verificação roda em request (síncrona) com **timeout curto**; fail-open garante que instabilidade da
  OAB nunca derruba cadastro/login.

---

## 🧪 Testes

- **Unit `ValidadorOab`** (client via stub da interface): `confirmada` / `divergente` (não existe) /
  `divergente` (nome diverge) / `divergente` (situação irregular) / `nao_verificada` (indisponível);
  casos do matcher (acento, caixa, nome do meio, sobrenome de casada); `validarFormato` (ausente = ok,
  formato inválido = erro).
- **Functional**:
  - `CriarEscritorioUseCase`: bloqueia sem `confirmada`; cria com `confirmada`; verifica OAB inline no form.
  - `AceitarConvitePlataforma` / `IniciarCadastroPublico`: nunca bloqueiam; gravam status.
  - `ConfirmarCadastro`: cria escritório só se `confirmada`; senão só conta.
  - Perfil: informar/editar OAB + botão verificar altera status (rate-limit).
  - Admin: lista + re-verificar + marcar confirmada (super-admin; isolamento global).
- **Migration/backfill**: teste que valida a regra de grandfather (dono→confirmada; demais corretos).
- **Client SOAP real**: interface + stub nos testes; um teste de contrato/integração **skippável**
  (`@group external`) que bate no serviço real — fora do CI (flaky/rede).

---

## ⚠️ Casos de borda

| Cenário | Tratamento |
|---|---|
| Usuário sem OAB tenta criar escritório | `\DomainException` clara → orienta a informar/validar OAB no perfil. |
| Advogado real com OAB `nao_verificada` (serviço caiu no signup) | Vai ao perfil, clica "verificar" → `confirmada` → cria escritório. |
| OAB `divergente` por nome | Entra, marcado; admin revisa/override ou o usuário corrige o nome/OAB e re-verifica. |
| Advogado suspenso/licenciado | `divergente` (não cria escritório); admin decide override. |
| Backfill: dono existente | `confirmada` (grandfather) — não trava quem já opera. |
| Cadastro público sem OAB confirmada | Cria só a conta; `nomeEscritorio` do form é descartado; cai no estado vazio. |
| Serviço da OAB fora do ar em massa | Todos entram `nao_verificada`; ninguém é bloqueado de cadastrar; criação de escritório espera revalidação. |

---

## 🗺 Faseamento (cada fase entrega valor sozinha)

1. **Backend core** — `OabWebServiceClient`(+interface+stub) + `ValidadorOab` (**fecha #4**) + enum/DTOs +
   colunas `oab_*` (`User`/`CadastroPendente`) + backfill + verificação nos 3 fluxos + **gate no
   `CriarEscritorioUseCase`** + `ConfirmarCadastro` condicional. *(Sistema já correto: OAB fake não abre
   escritório; donos existentes grandfathered.)*
2. **UX** — **seção de OAB no perfil (`/perfil`)**: status + informar/editar + botão "verificar"
   (entry point universal). Estado vazio/dropdown refletindo o status ("criar escritório" condicional).
3. **Admin** — tela de revisão super-admin (`/admin/platform/oab`): listar `divergente`/`nao_verificada`,
   ações re-verificar + marcar confirmada.

---

## 🔎 A resolver na implementação (não bloqueia o desenho)

- **Formato exato do XML** de `ConsultaAdvogado` e os **valores de "situação"** (ex.: "REGULAR",
  "LICENCIADO", "SUSPENSO", "CANCELADO") — confirmar contra o serviço/WSDL real e ajustar o parser + a
  lista do que conta como "Regular".
- **Robustez do `SoapClient`**: timeout via stream context, tratamento de `SoapFault`, e comportamento
  quando a OAB devolve HTML/erro em vez de XML (→ tratar como indisponível).
