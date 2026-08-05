# SPEC — Validador da linha `Filtros:` do rodapé (item 6)

**Risco:** MÉDIO. Não move um centavo: só **recusa arquivo**. Mas errar para o lado frouxo devolve a
falha original (importar recorte errado em silêncio), e errar para o lado rígido **trava a importação
inteira** por causa de um ponto e vírgula. Por isso: spec + teste provado por reintrodução + duas
revisões.

**Origem:** item 6 do `HANDOFF_AUTOMATIZAR_DOWNLOADS.md` §6.3. É a peça que o handoff descreve como
*"teria pego o filtro de 2026 sozinho"* — e que **vale igual com download manual**, o que a torna
independente da pendência da §7.1 (a automação não é oficial).

---

## 1. O problema, dito sem eufemismo

O relatório de Acordos vinha com um recorte que escondia a maior parte dos acordos, e **ninguém viu
por meses**. O único lugar do arquivo que registra qual recorte foi usado é a linha `Filtros:` do
rodapé — e ninguém lê rodapé.

**O dano do download manual nunca foi o tempo. Foi o filtro errado passar despercebido.** Um arquivo
com recorte errado não dá erro: ele importa lindamente e o número fica menor do que a realidade. É
uma falha silenciosa que só aparece meses depois, quando alguém confere com a contabilidade.

O validador transforma isso num erro barulhento, no segundo zero.

---

## 2. Fatos medidos (06/08/2026) — o rodapé real das 4 fontes

Medido com `_ver_rodape.php` e `_item6_rodapes.php` contra **13 arquivos** (o lote de 04/08 pela API,
o lote completo, e os manuais de 29/07, 01/08 e 03/08). Nada aqui é suposição.

### 2.1 O texto de cada fonte

| fonte | L2 | linha `Filtros:` |
|---|---|---|
| **Inadimplência** | `INADIMPLÊNCIA DETALHADA` (⚠️ **não** identifica o condomínio) | `Filtros:··Inadimplência até:04/08/2026; Competência: Todas; Período de vencimento: Todos; Unidade: Todas; Sacado: Todos` |
| **Acordos** | o condomínio (`APLC - TOP LIFE 2`) | `Filtros: Situação do acordo: Em andamento; Período de criação do acordo: Todos; Unidade/Cliente: Todos; Sacado: Todos` |
| **Receitas** | o condomínio | `Filtros: Situação das contas: Baixadas; Competência: Todas; Período de vencimento: Todos; Todos; Unidade: Todos; Classe de conta: Todas; Sacado: Todos;` |
| **Cadastro** | o condomínio | `Filtros: Unidades: Todas` |

### 2.2 🔑 As cinco armadilhas do formato (cada uma quebraria um parser ingênuo)

1. **A Inadimplência usa DOIS espaços** depois de `Filtros:`, e escreve `Inadimplência até:04/08/2026`
   **sem espaço** depois dos dois-pontos. As outras três usam um espaço e `chave: valor`.
2. **O `Período de recebimento` PERDE o rótulo quando vale "todos"** — sobra um `Todos;` **órfão**, sem
   chave. Confirmado em 6 amostras da Receitas:

   | arquivo | `Período de vencimento` | `Período de recebimento` |
   |---|---|---|
   | TL1 01/08 (manual) | `01/01/2026 a 01/01/2027` | `01/01/2026 a 01/01/2027` |
   | TL1 03/08 (manual) | `01/01/2026 a 01/01/2027` | **`Todos` órfão** |
   | TL2 03/08 (manual) | `01/01/2026 a 01/01/2027` | **`Todos` órfão** |
   | TL1/TL2 04/08 (API, janela) | `Todos` **com rótulo** | `01/01/2026 a 04/08/2026` |
   | TL1/TL2/AMLI (completo) | `Todos` **com rótulo** | **`Todos` órfão** |

   ⚠️ **O vencimento NÃO se comporta assim** — ele mantém o rótulo mesmo valendo `Todos`. São dois
   campos vizinhos com regras diferentes; tratar os dois igual erra um dos dois. (Casa com a §6.0.2 do
   handoff: *"`Período de recebimento: Todos` não é um valor, é a ausência dos dois parâmetros"*.)
3. **A lista de campos NÃO é fixa.** `Conta (bancária, caixinha...): Todas as contas;` aparece nos
   arquivos manuais e nos emitidos com janela, e **não aparece** nos `_completo` de TL2 e AMLI. Um
   validador que exija N campos exatos quebra sozinho.
4. **O enum enviado não é o texto impresso** (§3.6 do handoff): manda-se `BAIXADA`, o rodapé escreve
   **`Baixadas`** (plural); `EM_ANDAMENTO` vira `Em andamento`. O mapa é tabelado à mão, e a
   comparação é **exata**, nunca `contains` — `contains("Baixadas", "Baixada")` é verdadeiro e foi por
   sorte que a primeira conferência passou.
5. **No Acordos o rodapé se repete em TODAS as abas, e é idêntico** — medido em 8, 26 e 8 abas. Ler a
   primeira basta; ler todas custaria caro e não acrescenta.

### 2.3 A prova de que o validador pega o erro real

O arquivo que a secretária baixou à mão em 03/08
(`top_life_1_Receitas_detalhadas_por_unidade_cliente_2026_08_03_09_51_26.xlsx`) tem:

```
Situação das contas: Aberta e baixada; ... Período de vencimento: 01/01/2026 a 01/01/2027; Todos; ...
```

**Dois campos fora do esperado** (`Baixadas` e `Todos`). O validador o recusa. ✅ É o caso concreto que
motivou o item.

---

## 3. Desenho

### 3.1 Onde

Serviço novo, sem estado: `app/src/Cobranca/Service/Importacao/ValidadorRodapeFiltros.php`.
Irmão dos adapters — conhece **esta** fonte, não é validador universal.

Tipos de apoio no mesmo namespace: `RecorteEsperado` (o que se espera por fonte) e `ResultadoRodape`
(o veredito + os campos lidos + o motivo da recusa).

### 3.2 Quando

**Nos 4 comandos e na TELA** — as cinco portas de entrada.

Nos comandos: depois da checagem de arquivo legível e **antes** de `$adapter->ler()`. Recusa →
`Command::INVALID`, com a linha lida e a diferença impressas. O bloco compartilhado mora no trait
`App\Cobranca\Command\ConfereRecorteDoArquivo`.

Na tela (`ImportacaoController::prever`): logo após guardar o temporário e **antes** da leitura;
recusa descarta o temporário e volta para o upload com a mensagem. **Só na prévia, não também no
`confirmar`** — o ponteiro do arquivo só entra na sessão depois de a prévia passar, então não há
caminho que alcance o confirmar sem passar por ali, e guarda redundante que nenhum teste distingue é
guarda que envelhece mentindo (regra da casa).

⚠️ **A tela foi achado ALTA da 1ª revisão.** A primeira versão desta spec dizia "os 4 comandos" e
esquecia a interface — que é justamente por onde o gestor importa. Regra que fecha 4 de 5 portas não
fecha porta nenhuma.

Vale nos dois modos: **dry-run também é recusado** — um dry-run sobre arquivo errado produz um
relatório convincente e falso, que é pior do que erro nenhum.

### 3.3 Como lê (e por que assim)

Primeira aba, `setReadDataOnly(true)`, `setLoadSheetsOnly([primeira])` **e um `IReadFilter` que
descarta toda coluna que não seja a A**.

⚠️ **O `IReadFilter` não é enfeite** (achado MÉDIA da 1ª revisão): `setReadDataOnly` +
`setLoadSheetsOnly` **não restringem colunas**. Sem o filtro, medido na Receitas completa de TL1
(916 KB), eram **21.150 linhas × colunas A–J** carregadas para ler UMA célula, e o importe passava de
4,1 s para 7,4 s. A primeira versão *afirmava no docblock* "só a coluna A" e só filtrava na busca,
depois da planilha inteira já estar em memória — doc que mente é pior que doc que falta.

Medido depois da correção, no mesmo arquivo: **1,98 s, pico de 22,7 MB**.

**Arquivo ilegível não estoura**: zip quebrado / `.xlsx` truncado / arquivo renomeado viram recusa com
motivo, não stack trace. É o cenário-mãe desta frente — download interrompido —, e no comando de
inadimplência a versão anterior tinha *regressado* o tratamento de erro que já existia (a conferência
ficou fora do `try/catch` que protegia o adapter).

Duas precisões vindas da 2ª revisão:

- captura-se `\Exception`, **não `\Throwable`**. Um `\Error` (`TypeError` no filtro, assinatura mudada
  num upgrade do PhpSpreadsheet, bug em `extrairLinha()`) viraria "confira se o download completou"
  em **todo** arquivo — culpa jogada no fornecedor, rastro do defeito apagado. Erro de programação sobe;
- o resultado marca `arquivoIlegivel`, e **CLI e tela usam frases diferentes** para os dois casos.
  Dizer "o recorte não serve, reemita o relatório" para um `.xlsx` truncado manda o operador refazer a
  emissão quando o defeito é o download. A mensagem do vendor (em inglês, e carregando o caminho
  absoluto do arquivo) **não** é repassada ao usuário.

### 3.4 O parser

1. Acha a primeira linha da coluna A que começa com `Filtros:` (comparação no início, após `trim`).
2. Tira o prefixo `Filtros:` e faz `trim` — isso absorve o caso dos dois espaços da Inadimplência.
3. Quebra por `;`, descarta pedaços vazios (a Receitas termina com `;`).
4. Cada pedaço vira `chave => valor` pelo **primeiro** `:`, com `trim` dos dois lados — isso absorve o
   `Inadimplência até:04/08/2026` sem espaço.
5. Pedaço **sem** `:` entra numa lista de **órfãos**, preservando a ordem.

Devolve `campos` (mapa) + `orfaos` (lista).

### 3.5 As regras de expectativa

Cada fonte declara uma lista de expectativas nomeadas. Três tipos, e só três:

| tipo | significado | usado em |
|---|---|---|
| `exato(chave, valor)` | a chave existe e o valor é **idêntico** | `Competência: Todas`, `Situação das contas: Baixadas` |
| `qualquerUmDe(chave, [v1, v2])` | a chave existe e o valor é um da lista | `Situação do acordo: Em andamento` **ou** `Liquidado` |
| `todosOuOrfao(chave)` | a chave vale `Todos`/`Todas`, **ou** a chave não existe e há um órfão `Todos` | `Período de recebimento` |

**Chave repetida com valores diferentes é RECUSA** (recorte ambíguo). A 1ª versão fazia "a primeira
ocorrência vence", e a 2ª revisão mostrou que isso escolhia o lado **frouxo**: aceitava em silêncio um
rodapé contraditório, contra o princípio do resto do arquivo ("a ausência não vira aprovação — seria
adivinhar"). Repetição com o **mesmo** valor é só redundância e passa. Nenhum arquivo real repete
chave; o ponto é que o custo de errar não é simétrico — recusar um caso inexistente não custa nada,
aceitar um recorte ambíguo devolve a falha silenciosa.

**Campo presente no arquivo e não declarado na expectativa é IGNORADO** — é o que faz o
`Conta (bancária, caixinha...)` não quebrar nada (§2.2.3). O validador afirma *"o que me importa está
certo"*, não *"o arquivo é exatamente isto"*.

**Campo declarado e ausente do arquivo é RECUSA** (exceto `todosOuOrfao`, cuja ausência é justamente
uma das formas de estar certo).

### 3.6 Os recortes esperados

| fonte | expectativas |
|---|---|
| **Inadimplência** | `Competência` = `Todas` · `Período de vencimento` = `Todos` · `Unidade` = `Todas` · `Sacado` = `Todos` |
| **Acordos** | `Situação do acordo` ∈ {`Em andamento`, `Liquidado`} · `Período de criação do acordo` = `Todos` · `Unidade/Cliente` = `Todos` · `Sacado` = `Todos` |
| **Receitas** | `Situação das contas` = `Baixadas` · `Competência` = `Todas` · `Período de vencimento` = `Todos` · `Período de recebimento` = todosOuOrfao · `Unidade` = `Todos` · `Classe de conta` = `Todas` · `Sacado` = `Todos` |
| **Cadastro** | `Unidades` = `Todas` |

**`Inadimplência até:<data>` NÃO é validado como valor** — a data muda a cada emissão, e é assim que
tem de ser. Fica de fora da lista; se um dia importar, entra como regra de **forma** (`dd/mm/aaaa`),
nunca de valor literal.

🔒 **`Situação do acordo: Cancelado` é RECUSADO** — e isso é de propósito. O dono decidiu que
cancelados ficam fora (§5 do handoff) e a instrução *"⛔ NÃO importar o `*_CANCELADO.xlsx`"* hoje é só
uma frase num documento. Com esta regra ela vira **trava técnica**: quem apontar o comando para o
arquivo errado é barrado, não avisado.

---

## 4. Limitações honestas (o que este validador NÃO faz)

1. 🔴 **Ele valida UM arquivo, não o CONJUNTO.** O erro original dos Acordos foi *faltar* a emissão de
   `Liquidado` — e um arquivo `Em andamento` sozinho é **legítimo**, porque o fluxo novo emite um por
   situação. Nenhuma leitura do rodapé desse arquivo pega isso. Quem pega é o **item 8** (o teste do
   zero: importar tudo e bater com a contabilidade). **A frase do handoff — "teria pego o filtro de
   2026 sozinho" — vale para a Receitas (§2.3, provado), não para o buraco dos Acordos.**
2. **Não confere a carteira.** A Inadimplência não se identifica (§2.1: o L2 é o título) — só o
   histórico da API diz de qual condomínio ela é. Isso é o **item 7** do handoff §6, frente própria.
3. **Não valida os encargos da L4.** A §7.2 já fechou esse assunto por outro caminho.
4. 🟠 **Efeito colateral aceito: o ramo `Cancelado` do importador de acordos virou código morto no
   caminho real.** Medido na 1ª revisão, nas 13 planilhas de Acordos: o rodapé e o `Situação:` de cada
   aba coincidem 1:1, **sem um único arquivo misto**. Barrando o rodapé `Cancelado`, nenhuma aba
   cancelada alcança o `ImportarAcordosDetalhadosUseCase` — o mapa `'cancelado' => StatusAcordo::Cancelado`
   (`:81`) deixa de ter origem pelo importe. Isso **decorre da decisão do dono** ("cancelados ficam de
   fora"), não é defeito; fica registrado porque o teste que exercita aquele ramo passou a guardar um
   comportamento que nenhum arquivo real produz. O ramo `!ehVigente()` continua alcançável por status
   vindo do banco.

---

## 5. Testes

Unitários, `app/tests/Cobranca/Unit/ValidadorRodapeFiltrosTest.php`. O parser é puro (recebe texto),
então a maior parte não precisa de `.xlsx` — e é isso que permite testar as armadilhas uma a uma.

**Casos obrigatórios, todos vindos de texto REAL medido na §2:**

| # | caso | espera |
|---|---|---|
| 1 | Receitas completa (`Todos` órfão) | aceita |
| 2 | Receitas com janela de recebimento | **recusa**, apontando `Período de recebimento` |
| 3 | Receitas manual de 03/08 (§2.3) | **recusa**, apontando `Situação das contas` **e** `Período de vencimento` |
| 4 | Inadimplência (2 espaços + `até:` sem espaço) | aceita |
| 5 | Acordos `Em andamento` | aceita |
| 6 | Acordos `Liquidado` | aceita |
| 7 | Acordos `Cancelado` | **recusa** |
| 8 | Cadastro | aceita |
| 9 | Receitas sem o campo `Conta (bancária...)` | aceita (campo extra/ausente não declarado é ignorado) |
| 10 | `Situação das contas: Baixada` (singular) | **recusa** — é a trava contra o `contains` da §2.2.4 |
| 11 | arquivo sem linha `Filtros:` | **recusa** com motivo próprio (não "passa por omissão") |

Mais dois casos, acrescentados pela 1ª revisão:

| # | caso | espera |
|---|---|---|
| 12 | `Período de recebimento` ausente **e sem** órfão `Todos` na linha | **recusa** (`recorte desconhecido`) |
| 13 | chave repetida na linha, a 2ª com valor errado | aceita — **a 1ª ocorrência vence** |

⚠️ **Prova por reintrodução — SEIS injeções, cada uma avermelhando um caso previsto e só ele.** A regra
da casa é que teste verde não prova nada e que é preciso conferir **qual** assert ficou vermelho:

| injeção | avermelha |
|---|---|
| 1. comparação exata → `str_contains` | caso 10 (`Baixada` singular) |
| 2. `todosOuOrfao` aceita qualquer coisa | caso 2 (janela de recebimento) |
| 3. remover a ligação do comando de cadastro | o funcional do cadastro |
| 4. trocar a ordem (adapter antes da conferência) | o funcional do arquivo corrompido |
| 5. ramo "ausente sem órfão" → `return null` | caso 12 |
| 6. ignorar a ambiguidade de chave repetida | caso 13 |
| 7. desligar a validação na TELA | os dois funcionais da tela |

**Funcional:** por comando + tela, nos **dois sentidos** — e o segundo sentido faltava inteiro até a
2ª revisão:

| sentido | o que prova | por que importa |
|---|---|---|
| **recusa** | recorte errado → `INVALID` (ou redirect com mensagem), sem chegar ao adapter | a falha original não volta |
| **aceite** | recorte CORRETO de cada fonte → o comando segue até a leitura | 🔑 **o lado rígido** |

⚠️ **O teste de aceite é o que faltava, e a armadilha que ele pega é real:** a inadimplência escreve
`Unidade: Todas` e a receitas escreve `Unidade: Todos` — uma letra. Com `RecorteEsperado` errado, todos
os testes de recusa continuariam verdes e o comando nasceria **travado em produção**. A spec abre
dizendo que "errar para o lado rígido trava a importação inteira"; era justamente esse lado o que não
tinha um único teste.

⚠️ **Como provar a ORDEM (e dois jeitos que NÃO provam).** Este teste nasceu errado duas vezes:

1. *"a planilha vazia prova"* — **falso**: os 4 adapters leem planilha vazia sem exceção, devolvendo
   zero itens. Mover a conferência para depois mantinha tudo verde;
2. *"a seção `Leitura:` não pode sair"* — **também falso**: mover a *leitura* para antes não move a
   *impressão*, que continua depois da conferência. Injetei a troca e o teste seguiu verde.

O que prova: um arquivo com **assinatura de ZIP seguida de lixo** (`PK\x03\x04` + zeros) — o que um
download interrompido produz. O validador transforma isso em recusa com motivo; o adapter estoura.
Se a conferência vier antes, sai a mensagem; se vier depois, o comando morre com erro. **Texto puro não
serve** — o `IOFactory` o aceita como CSV e o caso cai no ramo "sem linha `Filtros:`", que é outro.

**Fixtures:** as planilhas sintéticas dos testes de comando e as fixtures `.xlsx` da tela ganharam a
linha `Filtros:` real. As da tela tinham o rodapé **truncado pela anonimização** (só 2 dos 5 campos) —
completá-las foi corrigir a fixture, não acomodar o código. A `toplife_amostra_zip64.xlsx` foi
reescrita preservando `compress_type` por entrada, porque o que ela testa é o mime indetectável que só
o Zip64/store produz.
