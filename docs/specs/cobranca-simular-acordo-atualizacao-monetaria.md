# Simular acordo — calculadora de atualização monetária (réplica do JurisCalc/TJDFT)

> **Origem do pedido:** replicar a calculadora pública do TJDFT
> (`https://juriscalc.tjdft.jus.br/publico/calculos`) dentro do JusPrime, acionada pelo botão
> **Simular acordo** do `cobranca_objeto_show`.
>
> **Risco:** BAIXO pela taxonomia do `CLAUDE.md` (não toca ponto eletrônico nem identidade
> User/Tenant). Mas **produz número de dinheiro que vai para a mão do devedor**, então o rigor
> aplicado aqui é o de MÉDIO: spec antes do código, teste antes do motor, revisão contra a spec.

## 1. O que é a calculadora de origem

Calculadora pública de **atualização monetária judicial**: corrige valores por índice oficial,
aplica juros de mora, multas, honorários, os consectários do art. 523 §1º do CPC e o ressarcimento
de custas, e emite um demonstrativo imprimível.

Fonte autoritativa usada nesta spec: o manual oficial do TJDFT (26 páginas,
`https://www.tjdft.jus.br/servicos/atualizacao-monetaria-1/juriscalc-atualizacao-monetaria2024.pdf`),
complementado pela leitura dos bundles Angular da SPA.

**Ressalva importante sobre a investigação.** Os bundles JavaScript do site contêm também a versão
**interna** do sistema (autenticada, para a Contadoria do tribunal), com consulta ao PJE, cadastro
de participantes do processo, "Cálculos da unidade" e uma seção de Pagamentos/Deduções. **Nada
disso existe na calculadora pública** e nada disso entra aqui. O escopo desta spec é o da página
pública, conforme o manual.

## 2. Decisões tomadas com o dono

| # | Decisão | Escolha |
|---|---|---|
| 1 | Seções internas do tribunal (PJE, participantes, cálculos da unidade) | **Fora de escopo** — não existem na calculadora pública |
| 2 | Período coberto pela tabela de índices | **01/1994 até hoje** (Plano Real em diante, sem conversão de moeda antiga). O dono afirmou depois que **nenhuma dívida do escritório é anterior a 2000** — logo 1994–1999 é folga, não requisito, e a armadilha da janela de datas do BCB (§8) deixa de ter efeito prático. A carga continua desde 1994 porque a série inteira cabe em uma requisição e custa o mesmo |
| 3 | Relação com a dívida do objeto | **Pré-preenche, não grava** — é simulação; não altera saldo, obrigação nem histórico |
| 4 | Persistência das simulações | **Penduradas no objeto de cobrança** (por tenant), não em lista global |
| 5 | Campos Processo / Credor / Devedor | **Mantidos e pré-preenchidos**, editáveis |
| 6 | Prova de fidelidade dos números | **Casos de referência rodados na calculadora oficial**, congelados como teste. Dono autorizou uso do navegador exclusivamente no site do TJDFT |

## 3. Por que motor próprio, e não as alternativas

**Descartado — `iframe` da calculadora oficial.** Não pré-preenche, não salva, não integra, e a
página do TJDFT muito provavelmente recusa enquadramento. Além disso não seria "ter no sistema".

**Descartado — estender o motor de encargos existente**
([`CalculadoraEncargos.php`](../../app/src/Cobranca/Service/CalculadoraEncargos.php) /
[`EncargosVivos.php`](../../app/src/Cobranca/Service/EncargosVivos.php)). Aquele motor trabalha com
**taxa configurada pelo escritório** (% ao mês), não com **índice de inflação**. São regras
diferentes, e ele calcula o saldo que já está em produção. Mexer nele para acomodar índice arrisca
o dinheiro de quem já usa o sistema.

**Descartado — consumir a API do TJDFT.** Medido: `GET /api/public/configuracao-calculo-correcaos`
devolve **401 Unauthorized**. Não há endpoint público, e depender de API interna de tribunal num
SaaS comercial seria frágil e indevido.

**Adotado — domínio novo `app/src/AtualizacaoMonetaria/`**, isolado do motor de cobrança, com
tabela de índices própria alimentada das fontes primárias.

## 4. Onde vive e como abre

- Rota `cobranca_objeto_simular_acordo`, **página inteira** (o formulário tem listas dinâmicas de
  valores, multas, honorários e custas — não cabe bem em modal).
- O botão em [`app/templates/cobranca/objeto/show.html.twig`](../../app/templates/cobranca/objeto/show.html.twig)
  (linha ~184) perde o `disabled`, o `is-disabled` e o `<span>` com o tooltip "Ainda não tem função".
  O comentário do bloco (§1.4) precisa ser atualizado: hoje ele afirma que `Simular acordo` não
  existe no sistema.
- **Permissão:** mesmo gate `podeGerenciar` das demais ações do cabeçalho.
- **Isolamento:** o acesso é sempre pelo objeto de cobrança, que já é do tenant. O controller
  carrega o objeto pelo repositório filtrado por tenant; id de outro escritório resulta em 404, não
  em 403 (não revela existência). Teste funcional cross-tenant é obrigatório.

## 5. Os campos — réplica da calculadora pública

### 5.1 Aba "Dados do Cálculo"

**Identificação (opcional, pré-preenchida, editável)**

| Campo | Pré-preenchimento |
|---|---|
| `Processo` | número do processo da pasta judicial vinculada ao caso, se houver |
| `Credor` | cliente do caso de cobrança |
| `Devedor` | pessoa cobrada do objeto |

**Configuração**

- `Data final do cálculo` — opcional. **Em branco = data de realização da conta** (manual, p. 6).
- `Índice de atualização monetária` — duas opções, texto literal do original:
  - *Índices oficiais TJDFT (INPC até 31/08/2024, IPCA a partir de 01/09/2024)*
  - *INPC (Durante todo o período)*

**Valores** (lista dinâmica)

- Colunas: `Valor`, `Data do Valor` (termo inicial da correção daquele valor), `Descrição`.
- Botão `+ Adicionar valor`.
- O mesmo botão adiciona **parcelas de mesmo valor com vencimentos em intervalos mensais**
  (manual, p. 11) — gerador de N parcelas a partir de uma data.
- Ações por linha: editar (valor, data, descrição, confirmado por `Salvar valor`) e excluir.
- **Pré-preenchimento a partir do objeto** — ver §6.

**Juros**

- Termo inicial, três opções mutuamente exclusivas:
  - *A partir do(s) Valor(es) Devido(s)* — juros correm da data de cada valor;
  - *A partir da data da citação ou outra data* (+ campo de data) — para valores **anteriores** à
    data informada, os juros correm dela; para os **posteriores**, correm do próprio vencimento
    (manual, p. 13);
  - *A partir de uma data fixa* (+ campo de data) — mesma data para todas as parcelas.
- Tipo:
  - *Juros legais* — composição por período: **0,5% a.m. até 10/01/2003**, **1% a.m. a partir de
    11/01/2003**, **taxa legal a partir de 30/08/2024**;
  - *Percentual Fixo* — habilita o campo `Percentual`;
  - *Sem Juros* — incide apenas correção monetária.
- `Data final da incidência dos juros` — opcional. **Em branco = data da conta** (manual, p. 16).
- **Regra condicional do manual (p. 9):** quando o índice escolhido é *INPC (Durante todo o
  período)*, os juros legais são apenas 0,5% até 10/01/2003 e 1% a partir de 11/01/2003 — **a taxa
  legal não entra**. A taxa legal só existe na opção *Índices oficiais TJDFT*.

**Multas**

- Uma ou mais, cada uma em **percentual (%)** ou **valor monetário (R$)**.
- Botão `+ Adicionar Multa`.

**Honorários**

- **Percentual** — incide sobre o **total do débito atualizado**.
- **Valor fixo (R$)** — admite **termos iniciais próprios** de correção monetária e de juros,
  distintos dos do cálculo principal (manual, p. 18).
- Botão `+ Adicionar honorários`.

**Consectários da mora (art. 523, §1º, CPC)**

Três opções, **uma por cálculo** (a SPA original recusa duplicar: *"Já existe multa/honorário. Não
é possível adicionar mais de um(a) multa/honorário do 523."*):

- *Multa (art. 523 CPC)* — 10%, fixo;
- *Honorário de cumprimento de sentença (art. 523 CPC)* — 10% ou valor monetário;
- *Ambas (multa e honorário art. 523 CPC)* — 10% + 10%.

**Ressarcimento de custas e outras despesas processuais**

- Linhas de `Valor` + `Descrição`, botão `+ Adicionar Custas`.
- **Não incide juros nem multa sobre esses valores** — explícito no manual (p. 21). É uma regra
  fácil de perder e tem caso de referência dedicado (§8).

### 5.2 Aba "Demonstrativos do Cálculo"

- Demonstrativo com os valores apurados **agrupados por verba cadastrada**.
- Botões `Editar cálculo` (volta à aba 1 com tudo preenchido), `Novo Cálculo` (limpa),
  `Imprimir` (PDF via Dompdf, que o projeto já usa).
- Acréscimo desta implementação: `Salvar simulação` (§7).

### 5.3 Validações herdadas do original

Reproduzir as mensagens de recusa que a SPA original já faz: valor obrigatório e maior que zero;
data inicial obrigatória; percentual obrigatório quando o tipo de juros é percentual; índice
obrigatório; descrição do valor com no máximo 50 caracteres; **não são permitidos cálculos com data
futura**; não são permitidas datas posteriores à data final do cálculo.

## 6. Pré-preenchimento a partir da dívida — e a armadilha

Os `Valores` entram com as **obrigações em aberto** do objeto: uma linha por obrigação, com

- `Valor` = **principal ainda em aberto** da obrigação;
- `Data do Valor` = **vencimento** da obrigação;
- `Descrição` = descrição/competência da obrigação.

> **⚠️ Só o principal entra.** Juros, multa, correção e honorários já calculados pelo motor da casa
> (`EncargosVivos`) **não** são pré-preenchidos. A calculadora vai calcular os encargos dela a
> partir do principal e da data — se o principal já viesse com encargos embutidos, o mesmo dinheiro
> seria contado duas vezes. Esse é exatamente o tipo de erro que já ocorreu neste módulo antes.

O usuário pode editar, remover e acrescentar linhas livremente depois do pré-preenchimento.

**A divergência precisa estar escrita na tela.** O número da simulação será **diferente** do
`Total em aberto` exibido no mesmo objeto, porque as regras são outras (índice de inflação × taxa
configurada pelo escritório). Isso é esperado e correto, mas silencioso vira dúvida de "qual é o
certo?" na hora de cobrar. Exigência: frase explícita no topo da simulação **e** no rodapé do
impresso, dizendo que é simulação por índice oficial e que não substitui o saldo do sistema.

## 7. Modelo de dados

### 7.1 `indice_monetario` — **sem `tenant_id`**

| Coluna | Tipo | Nota |
|---|---|---|
| `id` | integer | ver a nota de PK abaixo |
| `serie` | varchar(20) | `INPC` · `IPCA` · `TAXA_LEGAL` (enum PHP) |
| `competencia` | date | sempre o dia 1 do mês |
| `variacao_pct` | numeric(12,6) | variação percentual do mês, como o BCB publica |
| `fonte` | varchar(40) | ex.: `BCB/SGS/188` |
| `importado_em` | timestamp | `datetime_immutable`, a convenção do projeto; migração para `datetimetz_immutable` é backlog do repositório inteiro, não desta feature |

`UNIQUE (serie, competencia)` — que serve também de índice das duas consultas quentes (série inteira
em ordem, e última competência publicada da série). Um índice extra nas mesmas colunas seria
redundante e o Doctrine o descarta ao gerar a migration.

> **Nota de PK — `integer`, não `uuid`.** *(Implementado primeiro, decidido depois: a Parte 2 foi
> escrita com `integer`, a divergência foi levada ao dono como pendência aberta, e ele **ratificou**
> em 29/07/2026. Registrado nessa ordem de propósito — spec ajustada ao código já escrito é
> precedente ruim, e quem ler daqui a seis meses tem direito de saber qual veio antes.)*
>
> As duas tabelas desta feature usam `integer` auto-increment. Esta spec pedia `uuid`, mas
> `symfony/uid` não está instalado e
> nenhuma das ~40 entidades do projeto usa UUID. O que o uuid compraria é id não-enumerável em URL —
> e disso quem cuida é o guarda cross-tenant que o §7.2 exige (404, nunca 403, para não revelar
> existência), que é defesa mais forte e já obrigatória. Adotar uuid em uma feature só deixaria o
> repositório meio a meio; se for para migrar, é decisão do projeto inteiro, não desta frente.

> **Exceção consciente à regra de isolamento multi-tenant.** O `CLAUDE.md` exige filtro de tenant em
> toda query, e a regra continua valendo para tudo o mais nesta feature. Índice oficial de inflação
> é **dado público, idêntico para todos os escritórios** — replicá-lo por tenant não protegeria nada
> e criaria a possibilidade de dois escritórios calcularem o mesmo mês com números diferentes.
> Tratamento: tabela global de referência, como uma tabela de câmbio, **somente leitura para a
> aplicação** (só o importador escreve). Nenhum dado de tenant entra nela.

### 7.2 `simulacao_acordo` — **com `tenant_id`**

| Coluna | Tipo | Nota |
|---|---|---|
| `id` | integer | ver a nota de PK em §7.1 |
| `tenant_id` | FK | filtro obrigatório |
| `objeto_id` | FK | para o objeto de cobrança |
| `criado_por_id` | FK | usuário |
| `criado_em` / `atualizado_em` | timestamp | `datetime_immutable`, como o resto do projeto |
| `descricao` | varchar(255) | rótulo da proposta |
| `entrada_json` | jsonb | todos os parâmetros do cálculo |
| `resultado_json` | jsonb | demonstrativo **congelado** |

> **O id aparece em URL** (excluir, reabrir), e ele é sequencial. A proteção **não** é a
> imprevisibilidade do número: é o guarda de posse — acesso a simulação de outro escritório devolve
> **404, nunca 403**, para não revelar sequer que o registro existe. Isso é requisito de teste da
> Parte 4 do plano, não recomendação.

> **Por que congelar o resultado.** O IBGE revisa índice publicado, e a tabela pode mudar depois.
> Uma proposta enviada ao devedor tem de continuar mostrando o número que mostrou no dia. O
> `resultado_json` é o que a tela e o impresso exibem ao reabrir; recalcular só acontece se o
> usuário mandar.

## 8. Índices: origem, importação e guardas

**Fonte única: API pública do Banco Central (SGS).** Uma integração em vez de duas — o BCB publica
INPC e IPCA além da taxa legal. Endpoint:
`https://api.bcb.gov.br/dados/serie/bcdata.sgs.{serie}/dados?formato=json`

| Série | Código SGS | Cobertura medida em 29/07/2026 |
|---|---|---|
| INPC | `188` | de 04/1979 a 06/2026 (567 registros) |
| IPCA | `433` | de 01/1980 em diante |
| Taxa legal (Lei 14.905/2024) | `29543` | de 08/2024 em diante |

**⚠️ Armadilha medida — não use os filtros de data da API.** A consulta
`?dataInicial=01/01/1994&dataFinal=31/03/1994` na série 188 devolve **lista vazia**, embora os dados
existam (buscando a série inteira, 01/1994 = 41,32). O importador deve **baixar a série completa e
filtrar em PHP**. Confiar na janela produziria importação silenciosamente vazia.

**Command `app:importar-indices-monetarios`** — idempotente (reimportar valor igual não duplica, não
altera e não mexe nem no `importado_em`), roda a carga histórica (a partir de 01/1994) e o incremento
mensal com o mesmo comando. Opções: `--serie` e `--dry-run`. Lock por `flock`, como o cron do DJEN.
Não sobrescreve valor já gravado sem registrar a alteração — cada revisão sai na tela e no log com o
valor anterior e o novo. Sai com `FAILURE` quando qualquer série falha, **volta vazia** ou
quando **o lock está ocupado**, para o cron alarmar em vez de deixar a tabela silenciosamente
incompleta. Falha numa série não descarta as que já terminaram: cada série é uma unidade de trabalho
própria, com `flush` ao fim e descarte do parcial em caso de erro.

**Cron mensal** — a periodicidade foi escolhida pelo dono em 29/07/2026, entre mensal e diário. O
**dia 11 às 4h** é decisão de implementação, não do dono: o INPC do mês sai por volta do dia 7–10 do
mês seguinte, e as 4h seguem o horário do cron do DJEN. Mesmo padrão dele, no host da VPS:

```
0 4 11 * * docker exec -w /var/www/app jusprime_php_prod php bin/console app:importar-indices-monetarios >> /var/log/jusprime-indices.log 2>&1
```

> ⏳ **Só instalar depois do deploy** — o comando ainda não existe na imagem de produção. E vale saber
> o que a escolha mensal implica: uma execução falha deixa a tabela um mês parada, e a guarda abaixo
> passa a recusar cálculo que deveria funcionar. Por isso o `FAILURE` no exit importa aqui mais do
> que num cron diário: o log é o único aviso. Recuperação é manual e barata — rodar o comando à mão.

**Guarda do índice não publicado.** O INPC de um mês sai por volta do dia 7–10 do mês seguinte —
medido: em 29/07/2026 o último INPC disponível era **06/2026**. A calculadora **recusa** data final
posterior à última competência publicada, explicando qual é o último mês disponível. Nunca extrapola
nem repete o último índice.

⚠️ **A última competência publicada é por SÉRIE, não global** (medido em 29/07/2026: a taxa legal já
tinha `07/2026` enquanto INPC e IPCA paravam em `06/2026`). A guarda tem de perguntar pela série que
o cálculo vai usar — uma "última competência" global recusaria data válida ou, pior, aceitaria data
sem índice.

## 9. Como a fidelidade será provada

O manual descreve os campos, mas **não publica a fórmula**. Ficam genuinamente em aberto, e serão
**determinados pelos casos de referência, não por suposição**:

- juros legais simples ou compostos, e como os três períodos (0,5% · 1% · taxa legal) se compõem;
- a ordem entre correção monetária e juros;
- o critério de arredondamento a cada etapa;
- a base exata sobre a qual incidem os 10% do art. 523;
- se a correção é composta a partir das variações mensais ou de coeficiente acumulado publicado —
  os dois caminhos divergem em centavos, e é o caso de referência que decide qual reproduz o TJDFT.

**Método.** Rodar na calculadora oficial do TJDFT um conjunto de casos de entrada conhecida,
registrar entrada + resultado, e congelar como teste unitário do motor. A suíte passa a falhar se
divergir **um centavo**. Os casos são escritos **antes** do motor, conforme o fluxo do `CLAUDE.md`
(teste primeiro).

**Cobertura mínima dos casos de referência:**

1. valor único, só correção, sem juros
2. valor único com juros legais
3. vários valores com datas diferentes
4. virada 31/08/2024 → 01/09/2024 (INPC → IPCA) na opção *Índices oficiais TJDFT*
5. mesma virada na opção *INPC durante todo o período* (deve seguir em INPC)
6. virada 10/01/2003 → 11/01/2003 (0,5% → 1%)
7. taxa legal após 30/08/2024
8. juros *a partir da citação*, com valores antes e depois da data (prova a regra da p. 13)
9. juros *a partir de data fixa*
10. percentual fixo de juros
11. *Sem Juros*
12. multa em percentual
13. multa em valor monetário
14. duas multas no mesmo cálculo
15. honorário percentual sobre o total atualizado
16. honorário em R$ com termos iniciais próprios de correção e juros
17. art. 523 — só multa
18. art. 523 — só honorário
19. art. 523 — ambas
20. custas — **provando que não recebem juros nem multa**
21. gerador de parcelas mensais iguais
22. data final em branco (= data da conta)

## 10. Testes

**Unitários** (`app/tests/AtualizacaoMonetaria/Unit/`)
- os 22 casos de referência acima contra `CalculadoraAtualizacaoMonetaria`, ao centavo;
- guarda de competência não publicada;
- validações de formulário (data futura, data posterior à final, percentual obrigatório).

**Funcionais** (`app/tests/AtualizacaoMonetaria/Functional/`)
- abrir a simulação de objeto de **outro tenant** → 404;
- usuário sem `podeGerenciar` → recusado;
- POST sem token CSRF → recusado (**assertar a mensagem**, não só o status — o projeto já teve teste
  de recusa que passava pelo motivo errado);
- salvar simulação → não altera saldo, obrigação nem histórico do objeto (asserção explícita, é a
  garantia da decisão #3);
- reabrir simulação salva → exibe o resultado congelado.

**Importador**
- série completa parseada corretamente;
- reimportação é idempotente;
- resposta vazia da API **não** apaga nem zera a tabela.

## 11. Riscos e o que fica de fora

| Risco | Tratamento |
|---|---|
| Número da simulação ≠ saldo do sistema | frase explícita na tela e no impresso (§6) |
| Índice do mês ainda não publicado | recusa explicando o último mês disponível (§8) |
| API do BCB fora do ar | cálculo usa a tabela local; só o importador depende da API |
| IBGE revisa índice já publicado | resultado congelado no `resultado_json` (§7.2) |
| Divergência de arredondamento com o TJDFT | casos de referência ao centavo (§9) |

**Explicitamente fora de escopo:** consulta ao PJE, cadastro de participantes do processo,
"Cálculos da unidade"/Contadoria, seção de Pagamentos/Deduções, índices anteriores a 01/1994 e
gravação de qualquer valor calculado como saldo oficial do objeto.
