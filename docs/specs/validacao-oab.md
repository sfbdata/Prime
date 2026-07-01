# Spec — Validação real de OAB (CNA/SOAP oficial)

> **Risco:** ALTO (identidade `User` — nova coluna de status; gate de criação de escritório)
> **Data:** 2026-07-01 · **Status:** 🟢 **Passo 1 (manual-first) COMMITADO (`f96ae9e`), revisado 2×, suíte 1002/1002. Passo 2 PLANEJADO** (brainstorming fechado; plano em `validacao-oab-plano-fase2.md`) — em implementação. **Passo 3 PENDENTE.**
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

### ⚠️ Revisão 2026-07-01 (pós-teste do SOAP real) — **MANUAL-FIRST**

Teste de chamada real ao `ConsultaAdvogado` revelou que **o serviço EXIGE uma chave de identificação
registrada** (`soap:header Authentication/Key`): sem chave → `Fault "É preciso enviar a chave..."`; com
chave inválida → `Fault "Chave de identificação inválida!"`. **Não temos essa chave** (a OAB só emite
mediante cadastro). Consequências:

- **Verificação automática fica DORMENTE.** A Fase 1 entra com a **interface** + um client
  **"indisponível"** (sempre lança `OabIndisponivelException` → `verificar` devolve `nao_verificada`).
  O **client SOAP real (com `Key`)** vira tarefa **futura** — não dá nem para escrever o parser agora,
  pois nunca vimos uma resposta de sucesso (só erros de chave). Quando houver chave (ou uma API paga),
  implementa-se a interface e o automático liga, **sem tocar no resto**.
- **A verificação passa a ser MANUAL:** a única forma de virar `confirmada` é (a) backfill de dono
  existente, ou (b) **aprovação do super-admin** na tela de revisão. Logo a tela de revisão **deixa de ser
  opcional/tardia** e o **gate só liga por último** (depois de existirem os caminhos de aprovação/entrada).
- **Advogado novo espera aprovação do admin** para criar escritório (não é mais instantâneo) — aceito pelo
  dono (é o "aguardar a validação pelo admin").

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

- **`App\Auth\Service\OabWebServiceClientInterface`** — `consultar(inscricao, uf, nome): ConsultaOabResultado`
  (lança `OabIndisponivelException` em falha). A interface permite **stub nos testes** e troca de backend.
  - **Fase 1 (agora):** impl **dormente** `ClienteOabIndisponivel` — sempre lança `OabIndisponivelException`
    (não há backend automático; ver Revisão MANUAL-FIRST). `verificar` sempre devolve `nao_verificada`.
  - **Futuro:** impl real `OabWebServiceClientSoap` (SOAP `ConsultaAdvogado` via `SoapClient`, header
    `Authentication/Key` por env, timeout 4s, parse do XML de `ConsultaAdvogadoResult`) — quando houver
    chave. Retorna `ConsultaOabResultado` (`existe`, `nomeOficial`, `situacao`).

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
| **Admin (`/admin/platform/oab`)** | Lista paginada de `User`s com OAB (filtro por status, default pendentes; busca); ações **marcar confirmada** (override), **reverter** (admin escolhe `nao_verificada`/`divergente`) e **re-verificar** (dormente). Ações do admin auditadas. |

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

## 🗺 Faseamento (revisado — MANUAL-FIRST; ordem que nunca deixa estado quebrado)

O **gate liga por último** (Passo 3), só depois de existirem os caminhos de aprovação (admin) e de entrada
de OAB (perfil) — senão um advogado novo ficaria preso em `nao_verificada` sem como ser aprovado.

1. **Passo 1 — Modelo + #4 (sem gate)** — interface `OabWebServiceClientInterface` + `ClienteOabIndisponivel`
   (dormente) + `ValidadorOab` (**fecha #4**) + enum/DTOs + colunas `oab_*` (`User`/`CadastroPendente`) +
   backfill (donos→`confirmada`) + os 3 fluxos gravando status. **Nada bloqueia** (não-breaking).
2. **Passo 2 — Caminhos manuais** *(plano detalhado: `validacao-oab-plano-fase2.md`; decisões de UX
   fechadas com o dono em 2026-07-01)* — tela de revisão super-admin (`/admin/platform/oab`: lista
   **paginada** com **filtro por status** + **busca**; ações **marcar `confirmada`** / **reverter** — *admin
   escolhe o destino* `nao_verificada`|`divergente` — / **re-verificar** dormente; ações do admin
   **auditadas automaticamente** — `User` é `Auditavel`, o `AuditLogSubscriber` registra a mudança de
   `oabStatus` com o super-admin como ator) **+** seção de OAB no perfil
   (`/perfil`: ver status + informar/editar OAB — **reset quando muda** — + botão "verificar" **dormente**
   com mensagem clara, rate-limited). A área do admin ganha um **layout/nav de plataforma** próprio
   (fora de escritório), pensado para crescer (escritórios, análise).
3. **Passo 3 — Liga o gate** — `CriarEscritorioUseCase` passa a exigir `confirmada` + estado vazio/dropdown
   condicional. *(Agora seguro: quem precisa tem como ser aprovado.)*
4. **Futuro** — `OabWebServiceClientSoap` (com `Key`, quando obtida) ou API paga → liga a verificação
   automática (o `verificar` deixa de ser dormente). Sem tocar no resto.

---

## 🔎 A resolver na implementação (não bloqueia o desenho)

- **Formato exato do XML** de `ConsultaAdvogado` e os **valores de "situação"** (ex.: "REGULAR",
  "LICENCIADO", "SUSPENSO", "CANCELADO") — confirmar contra o serviço/WSDL real e ajustar o parser + a
  lista do que conta como "Regular".
- **Robustez do `SoapClient`**: timeout via stream context, tratamento de `SoapFault`, e comportamento
  quando a OAB devolve HTML/erro em vez de XML (→ tratar como indisponível).
- **Matcher de nome — endurecer antes de ligar o backend (achado BAIXO-2):** o matcher lenient atual
  aceita quando os tokens do nome mais curto ⊆ oficial, então um nome digitado com **1 token genérico**
  (ex.: "Silva") "bate" com "Maria Silva" → falso-positivo de `confirmada`. Inócuo no Passo 1 (backend
  dormente nunca chega no matcher), mas **antes de ligar a verificação automática**, exigir mínimo de 2
  tokens (ou casar primeiro nome + sobrenome).
