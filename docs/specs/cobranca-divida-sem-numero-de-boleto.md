# SPEC — Dívida sem número de boleto (NN): chave substituta

**Aberta em 2026-08-05.** Risco **ALTO** (cria dívida em nome de devedor real; erro na chave duplica ou
some com dinheiro). Fecha o **item 1 do checklist** do `HANDOFF_AUTOMATIZAR_DOWNLOADS.md` §6.3 —
**R$ 17.444,66**.

Origem do problema: `HANDOFF_AUTOMATIZAR_DOWNLOADS.md` §6.1. Chave de dedup atual:
`docs/specs/cobranca-importar-chave-competencia.md`.

---

## 1. O problema, em uma frase

Os três importadores descartam **toda linha sem Nosso Número**, porque o NN é a chave de deduplicação —
e sem chave, a segunda importação duplicaria a dívida. Só que **157 dessas linhas nunca tiveram boleto
emitido**: são taxas mensais antigas, reais, com unidade, sacado, competência e vencimento preenchidos.
Elas somem da importação, e o devedor aparece devendo menos do que deve.

Trocar "dinheiro faltando" por "dinheiro dobrado" seria pior. Por isso a correção **não é remover a
guarda** — é dar a essas linhas uma chave que o NN daria.

## 2. O que foi medido (05/08/2026)

**Como:** leitura crua dos `.xlsx` de `planilhas atualizadas/2026-08-04-api/` e `2026-08-04-completo/`
(scripts descartáveis `_semnn_*.php` na pasta gitignored), **sem passar pelos adapters** — para medir
justamente o que os adapters descartam. Reconciliação: a soma bate com a §6.3 ao centavo.

| fonte | linhas sem NN | valor | unidades | acordos |
|---|---:|---:|---:|---:|
| Acordos detalhados — `Relação das contas originais` | **84** | **R$ 6.750,00** | 5 | 253 (Em andamento) · 12, 51, 59, 151 (Liquidado) |
| Inadimplência detalhada | **73** | **R$ 10.694,66** | 2 | nenhum (coluna N = `-` nas 73) |
| **total** | **157** | **R$ 17.444,66** | | |

Só a **TOP LIFE 1** tem linhas sem NN. **TL2 e AMLI têm zero.**

### 2.0 ⚠️ O que os R$ 17.444,66 são — e o que NÃO são

O número é o do checklist §6.3, e ele **soma duas grandezas diferentes**, porque as duas fontes
reportam coisas diferentes. Decomposto (medido nas colunas cruas):

| | principal | juros+multa+correção | honorários | total |
|---|---:|---:|---:|---:|
| Inadimplência (73 linhas) | R$ 5.760,00 | R$ 3.152,21 | R$ 1.782,45 | R$ 10.694,66 |
| Acordos — contas originais (84 linhas) | R$ 6.750,00 | — (a fonte não traz) | — | R$ 6.750,00 |
| **soma** | **R$ 12.510,00** | R$ 3.152,21 | R$ 1.782,45 | **R$ 17.444,66** |

Duas ressalvas que mudam o que o dono deve esperar ver na tela:

1. **Honorário não é dívida do credor** (INV-E2) — os R$ 1.782,45 não entram no saldo exigível.
2. 🔑 **Os R$ 6.750,00 dos acordos NÃO aumentam o saldo do devedor.** A conta original reconstruída
   nasce com `acordoSubstituto` apontando para o acordo
   (`ImportarAcordosDetalhadosUseCase.php:665`), e `ObrigacaoRepository::doCasoExigiveis` exclui
   obrigação substituída por acordo **vigente**. É história reconstruída: só vira dívida cobrável se o
   acordo for **rompido ou cancelado**.

**O que de fato aparece como dívida nova na tela hoje: os R$ 8.912,21 da inadimplência**
(principal + encargos, sem honorário). O resto é completude de histórico — que continua valendo, porque
é o que faz o rompimento de um acordo restaurar a dívida certa.

Isto ecoa a lição da frente do hífen, onde R$ 49.038,17 "recuperados" tinham **efeito zero no saldo**.
Número grande no commit não é dinheiro na tela.

### 2.1 Três afirmações do handoff caíram na remedição

1. ❌ *"165 contas originais, R$ 6.750,00"* — os dois números são de recortes diferentes. **Com** o
   `CANCELADO` (que não é importado) são **165 linhas / R$ 13.310,00**; **sem** ele, **84 linhas /
   R$ 6.750,00**. O valor do handoff estava certo; a contagem, não.
2. ❌ *"405 das 409 linhas nunca tiveram boleto"* — não reproduz. Nas duas fontes importáveis há
   **157** linhas sem NN, não 409. O 409 contava linhas de outras seções do arquivo.
3. ❌ *"o acordo 151 tem 73 taxas mensais"* — 151 é o maior caso, mas **são 5 acordos**, não um.

Nenhuma decisão muda por causa disso. Mudam os números — de novo, nesta fonte.

### 2.2 Zero sobreposição entre as duas fontes

Nenhuma das 73 linhas da inadimplência casa com uma conta original de acordo (chave
`unidade+competência+vencimento+classe`). As duas fontes tratam de dívidas **distintas** hoje. O desenho
abaixo mesmo assim usa **a mesma fórmula de chave nos dois lados**, para que uma sobreposição futura
deduplique em vez de duplicar.

### 2.3 Os 5 acordos afetados nascem da Receitas

Em `saas_ux_pos_etapa3` só o acordo 12 existe — mas aquele banco é do import **antigo e estreito**.
Medido no arquivo `top_life_1_Receitas_detalhadas_TODOS.xlsx`: **os 5 (12, 51, 59, 151, 253) são citados**,
logo os 5 são criados pelo importador de Receitas numa importação do zero. **O item 1 não depende do
item 5** do checklist.

## 3. A chave substituta

### 3.1 A chave de dedup que já existe

`app/migrations/Version20260730120000.php:61` —

```sql
CREATE UNIQUE INDEX uniq_cobranca_obrigacao_ref_competencia
  ON cobranca_obrigacao (caso_id, referencia_externa, competencia)
  NULLS NOT DISTINCT WHERE referencia_externa IS NOT NULL
```

`caso_id` e `competencia` já são colunas do índice. `referencia_externa` é `VARCHAR(255)` livre.
**Logo a chave substituta não precisa de migration nem de coluna nova: ela é sintetizada dentro da
própria `referencia_externa`.**

### 3.2 A regra

> **Linhas sem NN do mesmo `(caso, competência, vencimento)` formam UMA obrigação, cuja
> `referencia_externa` é `SNN:<vencimento em ISO>`.**

Exemplo: as duas linhas de 09/2019 da unidade 20-03C (`1.1 - Taxa de condomínio` R$ 100,00 e
`1.14 - Energia` R$ 45,00, ambas vencendo 10/09/2019) viram **uma** obrigação de R$ 145,00 com
`referencia_externa = 'SNN:2019-09-10'` e `competencia = '09/2019'`.

Quatro propriedades, cada uma com um motivo:

| propriedade | por quê |
|---|---|
| **prefixo `SNN:`** | NN real é dígito puro (`ctype_digit`). O prefixo torna impossível uma referência sintética colidir com um boleto de verdade, e deixa o dado auditável na tela. |
| **agrupa por `(caso, competência, vencimento)`** | é exatamente o que o NN faz hoje: o adapter de inadimplência agrupa por NN, o de acordos por `NN\|competência`. Um boleto cobre a taxa **e** a energia do mês. Sem NN, o mês + o vencimento são o que resta do boleto. |
| **injetiva por construção** | a chave do agrupamento **é** a chave da referência. Não existem dois grupos com a mesma chave — não há como duas dívidas distintas se fundirem por azar de conteúdo. |
| **o valor NÃO entra na chave** | 🔑 se entrasse, o **item 3** do checklist (*o importe sobrescreve valor divergente*, decidido pelo dono) criaria uma **obrigação nova** a cada correção de centavo em vez de atualizar a existente. Item 1 e item 3 se destruiriam. |

### 3.3 O que a medição diz sobre a regra

Aplicando o agrupamento aos dados reais:

| | linhas | → obrigações | soma | grupos misturando 2 acordos | grupos com classe repetida | grupos sem principal |
|---|---:|---:|---:|---:|---:|---:|
| Acordos | 84 | **54** | R$ 6.750,00 | **0** | **0** | — |
| Inadimplência | 73 | **45** | R$ 10.694,66 | — | **0** | **0** |

- **soma preservada ao centavo** nas duas fontes (o agrupamento não perde nem inventa dinheiro);
- **nenhum grupo mistura dois acordos** → a obrigação nunca fica pendurada no acordo errado;
- **nenhum grupo tem principal zero** → a guarda *"boleto sem principal"* não rejeita nenhum deles;
- 30 dos 54 e 28 dos 45 grupos têm 2 linhas — sempre o par `1.1 Taxa` + `1.14 Energia` do mesmo mês.

### 3.4 O caso que a regra funde de propósito

Duas dívidas realmente distintas, mesmo caso, mesma competência, mesmo vencimento, **ambas sem NN**,
seriam fundidas numa obrigação só. Isso é aceito, por três razões: (a) **não some dinheiro** — o valor
é somado, como num boleto; (b) sem NN não existe informação na fonte capaz de distingui-las; (c) é
**o mesmo comportamento** que o importador já tem para duas linhas que compartilham NN — medido no
acordo 333, linhas 109/110 (NN 66445, duas linhas `1.1` de 04/2022, R$ 100,00 + R$ 45,00), que hoje
viram uma conta de R$ 145,00.

**Não ocorre no dado atual** (0 grupos com classe repetida nas duas fontes).

## 4. O que muda no código

### 4.1 `TopLifeInadimplenciaAdapter`

- `:84` — a chave de agrupamento de linha sem NN deixa de ser sintética-por-linha (`__sem_nn_$i`,
  que isolava cada linha) e passa a ser `objetoIdentificacao \x1f SNN:<venc ISO> \x1f competência` —
  **três** partes. A competência entra porque duas dívidas de meses diferentes podem ter o mesmo
  vencimento; sem ela, o agrupamento fundiria as duas e a competência gravada seria a da primeira.
- `:120-122` — a rejeição `'Boleto sem número (NN) — não é possível deduplicar.'` **é removida**. Não
  há motivo de rejeição novo no lugar dela: linha sem vencimento parseável já era IGNORADA antes de
  chegar ao agrupamento (`:79-84`), e continua sendo. Rodapé e totais saem por aí, como sempre saíram.
- `BoletoImportavel::$nn` passa a receber a referência sintética. **Nenhuma outra guarda muda** — sem
  sacado, competência inválida, valor não numérico e sem principal seguem rejeitando igual.

### 4.2 `AcordosDetalhadosAdapter`

- `:183-187` — hoje a linha de dados é reconhecida por `preg_match('/^\d+$/', $a)` (NN só-dígitos), e
  tudo que não casa é **silenciosamente ignorado**, sem `LinhaRejeitada`. O discriminador passa a ser:
  **está na seção `Relação das contas originais`** *e* **competência casa `MM/AAAA`** *e* **vencimento
  casa `dd/mm/aaaa`** — independente do NN.

  ⚠️ O **valor NÃO entra no critério**, embora a primeira versão desta spec dissesse que sim. Linha de
  dado com valor vazio ou não numérico tem de chegar a `montarContaOriginal` e sair como **rejeição com
  motivo**, que é informação para o operador; barrá-la aqui a transformaria em silêncio, e seria uma
  terceira defesa em série, impossível de provar isoladamente. Medido: **0** das 84 linhas tem valor
  vazio, então a mudança não move nenhum centavo hoje.

  Medido nos **seis** arquivos importáveis (TL1 · TL2 · AMLI × Em andamento · Liquidado): o
  discriminador devolve **6.247** linhas de seção 1 (**6.163** com NN + **84** sem) e **zero** linha de
  rodapé, cabeçalho ou da seção de parcelas. *(Contando só os dois arquivos da TL1 o número é outro —
  5.988 + 84; a 1ª revisão bateu nisso porque a spec não dizia o recorte.)*
- `:195` — a chave de agrupamento de conta original sem NN vira `SNN:<venc ISO>|competência`.
- **A seção de parcelas não muda.** Toda parcela medida tem NN.

### 4.3 UseCases

Nada muda na gravação: `ImportarRelatorioCarteiraUseCase` e `ImportarAcordosDetalhadosUseCase` já
procuram por `findOnePorReferenciaECompetenciaNoCaso(caso, referencia, competencia)`. A referência
sintética entra por esse mesmo caminho.

**Acrescentar ao relatório da importação** (prévia e confirmação): quantas obrigações vieram de chave
substituta e quanto somam. Sem isso o operador não distingue dívida com boleto de dívida sem boleto —
e essa distinção é jurídica, não cosmética.

### 4.4 O que NÃO muda

- linha **com** NN continua usando o NN puro, sem prefixo;
- o índice único, a migration e a entidade `Obrigacao` ficam como estão — **sem migration nesta frente**;
- `TopLifeReceitasAdapter` **não é tocado**: a Receitas é a fonte de *pagamento*, e pagamento sem NN não
  tem o que deduplicar. (Medido: 1 recebimento rejeitado, por valor líquido não positivo, não por NN.)

## 5. Testes obrigatórios

Todo teste é provado **reintroduzindo o defeito** e conferindo **qual assert** fica vermelho. Onde há
mais de um assert, **duas injeções distintas** — uma só faz os outros pegarem carona (lição da etapa 3).

| # | o que prova | injeção que tem de deixar vermelho |
|---|---|---|
| T1 | linha sem NN com competência e vencimento válidos **entra** | voltar a rejeitar sem NN → a obrigação some |
| T2 | duas linhas do mesmo mês/vencimento viram **uma** obrigação, somando | agrupar por linha → viram duas |
| T3 | a referência é `SNN:<ISO>` e **nunca** é dígito puro | tirar o prefixo → colide com NN real |
| T4 | **idempotência**: importar duas vezes não duplica nem dobra o valor | remover a competência da busca → cria a 2ª |
| T5 | mudar **só o valor** entre as duas importações **atualiza**, não duplica | pôr o valor na chave → cria obrigação nova |
| T6 | linha sem NN **e sem vencimento** continua **IGNORADA** (não rejeitada) | fabricar uma data quando o parse falha → ela vira rejeição, e duas dívidas passam a dividir a mesma referência |
| T7 | linha sem NN de duas unidades diferentes não colide (caso distinto) | ignorar o caso na busca → funde as duas |
| T8 | isolamento multi-tenant: a busca da referência não cruza tenant | tirar o filtro de tenant do repositório |

T8 é obrigatório em toda tarefa desta base, e o `TenantFilter` SQL global **fica desligado em CLI** —
o filtro tem de estar explícito na query.

## 6. Riscos aceitos, ditos por extenso

1. **A fusão do §3.4** — aceita, medida como inexistente hoje, e reportada quando ocorrer.
2. **Dívida antiga vira dívida ativa na tela do devedor.** São taxas desde 09/2019. A decisão do dono
   (*"o importe é a fonte da verdade"*) manda importar; a conveniência de cobrar cada uma é do escritório,
   não do importador. O relatório do §4.3 é o que dá visibilidade disso.
3. **Se as duas fontes passarem a se sobrepor**, a mesma dívida chega com valores diferentes
   (a inadimplência traz encargos; a conta original, só principal) e a última importação sobrescreve a
   anterior. É coerente com a decisão do dono, mas precisa **aparecer no relatório**.

4. 🔴 **O dia em que a contábil boletar essa dívida, ela duplica.** Este é o risco central da frente, e
   ele decorre da própria chave: sem NN, o vencimento é a única coisa que distingue a dívida. Se a
   contábil **emitir boleto** para uma delas — que é exatamente o que o escritório quer, ao cobrar —,
   a linha volta com NN de verdade, não casa com `SNN:2019-09-10`, e nasce uma **segunda obrigação**
   para a mesma dívida. Mesma coisa se apenas o **vencimento** for atualizado.

   Note a ironia: `ObrigacaoRepository` documenta que *"o vencimento NÃO serve como discriminador"*
   justamente porque reemissão muda a data. Para dívida sem NN não sobrou alternativa.

   **Medido (05/08/2026):** comparando a emissão de **08/07** com a de **04/08** — 27 dias —, as 73
   linhas sem NN estão nas duas, e **0** mudaram de vencimento, **0** ganharam NN, **0** perderam NN.
   O risco é real e não se materializou uma vez sequer no histórico disponível.

   **Mitigação nomeada, não implementada:** quando um boleto COM NN chegar para um `(caso, competência)`
   que já tem obrigação `SNN:`, migrar a referência em vez de criar. Fica fora desta frente porque
   custa uma consulta a mais por boleto (~3.000 por arquivo) para um caso que ainda não ocorreu — mas é
   a primeira coisa a fazer se o escritório começar a boletar essas dívidas.

6. 🟡 **O valor que o relatório mostra é o da PLANILHA, não o gravado.** O acumulador soma
   `principal + encargos` de toda linha sem boleto do lote, inclusive das que só serão *atualizadas* —
   e no ramo de atualização o `valorOriginal` é preservado (invariável 20). Se a contábil corrigir o
   principal de uma linha sem NN, o relatório mostra o número novo e o banco mantém o antigo.
   **Medido nas três emissões (31/07 · 03/08 · 04/08): principal idêntico nas 45 (R$ 5.760,00 em
   todas), 0 divergências** — o que muda entre emissões são encargos e honorários, que são
   sobrescritos. Efeito hoje: zero. O rótulo do relatório diz "pela planilha" para não mentir.

5. 🟠 **Dívida `SNN:` não pode ser quitada por importação.** A Receitas descarta linha sem NN
   (`TopLifeReceitasAdapter.php:106`) e casa por `(caso, NN, competência)`. Nenhum recebimento
   importado casará com `SNN:2019-09-10` — o pagamento chegaria com o NN do boleto novo, que recai no
   item 4 acima. Na prática: **a baixa dessas obrigações é manual**, pela tela — as 45 da inadimplência
   desde já, e as 54 dos acordos no dia em que um rompimento as devolver ao exigível. E como a importação
   **não congela** encargos (o desenho "ao vivo" materializa mas não congela — ver
   `ReconciliadorLiquidacao.php:62`), elas seguem acumulando juros enquanto estiverem abertas.
   Isso vale para qualquer dívida antiga importada, com ou sem NN; o que é novo é que estas 45 passam
   a existir.
