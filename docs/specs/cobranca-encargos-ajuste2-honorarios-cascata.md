# Spec curta — Ajuste 2: honorários editáveis no CASO e na OBRIGAÇÃO (cascata)

> Rodada pós-go-live da feature "Encargos separados e configuráveis em cascata" (`App\Cobranca`).
> Risco **ALTO** (dinheiro). Módulo **em produção**. Complementa a spec-mãe
> `docs/specs/cobranca-encargos-configuraveis-cascata.md` (§7 honorários, §11 UI) — aqui só o delta do Ajuste 2.
> Branch: `cobranca-encargos-cascata`. Push/merge/deploy = humano.

## 1. Pedido do dono (o quê e por quê)

Hoje os honorários só são editáveis na **Carteira**. O caso snapshota `formaHonorarios`+`percentualHonorarios`
no nascimento (ex.: um caso nasceu com 10% e ficou **preso** nesse valor mesmo depois de a carteira ir a 20%).
Não há como corrigir. O dono quer:

1. **Editar os honorários no CASO** (a tela do objeto mostra o caso âncora) — forma, %, base e carência.
2. **Editar o honorário por OBRIGAÇÃO** individual, com o **campo de honorário junto dos encargos** nos modais
   de registrar/editar obrigação (hoje só juros/multa/correção têm campo).

## 2. Descobertas da investigação que travam o desenho (fonte: Explore read-only)

- **Cascata real = Carteira → Caso → Obrigação.** `ObjetoCobranca` **não tem coluna de config nenhuma**; o
  "objeto" da cascata é resolvido como `caso.getObjeto().getCarteira()`. A tela do objeto opera **1 caso âncora
  por objeto** (`CasoCobrancaRepository::casoAncoraDoObjeto`). Logo **"editar no objeto/caso" = editar o caso
  âncora** — **zero coluna nova**.
- **Caso já tem as 4 colunas de honorário:** `formaHonorarios` (NOT NULL, snapshot), `percentualHonorarios`
  (`?string` decimal(5,2), snapshot), `baseHonorarios` (`?BaseEncargo`, override nullable=herda),
  `carenciaHonorariosDias` (`?int`, override nullable=herda). Editar = só `set*`, espelhando
  `EditarConfiguracaoCarteira*` (subconjunto de honorários).
- **Obrigação:** `honorarios` (int centavos, **materializado**, fora do exigível), `encargosCongeladosEm`
  (`?DateTimeImmutable`). Override cascata só de `baseHonorarios`/`carenciaHonorariosDias`; **não há** override
  de forma/percentual/taxa de honorário na obrigação (decisão D2 — sem coluna de taxa paralela). O honorário R$
  já é coluna; falta só o **campo no form**.
- **Resolvedor:** a **taxa** de honorário (`taxaHonorariosBp`) da obrigação vem SEMPRE do snapshot do caso
  (`ResolvedorConfigEncargos`), derivada de forma+percentual; `base`/`carência` herdam campo a campo.
- **UseCases da obrigação (Ajuste 1):** `Registrar`/`Editar` decidem *automática vs congelada* por
  `encargosCongeladosEm`. `mexeuManual` compara juros/multa/correção **componente a componente**; ao digitar,
  o motor **completa** os honorários (`CalculadoraEncargos::honorarios()`) sobre a base digitada e **congela**.
  O guard do exigível usa **só j+m+c** (honorário fora, INV-E2).
- **Espelho %↔R$ (JS `initEspelhoEncargos`):** lê a base de **um** input (`data-encargo-base="valorOriginal"`).
  O honorário incide sobre **base composta** (P+j+m+c) — o JS atual **não** soma campos. Contrato travado por
  `tests/Cobranca/Functional/ObjetoShowContratoJsTest.php`.
- **INV-E2 preservado hoje:** `CalculadoraSaldo`, `AutoAlocadorFifo`, `MontarDetalheAcordoUseCase`, Dashboard
  **não leem** `honorarios`. O prefill do "Receber" usa `CalculadoraHonorarios` (projeção), não o campo.

## 3. Decisões (defaults adotados — o humano pode vetar na revisão)

- **D-A2-1** "Editar no objeto/caso" = editar a config do **caso âncora**. **Nenhuma coluna nova** em nenhuma
  entidade. Não se cria nível de config no `ObjetoCobranca`.
- **D-A2-2** O form de config do caso edita **só honorários** (forma, %, base, carência) — é o pedido explícito.
  Juros/multa/correção por caso ficam fora do escopo (o caso os herda da carteira; override por caso é follow-up).
- **D-A2-3 (dinheiro)** Ao salvar a config de honorário do caso, **recalcular imediatamente** os honorários das
  obrigações **automáticas** (não congeladas) e **vivas** (mesmo conjunto que o cron recalcula) do caso, para
  **hoje** — coerência com o Ajuste 1 ("recalcula na hora, não espera o cron"). Congeladas **intactas** (INV-E4).
  **Sem o freio de redução** do cron: baixar o % é decisão deliberada do gestor; reduzir honorário aqui é
  esperado. Estourou (`EncargosInexequiveisException`)? pula **aquela** obrigação e segue.
- **D-A2-4** Honorário na obrigação fica **FORA do `valorExigivel`/saldo** (INV-E2) — confirmado pela diretriz.
  Não toca `CalculadoraSaldo`/FIFO/Acordo/Dashboard.
- **D-A2-5** Campo de honorário na obrigação: **vazio = automático** (o motor completa/recalcula, comportamento
  de hoje preservado); **digitado = override + congela** (o gestor fixou aquele honorário). Espelho **%↔R$
  sobre base composta** (P+j+m+c), consistente com os outros três encargos. `honorarios` no DTO é `?int`
  (`null` = não informado ≠ `0` = zero explícito).
- **D-A2-6** Editar a config de honorário do caso **também muda** o rateio de pagamento e o "bruto sugerido" do
  "Receber" (ambos leem o mesmo snapshot via `CalculadoraHonorarios`). Isso é **desejado** (consistência), não
  bug. Registrar no texto de ajuda do modal.
- **D-A2-7** Guard de encerrado: o form de config do caso **não** é bloqueado por caso encerrado (é metadado de
  config, não movimento financeiro); mantém apenas a **guarda multi-tenant** (caso por id+tenant). Recalcular
  obrigações de caso encerrado é inócuo (não há automáticas vivas típicas), mas o loop respeita os predicados.

## 4. Fatiamento (duas fatias SEQUENCIAIS — compartilham `_acoes_modais.html.twig` e `objeto/show.html.twig`)

Cada fatia: `feature-implementer` (worktree) → `feature-review-agent` (read-only) → orquestrador integra
(cherry-pick individual) → testes direcionados → suíte → **SMOKE visual** (orquestrador). Fatia B só começa
depois de A integrada e estável.

### Fatia A — Editar honorários do CASO (config + recálculo imediato)

**Novos arquivos (espelhar `EditarConfiguracaoCarteira*`, subconjunto de honorários):**
- `DTO/EditarConfiguracaoCasoInput.php` — `?int $casoId`, `FormaHonorarios $formaHonorarios`,
  `?string $percentualHonorarios` (Regex `^\d{1,3}(\.\d{1,2})?$`), `?BaseEncargo $baseHonorarios`,
  `?int $carenciaHonorariosDias` (`PositiveOrZero` + `LessThanOrEqual(3650)`). Sem juros/multa/correção.
- `Form/EditarConfiguracaoCasoType.php` — `formaHonorarios` EnumType, `percentualHonorarios` **PercentualType**
  (required false), `baseHonorarios` EnumType, `carenciaHonorariosDias` IntegerType (required false, placeholder
  "Vazio = usa a carência da carteira"). `casoId` **não** é campo (vem da rota). `data_class` = o Input.
- `UseCase/EditarConfiguracaoCasoUseCase.php` —
  - resolve caso por id+tenant (`CasoCobrancaRepository::findOneByIdDoTenant`); null → `CasoNaoEncontradoException`.
  - `set*` os 4 campos de honorário.
  - **Recálculo imediato (D-A2-3):** para cada obrigação do caso que o cron recalcularia (não congelada +
    "dívida viva"/exigível — reusar o MESMO predicado da F3, escopado ao caso; ver `AtualizarEncargosCommand`/
    `ObrigacaoRepository`), resolve `ConfigEncargos` (já reflete o caso novo), `CalculadoraEncargos::calcular(...)`
    para `hoje`, `definirEncargos(j,m,c,h,hoje)` **sem** `congelarEncargos`. **Sem freio de redução.** Envolve
    `EncargosInexequiveisException` **por obrigação** (pula e conta). `flush` único ao fim.
  - Retorna o caso; controller dá flash com a contagem recalculada.
- Rota no `CasoController`: `#[Route('/casos/{id}/configuracao-honorarios', name: 'cobranca_caso_editar_config',
  methods: ['POST'], requirements: ['id' => '\d+'])]`. Gate `resources.cobranca.gerenciar`. Em erro de validação,
  usar o mesmo mecanismo de **B5/erro-modal** dos outros modais do caso (reabrir preenchido) — seguir o padrão de
  `RegistrarObrigacao`/`EditarObrigacao` no controller.

**Alterados:**
- `Service/MontadorModaisCaso.php` — `deMutacao(...)` passa a montar a view do `EditarConfiguracaoCasoType`
  pré-carregada com os valores atuais do caso (forma/%/base/carência), como o modal da carteira faz.
- `templates/cobranca/caso/_acoes_modais.html.twig` — novo modal `#modalEditarConfigCaso` (action
  `path('cobranca_caso_editar_config', {id: caso.id})`), com os 4 campos e um texto de ajuda explicando D-A2-6
  (mudar o % aqui recalcula as automáticas e afeta o rateio do "Receber").
- `templates/cobranca/objeto/show.html.twig` — botão "Editar honorários" **junto ao bloco de honorários do
  cabeçalho** (onde aparece "Honorários acrescidos à dívida (X%)"), abrindo o modal. Preservar o gate
  `podeGerenciar`.

**Testes (a implementer ESCREVE; orquestrador roda no container após integrar):**
- Unit `EditarConfiguracaoCasoUseCaseTest`: (a) altera os 4 campos; (b) recalcula honorário de automática viva
  (ex.: 10%→20% sobe o honorário); (c) **congelada não é tocada** (INV-E4, prova por mutação); (d) redução
  (20%→10%) **é aplicada** (sem freio); (e) guarda multi-tenant (caso de outro tenant → exceção).
- Functional `CasoConfigHonorariosControllerTest` (ou estender o existente de mutação do caso): POST válido
  redireciona + persiste + recalcula; POST inválido reabre o modal com erro; sem capacidade → 403/redirect.

**Fora do escopo da Fatia A:** juros/multa/correção por caso; nível de config no objeto; qualquer mudança em
saldo/FIFO/Acordo/Dashboard; tocar o cron/o freio de redução (só **reusar** o predicado de seleção).

### Fatia B — Campo de honorário na OBRIGAÇÃO (register + edit)

**Alterados — DTO/Form:**
- `DTO/RegistrarObrigacaoInput.php` e `DTO/EditarObrigacaoInput.php` — novo campo `public ?int $honorarios = null`
  (centavos; `null` = não informado). Validação `PositiveOrZero` quando não-nulo.
- `Form/RegistrarObrigacaoType.php` e `Form/EditarObrigacaoType.php` — `honorarios` via **CentavosType**
  (`required => false`, sem `empty_data` → mapeia `null` quando vazio). Mesmo `data-encargo`/atributos dos outros
  R$ (ver macro).

**Alterados — Twig/JS:**
- `templates/cobranca/caso/_acoes_modais.html.twig`, macro `camposEncargos` — acrescentar **Honorários** como
  4º par, mas com `data-encargo-base="composta"` (não `valorOriginal`). Ajuste do texto: "Honorário incide sobre
  a base composta (valor + juros + multa + correção). Deixe **vazio** para calcular automaticamente."
- `templates/cobranca/objeto/show.html.twig`, `initEspelhoEncargos` — suportar base composta:
  quando `data-encargo-base="composta"`, `baseCentavos = Σ parseCentavos(valorOriginal, juros, multa, correcao)`
  dos inputs do modal; re-sincronizar o **%** do honorário quando **qualquer** um dos quatro muda (hoje só
  escuta um `base`). O R$ do honorário continua a **fonte de verdade** (submetido); o % é auxiliar sem `name`.
  Preservar o reset-on-close e o `data-preenche-ao-abrir` do editar.

**Alterados — UseCases (a lógica de dinheiro):**
- `RegistrarObrigacaoUseCase`: `manual = (juros>0 || multa>0 || correcao>0 || honorarios !== null)`. Se manual:
  `hFinal = honorarios ?? calculadora->honorarios(P, juros, multa, correcao, config, dias)`; `definirEncargos(...)`
  + `congelarEncargos`. Senão automática (motor calcula os 4).
  *(F4 bloqueante preservado: honorário vazio ⇒ motor completa, nunca 0-para-sempre.)*
- `EditarObrigacaoUseCase`: `mexeuManual = jChanged || mChanged || cChanged || (honorarios !== null)`.
  Ramo manual: `hFinal = honorarios ?? calculadora->honorarios(P, jFinal, mFinal, cFinal, config, dias)`;
  congela. Ramo automática (sem mexida): motor recalcula os 4 (Ajuste 1 intacto — editar vencimento recalcula).
  Ramo congelada sem mexida: mantém. **Honorário NÃO entra no guard `$novoExigivel`** (INV-E2). O campo
  `honorarios` **não** é pré-preenchido no editar (fica vazio, placeholder "vazio = automático"), para que
  `null` signifique inequivocamente "automático" e o comportamento do Ajuste 1 seja preservado ao mexer só no
  vencimento.

**Testes (implementer ESCREVE):**
- Unit `RegistrarObrigacaoUseCaseTest`/`EditarObrigacaoUseCaseTest`: (a) honorário vazio → automático (motor
  completa/recalcula, como hoje); (b) honorário digitado → usa o valor, congela; (c) editar só o vencimento com
  honorário vazio → recalcula (Ajuste 1 intacto, prova por mutação); (d) honorário **fora** do exigível — guard
  `ValorAbaixoDoAlocado` não conta honorário (prova: honorário alto não altera o guard).
- Functional `ObrigacaoMutacaoControllerTest`: POST com honorário; `ObjetoShowContratoJsTest` estendido para o
  par honorário/base composta.

## 5. Invariantes a NÃO afrouxar (revisão confere contra isto)

- **INV-E2** honorário fora do `valorExigivel()` e de todo o maquinário de saldo — em ambas as fatias.
- **INV-E4** obrigação congelada nunca é tocada — nem pelo recálculo da Fatia A nem por edição sem mexida.
- **INV-E1** `valorExigivel = valorOriginal + juros + multa + correcao` — inalterado.
- **Ajuste 1 intacto** — editar só o vencimento de uma automática recalcula na hora; o campo de honorário vazio
  não pode quebrar isso.
- **Multi-tenant** — caso/obrigação sempre por id+tenant; nada de IDOR nas rotas novas.
- **Sem regressão** — `tests/Cobranca` verde (baseline 772) + global.

## 6. Riscos conhecidos (a revisão deve mirar)

1. **Conjunto do recálculo (Fatia A):** recalcular obrigação que NÃO deveria (parcela de acordo, substituída,
   paga) moveria dinheiro indevidamente. Reusar o **mesmo predicado** da F3 (dívida viva/exigível), escopado ao
   caso — não reinventar.
2. **Base composta no JS (Fatia B):** somar campos errados ou não re-sincronizar em uma das 4 entradas calcula %
   sobre base errada, em silêncio, sobre dinheiro. Cobrir no `ObjetoShowContratoJsTest`.
3. **Empty vs zero (Fatia B):** `honorarios` DEVE ser `?int` — `null` (vazio) ≠ `0` (zero explícito). Vazio =
   automático; `0` digitado = honorário zero fixo (congela). Não colapsar os dois.
4. **Redução sem freio (Fatia A):** correto aqui (config deliberada), mas confirmar que o freio do **cron**
   continua ativo (não removê-lo; a Fatia A só não o usa no seu próprio loop).
