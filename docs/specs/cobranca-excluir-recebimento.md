# Excluir recebimento — o desfazer que faltava no caminho do dinheiro

**Risco:** ALTO (apaga dinheiro recebido; mexe em liquidação de dívida)
**Data:** 2026-08-01 · **Origem:** régua D7 do dono + o beco sem saída aberto pela frente `cancelar acordo`
**Handoff de origem:** `docs/gestao-cobrancas/HANDOFF_EXCLUIR_RECEBIMENTO.md`

---

## 1. O problema

Duas coisas convergem.

**a) Um portão sem saída.** `CancelarAcordoUseCase:154` recusa cancelar acordo com pagamento alocado em
alguma parcela (`AcordoComParcelaPagaException`), e a mensagem manda *"Desfaça o pagamento primeiro"*.
**Desfazer pagamento não existe.** Há apenas `cobranca_pagamento_registrar` e
`cobranca_pagamento_corrigir`; a correção exige `valorPago` **positivo** (`CorrigirPagamentoInput.php:29`)
e o docblock do UseCase diz literalmente *"NÃO há estorno no MVP"*. O próprio docblock da exceção
(`AcordoComParcelaPagaException.php:20-23`) registra a lacuna como beco sem saída.

**b) A régua D7 do dono (01/08):** *"ações não podem ser irreversíveis"* — e vale **também para os
escritórios que não usam importação**, logo a reversibilidade não pode depender de reimportar planilha.

**Palavras do dono sobre o desenho:**

> *"na lista de valores 'recebidos' tenha a opção de apagar recebimento. A lista deve ter todos os
> recebimentos. Os que são de acordos ligados ao pagamento do acordo. Aí, para excluir um registro de
> pagamento, resolve o problema quando tiver que cancelar ou romper acordo."*

## 2. Decisões do dono (01/08, ao abrir a frente)

| # | Decisão |
|---|---|
| E1 | **Apagar de verdade (hard delete).** A linha `cobranca_pagamento` e suas `cobranca_alocacao_pagamento` somem do banco. Sem soft-delete. |
| E2 | **Motivo é OPCIONAL.** Diferente de `CorrigirPagamento` (que exige `motivoCorrecao`) e de `ExcluirObrigacao` (que exige `motivo`). |
| E3 | **Capacidade: `resources.cobranca.movimentacao_financeira`** — a mesma de registrar e corrigir. Quem lança pode desfazer o próprio erro. Nenhuma capacidade nova, nenhuma migration de permissão. |

### 2.1 Por que E1 não recria o problema que fez o acordo cancelado ser escondido

Na frente `cancelar acordo` a revisão forçou esconder em vez de apagar, porque a linha do acordo
guardava informação insubstituível (**quais originais ele substituiu**) e a reimportação ressuscitava um
acordo órfão, contando a mesma dívida duas vezes.

**Um pagamento não tem nenhuma das duas propriedades.** Ele não é procurado por chave externa por
importador nenhum (nenhum comando CLI toca `Pagamento` — medido: `grep -rln "Pagamento"
src/Cobranca/Command/` volta vazio), e a informação que ele carrega (valor, data, onde abateu) cabe
inteira no payload JSON do evento de histórico. Apagar aqui não perde dado que outra parte do sistema
precise para ficar correta.

## 3. O comportamento especificado

### 3.1 Contrato

- **Rota:** `POST /cobrancas/pagamentos/{id}/excluir`, nome `cobranca_pagamento_excluir`.
- **Capacidade:** `resources.cobranca.movimentacao_financeira` (E3).
- **CSRF manual** por pagamento: token `excluir_pagamento_<id>` — o padrão de
  `ObrigacaoController::excluir:137`, porque a ação é um botão-modal reutilizável, sem Symfony Form.
- **Motivo:** campo opcional no corpo (`motivo`); string vazia vira `null` no payload. Aparado e
  **truncado em 255 no servidor** — o `maxlength` do modal é só HTML e não vale como guarda.
- **Redirect:** `cobranca_objeto_show#secao-movimentos` (é onde a lista de recebimentos vive).

### 3.2 Guardas, na ordem

1. `PagamentoRepository::findOneByIdDoTenant($id, $tenant)` → `null` dispara **404**. É a guarda
   multi-tenant: pagamento de outro escritório não existe para este usuário. (O filtro SQL do Doctrine
   **não** se aplica a `find()` por PK — por isso o tenant vai explícito.)
2. Pagamento sem caso (defensivo; a `JoinColumn` é `NOT NULL` mas o getter é nullable) → **404**.
3. **Caso encerrado → recusa** (`CasoEncerradoException`), a mesma regra de
   `CorrigirPagamentoUseCase:63-65`. Caso encerrado não aceita movimentação financeira; abrir uma exceção
   só para o delete criaria uma assimetria sem pedido do dono.

### 3.3 O núcleo — e o defeito que o handoff teria introduzido

⚠️ **O handoff (`§3`) manda chamar o reconciliador com `alocadoPorObrigacao` ZERADO nas obrigações
tocadas. Isso está errado e é um defeito de dinheiro.**

Se **outro** pagamento também abateu a mesma obrigação, zerar o alocado dela faz o reconciliador
`reabrir()` (`ReconciliadorLiquidacao:103-105`) uma dívida que **continua legitimamente paga** — o
devedor volta a ser cobrado pelo que pagou em outro lançamento. É o mesmo erro que §3.2 da spec de
cancelar acordo classifica como o pior possível neste domínio.

**O certo é o alocado FINAL:** `Σ persistido no banco − as alocações DESTE pagamento`. É exatamente o que
`CorrigirPagamentoUseCase::alocadoFinalPorObrigacao:179-201` faz (lá com `− antigas + novas`; aqui só
`− antigas`, porque não há novas). O Σ persistido ainda inclui as alocações a apagar, pois o `remove` só
some no flush — então subtrair é obrigatório, e é suficiente.

**Sequência do UseCase:**

1. Resolve o pagamento (guardas §3.2).
2. **Lê as alocações do pagamento por QUERY**, nunca por `$pagamento->getAlocacoes()` — ver §3.4.
3. Monta o snapshot autocontido para o evento (§3.5).
4. `alocadoFinal = AlocacaoPagamentoRepository::somasPorObrigacaoDosCasos([casoId], tenant)` menos, por
   obrigação, o valor das alocações deste pagamento.
5. `reconciliarLiquidacoes(obrigacoesTocadas, alocadoFinal)` — **local, sem data e sem o
   `ReconciliadorLiquidacao`** (ver §3.3.1; a versão inicial desta spec mandava chamar o reconciliador
   com a data do pagamento apagado, e era por ali que os três defeitos entravam).
   - **Obrigações tocadas:** só as que este pagamento abateu (as demais não mudaram de alocado).
6. `RegistrarEventoHistorico::registrar(..., PagamentoExcluido, ..., flush: false)`.
7. `PagamentoRepository::remover($pagamento, flush: true)` — o mesmo flush commita evento + remoção.
   Padrão idêntico ao de `ExcluirObrigacaoUseCase:66-83`.

### 3.3.1 ⚠️ O segundo caminho para o mesmo defeito — achado pela revisão

A regra do §3.3 (alocado final, nunca zero) **não bastava**. A revisão encontrou o mesmo erro de dinheiro
entrando por outra porta, e ele é alcançável pela tela com as carteiras reais (as duas têm juros ≠ 0):

`ReconciliadorLiquidacao:77-84` **recalcula o exigível AO VIVO** na data que recebe, ignorando o
congelamento — enquanto `Obrigacao::liquidar()` documenta o contrário: *"após isto, nenhum leitor
recalcula: os quatro valores são o snapshot definitivo"*.

Cenário medido:

1. Obrigação de R$ 1.000,00, vencida em 01/01, carteira com juros de 1% a.m.
2. Pagamento **legítimo** em 01/02 aloca R$ 1.010,00 → **liquida** e congela nesse valor.
3. **Duplicata** lançada em 01/06 aloca outros R$ 1.010,00 na mesma obrigação (permitido: o
   `AlocadorPagamento` valida caso e Σ, não o restante da obrigação, e a alocação manual oferece
   obrigação já paga).
4. Apagar a duplicata reconcilia em **01/06**: o exigível recalculado nessa data é R$ 1.050,00, acima do
   alocado que sobra (R$ 1.010,00) → **`reabrir()`**. A dívida quitada em fevereiro volta a viva e os
   juros voltam a correr **sobre dinheiro já entregue**.

#### O espelho — achado na 2ª passada da revisão

A primeira correção cobria **uma** direção. O recálculo ao vivo erra nas duas, e a segunda é a mais
comum na tela:

5. Parcial de R$ 20,00 em 01/02; em 01/06 entra o que fecha a conta e a obrigação **liquida em
   R$ 1.050,33**. Apagar o parcial reconcilia em **01/02**, quando a dívida valia R$ 1.010,33 — o que
   sobra (R$ 1.030,33) *parece* cobri-la, então ela fica **liquidada e CONGELADA com R$ 20,00 a
   descoberto**.
6. Medido: o saldo do caso **mostra** os R$ 20,00 (obrigação liquidada continua no exigível, e
   `bruto − alocado` = 2000) — o dinheiro não some da conta. O que quebra é o relógio: congelada, aquela
   diferença **nunca mais cresce**, e a linha se diz quitada. Subcobrança silenciosa.

#### A terceira porta — achada na 3ª passada

Cobrir as duas direções acima ainda deixava o ramo da obrigação **VIVA** decidindo por data, e ali o
erro é o oposto: a exclusão **LIQUIDA** dívida viva.

7. R$ 5,00 em 01/02 e R$ 1.100,00 em 01/12 numa dívida que vale R$ 1.111,33 em dezembro → Σ 1.105,00,
   obrigação **viva**, faltam R$ 6,33. Apagar o recebimento de **R$ 5,00** reconcilia em **01/02**,
   quando a dívida valia R$ 1.010,33 — o que sobra (R$ 1.100,00) supera esse número e a obrigação é
   **liquidada e congelada**, com `liquidadaEm` anterior ao pagamento que a cobriu. Saldo do caso fica
   **negativo**: crédito fictício. Apagar R$ 5,00 apagou ~R$ 96,00 de dívida.

#### 🔑 A correção, e o princípio que a resume

**Apagar pagamento só TIRA dinheiro.** Disso decorre a regra inteira, e ela dispensa data:

| Estado da obrigação | Destino |
|---|---|
| **viva** | continua viva — não foi liquidada com MAIS alocado, não há como ser com menos. **Uma exclusão nunca quita dívida.** |
| **liquidada**, alocado final **cobre** o snapshot (`valorExigivel()`) | segue quitada, intacta |
| **liquidada**, alocado final **não cobre** o snapshot | `reabrir()` — volta a viva e descongela |

Por isso a exclusão **não usa `ReconciliadorLiquidacao` e não usa data nenhuma**
(`ExcluirPagamentoUseCase::reconciliarLiquidacoes`). Os três defeitos somem juntos quando a data sai da
conta — não por remendo em cada um, mas porque a data nunca deveria ter entrado numa operação que só
subtrai.

**Substituída por acordo VIGENTE** não é tocada em hipótese alguma (mesma guarda de
`ReconciliadorLiquidacao:63`): quem manda nela é o acordo. **Congelada mas não liquidada** (encargo
legado travado) também fica intacta.

O recálculo por data continua valendo em **Registrar** e **Corrigir** pagamento, onde entra dinheiro e a
data é a base legítima da quitação.

**Escopo da correção:** só a exclusão. O mesmo recálculo existe no caminho de `CorrigirPagamento`, e
mudá-lo é alterar serviço COMPARTILHADO (Registrar/Corrigir) — fora desta frente. **Fica registrado como
achado para o dono decidir**, não silenciado.

### 3.4 Por que ler as alocações por QUERY

Regra da casa que já custou caro: **coleção INVERSA do Doctrine nasce vazia quando a entidade foi criada
na mesma unidade de trabalho.** `Pagamento::$alocacoes` é o lado inverso (`mappedBy: 'pagamento'`), então
num teste que cria pagamento e alocação na mesma UoW ela pode vir vazia — e o alocado final sairia igual
ao Σ do banco, o reconciliador não reabriria nada, e **o teste passaria mesmo assim**, porque em produção
é outro request e a coleção carrega do banco. O defeito ficaria invisível exatamente onde dói.

Por isso: método novo `AlocacaoPagamentoRepository::doPagamento(int $pagamentoId, Tenant $tenant): array`,
por DQL, filtrando tenant.

*(A remoção física das alocações continua por `cascade: ['remove']` + `orphanRemoval` do mapeamento —
ler por query é para o CÁLCULO, não para o delete.)*

### 3.5 O evento tem de ser autocontido

`TipoEventoHistorico::PagamentoExcluido` (novo). Depois do delete o pagamento não existe para ser
consultado, então o payload JSON guarda tudo — padrão de `ExcluirObrigacaoUseCase:71-79`:

```
pagamentoId, data (Y-m-d), valorDivida, valorEncargos, valorHonorarios, valorTotal,
motivo (ou null), alocacoes: [{obrigacaoId, descricao, valor}, ...]
```

A descrição visível na timeline é autoexplicativa e traz o valor, porque **o `dados` JSON não é exibido
em Twig nenhum** — é só rastro para auditoria.

**Duas mudanças obrigatórias no enum** (`TipoEventoHistorico.php`), ambas `match` **sem `default`** — um
case novo quebra em runtime se esquecido:
- `label(): string` → `'Pagamento excluído'`;
- `ehTrabalhoDeCobranca(): bool` → **`false`**. O corte da Central de Acompanhamento é *"falar com devedor,
  negociar, receber, encaminhar"*; apagar um lançamento errado não é nenhum dos quatro — é correção
  administrativa, como `ObrigacaoExcluida` (também `false`). Contar aqui faria quem só desfez um erro
  aparecer com "ação recente" sem ter cobrado ninguém, que é a distorção que a spec §5.1 da Central
  existe para evitar.

### 3.6 Resíduo conhecido e aceito

O evento `PagamentoRegistrado` do pagamento apagado **permanece** no histórico (não há FK entre
`cobranca_evento_historico` e `cobranca_pagamento`). Consequência: o contador de "baixas" da Central
(`EventoHistoricoRepository:139`, `COUNT(*) FILTER (WHERE e.tipo IN (:pagamento,:liquidacao))`) segue
contando aquela baixa.

**Fica assim, de propósito:** o histórico é registro do que aconteceu, e o lançamento aconteceu — a linha
`PagamentoExcluido` logo abaixo conta o resto da história. Apagar eventos passados contradiria o desenho
de rastro que a frente `cancelar acordo` acabou de firmar. Registrado aqui para a revisão poder
contestar, não para ser descoberto depois.

### 3.7 O que NÃO precisa ser feito (medido)

- **Saldo:** corrige-se sozinho. `CalculadoraSaldo:82` abate por **query agregada**
  (`totalAlocadoEmObrigacoes`), nunca pela coleção do Doctrine. Apagada a linha de alocação, o `SUM` cai
  na próxima leitura (invariável 20: saldo é derivado, nunca coluna). **Nenhuma escrita imperativa de
  saldo.**
- **Honorários recebidos:** idem — `MontarDetalheCasoUseCase:227-230` recalcula de `doCaso`.
- **Migration:** nenhuma. O tipo do evento é coluna string; o enum é PHP.
- **Repositório:** `PagamentoRepository::remover()` **já existe** (`:33`) e hoje não tem chamador — com
  `cascade: ['remove']` + `orphanRemoval` (`Pagamento.php:70-76`) ele apaga as alocações antes do
  pagamento, na ordem certa do `UnitOfWork`.
- ⚠️ **Nunca por SQL cru:** a FK `FK_86758925E06F81F7` (`Version20260709142845:42`) **não tem
  `ON DELETE CASCADE`**; um `DELETE FROM cobranca_pagamento` direto viola a FK.

### 3.8 Tela

- Botão **Excluir** ao lado do **Corrigir** existente, em `_movimentos.html.twig:74-87`, sob o mesmo
  `podeMovimentar` (capacidade + caso não encerrado).
- Modal `modalExcluirPagamento` em `_acoes_modais_financeiro.html.twig`, no padrão do modal reutilizável:
  `action` injetada por JS a partir de `data-acao-url`, confirmação com valor e data do recebimento, campo
  de motivo **opcional**.
- ⚠️ `formExcluirPagamento` **tem de entrar na lista de guarda anti-submit-sem-alvo** em
  `show.html.twig:2131` — sem isso, um submit com `action` vazia posta na própria página.

### 3.9 Docblocks que passam a mentir (regra: nunca documentar comportamento que não existe)

Três lugares afirmam que desfazer pagamento não existe. Com esta frente, passam a ser falsos e **têm de
ser corrigidos no mesmo commit**:
- `AcordoComParcelaPagaException.php:20-29` (docblock) e a **mensagem** da exceção, que hoje manda
  "desfaça o pagamento primeiro" sem apontar caminho;
- `CancelarAcordoUseCase.php:138-141`;
- `Pagamento.php:23-24` e `CorrigirPagamentoUseCase.php:26` (*"NÃO há estorno no MVP"*) — continua verdade
  que **correção** não estorna, mas a frase precisa deixar de sugerir que não há desfazer nenhum.

## 4. Como se prova

Cada teste validado **reintroduzindo o defeito que ele guarda**, e conferindo que a prova mede algo real —
na frente anterior DOIS asserts passavam com o defeito presente (um media estado que o cenário nunca
produzia; outro chamava método sem chamador de produção).

| # | O que prova | Defeito que ele guarda |
|---|---|---|
| P1 | O saldo do caso sobe **exatamente** o valor apagado | reconciliação/alocação erradas |
| P2 | Obrigação **liquidada por aquele pagamento volta a VIVA** e os juros voltam a correr | não chamar o reconciliador (obrigação trava em Liquidada+Congelada) |
| P3 | 🔑 Obrigação paga por **DOIS** pagamentos, apagando um: **continua liquidada** se o outro ainda cobre | o "zerar o alocado" do handoff (§3.3) |
| P3b | 🔑 **Carteira COM juros**: apagar duplicata lançada meses depois não reabre a dívida liquidada antes | §3.3.1, falsa reabertura — a carteira neutra dos outros testes é incapaz de produzir este cenário |
| P3c | 🔑 **Espelho**: apagar o parcial anterior à quitação REABRE a obrigação que ficou a descoberto | §3.3.1, sub-reabertura — liquidada e congelada com valor faltando |
| P3c2 | 🔑 **Terceira porta**: apagar recebimento pequeno e antigo NÃO liquida obrigação viva nem gera saldo negativo | §3.3.1, liquidação espúria |
| P3d | Liquidada é decidida pelo snapshot, não pelo valor recalculado (unit, barato) | a mesma troca de referência, sem precisar de carteira com juros |
| P3e | Substituída por acordo vigente não é tocada nem a descoberto | guarda de `reconciliarLiquidacoes` |
| P4 | Pagamento parcial: `alocado` da obrigação zera e `restante` volta ao cheio | subtração incompleta |
| P5 | **Depois de apagar, cancelar o acordo passa** | é o fecho do portão (§1a) |
| P6 | Pagamento de outro tenant → **404** | vazamento multi-tenant |
| P7 | Caso encerrado → recusa | guarda §3.2.3 |
| P8 | Sem a capacidade → sem acesso | guarda de permissão |
| P9 | CSRF inválido → não apaga | guarda CSRF |
| P10 | As `cobranca_alocacao_pagamento` somem junto (nenhuma órfã) | cascade quebrado |
| P11 | O evento `PagamentoExcluido` guarda valor, data e onde estava alocado | payload não autocontido |

**Viés de confirmação = a contabilidade.** Enquanto a *Receitas detalhadas* (frente 2) não for importada,
todo recebimento é digitado à mão e sem conferência externa — mais uma razão para poder apagar.

## 5. Fora de escopo

- **Desfazer liquidação** (`Liquidacao`): a linha dela em `_movimentos.html.twig:101` tem o bloco de ações
  vazio. Mesma régua D7 aplicada, mas é frente própria — o dono pediu "apagar recebimento".
- **Soft-delete / lixeira**: descartado por E1.
- **Reativação por importação (D6)** e **importar Receitas**: frentes 2 e 3, nesta ordem.
- **Aceitar boleto sem principal** (R$ 4.390,86 da TOP LIFE I): pendência declarada pelo dono em 01/08 —
  medir antes se o boleto é acessório de um de taxa.

## 6. Estado ao abrir a frente

- `master` local em `8b6df934`, **5 commits não publicados**, árvore limpa.
- Suíte **3113/3113 OK** (11815 asserções), medida nesta sessão — não herdada do handoff.
- Migration `Version20260801150000` aplicada no dev, **pendente em produção**.
- ⏳ **Smoke do dono no caso 193 ainda NÃO foi feito** (confirmado por ele em 01/08). A frente anterior
  está provada pela suíte, não pela tela.
