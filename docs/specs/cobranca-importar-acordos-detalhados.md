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

O item 2 **não é hipótese**. Medido contra o banco de **produção** em 2026-07-29:

| Acordo | Unidade / Sacado | NNs indevidos abertos | Principal duplicado |
|---|---|---|---|
| 28 | QUADRA 01 CHACARA 01/09 — Francisco de Carvalho Coutinho | 60490 | R$ 145,00 |
| 31 | QUADRA 04 CHACARA 02/23 — Alilson Pereira de Sousa | 60049, 60240 | R$ 290,00 |
| 37 | QUADRA 05 CHACARA 03/04 — Gessi Pereira dos Santos | 60145, 60334, 60812, 61326 | R$ 680,00 |

**Total: R$ 1.115,00 de principal**, e crescendo — juros, multa e honorários são calculados ao vivo e
seguem correndo sobre dívida já renegociada. Das 25 contas originais dos 7 acordos, só essas 7 existem
no banco; as outras 18 nunca foram importadas.

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
- `acordoOrigem` = o acordo (achado por `numero_externo` + carteira; **não cria acordo novo** — se o
  acordo não existe, a aba é reportada e ignorada, porque o acordo é responsabilidade da inadimplência)
- `valorOriginal` = **soma da coluna "Valor acordado"** daquele NN
- **honorários = 0** (decisão #8 da spec irmã: acordo não cobra honorários)
- `vencimento` = o da planilha; encargos ao vivo a partir dele
- `referenciaExterna` = NN

### 3.2. Reconciliar contas originais — **a correção**
Para cada NN da seção "contas originais":
- Se **existe** `Obrigacao` com esse `referencia_externa` na carteira **e** `acordo_substituto_id` é
  nulo → `setAcordoSubstituto(<acordo>)`.
- Se já está marcada → no-op.
- Se **não existe** → **não cria**. Reporta como "conta original ausente do sistema".

**Nunca apaga** (invariável 14 — a obrigação fica no histórico, marcada). O mecanismo é o mesmo que o
`CriarAcordoUseCase` já usa quando um acordo nasce pela tela; `acordoSubstituto` é honrado em todo o
`ObrigacaoRepository` e no `MontarDetalheCasoUseCase` (verificado), então marcar de fato tira do saldo.

> **Decisão consciente — não criar as 18 contas ausentes.** Criar dívida morta só para anulá-la em
> seguida inventa passivo que nunca foi importado, e alimenta exatamente a classe de bug já vista neste
> domínio (acordo rompido restaurando originais e contando o dinheiro 2×). Elas ficam fora, contadas
> no resumo.

### 3.3. Situação do acordo
`Situação:` do cabeçalho → `StatusAcordo`. Mapeamento a fechar na implementação; `Em andamento` → `Ativo`
é o único caso presente no dado atual. Situação desconhecida **não** altera o status: reporta e mantém.

## 4. Divergência de valor entre as fontes

Medido: 3 dos 7 NNs valem **R$ 145,00** no banco de produção e **R$ 170,00** na planilha de acordos
(60049, 60240, 60490).

**Regra: não sobrescrever.** A obrigação existente mantém seu valor; só é marcada como substituída, e a
divergência entra no resumo. O valor em produção pode refletir pagamento parcial ou a taxa vigente à
época; alterá-lo mexeria em dinheiro sem base documental.

Corolário: o casamento é **por NN**, nunca por valor.

## 5. Fora de escopo (decisão do dono, 2026-07-29)

- **Baixa automática de pagamento.** As colunas `Liquidação` / `Valor liquidado` **não** geram
  `RegistrarLiquidacaoUseCase` nesta entrega. Hoje há **zero** parcelas liquidadas — nada a ganhar,
  muito a perder (baixa de pagamento é irreversível na prática). O resumo avisa "N parcelas constam
  liquidadas na planilha, confira à mão". **Reavaliar quando a planilha vier com parcelas pagas.**
- Criar acordo que não veio pela inadimplência (§3.1).
- Reconstruir contas originais ausentes (§3.2).
- Leitor de boleto.

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

Segunda execução do mesmo arquivo não altera nada.

## 8. Impacto operacional (comunicar antes de rodar em prod)

O saldo devedor de **três unidades reais cai** ao confirmar. Relatórios gerenciais já emitidos passam a
discordar do sistema. Isso é o comportamento correto aparecendo — mas a equipe de cobrança precisa ser
avisada, porque são sacados com quem se negocia.

Ordem recomendada: rodar o dry-run, conferir a tabela contra §1, confirmar, e só então emitir novo
relatório.

## 9. Testes

- **Unit do adapter:** múltiplas abas; duas seções; NN de parcela em várias linhas soma corretamente;
  cabeçalho (número, unidade, sacado, situação); rodapé ignorado; aba sem seção de parcelas.
- **Unit/functional do UseCase:**
  - parcela ausente é criada com honorários 0 e `acordoOrigem` correto;
  - conta original existente e aberta é marcada com `acordoSubstituto`;
  - conta já marcada não é remarcada (idempotência);
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
