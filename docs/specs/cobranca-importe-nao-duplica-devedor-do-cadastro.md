# SPEC — O importe não duplica o devedor que o cadastro já criou

**Risco: ALTO.** Mexe em **identidade do devedor**: quem é a pessoa cobrada num caso de cobrança.
Exige esta spec, teste provado por reintrodução do defeito e **duas** passadas de `/review`.

**Origem:** achado em 08/08/2026 ao importar a **carteira da AMLI BR 060** (a primeira carteira do
sistema que recebe o relatório de **Dados cadastrais**). Handoff: §17 de
`docs/gestao-cobrancas/HANDOFF_AUTOMATIZAR_DOWNLOADS.md`.

---

## 1. O problema, em uma frase

Quando a unidade **já existe com a pessoa vinculada** (porque o cadastro dos condôminos a criou) mas
**ainda não tem caso de cobrança aberto**, o importe cria uma **segunda pessoa com o mesmo nome** — sem
CPF, sem e-mail, sem telefone — e abre o caso apontando para **essa cópia pobre**, deixando a pessoa
completa do cadastro de lado.

## 2. O que foi MEDIDO (não suposto)

Tudo contra as planilhas de `2026-08-04-completo/` e a carteira 3 (AMLI) do `saas_ux`.

### 2.1 O tamanho do defeito na AMLI

| porta | pessoas que criaria | em unidades que já têm pessoa |
|---|---:|---:|
| `app:cobranca:importar` (Inadimplência) | **9** | 9 |
| `app:cobranca:importar-receitas` (Receitas) | **45** | 45 |

**45 das 51 unidades da AMLI** terminariam com o devedor em duplicata. As 44 pessoas com CPF, e-mail e
telefone que o cadastro importou ficariam **fora do caso de cobrança**.

### 2.2 Inverter a ordem NÃO resolve — medido nos dois sentidos

Banco descartável `saas_ux_amli_ordem` (restaurado do dump do `saas_ux` de antes do cadastro):

| ordem | pessoas novas | unidades com a MESMA pessoa duplicada |
|---|---:|---:|
| cadastro → inadimplência | 54 | 9 |
| **inadimplência → cadastro** | **54** | **9** |

As duas ordens produzem o mesmo estrago, porque as duas fontes se reconhecem por chaves diferentes: o
**cadastro casa por CPF** (que a inadimplência não traz) e o **importe casa por nome**. Prova no banco —
`DIOGO PEREIRA RIBEIRO (id 216, SEM CPF)` convivendo com `DIOGO PEREIRA RIBEIRO (id 225, CPF …)` na
mesma unidade, e assim em 9 unidades.

⚠️ **Um caso parecido que NÃO é defeito e não pode ser tocado:** `QUADRA D LOTE 03` tem duas pessoas
**diferentes** (`CARLOS ALBERTO DE LIMA` e `EDUARDO TAVARES DE LIMA`), os dois proprietários da unidade.
Nomes distintos → pessoas distintas → correto. A regra só une **nome igual**.

### 2.3 Por que isto nunca apareceu antes

| carteira | unidades | pessoas com CPF | pessoas sem CPF |
|---|---:|---:|---:|
| TOP LIFE I | 81 | **0** | 91 |
| TOP LIFE II | 121 | **0** | 124 |
| AMLI BR 060 | 51 | **44** | 1 |

**A AMLI é a primeira carteira com o cadastro importado.** Em TL1 e TL2 ninguém tem CPF — só a
inadimplência rodou, e ela não traz documento. O teste do zero (§13) importou
*Inadimplência → Receitas → Acordos*, **sem cadastro**: o caminho com defeito nunca foi exercitado.

### 2.4 A causa exata, no código

`ImportarRelatorioCarteiraUseCase.php:181-186`:

```php
$caso = $this->casoRepository->casosAtivosDoObjeto($objeto)[0] ?? null;
if ($caso === null) {
    $pessoa = $this->criarPessoa->executar(...);   // ← cria SEM olhar quem já está no objeto
```

O ramo vizinho (`elseif`, `:188`) **já faz a checagem certa** com
`sacadoJaRepresentadoNoObjeto()` — e `nomesRepresentadosNoObjeto()` **já aceita caso nulo** e já varre
`pessoasVinculadasAoObjeto()`. A capacidade existe; ela só não é usada no ramo em que o caso é nulo.

`ImportarReceitasUseCase.php:156-160` tem a **mesma** forma, sem nem o `elseif`.

🔑 **É o mesmo padrão de falha que a 1ª revisão da §16 pegou:** *"a porta B era mais frouxa que a porta
A"*. Por isso a correção nasce como **um serviço único usado pelas duas portas**, não como dois trechos
parecidos que divergem na próxima manutenção.

---

## 3. A regra nova

Antes de criar a pessoa no ramo em que **o caso ainda não existe**, procurar entre as pessoas **já
vinculadas àquele objeto** uma cujo nome normalizado (`NormalizadorNome`) seja **igual** ao do sacado:

- **achou** → reusa essa pessoa; **não** cria pessoa, **não** cria vínculo novo, **não** incrementa o
  contador de pessoas criadas. O caso abre apontando para ela;
- **não achou** → comportamento de hoje, sem alteração alguma.

**Escopo da busca: o objeto.** Nunca global, nunca entre carteiras, nunca entre tenants — a mesma regra
de escopo que o `NormalizadorNome` já documenta.

## 4. O que esta spec NÃO faz

- **não funde** pessoas duplicadas que já existam em banco (isso é migração de dados, frente própria);
- **não troca** a pessoa cobrada de um caso já aberto — continua valendo a §28 (decisão jurídica é
  humana, o importe só reporta);
- **não mexe** no importador de cadastro, nem no de acordos (que não cria pessoa);
- **não usa CPF** como chave: a inadimplência e a receita não trazem documento. A chave é o nome
  normalizado **dentro do objeto**, que é a mesma régua que o `elseif` vizinho já usa.

## 5. Riscos aceitos e registrados

1. **Dois moradores homônimos na mesma unidade viram uma pessoa só.** Aceito: é exatamente o que o ramo
   `elseif` já faz hoje para o mesmo caso, e a alternativa (duplicar sempre) é o defeito que se corrige.
   Unicidade por nome dentro de **uma unidade** é o que o sistema já assume.
2. **Reusar pessoa do cadastro muda quem é cobrado** em relação ao comportamento atual — e é o objetivo:
   passa a ser a pessoa **com** CPF e contato, em vez da cópia sem documento.

## 6. Como provar

- teste que **falha** com o código de hoje (unidade com pessoa vinculada e sem caso → hoje cria 2ª pessoa);
- teste dos **dois sentidos**: nome igual reusa · **nome diferente continua criando** (o caso legítimo
  do §2.2);
- teste em **cada uma das duas portas** — a regra da casa é que porta nova não pode nascer mais frouxa;
- **prova por reintrodução do defeito** em cada teste, conferindo qual assert fica vermelho;
- suíte completa verde.

---

## 7. REVISÃO 1 — o que a revisão achou e o que foi feito

O revisor **mediu antes de afirmar**: rodou os dry-runs reais na carteira 3 (AMLI) e confirmou
**0 pessoas criadas** nas duas portas (eram 9 e 45), e rodou `tests/Cobranca` (1.621 testes) em vez de
aceitar "verde" de relato. Nenhum bloqueante de dinheiro ou identidade.

### 7.1 Corrigidos

| # | achado | correção |
|---|---|---|
| MÉDIA 1 | o **handoff §17.2 dizia "a AMLI importa limpo"** — escrito sobre o import que duplicava 45 devedores | §19 do handoff registra esta frente e a §17.2 foi corrigida, **no mesmo commit** |
| MÉDIA 2 | faltava o cenário em que o ramo novo e o `elseif` do §28 decidem **em sequência sobre o mesmo objeto** | `testLinhaQueReusaELinhaDivergenteNoMesmoObjeto`: duas linhas, uma reusa e a outra é reportada como divergente, e a cobrada continua sendo a primeira |
| MÉDIA 3 | faltava teste **cross-tenant** num risco ALTO | `testNaoReusaPessoaDeOutroTenant`, com a MESMA identificação de unidade e o MESMO nome nos dois escritórios — o pior caso |
| BAIXA 4 | na porta B, o contador e a decisão de criar usavam **argumentos diferentes** | uma resolução só (`$pessoaExistente`) alimenta as duas coisas: não há como divergirem |
| BAIXA 5 | comentário afirmava "a pergunta é feita antes de qualquer escrita", verdade só na 1ª linha | reescrito para dizer o que o código garante |
| BAIXA 6 | o resolvedor era chamado em **toda** linha, mesmo com caso existente | só é chamado quando `objeto existe E não há caso` — nos dois modos, com a mesma condição |
| BAIXA 9 | ordem dos imports nos 4 testes ajustados | corrigida |
| — | nenhum teste contava **vínculos** (foco 4 da revisão) | `assertCount(1, …VinculoPessoaObjeto…)`: reusar não cria vínculo a mais |

### 7.2 Registrados, sem mudança de código (medidos como risco zero hoje)

- **BAIXA 7 — homônimos exatos no mesmo objeto:** `pessoasVinculadasAoObjeto` não tem `ORDER BY`; se um
  dia houver duas pessoas de nome normalizado idêntico na mesma unidade, qual delas vira a cobrada é
  indefinido. **Medido: 0 pares nas 3 carteiras.** Herda a régua que o `elseif` do §28 já usava.
- **BAIXA 8 — vínculo encerrado conta como "já vinculada":** a query não filtra `data_fim`. Uma pessoa
  com vínculo encerrado pode virar a cobrada de um caso novo. **Medido: 0 vínculos encerrados nas 3
  carteiras.** Mesma régua do `elseif`; mudar isso é decisão de produto, não desta correção.

### 7.3 Prova por reintrodução — 5 injeções, 5 vermelhos

| # | defeito reintroduzido | assert que ficou vermelho |
|---|---|---|
| 1 | porta A, escrita: volta a criar pessoa sempre | *"a pessoa do cadastro tem de ser REUSADA"* + o de nome normalizado |
| 2 | porta A, **prévia**: contador ignora o reuso | *"a prévia não pode prometer pessoa que a confirmação não cria"* |
| 3 | porta B, escrita | *"o caso cobra a pessoa COM CPF, não uma cópia sem documento"* |
| 4 | porta B, contador | *"a pessoa do cadastro é REUSADA, não duplicada"* |
| 5 | resolvedor busca **global** (ignora objeto e tenant) | *"reuso é DENTRO do objeto"* + o cross-tenant, que estoura em `PessoaNaoEncontradaException` |

🔑 **A injeção 3 ensinou algo que nenhum teste pegava:** o contador continuou dizendo *"0 pessoas
criadas"* enquanto o banco ganhava uma pessoa. Por isso os testes passaram a **contar no banco**, não só
conferir o contador — número de relatório não é o que está na tabela.

🔑 **A injeção 5 revelou que o isolamento tem DUAS barreiras**, não uma: a query do vínculo e a
revalidação de tenant em `AbrirCasoUseCase`, que derruba a transação inteira.

---

## 8. REVISÃO 2 — achou defeito nas correções da 1ª (a 12ª vez seguida nesta frente)

O revisor mediu de novo (dry-runs reais nas duas portas: **0 pessoas** em ambas), reconferiu os números
da §2.3 e da §7.2 no banco, e confirmou que o helper removido não deixou chamada órfã.

### 8.1 O achado que importou — e ele é do mesmo tipo que a §16 já tinha corrigido

🔴 **Eu fechei os dois furos de teste da 1ª revisão SÓ NA PORTA A.** O teste novo da porta B usava
**uma linha**, e o revisor mediu que a AMLI tem **319 recebimentos para 45 unidades — ~7 por unidade**.
Ou seja: o teste cobria o arranjo que **não existe** na carteira que originou a frente, e deixava de
fora o que existe. É literalmente *"a porta B mais frouxa que a porta A"* outra vez.

Pior: da **2ª linha em diante** de uma unidade sem caso, os dois modos passavam valores **opostos** —
na prévia o caso continua nulo (`true`), na confirmação ele já nasceu na linha 1 (`false`).

**Corrigido em duas frentes:**
1. `testUnidadeDoCadastroComVariosRecebimentosMantemParidade` — 3 recebimentos da mesma unidade, prévia
   × confirmação em **todos** os campos;
2. a resposta passou a ser **memorizada por unidade, no primeiro encontro**, nos dois modos — a paridade
   deixa de depender de um gate distante e as consultas caem de **uma por linha para uma por unidade**
   (319 → 45 na AMLI).

### 8.2 🔑 A 6ª injeção PASSOU — e isso corrige o que eu ia escrever

Removi a memorização e o teste multi-linha **continuou verde**. A paridade estava sendo sustentada
sozinha pelo gate `casosVistos` do acumulador, exatamente como o revisor afirmou.

**Portanto, dito sem inflar:** a memorização **não conserta um defeito observável** — ela é defesa em
profundidade (tira a paridade da dependência de um gate distante) e economia de consulta. Vender isso
como "correção de um bug de paridade" seria falso, e o docblock do teste registra que ele **não** falha
sem a memorização. *(Esta frente já teve 4 injeções passando batido; esta é a 5ª, e a única cuja lição
foi "a correção é menor do que eu ia dizer".)*

### 8.3 Demais achados

| # | achado | desfecho |
|---|---|---|
| MÉDIA 2 | comentário afirmava que os dois modos pagavam **a mesma query** — medido: prévia 319, confirmação 45 | resolvido pela memorização; o comentário foi reescrito |
| BAIXA 3 | import de `ObjetoCobranca` ficou órfão ao remover o helper | voltou a ser usado (type-hint do helper memorizado) |
| BAIXA 4 | handoff §19.4 dizia "3.423 verde", número **anterior** aos 2 testes que as correções da 1ª acrescentaram | corrigido para **3.426** |
| BAIXA 5 | docblock do teste cross-tenant dizia "pega as duas barreiras" — o filtro `v.tenant` **não** é exercitado (a unidade do tenant B nasce sem vínculo) | docblock reescrito para dizer o que o teste garante |
| BAIXA 6 | `ImportarCadastroCondominosUseCase` **recusa** unir quando a vinculada tem CPF e a de entrada não; o resolvedor **une** nesse caso | é a direção que a §5.2 quer, mas a contradição não estava escrita — fica registrada aqui |

### 8.4 Confirmados como não-achado pelo próprio revisor

O assert de vínculo mede o que promete (com o defeito nascem 2); o default `= false` de
`projetarObjetoECaso` está morto (os 2 chamadores passam o argumento); o vermelho por
`PessoaNaoEncontradaException` no cross-tenant **não** invalida o teste; a remoção do helper não perdeu
comportamento.

**Estado final: 3.426 testes verdes, 13.164 asserts.**
