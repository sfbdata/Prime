# Handoff — o sistema como espelho da contabilidade

> Estado em **2026-08-19**. Este documento é autossuficiente: quem o ler não precisa de nenhuma
> conversa anterior.

## 1. A regra que manda

**O sistema reflete EXATAMENTE os dados da contabilidade.** Ela é a autoridade sobre a dívida; o
sistema existe para traduzir o que ela diz. Valor diferente do dela é **defeito**, por mais razoável
que a regra do código pareça. É decisão fechada do dono — não reabrir, não perguntar *"você quer
corrigir?"*.

Três delimitações do dono, todas essenciais:

**1.1 — O sistema MOSTRA; a gerência JULGA.**

> *"Não faz sentido dizer que está faltando alguma coisa se a contabilidade está fazendo as coisas.
> O sistema não tem que dizer que está faltando, deve apenas mostrar os números de uma forma que, se
> estiver faltando, é a gerência que vai analisar os números e ver essa falta — e não o sistema
> dizer isso."*

É o nome certo do defeito: **as violações são o sistema formando opinião em vez de copiar.** Ao achar
uma divergência, a pergunta NÃO é *"qual é a regra certa?"* — é **"o sistema está reproduzindo o
número dela, ou está julgando?"**. Se julga, o conserto é **tirar o julgamento**, não trocá-lo por um
melhor. **Espere apagar código, não escrever.** Solução que acrescenta regra nova provavelmente
entendeu a tarefa errado.

**1.2 — DADO é dela; INTERFACE é do dono.**

Valor gravado no banco (principal, juros, multa, honorário, data de acordo, se está liquidada) ⟹ tem
de ser o dela. Arranjo, rótulo, agrupamento e navegação ⟹ do dono, é produto, não mexer.
⚠️ Cuidado com o derivado que **volta a virar dado**: um cálculo de tela que também decide como um
pagamento é abatido é dado disfarçado. Percorra os pontos de chamada antes de classificar.

**1.3 — O sistema é o "UX melhorado da contabilidade".**

> *"É para mostrar os números como estão na contabilidade, só que de uma forma que traz uma melhor
> experiência ao usuário."*

Resolve o caso ambíguo: quando um número **existe** no relatório dela e o sistema mostra **outro**,
isso não é liberdade de interface — é o espelho quebrado.

**A exceção legítima, já aceita pelo dono:** entre um relatório e o próximo o sistema recalcula
encargo **ao vivo** (`EncargosVivos`). Isso é **projeção**, não julgamento, e a calibração provou que
a fórmula reproduz a dela (90,8% das linhas ao centavo, em produção). **Não desmontar.**

## 2. O que JÁ está em produção (não refazer)

| entregue | prova |
|---|---|
| **Espelho dos 4 relatórios** | integrada 14/08 (`0194be63`). A régua lia 1 de 4; agora guarda os quatro |
| **Dupla contagem corrigida** | 13/08: 25 dívidas reconciliadas, R$ 1.429,55 fora do saldo, régua confirma **zero** nas 3 carteiras |
| **O sistema parou de inventar data de acordo** | 18/08 (`dbb434e5`). Migration `Version20260817180000` aplicada em prod, `data_acordo` aceita nulo. 4 revisões + smoke do dono |
| **Importação de 17/08** | 3 carteiras, 5/5 passos, R$ 5.994,16 |

### O estado medido em produção (lote de 13/08, cobertura de 1 de 3 relatórios com dinheiro)

| | resultado |
|---|---|
| dívida que ela cobra e o sistema não tem | **0** |
| dívida com principal diferente do dela | **0** |
| dupla contagem | **0** |
| dívidas conferindo | **3.701** |
| linhas de encargo exatas | **4.294 de 4.727 (90,8%)** |
| sobrando no sistema (ela não lista) | 99 · R$ 18.020,10 |
| ela dá como paga, sistema não baixou | 26 |
| diferença de REGRA (acima de R$ 1) | 415 linhas |

🔑 **`até 1 centavo` = ZERO nas três carteiras.** Medindo linha a linha a faixa de arredondamento
desapareceu — confirmação independente de que **a contabilidade calcula por linha**.

## 3. AS PENDÊNCIAS, em ordem

### 3.1 🔴 O passivo dos 377 acordos com data chutada — O MAIOR

Medido em prod em **19/08**: **377 de 398 acordos** com `data_acordo` no dia 1º do mês (o acaso daria
~13). Sobre elas foram calculados **R$ 203.265,07** de encargo, e **256 dívidas cobram R$ 0,00**
porque a data inventada **precede o vencimento da própria dívida** (pior caso: 679 dias antes).

🔴 **Elas NÃO se consertam sozinhas.** `ImportarAcordosDetalhadosUseCase:395` faz
`if (!$acordo->temData() && $aba->dataBase !== null)` — **só preenche o vazio, não sobrescreve data
existente**. A justificativa no comentário é correta para o caso geral (não regravar o mesmo dado a
cada lote), mas para estes 377 a data guardada **não veio da fonte**: foi inventada, e o importador
não tem como distinguir.

**O que fazer:** comando de reconciliação, no molde do `app:cobranca:reconciliar-dupla-contagem`
(que já rodou em produção e é o padrão da casa: simula, mostra número, e só grava com `--aplicar`).

⚠️ **Isto faz a cobrança SUBIR.** Não é decisão de *se* — a data está errada e tem de ficar certa.
É decisão de *quando e como*, e exige: (a) medir quanto sobe, por dívida e no total; (b) simulação
com números; (c) autorização explícita do dono; (d) provavelmente aviso à equipe de cobrança.

### 3.2 🔴 Honorário no total — R$ 126.878,17 — JÁ APROVADO PELO DONO

Medido no rodapé do relatório de inadimplência dela (emissão 17/08):

| | principal | juros | multa | honorários | total dela |
|---|---:|---:|---:|---:|---:|
| 3 carteiras | 535.384,49 | 149.771,17 | 10.705,69 | **126.878,17** | **822.739,52** |

Ela soma `principal + juros + multa + honorários`. O `Obrigacao::valorExigivel()` deixa o honorário
de fora — a tela mostra ~R$ 126.878,17 **a menos** que o documento dela.

**Método obrigatório: medir → auditar → só então escrever.** Três coisas a resolver com dado, não
com bom senso:

1. **Os 35 pontos de chamada**, sendo que **dois gravam**: o que decide se a dívida **reabre** ao
   excluir pagamento (`ExcluirPagamentoUseCase:175`) e o que faz a obrigação criada-já-paga não mexer
   no saldo (`ImportarReceitasUseCase`).
2. **Quantas dívidas hoje marcadas como quitadas deixariam de estar** — quem pagou principal + juros
   + multa e não o honorário. Medir em produção **antes** de escrever.
3. **A ordem de alocação de um pagamento.** Se hoje o dinheiro abate principal → juros → multa,
   entrar o honorário muda para onde ele vai. **Não escolham uma ordem: descubram a dela** — o
   relatório de receitas separa o recebido por categoria, inclusive `1.15 - Honorário advocatício`.

⚠️ Efeito visível: o total na tela **sobe ~R$ 126 mil**. O dono avisa a equipe; registre na spec.

### 3.3 🔴 A baixa que a planilha manda dar e o sistema não dá

`ResultadoImportacaoAcordos:22` diz, com todas as letras: *"A baixa de pagamento está FORA de escopo
(§5): o resumo avisa para conferir à mão."* **Não é limitação técnica** — o importador **tem** os NNs
liquidados, ele os imprime. É escolha de escopo virando regra de escritório dentro do código.

Tamanho: **centenas por lote** (só a TL1 em 17/08: ~860 nos dois arquivos de acordo). É a explicação
mais provável das **99 dívidas "sobrando"**.

⚠️ **Investigar antes de consertar:** quem registra o dinheiro é o importador de **receitas**. Se a
baixa passar a sair do relatório de **acordos**, ficam dois caminhos liquidando a mesma parcela.
Descubra **por que as receitas já importadas não deram a baixa** antes de abrir o segundo caminho —
o conserto certo pode ser no importador de receitas.

### 3.4 Honorário zerado na parcela de acordo — 103 dívidas · R$ 7.229,81

`ImportarAcordosDetalhadosUseCase:1290` **e** `ImportarReceitasUseCase:577` gravam `honorariosBp = 0`
("acordo não cobra honorário sobre honorário"). A tela mostra R$ 0,00 e a contabilidade cobra.

📌 **Hipótese REFUTADA (não a persiga de novo):** não é o mesmo defeito da data. Populações e
mecanismos diferentes; as duas subcobram e se somam.

🔑 **O relatório de acordos NÃO tem coluna de encargo** — só Valor acordado e Valor liquidado. O
honorário das 103 **não sai de lá**.

### 3.5 Principal reclassificado no boleto comum — R$ 6,28

`TopLifeInadimplenciaAdapter` trata a linha `1.4`/`1.5` como encargo; a contabilidade trata como
**principal** e cobra encargo em cima. Valor pequeno, mas mexe em `valorOriginal` de boleto comum —
raio maior que o número. **Prova de que a régua dela é a que vale:** nas parcelas de acordo da TL1 a
multa lançada em cada linha é 2% do Valor **daquela linha** — 23/23 nas linhas de multa. Ela cobra
multa **sobre** a linha de multa; logo, para ela, aquilo é principal.

### 3.6 A régua ainda cobre 1 de 3 relatórios com dinheiro — a "fatia 0c"

`conferir` e `calibrar` comparam só contra a **inadimplência**; receitas e acordos são guardados mas
não conferidos. **É o que segura a caixa verde**, que é o portão do dono para começar a interface.

🔴 **Defeito conhecido e não consertado:** o painel de cobertura **nomeia o lote errado**. Ele escolhe
o de **maior `id`** (último carregado) em vez do de `emitido_em` mais recente. A `inadimplencia` escapa
porque tem `dados_ate` preenchido; `cadastro`, `receitas` e `acordos` têm `dados_ate = NULL`. Medido
em 17/08 na TL1: `cadastro` tem lotes 16 (13/08), 40 (12/08) e 28 (11/08) — o painel nomeou o **40**.
Hoje não corrompe número (esses tipos aparecem como "carregado, mas NÃO conferido"), mas **vira
dinheiro na 0c**, quando a medição passar a usá-los.

### 3.7 Investigação aberta: as 99 injulgáveis

Carregar os lotes de 11 e 12/08 **não mudou nada** (83/4/12, idênticas) — a hipótese "faltava carregar
o lote" está **derrubada**. Pista medida, não conclusão: `assinatura avaliada` = 3.016 na TL1, e as
dívidas em aberto com `encargos_atualizados_em` em 13/08 são 3.001 e em 11/08 são 15 (3.001+15=3.016).
Há 621 com snapshot em **07/08** e 44 em **10/08**, datas sem lote carregado.

### 3.8 🔴 Fatia própria: CPF em arquivos JÁ PUBLICADOS

Um dos três arquivos abaixo tem CPF com dígito verificador **válido** que casa com o dado real
(medido, não hipótese). Já saiu da máquina, então é mais grave que o resto:
`docs/specs/cobranca-etapa7-importacao.md` · `docs/specs/cobranca-importar-cadastro-condominos.md` ·
`docs/gestao-cobrancas/mockup-ajuste10-objeto-show.html`. A decisão que ela exige **não é técnica**:
se limpar histórico já publicado vale o custo, ou se basta remover da árvore e registrar.

## 4. Como medir (nada disso é chute)

Régua pronta, em produção, somente leitura:

    app:cobranca:espelho:conferir    # mesmo conjunto e mesmo principal
    app:cobranca:espelho:calibrar    # nossa fórmula x a dela, linha a linha
    app:cobranca:espelho:encargos    # encargo gravado x nossa fórmula

Runbook da carga: `docs/runbooks/espelho-carregar-em-producao.md`.

MCP `jusprime-prod` (SELECT apenas). **Chame `descrever_esquema` antes de escrever SQL contra tabela
desconhecida, e leia as `chaves`** — a cadeia é `obrigacao -> caso -> objeto -> carteira`, e o
`carteira_id` mora no **objeto**, não no caso.

O rodapé dos relatórios dela está no espelho: `cobranca_relatorio_totalizador`, ligado a
`cobranca_relatorio_importado`. É de lá que sai o total de inadimplência dela.

## 5. Regras da casa que valem aqui

- **Worktree própria** (`scripts/frente-abrir.sh`), registrada em `docs/frentes-ativas.md`.
- **Suíte verde antes e depois** (`scripts/frente-testar.sh <frente>`), e **de novo no master depois
  do merge** — é o passo que todo mundo pula e que já salvou esta frente duas vezes.
- **Spec em `docs/specs/`** — isto é dinheiro, risco MÉDIO/ALTO.
- **`push`, `merge`, `rebase`, `deploy` são do dono.** Commit local pode. Nenhuma sessão alcança a VPS.
- **`/review` explícito.** Não confie em auto-delegação.
- Todo comando que lida com dado pessoal exige `APP_DEBUG=0` (guarda com código de saída `69`).
  **Não colar PII em conversa.**

## 6. Armadilhas que já morderam — leia antes de repetir

| armadilha | o que acontece |
|---|---|
| **`null\|date` no Twig** | imprime **a data de hoje**, sem erro. Tornar coluna de data anulável faz a tela inventar data. **Nenhum teste pega** — a suíte lê HTML e `19/08/2026` é HTML válido |
| **Worktree não herda `app/.env.local`** | `migrations:execute` de dentro dela aplica no banco `saas` (parado no tempo), não no `saas_ux` que a aplicação usa. Passe `DATABASE_URL` explicitamente |
| **O nginx do dev serve só `app/`** | worktree não é publicada; não há smoke sem integrar no master local primeiro |
| **`scripts/frente-abrir.sh` aborta e mente sucesso** | o `composer install` estoura os 128M no `cache:clear` e o `set -e` mata antes de criar uploads e clonar o banco. **Não canalize por `tail`/`head`** — o código de saída lido vira o do `tail` |
| **`migrations:status` não lista versão** | só imprime contadores. Para conferir uma versão use `migrations:list` e **leia a coluna** (`not migrated` contém `migrated`) |
| **Emissão da contábil enfileira** | pode precisar de 2–3 passadas do `emitir`. **"✅" não garante arquivo bom** — em 17/08 um veio com 20K e depois com 292K. **O tamanho é que diz** |
| **Auditoria estática não substitui teste de tela** | duas vezes nesta frente a auditoria apontou o lugar errado e só o teste que renderiza a tela pegou |
| **A prova precisa ser provada** | **três vezes** uma correção entrou declarada como "provada por reintrodução" sem estar. Apague a correção, veja vermelho, restaure, veja verde — e diga qual teste morreu |

## 7. O portão do dono

> *"Só vou começar a interface depois que O SISTEMA ESTIVER 100% BATENDO COM A CONTABILIDADE."*

Com o veredito amarrado à cobertura, **a caixa verde é inalcançável** enquanto a régua ler menos que
tudo. **Isso é de propósito** — o dia em que o verde voltar é o dia em que ele pode começar a
interface. **Não "consertar" isso.**
