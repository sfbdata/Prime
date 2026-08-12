# Espelho da contabilidade — Fase 0

**Escrita em 2026-08-12**, depois que a revisão adversarial reprovou a spec
`cobranca-parcela-de-acordo-so-encargos.md` (v2) e o dono redefiniu a premissa do módulo.
**Versão 4 desta spec.** A v1 foi reprovada com 10 achados (§13); a v2 confirmou 7 consertos e foi
reprovada com mais 7 (§14); a v3 confirmou 6 dos 7 e foi reprovada por **um** bloqueador de uma linha
(§15). Correções marcadas ✏️ (v2) e ✏️✏️ (v3/v4).

> **Para quem for implementar:** todo número desta spec foi medido — no banco de **produção** pelo
> MCP de leitura, ou rodando código real contra o arquivo real, ou lendo o código com âncora
> `arquivo:linha`. O que é suposição está marcado como **SUPOSIÇÃO**. Se algum número não reproduzir
> na sua medição, **pare e meça**; não ajuste o código para caber no número. Três versões de spec
> desta frente já caíram por número não conferido — inclusive uma soma de três parcelas que não
> fechava (§13, achado 4).

---

## 1. A premissa — decidida pelo dono em 12/08

> *"O sistema é só um local que facilita os funcionários a entender a planilha da contabilidade. A
> verdade absoluta é o import da contabilidade e é a contabilidade que faz tudo. Aqui é só um jeito
> bonito de traduzir o estado da contabilidade."*

Três consequências saem direto dela:

1. **No import, a contabilidade sempre ganha.** Não existe reconciliação como negociação entre duas
   verdades. Chegou número da planilha, é ele.
2. **O cálculo ao vivo é uma PROJEÇÃO**, não uma segunda verdade. Ele estima onde a contabilidade
   estaria hoje, entre um import e o próximo. Se a projeção erra, o erro é nosso.
3. **A tela precisa ser honesta** sobre o que é fato importado e o que é projeção.

⚠️ Isso **substitui** a §9.1 da spec anterior, que dizia *"encargos são do sistema; divergir da
planilha é o comportamento correto; não conferir"*. Divergir continua **esperado** (a contabilidade
calculou num instante anterior), mas deixou de ser **inconferível**.

## 2. O problema que a Fase 0 resolve

**O sistema nunca guardou o que a contabilidade disse.** Medido:

- **Nenhuma tabela de importação existe.** As 19 tabelas `cobranca_*` não incluem nenhuma. O único
  vestígio de um import no banco é o JSON `Carteira::$emissaoPorTipoDeRelatorio`
  (`app/src/Cobranca/Entity/Carteira.php:144-145`), **sobrescrito a cada import, sem histórico**.
- **Três colunas nunca são lidas**: G (Atraso), M (Total), O (Recebimento) —
  `TopLifeInadimplenciaAdapter.php:37-48`.
- **A data de corte é lida e descartada de propósito.** `RecorteEsperado.php:39-41` a documenta como
  fora *"de propósito"*; `ResultadoRodape.php:15-17` a chama de *"payload morto"*.
  ✏️ **Correção de precisão:** ela não é uma linha própria do rodapé — é um campo dentro da linha
  `Filtros:`, escrito como `Inadimplência até:<data>` **sem espaço após os dois-pontos**.
- **Linhas rejeitadas perdem todo o valor financeiro**: `LinhaRejeitada` guarda só
  `[nn, unidade, sacado, competencia]` (`TopLifeInadimplenciaAdapter.php:133`).

**É por isso que "o sistema está alinhado?" só pode ser respondido abrindo o Excel na mão** — e é a
causa raiz dos erros de todas as versões de spec desta frente.

## 3. Escopo

**Faz:** espelho (§4) · conferência (§5) · calibração (§6).

**NÃO faz — normativo:**

- ⛔ **Não escreve uma única linha em `cobranca_obrigacao`, `cobranca_acordo`, `cobranca_caso`,
  `cobranca_objeto` ou `cobranca_pagamento`.** Aditiva: cria tabelas novas e lê as existentes.
- ⛔ **Não conserta os dois defeitos** da spec anterior (porta fechada dos 17 boletos; dupla
  contagem nos encargos). Ficam para a Fase 1, que a Fase 0 vai finalmente conseguir medir.
- ⛔ **Não altera `EncargosVivos`, `CalculadoraEncargos` nem `ResolvedorConfigEncargos`** (§6.3).
- ⛔ **Não importa nada em produção.** O bloqueio do dono sobre o lote de 12/08 vale até a Fase 1
  estar em produção.

## 4. Peça 1 — o espelho

### 4.1 O princípio inegociável

**O espelho lê a planilha com um leitor próprio, independente do adapter.**

O adapter **interpreta**: agrupa por (unidade + NN) (`:96-98`), soma em baldes (`:169-186`), decide o
que é principal, descarta G/M/O. Se o espelho reaproveitar essa leitura, herda cada defeito do
adapter — e não serve para conferir o adapter, que é para o que ele existe.

O leitor do espelho faz uma coisa: **cada linha da planilha vira uma linha da tabela**, com as 15
colunas como estão. Sem agrupar, sem somar, sem julgar.

✏️ **Com uma exceção obrigatória, medida:** o arquivo tem **duas tabelas**, e o segundo bloco tem
**duas formas diferentes** — a v2 desta spec descreveu só uma. Conteúdo literal do TL1 de 12/08:

```
L4130: A[Total inadimplência das unidades] B..G[vazias] H[441975.94] I[151473.95] … M[719436.76]
L4133: cabeçalho do 2º bloco
L4134-4140: A[classe] B[valor] C[juros] … G[total]
```

- **L4130 está no layout de 15 colunas** (valores em H–M), igual ao bloco de dados.
- **L4134–4140 estão no layout de 7 colunas** (valores em B–G).

Gravar as duas pela mesma posição poria `Total inadimplência das unidades` com todos os valores
nulos. O leitor do totalizador **detecta a forma por linha** e normaliza as duas para as mesmas
colunas (§4.2).

O leitor **detecta o fim do bloco principal e para**. *(Verificado nos 23 arquivos: sempre dois
blocos, nenhum dado depois do fim.)* O leitor é burro quanto ao **significado** dos valores, não
quanto à **estrutura** do arquivo.

### 4.2 As três tabelas ✏️

**`cobranca_relatorio_importado`** — um registro por arquivo lido.

| coluna | origem |
|---|---|
| `id` | PK integer autoincrement |
| `tenant_id` | FK obrigatória |
| `carteira_id` | FK — ver §4.4 sobre atribuição |
| `tipo` | enum: `inadimplencia` hoje |
| `arquivo_nome`, `arquivo_hash` | nome original · sha256 do conteúdo |
| `emitido_em` | rodapé `Emissão: dd/mm/aaaa hh:mm` (`LeitorEmissaoDoRelatorio.php:31`) |
| `dados_ate` | campo `Inadimplência até:` da linha `Filtros:` — **hoje descartado**; âncora da calibração |
| `config_declarada` | ✏️ JSON da linha 4: `{juros: 100, multa: 200, honorarios: 2000}` — a config que **a contabilidade declarou naquela emissão** |
| `unidades_declaradas` | ✏️ campo `Número de unidades` do cabeçalho — ✏️✏️ **guardar, nunca asserir igualdade**: medido, ele diverge da contagem real de unidades distintas (TL2 declara 116 e tem 123; AMLI declara 15 e tem 21) |
| `linhas_total`, `linhas_dados`, `linhas_totalizador` | contagens |
| `versao_leitor` | ✏️ inteiro, incrementado quando o leitor muda |
| `lido_em`, `lido_por_id` | auditoria |

✏️ **Índice único em `(tenant_id, carteira_id, arquivo_hash, versao_leitor)`.** Sem `versao_leitor` a
regra "corrigiu o leitor? relê e gera lote novo" (§4.4) seria recusada pelo próprio índice — mesmo
arquivo tem sempre o mesmo hash.

*(Verificado: o hash resolve os dois casos difíceis. Três relatórios diferentes com a mesma
`Emissão: 12/08/2026 09:42` existem no corpus e têm hashes distintos; o mesmo arquivo renomeado
colapsa corretamente.)*

**`cobranca_relatorio_linha`** — uma linha por linha de dado.

`id` · `tenant_id` · `relatorio_id` (FK) · `numero_linha` (posição física no xlsx) ·
✏️ `bloco` (enum `dados` | `totalizador`) · `unidade` (A) · `sacado` (B) · `nn` (C) · `classe` (D) ·
`competencia` (E) · `vencimento` (F) · `atraso` (**G**) · `valor` (H) · `juros` (I) · `multa` (J) ·
`correcao` (K) · `honorarios` (L) · `total` (**M**) · `acordo_texto` (N) · `recebimento` (**O**) ·
`bruto` (JSON com as células como texto)

**`cobranca_relatorio_totalizador`** ✏️ — o rodapé somado da própria planilha:
`relatorio_id` · `numero_linha` · `forma` (enum `larga` 15 col | `estreita` 7 col) · `rotulo` ·
`valor` · `juros` · `multa` · `correcao` · `honorarios` · `total`.

O `forma` existe porque as duas formas coexistem no mesmo bloco (§4.1); o leitor normaliza, mas
registra de qual layout cada linha veio — sem isso não há como provar que a normalização acertou.

Regras:

- **Valores monetários em centavos int** (convenção da casa, `Obrigacao.php:64-65`) **e também** o
  texto original em `bruto`. O int é para consultar; o texto prova a conversão.
- **Grava TODAS as linhas de dado** — inclusive as que o adapter rejeitaria. "Importável" é
  julgamento do adapter, e é o que se quer conferir.
- **Imutável.** Nunca sofre `UPDATE`.
- **Não tem FK para `cobranca_obrigacao`.** A ligação é feita na conferência e é justamente o que
  pode falhar.
- ✏️ **Entidades sem constructor property promotion**, seguindo as 19 entidades de
  `app/src/Cobranca/Entity/` (0 de 19 usam), e não a skill `criar-entity/SKILL.md:71`. O módulo vence
  a skill; a divergência é registrada aqui para não virar discussão na revisão.

### 4.3 Reconciliação interna — a prova mais barata que existe ✏️

Antes de conferir contra o sistema, o espelho confere **contra ele mesmo**: a soma das linhas de dado
tem que bater com o totalizador da própria planilha.

Medido no TL1 de 12/08, sobre as 4.123 linhas de dado:
`H 44.197.594 · I 15.147.395 · J 883.952 · K 0 · L 11.714.735 · M 71.943.676` — **idêntico ao
rodapé**, e `H+I+J+K+L == M`.

Se essa conta não fechar, **o leitor está errado** e nenhuma conferência posterior vale. É a primeira
coisa que o comando roda, e ela falha alto.

### 4.4 Validação de cabeçalho e carga do histórico

**Cabeçalho.** Medido: o adapter nunca compara nomes de cabeçalho — só exige que a célula A contenha
`"Unidade"` (`:249-258`) e depois lê por **posição fixa**. Uma coluna deslocada passaria em silêncio.
O leitor do espelho valida os 15 nomes e recusa o arquivo se não bater. *(Verificado: os 23 arquivos
têm o mesmo cabeçalho, na mesma ordem, com dados começando na linha 7.)*

**Histórico.** São **23 relatórios** em `docs/gestao-cobrancas/`, distribuídos
**TOP LIFE I = 8 · TOP LIFE II = 10 · AMLI BR 060 = 5**.

✏️✏️ O total 10 da TL2 **inclui** o `planilhas atualizadas/Inadimplências_detalhadas_2026_07_29_16_06_05.xlsx`,
que não traz carteira no nome nem no conteúdo — e que a regra abaixo **deriva** como TL2 por
sobreposição de 100% das suas 126 unidades contra o conjunto da TOP LIFE II (contra 5,6% da TL1 e
4,8% da AMLI). A v2 chegava ao mesmo 10 **pré-atribuindo**; agora ele é derivado e verificável. Se a
regra não derivar, o arquivo é recusado e a distribuição vira 8 · 9 · 5 · 1 recusado.

⚠️ ✏️ **Nenhum arquivo identifica a própria carteira no conteúdo.** O cabeçalho traz só
`L. G Soluções Contábeis Eireli`, `INADIMPLÊNCIA DETALHADA`, `Número de unidades`, taxas e filtros.
E `planilhas atualizadas/Inadimplências_detalhadas_2026_07_29_16_06_05.xlsx` não tem carteira nem no
nome do arquivo. **Atribuir a carteira errada produz 100% de "falta" + "sobra", em silêncio** — é o
modo de falha mais perigoso da carga.

✏️✏️ **A carga roda em DUAS passadas** — os arquivos identificáveis pelo nome primeiro, os demais
depois. Sem isso o nível 3 fica **dependente da ordem de leitura**: em ordem alfabética o arquivo sem
carteira seria lido antes dos nomeados, o espelho estaria vazio, e ele seria recusado — contradizendo
a promessa de carga reexecutável.

**Passada 1 — o nome identifica a carteira.** ✏️✏️ Medido: o prefixo canônico
(`top_life_1_` / `top_life_2_` / `amli_br_060_`) casa com **19** dos 23; outros **3** trazem a
carteira no nome com grafia livre (`TOPLIFE I_…`, `TOPLIFE II_…`, `toplifeII_…`). A regra é *"o nome
identifica a carteira"*, não *"o prefixo canônico"* — com a leitura estrita a distribuição não fecha.

**Passada 2 — para o que sobrar, nesta ordem:**
1. `Honorários: 20,00%` ⟹ TOP LIFE I (TL2 e AMLI são ambas 15%, então isto só desempata a I);
2. ✏️ **sobreposição do conjunto de unidades** contra as unidades já no espelho para cada carteira.
   ✏️✏️ **Limiar numérico** (não "maioria dominante", que é julgamento): atribui quando a contenção
   for **≥ 90%** do conjunto do arquivo **e** **≥ 5×** a do segundo colocado. *(No caso real a
   separação é 100% × 5,6%, folgada em qualquer corte entre 10% e 100%.)*
3. **senão, RECUSA o arquivo e reporta.** Nunca adivinha.

✏️ ⚠️ **O nível 3 da v2 estava errado e foi substituído.** Ele mandava casar `Número de unidades` do
cabeçalho contra a contagem de objetos da carteira. Medido: esse campo é a contagem de unidades
**inadimplentes naquela emissão**, não o tamanho da carteira — no TL1 as emissões trazem 86, 89, 96,
102 e 132, e nenhuma bate com a contagem de objetos. A regra nunca dispararia corretamente, e quando
disparasse seria coincidência.

⛔ **PROIBIDO carregar histórico pelo caminho de importação.** Medido: reimportar arquivo antigo pelo
`ImportarRelatorioCarteiraUseCase` sobrescreveria os encargos atuais com o snapshot velho
(`materializarEncargosImportados()` :276-286) e carimbaria `encargosAtualizadosEm` com o *agora*
(`:169`). O `max()` da carteira (`Carteira.php:407`) é cosmético. **A carga só toca as tabelas
novas**, é reexecutável e idempotente pelo índice da §4.2.

**Volume:** ~38,5 mil linhas nas 23 emissões (TL1 ~32,6k · TL2 ~5,5k · AMLI ~336). Não é problema.

## 5. Peça 2 — a conferência

Comando de console, **somente leitura**, que recebe um lote do espelho e devolve, por carteira:

### 5.1 Os dois universos ✏️ — a correção mais importante desta versão

A v1 desta spec dizia que o universo do lado do sistema era *"obrigações não liquidadas, com
competência ≤ a do relatório"*. **Está errado, e o erro é grande.**

Obrigação **substituída por acordo** continua com `liquidada_em IS NULL` (`CriarAcordoUseCase.php:147-148`
— *"Marca (nunca apaga)"*) e sai do relatório de inadimplência **corretamente**: ela virou parcela de
acordo. Pela régua antiga, cada uma vira uma "sobra" falsa.

**Medido em PRODUÇÃO (12/08):**

| carteira | universo régua antiga | substituídas por acordo | vencem após o corte | **universo correto** |
|---|---:|---:|---:|---:|
| TOP LIFE I | 7.285 | 3.604 | 582 | **3.099** |
| TOP LIFE II | 633 | 94 | 17 | **522** |
| AMLI BR 060 | 137 | 78 | 8 | **51** |

Na TOP LIFE I a régua antiga produziria **4.186 alarmes falsos em 7.285 — 57% de ruído**. O relatório
seria inutilizável.

✏️✏️ **A régua da v2 também estava incompleta, e errava nos DOIS sentidos.** Ela usava só
`acordo_substituto_id IS NULL`. A régua canônica de exigibilidade **já existe no repositório** —
`ObrigacaoRepository.php:185-186`:

```php
->andWhere('(asub.id IS NULL OR asub.status IN (:naoVigentes))')   // rompido, cancelado
->andWhere('(aorig.id IS NULL OR aorig.status IN (:vigentes))')    // ativo, cumprido
```

Sem essas duas cláusulas:

- **obrigação restaurada some do universo.** Quando um acordo é rompido, `RestauradorObrigacoesOriginais`
  **preserva o vínculo de propósito** (docblock `:24`: *"deliberadamente NÃO faz: apagar o vínculo
  `acordoSubstituto`"*). A original volta a ser exigível **com `acordo_substituto_id` preenchido** →
  a régua da v2 a descartava → balde *"falta no sistema"* falso.
- **parcela de acordo morto entra no universo.** Parcela de acordo rompido tem `liquidada_em IS NULL`
  e `acordo_substituto_id IS NULL`, mas não é exigível (quem voltou foi a original) → balde
  *"sobra no sistema"* falso. É a dupla contagem que a segunda cláusula existe para impedir.

**D10 — a conferência reusa a régua do sistema, não escreve a sua.** A pergunta que a conferência faz
é *"o que o SISTEMA considera devido bate com a contabilidade?"* — logo ela tem que usar a definição
**do sistema**, não uma reimplementação. Uma segunda cópia de regra de dinheiro diverge em silêncio,
que é exatamente a classe de defeito que esta frente inteira existe para consertar.

Implementação: **extrair o predicado de exigibilidade** para um ponto único, consumido pela nova
consulta por carteira e pelos consumidores que hoje o repetem. Reusar a *regra*, não o método —
`doCasoExigiveis` é por caso, e a conferência é por carteira.

✏️✏️ **A segunda cópia já existe:** `ObrigacaoRepository::exigiveisDosCasos:311-331` repete as mesmas
6 linhas verbatim de `doCasoExigiveis:178-197`. Se a extração absorver só uma, o "ponto único" nasce
com três consumidores e duas cópias — exatamente o que o D10 quer impedir. **As duas entram.**

**Universo do lado do sistema** = predicado de exigibilidade do repositório **∧**
`liquidada_em IS NULL` **∧** `vencimento_original <= dados_ate`.

⚠️✏️✏️ **"Exigível" NÃO quer dizer "em aberto", e a v3 desta spec omitiu o filtro.** O predicado do
repositório não exclui obrigação quitada — está documentado em `AlertasCobranca.php:29`:
*"o conjunto de `doCasoExigiveis` inclui a obrigação quitada e a parcela de acordo CUMPRIDO"*.

Medido em produção (12/08), o tamanho do erro:

| carteira | universo correto | sem o filtro de liquidada | falsos positivos |
|---|---:|---:|---:|
| TOP LIFE I | **3.099** | 10.576 | **7.477** |
| TOP LIFE II | **522** | 1.370 | 848 |
| AMLI BR 060 | **51** | 385 | 334 |

São **três** condições independentes, e o código deve mantê-las separadas: exigibilidade é regra do
**sistema** (reusada, D10); "em aberto" e o corte por vencimento são recortes **da conferência**.

✏️✏️ **D12 — qual régua de "pago", e o que fazer com a discordância.** O sistema tem duas:
`liquidada_em IS NOT NULL` e "alocado ≥ exigível" (a de `ObrigacaoOutput::quitada()`). **Elas não
concordam em produção hoje:** medido, **11 obrigações na TOP LIFE I e 2 na TOP LIFE II estão
totalmente pagas e não marcadas como liquidadas** (e zero no sentido inverso).

A conferência usa **`liquidada_em IS NULL`** como universo — é o marcador de estado do próprio
sistema — **e reporta as 13 em balde próprio** (*paga mas não liquidada*). Decidir silenciosamente
por uma das duas esconderia uma inconsistência interna real; o padrão aqui é o mesmo do D11:
ambiguidade vira balde, não palpite.

*(Medido em produção, 12/08: a régua canônica e a da v2 dão **exatamente o mesmo número** —
3.099 · 522 · 51 — porque hoje não existe nenhum acordo rompido ou cancelado em produção: são 311
cumpridos e 82 ativos, zero das duas situações. **O conserto é de correção, não de número: ele passa
a importar no dia do primeiro rompimento.** ✏️✏️ No dev, onde há 2 acordos cancelados, a régua da v2
erra em **9 casos dentro do recorte da conferência** (`vencimento <= dados_ate`) — mais 33 parcelas
mortas que vencem depois do corte e que o próprio recorte já excluiria. A v3 desta spec somava os
dois e dizia "42", o que superestima o erro **dentro** do universo que a conferência define.)*

*(Sanidade: o universo corrigido da TL1 dá 3.099 contra **3.023 grupos no relatório — dos quais 3.006
importáveis** (o adapter rejeita 17). ✏️ A v2 rotulou os 3.023 como "importáveis", trocando os dois
números. A diferença é matéria do comando, não premissa dele — **SUPOSIÇÃO** que seja divergência
genuína.)*

**Universo do lado do relatório:** as linhas do bloco `dados` do lote — e a §5.2 declara qual
recorte delas entra em cada comparação.

### 5.2 (a) Mesmo conjunto de dívidas

Todo NN do relatório existe no sistema, e nada a mais.

✏️ **A chave de ligação é `(caso, referencia_externa, competencia)`** — não `(carteira, NN, competência)`,
como a v1 dizia. `ReferenciaSubstituta.php:17-19` documenta que a referência sintética
(`SNN:<vencimento ISO>`, usada quando o NN vem vazio) só é única *"porque `caso_id` e `competencia` já
compõem o índice"*, e o índice real é
`uniq_cobranca_obrigacao_ref_competencia (caso_id, referencia_externa, competencia)`.

Medido: com a chave errada há **4 colisões** em 3.002 chaves no TL1 de 12/08 — as unidades `17-01/1-2`
e `20-03C` compartilham `SNN:2022-05-10|05/2022` e mais três. Duas dívidas de apartamentos diferentes
virariam uma, e cairiam em dois baldes ao mesmo tempo.

✏️ **Sem-NN, em unidades corretas:** são **45 boletos** (grupos) e **73 linhas físicas** — o mesmo 73
documentado em `ReferenciaSubstituta.php:13-14`. A v1 dizia "45 linhas", misturando as duas unidades.

✏️✏️ **Como se chega no `caso` a partir do espelho, e a guarda que falta.** O espelho tem `unidade`,
não `caso_id` (D4). O caminho é `unidade → IdentificacaoDaUnidade::separar → ObjetoCobranca.referenciaExterna
→ caso`. Hoje é determinístico — medido: **248 objetos, todos com exatamente 1 caso** — mas
`cobranca_caso.objeto_id` **não tem índice único**, então nada impede um segundo caso no mesmo objeto,
e aí a chave volta a colidir.

A conferência **não pode tratar isso como dado**: detecta objeto com mais de um caso e reporta em
balde próprio (*ambiguidade de caso*), em vez de escolher um. Chutar aqui produziria divergência
falsa e silenciosa.

### 5.3 (b) Mesmo principal

Σ da coluna H no espelho × `valor_original` da obrigação.

✏️ **Com o universo declarado.** A v1 citou `Σ H = 43.682.375` chamando-o de "Σ H no espelho", mas
esse número é a soma sobre os **3.006 boletos importáveis** — o que o *adapter* aceita. A soma sobre
todas as 4.123 linhas de dado, que é o que o espelho guarda, é **44.197.594**. A diferença de
**515.219 centavos (R$ 5.152,19)** são os 17 boletos rejeitados. Quem implementar "Σ H do espelho"
sem o recorte acha o número errado e a conta não fecha.

**E com regra de residual, por ramo.** Medido: Σ H (importáveis) 43.682.375 × Σ `valor_original`
43.681.747 → **R$ 6,28 de diferença, num único boleto** (NN 61687, unidade 09-04C: taxa 100,00 +
energia 45,00 + linha `1.4` 3,38 + linha `1.5` 2,90).

A diferença é **legítima**: no boleto comum `valorOriginal = principalCentavos` (só as classes
1.1/1.14/1.6), e o H das linhas de encargo fica fora **de propósito**. Logo:

- boleto **sem acordo**: Σ H das classes 1.1/1.14/1.6 × `valor_original`
- **parcela de acordo**: Σ H de todas as classes × `valor_original`

🔑 **Quem tentar fazer o Σ H cru "fechar" no boleto comum reintroduz a dupla contagem no caminho
normal** — o defeito que a Fase 1 existe para matar. É a armadilha mais provável desta peça.

⚠️ **Ressalva medida:** na reimportação o `valorOriginal` é **preservado, não reescrito**
(`ImportarRelatorioCarteiraUseCase.php:258-260`), então (b) compara contra um valor que pode ter
nascido de um arquivo antigo. *Verificado no corpus das 23 emissões: nenhum grupo mudou o
`valorOriginal` derivado e houve **zero** transições boleto→parcela-de-acordo — hoje isso não
contamina (b). O comando reporta a data de origem do valor mesmo assim.*

### 5.4 (c) Baldes que somam

✏️ **Com denominador declarado.** (a) e (b) operam por **boleto/grupo**; a v1 exigia que "toda linha"
caísse num balde, misturando as unidades.

✏️✏️ **A reconciliação por linha da v2 não fechava, e teria feito o comando falhar nos 23 arquivos.**
Ela dizia `dados + totalizador = linhas do arquivo`. Medido no TL1 de 12/08: **4.123 linhas de dado**
+ **8 de totalizador** = 4.131, mas o arquivo tem **4.145 linhas**. Faltam as do cabeçalho, a do
cabeçalho do segundo bloco, as de rodapé (`Filtros:`, empresa, `Emissão:`) e as em branco.

**Toda linha do arquivo cai em exatamente um destes baldes:**
`cabecalho` · `dados` · `cabecalho_bloco2` · `totalizador` · `rodape` · `branca`

E a soma dos seis == número de linhas do arquivo.

✏️✏️ **Com regra de precedência**, porque linha vazia cabe em dois baldes (a linha 5 é tão
"cabeçalho" quanto "branca"):

1. **todas as células vazias ⟹ `branca`** — precede tudo;
2. coluna C preenchida ⟹ `dados`;
3. coluna A preenchida e C vazia ⟹ `totalizador`;
4. linha do bloco 2 cujas B..G são texto ⟹ `cabecalho_bloco2`;
5. antes da primeira linha de dado ⟹ `cabecalho`; depois do último totalizador ⟹ `rodape`.

*(Verificado no TL1 de 12/08: os seis baldes cobrem as 4.145 linhas sem sobra e sem ambiguidade.)*

⚠️ **Esta spec não fixa a contagem de cada balde de propósito.** Ela varia por arquivo, e chutá-la
aqui seria repetir pela terceira vez o erro que derrubou as duas versões anteriores. A implementação
**mede** e o teste assere a *identidade* (soma == total), não números literais.

**Por grupo:** *confere* · *falta no sistema* · *sobra no sistema* · *principal diferente* = total de
grupos do relatório, mais o balde *sobra* contado sobre o universo do sistema (§5.1). No TL1 de
12/08 são **3.023 grupos** contra **3.099** do universo do sistema.

**Se qualquer uma das duas não somar, o comando falha.** Não reporta parcial.

### 5.5 ✏️✏️ Premissas estruturais — medidas em produção (12/08)

Não é enumeração de divergências (isso é o **produto** da conferência, e adivinhá-las de véspera foi
o erro das versões anteriores). É a checagem do que faria a conferência **medir errado**:

| premissa | medido em prod | consequência |
|---|---|---|
| obrigação criada à mão, fora do import | **0** nas três carteiras | não existe a categoria "nunca esteve no relatório"; se aparecer no futuro, vira balde |
| obrigação com `competencia` NULL (legado) | **0** | a chave da §5.2 não precisa do fallback NULL do repositório |
| objeto com mais de um caso | **0** de 504 | o balde do D11 permanece como **guarda** — nenhum índice impede |
| referência substituta (`SNN:`) já gravada | **99**, todas na TOP LIFE I | confirma que o caminho sem-NN é real e precisa casar por referência, não por NN |

⚠️ **A surpresa: 22 objetos não têm caso nenhum** (TL1 10 · TL2 8 · AMLI 4, de 504 objetos). Se o
relatório cobrar uma dessas unidades, não há caso para chegar — e o balde *falta no sistema* diria a
verdade pelo motivo errado.

**Sub-baldes obrigatórios de *falta no sistema*:** *unidade sem objeto* · *objeto sem caso* ·
*caso existe, obrigação não*. São três causas diferentes, com três consertos diferentes; somá-las
esconde qual é.

## 6. Peça 3 — a calibração

### 6.1 A pergunta

**Se a gente rodar a nossa fórmula com os dados da planilha, na data que a contabilidade usou, dá o
número dela?**

### 6.2 ✏️ Já foi medido — e o resultado é bom

A revisão desta spec rodou a calibração contra o dado real: `CalculadoraEncargos` com
`padraoTopLife(2000)` e `dataReferencia = 12/08`, sobre as **3.156 linhas de classe 1.1 do TL1**:

| | exatos |
|---|---|
| juros | **3.154 / 3.156** (2 divergências de 1 centavo) |
| multa | **3.156 / 3.156** |
| honorários | **3.156 / 3.156** |
| coluna G (Atraso) == dias(vencimento → `dados_ate`) | **3.156 / 3.156** |

🔑 ✏️✏️ **Na população medida, nossa fórmula reproduz a da contabilidade.** O cenário previsto é o
**"bate quase → reancoragem"**: as duas divergências são de 1 centavo (linhas 1420 e 3024:
4201×4200 e 2255×2254), arredondamento puro, e zeram a cada import.

⚠️ **A conclusão não pode ser maior que a evidência.** Isto é **uma** carteira, **uma** emissão,
**uma** classe de conta. Dizer "a fórmula é a fórmula da contabilidade" sem essa qualificação seria
o mesmo tipo de salto que derrubou as versões anteriores. TL2, AMLI e as demais classes **não foram
medidas** — e medi-las é entregável desta fase, não premissa dela.

Configuração **medida em produção** (12/08), coerente com `ConfigEncargos::padraoTopLife()` e com o
cabeçalho de cada planilha:

| | TOP LIFE I | TOP LIFE II | AMLI BR 060 |
|---|---|---|---|
| juros | 100 bp/mês, simples | idem | idem |
| multa | 200 bp sobre principal | idem | idem |
| correção | 0 | 0 | 0 |
| honorários | base composta, `acrescido_divida` | idem | idem |
| **% honorários** | **20,00%** | **15,00%** | **15,00%** |
| carência honorários | 30 dias | idem | idem |
| tolerância juros/multa | 0 | idem | idem |

E a fórmula (`CalculadoraEncargos.php:27-33`) já tinha o arredondamento assimétrico (juros
meio-para-baixo, resto meio-para-cima) derivado de **4.413 linhas reais**, com paridade pinada em
quatro pontos (`CalculadoraEncargosTest.php:41-97`).

**O que a Fase 0 acrescenta:** tornar isso **contínuo e por carteira** — a medição acima é de uma
carteira, uma emissão e uma classe. TL2 e AMLI não foram medidas, e as classes fora da 1.1 também
não.

### 6.3 Como calcular sem tocar no núcleo

`EncargosVivos::hidratar()` não aceita data — calcula sempre até `clock->now()` (`:85, :101`) e
**muta a entidade**. Não serve e não deve ser alterada.

`CalculadoraEncargos::calcular(int $principal, \DateTimeImmutable $vencimento, ConfigEncargos $config,
\DateTimeImmutable $dataReferencia)` recebe a data explicitamente e é **pura, sem estado e sem I/O**
(`:17-19`). A calibração chama ela direto, com `$dataReferencia = dados_ate`. Zero mutação.

✏️ **De qual nível da cascata sai o `ConfigEncargos`:** a calibração usa
`ResolvedorConfigEncargos::resolver($obrigacao)` — a cascata **completa** (carteira → objeto →
obrigação), não o preset. Medido: **58 obrigações têm override por-obrigação**; calibrar contra o
preset da carteira as reportaria como divergência falsa. Linha do espelho que não casa com obrigação
nenhuma fica fora da calibração e cai no balde *falta no sistema* da §5.

### 6.4 O relatório

Por carteira, para cada linha do espelho com atraso > 0: juros/multa/correção/honorário nossos × os
da planilha (I/J/K/L). Saída: distribuição das diferenças (exatas, até 1 centavo, até 1 real, acima)
e a lista das piores.

- **Bate exatamente** → projeção validada.
- **Bate quase** (centavos) → projeção validada **com reancoragem** a cada import. *É o resultado
  esperado, pela §6.2.*
- **Não bate** → divergência de regra. Achado para o dono levar à contabilidade, com número na mão.
  ⛔ **Não se ajusta a fórmula para caber na planilha sem entender a causa.**

## 7. Verificado: a data do acordo já está correta

`ImportarAcordosDetalhadosUseCase.php:506` grava `setDataAcordo($aba->dataBase)` — a coluna
`Data base:` da planilha, nunca `now()` nem `Criado em:`. Decisão documentada em `:502-505` (D3,
dono, 07/08) e provada por `ImportarAcordosDetalhadosTest.php:757`. Aba sem `Data base` não cria
acordo (`:478-483`). As obrigações substituídas têm encargos materializados **na data do acordo** nos
dois caminhos (`CriarAcordoUseCase.php:136-145`; `ImportarAcordosDetalhadosUseCase.php:1056-1071`).

⚠️ **Ressalva para a Fase 1:** o *outro* importador não faz isso. Quando o relatório de inadimplência
cria acordo pela coluna N, `ImportarRelatorioCarteiraUseCase.php:320` usa `dataAcordoPadrao()`
(`:356-359`), que deriva a data do 1º dia do mês da competência. **Fora do escopo da Fase 0.**

## 8. Riscos e proibições

| # | risco | mitigação |
|---|---|---|
| 1 | Carga de histórico pelo importador corromper dado atual | ⛔ proibido (§4.4); a carga só escreve nas tabelas novas |
| 2 | Espelho reaproveitar o adapter e herdar seus defeitos | §4.1 — primeiro ponto que a revisão deve conferir |
| 3 | Conferência (b) "fechar" o Σ H cru e reintroduzir a dupla contagem | §5.3 — dois comparativos por ramo, com alerta |
| 4 | Universo da conferência gerar ruído | §5.1 — medido em prod: a régua errada dá 57% de falso positivo na TL1 |
| 5 | Chave de ligação colidir | §5.2 — `(caso, referencia_externa, competencia)`, medido: 4 colisões com a chave errada |
| 6 | Carteira atribuída errada na carga histórica | §4.4 — 2 passadas (nomeados primeiro) + 3 níveis, e recusa em vez de adivinhar |
| 7 | Alguém alterar `EncargosVivos` para a calibração | §6.3 — a Calculadora já aceita data |
| 8 | Escrita acidental por hidratação + flush | §9 teste 9, com a forma que realmente detecta |
| 9 | Tabela nova sem filtro de tenant | as três carregam `tenant_id`; teste cross-tenant obrigatório |
| 10 | `make:migration` propor `DROP INDEX` nos índices funcionais | migration **escrita à mão**, como a `Version20260730120000.php:30-31` |

## 9. Testes exigidos

1. **Leitor lê as 15 colunas**, incluindo G, M e O — com um caso em que M ≠ H+I+J+K+L, provando que M
   é copiado e não recalculado. ✏️ **Fixture sintética, obrigatoriamente**: medido, no TL1 de 12/08
   **zero das 4.123 linhas** têm M ≠ H+I+J+K+L. O dado real não serve para este teste.
2. ✏️ **O segundo bloco (totalizador) não entra como linha de dado** e vai para a tabela própria com
   as colunas certas — ✏️✏️ **cobrindo as DUAS formas** (`larga` de 15 colunas, como a linha
   "Total inadimplência das unidades", e `estreita` de 7 colunas). É o teste que pega o achado 4 da
   re-revisão.
3. ✏️ **Reconciliação interna** (§4.3): soma das linhas == totalizador da planilha, e H+I+J+K+L == M.
   Provar reintroduzindo o defeito: com uma linha corrompida, vermelho.
4. **Cabeçalho fora de posição é recusado** (§4.4). Provar reintroduzindo: com a validação desligada,
   o arquivo torto passa.
5. **Idempotência**: ler o mesmo arquivo duas vezes não duplica; ✏️ ler com `versao_leitor` diferente
   **gera** lote novo.
6. ✏️ **Arquivo sem carteira identificável é RECUSADO**, não adivinhado (§4.4).
7. **Linha rejeitada pelo adapter existe no espelho com seus valores** — o que `LinhaRejeitada` perde.
8. **Isolamento de tenant** nas três tabelas: leitura cross-tenant devolve vazio.
9. ✏️ **A Fase 0 não escreve em dado de dívida.** A asserção via ORM **não serve**: o identity map
   devolve o objeto já mutado em memória, com ou sem flush. O teste tira um **snapshot via DBAL, fora
   do ORM**, de `juros, multa, correcao, honorarios, encargos_atualizados_em, atualizado_em` antes e
   depois de rodar espelho + conferência + calibração. `atualizado_em` é o detector: `Obrigacao.php:206-211`
   o carimba no `PreUpdate`. O acidente é real e está no repositório —
   `CalculadoraSaldo.php:49-56` hidrata (muta entidades managed) e `EncerrarCasoUseCase.php:54,76`
   flusha depois.
10. ✏️ **Conferência com desalinhamento plantado**, cobrindo os quatro baldes E o universo da §5.1:
    obrigação substituída por acordo **não** aparece como sobra; parcela com vencimento após o corte
    **não** aparece como sobra.
10b. ✏️✏️ **Os dois casos de acordo morto** — hoje impossíveis de observar em produção (zero acordos
    rompidos), e por isso obrigatórios em teste: (a) obrigação **restaurada** por rompimento de
    acordo, que mantém `acordo_substituto_id` preenchido, **entra** no universo; (b) **parcela de
    acordo rompido** **não** entra. São os dois falsos positivos que a régua da v2 produzia.
10c. ✏️✏️ **Reconciliação por linha fecha** (§5.4): os seis baldes somam o total de linhas do
    arquivo. Asserir a identidade, nunca contagens literais.
10d. ✏️✏️ **Objeto com dois casos vira balde de ambiguidade**, não escolha silenciosa (§5.2).
11. **Conferência não confunde boleto comum com parcela de acordo** (§5.3).
12. ✏️ **Chave de ligação não colide** — caso com dois boletos sem NN, mesmo vencimento, unidades
    diferentes (o caso real medido).
13. **Calibração reproduz os 4 casos pinados** em `CalculadoraEncargosTest` pelo caminho novo,
    ✏️ e usa a cascata completa, não o preset (§6.3).
14. Suíte completa verde.

## 10. Decisões

| # | decisão | por quê |
|---|---|---|
| D1 | Leitor próprio, não reaproveitar o adapter | senão o espelho não confere o adapter (§4.1) |
| D2 | Centavos int **e** texto original | o int é consultável; o texto prova a conversão |
| D3 | Guardar linha rejeitada | "importável" é julgamento do adapter, e é o que se confere |
| D4 | Espelho imutável, sem FK para obrigação | a ligação é resultado, não estrutura |
| D5 | Carregar as 23 emissões | mostra como a base chegou ao estado atual |
| D6 | Não tocar em `EncargosVivos` | a Calculadora já aceita data (§6.3) |
| D7 ✏️ | Obrigação substituída por acordo **não** é divergência | saiu do relatório com razão; aprovado pelo dono em 12/08 |
| D8 ✏️ | Sem constructor property promotion | 0 de 19 entidades do módulo usam (§4.2) |
| D9 ✏️ | Totalizador em tabela própria, com discriminador de forma | o bloco tem **duas** formas de layout (§4.1) |
| D10 ✏️✏️ | A conferência **reusa** o predicado de exigibilidade do sistema, não escreve o seu | a pergunta é "o que o SISTEMA considera devido bate?"; segunda cópia de regra de dinheiro diverge em silêncio (§5.1) |
| D11 ✏️✏️ | Objeto com mais de um caso é balde de ambiguidade | escolher um caso produziria divergência falsa e silenciosa (§5.2) |
| D12 ✏️✏️ | Universo usa `liquidada_em IS NULL`; as 13 "pagas mas não liquidadas" viram balde próprio | as duas réguas de "pago" do sistema discordam em produção **hoje**; decidir em silêncio esconderia inconsistência real (§5.1) |

## 11. Em aberto para o dono

1. **Frequência de import.** Sob a premissa nova, mais import = mais fidelidade. A automação de
   download já existe (`scripts/emitir-relatorios-contabil.sh`). Não é decisão da Fase 0.
2. **A tela honesta** (§1.3) — "contabilidade em DD/MM · projetado até hoje". Entra depois da
   calibração.

## 12. Depois de implementar

Revisão adversarial contra esta spec (`/review`), correções, **re-revisão**. Só então a Fase 1 (os
dois defeitos do adapter), que passa a ter medida em vez de estimativa. Deploy é do dono.

**Risco:** BAIXO pela tabela do projeto (não toca identidade, tenant-role nem permissão) e **escrita
zero em dado de dívida**. A spec existe porque é fundação de uma frente ALTO.

## 13 ✏️ O que a revisão da v1 derrubou

Registrado para que a próxima revisão confira o conserto, e para que o erro não volte:

| # | achado | conserto |
|---|---|---|
| 1 | BLOQUEADOR — universo da conferência incluía substituída por acordo: **57% de falso positivo na TL1 em prod** | §5.1, com medição de produção |
| 2 | GRAVE — `Σ H = 43.682.375` era do adapter (3.006 importáveis), não do espelho (4.123 linhas = 44.197.594) | §5.3, universo declarado |
| 3 | GRAVE — chave `(carteira, NN, competência)` colide: 4 colisões reais | §5.2, `(caso, referencia_externa, competencia)` |
| 4 | GRAVE — "11 + 11 + 5 emissões" num parágrafo que afirmava 23. **Soma que não fecha, o mesmo erro que reprovou a v2** | §4.4: TL1 8 · TL2 10 · AMLI 5 |
| 5 | GRAVE — índice único bloqueava a releitura que a própria spec prescrevia | §4.2, `versao_leitor` na chave |
| 6 | GRAVE — faltava marcador de rodapé, e o arquivo tem **segundo bloco com layout diferente** | §4.1, §4.2, D9 |
| 7 | GRAVE — teste "nenhuma obrigação alterada" era vago e o ORM não detectaria | §9 teste 9, snapshot DBAL |
| 8 | MENOR — "45 linhas" eram 45 boletos / 73 linhas; §5(c) sem denominador | §5.2 e §5.4 |
| 9 | MENOR — espelho não guardava config declarada, nº de unidades e totalizador | §4.2, §4.3 |
| 10 | MENOR — convenção de entidade em aberto | D8 |

**Nenhum arquivo identifica sua carteira** foi achado junto ao #4 e virou a §4.4.

## 14 ✏️✏️ O que a re-revisão da v2 derrubou

A re-revisão confirmou **7 dos 10** consertos da v1 (achados 2, 3, 5, 7, 8, 9, 10 — cada um
reproduzido com prova) e deixou 3 pela metade. Mais 7 achados novos:

| # | achado | severidade | conserto |
|---|---|---|---|
| 1 | A régua de exigibilidade da §5.1 errava nos **dois** sentidos: descartava obrigação restaurada por rompimento (vínculo é preservado de propósito) e incluía parcela de acordo morto. A régua canônica já existe em `ObrigacaoRepository.php:185-186` | BLOQUEADOR | §5.1 + **D10**: reusar o predicado do sistema, não reimplementar |
| 2 | A reconciliação por linha não fechava: `dados + totalizador` = 4.131, mas o arquivo tem 4.145 linhas. **O comando falharia nos 23 arquivos** | GRAVE | §5.4: seis baldes, e a spec deixa de fixar contagens |
| 3 | O nível 3 da atribuição de carteira partia de premissa falsa — `Número de unidades` é a contagem de **inadimplentes da emissão**, não o tamanho da carteira. E a §4.4 pré-atribuía o arquivo que ela mesma mandava recusar | GRAVE | §4.4: nível 3 por sobreposição de unidades; distribuição vira 8 · 9 · 5 · **1 a identificar** |
| 4 | A tabela do totalizador não comportava a linha 4130, que está no layout **largo** de 15 colunas, não no estreito de 7 | MÉDIO | §4.1, §4.2: discriminador `forma`, D9 reescrita |
| 5 | O teste 1 pedia um caso (M ≠ H+I+J+K+L) que **não existe** no dado real — 0 de 4.123 | MENOR | §9.1: fixture sintética obrigatória |
| 6 | Rótulo trocado: "3.023 grupos importáveis"; 3.023 é o total, 3.006 os importáveis | MENOR | §5.1 |
| 7 | O caminho espelho → caso dependia de "1 objeto = 1 caso", que **nenhum índice garante** | MENOR | §5.2 + **D11**: balde de ambiguidade |

**Medição própria acrescentada nesta versão:** em produção não existe hoje nenhum acordo rompido ou
cancelado (311 cumpridos, 82 ativos), então a régua canônica e a da v2 dão o mesmo número — 3.099 ·
522 · 51. **O conserto do achado 1 não muda nenhum número hoje; ele passa a importar no dia do
primeiro rompimento.** No dev, com 2 acordos cancelados, a régua da v2 erra em **9 casos dentro do
recorte da conferência** (mais 33 que o recorte por vencimento já excluiria) — ver §5.1; o "42" que
esta seção trazia somava os dois e superestimava o erro.

**O que a re-revisão mediu e deu certo** (não volta como problema): a reconciliação interna da §4.3
reproduz ao centavo; a regra por ramo da §5.3 fecha em **exatamente** 43.681.747, provando que a
conferência decide o ramo sem reusar o adapter; a calibração da §6.2 foi reproduzida de forma
independente; as 58 obrigações com override conferem; a detecção do fim do bloco é determinística nos
23 arquivos; o detector de escrita acidental do teste 9 funciona
(`EncargosVivos::hidratar` grava `encargosAtualizadosEm` incondicionalmente → `PreUpdate` sempre
dispara).

## 15 ✏️✏️ O que a terceira revisão derrubou

Confirmou **6 dos 7** consertos da v3 (2, 3, 4, 5, 6 e 7 — cada um reproduzido no arquivo real, com a
taxonomia dos seis baldes fechando as 4.145 linhas do TL1 sem sobra) e reprovou por **um** bloqueador:

| # | achado | severidade | conserto |
|---|---|---|---|
| 1 | **"Exigível" não é "em aberto".** O predicado do repositório inclui obrigação quitada (`AlertasCobranca.php:29` documenta), e a fórmula da §5.1 omitia `liquidada_em IS NULL` — contradizendo os números da própria tabela | BLOQUEADOR | §5.1: três condições separadas; medido em prod, **7.477 falsos positivos** só na TL1 |
| 2 | O "ponto único" do D10 nasceria com duas cópias: `exigiveisDosCasos:311-331` repete o predicado verbatim | MENOR | §5.1: as duas entram na extração |
| 3 | Linha totalmente vazia cabia em dois baldes | MENOR | §5.4: regra de precedência em 5 níveis |
| 4 | "Maioria dominante" era julgamento, não regra | MENOR | §4.4: contenção ≥ 90% e ≥ 5× o segundo |
| 5 | O nível 3 era dependente da ordem de leitura | MENOR | §4.4: duas passadas, nomeados primeiro |
| 6 | "Prefixo canônico resolve 22 dos 23" — são 19 canônicos + 3 de grafia livre; e "42 casos no dev" eram 9 dentro do recorte + 33 fora | MENOR | §4.4 e §5.1 |

**Medição própria acrescentada na v4** (o revisor não tem MCP de prod, e o dev não é cópia de prod):

- Sem o filtro de liquidada, o universo da TOP LIFE I iria de **3.099 para 10.576**. No dev o mesmo
  erro aparecia só na AMLI (26 → 345); em produção ele é grande nas três.
- **As duas réguas de "pago" do sistema discordam em produção**: 11 obrigações na TL1 e 2 na TL2
  estão totalmente alocadas e não marcadas como liquidadas (zero no sentido inverso). O revisor
  mediu zero no dev e concluiu que a escolha era barata — **em prod não é**. Daí o D12.
- O arquivo sem carteira **é derivável**: 126 unidades, contenção 100% na TL2 contra 5,6% na TL1 e
  4,8% na AMLI. A distribuição volta a 8 · 10 · 5, agora derivada.

**Trajetória das quatro versões:** 10 achados → 7 → 1 bloqueador + 5 menores → (esta). Nenhuma
rodada foi estilística: cada bloqueador teria produzido número errado em dinheiro. As três primeiras
falharam por número não conferido; esta última, por uma condição faltando numa fórmula.
