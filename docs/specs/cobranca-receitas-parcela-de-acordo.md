# SPEC — Etapa 3: o recebimento nasce como PARCELA DE ACORDO (+ D6)

**Risco ALTO.** Aberta em 2026-08-03.
Spec-mãe: `docs/specs/cobranca-importar-receitas.md` (§11 é o achado que define esta etapa).
D6: `docs/specs/cobranca-cancelar-acordo.md` §3.2.
Handoff de estado: `docs/gestao-cobrancas/HANDOFF_IMPORTAR_RECEITAS.md`.

⛔ **A importação de Receitas está TRAVADA até esta etapa fechar** (decisão A3). Rodar `--confirmar`
antes criaria 187 obrigações avulsas que esta etapa teria de desfazer.

---

## 1. O buraco, em uma frase

O `TopLifeReceitasAdapter` **já lê** a coluna J e produz `AcordoDoRelatorio(numero, parcelaIndice,
parcelaTotal)` (`TopLifeReceitasAdapter.php:313-320`). O `ImportarReceitasUseCase` **nunca lê**
`$receita->acordo` — o campo existe em `ReceitaImportavel.php:34` e morre ali.

Consequência: **187 recebimentos** que são parcela de acordo (160 TL I + 27 TL II) virariam obrigações
avulsas "Taxa MM/AAAA", soltas na fila de cobrança, sem vínculo com o acordo que as gerou.

## 2. A régua de medição (leia antes de acreditar em qualquer número da §3)

Cinco "fatos medidos" da spec-mãe caíram ao serem remedidos, e a minha própria primeira medição errou.
Por isso, **todo número desta spec diz como foi obtido**, e todos foram conferidos contra algo externo.

**Como:** scripts descartáveis em `docs/gestao-cobrancas/planilhas atualizadas/_medir_acordos_etapa3*.php`
(pasta **gitignored**, PII — nunca commitar), lendo os arquivos de **03/08** com PhpSpreadsheet, agrupando
por `(unidade, NN)` — a **mesma chave do adapter** — e casando a coluna J com a **mesma regex** do código
(`TopLifeReceitasAdapter::REGEX_ACORDO`).

**Conferência externa:** o relatório imprime o próprio gabarito no rodapé. A soma da coluna I sobre todos
os grupos recebidos e de líquido positivo deu **R$ 243.013,53** (TL I) e **R$ 136.898,49** (TL II) —
**bate ao centavo** com "Total de receitas das unidades". É essa conferência que valida o parser; sem ela
os números da §3 não valeriam nada. (Ela já pegou um defeito real: a primeira versão do meu parser tratava
célula numérica como texto e multiplicava tudo por 100.)

**⚠️ Fato medido tem prazo curto nesta fonte.** Se estas linhas passarem de um ou dois dias, remeça antes
de decidir. Os arquivos usados são os de 03/08 09:48–09:54, os quatro da mesma data.

## 3. O que foi medido (2026-08-03)

### 3.1 O universo

| | TL I | TL II | total |
|---|---|---|---|
| grupos `(unidade, NN)` recebidos | 1.220 | 858 | 2.078 |
| — **que são parcela de acordo** (coluna J casada) | **160** | **27** | **187** |
| — avulsos (coluna J vazia ou fora do formato) | 1.060 | 831 | 1.891 |
| linhas com "acordo" na J que a regex **não** casa | 0 | 0 | **0** |

Quatro propriedades que sustentam a chave, todas remedidas hoje e todas de pé:
**nenhum NN em dois acordos** · **nenhum acordo cruzando unidade** · **nenhum acordo com dois
`parcelaTotal` diferentes** · **nenhuma parcela com dois NNs**.

### 3.2 🔑 Dois denominadores — e o que a §11.2 da spec-mãe confundiu

| denominador | acordos citados | com aba em "Acordos detalhados" |
|---|---|---|
| todos os grupos com coluna J (**inclui parcela não paga**) | 127 | 48 |
| **só grupos com parcela PAGA** — é o que a A1 manda criar | **106** | **27** |

A §11.2 da spec-mãe corrigiu a §1 de "106 citados" para "127", tratando o primeiro número como errado.
**Os dois estão certos, em denominadores diferentes.** Esta etapa cria acordo a partir de parcela **paga**
(A1), então o número que governa é **106**.

Na TOP LIFE II a cobertura pelo relatório de Acordos é **zero**: as 8 abas dela (9, 21, 28, 31, 32, 34,
37, 39) não são citadas por nenhuma parcela paga.

### 3.3 🔑 "79 sem fonte completa" está certo na conta e errado na consequência

Dos **106** acordos a criar:

| | |
|---|---|
| **já quitados** (parcelas pagas ≥ `parcelaTotal`) — a Receitas dá a informação **completa** | **75** |
| parciais, **com** aba no Acordos detalhados (há fonte para as parcelas futuras) | **27** |
| parciais, **sem** fonte nenhuma para as futuras | **4** |

Os 4 órfãos: acordo **212** (faltam 19 de 20), **230** (27 de 28), **237** (16 de 20), **280** (9 de 10) —
**71 parcelas futuras** que nenhum dos três relatórios traz.

O buraco real não são 79 acordos: são **4**.

### 3.4 Rodar o importador de Acordos detalhados antes NÃO resolve — medido

1. **Ele não cria acordo, por decisão de spec.** `ImportarAcordosDetalhadosUseCase.php:200-204`: se não
   acha por `numero_externo`, devolve `abaIgnorada` — "quem cria acordo é o relatório de inadimplência"
   (`cobranca-importar-acordos-detalhados.md:65-66` e §5). Rodá-lo antes criaria **zero** dos 106.
2. **A Inadimplência — único importador que cria acordo hoje (`ImportarRelatorioCarteiraUseCase.php:295`)
   — cobre 11 dos 106.** Faz sentido: parcela paga sai da inadimplência.
3. **A ordem útil é a inversa:** Receitas cria os 106 → depois o Acordos detalhados completa as parcelas
   futuras dos 27 que têm aba (é o que `completarParcelas` já faz).

### 3.5 Não há cobrança em dobro — medido, porque era o furo óbvio

As 27 abas listam **2.013 contas originais** consolidadas nesses acordos. **Nenhuma** delas aparece na
Inadimplência nem na Receitas: as dívidas que o acordo consolidou não entram no sistema por caminho
nenhum. Logo o acordo criado aqui **não passa a somar em cima de dívidas que já existem**.

⚠️ **Resíduo a conferir em produção:** a medição acima é sobre os *relatórios*. Se uma importação
**anterior** tiver trazido alguma dessas contas (quando ainda estavam inadimplentes), elas existiriam como
obrigação. No dev não dá para medir — só a TOP LIFE II está carregada, e ela tem 0 abas entre os 26.
Conferir antes do `--confirmar` em prod.

### 3.6 O dinheiro que muda de forma

Das 187 parcelas, **85 têm juros/multa** (classes 1.4/1.5 — foram pagas com atraso).

| | valor | como foi medido |
|---|---|---|
| bruto recebido nas 187 | **R$ 92.187,81** | Σ coluna I dos 187 grupos |
| — juros e multa (1.4 + 1.5) | R$ 5.571,25 | Σ coluna I das linhas 1.4/1.5 |
| **= `valorOriginal` das 187 parcelas** | **R$ 86.616,56** | bruto − juros/multa |

Classes presentes nas parcelas de acordo: `1.1` (150×), `1.14` (65×), `1.15` (66×), `1.4` (84×),
`1.5` (71×), `1.6` (23×).

**O total recebido não muda** — os R$ 379.912,02 e os oito números da conferência contábil (spec-mãe
§8.1) seguem valendo intactos. O que muda é a **forma** de 187 obrigações.

### 3.7 Estado do banco dev

Só existe **uma** carteira: `id=1, tenant_id=1, "TOP LIFE II"`. A TOP LIFE I nunca foi importada aqui.
Existem 11 acordos, **7** com `numero_externo` (9, 21, 28, 31, 32, 34, 37) — a spec-mãe §11.2 diz 8,
incluindo o 39; **o 39 não está no banco**. Todos os 11 estão `ativo`: **nenhum rompido ou cancelado**, ou
seja, D6 não tem caso natural no dev e precisa de cenário montado em teste.

## 4. Decisões

### 4.1 Do dono, em 03/08 (contrato — não reabrir)

| # | Decisão |
|---|---|
| **A1** | Parcela paga ⇒ **o acordo existe e tem de ser criado**. Não se cria "só a parcela". |
| **A2** | Status **`Ativo`**; só não é ativo se já terminou de ser pago — aí **`Cumprido`**. |
| **A3** | A etapa 2 fecha como está. Isto é a etapa 3, junto com D6. |

### 4.2 Do dono, ao abrir esta etapa

| # | Decisão | Efeito medido |
|---|---|---|
| **B1** | Os 4 acordos parciais sem fonte **nascem só com as parcelas pagas**, e o comando lista os 4 com quantas parcelas faltam. Nada é sintetizado. | 71 parcelas futuras ficam de fora, visíveis no resumo |
| **B2** | A parcela de acordo usa a **soma de todas as classes menos juros/multa** como `valorOriginal`, e **honorário zero** — o precedente de `ImportarRelatorioCarteiraUseCase.php:479-481` | R$ 86.616,56 em 187 obrigações; as 37 sem principal deixam de nascer R$ 0,00 |
| **B3** | **D6 entra só no caminho de Receitas** agora. O importador de Inadimplência não é tocado. | escopo menor, código de produção intacto |
| **B4** | Ao final, `--confirmar` **no DEV** para provar o caminho de escrita ponta a ponta | produção continua do dono |

### 4.3 Derivadas do código existente (precedente, não decisão nova)

| # | Regra | Precedente |
|---|---|---|
| C1 | busca do acordo por `(numeroExterno, carteira, tenant)` | `AcordoRepository::findOnePorNumeroExternoNaCarteira` — já filtra tenant **e** carteira |
| C2 | `dataAcordo` = 1º dia da competência da parcela, fallback vencimento | `ImportarRelatorioCarteiraUseCase::dataAcordoPadrao` |
| C3 | `valorTotalNegociado` só quando `parcelaTotal === 1`; senão `null` | idem, `:303-305` — não inventar total que a fonte não dá |
| C4 | `numeroParcelasTotal` = `parcelaTotal` da coluna J | idem |

**Sobre C1 e a colisão entre carteiras:** as abas **31 e 32** existem nas duas carteiras e são acordos
diferentes. O índice `(tenant_id, numero_externo)` **não é único** e não bastaria. C1 já resolve porque
restringe pela carteira via `caso → objeto → carteira`. Hoje não há colisão entre os citados (TL I usa
212..431, TL II usa 1..39), mas a defesa é necessária e é grátis.

## 5. O desenho

### 5.1 O ponto de decisão

A coluna J decide dois caminhos na gravação, espelhando `ImportarRelatorioCarteiraUseCase:194-198`:

- **vazia / fora do formato** → boleto avulso, **exatamente como hoje** (1.891 dos 2.078);
- **`Acordo N - Parc. x/y`** → resolve/cria o acordo **ANTES** de resolver a obrigação, e a obrigação
  nasce com `acordoOrigem`.

O acordo é resolvido antes de propósito: a parcela precisa apontar para ele nos **dois** ramos — obrigação
nova **e** obrigação preexistente (que pode ter nascido avulsa numa importação anterior).

### 5.2 A parcela de acordo (B2) — e por que a conta fecha

Hoje `valorExigivel() = valorOriginal + juros + multa + correcao`; **honorário fica fora do exigível**
(`Obrigacao.php:231-234`). É por isso que a obrigação avulsa quita exatamente hoje:

```
avulsa:  valorOriginal = valorDivida ; liquidar(juros, multa, 0, honorarios)
         exigível  = divida + juros + multa
         alocação  = recuperadoDividaCentavos() = divida + juros + multa   →  quita exato ✓
```

Na parcela de acordo o honorário **não é honorário do escritório sobre a dívida**: ele foi consolidado
**dentro** da parcela negociada. Então ele entra no principal, e nada de honorário é materializado:

```
parcela: valorOriginal = valorDivida + valorHonorarios ; liquidar(juros, multa, 0, 0)
         honorariosBp = 0 (modoHonorarios 'percent')
         exigível  = divida + honorarios + juros + multa
         alocação  = totalRecebidoCentavos() = divida + juros + multa + honorarios → quita exato ✓
```

⚠️ **As duas mudanças são inseparáveis.** Mover o honorário para o principal **sem** zerar o quarto
argumento de `liquidar()` contaria o honorário duas vezes no exigível e a parcela nasceria devendo. Mover
sem trocar a alocação faria a parcela nascer com resíduo igual ao honorário. Há um teste para cada uma
das três metades.

**O `Pagamento` NÃO muda.** `valorDivida` / `valorEncargos` / `valorHonorarios` continuam exatamente o que
a planilha diz — a contabilidade rateou, o sistema não re-rateia. É o que preserva a conferência da §8.1
da spec-mãe.

`honorariosBp = 0` também fecha a §9.2 da spec-mãe para as parcelas: reaberta pela etapa 1, a parcela não
volta a acumular honorário pela cascata da carteira.

### 5.3 Status (A2)

`Cumprido` **se e somente se** o número de parcelas distintas pagas do acordo **nesta execução mais as já
existentes no banco** alcançar `numeroParcelasTotal`. Senão, `Ativo`.

Medido: **75 dos 106 nasceriam `Cumprido`** (49 TL I + 26 TL II) e 31 `Ativo`.

⚠️ A régua é conservadora de propósito. O export é filtrado por **vencimento** (achado da etapa 2), então
um acordo de 40 parcelas pode aparecer com só as que vencem na janela. Nesse caso `pagas < total` e ele
nasce **`Ativo`** — que é o lado certo de errar. O contrário (marcar `Cumprido` um acordo que ainda deve)
seria subcobrança silenciosa.

`StatusAcordo::Cumprido` é vigente (`ehVigente()`), então as parcelas continuam contando no exigível
exatamente como as de um acordo `Ativo`. A escolha não move dinheiro; move o que a tela diz.

### 5.4 ESTADO intra-execução — onde esta etapa vai errar se errar

🔑 **É o defeito que já apareceu DUAS vezes nesta frente.** Um acordo de 8 parcelas pagas aparece em 8
linhas do arquivo. Na prévia, o banco responde "não existe" nas 8 consultas, porque a prévia não grava:
sem estado, ela prometeria **8 acordos criados** onde a confirmação cria **1**.

Então `EstadoDaImportacaoDeReceitas` passa a carregar:

- `acordosVistos: array<string,true>` chaveado por `numeroExterno` — conta a criação **uma vez por
  acordo**, não uma por parcela (mesmo padrão de `objetosVistos`);
- `parcelasPagasPorAcordo: array<int, array<int,true>>` — os índices de parcela vistos, para a régua do
  `Cumprido` da §5.3 enxergar as parcelas anteriores da MESMA execução;
- `acordosIncompletos` — número, pagas e total, para o comando listar os 4 órfãos.

O campo `acordosCriados`, hoje inerte com um docblock dizendo "sempre zero de propósito"
(`EstadoDaImportacaoDeReceitas.php:41-47`), passa a ser incrementado de verdade; o docblock cai junto.

### 5.5 D6 — reativação por importação (B3)

**Palavras do dono:** *"o importe é sempre a verdade"* — em carteira importada o estado do acordo é da
planilha. Se ela traz uma parcela paga de um acordo que o sistema tem como **rompido** ou **cancelado**, o
acordo volta a **`Ativo`**, sem aviso: o histórico já guarda o rompimento.

Implementação: no `resolverOuCriarAcordo` do caminho de Receitas, acordo achado com status `Rompido` ou
`Cancelado` → `setStatus(Ativo)`, limpando `motivoRompimento`/`motivoCancelamento`, e um evento no
histórico do caso.

**O furo que a §3.2 da spec-cancelar manda resolver, e por que ele não existe aqui.** A cadeia temida era:
acordo desfeito → originais voltam ao saldo → gestor recebe numa original → reativação → a original sai do
exigível → `CalculadoraSaldo` só abate alocação de obrigação **exigível** → o dinheiro pago **para de
abater** e o devedor é cobrado por algo que já pagou.

O que fecha isso é exatamente esta etapa: a Receitas identifica o pagamento **por obrigação** (NN), não
por regra, então o dinheiro das parcelas chega com o NN das parcelas e não precisa ser rateado por
adivinhação. **Mas isso não elimina o caso do pagamento lançado à mão numa original** — esse continua
possível. Ele é medido e reportado, não corrigido em silêncio: quando a reativação tira do exigível uma
obrigação que **tem alocação**, o comando **lista o caso, o NN e o valor** que deixou de abater.
Corrigir automaticamente seria decidir por conta própria para onde vai dinheiro de terceiro.

## 6. Como se prova

Cada item abaixo tem teste, e **cada teste é provado reintroduzindo o defeito e conferindo QUAL assert
fica vermelho** — na etapa 2, três dos quatro defeitos de teste eram asserts que não podiam falhar, e uma
"prova por injeção" falhou por carona em outro assert. Onde o cenário tem defesas em série, o teste é
mínimo e isola uma só.

| # | O que se prova |
|---|---|
| P1 | coluna J vazia → obrigação avulsa, **byte a byte como hoje** (`acordoOrigem` nulo, `valorOriginal` = principal, honorário materializado) |
| P2 | `Acordo N - Parc. x/y` → obrigação com `acordoOrigem` apontando ao acordo de `numeroExterno = N` |
| P3 | acordo **inexistente** → criado com `numeroExterno`, `numeroParcelasTotal`, `dataAcordo` e `criadoPor` |
| P4 | acordo **existente na mesma carteira** → **reusado**, não duplicado |
| P5 | mesmo `numeroExterno` em **outra carteira do mesmo tenant** → acordo **separado** (C1) |
| P6 | **duas parcelas do mesmo acordo na mesma execução → UM acordo**, e a prévia diz o mesmo número que a confirmação |
| P7 | parcela de acordo: `valorOriginal` = divida + honorários (isolado) |
| P8 | parcela de acordo: `liquidar` recebe honorário **zero** (isolado) |
| P9 | parcela de acordo: alocação = bruto, e a obrigação **nasce quitada** (exigível == alocado) |
| P10 | parcela de acordo grava `honorariosBp = 0`, e reaberta **não** acumula honorário |
| P11 | todas as parcelas pagas → `Cumprido`; faltando uma → `Ativo` |
| P12 | acordo `Rompido` → volta a `Ativo` na importação (D6), com evento no histórico |
| P13 | reativação que tira do exigível obrigação **com alocação** → **reportada**, não silenciosa |
| P14 | **idempotência**: reimportar o mesmo arquivo não cria acordo nem repõe vínculo |
| P15 | **prévia × confirmação idênticas em TODOS os campos** (o comparador por reflexão já existente cobre os campos novos) |
| P16 | **isolamento por tenant** na busca do acordo — **teste do repositório direto, sem request**, com dado CRUZADO. O `TenantFilter` é global e ligado **por request**: fica DESLIGADO em CLI, que é onde o importador roda, então teste funcional não prova nada aqui |
| P17 | o comando **imprime** acordos criados, ligados e os incompletos com parcelas faltando — com cenário que exercite cada contador (contador sem cenário compara `[]` com `[]`) |

**Prova externa, ao final:** dry-run contra os quatro arquivos de 03/08 tem de reproduzir os números da
§3 — 187 parcelas, 106 acordos, 75 `Cumprido`, 4 incompletos sem fonte — e os oito números da conferência
contábil da spec-mãe §8.1 têm de continuar batendo ao centavo.

## 7. Fora de escopo

- **Criar as parcelas futuras** dos 27 com aba — é o `ImportarAcordosDetalhadosUseCase` existente,
  rodado **depois**.
- **Alterar o importador de Acordos detalhados** para criar acordo (§3.4: cobriria 27 de 106 e reabriria
  uma decisão de spec já tomada).
- **D6 no importador de Inadimplência** (B3).
- **Sintetizar as 71 parcelas futuras dos 4 órfãos** (B1).
- **Rateio automático** do pagamento lançado à mão numa dívida original quando o acordo é reativado —
  reportado, não corrigido (§5.5).

## 8. Estado

**Ao abrir a etapa:** `master` local em `2c59cb06`, **19 commits não publicados**, árvore limpa, suíte
**3169/3169**, sem migration pendente. Nada em produção, nada gravado.

Esta etapa **não tem migration**: `Acordo.numeroExterno`, `Acordo.numeroParcelasTotal` e
`Obrigacao.acordoOrigem` já existem.
