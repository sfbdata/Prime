# Handoff — o sistema como espelho da contabilidade

> Estado em **2026-08-21**. Este documento é autossuficiente: quem o ler não precisa de nenhuma
> conversa anterior.
>
> ✅ **21/08: a fatia do honorário está EM PRODUÇÃO.** Falta rodar o comando das 135 (§7.1), o smoke
> e o aviso à equipe.
>
> 🔴 **O objetivo principal ainda NÃO é mensurável**: a régua confere 1 dos 3 relatórios com dinheiro
> (§3.6). Enquanto ela não medir os três, não há como afirmar que o sistema bate — e é isso que
> segura o portão do dono (§8). **É a próxima fatia que importa.**

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

### 3.1 ✅ O passivo da data do acordo — MEDIDO, e MUITO menor do que este handoff dizia

🔴 **CORREÇÃO (19/08): os R$ 203.265,07 que este handoff trazia NÃO eram o erro** — eram o tamanho
do número que a data inventada produziu. O erro dentro dele é **R$ 450,38**, e aponta **para BAIXO**,
não para cima. A frase "isto faz a cobrança SUBIR", que estava aqui, estava errada.

**Como foi medido** (régua validada: a fórmula reproduzida em SQL bate com o PHP em **3.909 de 3.909
obrigações, zero centavo**): a data verdadeira (`Data base`) está no espelho para **398 de 398**
acordos, uma só por acordo. O principal das contas originais bate com o dela em **398/398, ao
centavo**.

| | |
|---|---:|
| acordos com data diferente da dela | **372** (não 377 — 5 dos que caem no dia 1º estão **certos**) |
| obrigações afetadas · principal | 3.656 · R$ 524.987,08 |
| encargo hoje → na data dela | R$ 207.978,50 → R$ 207.528,12 |
| **efeito líquido** | **− R$ 450,38** |
| sobem 1.967 · descem 1.561 · iguais 128 | +R$ 5.529,76 · −R$ 5.980,14 |
| das 258 que cobram R$ 0,00, saem do zero | 130 |
| maior alta · maior baixa numa dívida | +R$ 59,48 · −R$ 75,12 |

**Não toca o saldo:** os 398 acordos são `ativo`/`cumprido`, e `aplicarExigibilidade` exclui do
exigível toda obrigação com acordo substituto vigente. **Não muda a calibração:** medido, nenhuma das
3.656 aparece no relatório de inadimplência dela (join validado — 4.559 obrigações casam, nenhuma
delas substituída). É número de **ficha de acordo**, não dívida cobrável.

🔑 **O critério NÃO pode ser "dia 1º do mês"** — corromperia os 5 que estão certos. É
`data_acordo <> Data base do espelho`.

**Estado:** comando `app:cobranca:reconciliar-data-acordo` escrito e provado na frente
`cobranca-reconciliar-data-acordo` (commit `6995bb99`; 3901/3901; prova por reintrodução executada).
**Revisado e NÃO aprovado** — ver §7.

### 3.2 ✅ Honorário no total — EM PRODUÇÃO 21/08 (ver §7.1)

Ela soma `principal + juros + multa + honorários` (rodapé de 17/08: 535.384,49 + 149.771,17 +
10.705,69 + **126.878,17** = **822.739,52**). O sistema deixava o honorário fora do exigível.

✅ **DECIDIDO PELO DONO (19/08): modelo A** — o honorário passa a viver DENTRO da dívida e a SPEC §18
(rateio do pagamento) é aposentada. O critério foi o do espelho, medido:

| | honorário sobre dívida em aberto | distância do rodapé dela |
|---|---:|---:|
| **ela** | R$ 126.878,17 | — |
| **modelo A** (honorário gravado por dívida) | R$ 126.362,85 | **R$ 515,32 · 0,4%** |
| **modelo B** (alíquota lisa sobre o saldo) | R$ 173.625,55 | **R$ 46.747,38 · 37% a mais** |

B erra porque ignora a carência de 30 dias e as parcelas de acordo — cobra em 1.017 dívidas em que
ela não cobra. E B **calcula** um rateio que ela **declara** (categoria `1.15` do relatório de
receitas): é o sistema formando opinião, o defeito da §1.1.

**Três medições que dissolveram perguntas do handoff antigo:**
- pontos de chamada: **15**, não 35 (os 35 contavam menções em comentário)
- dívidas quitadas que reabririam: **ZERO**, e as 1.014 com encargo e honorário R$ 0,00 se explicam
  por inteiro (601 carência · 343 override de parcela · 70 criadas-já-pagas). Sem resto
- **ordem de alocação: não existe de nenhum dos dois lados.** Ela publica rateio declarado por linha;
  `cobranca_alocacao_pagamento` guarda **um único valor** por obrigação. Nada a construir

🔑 **O rateio da §18 NUNCA rodou em produção.** Dos 8.902 pagamentos, os **1.154** em que os dois
modelos divergem alocam **todos** o valor cheio — o importador nunca passou pelo rateio. **Não há
migração de dado**; a opção A alinha o caminho manual ao que o importador já fazia.

**Estado:** frente `cobranca-honorario-no-total`, 3 commits, suíte 3867/3867.
🔴 `336b0e41` e `25658fd6` **não podem ser separados** — o primeiro sozinho faz **nenhuma dívida
quitar**. **Revisada e NÃO aprovada** — ver §7.

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

### 3.8.1 🔴 O tooltip do "Total" apontava para um número que não existe (CORRIGIDO)

Achado da 11ª revisão, e o mais instrutivo da frente porque **foi uma correção parcial que o criou**.

O tooltip da coluna "Total" mandava o operador comparar com *"o 'Total em aberto' do topo"*, dizendo que
lá os recebimentos já estavam descontados. Três erros, num texto que o operador lê sobre dinheiro:

1. esse número **saiu da tela em 28/07/2026** (`113e5584`) — o que ficou é o card `Total vencido`;
2. o card é **BRUTO**: pagamento parcial não o reduz. O tooltip afirmava o **oposto**;
3. o card só conta o que **já venceu e não foi quitado**; a coluna aparece em todas as linhas.

Provado por teste que já existia: `CabecalhoObjetoShowTest` assere o card em R$ 1.000,00 num cenário com
R$ 400,00 pagos e alocados, e que "Total em aberto" não está no cabeçalho.

🔑 **A lição:** a rodada anterior corrigiu a metade da frase que falava de honorário ("e SEM honorários"
saiu, com razão) e **por isso não olhou o resto**. Corrigir metade de uma frase falsa é como não
corrigir — o que sobra herda a credibilidade do que mudou.

### 3.9 🔴 Fatia própria: a garantia do `EditarConfiguracaoCaso` caiu com a INV-E2

Achado em 20/08, durante a varredura da 10ª rodada da frente do honorário. **Não é comentário — é
comportamento.** Nenhuma das revisões do honorário o pegou, porque as duas estavam limitadas ao diff.

`EditarConfiguracaoCasoUseCase` recalcula **só o honorário** das obrigações do caso, e o argumento de
segurança escrito ali tinha duas metades:

1. não recompor juros/multa/correção (esses descem da CARTEIRA — recompô-los reduziria o exigível de
   todas as automáticas num POST, sem guard de alocado). **Essa metade continua de pé.**
2. *"o honorário fica fora do exigível (INV-E2), logo pode subir OU descer livremente aqui"*.
   **Essa caiu**: o honorário entra no `valorExigivel()` desde `cobranca-honorario-no-total.md`.

**Consequência:** mexer no percentual de honorário de um caso passa a mover o exigível de todas as
dívidas automáticas dele, num clique, **sem guard de alocado** — a mesma bomba que a auditoria
adversarial pegou e que o desenho tinha fechado. Uma dívida com `alocado >= exigível` pode virar
"quitada" por uma edição de tela.

**Exposição medida em produção (20/08):**

| | |
|---|---:|
| casos | **483** |
| com percentual de honorário próprio | **0** |
| com base ou carência própria | **0** |
| já editados (`atualizado_em`) | **0** |

**A tela nunca foi usada.** O risco é real e está dormindo; acorda no primeiro uso.

⏳ **A decisão é do dono, e é o que a fatia precisa antes de escrever código:** se baixar o percentual
fizer uma dívida virar paga, o sistema **recusa**, **avisa** ou **deixa**?

📌 O comentário no código foi corrigido **mantendo o defeito à vista** (spec §7.1, terceiro caso da
regra): a frase falsa saiu, o defeito aberto ficou registrado na mesma linha, com estes números.

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
| **O `cd` persiste entre chamadas de shell** | mordeu em 19/08: um `cd` para a raiz fez três edições caírem no **checkout principal** em vez da worktree. O sinal que denunciou foi a falha voltar **idêntica** — a mutação não estava onde eu pensava. Use caminho ABSOLUTO ao editar worktree, e confira `git status` no master depois |
| **Inventário por `grep` de método erra a conta** | a spec do honorário mapeou "as três cópias da regra do exigível" grepando `->valorExigivel()`. Havia **cinco**: duas escrevem a soma à mão (`EditarObrigacaoUseCase`) e uma está em DQL. Cópia de regra de dinheiro se esconde de grep de método — procure também pela SOMA (`valorOriginal + juros + multa`) |
| **Trocar o número de um teste pode matar o invariante que ele guardava** | em 19/08 a asserção `assertSame(0, valorExigivel(), 'a alocação que a acompanha vale R$ 0,00')` virou `assertSame(5000, ...)` e a menção à alocação sumiu — junto com a única guarda de que exigível == alocado na criada-já-paga. **Leia o que a mensagem da asserção protege antes de mexer no número** |
| **A prova precisa ser provada** | **três vezes** uma correção entrou declarada como "provada por reintrodução" sem estar. Apague a correção, veja vermelho, restaure, veja verde — e diga qual teste morreu |
| 🔴 **Justificativa inventada é PIOR que a errada** | na 10ª rodada troquei uma justificativa falsa (que citava a INV-E2) por outra falsa (que citava a coluna-sombra, e era invenção). A original a varredura **encontrava**; a inventada ficou **invisível para o instrumento** e teria sobrevivido a todas as varreduras seguintes. **Regra: justificativa nova em comentário de dinheiro cita a medição ou o invariante que a sustenta, senão não entra.** Se você não consegue nomear o que a sustenta, o que falta é medição, não redação — registre a pendência com nome |
| 🔴 **Inventário por comentário não vê o lugar sem comentário** | as cópias da regra do exigível foram mapeadas a partir dos comentários que existiam. A de MAIOR consequência — a que decide se uma **dívida quitada REABRE** (`EditarObrigacaoUseCase`, `$exigivelSeViva`) — **não tinha comentário nenhum**, e por isso não estava no mapa, não era protegida e não era encontrável. O lugar sem pista é justamente o que ninguém acha |
| 🔴 **Âncora por número de linha manda apagar o que protege** | um script guardava 3 comentários por `arquivo:linha`. **Uma** linha inserida acima desloca tudo, e a saída passa a listá-los como "falsos restantes" **e** como "sumidos" — empurrando a próxima sessão a apagar exatamente o que ele existe para preservar. **Âncore por trecho do texto**, e prove inserindo uma linha acima para ver a âncora acompanhar |

## 7. 🔴 ONDE PARAMOS (21/08)

Duas frentes prontas, com suíte verde, **as duas revisadas e NENHUMA aprovada**. Nada foi integrado
ao master. As worktrees estão limpas e commitadas; não há trabalho solto.

| frente | estado |
|---|---|
| `cobranca-honorario-no-total` | ✅ **em produção 21/08** (`aacc4814`), 11 revisões, worktree fechada. Falta o comando das 135 + smoke — §7.1 |
| `cobranca-reconciliar-data-acordo` | ⛔ `6995bb99`, 3901/3901, **2 achados ALTO** e NÃO integrada — §7.3 |

### 7.1 ✅ Honorário no total — EM PRODUÇÃO 21/08. Falta rodar o comando das 135

**Integrada (`aacc4814`), publicada e DEPLOYADA.** Worktree e branch removidas, master com
**3.991 testes verdes**, varredura fechada. Onze revisões. Detalhe: spec §10 — **comece pela §10.8**.

#### ⏳ O QUE FALTA (é por aqui que a próxima sessão começa)

**1. O comando das 135, em produção.** O código está no ar; as dívidas gravadas continuam erradas —
**R$ 2.764,16**. A sequência é fixa e não pode ser encurtada:

```
# na VPS, SEM --aplicar. Isto só lê.
app:cobranca:reconciliar-honorario-parcela --tenant-id=<id>
```

O dono traz a saída → confere-se a lista contra a planilha da contabilidade → devolve-se a linha
`--aplicar --usuario-id=<id> --ids=...` para ele colar. **Só então algo é escrito.**
⚠️ A linha pronta traz só as de FORA do exigível (INV-H4). As de dentro saem em tabela separada e só
entram se o dono digitar o id.

**2. O smoke do dono**, nestes cinco pontos:

| # | onde | o que conferir |
|---|---|---|
| 1 | tooltip do "Total" na lista de dívidas | fala em "Total vencido" (não "Total em aberto"), diz que os dois são brutos, e que o de cima só tem as vencidas não quitadas |
| 2 | cabeçalho do objeto | o card "Total vencido" subiu — agora inclui honorário |
| 3 | botão "Receber" numa dívida parcialmente paga | o valor pré-preenchido mudou (ex.: R$ 880 → R$ 920). É o certo |
| 4 | detalhe do acordo | as parcelas somam com honorário dentro |
| 5 | `app:cobranca:espelho:encargos` | a coluna "honorário" saiu, o rodapé virou um total, e sumiu o "ATENÇÃO: o honorário NÃO entra no saldo" |

**3. O aviso à equipe de cobrança** (se ainda não foi), com as TRÊS mudanças: o total subindo
~R$ 126 mil, o botão "Receber", e o texto de ajuda corrigido. Vale pôr prazo: *"nas duas primeiras
semanas, número que parecer errado, me chamem antes de ajustar à mão"*.

#### O que a fatia entregou

O relatório de acordos da contabilidade não tem coluna de encargo e as 135 não aparecem na
inadimplência dela (0 de 135). Elas cobravam R$ 2.764,16 que ela não cobra, enquanto **1.906**
parcelas já estavam certas — eram a exceção, não a regra. O honorário passou a viver DENTRO do
exigível (modelo A, §3.2), e a régua do espelho foi alinhada a isso.

#### 🔴 A premissa que caiu na 7ª revisão — não reaprender

*"A contabilidade não cobra encargo em parcela de acordo — 0 de 8.671 linhas"* é **FALSO**. Vale para
o relatório de ACORDOS; a parcela **atrasada** migra para o de INADIMPLÊNCIA, que tem as colunas, e lá
ela cobra: **114 parcelas, 338 com honorário, R$ 6.601,57** (lote de 17/08). Olhar um relatório e
concluir sobre os dois foi o erro que reduziu esta fatia pela metade — a parte prospectiva saiu.

⛔ **A decisão do dono de 19/08 ("vai inteiro", teto de R$ 125.526,35) perdeu o objeto.** Era sobre a
parte prospectiva, que não existe mais. **Não aplicar.**

#### Números que já custaram revisão — não reaprender

- as **1.906 parcelas CERTAS não têm** a marca "Reconstruída da planilha de acordos"; usá-la como
  régua pula justo as que cobram;
- **3.473** dívidas velhas engolidas por acordo cobram honorário **de propósito** (R$ 106.682,29);
- `taxa_honorarios_bp = 0` tem **dois** significados: override de encargo **e** o sinal de alocação
  BRUTA em `ImportarReceitasUseCase`;
- **6.455** avulsas com honorário materializado (R$ 227.126,42) cairiam em `bp=0, honorarios>0`,
  estado que a régua do comando (`taxaHonorariosBp IS NULL`) nunca alcança;
- na parcela dela, **26% têm encargo embutido**: R$ 71.073,07 de honorário sobre R$ 649.655,13.

#### As lições, que valem além desta frente

- **quatro réguas automáticas caíram** porque o dado que decide (o Valor acordado declarado) não está
  no banco na hora da correção. A saída foi o comando **parar de decidir** e exigir `--ids` da lista
  aprovada (INV-H0). É a §1.1 aplicada ao próprio comando;
- **corrigir metade de uma frase falsa é como não corrigir** — o que sobra herda a credibilidade do
  que mudou. Foi assim que o tooltip ficou errado;
- **justificativa nova em comentário de dinheiro cita a medição, ou não entra** (spec §7.1.1);
- **âncora por número de linha manda apagar o que protege** — ancore por trecho de texto (§7.1.2);
- **inventário feito por comentário não vê o lugar sem comentário** — a cópia de maior consequência
  (a que decide se dívida quitada REABRE) era a única sem pista.

#### Integração: o que mordeu, para não morder de novo

A suíte da frente deu **41 erros e 9 falhas** ao trazer o master para dentro. **Nada era código**: o
banco de teste da frente é um clone feito antes de 3 migrations que chegaram pelo master. Recriar o
banco (`DROP` + `CREATE ... TEMPLATE saas_test`) zerou tudo — 3.991/3.991.

### 7.2 Os outros achados confirmados (frente do honorário)

- 🔴 **Duas cópias a mais da regra do exigível**, escritas à mão em `EditarObrigacaoUseCase:110`
  (decide se dívida liquidada REABRE) e `:141` (guard `ValorAbaixoDoAlocado`). **A spec §2 conta três
  cópias e conta ERRADO** — o inventário grepou `->valorExigivel()` e essas escrevem a soma à mão.
  **Confirmado por medição:** `EditarObrigacaoUseCaseTest` passa 22/22 com DOIS testes que se
  contradizem — `honorarioNaoEntraNoGuardDoExigivel` e `honorarioAltoEntraNoExigivel`.
- 🔴 **Saldo fantasma na obrigação criada-já-paga:** `ReceitaImportavel::recuperadoDividaCentavos()`
  = `divida + encargos`, **sem honorário**, mas `liquidar()` materializa o honorário. Exigível >
  alocado ⟹ resíduo permanente no saldo de uma obrigação marcada como paga. **Está no caminho do
  IMPORTADOR** — quebra o espelho. ⚠️ O teste que guardava o invariante foi o reescrito nesta fatia
  (`ImportarReceitasFluxoTest`, NN 8040): a asserção antiga dizia "a alocação que a acompanha vale
  R$ 0,00" e foi trocada sem notar que o invariante caiu.
- 🟠 **Quarta cópia, em DQL:** `ObrigacaoRepository:237` (`having` da régua `pagasMasNaoLiquidadas`).
  Vai encher de falso positivo o relatório usado para provar o espelho. Medido no dev: 0 hoje.
- 🟡 Asserção **tautológica** em `MontarDetalheCasoUseCaseTest:227` (compara duas avaliações da mesma
  expressão). `SecaoJaPagoTest`, em contraste, foi refundado na super-alocação e está honesto.
- ⚪ **17 comentários que agora mentem** (os piores em `ObrigacaoOutput:43-53` e
  `show.html.twig:2037`, citando métodos apagados). A §1.1 pediu que ninguém pudesse restaurar INV-E2
  lendo comentário velho — sobraram 17.

### 7.3 Os achados do comando da data

- 🔴 **A nota impressa afirma "o saldo não muda" e o código não garante:** acordo **rompido/cancelado
  volta ao exigível** e o comando não filtra nem reporta status. A §4.1 sustenta a afirmação numa
  medição de 19/08 (todos vigentes) — premissa de um instante virando fato impresso.

  ✅ **DECIDIDO PELO DONO (19/08): opção B — corrigir TODOS e CONTAR.** O comando conserta a data em
  qualquer acordo (a data errada é errada em todo lugar; é o objetivo da frente), mas:
  1. conta os candidatos **por status do acordo**, medido na hora da execução;
  2. a nota final deixa de ser texto fixo e passa a ser **derivada** do que ele achou — com zero
     não-vigentes, imprime "o saldo não muda"; com N, imprime que N estão em acordo rompido/cancelado
     e **quanto a cobrança muda neles**;
  3. o aviso sai na SIMULAÇÃO, antes de qualquer escrita — o dono vê antes de autorizar o `--aplicar`.

  🔑 **O motivo da escolha, para não ser reaberto:** a frase de hoje é o sistema AFIRMANDO algo que
  não conferiu — exatamente a §1.1 ("o sistema mostra, a gerência julga"). Filtrar por `ehVigente()`
  (opção A) deixaria data errada gravada, o oposto do objetivo. Precisa de teste do caso não vigente,
  que hoje tem cobertura zero.
- 🔴 **A §4 nunca foi reproduzida em produção.** O revisor não tem o MCP; mediu no dev (37
  candidatos, não 372). A verificação central da frente **ainda não existe**.
- Sem a trava `--esperado-*`/`LISTA_MUDOU` do molde; `ambiguos` não muda o código de saída; a
  asserção de buckets não existe; `contasFecham()` é tautológica (`pulados` é inalcançável porque o
  repositório faz `innerJoin` do caso); o teste de tenant **não prova** o filtro (passaria com o
  `andWhere` do tenant apagado); 4 comportamentos sem teste (`semDataNoSistema`,
  `semAcordoNoSistema` — que é 351 de 392 no dev —, acordo não vigente, `RastroIncompletoException`).
- 5 das 6 decisões do implementador **procedem**; só "sem filtro por status" não.

### 7.4 Registrado como dívida conhecida, com o número medido — NÃO inflar

Ambos medidos como **zero hoje**, e por isso não entram na lista de problemas abertos:

- `retido_recuperado` / `cobrado_separado` ficaram meio-caminho depois da opção A. **0 carteiras** em
  outra forma; as três são `acrescido_divida`. Risco só para carteira futura (a tela oferece as 4).
- Pagamento manual passa a gravar `valorHonorarios = 0`, mudando o conteúdo de 3 indicadores de tela.
  **0 de 8.902 pagamentos** foram lançados à mão — todos vieram do importador, que grava o honorário
  **declarado por ela**. O espelho não é afetado.

## 8. O portão do dono

> *"Só vou começar a interface depois que O SISTEMA ESTIVER 100% BATENDO COM A CONTABILIDADE."*

Com o veredito amarrado à cobertura, **a caixa verde é inalcançável** enquanto a régua ler menos que
tudo. **Isso é de propósito** — o dia em que o verde voltar é o dia em que ele pode começar a
interface. **Não "consertar" isso.**
