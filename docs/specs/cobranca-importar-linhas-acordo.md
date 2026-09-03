# Cobrança — Importar linhas de acordo como Acordo

> Ponto **#7** dos ajustes pós-taxa. Risco **MÉDIO/ALTO** (importação + acordo + dinheiro). **Base de implementação:
> a branch de encargos** (`cobranca-encargos-cascata`) — o importador mudou lá (o importado deixou de "congelar";
> obrigação nasce viva). Construir sobre `origin/master` daria conflito e comportamento antigo.

## 1. Objetivo

No relatório de inadimplência (TOPLIFE), linhas com o **mesmo NN** cuja coluna "Informações do acordo" está
preenchida representam um **acordo**. Hoje o import só cola esse texto na **observação** de uma obrigação comum
(`BoletoImportavel::observacao()`), sem criar Acordo. Passar a **criar um Acordo de verdade**, alinhando o sistema
com o documento.

## 2. Modelo lido do relatório (2 exemplos reais)

- **Formato da coluna:** `Acordo <N> - Parc. <p>/<total>` (ex.: "Acordo 28 - Parc. 1/1", "Acordo 31 - Parc. 1/3").
- **1 NN = 1 parcela** de um acordo (um boleto). As várias **linhas do mesmo NN** são a **composição** daquela
  parcela (taxa de condomínio, juros, multa, honorário advocatício…).
- **Número do acordo (28, 31)** amarra as parcelas do mesmo acordo. **Dedup: (carteira + número)** — o número é
  **sequencial por carteira** (pode haver "Acordo 31" na TOPLIFE I e outro na TOPLIFE II; nunca dois na mesma
  carteira). O import roda sempre dentro de **uma carteira escolhida**.
- **`p/total`:** `1/1` = parcela única (acordo completo no relatório). `1/3` = multi-parcela — as parcelas 2/3 e
  3/3 **não estão** no relatório de inadimplência (só entram quando vencerem, em NNs futuros).

## 3. Regras de mapeamento

### 3.1. Reconhecer o acordo
O adapter (`TopLifeInadimplenciaAdapter`) passa a **parsear** a coluna em `{ numero, parcelaIndice, parcelaTotal }`
(quando casar `Acordo <N> - Parc. <p>/<t>`); senão, `null` (obrigação comum, comportamento atual). Expor isso no
`BoletoImportavel` (ex.: `?AcordoDoRelatorio $acordo`).

### 3.2. Criar/achar o Acordo e anexar a parcela
No `ImportarRelatorioCarteiraUseCase`, para um NN com acordo:
1. Resolver o Objeto/Caso como hoje (dedup por identificação; caso **cobrável** do objeto — não encerrado, retificado em 03/09/2026).
2. **Achar ou criar o Acordo** por **(carteira + número)** — via um campo novo de identidade externa no Acordo
   (§4). O acordo pertence ao **caso** do objeto.
3. Criar a obrigação daquele NN como **parcela** do acordo (`acordoOrigem = acordo`), **não** como dívida solta:
   - `valorOriginal` = **principal negociado** = soma da coluna **"Valor"** das linhas do NN (o honorário do
     relatório **já faz parte** do valor negociado — decisão do dono). **Nunca** usar o "Total" (senão os
     juros/multa seriam contados 2×: o motor recalcula ao vivo).
   - Encargos **ao vivo** (juros/multa) a partir do vencimento da parcela; **honorários = 0** (decisão #8 — acordo
     não cobra honorários; a parcela tem taxa de honorários zero, via a feature taxa por-obrigação).
   - `dataAcordo` e (descritivo) `valorTotalNegociado` preenchidos quando derivável.
4. **Idempotência:** reimportar atualiza — a parcela por **NN** (chave já usada hoje) e o acordo por
   **(carteira + número)**. Não duplica.

### 3.3. Multi-parcela (7b — legado, manual)
- `total > 1`: o import registra a(s) parcela(s) presente(s) e o acordo fica com **`total` esperado > parcelas
  cadastradas** → indicar "faltam parcelas". As faltantes entram por **importação futura** (quando vencerem) **ou
  à mão** (a funcionária tem o boleto). **Sem leitor de boleto** nesta entrega.
- **Verificar** se a tela de acordo já permite **acrescentar** uma parcela a um acordo existente
  (`EditarAcordoUseCase`/`ParcelaAcordoType`); se não permitir, essa pequena adição faz parte do 7b.

## 4. Modelo de dados

- **Acordo ganha identidade externa:** coluna nova `numero_externo` (nullable int) em `cobranca_acordo` — o número
  do acordo na origem (TOPLIFE), para dedup por (carteira + número) na (re)importação. Nulo para acordos criados
  manualmente. A carteira do acordo é derivável via `caso → objeto → carteira`.
- **Migração:** 1 `ADD COLUMN numero_externo INT NULL` + índice `(tenant_id, numero_externo)` (a carteira entra no
  filtro em memória/DQL). Aplicada pelo humano no deploy.

## 5. Fora de escopo (parkeados)

- **Honorários no acordo (#8): DECIDIDO — não cobra.** A parcela nasce com honorários 0. Nenhum mecanismo novo.
- Leitor automático de boleto (parsing) — legado é manual; novos acordos nascem no sistema já corretos.
- Reconstruir as parcelas 2..N a partir do relatório (elas não estão lá).

## 6. Testes
- Unit do adapter: parseia `Acordo 28 - Parc. 1/1` e `Acordo 31 - Parc. 1/3`; linha sem acordo → `null`.
- Unit do UseCase de importação: NN com "Parc. 1/1" cria Acordo + 1 parcela (`valorOriginal` = soma da coluna Valor;
  honorários 0; viva); reimportar não duplica (dedup carteira+número e NN); "Parc. 1/3" cria acordo marcado como
  incompleto (faltam 2 parcelas); NN sem acordo segue como obrigação comum (regressão).
- Multi-tenant: nunca cruza tenant/carteira; "Acordo 31" de carteiras diferentes não se confunde.
- Suíte de Cobrança verde + global verde (container `-d memory_limit=512M`).

## 7. Coordenação
Construir **sobre a base de encargos** (importador já sem congelamento; parcela viva com honorários 0 depende da
taxa por-obrigação). Alinhar com o chat que implementa a taxa antes de mesclar.
