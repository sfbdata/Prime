# HANDOFF — Importar "Receitas detalhadas" (etapa 2 de 3) — EM ANDAMENTO

**Aberto em 2026-08-01, reescrito em 2026-08-03** depois que a medição derrubou parte do que estava aqui.
Risco **ALTO**. Spec: `docs/specs/cobranca-importar-receitas.md` (é ela que manda; este arquivo é o estado).

---

## 1. Estado do repositório

- `master` local em **`edacfacc`**, **8 commits NÃO publicados**. Nada em produção.
- Suíte **3149/3149**. `lint:container` e `lint:twig` verdes. **Sem migration** nesta etapa.
- Commits desta etapa: `81aa3166` (leitura da planilha) e `edacfacc` (gravação + comando).
- A etapa 1 (excluir recebimento) está fechada em `40c3e05a`.

## 2. ⚠️ O que a medição de 03/08 DERRUBOU deste handoff

A versão anterior afirmava, com medição de 01/08:

> *"Incluir 'Aberta' não acrescentou uma linha sequer. Toda linha do relatório já tem `Recebimento`
> preenchido."*

**É falso nos arquivos de 03/08.** Medido: **2.094 linhas** com `Recebimento = "-"` e `Valor recebido = 0`,
somando **R$ 2.045.780** na coluna nominal — boletos em aberto, não receita. Se entrassem, o sistema
registraria dois milhões que ninguém recebeu.

🔑 **Lição:** fato medido tem data de validade quando a fonte é um export que o humano refaz. Remedir
custou dois minutos.

## 3. A descoberta que mudou o desenho da etapa

Casar recebimento por `(caso, NN, competência)` — o plano original — pousa em **3,8%** das linhas.

| | TOP LIFE I | TOP LIFE II | total |
|---|---|---|---|
| NNs com recebimento válido | 1.220 | 858 | **2.078** |
| existem como obrigação | 1 | 79 | **80** |
| acordos citados / que existem | — | — | **106 / 0** |

**Causa estrutural, não defeito:** o sistema só conhece o que a *Inadimplência* traz, e ela só traz o que
**não foi pago**. A Receitas fala dos pagos. Conjuntos quase disjuntos por construção — e o overlap é de
3–5% em **todos** os sete meses, então não é atraso que o tempo resolve.

Daí a decisão R1 do dono: **criar a obrigação que falta, já paga**.

## 4. Decisões do dono (01–03/08)

| # | Decisão |
|---|---|
| R1 | Boleto desconhecido → **cria a obrigação já paga**. O sistema passa a ter o panorama do ano. |
| R2 | **Coluna I** (`Valor recebido`) é o dinheiro, não a H. |
| R3 | Pagamento importado é **igual ao digitado**: sem marca de origem, apagável à mão, e reimportar traz de volta. |
| R4 | Os relatórios têm de ser da **mesma data**. Os de 03/08 (09:48–09:53) cumprem. |
| R5 | **Histórico pago em SEÇÃO PRÓPRIA na tela**, separado do em aberto. |

**Por que R5 é condição e não enfeite:** um devedor com 7 boletos pagos e 3 em aberto passaria a mostrar
10 linhas com as 3 que importam no meio. Não é risco de dinheiro (obrigação paga entra no bruto e sai no
alocado, saldo inalterado — medido); é risco de leitura, e o ganho do panorama se perderia na tela onde
ele deveria aparecer.

## 5. O que JÁ ESTÁ PRONTO (não refazer)

- **`TopLifeReceitasAdapter`** + `ReceitaImportavel` + `ResultadoLeituraReceitas` — leitura, descarte do
  "-", agregação por NN, baldes por classe de conta. **10 testes.**
- **`ImportarReceitasUseCase`** (`prever`/`confirmar`) + `EstadoDaImportacaoDeReceitas` +
  `ResultadoImportacaoReceitas`. **3 testes funcionais** contra o banco real.
- **`ImportarReceitasCommand`** (`app:cobranca:importar-receitas`), dry-run por padrão.
- **`AlocacaoPagamentoRepository::existeNaObrigacaoComData`** — a chave de idempotência.

### Provas já feitas (por injeção de defeito)

| Defeito injetado | Quebra |
|---|---|
| não descartar o `-` | 1 teste do adapter |
| ler a coluna H | 3 testes do adapter |
| prévia sem estado intra-execução | o teste dos 13 campos |
| sem idempotência | o teste de reimportar |

## 6. O QUE FALTA

1. **R5 — a tela.** Separar, no `objeto_show`, o que está **em aberto** do **já pago no ano**, com o total
   à mostra e o detalhe sob demanda. É o que falta de produto.
2. **Dry-run contra as planilhas reais de 03/08** — mostra, antes de qualquer escrita, quantas obrigações
   seriam criadas de verdade. Ainda **não foi rodado**.
3. **`/review` duas passadas** (risco ALTO), com correção entre elas.
4. **Conferência contra a contabilidade**: o total recebido tem de bater, ao centavo, com a soma da coluna
   `Valor recebido` da planilha. Os quatro relatórios agora são da mesma data — é a primeira vez que essa
   conferência é possível.

## 7. Armadilhas medidas nesta frente (não repita)

- **A prévia mente se não carregar estado intra-execução.** Já aconteceu duas vezes. Por isso prévia e
  confirmação compartilham `EstadoDaImportacaoDeReceitas` — um caminho só, sem como divergirem.
- **Comparar prévia × confirmação por amostra não prova nada.** O teste achata os 13 campos e compara de
  uma vez: quem escolhe os campos do assert escolhe mal.
- **Serviço só usado por teste é *inlined* pelo Symfony** e some do container (`ServiceNotFoundException`).
  O UseCase só ficou acessível quando o Command passou a injetá-lo.
- **`AcordoDoRelatorio` usa `parcelaIndice`/`parcelaTotal`**, não `parcela`/`totalParcelas`.
- **Teste alheio consertado no caminho:** `ImportarRelatorioCarteiraTest` buscava o caso com `findOneBy`
  por tenant **sem ORDER BY**, num cenário com 4 casos — intermitente. Cuidado ao provar esse tipo de
  conserto: o 1º e o 4º caso têm a MESMA pessoa, então os dois extremos da ordenação passam e a prova
  parece falhar. Só o 2º ou o 3º discriminam.

## 8. Pendências do dono

- ⏳ **Smoke do caso 193** (aberto desde 01/08) e o smoke da etapa 1 (botão Excluir no recebimento, modal,
  e cancelar acordo com parcela paga depois de apagar).
- ⏳ **8 commits não publicados.**
- ⏸️ **Boleto sem principal** (R$ 4.390,86 da TOP LIFE I): pendência declarada — medir antes se o boleto é
  acessório de um de taxa. É pré-requisito de conferência fina, não do importador.

## 9. A etapa 3, que vem depois

**D6 — reativação por importação** (`docs/specs/cobranca-cancelar-acordo.md` §3.2). Só depois desta, e o
motivo agora está medido: os 106 acordos da Receitas **não existem no sistema**, então é a criação em R1
que dá a ela em que se apoiar.

## 10. Onde estão as planilhas

`docs/gestao-cobrancas/planilhas atualizadas/` — **gitignored (PII)**. Usar as de **03/08**
(`..._2026_08_03_...`), que são as três da mesma data. Nunca commitar, nunca colar conteúdo.
