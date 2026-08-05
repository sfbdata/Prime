# Cobrança — Importar Acordos Detalhados (e reconciliar contas originais)

> Risco **ALTO**: mexe em dinheiro que já está em produção. Exige revisão dupla (`feature-review-agent`
> antes e depois das correções). Complementa — **não substitui** —
> `docs/specs/cobranca-importar-linhas-acordo.md`, que já está implementada.

## 1. Por que existe

O acordo já entra no sistema pela coluna "Informações do acordo" da inadimplência
(`ImportarRelatorioCarteiraUseCase`, dedup por `numero_externo` + carteira). Duas coisas aquele caminho
**estruturalmente não consegue** fazer, porque a inadimplência só mostra o que está vencido:

1. **Parcelas futuras não aparecem.** Medido em 2026-07-29: dos 12 boletos de parcela dos 7 acordos,
   **5 estão ausentes** da inadimplência — R$ 1.399,49 a receber que nenhum relatório enxerga.
2. **Contas originais não são marcadas como substituídas.** A contábil as remove do relatório ao fechar
   o acordo; o importador não apaga o que sumiu. Se uma importação anterior já as tinha criado, elas
   ficam **abertas para sempre**, somando junto com a parcela do acordo.

O item 2 **não é hipótese**. Medido contra o banco de **produção** — números **revisados em 2026-07-30**,
depois que a investigação da chave de importação (`cobranca-importar-chave-competencia.md`) mostrou que o
NN se repete entre carteiras:

| Acordo | Unidade / Sacado | NNs indevidos abertos | Principal duplicado |
|---|---|---|---|
| 37 | QUADRA 05 CHACARA 03/04 — Gessi Pereira dos Santos | 60145, 60334, 60812, 61326 | R$ 680,00 |

**Total: R$ 680,00 de principal, 1 sacado**, e crescendo — juros, multa e honorários são calculados ao
vivo e seguem correndo sobre dívida já renegociada.

> **Correção de um erro desta spec.** A versão de 29/07 afirmava **R$ 1.115,00 em 3 sacados**, incluindo os
> acordos 28 e 31. Estava errado. Os NNs 60049, 60240 e 60490 que apareciam no banco são dívidas de
> **competência 2022, R$ 145,00, da carteira TOP LIFE I** — outros boletos, que apenas repetem o número dos
> boletos de 2026 dos acordos 28 e 31. Não são as contas originais desses acordos, e **não podem ser
> tocadas**: marcá-las como substituídas apagaria R$ 435,00 de cobrança legítima de terceiros.
>
> O erro veio de casar boletos **só pelo NN**. É a mesma causa raiz da spec da chave — por isso ela é
> pré-requisito desta.

Das 25 contas originais dos 7 acordos, **4 existem** no banco (todas do acordo 37, conferidas por NN +
competência + vencimento + valor); as outras **21 nunca foram importadas**.

Portanto: **isto é a correção de um bug de dinheiro em produção**, não uma melhoria de rastreabilidade.

## 2. Layout medido da fonte (2026-07-29)

Uma aba **por acordo** (`Acordo n28`, `Acordo n21`, …), formato de ficha, não de tabela.

- **Cabeçalho (L6–L10):** `Acordo de número <N>` · `Unidade:` · `Data base:` · `Sacado:` ·
  `Valor total das contas originais:` · `Criado em:` · `Valor final acordado:` · `Situação:`
- **Seção "Relação das contas originais"** — colunas: Nosso Número · Classe de Conta · Competência ·
  Vencimento · Valor original (R$) · Detalhamento
- **Seção "Parcelas das contas geradas pelo acordo"** — colunas: Nosso Número · Classe de Conta ·
  Parcela (`p/t`) · Competência · Vencimento · **Liquidação** · Valor acordado (R$) · Valor liquidado (R$)
- Rodapé: `Filtros:` · endereço da contábil · `Emissão:`

Um mesmo NN de parcela ocupa **várias linhas** (a composição: taxa de condomínio de cada competência +
honorário). O valor da parcela é a **soma** das linhas daquele NN.

## 3. Operações

Em ordem crescente de risco. As três são idempotentes.

### 3.1. Completar parcelas futuras
Parcela da planilha sem `Obrigacao` de mesmo NN na carteira → cria:
- `acordoOrigem` = o acordo (achado por `numero_externo` + carteira). ⚠️ **REVOGADO em 2026-08-07 pelo
  item 5** (`docs/specs/cobranca-importar-acordos-criar-acordo.md`): esta linha dizia *"não cria acordo
  novo — a aba é reportada e ignorada, porque o acordo é responsabilidade da inadimplência"*. O
  fundamento caiu na medição: quem cria acordo é a **Receitas**, e só quando alguém **pagou** uma
  parcela — 38 dos 392 acordos declarados pela contábil não nasciam por causa disso. Hoje a aba cujo
  acordo não existe **cria o acordo**, com quatro recusas (ver a spec do item 5)
- `valorOriginal` = **soma da coluna "Valor acordado"** daquele NN
- **honorários = 0** (decisão #8 da spec irmã: acordo não cobra honorários)
- `vencimento` = o da planilha; encargos ao vivo a partir dele
- `referenciaExterna` = NN

### 3.2. Reconciliar contas originais — **a correção**
Para cada NN da seção "contas originais":
- Se **existe** `Obrigacao` com esse `referencia_externa` **E a mesma competência** na carteira, **e**
  `acordo_substituto_id` é nulo → `setAcordoSubstituto(<acordo>)`. **O casamento por NN sozinho é
  proibido aqui** (ver `cobranca-importar-chave-competencia.md`): foi o que quase marcou 3 dívidas de
  2022 da TOP LIFE I como substituídas por acordos de 2026 da TOP LIFE 2.
- Se já está marcada → no-op.
- Se **não existe** → **cria, já nascendo substituída** (§3.2.1).

**Nunca apaga** (invariável 14 — a obrigação fica no histórico, marcada). O mecanismo é o mesmo que o
`CriarAcordoUseCase` já usa quando um acordo nasce pela tela.

**Prova de que marcar resolve** (lida em `ObrigacaoRepository::doCasoExigiveis`, a fonte do saldo —
SPEC §12, invariável 15): a query exclui `asub.id IS NOT NULL AND asub.status` vigente, isto é, uma
obrigação marcada com `acordoSubstituto` de acordo **Ativo/Cumprido** sai do saldo. A mesma query
descarta parcelas de acordo **Rompido/Cancelado** e, por derivação, restaura as originais
(invariável 20). Logo, o cenário temido — romper o acordo e passar a contar original + parcela — **já
está coberto** e não exige mecanismo novo. O teste de regressão de §9 existe para não deixar isso
regredir.

### 3.2.1. Criar as 21 contas ausentes (decisão do dono, 2026-07-30 — reverte recomendação anterior)

Das 25 contas originais dos 7 acordos, **4 existem** no banco de produção (todas do acordo 37) e **21
nunca foram importadas** (viraram acordo na contábil antes de qualquer importação passar por elas). Elas
são criadas, **já com `acordoSubstituto` preenchido no mesmo fluxo** — nascem substituídas e portanto
**nunca entram no saldo** (§3.2, `doCasoExigiveis`). Nenhum saldo muda hoje por causa delas.

**Valor histórico envolvido: R$ 3.570,00** (21 × R$ 170,00, valores da planilha).

**Por que reverti minha recomendação.** Eu havia recomendado não criá-las, com o argumento de que
seria "inventar passivo". O argumento estava errado: a dívida **foi real** — o condômino devia aqueles
boletos e o acordo os substituiu. Registrar isso é histórico, não invenção. Sem elas, o card do acordo
mostra "1 substituída" onde o documento da contábil diz 6, e ninguém consegue auditar de onde o acordo
veio.

**Risco aceito pelo dono, explicitamente.** Se um desses acordos for **rompido**, `doCasoExigiveis`
restaura as originais para a cobrança (invariável 20 — comportamento correto e desejado). Com as 21
criadas, voltariam R$ 3.570,00 com juros e multa retroativos ao vencimento original, e com **valor
vindo da planilha, não de um boleto importado** — nunca conferido contra um boleto real. Consequência
prática: num rompimento, o valor restaurado pode não ser exatamente o que a contábil reemitiria, e a
conferência com a contábil passa a ser obrigatória nesse cenário.

**Marcação de procedência (obrigatória).** A obrigação criada por este caminho registra na `observacao`
que veio da planilha de acordos, com a data de emissão do relatório. Sem isso não há como distinguir,
depois, o que foi boleto importado de verdade do que foi reconstruído a partir de documento — e essa
distinção é exatamente o que alguém vai precisar no dia do rompimento.

### 3.3. Situação do acordo
`Situação:` do cabeçalho → `StatusAcordo`. Mapeamento a fechar na implementação; `Em andamento` → `Ativo`
é o único caso presente no dado atual. Situação desconhecida **não** altera o status: reporta e mantém.

## 4. A "divergência de valor" não existia — era casamento errado

A versão de 29/07 desta spec registrava uma divergência: 3 NNs valendo **R$ 145,00** no banco e
**R$ 170,00** na planilha (60049, 60240, 60490), e criava uma regra de "não sobrescrever valor" para
lidar com ela.

**A investigação de 30/07 dissolveu a divergência: não eram os mesmos boletos.** Os do banco são de
competência 2022, carteira TOP LIFE I; os da planilha são de competência 2026, carteira TOP LIFE 2. Duas
dívidas distintas com o mesmo número. Não há divergência a tratar — havia um casamento errado a corrigir.

Regras que ficam:

- **Casar por NN + competência**, dentro da carteira. Nunca por NN sozinho, nunca por valor.
- **Não sobrescrever o valor** de obrigação existente. A regra sobrevive, agora como princípio geral (a
  planilha não é autoridade sobre dinheiro já lançado), não como remendo para uma divergência inexistente.
- Qualquer diferença de valor entre as fontes para um par (NN, competência) que **realmente** case entra no
  resumo como divergência — e aí é sinal de problema de verdade, não de ruído.

**Lição que a spec preserva:** um número igual não prova que é a mesma coisa. Antes de agir sobre dinheiro
a partir de um casamento, verifique um segundo campo independente — aqui, competência e carteira.

## 5. Fora de escopo (decisão do dono, 2026-07-29)

- **Baixa automática de pagamento.** As colunas `Liquidação` / `Valor liquidado` **não** geram
  `RegistrarLiquidacaoUseCase` nesta entrega. Hoje há **zero** parcelas liquidadas — nada a ganhar,
  muito a perder (baixa de pagamento é irreversível na prática). O resumo avisa "N parcelas constam
  liquidadas na planilha, confira à mão". **Reavaliar quando a planilha vier com parcelas pagas.**
- ~~Criar acordo que não veio pela inadimplência (§3.1).~~ ⚠️ **SAIU do fora-de-escopo em 2026-08-07**
  (item 5), pela mesma razão registrada na §3.1.
- Leitor de boleto.

> Reconstruir as contas originais ausentes **saiu do fora-de-escopo** em 2026-07-30 e virou §3.2.1, por
> decisão do dono.

## 6. Entrega

Comando CLI `app:cobranca:importar-acordos`, mesmo contrato dos demais (`--tenant-id --carteira-id
--usuario-id --arquivo`, dry-run por padrão, `--confirmar` para persistir).

O **dry-run é o produto principal**: imprime, por acordo, as parcelas que criará e as contas originais
que marcará como substituídas, com unidade e sacado — a tabela de §1 tem que sair dele antes de qualquer
escrita.

## 7. Idempotência

- Parcela: por NN.
- Acordo: por `numero_externo` + carteira (nunca cria).
- Substituição: só quando `acordo_substituto_id` é nulo.
- Conta original reconstruída (§3.2.1): por NN — se já existe (criada por execução anterior **ou**
  importada de verdade depois), **não recria**. É o ponto mais fácil de duplicar dinheiro nesta entrega.

Segunda execução do mesmo arquivo não altera nada.

## 8. Impacto operacional (comunicar antes de rodar em prod)

O saldo devedor de **uma unidade real cai** ao confirmar — QUADRA 05 CHACARA 03/04, Gessi Pereira dos
Santos: R$ 680,00 de principal, mais os encargos que vinham correndo em cima. Relatórios gerenciais já
emitidos passam a discordar do sistema. Isso é o comportamento correto aparecendo — mas a equipe de
cobrança precisa ser avisada, porque é um sacado com quem se negocia.

As **21 contas reconstruídas (§3.2.1) não mexem em saldo nenhum** hoje: nascem substituídas. Elas
aparecem no histórico do acordo e só voltariam a contar num rompimento — ver o risco aceito em §3.2.1.

Ordem recomendada: rodar o dry-run, conferir a tabela contra §1, confirmar, e só então emitir novo
relatório.

## 9. Testes

- **Unit do adapter:** múltiplas abas; duas seções; NN de parcela em várias linhas soma corretamente;
  cabeçalho (número, unidade, sacado, situação); rodapé ignorado; aba sem seção de parcelas.
- **Unit/functional do UseCase:**
  - parcela ausente é criada com honorários 0 e `acordoOrigem` correto;
  - conta original existente e aberta é marcada com `acordoSubstituto`;
  - conta já marcada não é remarcada (idempotência);
  - **conta original ausente é criada JÁ substituída** e **não aparece no saldo** (§3.2.1) — o teste
    tem de asserir o saldo, não só a existência da linha;
  - **conta reconstruída não é recriada** na segunda execução (idempotência por NN);
  - **rompimento:** ao romper o acordo, as 25 originais (4 reais + 21 reconstruídas) voltam ao saldo e
    as parcelas saem — uma vez cada, sem dobrar. Este é o teste que cobre o risco aceito em §3.2.1;
  - a obrigação reconstruída carrega a **marcação de procedência** na observação;
  - conta original **inexistente não é criada**;
  - divergência de valor é **reportada e não aplicada**;
  - acordo inexistente → aba ignorada e reportada, sem escrita.
- **Regressão de dinheiro:** o saldo do objeto **cai exatamente** o principal das contas marcadas, e as
  parcelas do acordo continuam contando uma única vez. Este teste é o que prova a correção — construir
  reintroduzindo o defeito para provar que ele pega.
- **Multi-tenant:** "Acordo 31" de carteiras diferentes nunca se confunde.
- Suíte de Cobrança verde + global verde.

## 10. Rigor exigido pelo risco ALTO

`feature-review-agent` (read-only) contra esta spec **antes** das correções e **de novo depois**. A
revisão deve olhar especificamente: o saldo após a marcação, o comportamento em caso de rompimento do
acordo (as originais restauram? contam 2×?) e o isolamento multi-tenant.
