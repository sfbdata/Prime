# Cobrança — Chave de idempotência da importação: NN + competência

> Risco **MÉDIO** (mexe no importador, que mexe em dinheiro). **Pré-requisito das outras duas specs de
> importação** — se a chave nascer errada, o erro se multiplica por três.
> Investigação de 2026-07-30, medida contra o banco de **produção**.

## 1. O problema

`ObrigacaoRepository::findOnePorReferenciaExternaNoCaso` acha a obrigação por **(caso, tenant, NN)**. O NN
(Nosso Número) **não é único**: a contábil o reaproveita.

Quando dois boletos diferentes compartilham o NN **dentro do mesmo caso**, a importação:

1. **engole o boleto novo** — acha que já existe, não cria, segue adiante (some receita);
2. **grava encargos errados** no boleto antigo — `materializarEncargosImportados` aplica os juros/multa do
   boleto novo sobre a dívida velha.

Não destrói o principal nem o vencimento (o código preserva: *"Preserva o valorOriginal (invariável 20)"*),
e o boleto engolido aparece como "atualizada" no resumo — sinal fraco, fácil de passar batido.

## 2. O que a medição mostrou (e o que ela DESMENTIU)

Contra produção, cruzando o relatório TOP LIFE 2 de 29/07/2026 (531 boletos, faixa 60005–61600) com as
obrigações existentes:

| Medida | Valor |
|---|---|
| Obrigações antigas (venc. < 2025) na mesma faixa de NN | 167 |
| NNs do relatório que colidem com alguma delas | 69 |
| Dessas, com par de 2026 já no banco (convivem) | 59 |
| Sem par de 2026 | 10 |
| **Colisões REAIS (mesmo caso)** | **0** |

**As 69 colisões são todas entre carteiras diferentes.** As 10 sem par foram checadas uma a uma: todas na
carteira **TOP LIFE I**, com identificação de unidade em outro padrão (`12-02`, `25-03`, `07-02E`) — nada a
ver com a TOP LIFE 2 (`QUADRA 01 CHACARA 01/09`). Como a busca é escopada por **caso**, e caso deriva de
objeto que deriva de carteira, o importador **nunca** confundiria os dois.

Dentro de um mesmo relatório da TOP LIFE 2, **nenhum NN se repete** (medido: 0 duplicatas em 531).

**Conclusão honesta: o defeito é real no código, mas não tem nenhuma ocorrência hoje.** A correção é
**precaução**, não conserto. A sequência de NN é por condomínio; quando ela der a volta, a colisão passa a
ser dentro da mesma carteira — e aí o defeito morde.

> Registro do erro de processo: durante a investigação foi afirmado "69 boletos em risco, não importe nada".
> Era alarme falso — faltava cruzar a **carteira**. O dado que muda a conclusão é sempre o que ainda não foi
> medido; sob suspeita de perda de dinheiro, medir até o fim ANTES de recomendar parar.

## 3. Por que a chave NÃO pode ser o vencimento

A primeira proposta foi `(caso, NN, vencimento)`. **O dono derrubou** com um caso concreto:

> *"E se uma dívida antiga de anos atrás teve só o vencimento atualizado para ele pagar?"*

Boleto reemitido mantém o NN e **muda o vencimento**. Uma dívida de 08/2022 reemitida para vencer em
08/2026 teria 4 anos de diferença — a chave por vencimento criaria uma **duplicata**, cobrando a pessoa
duas vezes. Exatamente o dano que a correção existe para evitar.

Vencimento **muda por natureza**. Não serve como identidade.

Também foi descartada a heurística "diferença até 90 dias = mesma dívida": ela erra precisamente no caso
acima, e um limite arbitrário não descreve nenhuma regra do negócio.

## 4. A chave correta: NN + competência

A **competência** é o mês a que a dívida se refere. Ela **não muda** — boleto reemitido de 08/2022 continua
sendo de 08/2022.

| Situação | Interpretação | Ação |
|---|---|---|
| Mesmo NN, mesma competência | a mesma dívida | atualiza encargos (**avisa** se o vencimento mudou) |
| Mesmo NN, competência diferente | dívidas diferentes | cria a nova, **reporta** "NN reutilizado" |
| Dívida antiga reemitida (venc. novo) | mesma competência → mesma dívida | atualiza, **não duplica** |
| Competência ausente na obrigação | indeterminado | cai no comportamento de hoje (só NN) e **reporta** |

Princípio: **na dúvida, criar e avisar — nunca engolir em silêncio.** O erro barato é uma linha a mais,
visível. O erro caro é dívida sumindo sem ninguém ver.

## 5. Modelo de dados

A competência **existe** hoje, mas só como texto dentro de `descricao`, montado pelo próprio importador
(`BoletoImportavel::descricao()`): `"<classes> — competência MM/AAAA"`.

- **Coluna nova** `competencia` em `cobranca_obrigacao` — `varchar(7)`, nullable, formato `MM/AAAA`.
- **Índice** `(tenant_id, referencia_externa, competencia)`.
- **Backfill** por regex sobre `descricao`: `compet.ncia ([0-9]{2}/[0-9]{4})`.

**Cobertura do backfill medida em produção:** 3.270 de 3.300 obrigações (**99,1%**). As 30 restantes são
cadastro manual — ficam com `competencia` nula e caem na regra da última linha de §4, isto é, exatamente o
comportamento atual. **Nada regride.**

O importador passa a gravar `competencia` diretamente (o dado já está em `BoletoImportavel::$competencia`).

## 6. Escopo da mudança

- `Obrigacao`: campo + getter/setter.
- Migração: `ADD COLUMN` + índice + backfill (UPDATE com regex).
- `ObrigacaoRepository`: novo método `findOnePorReferenciaEComperencia(...)`; o antigo permanece para o
  fallback de §4.
- `ImportarRelatorioCarteiraUseCase`: dois pontos de uso (linhas ~117 e ~182) + os novos avisos no
  `ResultadoImportacao`.
- `RegistrarObrigacaoInput` / `EditarObrigacaoInput`: transportar a competência.

**Não muda:** o que a reimportação atualiza (só encargos), a preservação de `valorOriginal` e do
vencimento, e o comportamento de obrigação criada à mão.

## 7. Testes

- Mesmo NN + mesma competência → **atualiza**, não duplica.
- Mesmo NN + competência diferente → **cria** a segunda, ambas coexistem, resumo acusa "NN reutilizado".
- **Dívida antiga reemitida** (mesma competência, vencimento 4 anos depois) → **atualiza**, não duplica, e
  o resumo registra a mudança de data. *É o teste do caso levantado pelo dono; sem ele a spec não está
  provada.*
- Competência nula → cai no fallback por NN (comportamento de hoje).
- **Regressão do caso 61457:** mesmo NN em TOP LIFE I e TOP LIFE 2 → duas obrigações independentes, nenhuma
  interferência. Reproduz o dado real medido.
- Backfill: obrigação com `descricao` no formato padrão recebe a competência; sem o formato, fica nula.
- Multi-tenant: nunca cruza tenant nem carteira.

Cada teste novo deve ser provado **reintroduzindo o defeito** e vendo-o falhar. Suíte de Cobrança + global
verdes.

## 8. Ordem de execução

Esta spec é a **pendência nº 1** definida pelo dono em 2026-07-30, e precede:

2. `cobranca-importar-cadastro-condominos.md`
3. `cobranca-importar-acordos-detalhados.md`

Sobe para produção pelo procedimento completo (`deploy-prod-tls.sh`), porque agora inclui migração — o
plano original supunha "sem migração", e isso mudou quando a chave passou a ser a competência.

**A importação da inadimplência pela tela NÃO está bloqueada** — medição de §2: zero colisões reais.
