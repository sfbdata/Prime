# HANDOFF — Importar "Receitas detalhadas por unidade/cliente" (frente 2 de 3)

**Aberto em 2026-08-01.** Risco **ALTO** (cria pagamento = mexe em dinheiro). Spec + `/review` obrigatórios.

É o **4º e último** relatório da contábil que falta importar. Responde *"o que foi pago"* — ver
`reference_cobranca_relatorios_contabil` na memória.

---

## 1. Aberta × Baixada — MEDIDO, e a resposta é: tanto faz

O dono exportou com as duas situações e perguntou se valia baixar só "Baixada". Medição nos dois
arquivos de 01/08:

| Arquivo | Linhas de dado | Com data de Recebimento | Sem |
|---|---|---|---|
| `top_life_1_Receitas_..._2026_08_01_16_24_37.xlsx` | **3.155** | **3.155** | **0** |
| `top_life_2_Receitas_..._2026_08_01_16_23_03.xlsx` | **1.860** | **1.860** | **0** |

**Incluir "Aberta" não acrescentou uma linha sequer.** Toda linha do relatório já tem `Recebimento`
preenchido. Então: exportar só "Baixada" dá o mesmo dado, com menos ambiguidade — mas não é obrigatório,
e o importador deve **ignorar linha sem data de recebimento** de qualquer forma (defesa, não confiança).

## 2. Layout medido (as duas carteiras são iguais)

Cabeçalho na **linha 7**; dados a partir da 8. Linhas 1–6 são cabeçalho do relatório
(`L. G Soluções Contábeis Eireli`, nome da carteira, título).

| Col | Campo | Observação |
|---|---|---|
| A | Unidade | mesma identificação usada pelas outras planilhas (ex.: `CHACARA 01/02`) |
| B | Sacado | nome — **PII** |
| C | **NN** | o Nosso Número: a chave que liga à obrigação |
| D | **Classe de Conta** | mesma taxonomia da inadimplência: `1.1 - Taxa de condomínio`, `1.4 - Juros`, `1.5 - Multas`, `1.6 - Descontos`, `1.15 - Honorário advocatício` |
| E | **Competência** | fecha a chave `(caso, NN, competência)` já estabelecida |
| F | Vencimento | |
| G | **Recebimento** | a data do pagamento — é ela que faz a linha ser uma receita |
| H | Valor (R$) | |
| I | **Valor recebido (R$)** | ⚠️ **coluna diferente da H** — conferir qual manda |
| J | **Informações do acordo** | mesmo formato da inadimplência: `Acordo 377 - Parc. 1/40` |

Distribuição das classes (TOP LIFE II, 1.860 linhas): 878 taxa · 748 descontos · 106 juros · 106 multas
· 21 honorários · 1 outra.

## 3. Por que o encaixe é bom (e o que reaproveitar)

🔑 **Um pagamento no sistema já tem exatamente a decomposição que esta planilha traz.** A entidade
`Pagamento` guarda `valorDivida` / `valorEncargos` / `valorHonorarios`; as linhas por Classe de Conta
somam para esses três baldes. Não é preciso inventar rateio: a contabilidade já rateou.

Reaproveitar, sem reescrever:
- **`AcordoDoRelatorio` + o regex de `Acordo N - Parc. p/t`** — a coluna J é idêntica à da inadimplência;
- **a chave `(caso, NN, competência)`** — `ObrigacaoRepository::findOnePorReferenciaECompetenciaNoCaso`;
- **o par prever/confirmar** dos outros importadores, com o mesmo cuidado de estado intra-execução (a
  prévia já mentiu duas vezes nesta frente por falta disso);
- **`AlocadorPagamento` / `ReconciliadorLiquidacao`** — quem cria pagamento no sistema hoje.

## 4. Decisões a tomar na spec (não decidir sozinho)

1. **Coluna H ou I?** `Valor (R$)` × `Valor recebido (R$)` divergem em parte das linhas. Qual é o que
   entrou na conta do condomínio? **Medir a diferença e perguntar ao dono.**
2. **Desconto (`1.6`) entra como quê?** Vem negativo. É abatimento do principal, ou uma liquidação
   não-monetária? O sistema tem `Liquidacao` para "quitação sem dinheiro".
3. **Idempotência:** reimportar não pode duplicar pagamento. Qual a chave — `(NN, competência, data de
   recebimento)`? Há mais de uma linha por NN (uma por classe), então o pagamento é **agregado** por
   NN+recebimento, não por linha.
4. **Pagamento importado pode ser apagado à mão?** Cruza com a frente 1 (excluir recebimento).
5. **O que fazer com NN que não existe no sistema** — recusar e reportar (padrão da casa) ou criar?

## 5. Tamanho e caminho de execução

3.155 e 1.860 linhas. Pelo precedente da TOP LIFE I (2.901 boletos → **500 por timeout de 30s na tela**),
esta importação é **CLI**, não tela: `app:cobranca:importar-receitas`, com `APP_DEBUG=0` e
`memory_limit` alto. Em prod, `scp` → `docker cp` → `docker exec -w /var/www/app`.

## 6. Como se prova

- **prévia × confirmação idênticas** (18 campos, não uma amostra — foi assim que o defeito escapou antes);
- reimportar o mesmo arquivo **não cria pagamento nenhum** na 2ª vez;
- um pagamento importado **abate exatamente** o mesmo que abateria digitado à mão;
- obrigação totalmente paga vira **Liquidada**; parcial mantém `restante` correto;
- linha sem data de recebimento é **ignorada** (mesmo que hoje não exista nenhuma);
- isolamento por tenant em tudo.

**Viés de confirmação = a contabilidade.** Depois de importar, o saldo do caso tem de bater com a
inadimplência atualizada — os dois relatórios são da mesma data e têm de contar a mesma história.

## 7. Onde estão as planilhas

`docs/gestao-cobrancas/planilhas atualizadas/` — **gitignored (PII: nome e unidade de centenas de
pessoas)**. Nunca commitar, nunca colar conteúdo em log, issue ou mensagem.
