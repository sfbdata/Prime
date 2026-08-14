# Espelho da contabilidade — Fatia 0: cobrir os QUATRO relatórios

**Frente:** `cobranca-espelho-quatro-relatorios` · **Risco: ALTO** (é a régua que decide o que é defeito
de dinheiro; régua errada manda consertar o que está certo).
**Base:** `origin/master` @ `4ff88ee6` · **Escrita em produção: NENHUMA.** Tudo aqui é leitura.

---

## 1. Por que esta fatia existe, e por que ela vem antes do conserto

O módulo tem três instrumentos em produção — `espelho:conferir`, `espelho:calibrar`,
`espelho:encargos` — e os três reportam números que **parecem totais e são parciais**.

A causa é uma linha de filtro em [`CarregarEspelhoRelatorioCommand`](../../app/src/Cobranca/Command/CarregarEspelhoRelatorioCommand.php):

```php
// Só relatórios de inadimplência; os outros três tipos têm layout diferente e
// entram numa fatia posterior.
if (str_ends_with(mb_strtolower($nome), '.xlsx') && stripos($nome, 'nadimpl') !== false) {
```

A fatia posterior nunca veio. Medido em produção em 13/08/2026: os **9 lotes** carregados são todos
`tipo = 'inadimplencia'`. O enum [`TipoRelatorioContabil`](../../app/src/Cobranca/Enum/TipoRelatorioContabil.php)
declara quatro casos; o espelho enxerga um.

**Consequência que motivou a frente.** Ao medir a violação nº 1 (`honorariosBp = 0` na parcela de
acordo), 226 dívidas da TOP LIFE I — R$ 47.740,88 de principal, abertas, vencidas há mais de 30 dias,
todas de acordo **ativo** em caso **ativo** — não aparecem em lote nenhum. Duas leituras cabem no
dado, e a régua atual não distingue entre elas:

- a contabilidade não cobra nada nelas → o sistema cobra a mais → **defeito grande**;
- ou elas são publicadas em **outro** dos quatro relatórios → não há divergência → **nada a consertar**.

Consertar sem saber qual das duas é verdade seria mexer em dinheiro de 226 devedores sem referência.
É exatamente o erro que esta frente passou o mês corrigindo. Por isso a Fatia 0 é **pré-requisito**
da Fatia 1 — e, por decisão do dono em 13/08, pré-requisito da **meta** de começar a interface:

> *"eu disse que só começo a interface quando o sistema estiver 100% batendo com a contabilidade, e
> agora sei que hoje nem dá para medir isso."*

## 2. O que a Fatia 0 entrega

| | entrega |
|---|---|
| **0a** | o espelho **lê e guarda** os quatro relatórios, não um |
| **0b** | os três instrumentos **declaram a cobertura** de cada número que imprimem |

**0c — a conferência do conteúdo novo (acordos × acordos do sistema) NÃO está nesta fatia.** Ela é a
que responde às 226. Fica declarada aqui como o passo seguinte, na mesma frente, para que ninguém leia
"carregado" como "conferido" (§7).

## 3. O que cada relatório publica — medido nos arquivos reais

Amostras de `app/var/relatorios/` (as três carteiras, emissão de 10/08/2026), inspecionadas linha a
linha. **Nenhum dos três layouts novos se parece com o da inadimplência.**

### 3.1 Inadimplência — o que já é lido

Aba única. Cabeçalho de **15 colunas** na linha 6. Traz `Juros`, `Multa`, `Correção`, `Honorários`,
`Total`. É o único relatório que publica **encargo**.

### 3.2 Acordos detalhados — 🔑 **não tem coluna de encargo nenhuma**

**Uma aba por acordo** (TL1: 65 abas `EM_ANDAMENTO` + 260 `LIQUIDADO` = 325, que é exatamente o número
de acordos da TL1 em produção). São **dois arquivos por carteira**, não um.

Cabeçalho da aba, linhas 6–10, em pares `rótulo: valor`:

```
L6   Acordo de número 377
L7   Unidade: 01-01 (05-03,…)     ¦ Data base: 23/03/2026
L8   Sacado: <nome>               ¦ Valor total das contas originais: …
L9   Criado em: 23/03/2026        ¦ Valor final acordado: 9.515,00
L10  Situação: Em andamento
```

Depois, **duas tabelas**:

| tabela | título | cabeçalho |
|---|---|---|
| 1 | `Relação das contas originais` | Nosso Número ¦ Classe de Conta ¦ Competência ¦ Vencimento ¦ **Valor original (R$)** ¦ Detalhamento |
| 2 | `Parcelas das contas geradas pelo acordo` | Nosso Número ¦ Classe de Conta ¦ Parcela ¦ Competência ¦ Vencimento ¦ Liquidação ¦ **Valor acordado (R$)** ¦ **Valor liquidado (R$)** |

🔴 **INV-Q1 — o achado que limita o que esta frente pode prometer.** As duas tabelas trazem **apenas
valor**: nenhuma coluna de juros, multa, correção ou honorário. A contabilidade **não publica encargo
de parcela de acordo neste relatório**. Onde ela publica encargo de uma parcela é na **inadimplência**
— e é de lá que saem as 103 dívidas / R$ 7.229,81 da violação nº 1.

**O que isso decide, antes de alguém escrever código com a expectativa errada:** carregar o relatório
de acordos **não** vai produzir um valor de honorário para as 226. Vai responder outra pergunta, e é
a pergunta certa — *o sistema tem os mesmos acordos, as mesmas parcelas, os mesmos vencimentos e os
mesmos valores acordados que a contabilidade?* Se o vencimento delas no sistema não for o vencimento
dela, "vencida há mais de 30 dias" era premissa nossa, não fato dela.

⚠️ **Armadilha de leitura medida:** na tabela 1 as linhas vêm **espaçadas de duas em duas** (dados em
L14, L16, L18…), com linha em branco no meio. Um leitor que pare na primeira linha vazia lê **uma**
conta e reporta sucesso.

#### 🔴 INV-Q9 — o número negativo vem com ESPAÇO NÃO-QUEBRÁVEL, e some da soma sem erro

A célula de desconto (`1.6 - Descontos`) não é um número: é o texto `- 15,00`, e o separador entre o
sinal e o algarismo é **U+00A0**, não espaço comum. Bytes conferidos na aba `Acordo n25` do
`top_life_1_..._LIQUIDADO`: `2d c2a0 31 35 2c 30 30`.

Consequências, ambas medidas nesta frente:

1. `trim()` **não** remove U+00A0, e `\s` em PCRE **sem** o modificador `/u` também não. Uma conversão
   ingênua devolve `null`, o desconto **não entra na soma**, e nada falha.
2. Foi exatamente o que aconteceu na primeira medição desta frente: o portão dos acordos parecia
   recusar **47 das 325 abas**. Depois de tratar o U+00A0, fecham **381 de 392**. As 47 não existiam —
   eram defeito do meu conversor. *Se esse número tivesse ido para o dono como "a contabilidade não
   fecha com ela mesma", teria sido uma medição errada apresentada como achado.*

`LeitorEspelhoRelatorio::centavos()` tem a mesma cegueira e nunca foi mordido por ela **porque na
inadimplência o desconto chega como número, não como texto**. É armadilha latente, não defeito vivo:
todo conversor de centavos desta fatia normaliza U+00A0 antes de decidir.

#### INV-Q8 — o portão dos acordos fecha por ABA, com tolerância medida

Não há total geral no arquivo. O que existe é, por aba, `Valor final acordado` no cabeçalho contra a
soma da coluna `Valor acordado` das parcelas. Medido nos 6 arquivos das 3 carteiras:

| | |
|---|---|
| abas | **392** |
| fecham exatamente | **381** (97,2%) |
| divergem | **11**, todas entre **−1 e −5 centavos** |

As 11 têm duas propriedades que se repetem sem exceção e que definem a tolerância:

1. a diferença é **sempre para menos** (a soma das parcelas nunca passa do total declarado);
2. `|diferença| ≤ número de LINHAS de parcela` — é arredondamento por linha, não por parcela. O caso
   que prova que a régua é por linha e não por parcela é o `Acordo n105`: **1 parcela, 38 linhas,
   d = −2**.

**Portão:** aceita `0 ≥ d ≥ −(nº de linhas de parcela)`. Fora disso, recusa a aba.
⚠️ **Toda aba que usar a tolerância é contada e reportada.** Tolerância silenciosa é o mesmo descarte
silencioso do INV-Q6 com outro nome.

### 3.3 Receitas detalhadas — dinheiro recebido, sem encargo separado

Aba única, **21.333 linhas** na TL1. Cabeçalho de **10 colunas** na linha 7:

```
Unidade ¦ Sacado ¦ NN ¦ Classe de Conta ¦ Competência ¦ Vencimento ¦ Recebimento ¦
Valor (R$) ¦ Valor recebido (R$) ¦ Informações do acordo
```

Tem rodapé totalizador por classe de conta e uma linha `Total de receitas` com dois valores
(`valor` e `valor recebido`) — é calibrável contra o que o sistema registrou como pagamento.

### 3.4 Dados cadastrais — **não publica dinheiro**

Aba única, 8 colunas na linha 8: `Unidade ¦ Nome/Nome fantasia ¦ Sacado ¦ CPF/CNPJ ¦ Fração ideal ¦
Endereço ¦ E-mail ¦ Telefone`.

🔑 **INV-Q2 — "4 de 4" não é a meta certa para todo número.** Este relatório não tem valor nenhum.
Um número de dinheiro que o cobre não existe; o que ele pode cobrir é identidade de unidade e sacado.
A cobertura, portanto, é declarada **por relatório aplicável**, nunca como uma fração única — e um
instrumento que diga "cobre 3 de 3 relatórios com dinheiro" está sendo mais honesto do que um que
diga "3 de 4" e pareça incompleto, ou "4 de 4" e minta. Ver §5.

⚠️ **Contém PII densa** (CPF/CNPJ, e-mail, telefone de condômino). O espelho guarda linha bruta; esta
é a primeira vez que dado assim entra na tabela. Ver §6.

## 4. Desenho de 0a — ler e guardar os quatro

### 4.1 Um leitor por layout, nenhum reaproveitando o outro

`LeitorEspelhoRelatorio` continua **exclusivo da inadimplência** e não muda de comportamento. Os três
novos nascem ao lado:

| classe | lê |
|---|---|
| `LeitorEspelhoRelatorio` (existe) | inadimplência |
| `LeitorEspelhoAcordos` | acordos detalhados (multi-aba, duas tabelas) |
| `LeitorEspelhoReceitas` | receitas detalhadas |
| `LeitorEspelhoCadastro` | dados cadastrais |

**INV-Q3 — a mesma independência do INV-R1, pelo mesmo motivo.** Os leitores do espelho não
reaproveitam os adapters de importação (`TopLifeInadimplenciaAdapter` e irmãos). Um leitor que
herdasse a interpretação do adapter não serviria para conferir o adapter, que é para o que ele existe.
A duplicação de mecânica é consciente e é o preço.

**INV-Q4 — burro quanto ao significado (herdado do INV-R2).** Nenhum leitor novo decide o que é
principal, encargo ou pagamento. Guarda o que está escrito. Interpretar é trabalho da conferência.

**INV-Q5 — nada se perde (herdado do INV-R3).** Toda linha do arquivo sai classificada num balde e a
contagem por balde tem que somar o total de linhas do arquivo. Célula que não vira número vira `null`
no campo tipado e continua íntegra em `bruto`.

### 4.2 A seleção de arquivos passa a ser por tipo, e o tipo vem do NOME

Hoje: `stripos($nome, 'nadimpl') !== false`. Passa a existir um classificador único que devolve o
`TipoRelatorioContabil` a partir do nome do arquivo, com os padrões medidos nos arquivos reais:

O nome é normalizado antes (minúsculo, sem acento, todo separador virando espaço), então o fragmento
**não pode conter `_`** — e o discriminador é sempre o substantivo do relatório:

| tipo | fragmento |
|---|---|
| `inadimplencia` | `inadimpl` |
| `acordos` | `acordos` |
| `receitas` | `receitas` |
| `cadastro` | `cadastrais` |

⚠️ **`detalhadas` não serve para nada**: aparece em três dos quatro nomes.

⚠️ **INV-Q6 — arquivo não classificado é ERRO, nunca silêncio.** O filtro de hoje descarta em silêncio
tudo que não casa. É assim que três relatórios sumiram por semanas. O carregador passa a **listar** os
arquivos que não reconheceu e a sair com código de não-sucesso se sobrar algum. *Descarte silencioso
foi a causa raiz desta fatia inteira; repeti-lo num lugar novo é o defeito mais provável daqui.*

⚠️ **Os acordos vêm em DOIS arquivos por carteira** (`_EM_ANDAMENTO` e `_LIQUIDADO`), e os dois são do
mesmo tipo. O carregador não pode assumir um arquivo por (carteira, tipo).

### 4.3 Onde os dados novos ficam

`cobranca_relatorio_importado` já tem a coluna `tipo` — a dimensão existe e não muda.

`cobranca_relatorio_linha` tem colunas fixas que servem à inadimplência. Os layouts novos trazem
campos que não têm onde cair (`Parcela`, `Liquidação`, `Valor recebido`, `Valor liquidado`,
`Valor original`, e os campos de cadastro). **Migration, com colunas nullable:**

| coluna nova | de onde vem |
|---|---|
| `aba` (varchar, null) | nome da aba — o acordos tem uma por acordo; os outros ficam `null` |
| `parcela` (varchar, null) | `Parcela` (`5/40`) |
| `liquidacao` (date, null) | `Liquidação` / `Recebimento` |
| `valor_recebido` (int, null) | `Valor recebido` / `Valor liquidado` |

🔴 **Não reaproveitar coluna existente com outro significado.** Mapear `Valor recebido` em `total`
porque "cabe" produz número errado com cara de certo — o defeito que este módulo já levou duas vezes.
Coluna que não existe se cria; campo sem coluna fica em `bruto` e **não** vira número tipado.

⚠️ **Antes de gerar a migration**, fotografar a divergência pré-existente
(`doctrine:schema:update --dump-sql`) e tirar do arquivo gerado tudo que já aparecia — inclusive
qualquer `DROP INDEX` de índice funcional, que o Doctrine propõe apagar por não saber representá-lo.

⚠️ **Frente com migration vai uma de cada vez.** `cobranca-acompanhamento-canonico` está **parada**
mas tem 4 migrations aplicadas no banco de dev (ver `docs/frentes-ativas.md`). Não há conflito de
versão porque ela não avança, mas o banco de dev **não** é fotografia limpa do master.

## 5. Desenho de 0b — a declaração de cobertura

Pedido explícito do dono, 13/08:

> *"depois dela, rode os três instrumentos de novo e me diga QUANTO DOS QUATRO RELATÓRIOS cada número
> cobre. Não quero mais número que parece total e é parcial."*

**INV-Q7 — nenhum instrumento imprime número de dinheiro sem dizer o que ele cobre.** Cada um dos três
comandos passa a abrir com um bloco de cobertura, por carteira:

```
Cobertura desta medição — TOP LIFE I
  inadimplência  lote 8 · emissão 13/08 18:03   ✓ lido e conferido
  acordos        lote 11 · emissão 13/08 18:03  ✓ lido · ⚠ NÃO conferido (fatia 0c)
  receitas       lote 12 · emissão 13/08 18:03  ✓ lido · ⚠ NÃO conferido (fatia 0c)
  cadastro       lote 13 · emissão 13/08 18:03  ✓ lido · sem valor a conferir (INV-Q2)
  Os números abaixo cobrem 1 dos 3 relatórios com dinheiro.
```

Três regras que a experiência desta frente já cobrou:

1. **A cobertura é medida, não declarada em constante.** Ela sai do que existe no banco para aquela
   carteira. Um tipo que ninguém carregou aparece como ausente, e é isso que se quer ver.
2. **Ausência de lote de um tipo não é falha do instrumento**, é cobertura menor — reportada, não
   fatal. Mas **cobertura incompleta não pode sair com caixa verde**: é a mesma armadilha que a
   revisão pegou na peça 4 (`espelho:encargos` imprimia verde sobre 0,4% de cobertura).
3. **Nada de fração única sobre os 4.** A conta é sobre os relatórios **aplicáveis àquele número**
   (INV-Q2) e o texto diz qual é o denominador.

⚠️ **O bloco de cobertura é saída de TELA, e teste que não a lê não a protege.** O
`CalibrarEspelhoCommandTest` existe justamente porque apagar um aviso impresso deixava a suíte verde.
Todo teste de cobertura desta fatia **lê a saída do comando**.

## 6. PII

O espelho já guarda `sacado` e `unidade` — identificam o devedor. O relatório de cadastro acrescenta
**CPF/CNPJ, e-mail e telefone**. Consequências desta fatia:

- os dados entram na mesma tabela multi-tenant, com o mesmo filtro — nada de novo no isolamento;
- **nenhuma saída de comando imprime coluna de cadastro.** O cadastro entra para ser conferido em
  contagem e identidade, não para ser listado;
- salvar saída em arquivo continua indo para `docs/gestao-cobrancas/listas-reconciliacao/`
  (gitignorado), e o `mkdir -p` antes do redirect **não é opcional**.

### 🔴 INV-Q10 — o log de SQL despeja PII, e o comando recusa rodar com ele ligado

**Isto é invariante, não recomendação.** Com `APP_DEBUG=1`, o middleware de depuração do Doctrine
imprime **os parâmetros** de cada consulta. Depois desta fatia, esses parâmetros incluem o conteúdo do
relatório de dados cadastrais:

```
INSERT INTO cobranca_relatorio_linha (...) VALUES (?, ...) (parameters: array{
  "4":"<NOME DO CONDÔMINO>",
  "18":"[\"<UNIDADE>\",...,\"000.000.000-00\",...,\"fulano@example.com\",\"+55 (61) 90000-0000\"]"})
```

⚠️ **O bloco acima é SINTÉTICO.** A primeira versão deste documento trazia o dump real — nome, CPF
válido, e-mail e telefone de um condômino —, colado do log de produção **dentro do documento que
existe para impedir isso**. Foi pego na quarta revisão, antes de qualquer publicação. Exemplo aqui usa
`000.000.000-00` e `example.com` (RFC 2606). *Documentar o vazamento não autoriza reproduzi-lo.*

**Aconteceu de verdade em 13/08/2026**, na medição de volume desta própria fatia: o comando rodou com
`APP_ENV=prod` mas sem `APP_DEBUG=0`, e o `.env` do projeto traz `APP_DEBUG=1`. **`--env=prod` sozinho
não desliga o log.**

O que faz isso ser grave e não nota de rodapé: **o dono roda estes comandos na VPS e cola a saída em
chat** para conferência. Um dump desses tira dado pessoal de centenas de condôminos do sistema por um
canal que ninguém desenhou para isso.

**A trava:** `GuardaDeLogComPii` recusa a execução com código de saída **`69`**, imprimindo o comando
correto pronto para copiar. Escape consciente: `--aceito-log-com-pii`, que diz no nome o que se está
aceitando.

**São NOVE comandos, não quatro** — os do espelho, os quatro importadores (`importar`,
`importar-acordos`, `importar-receitas`, `importar-cadastro`) e a reconciliação. Cinco deles **já
rodavam em produção** e passaram a poder sair com `69`; conferido antes de ligar:
`scripts/vps/bluejus-importar` passa `APP_DEBUG=0` num único ponto de saída desde `00bf1e55` (11/08,
o commit que o criou), então o canal de importação não quebra.

### A régua é declaração, não adivinhação

Todo `*Command.php` de `src/Cobranca/Command/` implementa `LidaComDadoPessoal` **ou**
`NaoLidaComDadoPessoal`, e **quem não declarar nenhuma quebra a suíte**
(`ComandosComPiiPassamPelaGuardaTest`, três checagens: declara exatamente uma · declarou que lida ⟹
chama a guarda · declarou que não lida ⟹ não menciona campo de PII).

🔴 **Duas versões anteriores desta trava falharam, e o registro importa mais que a solução:**

1. **lista de caminhos** — comando novo que esquecesse a guarda passava;
2. **heurística por marcas de PII no código** — era **circular**. Apagando a guarda dos quatro
   comandos do espelho e da reconciliação sobravam **zero** marcas, porque a única que eles tinham era
   a string da própria opção `--aceito-log-com-pii`. *O teste ficava verde justamente quando a
   proteção era removida*, e cobria 4 de 9.

> *"Teste que fica verde justamente quando a proteção é removida é pior que não ter teste — dá falsa
> segurança."* — dono, 13/08/2026.

A declaração obrigatória elimina a categoria: comando novo não nasce fora da régua porque não nasce
sem decidir. A checagem por `ReflectionClass::implementsInterface()` (não por texto) também fecha o
caso de `implements Outra, LidaComDadoPessoal`.

⚠️ **A guarda dispara com debug ligado FORA do ambiente `test`.** A exceção do `test` é deliberada e
tem razão: lá a saída vai para um buffer de asserção, o DAMA reverte tudo e os dados são fabricados
pelo Foundry — não há PII real nem humano lendo terminal. Guardar por `debug` puro quebraria toda a
suíte funcional de comandos, que roda com `kernel.debug = true`.

Runbook completo: [runbooks/espelho-carregar-em-producao.md](../runbooks/espelho-carregar-em-producao.md).

## 7. Fora do escopo — declarado para não virar promessa implícita

- ⛔ **A conferência do conteúdo dos três relatórios novos (0c).** Esta fatia carrega e declara
  cobertura. Dizer se o sistema *bate* com o relatório de acordos é a fatia seguinte, e é ela que
  responde às 226.
- ⛔ **Qualquer escrita em `cobranca_obrigacao`.** A Fatia 1 (remover `honorariosBp = 0` e reconciliar
  as 1.050) não começa antes de 0a+0b+0c rodarem em produção — decisão do dono, 13/08.
- ⛔ **Violação nº 2** (classificação do boleto comum): medida **R$ 0,00** no lote de 13/08 e R$ 0,00
  nos de 11 e 12/08. Sai da fila de conserto. Mexer nela altera `valorOriginal` de boleto comum, raio
  muito maior que o problema. Fica registrada aqui como medida, não como pendência.
- ⛔ **Violação nº 3** (`dataAcordoPadrao`): **não é violação da regra suprema** — as duas fontes que
  derivam a data genuinamente não a publicam, e a única que publica (`acordos detalhados`) já lê a
  real. O furo real é menor e fica para depois: `ImportarAcordosDetalhadosUseCase:506` só grava a
  `Data base` em acordo **novo**; acordo já criado com data derivada nunca é corrigido.
- ⛔ **`--usuario-id` sem validar posse do tenant** (achado da 3ª revisão). Em
  `CarregarEspelhoRelatorioCommand`, o usuário que assina a leitura é resolvido com `find()` puro: um
  id de OUTRO escritório assina o lote em `lidoPor`. **É preexistente** — idêntico em `4ff88ee6`,
  não é regressão desta fatia. Fica registrado como **fatia futura**: mexer em validação de posse sem
  spec própria é exatamente o que esta frente aprendeu a não fazer (decisão do dono, 13/08).
- ⛔ **Violação nº 4** (honorário fora do `valorExigivel()`): R$ 236.364,00 em 6.623 dívidas vivas,
  35 pontos de chamada. Spec própria, por último. O dono pediu a medição **separada em duas
  perguntas** antes de decidir — honorário ser do advogado e não do condomínio pode ser motivo
  legítimo para ficar fora do saldo, e nesse caso a divergência é de **exibição** (o Total que a
  pessoa vê), não de conceito.

## 7.1 O veredito é barra de progresso, não relato de falha

Com o veredito amarrado à cobertura (§5.1), os três instrumentos **nunca imprimem caixa verde hoje** —
eles leem 1 de 3 relatórios com dinheiro. **Isso está certo e não se conserta** (decisão do dono,
13/08):

> *"a caixa verde inalcançável está CERTA. Ela é a barra de progresso da minha meta: o dia em que o
> verde voltar é o dia em que o sistema está batendo com a contabilidade de verdade."*

A obrigação que isso cria é de **legibilidade**: o aviso precisa dizer, item a item, o que falta
cobrir, e dizer em voz alta que não é defeito. As duas faltas são diferentes e pedem ações diferentes:

| pendência | o que resolve |
|---|---|
| `acordos — nenhum lote no espelho` | rodar `app:cobranca:espelho:carregar` |
| `receitas — está no espelho, mas este comando ainda não o confere` | a fatia **0c** |

Um aviso que só diz "parcial", sem dizer parcial em quê, é lido como problema — e alguém vai
"consertar" o que está certo.

## 8. Como esta fatia se prova

1. **Teste por reintrodução, um por invariante que vale dinheiro ou visibilidade.** Em particular:
   INV-Q6 (arquivo não reconhecido tem de falhar — reintroduzir o descarte silencioso e ver o teste
   ficar vermelho), a armadilha das linhas espaçadas do §3.2, e o bloco de cobertura do §5.
   *A suíte já foi cega para um defeito de dinheiro por causa de um helper que zerava encargos: teste
   verde só vale depois de ter ficado vermelho pelo motivo certo.*
2. **Suíte da frente verde** via `scripts/frente-testar.sh cobranca-espelho-quatro-relatorios`
   (o comando padrão testa o repositório principal e dá verde falso).
3. **`/review` antes de encostar em produção**, e re-revisão depois das correções (risco ALTO).
4. **Execução em produção é do dono.** Nenhuma sessão alcança a VPS: o comando é montado aqui, ele
   roda e cola a saída.

### 8.1 O par que trava um defeito de disciplina: construção **e** teste que lê o fonte

O achado 1 da revisão era o mesmo defeito pela **terceira vez** nesta frente: o instrumento calcula
que cobriu uma parte, imprime isso, e estampa caixa verde logo abaixo. Já tinha sido corrigido duas
vezes, e voltou.

Nenhuma das duas metades bastaria sozinha:

- **só construção** (`VereditoSobCobertura` recusando verde) — nada em PHP impede um comando de
  ignorar o objeto e chamar `$io->success()` na mão. Foi assim que o defeito voltou da segunda vez
  para a terceira;
- **só teste** — pega a chamada errada, mas não oferece a certa, e quem for escrever o próximo
  instrumento não tem para onde ir.

Juntos: o objeto é o único caminho para afirmar sucesso, e `VereditoNaoEscapaDaCoberturaTest` lê o
código-fonte dos três comandos proibindo `$io->success(` **e exigindo** `cobertura->declarar(` —
porque proibir a chamada errada não obriga ninguém a fazer a certa.

📌 **Instrução do dono, 13/08: onde aparecer padrão parecido, use a mesma dupla.**

### 8.2 Dois defeitos que só apareceram RODANDO — revisão não substitui execução

A revisão adversarial desta fatia foi boa: 15 achados, vários com medição própria. **Nenhum destes
dois estava nela**, e os dois nasceram justamente das correções que ela pediu.

1. **`!isset($a, $b)` nega o conjunto, não cada um.** A guarda do achado 9 (aba sem `Valor final
   acordado`) saiu como `!isset($comTotalizador[$aba], $sem[$aba])` — verdadeiro quando **qualquer
   um** dos dois falta. Resultado: acusava de "sem declaração" toda aba que **tinha** totalizador.
   Derrubou 5 testes na primeira execução. Lida em revisão, a linha parece correta.

2. **A cobertura passou a despejar o acervo.** Ao corrigir o achado 5 (acordos vêm em 2 arquivos), a
   consulta trocou "último lote" por "todos os lotes" — e na TOP LIFE I do desenvolvimento isso
   imprimiu **8 emissões de inadimplência numa linha de ~500 caracteres**. O código estava certo
   quanto ao dado e inútil quanto ao propósito: *cobertura que ninguém lê não cobre nada*. Só apareceu
   ao olhar a saída real, e a resposta certa era a **emissão mais recente**, agrupada por `emitidoEm`.

**A lição, escrita para a próxima frente:** revisão de código encontra o que está escrito; rodar
encontra o que acontece. Aqui as duas foram necessárias, nessa ordem — e o INV-Q10 (o despejo de PII)
também só apareceu rodando.

### 8.3 Revisão de DADO é passada separada da revisão de lógica — regra operacional

Escrita depois de a quarta revisão achar **PII real de condômino versionada no Git**, colocada lá
pela própria fatia que existe para impedir o despejo de PII: o bloco de exemplo do incidente, em três
arquivos, era o dump de produção colado como veio — nome completo, CPF de dígito verificador válido,
e-mail, telefone e unidade de uma pessoa real. O histórico foi reescrito pelo dono antes de qualquer
publicação (os 6 commits da frente viraram 1).

**As regras, que valem para qualquer frente deste repositório:**

1. **Quem revisa documento com exemplo tem de olhar o EXEMPLO, não só o diff.** Ler o diff responde
   "a mudança está certa?"; não responde "este dado pode existir aqui?". São duas passadas, e a
   segunda não acontece sozinha. Três revisões passaram por esses arquivos: as duas primeiras porque
   o bloco não existia ainda, a terceira porque leu a lógica do diff que o introduziu.

2. **Exemplo de dado pessoal é sintético, sempre.** `000.000.000-00` (inválido por construção) e
   `example.com` (RFC 2606). Documentar um vazamento não autoriza reproduzi-lo — e o aviso de que o
   exemplo é sintético fica no próprio arquivo, senão a próxima pessoa acha que o real é "mais
   didático".

3. 🔴 **Checagem de dado sensível se monta por PADRÃO, nunca pelo VALOR.** Ao entregar o comando de
   conferência, o autor escreveu `git log -S "<início do CPF real>"` — o que põe o dado no terminal
   do dono e no histórico do shell dele. É a mesma falha um nível acima: a busca por vazamento
   virando vazamento. A forma certa não cita o dado:

   ```bash
   # padrão de CPF/CNPJ em qualquer lugar do repositório, incluindo histórico
   git grep -nE '[0-9]{3}\.[0-9]{3}\.[0-9]{3}-[0-9]{2}'
   git log -p --all | grep -nE '[0-9]{3}\.[0-9]{3}\.[0-9]{3}-[0-9]{2}' | head
   ```

4. **Trava que protege um canal não protege o dado.** A `GuardaDeLogComPii` fecha o terminal; o
   vazamento saiu pelo **repositório**, que vai para a VPS no deploy e para o remoto no push. Ao
   fechar um canal, pergunte por quais outros o mesmo dado sai.

### 8.4 ⏳ Fatia futura: varredura de PII no repositório INTEIRO

Achado do dono em 14/08, **maior que o desta frente e fora do escopo dela**: há strings em formato de
CPF em arquivos **já publicados no remoto** —

- `docs/specs/cobranca-etapa7-importacao.md`
- `docs/specs/cobranca-importar-cadastro-condominos.md`
- `docs/gestao-cobrancas/mockup-ajuste10-objeto-show.html`

🔴 **Não são todos sintéticos — um foi MEDIDO e é real.** A quinta revisão conferiu dígito
verificador e cruzou contra o banco (`saas_ux`, dataset de produção), sem citar valor:

| arquivo | dígito verificador | casa com dado real? |
|---|---|---|
| `docs/specs/cobranca-importar-cadastro-condominos.md` | **válido** | 🔴 **sim — 1 ocorrência em `cobranca_relatorio_linha.bruto`** |
| `docs/specs/cobranca-etapa7-importacao.md` | inválido | não |
| `docs/gestao-cobrancas/mockup-ajuste10-objeto-show.html` | inválido | não |

Ou seja: **CPF de condômino real, versionado e já publicado no remoto.** É pior que o vazamento desta
frente, porque já saiu da máquina — reescrever commit local não resolve.

⛔ **Deliberadamente NÃO tratado aqui** (decisão do dono, 14/08): vira fatia própria. O que ela vai
precisar decidir, e que não é técnico: se limpar histórico já publicado vale o custo, ou se basta
remover da árvore e registrar.

Quando for aberta, a varredura tem de cobrir **o repositório inteiro e o histórico** — não só estes
três arquivos —, e ser montada **por padrão, nunca por valor** (§8.3, regra 3).
