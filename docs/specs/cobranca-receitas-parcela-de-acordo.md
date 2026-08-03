# SPEC — Etapa 3: o recebimento nasce como PARCELA DE ACORDO (+ D6)

**Risco ALTO.** Aberta em 2026-08-03.
Spec-mãe: `docs/specs/cobranca-importar-receitas.md` (§11 é o achado que define esta etapa).
D6: `docs/specs/cobranca-cancelar-acordo.md` §3.2.
Handoff de estado: `docs/gestao-cobrancas/HANDOFF_IMPORTAR_RECEITAS.md`.

⛔ **A importação de Receitas está TRAVADA até esta etapa fechar** (decisão A3). Rodar `--confirmar`
antes criaria 187 obrigações avulsas que esta etapa teria de desfazer.

---

## 1. O buraco, em uma frase

O `TopLifeReceitasAdapter` **já lê** a coluna J e produz `AcordoDoRelatorio(numero, parcelaIndice,
parcelaTotal)` (`TopLifeReceitasAdapter.php:313-320`). O `ImportarReceitasUseCase` **nunca lê**
`$receita->acordo` — o campo existe em `ReceitaImportavel.php:34` e morre ali.

Consequência: **187 recebimentos** que são parcela de acordo (160 TL I + 27 TL II) virariam obrigações
avulsas "Taxa MM/AAAA", soltas na fila de cobrança, sem vínculo com o acordo que as gerou.

## 2. A régua de medição (leia antes de acreditar em qualquer número da §3)

Cinco "fatos medidos" da spec-mãe caíram ao serem remedidos, e a minha própria primeira medição errou.
Por isso, **todo número desta spec diz como foi obtido**, e todos foram conferidos contra algo externo.

**Como:** scripts descartáveis em `docs/gestao-cobrancas/planilhas atualizadas/_medir_acordos_etapa3*.php`
(pasta **gitignored**, PII — nunca commitar), lendo os arquivos de **03/08** com PhpSpreadsheet, agrupando
por `(unidade, NN)` — a **mesma chave do adapter** — e casando a coluna J com a **mesma regex** do código
(`TopLifeReceitasAdapter::REGEX_ACORDO`).

**Conferência externa:** o relatório imprime o próprio gabarito no rodapé. A soma da coluna I sobre todos
os grupos recebidos e de líquido positivo deu **R$ 243.013,53** (TL I) e **R$ 136.898,49** (TL II) —
**bate ao centavo** com "Total de receitas das unidades". É essa conferência que valida o parser; sem ela
os números da §3 não valeriam nada. (Ela já pegou um defeito real: a primeira versão do meu parser tratava
célula numérica como texto e multiplicava tudo por 100.)

**⚠️ Fato medido tem prazo curto nesta fonte.** Se estas linhas passarem de um ou dois dias, remeça antes
de decidir. Os arquivos usados são os de 03/08 09:48–09:54, os quatro da mesma data.

## 3. O que foi medido (2026-08-03)

### 3.1 O universo

| | TL I | TL II | total |
|---|---|---|---|
| grupos `(unidade, NN)` recebidos | 1.220 | 858 | 2.078 |
| — **que são parcela de acordo** (coluna J casada) | **160** | **27** | **187** |
| — avulsos (coluna J vazia ou fora do formato) | 1.060 | 831 | 1.891 |
| linhas com "acordo" na J que a regex **não** casa | 0 | 0 | **0** |

Quatro propriedades que sustentam a chave, todas remedidas hoje e todas de pé:
**nenhum NN em dois acordos** · **nenhum acordo cruzando unidade** · **nenhum acordo com dois
`parcelaTotal` diferentes** · **nenhuma parcela com dois NNs**.

### 3.2 🔑 Dois denominadores — e o que a §11.2 da spec-mãe confundiu

| denominador | acordos citados | com aba em "Acordos detalhados" |
|---|---|---|
| todos os grupos com coluna J (**inclui parcela não paga**) | 127 | 48 |
| **só grupos com parcela PAGA** — é o que a A1 manda criar | **106** | **27** |

A §11.2 da spec-mãe corrigiu a §1 de "106 citados" para "127", tratando o primeiro número como errado.
**Os dois estão certos, em denominadores diferentes.** Esta etapa cria acordo a partir de parcela **paga**
(A1), então o número que governa é **106**.

Na TOP LIFE II a cobertura pelo relatório de Acordos é **zero**: as 8 abas dela (9, 21, 28, 31, 32, 34,
37, 39) não são citadas por nenhuma parcela paga.

### 3.3 🔑 "79 sem fonte completa" está certo na conta e errado na consequência

Dos **106** acordos a criar:

| | |
|---|---|
| **já quitados** (parcelas pagas ≥ `parcelaTotal`) — a Receitas dá a informação **completa** | **75** |
| parciais, **com** aba no Acordos detalhados (há fonte para as parcelas futuras) | **27** |
| parciais, **sem** fonte nenhuma para as futuras | **4** |

Os 4 órfãos: acordo **212** (1 de 20 pagas), **230** (1 de 28), **237** (4 de 20), **280** (1 de 10) —
**71 parcelas** que nenhum dos três relatórios traz.

O buraco real não são 79 acordos: são **4**.

⚠️ **E elas não são "futuras", como esta seção dizia.** Medido depois da importação no dev: o export é
filtrado por **vencimento**, então as parcelas que faltam podem ser **anteriores** à janela — já pagas.
Nos 31 incompletos, **12** têm a menor parcela paga maior que 1, e **7** têm a **última** paga (o acordo
terminou; só a régua conservadora da §5.3 o mantém `Ativo`). Três dos quatro órfãos estão nesse caso:
o **212** tem a parcela 20/20 paga, o **280** a 10/10, o **237** as 17 a 20. Só o **230** (parcela 19 de
28) está de fato no meio.

Isso muda o que se pede à contábil: não é "as parcelas futuras", é **o extrato completo do acordo**.

### 3.3.1 🔑 A lacuna dos acordos é um FILTRO do export, não falta de dado

Achado em 04/08, lendo a linha `Filtros:` do rodapé — a mesma linha que já tinha explicado dois números
caídos da spec-mãe, e que ninguém tinha lido de novo.

| relatório | filtro declarado no rodapé |
|---|---|
| **Acordos detalhados** (as duas carteiras) | `Situação do acordo: **Em andamento**` |
| **Receitas detalhadas** | `Período de vencimento: **01/01/2026 a 01/01/2027**` |
| Inadimplências | `Inadimplência até: 03/08/2026` |

**As 74 abas do relatório de Acordos são TODAS "Em andamento"** (66 na TL I + 8 na TL II, medido aba a
aba). Acordo já quitado foi **excluído do export**.

Isso explica a cobertura quase inteira, sem sobrar quase nada:

- dos **106** acordos com parcela paga, **75 estão quitados** → não estão no export "Em andamento";
- dos **31** parciais, **27 têm aba**;
- os **4 sem aba** são **3 que terminaram** (212, 237, 280 — última parcela paga, logo fora do filtro)
  **+ o 230**, o único que está de fato em andamento e ainda assim não tem aba.

🔑 **A ação não é pedir extrato à contábil nem raspar o sistema deles: é reexportar o MESMO relatório com
o filtro de situação em "Todos".** O dado existe e está a um clique.

E o filtro da Receitas (`vencimento em 2026`) é a prova documental de por que faltam parcelas
**anteriores**: as de 2024/2025 estão fora da janela por construção, não por erro.

### 3.4 Rodar o importador de Acordos detalhados antes NÃO resolve — medido

1. **Ele não cria acordo, por decisão de spec.** `ImportarAcordosDetalhadosUseCase.php:200-204`: se não
   acha por `numero_externo`, devolve `abaIgnorada` — "quem cria acordo é o relatório de inadimplência"
   (`cobranca-importar-acordos-detalhados.md:65-66` e §5). Rodá-lo antes criaria **zero** dos 106.
2. **A Inadimplência — único importador que cria acordo hoje (`ImportarRelatorioCarteiraUseCase.php:295`)
   — cobre 11 dos 106.** Faz sentido: parcela paga sai da inadimplência.
3. **A ordem útil é a inversa:** Receitas cria os 106 → depois o Acordos detalhados completa as parcelas
   futuras dos 27 que têm aba (é o que `completarParcelas` já faz).

### 3.5 Não há cobrança em dobro — medido, porque era o furo óbvio

As 27 abas listam **2.013 contas originais** consolidadas nesses acordos. **Nenhuma** delas aparece na
Inadimplência nem na Receitas: as dívidas que o acordo consolidou não entram no sistema por caminho
nenhum. Logo o acordo criado aqui **não passa a somar em cima de dívidas que já existem**.

⚠️ **Resíduo a conferir em produção:** a medição acima é sobre os *relatórios*. Se uma importação
**anterior** tiver trazido alguma dessas contas (quando ainda estavam inadimplentes), elas existiriam como
obrigação. No dev não dá para medir — só a TOP LIFE II está carregada, e ela tem 0 abas entre os 26.
Conferir antes do `--confirmar` em prod.

### 3.6 O dinheiro que muda de forma

Das 187 parcelas, **85 têm juros/multa** (classes 1.4/1.5 — foram pagas com atraso).

| | valor | como foi medido |
|---|---|---|
| bruto recebido nas 187 | **R$ 92.187,81** | Σ coluna I dos 187 grupos |
| — juros e multa (1.4 + 1.5) | R$ 5.571,25 | Σ coluna I das linhas 1.4/1.5 |
| **= `valorOriginal` das 187 parcelas** | **R$ 86.616,56** | bruto − juros/multa |

Classes presentes nas parcelas de acordo: `1.1` (150×), `1.14` (65×), `1.15` (66×), `1.4` (84×),
`1.5` (71×), `1.6` (23×).

**O total recebido não muda** — os R$ 379.912,02 e os oito números da conferência contábil (spec-mãe
§8.1) seguem valendo intactos. O que muda é a **forma** de 187 obrigações.

### 3.7 Estado do banco dev

⚠️ **A primeira versão desta seção mediu o banco ERRADO** e dizia que só existia uma carteira, chamada
"TOP LIFE II", e que havia 7 acordos com `numero_externo`. O app do dev lê **`saas_ux`**, não `saas`
(`app/.env.local`, não versionado) — o `saas` é um banco antigo que ficou para trás. Medido no `saas_ux`:

| | |
|---|---|
| carteiras | `id=1 TOP LIFE I` · `id=2 TOP LIFE II` |
| obrigações | 3.431 |
| **pagamentos** | **0** — nada da etapa 2 foi gravado |
| acordos | 10 |
| — com `numero_externo` | **8** (9, 21, 28, 31, 32, 34, 37, **39**), todos na carteira 2, todos `ativo` |
| — sem `numero_externo` | 2, ambos `cancelado` |

A spec-mãe §11.2 estava certa ao dizer **8**. Os 2 cancelados não têm `numero_externo`, então o
importador nunca os encontra: **D6 não tem caso alcançável no dev** e precisa de cenário montado em teste.

🔑 A lição vale além desta seção: eu tinha [[project_ponto_horas_pagas]] registrando exatamente isto — *no
dev o app lê `saas_ux`* — e mesmo assim medi contra `saas`. É o segundo caso nesta frente de resposta que
já estava na memória.

## 4. Decisões

### 4.1 Do dono, em 03/08 (contrato — não reabrir)

| # | Decisão |
|---|---|
| **A1** | Parcela paga ⇒ **o acordo existe e tem de ser criado**. Não se cria "só a parcela". |
| **A2** | Status **`Ativo`**; só não é ativo se já terminou de ser pago — aí **`Cumprido`**. |
| **A3** | A etapa 2 fecha como está. Isto é a etapa 3, junto com D6. |

### 4.2 Do dono, ao abrir esta etapa

| # | Decisão | Efeito medido |
|---|---|---|
| **B1** | Os 4 acordos parciais sem fonte **nascem só com as parcelas pagas**, e o comando lista os 4 com quantas parcelas faltam. Nada é sintetizado. | 71 parcelas futuras ficam de fora, visíveis no resumo |
| **B2** | A parcela de acordo usa a **soma de todas as classes menos juros/multa** como `valorOriginal`, e **honorário zero** — o precedente de `ImportarRelatorioCarteiraUseCase.php:479-481` | R$ 86.616,56 em 187 obrigações; as 37 sem principal deixam de nascer R$ 0,00 |
| **B3** | **D6 entra só no caminho de Receitas** agora. O importador de Inadimplência não é tocado. | escopo menor, código de produção intacto |
| **B4** | Ao final, `--confirmar` **no DEV** para provar o caminho de escrita ponta a ponta | produção continua do dono |

### 4.3 Derivadas do código existente (precedente, não decisão nova)

| # | Regra | Precedente |
|---|---|---|
| C1 | busca do acordo por `(numeroExterno, carteira, tenant)` | `AcordoRepository::findOnePorNumeroExternoNaCarteira` — já filtra tenant **e** carteira |
| C2 | `dataAcordo` = 1º dia da competência da parcela, fallback vencimento | `ImportarRelatorioCarteiraUseCase::dataAcordoPadrao` |
| C3 | `valorTotalNegociado` só quando `parcelaTotal === 1`; senão `null` | idem, `:303-305` — não inventar total que a fonte não dá |
| C4 | `numeroParcelasTotal` = `parcelaTotal` da coluna J | idem |

**Sobre C1 e a colisão entre carteiras:** as abas **31 e 32** existem nas duas carteiras e são acordos
diferentes. O índice `(tenant_id, numero_externo)` **não é único** e não bastaria. C1 já resolve porque
restringe pela carteira via `caso → objeto → carteira`. Hoje não há colisão entre os citados (TL I usa
212..431, TL II usa 1..39), mas a defesa é necessária e é grátis.

## 5. O desenho

### 5.1 O ponto de decisão

A coluna J decide dois caminhos na gravação, espelhando `ImportarRelatorioCarteiraUseCase:194-198`:

- **vazia / fora do formato** → boleto avulso, **exatamente como hoje** (1.891 dos 2.078);
- **`Acordo N - Parc. x/y`** → resolve/cria o acordo **ANTES** de resolver a obrigação, e a obrigação
  nasce com `acordoOrigem`.

O acordo é resolvido antes de propósito: a parcela precisa apontar para ele nos **dois** ramos — obrigação
nova **e** obrigação preexistente (que pode ter nascido avulsa numa importação anterior).

### 5.2 A parcela de acordo (B2) — e por que a conta fecha

Hoje `valorExigivel() = valorOriginal + juros + multa + correcao`; **honorário fica fora do exigível**
(`Obrigacao.php:231-234`). É por isso que a obrigação avulsa quita exatamente hoje:

```
avulsa:  valorOriginal = valorDivida ; liquidar(juros, multa, 0, honorarios)
         exigível  = divida + juros + multa
         alocação  = recuperadoDividaCentavos() = divida + juros + multa   →  quita exato ✓
```

Na parcela de acordo o honorário **não é honorário do escritório sobre a dívida**: ele foi consolidado
**dentro** da parcela negociada. Então ele entra no principal, e nada de honorário é materializado:

```
parcela: valorOriginal = valorDivida + valorHonorarios ; liquidar(juros, multa, 0, 0)
         honorariosBp = 0 (modoHonorarios 'percent')
         exigível  = divida + honorarios + juros + multa
         alocação  = totalRecebidoCentavos() = divida + juros + multa + honorarios → quita exato ✓
```

⚠️ **As duas mudanças são inseparáveis.** Mover o honorário para o principal **sem** zerar o quarto
argumento de `liquidar()` contaria o honorário duas vezes no exigível e a parcela nasceria devendo. Mover
sem trocar a alocação faria a parcela nascer com resíduo igual ao honorário. Há um teste para cada uma
das três metades.

**O `Pagamento` NÃO muda.** `valorDivida` / `valorEncargos` / `valorHonorarios` continuam exatamente o que
a planilha diz — a contabilidade rateou, o sistema não re-rateia. É o que preserva a conferência da §8.1
da spec-mãe.

`honorariosBp = 0` também fecha a §9.2 da spec-mãe para as parcelas: reaberta pela etapa 1, a parcela não
volta a acumular honorário pela cascata da carteira.

### 5.3 Status (A2)

`Cumprido` **se e somente se** o número de parcelas distintas pagas **que ESTE arquivo traz** alcançar
`numeroParcelasTotal`. Senão, `Ativo`.

⚠️ Uma versão anterior desta seção dizia *"nesta execução **mais as já existentes no banco**"*, e o
código nunca fez isso — a 1ª revisão pegou a contradição. **Vale o que está escrito agora**, que é o que
o código faz: parcela paga numa importação anterior não entra na conta, então o acordo pode ficar `Ativo`
quando já estava quitado. É o lado certo de errar; o contrário — marcar `Cumprido` um acordo que ainda
deve — seria subcobrança silenciosa. Com `pagamentos = 0` no dev (§3.7), hoje as duas réguas dão o mesmo
resultado.

Medido: **75 dos 106 nasceriam `Cumprido`** (49 TL I + 26 TL II) e 31 `Ativo`.

⚠️ A régua é conservadora de propósito. O export é filtrado por **vencimento** (achado da etapa 2), então
um acordo de 40 parcelas pode aparecer com só as que vencem na janela. Nesse caso `pagas < total` e ele
nasce **`Ativo`** — que é o lado certo de errar. O contrário (marcar `Cumprido` um acordo que ainda deve)
seria subcobrança silenciosa.

`StatusAcordo::Cumprido` é vigente (`ehVigente()`), então as parcelas continuam contando no exigível
exatamente como as de um acordo `Ativo`. A escolha não move dinheiro; move o que a tela diz.

### 5.4 ESTADO intra-execução — onde esta etapa vai errar se errar

🔑 **É o defeito que já apareceu DUAS vezes nesta frente.** Um acordo de 8 parcelas pagas aparece em 8
linhas do arquivo. Na prévia, o banco responde "não existe" nas 8 consultas, porque a prévia não grava:
sem estado, ela prometeria **8 acordos criados** onde a confirmação cria **1**.

Então `EstadoDaImportacaoDeReceitas` passa a carregar:

- `acordosVistos: array<string,true>` chaveado por `numeroExterno` — conta a criação **uma vez por
  acordo**, não uma por parcela (mesmo padrão de `objetosVistos`);
- `parcelasPagasPorAcordo: array<int, array<int,true>>` — os índices de parcela vistos, para a régua do
  `Cumprido` da §5.3 enxergar as parcelas anteriores da MESMA execução;
- `acordosIncompletos` — número, pagas e total, para o comando listar os 4 órfãos.

O campo `acordosCriados`, hoje inerte com um docblock dizendo "sempre zero de propósito"
(`EstadoDaImportacaoDeReceitas.php:41-47`), passa a ser incrementado de verdade; o docblock cai junto.

### 5.5 D6 — reativação por importação (B3)

**Palavras do dono:** *"o importe é sempre a verdade"* — em carteira importada o estado do acordo é da
planilha. Se ela traz uma parcela paga de um acordo que o sistema tem como **rompido** ou **cancelado**, o
acordo volta a **`Ativo`**, sem aviso: o histórico já guarda o rompimento.

Implementação: no `resolverOuCriarAcordo` do caminho de Receitas, acordo achado com status `Rompido` ou
`Cancelado` → `setStatus(Ativo)`, limpando `motivoRompimento`/`motivoCancelamento`, e um evento no
histórico do caso.

**O furo que a §3.2 da spec-cancelar manda resolver, e por que ele não existe aqui.** A cadeia temida era:
acordo desfeito → originais voltam ao saldo → gestor recebe numa original → reativação → a original sai do
exigível → `CalculadoraSaldo` só abate alocação de obrigação **exigível** → o dinheiro pago **para de
abater** e o devedor é cobrado por algo que já pagou.

O que fecha isso é exatamente esta etapa: a Receitas identifica o pagamento **por obrigação** (NN), não
por regra, então o dinheiro das parcelas chega com o NN das parcelas e não precisa ser rateado por
adivinhação. **Mas isso não elimina o caso do pagamento lançado à mão numa original** — esse continua
possível. Ele é medido e reportado, não corrigido em silêncio: quando a reativação tira do exigível uma
obrigação que **tem alocação**, o comando **lista o caso, o NN e o valor** que deixou de abater.
Corrigir automaticamente seria decidir por conta própria para onde vai dinheiro de terceiro.

## 6. Como se prova

Cada item abaixo tem teste, e **cada teste é provado reintroduzindo o defeito e conferindo QUAL assert
fica vermelho** — na etapa 2, três dos quatro defeitos de teste eram asserts que não podiam falhar, e uma
"prova por injeção" falhou por carona em outro assert. Onde o cenário tem defesas em série, o teste é
mínimo e isola uma só.

| # | O que se prova |
|---|---|
| P1 | coluna J vazia → obrigação avulsa, **byte a byte como hoje** (`acordoOrigem` nulo, `valorOriginal` = principal, honorário materializado) |
| P2 | `Acordo N - Parc. x/y` → obrigação com `acordoOrigem` apontando ao acordo de `numeroExterno = N` |
| P3 | acordo **inexistente** → criado com `numeroExterno`, `numeroParcelasTotal`, `dataAcordo` e `criadoPor` |
| P4 | acordo **existente na mesma carteira** → **reusado**, não duplicado |
| P5 | mesmo `numeroExterno` em **outra carteira do mesmo tenant** → acordo **separado** (C1) |
| P6 | **duas parcelas do mesmo acordo na mesma execução → UM acordo**, e a prévia diz o mesmo número que a confirmação |
| P7 | parcela de acordo: `valorOriginal` = divida + honorários (isolado) |
| P8 | parcela de acordo: `liquidar` recebe honorário **zero** (isolado) |
| P9 | parcela de acordo: alocação = bruto, e a obrigação **nasce quitada** (exigível == alocado) |
| P10 | parcela de acordo grava `honorariosBp = 0`, e reaberta **não** acumula honorário |
| P11 | todas as parcelas pagas → `Cumprido`; faltando uma → `Ativo` |
| P12 | acordo `Rompido` → volta a `Ativo` na importação (D6), com evento no histórico |
| P13 | reativação que tira do exigível obrigação **com alocação** → **reportada**, não silenciosa |
| P14 | **idempotência**: reimportar o mesmo arquivo não cria acordo nem repõe vínculo |
| P15 | **prévia × confirmação idênticas em TODOS os campos** (o comparador por reflexão já existente cobre os campos novos) |
| P16 | **isolamento por tenant** na busca do acordo — **teste do repositório direto, sem request**, com dado CRUZADO. O `TenantFilter` é global e ligado **por request**: fica DESLIGADO em CLI, que é onde o importador roda, então teste funcional não prova nada aqui |
| P17 | o comando **imprime** acordos criados, ligados e os incompletos com parcelas faltando — com cenário que exercite cada contador (contador sem cenário compara `[]` com `[]`) |

**Prova externa, FEITA (dry-run de 03/08, nada gravado):** o comando reproduz a §3 ao número.

| | TOP LIFE I | TOP LIFE II | total | §3 dizia |
|---|---|---|---|---|
| recebimentos | 1.220 | 857 | 2.077 | — |
| — parcelas de acordo | **160** | **27** | **187** | 187 ✓ |
| acordos criados | **80** | **26** | **106** | 106 ✓ |
| — `Cumprido` | **49** | **26** | **75** | 75 ✓ |
| — incompletos | **31** | **0** | **31** | 31 ✓ |

E os **oito** números da conferência contábil (spec-mãe §8.1) continuam batendo ao centavo depois da
etapa 3 — R$ 243.013,53 / 228.867,89 / 5.610,14 / 8.535,50 e R$ 136.898,49 / 135.486,55 / 552,83 /
859,11. Era o que tinha de acontecer: a etapa 3 não toca o `Pagamento`.

Os 4 órfãos aparecem na listagem do comando com o número de parcelas medido — 212 (1 de 20), 230 (1 de
28), 237 (4 de 20) e 280 (1 de 10).

⚠️ **A contagem de parcelas pagas dos órfãos mudou** em relação à §3.3, que dizia 212 "faltam 19 de 20",
230 "27 de 28", 237 "16 de 20", 280 "9 de 10" — os totais faltantes eram 19/27/16/9 e o comando mostra
pagas 1/1/4/1, que dão os mesmos 19/27/16/9. Os dois números dizem a mesma coisa por lados opostos; não
houve divergência.

### 5.6 A alocação: o sinal é o override `taxa_honorarios_bp = 0`

Esta seção foi reescrita **duas vezes**, uma por revisão, porque as duas primeiras réguas erravam — cada
uma para um lado, e nenhuma das duas dava para acertar com a informação que olhava.

| régua | o que errava |
|---|---|
| `!$obrigacaoExistia` (*"eu criei agora?"*) — 1ª versão | a parcela **preexistente** ficava devendo o honorário, para sempre, com juros e multa em cima |
| `getAcordoOrigem() !== null` (*"é parcela?"*) — correção da 1ª revisão | a avulsa apenas **vinculada** recebia o honorário a mais, abatendo o saldo do caso indevidamente |

A pergunta certa é **"o `valorOriginal` desta obrigação já inclui o honorário?"**, e o sinal que a
responde é o override `taxa_honorarios_bp = 0`: quem grava obrigação com o honorário embutido grava o
override na mesma linha de código, exatamente porque o honorário já foi cobrado uma vez —
`ImportarRelatorioCarteiraUseCase:479-481`, `ImportarAcordosDetalhadosUseCase:662-663` e o
`criarObrigacaoJaPaga` desta etapa.

⚠️ **Esta seção já afirmou que isso era "certo por construção". NÃO É** — a 3ª revisão desmontou:

- digitar **0%** em Editar Obrigação grava `bp = 0` numa avulsa (`EditarObrigacaoInput:71` aceita zero
  com `PositiveOrZero`), criando um falso "honorário embutido";
- o JS de `show.html.twig:1329` trata a string `"0"` como **falsy** e cai no ramo `'herda'`, então
  **qualquer** edição de uma obrigação com `bp = 0` grava `NULL` e APAGA o marcador.

Medido no `saas_ux`: **0 avulsas com `bp = 0`** hoje. Os dois casos são latentes, não presentes.

**Como isso é tratado, já que não dá para garantir:** onde o importador CRIA a obrigação não há palpite —
ele grava as duas pontas. Onde a obrigação é **preexistente**, o sinal decide dinheiro sem garantia, e
por isso **cada caso é listado** (`alocacaoBrutaEmPreexistente`), com o comando pedindo a conferência do
`valorOriginal`. Palpite em caminho de dinheiro pode existir; silencioso, não.

**As duas formas coexistem no dado real.** Medido no `saas_ux`: das 51 obrigações com
`acordo_origem_id`, **14 têm `bp = 0`** e **37 têm `NULL`**. O acordo **31 tem das duas**: NN 61372 com
`NULL` e 61373/61374 com `0`.

⚠️ **Correção da 3ª revisão sobre a evidência:** os 37 com `NULL` não são todos "avulsas vinculadas".
**33** são parcelas MANUAIS (`CriarAcordoUseCase:168` / `EditarAcordoUseCase:258`), sem NN nem
competência — nunca alcançáveis por esta importação, que casa por `(caso, NN, competência)`. Avulsas
vinculadas de verdade são **4**. A conclusão continua de pé (as duas formas coexistem, e o acordo 31
prova), mas a evidência citada não era a que a spec dizia.

**Alcance medido em 03/08: R$ 0,00** — nenhum dos 2.077 recebimentos pousa hoje numa parcela
preexistente. ⚠️ Mas a ordem que a §7 manda executar em seguida — rodar "Acordos detalhados" **depois** —
cria justamente as parcelas 2..N com honorário embutido, e a importação do mês seguinte cairia toda ali.

⚠️ **O que esta seção NÃO resolve.** Uma parcela preexistente **não está liquidada**: os encargos dela
correm ao vivo, então o exigível no dia da importação pode ser maior que o recebido, e a linha não quita.
Isso não é defeito desta etapa — é a §9.3 da spec-mãe (*"o importador aloca o valor cheio; o excedente ou
a sobra vai para o saldo do caso"*), decisão já tomada e conferida. Aqui fica só registrado que vale
também para a parcela.

### 5.6.1 A troca de acordo usa a MESMA régua do vínculo

Achado da 2ª revisão. A detecção de troca comparava `numeroExterno` e o vínculo comparava identidade de
entidade. Divergem onde dói: o índice `(tenant_id, numero_externo)` **não é único**, e o
`AcordoRepository` documenta **tolerar** dois acordos com o mesmo número na mesma carteira (pega o
`id DESC`). Nesse caso o vínculo mudava de acordo e o aviso não saía. Agora as duas comparam a entidade,
e a prévia recebe o resultado da mesma busca que a confirmação usa.

### 5.7 O relatório de D6 é fotografado ANTES do laço, em DOIS canais

Também da 1ª revisão. `reativacoesComDinheiroParado` consulta **alocações**. Calculado dentro do laço, a
confirmação veria as alocações que ela mesma acabou de criar nas linhas anteriores (o
`RegistrarObrigacaoUseCase` dá flush a cada obrigação) e devolveria um número que a prévia não tem como
prever — e como o comando imprime o aviso **da projeção**, o operador não veria o dinheiro que a própria
importação acabou de encalhar.

O mapa é montado uma vez, no início dos dois modos, sobre o banco intocado. A igualdade
prévia × confirmação passa a valer **por construção**, não por sorte de cenário.

**E são dois canais, não um** (2ª revisão). A correção da 1ª empurrou o agregado "quanto sai do
exigível" para dentro da lista de dinheiro já pago — e o texto daquele aviso afirma *"o devedor passa a
ser cobrado por algo que já pagou"*, que passava a disparar **também quando ninguém tinha pagado nada**.
Alarme falso em aviso de dinheiro é pior do que aviso nenhum: ensina o operador a ignorar.

Agora:
- `reativacoesComDinheiroParado` — só obrigação **com alocação**; é o alerta grave;
- `reativacoesImpactoNoSaldo` — quanto o saldo se move, e vale mesmo sem dinheiro já pago. O valor é
  **`Σ(exigível − alocado)`**, não o exigível bruto: uma versão anterior imprimia R$ 500,00 onde o saldo
  se move R$ 350,00 — um número certo respondendo a outra pergunta.

⚠️ Os valores vêm do **snapshot gravado**, não do recálculo ao vivo. Uma original que voltou ao exigível
por rompimento antigo cresceu desde então, e o aviso **subestima**. É ordem de grandeza para segurar a
mão de quem confirma, não número de fechamento — e o comando diz isso na própria mensagem.

## 6.1 O que a 1ª revisão achou

| severidade | achado | o que foi feito |
|---|---|---|
| **BLOQUEANTE** | alocação discriminada por "criei agora?" → parcela preexistente fica devendo o honorário | corrigido (§5.6) + teste próprio, provado por injeção |
| MÉDIO | o teste de "aviso antes da escrita" rodava só dry-run — **não podia falhar** | o teste antigo passa a dizer só o que prova; um novo, com `--confirmar`, usa o aviso de D6, que **some** se calculado depois da gravação |
| MÉDIO | `reativacoesComDinheiroParado` podia divergir entre prévia e confirmação | corrigido (§5.7) + teste com acordo rompido e alocação prévia |
| MÉDIO | nenhum teste do COMANDO montava acordo rompido | coberto pelo teste novo com `--confirmar` |
| MÉDIO | a spec §5.3 contradizia o código | spec corrigida para o que o código faz |
| MÉDIO | D6 reportava contagem, não reais | o aviso passa a dizer quanto sai do exigível, mesmo sem dinheiro já pago |
| BAIXO | `totalAlocado > 0` ignorava alocação de **R$ 0,00**, que é pagamento real (a etapa 2 cria 10) | passou a usar `existeAlocacaoEmObrigacoes`, a mesma régua do `CancelarAcordoUseCase` |
| BAIXO | linha "— destes, PARCELAS de acordo" pendurada em "Casos abertos" | movida para junto dos recebimentos |
| BAIXO | religar obrigação a OUTRO acordo passava em silêncio | vira aviso nos dois modos, com teste e par negativo |

🔑 **Duas correções da 1ª revisão eram, elas mesmas, asserts que não podiam falhar** — o de ordem e o de
premissa do tenant. A 2ª passada existe para isso.

## 6.2 O que a 2ª revisão achou — nas CORREÇÕES da 1ª

Confirmando o padrão da etapa 2: **metade do que a 2ª passada achou eram defeitos introduzidos pela 1ª.**

| severidade | achado | o que foi feito |
|---|---|---|
| **MÉDIO/ALTO** | a correção do bloqueante criou o **espelho** dele: `getAcordoOrigem() !== null` fazia a avulsa apenas VINCULADA receber o honorário a mais | régua trocada pelo override `taxa_honorarios_bp = 0` (§5.6), com teste para os DOIS lados |
| MÉDIO | o novo teste de ordem **também não podia falhar**: `avisarDinheiroParado` recebe objeto já materializado, então mover a impressão não muda a saída | o comando passou a imprimir uma **fronteira** (`>>> GRAVANDO (--confirmar)`) antes de abrir a transação; o assert compara contra ela |
| MÉDIO | o agregado contaminou o canal de "dinheiro já pago" → **alarme falso** | dois canais separados (§5.7) |
| MÉDIO | o agregado somava o exigível **bruto**, não o efeito no saldo | passou a ser `Σ(exigível − alocado)` |
| BAIXO | a régua da troca divergia da do vínculo na duplicata de número | unificadas (§5.6.1) |
| BAIXO | truncagem em 40 sem o sufixo `… (+N)` que os avisos irmãos têm | corrigido |

## 6.3 O que a 3ª revisão achou — nas correções da 2ª

Terceira rodada, terceira vez que a correção trouxe defeito. O padrão parou de ser coincidência.

| severidade | achado | o que foi feito |
|---|---|---|
| **MÉDIO/ALTO** | `bp = 0` foi vendido como invariante e **não é**: a tela cria e apaga o valor | a spec e o código pararam de chamá-lo de garantia, e todo palpite em obrigação preexistente virou **relatório** (§5.6) |
| MÉDIO | os dois avisos de D6 começavam com o **mesmo prefixo**, então o assert de ordem casava a 1ª ocorrência: mover só o canal novo para depois da gravação deixava o teste verde | prefixos distintos (`D6 · DINHEIRO JÁ PAGO…` e `D6 · IMPACTO NO SALDO`), com um assert de ordem para **cada** canal |
| BAIXO | a spec dizia que os 37 com `NULL` eram avulsas vinculadas; **33 são parcelas manuais** | evidência corrigida acima |
| BAIXO | o comentário do `max(0, …)` dizia espelhar a `CalculadoraSaldo`, que não tem piso por obrigação | comentário corrigido; o piso continua, declarado como convenção do aviso |
| BAIXO | o aviso de impacto era o único sem `$confirmar` — não dizia se ia gravar | corrigido |

🔑 **A prova por injeção pegou 5 na 1ª rodada; as três revisões pegaram mais 6 asserts vacuosos, sempre
no código escrito para corrigir o assert vacuoso anterior.** O padrão não é "eu escrevo testes ruins de
vez em quando": é que **assert vacuoso é o estado natural de um teste escrito junto com o código que ele
testa**, porque os dois nascem da mesma suposição. Só a injeção de defeito e um leitor adversarial
separam os dois — e cada rodada de correção precisa das duas coisas de novo.

## 7. Fora de escopo

- **Criar as parcelas futuras** dos 27 com aba — é o `ImportarAcordosDetalhadosUseCase` existente,
  rodado **depois**.
- **Alterar o importador de Acordos detalhados** para criar acordo (§3.4: cobriria 27 de 106 e reabriria
  uma decisão de spec já tomada).
- **D6 no importador de Inadimplência** (B3).
- **Sintetizar as 71 parcelas futuras dos 4 órfãos** (B1).
- **Rateio automático** do pagamento lançado à mão numa dívida original quando o acordo é reativado —
  reportado, não corrigido (§5.5).

### 7.1 ⚠️ Duas consequências para o dono decidir — levantadas pela 1ª revisão

**(a) D6 contradiz a política escrita do importador irmão.**
`ImportarAcordosDetalhadosUseCase.php:600-606` diz, com todas as letras: *"o status do sistema é uma
decisão MANUAL do escritório que move dinheiro: ressuscitar um acordo rompido a partir de uma planilha
tiraria as dívidas originais do saldo de novo, desfazendo em silêncio o que uma pessoa decidiu"* — e por
isso lá a divergência vira **aviso**, não ação.

D6 faz exatamente o que aquele texto recusa, pelo caminho da Receitas, porque o dono decidiu que **"o
importe é sempre a verdade"**. As duas políticas podem coexistir (fontes diferentes, decisões
diferentes), mas a contradição fica **registrada, não escondida**. Se o dono quiser alinhar, é uma
frente própria.

**(b) Depois do `--confirmar`, os 106 acordos ficam INCANCELÁVEIS pela tela.**
Toda parcela criada aqui nasce com alocação, e `CancelarAcordoUseCase.php:144-156` recusa cancelar
acordo com qualquer alocação nas parcelas (`AcordoComParcelaPagaException`). Para cancelar um deles será
preciso antes **excluir os recebimentos um a um** (etapa 1). Isso pega em cheio os **31 incompletos** e
os **4 órfãos** — justamente os que podem precisar ser refeitos quando a fonte chegar. Não é defeito
desta etapa: é como o cancelamento foi especificado. Mas o dono precisa saber **antes** de confirmar.

## 8. Estado

**Ao abrir a etapa:** `master` local em `2c59cb06`, **19 commits não publicados**, árvore limpa, suíte
**3169/3169**, sem migration pendente. Nada em produção, nada gravado.

**Ao fechar o código:** suíte **3192/3192** (+23), `lint:twig`, `lint:container` e
`doctrine:schema:validate --skip-sync` verdes. Dry-run contra as quatro planilhas reais reproduzindo a §3.

Esta etapa **não tem migration**: `Acordo.numeroExterno`, `Acordo.numeroParcelasTotal` e
`Obrigacao.acordoOrigem` já existem.

### 8.1 O que a prova por injeção achou — e por que ela não é formalidade

Os 21 testes ficaram verdes na primeira execução. **Isso não vale nada por si**: rodando as 23 injeções
de defeito (uma por assert que cada teste alega guardar, conferindo qual assert fica vermelho), **5
falharam**, e três delas eram defeito de verdade:

| | O que estava errado |
|---|---|
| 1 | `assertSame(0, (int) fetchOne(...))` sobre `taxa_honorarios_bp`: **`(int) null` também é 0**, então o assert passava com a coluna NULA, isto é, com o override não gravado. Trocar `'percent'` por `'herda'` no código deixava a suíte verde. |
| 2 | O relatório de dinheiro parado tinha **duas defesas em SÉRIE** (um `totalAlocado` agregado com return antecipado, mais a checagem por obrigação). Relaxar só uma mantinha o teste verde — o caso negativo era improvável. A guarda redundante foi REMOVIDA: uma defesa, uma prova. |
| 3 | O teste de premissa do isolamento por tenant procurava por um filtro chamado `tenant_filter`. **O filtro se chama `tenant`** — o assert passava com o filtro ligado ou desligado. Ironicamente, era o teste escrito para vigiar que a premissa não se perdesse. |

As outras duas eram injeção mal escolhida da minha parte (a injeção não produzia o defeito alegado), e
foram trocadas até o vermelho vir do assert certo.

🔑 **Um assert que não pode falhar não é um teste — é um comentário que consome CPU.** Foi o padrão
dominante da etapa 2 (3 dos 4 defeitos de teste) e reapareceu 3 vezes aqui, inclusive dentro do teste
escrito justamente para não deixar isso acontecer.
