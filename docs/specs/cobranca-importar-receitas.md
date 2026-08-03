# Importar "Receitas detalhadas por unidade/cliente" — o 4º relatório

**Risco:** ALTO (cria pagamento e obrigação — mexe em dinheiro)
**Data:** 2026-08-03 · **Etapa 2 de 3** da retomada da cobrança
**Handoff de origem:** `docs/gestao-cobrancas/HANDOFF_IMPORTAR_RECEITAS.md` (⚠️ ver §2, que o corrige)

---

## 1. O que este relatório responde, e por que ele muda o sistema

É o **4º e último** relatório da contábil. Os outros três dizem *o que é devido*; este diz **o que foi
pago**. Sem ele, todo recebimento no sistema é digitado à mão e sem conferência externa.

🔑 **Mas a medição mostrou que ele não encaixa onde o handoff supunha.** Medido nos arquivos de 03/08:

| | TOP LIFE I | TOP LIFE II | total |
|---|---|---|---|
| NNs distintos com recebimento válido | 1.220 | 858 | **2.078** |
| NNs que EXISTEM como obrigação no sistema | 1 | 79 | **80 (3,8%)** |
| Acordos citados na coluna J | — | — | **106** |
| Acordos que existem no sistema | — | — | **0** |

E o overlap é de 3–5% **em todos os sete meses**, inclusive o mais recente — não é atraso histórico que
se resolve com o tempo.

**A causa é de construção, não defeito:** o sistema só conhece o que a **Inadimplência** traz, e a
Inadimplência só traz o que **não foi pago**. Boleto pago sai da inadimplência e nunca entrou aqui. A
Receitas fala justamente dos pagos. Os dois conjuntos são quase disjuntos por definição.

Logo, casar por `(caso, NN, competência)` — o desenho do handoff — pousaria em 4% das linhas e reportaria
96% como "NN não encontrado".

## 2. ⚠️ Correção de um fato que estava dado como resolvido

O handoff §1 registra, com medição:

> *"Incluir 'Aberta' não acrescentou uma linha sequer. Toda linha do relatório já tem `Recebimento`
> preenchido."*

**Isso era verdade no export de 01/08 e é FALSO no de 03/08.** Medido:

| Arquivo (03/08) | Linhas de dado | Com data de recebimento | Com `Recebimento = "-"` |
|---|---|---|---|
| `top_life_1_Receitas_..._2026_08_03_09_51_26` | 4.499 | 3.216 | **1.283** |
| `top_life_2_Receitas_..._2026_08_03_09_53_34` | 2.691 | 1.880 | **811** |

As 2.094 linhas ignoradas têm `Valor recebido = 0` e somam **R$ 2.045.780** na coluna H — são boletos em
**aberto**, não receita. Ignorá-las deixou de ser defesa teórica e passou a ser a regra que separa
receita de dívida. **Sem ela, entram dois milhões de reais que não entraram na conta de ninguém.**

Lição registrada: *fato medido tem data de validade quando a fonte é um export que o humano refaz.*
Remedir custou dois minutos; confiar teria custado R$ 2 milhões em pagamentos fantasma.

## 3. Decisões do dono (03/08)

| # | Decisão |
|---|---|
| R1 | **Boleto que o sistema não conhece: CRIAR a obrigação e já marcá-la paga.** O sistema passa a ter o histórico de faturamento, não só o que está em aberto. É o que dá base à etapa 3 — sem isso os 106 acordos da Receitas continuam inexistentes. |
| R2 | **Coluna I (`Valor recebido`) é o dinheiro**, não a H. |
| R3 | **Pagamento importado é igual ao digitado**: sem marca de origem, apagável à mão pela etapa 1, e reimportar traz de volta. Coerente com "o importe é a verdade absoluta". |
| R4 | Os relatórios têm de ser da **mesma data**. Os de 03/08 (09:48–09:53) cumprem. |
| R5 | **O histórico pago fica em SEÇÃO PRÓPRIA na tela**, separado do que está em aberto. Aprovado em 03/08. |

### 3.1 O que R1 muda no significado do sistema, e por que R5 é condição

Hoje a tela do devedor mostra **o que ele deve** — tudo que está na lista é coisa a cobrar. Depois de R1
o sistema conhece também **o que ele já pagou** no ano (jan–jul/2026, medido: os recebimentos vão de
02/01 a 31/07/2026, então a planilha é o ano corrente).

Volume: ~2.078 obrigações e ~106 acordos novos, contra 3.431 obrigações existentes hoje. **O acervo
praticamente dobra.**

⚠️ **Se o pago cair na mesma lista do em aberto, a tela deixa de responder "o que eu cobro dele?".** Um
devedor com 7 boletos pagos e 3 em aberto passaria a mostrar 10 linhas, com as 3 que importam no meio.
Não é risco de dinheiro — obrigação paga entra no bruto e sai no alocado, saldo inalterado (medido) —
é risco de **leitura**, e o ganho do panorama se perderia justamente na tela onde ele deveria aparecer.

Daí R5: **em aberto** de um lado (o que cobrar), **já pago no ano** do outro, com o total à mostra e o
detalhe sob demanda. O panorama entra sem custar a tela de cobrança, e ainda dá de graça uma informação
que hoje não existe: quanto aquele devedor já pagou no ano.

**Decidido ANTES de importar de propósito:** enquanto não há boleto pago no sistema, isto é ajuste de
tela; depois de 2.078 linhas dentro, é ajuste de tela **mais** conferência do que já entrou.

## 4. Layout (medido; idêntico ao do handoff, reconferido em 03/08)

Cabeçalho na **linha 7**, dados a partir da 8. Linhas 1–6 são cabeçalho do relatório.

| Col | Campo | Uso |
|---|---|---|
| A | Unidade | identificação do objeto, mesma das outras planilhas |
| B | Sacado | nome — **PII** |
| C | **NN** | chave do boleto |
| D | **Classe de Conta** | define o balde (ver §5) |
| E | **Competência** | compõe a chave com o NN |
| F | Vencimento | vencimento da obrigação criada em R1 |
| G | **Recebimento** | data do pagamento; `-` significa **em aberto → linha ignorada** |
| H | Valor (R$) | nominal — **não usado** (R2) |
| I | **Valor recebido (R$)** | **o dinheiro** (R2) |
| J | Informações do acordo | preenchida em 100% das linhas, mas só ~7% no formato `Acordo N - Parc. x/y` |

## 5. Classes de conta → baldes do pagamento

🔑 **A entidade `Pagamento` já tem exatamente a decomposição que a planilha traz** (`valorDivida` /
`valorEncargos` / `valorHonorarios`). Não é preciso inventar rateio: a contabilidade já rateou.

| Classe | Balde | Observação |
|---|---|---|
| `1.1`, `1.14` | `valorDivida` | taxa de condomínio — o principal |
| `1.4` | `valorEncargos` | juros |
| `1.5` | `valorEncargos` | multa |
| `1.6` | `valorDivida` | **desconto, sempre negativo** — abate o principal |
| `1.15` | `valorHonorarios` | honorário advocatício |
| `1.12`, `1.19`, `1.22` | `valorDivida` | raras (≤12 linhas no total); balde do principal, e o relatório de importação as lista |

**Desconto não precisa de tratamento próprio** (medido): somando a coluna I por NN, o líquido nunca dá
negativo nem zero — o abatimento já está embutido na decomposição.

**H × I divergem só em `1.4`, `1.5` e `1.6`**, nunca na taxa nem no honorário: nos juros/multa o recebido
é maior (acumulou até o pagamento), no desconto é mais negativo. É a evidência que sustenta R2.

## 6. Chave e idempotência

**Medido: cada NN tem EXATAMENTE UMA data de recebimento** nos dois arquivos (1.220 NNs → 1.220 chaves;
858 → 858). Então:

- **Um pagamento por NN**, agregando as ~2,3 linhas de classe daquele NN.
- **Chave de idempotência:** `(tenant, obrigação do NN, data de recebimento)`. Reimportar o mesmo arquivo
  não pode criar pagamento nenhum na segunda vez.
- A obrigação criada em R1 usa a chave já estabelecida `(caso, NN, competência)` —
  `ObrigacaoRepository::findOnePorReferenciaECompetenciaNoCaso`.

## 7. Caminho de execução

**CLI, não tela.** Pelo precedente da TOP LIFE I (2.901 boletos → 500 por timeout de 30s na tela), e
agora são 5.096 linhas válidas: `app:cobranca:importar-receitas`, com `APP_DEBUG=0` e `memory_limit`
alto. Em prod: `scp` → `docker cp` → `docker exec -w /var/www/app`.

**Par prever/confirmar**, com o mesmo cuidado de **estado intra-execução** que já falhou duas vezes nesta
frente: a prévia tem de simular o que a confirmação faria, inclusive o efeito de linhas anteriores da
MESMA execução (ver `feedback_previa_precisa_de_estado`).

## 8. Como se prova

- **prévia × confirmação idênticas** nos 18 campos, não por amostra — foi assim que o defeito escapou antes;
- **reimportar o mesmo arquivo não cria pagamento nenhum** na 2ª vez;
- **linha com `Recebimento = "-"` é ignorada** — e o teste mede que os R$ 2.045.780 NÃO entram (§2);
- um pagamento importado **abate exatamente** o mesmo que abateria digitado à mão;
- obrigação criada por R1 nasce **liquidada** com o valor recebido, e o saldo do caso não muda por causa dela;
- desconto negativo compõe o principal sem gerar pagamento negativo;
- **isolamento por tenant** em tudo;
- ⚠️ **a soma da coluna I por carteira bate com o total importado**, ao centavo.

**Viés de confirmação = a contabilidade.** Depois de importar, o saldo tem de contar a mesma história dos
outros três relatórios — e agora os quatro são da mesma data (R4), então a conferência é possível pela
primeira vez.

## 9. Fora de escopo

- **Reativação por importação (D6)** — etapa 3, só depois desta.
- **Aceitar boleto sem principal** (R$ 4.390,86 da TOP LIFE I) — pendência declarada pelo dono em 01/08:
  medir antes se o boleto é acessório de um de taxa.
- **Marca de origem no pagamento** — dispensada por R3.

## 10. Estado ao abrir a frente

- `master` local em **`40c3e05a`** (etapa 1 commitada), **6 commits não publicados**.
- Suíte **3136/3136**.
- Planilhas de 03/08 09:48–09:53, as três de cada carteira, **mesma data** — gitignored, PII.
