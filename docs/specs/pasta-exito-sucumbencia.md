# Pasta, Financeiro: honorários de êxito e sucumbência (previstos) e o "Total contratado"

Risco: **BAIXO/MÉDIO** (dado financeiro da pasta, migration em `pasta_pagamento`). Frente
`pasta-exito-sucumbencia`, aprovada pelo dono em 23/10/2026.

Desenho: `docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS 18.10.26.dc.html` (só no checkout
principal; não é copiado para cá). Pontos: `FIN_TIPOS` L.3584-3589, modal L.2055-2124 e
L.3702-3705, `finVals` L.3591-3720 (linha L.3615, resumo L.3677), faixa L.1878-1892, resumo do card
L.2011-2013, impressão `impFinanceiro` L.4849-4860, pendências `tabPendencias` L.5492-5510.

## 1. Regra

No modal "Adicionar pagamento", os tipos **Êxito** e **Sucumbência** deixam de ser desabilitados.
Eles não pedem valor nem vencimento: pedem só o **percentual** (padrão 20 no êxito e 10 na
sucumbência). O lançamento nasce como **previsto**: um honorário que só existe se houver resultado.

- Grava **uma** linha: `tipo` = `exito` | `sucumbencia`, `percentual` = N, `vencimento` nulo,
  descrição "Honorários de êxito (N%)" ou "Sucumbência estimada (N%)" (N com vírgula e sem zeros
  à direita: 20, 12,5).
- **Previsto** = tipo êxito ou sucumbência **e não pago**. Não é coluna: é derivado, como o resto
  do estado do pagamento.
- Percentual fora de (0, 100]: 422 com "Informe um percentual entre 0 e 100." e nada gravado.
- Contrato e custas passam a gravar o `tipo` também (o avulso antigo fica `contrato`, o padrão).
  Sem backfill: as custas lançadas antes desta frente seguem como `contrato` (o tipo só muda o
  comportamento de êxito e sucumbência).

## 2. Decisões do dono

1. **Base do %** — um serviço único, `App\Pasta\Service\BaseDoPercentual`. **Decisão C8 (23/10):**
   a base é o **Total contratado** = soma das linhas **não previstas**, pagas ou não (é o
   `baseCausa` do desenho, L.3600: `pct == null && !prev`). Não usa mais o `pasta.valor_causa`.
   O valor em R$ de um previsto com percentual = `round(base × pct / 100)` em centavos, calculado
   na **leitura** por esse serviço (o card, a impressão e o "+ R$ X previsto" leem dele).
   Ao **congelar** (decisão 3), a base é relida do banco sob a trava da pasta
   (`LancamentosDaPastaSobTrava`, `SELECT … FOR UPDATE`), numa transação, na ordem pasta → linhas.
   Com a base zerada, "Marcar como recebido" num previsto é recusado (422), sem gravar R$ 0,00.
2. **O previsto fica fora** do contratado, do recebido, dos vencidos, da régua (barra) e dos
   relatórios e totais. Ele só aparece como linha (selo azul "Previsto") e no
   "+ R$ X previsto em êxito | em sucumbência | em êxito e sucumbência".
3. **O valor congela** só em dois gestos:
   - "Marcar como recebido" (`AlternarQuitacaoDoPagamentoUseCase`): grava o valor calculado
     naquele instante e zera o percentual;
   - "Corrigir valor" (`CorrigirValorDoPagamentoUseCase`): grava o valor digitado e zera o
     percentual. A conferência de "valor exibido" compara com o valor **calculado**.
   Desfazer o recebimento de um êxito congelado faz a linha voltar a ser prevista, com o valor fixo.
4. **C8, o cartão da faixa:** o card "Valor da causa" do desenho (L.1878-1892) se chama **"Total
   contratado"** (desvio de nome aprovado). É a mesma soma da base, **só leitura**, com a sublinha
   "total dos pagamentos · sincronizado" e o título do desenho. Sai o card editável "Honorários
   contratuais" e a edição do valor da causa **nesta tela** (o valor da causa continua editável no
   modal do Processo). A coluna `valor_causa` e a rota `pasta_atualizar_valor_causa` ficam.

### Usos do `pasta.valor_causa` que NÃO mudam nesta frente (decisão do dono pendente)

- `RegistrarParcelamentoDaPastaUseCase::lerTotal`: contrato/custas com base "% da causa";
- `PastaResumoImpressaoOutput::financeiro` ("Valor da causa" na folha do resumo);
- `PastaRepository::mediaValorCausaPorCliente` (card "Média por CPF/CNPJ");
- `MontadorDosDadosDoProcesso` e `ResumoDoProcesso` (dados do processo, Push);
- `MontadorDeContextoDaPasta` (contexto da Inteligência);
- `PastaController::atualizarValorCausa` + `AtualizarValorCausaUseCase` (rota sem tela agora).

## 3. Migration `Version20261020151400` (`pasta_pagamento`)

```sql
ALTER TABLE pasta_pagamento ADD tipo VARCHAR(20) DEFAULT 'contrato' NOT NULL;
ALTER TABLE pasta_pagamento ADD percentual NUMERIC(5, 2) DEFAULT NULL;
ALTER TABLE pasta_pagamento ALTER vencimento DROP NOT NULL;
-- CHECKs (o Doctrine não os representa; não entram no diff do mapeamento)
ck_pasta_pagamento_tipo:       tipo IN ('contrato','exito','sucumbencia','custas')
ck_pasta_pagamento_percentual: percentual IS NULL
                               OR (tipo IN ('exito','sucumbencia') AND pago_em IS NULL
                                   AND percentual > 0 AND percentual <= 100)
ck_pasta_pagamento_vencimento: vencimento IS NOT NULL OR tipo IN ('exito','sucumbencia')
```

`tipo` é o enum PHP `App\Pasta\Entity\TipoDePagamento` (backed string). O `valor` da linha
prevista guarda o valor calculado **no lançamento** (um retrato): é o que o histórico mostra como
"era" se ela for corrigida; enquanto há percentual, nenhuma tela lê esse retrato.

## 4. Telas

- **Modal** (`show.html.twig`): os 4 tipos com as dicas exatas do `FIN_TIPOS`. Em êxito ou
  sucumbência, some o bloco de valor/entrada/parcelas/vencimento/juros (e a descrição livre, desvio
  antigo do sistema) e aparece o bloco do desenho: o % ("% sobre o proveito" / "% de sucumbência")
  e a "Base" só leitura (o Total contratado). Prévia: uma linha, "previsto", com o valor calculado.
  O cabeçalho do modal diz "Total contratado R$ X · cálculo automático".
- **Card Pagamentos**: linha prevista com selo azul "Previsto" e "N% sobre o total contratado ·
  condicionado ao resultado" (C8; congelada por correção: "condicionado ao resultado"). Ordem:
  pendentes → previstos → pagos. Abaixo da barra, com `bi-graph-up-arrow`, o "+ R$ X previsto
  em …" (só quando há previsto).
- **Faixa**: card "Total contratado" só leitura; o número acompanha cada resposta do card.
- **Impressão** (extrato e bloco Financeiro): previsto fora do Contratado/Recebido/A receber,
  " · Previsto (êxito/sucumbência) R$ X" no cabeçalho, situação "Previsto", vencimento vazio e
  " (N%)" ao lado do valor.
- **Pendências**: previsto nunca é vencido (e não tem vencimento).

## 5. Testes

- Unit: `BaseDoPercentualTest` (soma de contrato+custas, previsto fora, pago entra; valor do
  previsto); `RegistrarParcelamentoDaPastaUseCaseTest` (êxito/sucumbência gravam uma linha com tipo,
  %, vencimento nulo e descrição; 0 e 101 recusados; contrato/custas gravam o tipo);
  `PastaPagamentosOutputTest` (previsto fora dos totais e da barra, texto do previsto, ordem);
  `PastaExtratoImpressaoOutputTest` (previsto fora, situação "Previsto"); `PastaPendenciasOutputTest`
  (previsto não é vencido); `AlternarQuitacaoDoPagamentoUseCaseTest` e
  `CorrigirValorDoPagamentoUseCaseTest` (congelam e zeram o %).
- Functional: `PastaPagamentoPrevistoControllerTest` (lançar êxito e sucumbência pelo POST, tipo
  e %, 422 fora de 0-100, 404 em pasta de outro escritório, quitar congela, corrigir congela);
  `PastaFinanceiroArranjoTelaTest` (card "Total contratado" sem campo editável, filho direto da
  faixa; botões de êxito/sucumbência habilitados no modal).

## 6. Desenho item a item

- ✓ `FIN_TIPOS`: 4 tipos no modal, êxito e sucumbência habilitados, dicas com o texto do desenho
  (salvo a base, abaixo).
- ✓ Modal em êxito/sucumbência: some valor/parcelas/vencimento/juros; entra o % com o rótulo do
  desenho ("% sobre o proveito" / "% de sucumbência") e a "Base" só leitura.
- ✓ Padrões 20 (êxito) e 10 (sucumbência); descrição "Honorários de êxito (N%)" / "Sucumbência
  estimada (N%)".
- ✓ Linha prevista: selo azul "Previsto", sem vencimento, "N% sobre o total contratado ·
  condicionado ao resultado" (texto de C8).
- ✓ "+ R$ X previsto em …" abaixo da barra com `bi-graph-up-arrow`; previsto fora da barra e dos
  totais.
- ✓ Faixa: card "Total contratado" (desvio de nome aprovado em C8), só leitura, "sincronizado".
- ✓ Impressão: previsto fora dos totais, situação "Previsto", " (N%)" ao lado do valor.
- ✓ Pendências: previsto não vence.
- ✓ (desvio de C8) As dicas do `FIN_TIPOS` de êxito e sucumbência dizem "sobre o total
  contratado" no lugar de "sobre o valor da causa" do desenho, coerentes com a base de C8.
- ✗ Descrição livre some no previsto (o desenho não a tem no bloco do %); o sistema a tinha.
