# O importe da contábil enxerga o caso JUDICIALIZADO

> Status: implementada, aguardando smoke do dono · Aberta em 2026-09-03 · Domínio `App\Cobranca` · **sem migration**
> Suíte: **4423/4423** · 1ª revisão adversarial feita e corrigida (A1, A2, M1–M4, B1–B6)

## 1. O defeito, medido em produção

Em 03/09/2026, ao simular o lote da contábil, a TOP LIFE I projetou **2.609 obrigações criadas**
contra apenas 175 atualizadas. TOP LIFE II e AMLI BR 060 projetaram **zero criadas** (601 e 38
atualizadas). A assimetria tem uma causa única e medida.

Entre 01/09 11:05 e 03/09 12:33 o escritório **judicializou 54 casos** da TOP LIFE I. Medições em
produção (MCP somente-leitura):

| Fato | Número |
|---|---|
| Casos `judicializado` na carteira 1 | 54 |
| Objetos com caso judicializado **e nenhum ativo** | 54 (zero têm os dois) |
| Casos `judicializado` nas carteiras 2 e 3 | 0 |
| Obrigações penduradas nesses casos | 4.822 — **todas com nosso-número** (100% vindas do importe) |
| Dessas, abertas e não substituídas | 2.946 (2.656 vencidas) |
| Principal aberto | R$ 477.394,72 (R$ 390.370,46 só o vencido) |
| Encargos congelados desde | 2026-08-27 18:42 (o último importe) |
| Boletos do arquivo de 03/09 pertencentes a essas 54 unidades | **2.593 de 2.747 (94%)** |

**Remedição em produção em 03/09, pedida pela 2ª revisão** (a premissa "0 objetos com mais de um caso"
era de 12/08, e o desempate novo nunca rodara em dado real):

| Fato | Número | O que decorre |
|---|---|---|
| Casos `ativo` / `judicializado` / `encerrado` | 429 / 54 / **0** | o caminho §17 é hoje inteiramente teórico em prod |
| Status fora do enum | **0** | a diferença `IN` × `!=` (§3.2) não tem lastro prático |
| Objetos com mais de um caso cobrável | **0** | **a ordenação ativo-antes-de-judicializado não muda nada hoje** — é defesa contra o estado que a duplicação criaria, não mudança de comportamento |

**Três** dos quatro importadores resolvem o caso do objeto por
`CasoCobrancaRepository::casosAtivosDoObjeto()`, que filtra `status = ativo` (o de cadastro de
condôminos não toca em caso — ver §3.4). Caso judicializado é invisível para eles. Não achando caso, o importe
de inadimplência e o de receitas **abrem um caso novo** (`AbrirCasoUseCase`) e recriam ali as
obrigações — a mesma dívida passa a existir duas vezes.

**Nada disso foi gravado.** A simulação parou antes, então não há duplicata a desfazer.

### O custo de continuar parado

Comparando o que a contábil reporta para essas 54 unidades nos dois lotes:

| | 27/08 | 03/09 | Diferença |
|---|---|---|---|
| Principal | R$ 379.396,37 | R$ 370.568,44 | **−R$ 8.827,93** (pago) |
| Encargos | R$ 144.374,22 | R$ 145.271,33 | +R$ 897,11 |
| Honorários | R$ 100.845,12 | R$ 101.013,21 | +R$ 168,09 |

R$ 8.827,93 de dívida já foi pago nessas unidades e o sistema ainda cobra. A divergência cresce a
cada lote — e viola a regra de que o sistema reflete exatamente a contabilidade.

## 2. A decisão

> **O que decide se um caso recebe movimento do importe é ele NÃO estar encerrado — não é ele estar
> ativo.** `ativo` e `judicializado` são estados de cobrança viva; `encerrado` é o único em que se
> decidiu parar de cobrar.

### Por que isto realinha o código com a spec, em vez de contrariá-la

Cinco documentos já dizem isso, e o código é que estava fora:

- **SPEC §16**: *"A judicialização não encerra a cobrança. Ela representa uma mudança de fase […]
  o caso continua acompanhando saldo, pagamentos, acordos e liquidações."*
- **SPEC §17**: a proibição de receber obrigação nova é **exclusiva do `encerrado`**.
- **`cobranca-etapa5-estados-judicializacao-alertas.md:19`**: *"Judicializar […] não encerra […]
  O acompanhamento financeiro continua."*
- **`cobranca-etapa9-dashboard-alertas.md:93`**: *"`casosAtivos` = nº de casos **não encerrados**
  (ativo + judicializado)."* — o dashboard já conta assim.
- **`ObrigacaoRepository::paraRecalculoDeEncargosDoCaso`** já filtra `status != encerrado`, ou seja
  **o recálculo de encargos já inclui judicializado**. O importe é que destoa.

Além disso, as **18 guardas de mutação** do domínio (`RegistrarObrigacaoUseCase`,
`RegistrarPagamentoUseCase`, `CriarAcordoUseCase`, …) barram apenas `estaEncerrado()`. Judicializado
passa em todas. O importe era a única peça com régua diferente.

O comentário em `ImportarAcordosDetalhadosUseCase:546` (*"`casosAtivosDoObjeto` já devolve só os
ATIVOS — caso encerrado não recebe obrigação (SPEC §17)"*) descreve uma regra que o código **não**
implementa: ele filtra `= ativo`, não `!= encerrado`. É raciocínio errado congelado em comentário,
não decisão registrada.

### Os textos que usam a palavra "ativo", e a retificação

🔴 **A 1ª versão desta spec dizia que havia UM texto contrário. São seis** — a 1ª revisão adversarial
pegou a afirmação falsa. A palavra *"caso ativo"* ficou espalhada por documentos escritos quando
`ativo` e `não encerrado` coincidiam na prática:

| Documento | Situação |
|---|---|
| `cobranca-importar-acordos-criar-acordo.md` — D2, R1, T5 e a linha das "38 de 38 abas" | ✅ retificado nesta frente |
| `cobranca-etapa2-caso-saldo.md` — storytelling do `AbrirCaso`, `saldoConsolidadoObjeto`, lista de testes | ✅ retificado |
| `cobranca-etapa3-pagamentos-honorarios.md` — fórmula do consolidado | ✅ retificado |
| `cobranca-importar-linhas-acordo.md` — resolução do caso | ✅ retificado |
| `gestao-cobrancas/PLAN.md` — fórmula do consolidado | ✅ retificado |
| **`gestao-cobrancas/FEATURE_GESTAO_COBRANCAS_SPEC_FINAL.md` §6 (invariável 6)** | ⏳ **decisão do dono** |

Sobre **D2** especificamente: o **espírito** — *"o relatório de Acordos NÃO abre cobrança nova"* —
fica **preservado**. Incluir judicializado não abre nada; apenas deixa de recusar onde a cobrança já
existe.

### A SPEC canônica §6 — por que ela não é contradita, e por que mesmo assim é do dono

O texto da §6 é:

> ### Modo A — uma cobrança **ativa** por objeto
> Enquanto existir um caso **ativo**, novas obrigações entram nele.
> **Depois de encerrado**, uma nova inadimplência cria um novo caso.

A terceira linha resolve a ambiguidade das duas primeiras: **o gatilho para nascer um caso novo já é o
encerramento**, não a ausência de `status = ativo`. Lida junto de §16 (*"judicializar não encerra a
cobrança"*) e §17 (só o encerrado deixa de receber obrigação), a §6 **sustenta** esta mudança — o que
ela tem é uma palavra ambígua, escrita quando os dois sentidos coincidiam.

Ainda assim a alteração do texto **não foi feita**: a §6 é o invariável 6 da spec-mãe do módulo, e
mudar redação de invariável é decisão do dono, não de quem implementa. Fica registrado aqui para
ratificação.

## 3. Escopo

### 3.1 O conceito, num lugar só

Nasce `StatusCaso::ehCobravel(): bool` — verdadeiro para `Ativo` e `Judicializado`, falso para
`Encerrado`. Espelha `StatusAcordo::ehVigente()`, que já existe e é usado com o mesmo propósito.

E `StatusCaso::cobraveis(): list<string>` deriva dele a lista de valores que as **duas** consultas do
repositório usam no `IN`. Isso não é enfeite: sem ele o `ehCobravel()` seria código morto e a régua
continuaria escrita à mão em cada DQL — que é exatamente o par destinado a divergir na próxima
manutenção. Com ele, um status novo no enum entra (ou não entra) nas duas consultas por decisão de um
lugar só.

> 🔑 A lição de `cobranca-importe-nao-duplica-devedor-do-cadastro.md:78-82` (*"a porta B era mais
> frouxa que a porta A… a correção nasce como um serviço único usado pelas duas portas"*) vale aqui:
> **uma** definição de "caso que recebe movimento", consumida por todas as portas. Por isso os
> métodos são **renomeados**, não duplicados — não fica um par `ativo`/`cobrável` para divergir na
> próxima manutenção.

### 3.2 Repositório — `CasoCobrancaRepository`

| Antes | Depois | Filtro |
|---|---|---|
| `casosAtivosDoObjeto()` | `casosCobraveisDoObjeto()` | `status IN (:cobraveis)` |
| `existeCasoAtivoParaObjeto()` | `existeCasoCobravelParaObjeto()` | `status IN (:cobraveis)` |

🔑 **É `IN (lista)`, não `!= encerrado`, e a diferença importa.** As duas coincidem enquanto o banco só
tiver valores do enum, mas divergem diante de um valor estranho: `!=` o incluiria (fail-open), o `IN`
o exclui. Isso pesa em `existeCasoCobravelParaObjeto`, que só faz `COUNT` e **não hidrata** — um valor
fora do enum não estouraria ali, seria silenciosamente contado como não-cobrável e a guarda deixaria
nascer o segundo caso. Medido em 03/09 (`saas_ux`): só `ativo` (243) e `judicializado` (5), nenhum
valor estranho.

**Ordenação determinística (novo).** `casosAtivosDoObjeto` não tem `ORDER BY`, e os chamadores pegam
`[0]`. Com um só ativo isso nunca mordeu; ampliando o conjunto, `[0]` viraria loteria. A nova consulta
ordena **ativo primeiro, depois o mais recente** (`criadoEm DESC, id DESC`) — a mesma prioridade que
`casoAncoraDoObjeto` já usa para a tela. Assim, **entre casos cobráveis**, tela e importe passam a
eleger o mesmo caso.

⚠️ Eles seguem divergindo de propósito num ponto, e isso é correto: num objeto que só tem caso
ENCERRADO a âncora cai no fallback de qualquer status e devolve o encerrado (deep-link da tela),
enquanto a resolução do importe devolve `[]` — encerrado não recebe movimento (§17).

> 🪤 O DQL recusa `COALESCE` no `ORDER BY`; a prioridade vai por `CASE WHEN`, que passa nos dois.

### 3.3 Os 7 chamadores

**Importadores (5) — passam a enxergar o judicializado:**

- `ImportarRelatorioCarteiraUseCase:105` (prever) e `:190` (confirmar)
- `ImportarReceitasUseCase:102` (prever) e `:166` (confirmar)
- `ImportarAcordosDetalhadosUseCase` (resolução do caso na criação de acordo) — e o comentário errado ao lado dela é reescrito; a
  mensagem da recusa R1 deixa de dizer *"não tem cobrança ativa aqui"*.

> ⚠️ **Prevér e confirmar mudam JUNTOS, sempre.** `ImportarReceitasUseCase:61-64` avisa que a
> paridade prévia×confirmação já foi quebrada duas vezes nesta frente, e a revisão adversarial da
> `cobranca-acompanhamento-canonico` registrou exatamente este defeito (achado I1) ao trocar a
> resolução do caso em só um dos modos.

**Guarda de escrita (1) — fecha o segundo furo:**

- `AbrirCasoUseCase` (a guarda do modo A) passa a usar `existeCasoCobravelParaObjeto`. Sem isso o defeito volta por
  qualquer outro caminho que abra caso.

**Saldo consolidado (1):**

- `CalculadoraSaldo::saldoConsolidadoObjeto`. Sem chamador de produção hoje (só o próprio teste), mas passa a somar
  judicializado — coerente com o dashboard, que já conta assim.

### 3.4 Fora de escopo (e por quê)

| Ponto | Por quê fica |
|---|---|
| `casoAncoraDoObjeto` | já resolve certo pelo *fallback* de qualquer status; é o que faz a tela funcionar com caso judicializado hoje |
| Facetas de status (`baseFiltro`, `MontarVisaoCarteiraUseCase::ESTADOS`, `CarteiraController`) | é o recorte que o usuário escolhe na tela; se `judicializado` trouxer ativo, a faceta deixa de facetar |
| As 18 guardas `estaEncerrado()` | já aplicam a régua §17 corretamente |
| `MontarDashboardCobrancaUseCase` | `casosAtivos` já é "não encerrado" |
| `ImportarCadastroCondominosUseCase` | não toca em caso; zero superfície |
| `ImportarAcordosDetalhadosUseCase:380` (guarda de encerrado) | precisa continuar: sem ela o `RegistrarObrigacaoUseCase` derruba o **lote inteiro** por uma aba |
| `ModoCarteira::Multiplo` na guarda do `AbrirCaso` | a guarda só vale no modo `Único`. **Medido: as 3 carteiras de produção são `unico`**, então o furo é teórico hoje. Removê-lo mexeria em forms de carteira — escopo que o dono não pediu. Fica **registrado como pendência**, não corrigido aqui |
| Desduplicar dados legados | **não há o que desduplicar**: nada foi gravado |

## 4. O que muda no comportamento (e o que move dinheiro)

1. **A dívida atualiza no lugar certo.** As ~2.609 obrigações passam a ser encontradas pela chave
   `(caso, nosso-número, competência)` no caso judicializado e **atualizadas**, em vez de recriadas
   num caso novo. `casosCriados` cai para perto de zero na TL1.
2. **Encargos são re-materializados** (`ImportarRelatorioCarteiraUseCase:261`) nas obrigações
   preexistentes, pelo retrato da planilha. É o comportamento correto e desejado, mas **move dinheiro
   em ~2.609 linhas na primeira rodada** — a prévia tem de mostrar isso antes de qualquer gravação.
3. **Acordos passam a nascer nas unidades judicializadas** — a recusa R1 deixa de disparar ali. Cada
   acordo criado congela encargos na data-base e marca contas originais como substituídas.
4. **O ramo de sacado divergente** (`ImportarRelatorioCarteiraUseCase:205-213`) passa a rodar num
   dado nunca exercitado; pode reportar divergentes no relatório do operador.

Nada disso é gravado sem autorização explícita do dono, por lote e por carteira.

## 5. Testes — o ponto mais frágil

**Hoje não existe um único teste de importação com caso `Judicializado`.** Nos 11 arquivos de teste
de importação, as únicas manipulações de status usam `Encerrado`. Traduzindo: a suíte fica verde com
a mudança certa **e** com a mudança errada. Construir essa rede é a maior parte do trabalho.

| # | Prova | Onde |
|---|---|---|
| T1 | Inadimplência: unidade com caso **judicializado** e sem ativo **atualiza** a obrigação existente; **não** cria caso nem obrigação | functional, importe de inadimplência |
| T2 | **Paridade prévia×confirmação** no mesmo cenário de T1: `casosCriados`/`obrigacoesCriadas` da prévia batem com a confirmação | functional |
| T3 | Receitas: recebimento de unidade judicializada casa na obrigação existente; `casosCriados` = 0 | ✅ `ImportarReceitasFluxoTest` |
| T4 | Receitas: paridade prévia×confirmação no cenário de T3, **por reflexão, campo a campo** | ✅ mesmo teste, via `achatar()` |
| T5 | Acordos: aba de unidade judicializada **deixa de ser recusada** por R1 e cria o acordo | ✅ `ImportarAcordosDetalhadosTest::testObjetoComCasoJudicializadoCriaAcordo` |
| T6 | Caso **encerrado** continua fora — provado nos **três** importadores alterados | ✅ inadimplência, receitas e acordos |
| T7 | `AbrirCasoUseCase` recusa abrir segundo caso quando já existe um **judicializado** | ✅ functional (contra o banco). O unit `AbrirCasoUseCaseTest` mocka o repositório e **não** prova nada sobre judicializado |
| T8 | `casosCobraveisDoObjeto` devolve **ativo antes de judicializado**, e o mais recente primeiro | ✅ functional (repositório contra o banco) |
| T9 | O importe de um escritório não reaproveita caso nem dívida do outro | ✅ functional — mas leia o docblock dele: NÃO isola o filtro `c.tenant` |
| T10 | `StatusCaso::ehCobravel()` / `cobraveis()` — decisão explícita por status, sem fail-open | ✅ `StatusCasoTest` (unit) |

**Prova por reintrodução — feita em 03/09/2026.** Cada mudança foi desfeita uma a uma, com a suíte
rodando em cima:

| # | Defeito reintroduzido | Resultado |
|---|---|---|
| 1 | resolução do caso volta a `= ativo` (**o defeito de produção**) | ✅ vermelho |
| 2 | resolução passa a aceitar até o `encerrado` (violaria §17) | ✅ vermelho |
| 3 | some a prioridade ativo-antes-de-judicializado | ✅ vermelho |
| 4 | guarda do `AbrirCaso` volta a contar só `ativo` | ✅ vermelho |
| 5 | some o `c.tenant` de `casosCobraveisDoObjeto` | ⚠️ **ficou VERDE** |
| 6 | **receitas** — régua volta a `= ativo` | ✅ vermelho |
| 7 | **receitas** — régua passa a aceitar o `encerrado` | ✅ vermelho |
| 8 | **acordos** — régua volta a `= ativo` (T5b cai) | ✅ vermelho |
| 9 | **acordos** — régua passa a aceitar o `encerrado` (T5 cai) | ✅ vermelho |

As provas 6–9 fecham o furo apontado pela 1ª revisão: a frente consertava três importadores e provava
só um, e o não provado (`ImportarReceitasUseCase`) é o **outro** que abre caso — o mecanismo exato da
dívida duplicada.

🔑 A prova 6 revelou o desenho funcionando em camadas: com a régua antiga o importe de receitas tenta
abrir o segundo caso e é a **guarda do `AbrirCaso`** que o barra (`CasoCobravelJaExisteException`).
Fosse só o repositório corrigido, o defeito passaria; fosse só a guarda, o importe quebraria em vez de
atualizar. Os dois furos precisavam fechar.

🔴 **Duas lições ficaram registradas, e as duas eram armadilhas reais:**

- A prova 3 saiu **verde na primeira tentativa** porque o cenário era fraco: o teste criava o ativo
  por último, então `criadoEm DESC` já o punha em primeiro sem precisar da prioridade. Só passou a
  discriminar quando o ativo passou a nascer ANTES do judicializado.
- As provas 1 e 2 saíram vermelhas **pelo motivo errado** na primeira tentativa: a mutação deixava um
  `setParameter` órfão e o Doctrine estourava antes de qualquer asserção. Refeitas sem o órfão.

**A prova 5 não é corrigível com cenário realista, e isso é um achado, não uma pendência.** A consulta
casa por `c.objeto = :objeto`; o objeto já é tenant-bound, e os objetos de dois escritórios são linhas
diferentes — o caso do vizinho nunca entra, com ou sem o filtro. O `c.tenant` ali é **defesa em
profundidade**; a barreira real é o `findOnePorIdentificacaoNaCarteira` do importe, que o T9 exercita.
Registrado no docblock do próprio T9 para ninguém ler o nome do teste e concluir cobertura que não
existe.

### Testes existentes afetados

- `CalculadoraSaldoTest:180` e `AbrirCasoUseCaseTest:88,175,249` — mockam os nomes antigos; quebram
  por compilação e são atualizados.
- `ImportarAcordosDetalhadosTest:1011` `testObjetoSemCasoAtivoRecusa` — continua verde (usa
  `Encerrado`), mas o **nome passa a mentir**; é renomeado para refletir "sem caso não encerrado".
- `CalculadoraSaldoTest:171` — mesmo caso, nome com "CasosAtivos".

## 6. Critério de conclusão

1. Suíte completa verde no container principal.
2. Prova por reintrodução feita e registrada nos pontos de T1–T8.
3. `/review` pelo `feature-review-agent` contra esta spec, e correções aplicadas.
4. **Nova simulação da TL1 em produção**, com os números lado a lado contra a de 03/09:
   obrigações criadas devem cair de 2.609 para perto de zero e as atualizadas subir.
5. O dono olha os números e decide sobre a gravação. **Nenhum `--confirmar` sem essa autorização.**

## 7. Pendências registradas (não corrigidas aqui)

- **`ModoCarteira::Multiplo` desliga a guarda do `AbrirCaso`** (`AbrirCasoUseCase:68`). Teórico hoje
  (3/3 carteiras em `unico`), real se alguém criar carteira em modo múltiplo.
- **Nada impede, no banco, um objeto ter dois casos não encerrados.** A premissa "1 caso por objeto"
  foi medida em 0/504 (`cobranca-espelho-da-contabilidade.md:415-427`) e é sustentada só por guarda
  de aplicação. Um índice único parcial resolveria — mas exige migration, e frente com migration vai
  uma de cada vez enquanto a `cobranca-acompanhamento-canonico` estiver parada e viva.
- **`casoAncoraDoObjeto` loga aviso com >1 ativo, mas não com 1 ativo + 1 judicializado.** Se a
  duplicação ocorrer por outro caminho, ela é silenciosa.

## 8. Relação com a frente parada `cobranca-acompanhamento-canonico`

Aquela frente **já havia diagnosticado e corrigido este mesmo defeito**
(`EXECUCAO_ACOMPANHAMENTO_CANONICO.md:652-657`), inclusive apontando que a correção exige os **dois**
pontos (repositório + guarda do `AbrirCaso`) — o que esta spec adota.

Ela **não** é revivida: está 23 commits à frente e **542 atrás** de `origin/master`, carrega 4
migrations pendentes e um achado bloqueante em aberto. Este conserto não pede migration e não
conflita com ela. A frente segue parada e registrada em `docs/frentes-ativas.md`.
