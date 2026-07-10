# Etapa 9 — Dashboard + Central de Alertas (Gestão de Cobranças)

> Risco: **MÉDIO**. Leitura agregada tenant-wide que EXIBE dinheiro (saldo/honorários/recuperação) e
> depende de isolamento multi-tenant correto. **Sem escrita, sem migration, sem estado novo persistido.**
> Fonte de verdade das regras: `docs/gestao-cobrancas/FEATURE_GESTAO_COBRANCAS_SPEC_FINAL.md` §14 (alertas),
> §18/§19 (honorários e fronteira com o Financeiro), §20 (dashboard). Alinha ao padrão da Onda 8A (leitura).

## 1. Objetivo

Fechar a camada visual da feature com duas telas **de leitura, visão do escritório** (não por-caso):

1. **Dashboard** (`/cobrancas/painel`): visão financeira + operacional + resultado, com filtros simples por
   carteira e período.
2. **Central de alertas** (`/cobrancas/alertas`): alertas operacionais agregados por carteira e globalmente,
   reutilizando o serviço `AlertasCobranca` (E5).

Nada de domínio Financeiro (§19), nada de mini-ERP (§20), nada de escrita. O detalhe/alertas **por caso** já
existe na Onda 8A (`caso/show`) — a Etapa 9 é a visão **consolidada do tenant**.

## 2. Princípio arquitetural (o que NÃO fazer)

Saldo, honorários e alertas são **derivados** (invariável 20; §18/§19; invariável 28) e vivem em UM lugar:
os serviços `CalculadoraSaldo`, `CalculadoraHonorarios`, `AlertasCobranca`. A agregação tenant-wide **itera os
casos e reutiliza esses serviços** — exatamente o padrão de `MontarVisaoCarteiraUseCase` (que soma saldo caso a
caso em PHP). **NÃO** criar query SQL com `SUM` de saldo/exigível: isso reimplementaria a regra de "exigível"
(exclui obrigações substituídas por acordo vigente e parcelas de acordo rompido/cancelado) e divergiria do
serviço — proibido pela regra "não duplicar regra de saldo ou alerta em Controller/Twig/Repo".

Custo O(casos) por tela é aceito para o MVP (volume moderado) e limitável pelos filtros de carteira/período;
o mesmo trade-off já está sinalizado em `CasoCobrancaRepository` (comentário do `findByFilters`) e
`ListarCasosUseCase`. **Follow-up de performance registrado** (agregados materializados se escalar). Nota: a
constante por caso é ~6–8 queries (o dashboard chama `saldoExigivel`, `saldoVencido` e `alertasDoCaso`, e
`doCasoExigiveis` acaba repetido; `saldoExigivel` é recomputado dentro de `alertasDoCaso` para o
`ProntoParaEncerrar`) — a redução futura é tanto de materialização quanto dessa constante (ex.: um método
agregado que reúna exigível+movimentos por caso numa passada). Correção não é afetada, só custo.

## 3. Superfície reutilizada (já existe)

- `CalculadoraSaldo::saldoExigivel(Caso)` / `saldoVencido(Caso, hoje)` — centavos int, por caso.
- `CalculadoraHonorarios::projetados(Caso, baseDivida)` / `realizadosSobreRecuperacao(Caso, recuperadoDivida)`.
- `AlertasCobranca::alertasDoCaso(Caso, hoje)` → `AlertaCobranca[]` (retorna `[]` para caso encerrado).
- Repos por-caso: `ObrigacaoRepository::doCasoExigiveis`, `PagamentoRepository::doCaso`,
  `LiquidacaoRepository::doCaso`/`totalReconhecidoNoCaso`, `ProximaAcaoRepository::findAtivaDoCaso`,
  `RevisaoPessoaCobradaRepository::existePendenteDoCaso`.
- Enums com `label()`/`badgeClass()`/`icone()`: `StatusCaso`, `TipoAlerta`, `ModoCarteira`, `FormaHonorarios`.
- Trait `AutorizacaoCobranca` (`tenantComModulo()`), `CobrancaExtension` (`|centavos`).
- Twig reaproveitável: tiles `.caso-metric`, alerta `.caso-alerta`, empty `.cobranca-empty`, `_subnav`.
- DTO `CasoResumoOutput` (linhas de pendência), `AlertaCobranca`.

## 4. O que será criado

### 4.1 Repositório (1 método novo, sem SQL de saldo)

`CasoCobrancaRepository::doTenant(Tenant $tenant, ?Carteira $carteira = null): CasoCobranca[]`
- Lista **todos** os casos do tenant (WHERE `c.tenant = :tenant` sempre explícito), opcionalmente filtrados por
  carteira (via join `c.objeto.carteira`). Ordenado por atualização desc.
- Inclui **encerrados** (necessários para "valor total recuperado" all-time). A derivação por-caso naturalmente
  zera saldo/alertas de encerrados (`saldoExigivel==0`; `alertasDoCaso==[]`).

### 4.2 UseCases de leitura (2 novos)

**`MontarDashboardCobrancaUseCase::executar(Tenant, ?Carteira, DateTimeImmutable $inicio, DateTimeImmutable $fim, ?DateTimeImmutable $hoje = null): DashboardCobrancaOutput`**

Itera `doTenant(tenant, carteira)`; para cada caso deriva e acumula:

- **Visão financeira** (ponto-no-tempo, exceto recuperado):
  - `saldoEmAberto` = Σ `saldoExigivel` dos casos **não encerrados**.
  - `saldoVencido` = Σ `saldoVencido(caso, hoje)` dos não encerrados.
  - `valorRecuperadoNoPeriodo` = Σ (pagamentos com `data ∈ [inicio,fim]` → `valorRecuperadoDivida()`) +
    Σ (liquidações com `data ∈ [inicio,fim]` → `valorReconhecido`). Movimentos lidos via `doCaso` e filtrados
    por data em PHP (sem query nova por período).
  - `honorariosProjetados` = Σ `projetados(caso, saldoExigivelDoCaso)` dos não encerrados (honorário futuro
    sobre o que ainda está em aberto).
  - `honorariosRealizadosNoPeriodo` = Σ por caso, **conforme a forma do snapshot** (§18):
    - `acrescido_divida`: Σ `Pagamento.valorHonorarios` (já separado na origem) com `data ∈ período`.
    - `retido`/`cobrado_separado`: `realizadosSobreRecuperacao(caso, Σ valorRecuperadoDivida dos pagamentos do
      período)`.
    - `sem_percentual`: 0.
- **Visão operacional** (ponto-no-tempo, derivada de `alertasDoCaso`/repos — não encerrados):
  - `pagamentosAVerificar` = nº de casos com alerta `ObrigacaoVencida` (obrigações vencidas cujo pagamento
    precisa ser verificado — mapeamento documentado abaixo).
  - `proximasAcoesAtrasadas` = nº de casos com alerta `AcaoAtrasada`.
  - `parcelasAcordoVencidas` = nº de casos com alerta `ParcelaAcordoVencida`.
  - `revisoesPendentes` = nº de casos com alerta `RevisaoPendente`.
  - `casosJudicializados` = nº de casos `status == Judicializado`.
- **Resultado**:
  - `valorTotalRecuperado` (all-time) = Σ (Σ `valorRecuperadoDivida` de todos os pagamentos + Σ
    `valorReconhecido` de todas as liquidações), **todos os casos** (inclui encerrados).
  - `valorEmAberto` = `saldoEmAberto`.
  - `taxaRecuperacaoBasisPoints` = `valorTotalRecuperado * 10000 / (valorTotalRecuperado + valorEmAberto)`
    (aritmética inteira; 0 quando denominador 0). Exibida como `%` no Twig.
  - `objetosInadimplentes` = nº de **objetos distintos** com ≥1 caso não encerrado e `saldoExigivel > 0`.
  - `casosAtivos` = nº de casos não encerrados (ativo + judicializado).
  - Ambos exibidos sempre; a distinção só é materialmente diferente quando há carteira modo `multiplo`.

Saída: `DashboardCobrancaOutput` (readonly, campos int centavos + contagens int + echo dos filtros).

**`MontarCentralAlertasUseCase::executar(Tenant, ?Carteira, ?DateTimeImmutable $hoje = null): CentralAlertasOutput`**

Itera `doTenant(tenant, carteira)` (encerrados entram e produzem `[]` — ignorados); para cada caso com
`alertasDoCaso` não vazio, monta um `CasoComAlertasOutput` `{casoId, objetoIdentificacao, carteiraId,
carteiraNome, pessoaNome, statusLabel, statusBadgeClass, alertas: AlertaCobranca[]}`. Agrupa por carteira em
`CarteiraAlertasOutput` `{carteiraId, carteiraNome, casos[]}`. Global: `totaisPorTipo` = contagem por
`TipoAlerta->value` e `totalCasosComAlerta`. Reutiliza integralmente `AlertasCobranca` (nenhuma regra nova).

### 4.3 Output DTOs (novos)
`DashboardCobrancaOutput`, `CentralAlertasOutput`, `CarteiraAlertasOutput`, `CasoComAlertasOutput` — todos
`final`, readonly, sem entidade Doctrine crua (arrays/escalares para o Twig).

### 4.4 Controller (1 novo, fino)
`DashboardController` (`#[Route('/cobrancas')]`, `#[IsGranted('ROLE_USER')]`, `use AutorizacaoCobranca`):
- `GET /cobrancas/painel` → `cobranca_dashboard` — gate `tenantComModulo()`→`semAcesso()`; carteira opcional
  resolvida por `findOneByIdDoTenant`→**404** se id inválido/de outro tenant (anti-IDOR); período com default
  no servidor (mês corrente; parse defensivo); render `dashboard/index.html.twig`.
- `GET /cobrancas/alertas` → `cobranca_alertas` — mesmo gate; carteira opcional idem; render
  `alertas/index.html.twig`. `carteiraId` para o filtro vem de `CarteiraRepository::opcoesFacetaDoTenant`.
- Sem escrita → **sem CSRF**. Tenant nunca vem do request.

### 4.5 Templates
- `cobranca/dashboard/index.html.twig`: subnav (`ativo: 'painel'`), barra de filtro (select carteira + período
  início/fim), 3 blocos de cards (Financeira / Operacional / Resultado) reusando `.caso-metric`; contadores
  operacionais com link para `cobranca_alertas`/`cobranca_caso_index` filtrado; barra/gauge de taxa de
  recuperação; empty state quando o tenant não tem casos. `|centavos` em todo dinheiro; tema claro/escuro via
  `cobrancas.css`.
- `cobranca/alertas/index.html.twig`: subnav (`ativo: 'alertas'`), chips de `totaisPorTipo` (badge+ícone do
  enum), grupos por carteira listando casos + badges de alerta (markup `.caso-alerta`), link para
  `cobranca_caso_show`. Empty state "nenhum alerta pendente".
- `_partials/_subnav.html.twig`: adicionar itens **Painel** e **Alertas** (3–4 itens). Sidebar **inalterado**
  (menu segue apontando `cobranca_carteira_index` como landing — não quebrar UX/testes da 8A).
- `cobrancas.css`: pequenos ajustes (grid de tiles do dashboard, chips de alerta) — tema-aware.

## 5. Decisões de negócio (documentadas; MVP honesto, consistente com §18/§19)

1. **"Pagamentos a verificar" = obrigações vencidas a verificar.** Não existe status "pagamento pendente de
   verificação" no domínio (pagamento é confirmado manualmente no registro). O item do dashboard mapeia ao
   alerta `ObrigacaoVencida` — obrigações que venceram e exigem verificar se houve pagamento (§14: "obrigação
   chegou ao vencimento e precisa ser verificada"). Contado por **caso** com o alerta.
2. **Honorários realizados por forma** (§18): `acrescido_divida` usa o `valorHonorarios` já separado no
   pagamento; `retido`/`cobrado_separado` usam `realizadosSobreRecuperacao` sobre a dívida recuperada;
   `sem_percentual` = 0. Nenhum honorário é persistido novo — tudo derivado do que já existe.
3. **Liquidações (não monetárias) contam como recuperação** de dívida (reduzem saldo; entram em
   `valorRecuperado`/`valorTotalRecuperado`), mas **não geram honorários realizados** (coerente com a decisão
   E3: liquidação sem rateio de honorários; recebimento efetivo é do futuro Financeiro §19).
4. **Recuperado no período vs total recuperado**: a visão financeira mostra o período; o resultado mostra o
   acumulado (all-time). `taxaRecuperacao = recuperado_total / (recuperado_total + em_aberto)`.
5. **Encerrados** entram no "valor recuperado" — tanto no acumulado (all-time) quanto no do período (uma
   recuperação dentro da janela é uma recuperação, independentemente de o caso ter sido encerrado depois);
   ficam fora de saldo em aberto/vencido, honorários projetados, alertas e contagens operacionais
   (naturalmente, via derivação — saldo 0 e `alertasDoCaso == []`).
6. **Regra da Etapa 7 intocada** (linhas só-encargos): esta etapa não importa nem altera obrigações.

## 6. Segurança (obrigatória em TODA rota)

- `can_access_module('cobrancas')` via `tenantComModulo()` nas 2 rotas (leitura → módulo basta, como na 8A GET).
- Tenant **explícito** em toda query (`doTenant` filtra por tenant; movimentos lidos só de casos do tenant).
- Anti-IDOR: filtro de carteira resolvido por `findOneByIdDoTenant`→404; nunca `find()`/`findOneBy(['id'])`.
- Sem vazamento: casos/movimentos/alertas de outro escritório nunca entram nos agregados (provado por teste
  cross-tenant DB).
- Sem escrita → sem CSRF. Nenhuma entidade Doctrine crua no Twig (só Output DTOs/arrays).

## 7. Testes

- **Unit `MontarDashboardCobrancaUseCaseTest`**: financeira (saldo aberto/vencido, recuperado no período com
  filtro de data, projetados, realizados por forma), resultado (total recuperado all-time, taxa, objetos
  inadimplentes vs casos ativos), encerrado fora do aberto/dentro do recuperado, filtro por carteira.
- **Unit `MontarCentralAlertasUseCaseTest`**: agrupamento por carteira, `totaisPorTipo`, encerrado sem alerta,
  escopo por carteira.
- **Functional `DashboardControllerTest`**: sem auth→redirect; sem módulo→`semAcesso`; render painel; render
  alertas; filtro por carteira; **IDOR** (carteira de outro tenant→404); **não-vazamento** (dados de outro
  tenant ausentes dos totais); empty states.
- **Isolamento multi-tenant** (DB real): coberto por `MontarDashboardCobrancaUseCaseTest::testTenantScoping`
  e `MontarCentralAlertasUseCaseTest::testTenantScoping` (dois tenants; A jamais soma B) + o não-vazamento
  no nível HTTP em `DashboardControllerTest` (`testAlertasNaoVazaOutroTenant` e
  `testPainelNaoVazaValoresDeOutroTenant`, que assere que valores de outro tenant não aparecem nos totais).
- Suíte `tests/Cobranca` verde + global verde + `tenant-safety-review`.

## 8. Fora do escopo (reafirmado)
Deploy, push, domínio Financeiro, mini-ERP, per-item ACL, alteração da regra E7, escrita de qualquer espécie.
