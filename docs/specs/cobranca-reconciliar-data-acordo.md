# Spec — reconciliar a data do acordo com a data da contabilidade

> Risco **MÉDIO** (escreve dado financeiro em produção). Frente do espelho da contabilidade, §3.1 do
> `docs/HANDOFF_ESPELHO_CONTABILIDADE.md`. Medido em produção em **2026-08-19**.

## 1. O defeito

Até `dbb434e5` (18/08) os importadores gravavam uma data de acordo **inventada** quando o relatório
não trazia uma: o 1º dia do mês (`dataAcordoPadrao()`). Isso foi removido — mas as datas já gravadas
**não se consertam sozinhas**. `ImportarAcordosDetalhadosUseCase:395` faz

```php
if (!$acordo->temData() && $aba->dataBase !== null) {
```

— só preenche o vazio, **não sobrescreve data existente**. A justificativa é correta para o caso
geral (não regravar o mesmo dado a cada lote), mas para estes acordos a data guardada não veio da
fonte: foi inventada, e o importador não tem como distinguir uma da outra.

A data do acordo é a referência com que `materializarNaDataDoAcordo` grava juros, multa, correção e
honorários nas obrigações que o acordo substituiu. Data errada ⟹ encargo materializado errado.

## 2. A verdade, e onde ela está

O relatório de acordos dela traz, no cabeçalho de cada aba, a linha
`Unidade: <x>` na coluna A e `Data base: DD/MM/YYYY` na coluna B. O espelho **já guarda isso**:

```sql
SELECT r.carteira_id,
       (regexp_replace(l.aba,'^Acordo n',''))::int                                  AS numero,
       to_date(replace((l.bruto::jsonb)->>1,'Data base: ',''),'DD/MM/YYYY')          AS data_base
FROM cobranca_relatorio_linha l
JOIN cobranca_relatorio_importado r ON r.id = l.relatorio_id
WHERE r.tipo = 'acordos' AND l.bloco = 'cabecalho'
  AND (l.bruto::jsonb)->>0 ILIKE 'Unidade:%'
  AND (l.bruto::jsonb)->>1 ILIKE 'Data base:%'
  AND l.aba ~ '^Acordo n[0-9]+$'
```

`aba` casa com `Acordo.numero_externo` **dentro da mesma carteira**. Medido: **398 acordos, 398
datas, uma única data por acordo** em todos os lotes carregados — não há ambiguidade a resolver.

🔑 **É a mesma fonte que o importador usa** (`AcordosDetalhadosAdapter` lê essa linha para
`dataBase`, e o UseCase faz `setDataAcordo($aba->dataBase)`). Isto não é um critério novo: é o
critério que já vale, aplicado ao passivo.

## 3. O critério — e o critério que NÃO serve

**Critério:** `acordo.data_acordo <> data_base do espelho`.

🔴 **NÃO usar "data no dia 1º do mês".** Medido: 377 acordos têm data no dia 1º, mas **5 deles estão
certos** — o acordo realmente foi feito no dia 1º. A heurística corromperia 5 registros corretos.

Acordo **sem** `data_base` no espelho: **não tocar**. Sem a fonte não há verdade a copiar, e inventar
de novo é a violação que esta frente removeu.

## 4. O tamanho, medido em produção (2026-08-19)

Alvo: **372 acordos**, **3.656 obrigações substituídas**, principal de R$ 524.987,08.

| | |
|---|---:|
| encargo materializado hoje (na data inventada) | R$ 207.978,50 |
| encargo na data dela | R$ 207.528,12 |
| **efeito líquido** | **− R$ 450,38** |
| sobe em 1.967 dívidas | + R$ 5.529,76 |
| desce em 1.561 dívidas | − R$ 5.980,14 |
| não muda | 128 dívidas |
| das 258 que hoje cobram R$ 0,00 → saem do zero | 130 |
| passam a cobrar R$ 0,00 | 28 |
| maior alta numa dívida · maior baixa | + R$ 59,48 · − R$ 75,12 |

**Estes números são o alvo de verificação da simulação.** Rodando `--simular` em produção, o total
tem de reproduzi-los.

### 4.1 O que este conserto NÃO faz

- **Não mexe no saldo.** Os 398 acordos são `ativo`/`cumprido` (vigentes), e
  `ObrigacaoRepository::aplicarExigibilidade` exclui do exigível toda obrigação com acordo substituto
  vigente. O encargo materializado nelas é número de **ficha do acordo**, não dívida cobrável.
- **Não muda a calibração.** Medido: **nenhuma** das 3.656 aparece no relatório de inadimplência
  dela — o join foi validado (4.559 obrigações casam com linhas dela, nenhuma delas substituída).
  As 415 linhas "acima de R$ 1" ficam como estão.
- **Não sobrevive a um rompimento.** Acordo rompido devolve a original ao cálculo ao vivo, que
  reescreve o snapshot.

⚠️ Corrigir: o handoff §3.1 diz "isto faz a cobrança SUBIR" e cita R$ 203.265,07. Os R$ 203 mil são o
**tamanho do número** produzido pela data inventada, não o erro; o erro é R$ 450,38 e aponta **para
baixo**. A urgência é menor do que o handoff registrava — o defeito continua sendo defeito.

## 5. O comando

`app:cobranca:reconciliar-data-acordo`, no molde de `ReconciliarDuplaContagemCommand`
(`app/src/Cobranca/Command/`), que já rodou em produção e é o padrão da casa.

- **Simula por padrão.** Só grava com `--aplicar` **e** `--usuario-id` — mudança financeira precisa
  de autor no histórico.
- `implements LidaComDadoPessoal` + `GuardaDeLogComPii`: **não imprime nome, CPF nem unidade.**
  Identifica acordo por carteira + número externo, e obrigação por id.
- Códigos de saída na faixa `6x`, como o molde: erro de invocação, nada a fazer, contas não fecham.
- Filtro de tenant obrigatório em toda consulta (regra transversal do projeto).

### 5.1 O que ele escreve

1. `Acordo::setDataAcordo(<data_base>)` nos acordos que batem no critério da §3.
2. Re-materializa as obrigações substituídas por esses acordos, **pela mesma regra** de
   `ImportarAcordosDetalhadosUseCase::materializarNaDataDoAcordo`:
   - pula `encargosCongelados()` (já liquidada/legado — mantém o snapshot que tinha);
   - resolve a config por `ResolvedorConfigEncargos`, calcula por `CalculadoraEncargos`, grava por
     `Obrigacao::definirEncargos(..., $dataAcordo)`.
   - **Reusar os dois serviços. Não reimplementar a fórmula.**

### 5.2 Contrato duro — não usar `valorExigivel()`

🔴 O comando **não pode chamar `Obrigacao::valorExigivel()` nem `totalComHonorarios()`**. Ele reporta
as quatro colunas de encargo (`juros`, `multa`, `correcao`, `honorarios`) e o delta direto.

**Por quê:** a fatia do honorário (§3.2) está sendo escrita em paralelo e vai mudar o que
`valorExigivel()` soma. Se este comando dependesse dele, os números que ele imprime mudariam por
efeito da outra frente — uma frente falsearia a prova da outra.

## 6. Prova

A régua independente já existe: a medição da §4 foi feita em SQL puro contra a produção, e a fórmula
reproduzida em SQL **bate com o PHP em 3.909 de 3.909 obrigações, zero centavo de diferença**. A
simulação do comando tem de reproduzir os totais da §4.

Testes obrigatórios:

- **unit do UseCase:** acordo com data errada é corrigido; acordo com data certa não é tocado;
  acordo do dia 1º **que está certo** não é tocado (o caso que a heurística ruim quebraria); acordo
  sem `data_base` no espelho não é tocado; obrigação congelada não é re-materializada; sem
  `--aplicar` nada é gravado.
- **functional do comando:** simula por padrão; `--aplicar` sem `--usuario-id` recusa; isolamento de
  tenant.

**Prova por reintrodução, executada** (não rastreada por leitura): apagar a correção, ver vermelho,
restaurar, ver verde, e dizer qual teste morreu. É a regra desta frente — quatro correções já
entraram declaradas como provadas sem estar.
