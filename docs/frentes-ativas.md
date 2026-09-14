# Frentes ativas

Registro das frentes de trabalho abertas em paralelo. **É o que permite duas sessões saberem uma da
outra** — sem ele não há coordenação possível, só descoberta tardia no merge.

Quem abre uma frente acrescenta a linha. Quem integra tira.

| Frente (branch) | Domínio | Migration? | Arquivos compartilhados que toca | Estágio | Base |
|---|---|---|---|---|---|
| `cobranca-acompanhamento-canonico` | Cobrança (modelo objeto/caso) | **sim — 4** | `docs/gestao-cobrancas/` | 🛑 **PARADA** (ver abaixo) | `origin/master` @ `0bb1f29` |
| `expediente-ux` | Expediente + Pasta (telas) | não | `app/templates/expediente/`, `app/templates/pasta/` | implementando, **28 commits atrás do master** | `origin/codex/colaboracao-cobrancas` |
| `cobranca-reconciliar-data-acordo` | Cobrança (comando) | não | `RelatorioLinhaRepository` (método novo), `ComandosComPiiPassamPelaGuardaTest` (1 linha) | ✅ pronta: 3901/3901, prova por reintrodução feita — **aguarda `/review` e integração** | `master` local @ `18555616` |

### ⚠️ `pasta-show-chip-responsavel` extrai 196 linhas do `_tabela.html.twig`

O chip de responsável (CSS + markup do `#pastaRespMenu` + JS delegado) morava dentro do
`_tabela.html.twig`. Saiu para três partials — `pasta/_resp_estilo`, `pasta/_resp_menu` e
`pasta/_resp_script` — para que a `pasta_show` use o **mesmo** chip da listagem em vez de um
`<select>` cru. O `_tabela` passou a incluí-los; comportamento da Expediente inalterado
(os 3 asserts de `ExpedienteFiltroPastasControllerTest` seguem verdes).

🔴 **`expediente-ux` toca o mesmo `_tabela.html.twig`** e está ~30 commits atrás. Quem revivê-la
vai encontrar 196 linhas que mudaram de arquivo — conflito garantido, e do tipo que o merge
resolve errado em silêncio (o bloco existe nos dois lados, em arquivos diferentes). Traga o
master para dentro **antes** de escrever qualquer linha lá.

🔑 **O CSS não pode voltar para dentro de um `<style>`**: o partial `_resp_estilo` é dono da
própria tag, e `<style>` aninhado é HTML inválido. Foi por isso que o include dele ficou
**depois** do `</style>` no `_tabela`, e não no lugar de onde o bloco saiu.

### ✅ 21/08 — a frente do honorário FOI INTEGRADA, e o bloqueio caiu

`cobranca-honorario-no-total` foi mergeada no master (`aacc4814`), publicada e **deployada**. A
worktree e a branch foram removidas. **O núcleo de dinheiro da Cobrança está LIVRE de novo** — a
lista de arquivos bloqueados que ficava aqui não vale mais.

O que a frente entregou e o que ela deixou aberto está em
`docs/HANDOFF_ESPELHO_CONTABILIDADE.md` §7.1, que é autossuficiente.

⚠️ **Falta rodar o comando das 135 em produção** (simula → o dono confere contra a planilha →
`--aplicar --ids=...`). O código está no ar; as dívidas gravadas ainda não foram corrigidas.

### Worktrees que são resto e podem ser fechadas (conferidas por CONTEÚDO com `git cherry`)

`cobranca-data-acordo-espelho` · `cobranca-espelho-quatro-relatorios` — ambas **100% já no master**.
`agent-a40e8d8ebf3d119ca` — worktree de subagente; seu commit `67b7e454` foi integrado por
cherry-pick em `cobranca-reconciliar-data-acordo` (`6995bb99`, mesma árvore).

### 🟡 Duas frentes de Cobrança ao mesmo tempo (19/08) — e por que isto NÃO viola a regra do domínio

A regra é "um domínio por frente, porque duas no mesmo domínio conflitam quase sempre". Aqui elas
correm juntas de propósito, com o conflito eliminado por **contrato**, não por sorte:

- **Escrita disjunta.** A do honorário mexe no núcleo (`valorExigivel()` e suas duas cópias); a do
  comando **só cria arquivos novos** (`ReconciliarDataAcordoCommand`, seu UseCase, DTO e testes) e
  tem proibição explícita de tocar `Obrigacao.php`, `Acordo.php` e os serviços de cálculo.
- **O acoplamento real foi cortado:** o comando **não pode usar `valorExigivel()` nem
  `totalComHonorarios()`** (spec `cobranca-reconciliar-data-acordo.md` §5.2). Sem isso, o honorário
  mudaria os números que o comando imprime e **uma frente falsearia a prova da outra**.
- **Integração em série**, como sempre: um commit por vez, testes direcionados depois de cada um,
  suíte completa no master ao final.

Se as duas precisarem do mesmo arquivo, a do honorário vai primeiro e a do comando rebaseia — nunca
o contrário: o núcleo do dinheiro não espera por um comando.

### ✅ 21/08 — `pasta-subpastas-aninhadas` INTEGRADA (pasta dentro de pasta, até 10 níveis)

21 commits, **3976/3976 no master depois do merge**. Migration `Version20260819175112` (aditiva em
`pasta_secao`). Smoke feito no navegador: 10 de 13 itens, 3 defeitos achados e corrigidos.

⚠️ **`expediente-ux` toca os MESMOS arquivos de tela** (`app/templates/pasta/show.html.twig`) e está
28 commits atrás. Quando for integrar, traga o master para dentro e rode a suíte **antes** do merge —
o `show.html.twig` mudou bastante aqui.

⚠️ `app/public/js/pasta-arquivos.js` é **compartilhado com a Cobrança**
(`cobranca/caso/_documentos.html.twig`): o JS degrada para um nível quando o container não declara
`data-arvore`. Provado no navegador — criar pasta na Cobrança não dá erro e não mostra "Mover para".

🔴 **Lição do roteiro de integração:** frente com migration são **TRÊS** bancos, não dois —
`saas_test<frente>`, `saas_ux` (a tela) e **`saas_test` (a suíte do master)**. Esquecer o terceiro faz
a suíte do master explodir com `column ... does not exist` e parecer código quebrado. Conserto:
`doctrine:migrations:execute --up "DoctrineMigrations\VersionXXXX" --env=test` no repositório principal.

`pasta-valor-causa` foi **integrada em 2026-08-17** (fast-forward para `3629b19a`). Trouxe a migration
`Version20260814120000` — uma coluna `valor_causa` em `pasta`, aditiva e anulável, que não toca nenhuma
tabela existente. O segundo passo que todo mundo pula foi feito: master trazido para dentro da frente
(1 arquivo em comum, `PastaController.php`, sem conflito) e **suíte rodada de novo no master depois do
merge** — 3828/3828.

🔴 **Se a sua frente tem banco de teste próprio, ele NÃO tem a coluna `valor_causa`.** Os bancos das
frentes são clones do `saas_test` feitos *antes* desta migration; ao trazer o master para dentro da sua
frente, a suíte vai explodir com centenas de `column "valor_causa" of relation "pasta" does not exist`.
**Não é quebra de código** — aconteceu exatamente isso no master em 17/08. O conserto é uma linha:

```bash
docker exec -e TEST_TOKEN=<sua-frente> jusprime_php_dev bash -c \
  'cd /var/www/.claude/worktrees/<sua-frente>/app && php bin/console doctrine:migrations:execute \
   --up "DoctrineMigrations\Version20260814120000" --env=test --no-interaction'
```

Use `migrations:execute` (uma versão), **não** `migrations:migrate`: o ledger dos bancos clonados só
tem as migrations recentes, então o `migrate` tenta rodar as antigas e morre em `relation "tenant"
already exists`.

### 🔴 Integrar frente com migration são DOIS bancos, não um — e o roteiro de 18/08 esqueceu o segundo

Mordeu de novo em **18/08**, na integração da `pasta-cliente-principal`, com o aviso do `valor_causa`
logo acima nesta mesma página. O roteiro entregue mandava aplicar a migration e rodar a suíte, mas
aplicou **só no banco da aplicação**. Resultado: dezenas de `column "pasta.cliente_principal_id"
does not exist` num master recém-mergeado, com cara de código quebrado. **Não era.**

São bancos diferentes, e a diferença não é adivinhável:

| Quem usa | Banco | Vem de |
|---|---|---|
| a aplicação no navegador | **`saas_ux`** | `app/.env.local` |
| a suíte (PHPUnit) | **`saas_test`** | `app/.env` (`saas`) + sufixo `_test` |
| a suíte de uma frente | `saas_test<nome>` | idem + `TEST_TOKEN` |

🔑 **O porquê de `.env.local` não valer para os testes:** o Symfony **ignora `.env.local` quando
`APP_ENV=test`**, por design. Então a base do nome sai do `.env` versionado (`saas`), não do
`saas_ux` que a tela usa — e `dbname_suffix: '_test%env(default::TEST_TOKEN)%'`
(`config/packages/doctrine.yaml:122`) fecha o nome. É por isso que o banco de teste é `saas_test` e
**não** `saas_ux_test`, que seria o palpite natural.

**Ao integrar uma frente com migration, rode as DUAS:**

```bash
# 1. banco da aplicação (o navegador / o smoke)
docker exec jusprime_php_dev bash -c 'cd /var/www/app && php bin/console \
  doctrine:migrations:execute --up "DoctrineMigrations\VersionXXXX" --no-interaction'

# 2. banco de teste (a suíte) — o que foi esquecido
docker exec jusprime_php_dev bash -c 'cd /var/www/app && php bin/console \
  doctrine:migrations:execute --up "DoctrineMigrations\VersionXXXX" --env=test --no-interaction'
```

Conferir depois, sem acreditar na mensagem de sucesso:

```bash
for db in saas_ux saas_test; do
  docker exec jusprime_db_dev psql -U symfony -d $db -tAc \
    "SELECT count(*) FROM information_schema.columns
     WHERE table_name='<tabela>' AND column_name='<coluna>'"
done   # tem de sair 1 e 1
```

⚠️ `expediente-ux` estava **fora deste registro** e só apareceu num `git worktree list` de 14/08 — o
mesmo defeito que a `cobranca-acompanhamento-canonico` já tinha cometido. Ela tem 2 commits e 4
arquivos alterados sem commit (`expediente/index.html.twig`, `expediente/_acervo_geral.html.twig`,
`pasta/_filtros.html.twig` e o teste do filtro). Sem migration. **Está 28 commits atrás do master** —
quem a retomar traz o master para dentro antes de escrever qualquer linha.

⚠️ **Worktree não herda `app/.env.local`.** O `app/.env` versionado aponta para o banco `saas`, onde
`cobranca_relatorio_importado` **não existe** — o dev de verdade usa `saas_ux`. Qualquer conferência
rodada de dentro de uma worktree sem sobrescrever `DATABASE_URL` falha, ou pior, mede o banco errado.
Para os testes isso não vale: `scripts/frente-testar.sh` usa o banco clonado da frente.

⚠️ A regra da casa manda **uma frente com migration por vez**. A vaga está **livre** desde a
integração da `pasta-cliente-principal` em 18/08. A `cobranca-acompanhamento-canonico` tem 4, mas
está PARADA — quem revivê-la precisa ler o bloco dela e alinhar antes de gerar versão.

`cobranca-data-acordo-espelho` foi **integrada em 2026-08-18** (fast-forward para `3605188f`).
Trouxe a migration `Version20260817180000` — `cobranca_acordo.data_acordo` passa a aceitar **nulo**,
porque se a contabilidade tem acordo sem data o espelho grava sem data. Matou o `dataAcordoPadrao()`
dos dois importadores que derivavam a data do 1º dia da competência (violação #3: **375 de 395
acordos com data chutada**, R$ 203.265,07 de encargo calculado sobre ela, 256 dívidas zeradas por
data impossível), o `now()` do construtor de `Acordo` (#7) e a ausência de reescrita no ramo de
atualização (#6).

**Quatro revisões adversariais**, cada uma achando o que a anterior não podia ver: tela exibindo
resíduo, correção declarada como provada sem estar (duas vezes), catch inalcançável, badge
prometendo o que não cumpre, e uma **prévia que podia gravar** (`prever()` sem transação). Suíte
**3853/3853** na frente com o master dentro **e** de novo no master depois do merge. Smoke do dono
feito e aprovado em 18/08.

⚠️ **`scripts/frente-abrir.sh` aborta no meio e mente o sucesso.** O `composer install` sai com erro
(o `cache:clear` do post-install estoura os 128M) e o `set -e` mata o script **antes** de criar os
dirs de upload e de clonar o banco. **Não canalize a saída do script por `tail`/`head`** — o código
de saída lido vira o do `tail`. Confira `saas_test<nome>` no fim. Conserto do script: fatia própria.

🔴 **A worktree NÃO herda `app/.env.local`.** Rodar `doctrine:migrations:execute` de dentro dela
aplica no banco `saas` (parado no tempo), não no `saas_ux` que a aplicação usa. Aconteceu em 18/08,
com o aviso já escrito neste arquivo. Passe `DATABASE_URL` explicitamente.

🔴 **O nginx do dev serve só `app/` — a worktree não é publicada.** Não dá para fazer smoke do código
de uma frente sem antes integrá-la no master local. Preparar banco novo com código velho quebra a
tela (`Typed property $dataAcordo must not be accessed before initialization`).

`deploy-resiliente` foi **integrada e publicada em 2026-08-18** (fast-forward para `1a8271dc`).
Sem migration. O segundo passo que todo mundo pula foi feito: master trazido para dentro da frente
(1 arquivo em comum, `docs/frentes-ativas.md`, conflito mecânico) e **suíte rodada de novo no master
depois do merge** — 3853/3853 nos dois pontos.

🔴 **A causa de todo deploy rebaixar as 122 dependências era o próprio deploy.** O
`docker builder prune -f` do fim do `deploy-prod-tls.sh` zerava 100% do cache de build — medido,
1,719 GB → 0 B, e ter a imagem no store não protege. Agora a poda tem teto. Medição de dois deploys
consecutivos sem mudança de código: **209,9 s → 1,26 s**, 122 → **0** pacotes baixados. E o build
deixou de depender do GitHub: `composer install` completa com `--network=none`.

🔑 **O conserto que impede o site de cair é a ORDEM:** o build passa a vir **antes** do modo
manutenção. Build que falha não derruba mais nada — foi isso que custou ~40 min em 17/08. A
credencial também passou a ser validada por **forma** (o `grep "github-oauth"` antigo deixava passar
o texto de exemplo do modelo, que foi o que derrubou a produção). Prova:
`bash scripts/testar-deploy-guardas.sh` — 42 asserções, cobre os **dois** scripts.

✅ **As duas medições que faltavam foram feitas NA VPS em 18/08, e as duas deram a favor:**
`docker builder prune --help | grep -c max-used-space` → **1** (a poda usa o teto de verdade, não o
fallback por idade) e `command -v python3` → **`/usr/bin/python3`** (a validação da credencial roda
completa, com parser JSON, em vez de avisar e seguir).

🔴 **Medido na VPS em 18/08: o `.composer-auth.json` de lá é `{}` — NUNCA houve token em produção.**
O commit `37399179` montou o encanamento inteiro (secret do BuildKit, compose, Dockerfile) e a
credencial nunca foi instalada. Todos os builds desde então, inclusive o que funcionou em 18/08,
rodaram **anônimos** — ou seja, o teto de 5.000/hora que aquele commit foi buscar nunca valeu. O
deploy de 18/08 passou porque o GitHub se recuperou, não porque o token foi corrigido.

Isso explica a sequência de 17/08 sem mistério: placeholder → `401` → o arquivo foi esvaziado para
`{}` → anônimo → funcionou quando o GitHub voltou. **É por isso que a validação nova trata ausência
como AVISO e não como erro** (anônimo funciona), mas o aviso agora aparece.

🎉 **DEPLOYADO EM PRODUÇÃO em 18/08 — e o conserto se provou lá, não só na bancada.** O primeiro
deploy foi o cold esperado (~4 min, 122 pacotes, agora **autenticados**), e a linha que decide tudo
veio no fim:

```
cache de build agora: 2.475GB (teto pedido: 4 GB)
```

**Com o script antigo isso seria `0B`** — era o `prune` cego zerando tudo ali que fazia todo deploy
voltar a baixar as 122 dependências. A poda com teto rodou e **não removeu nada** (`Total: 0B`),
porque 2,475 GB está abaixo dos 4 GB. Disco depois: **67 G / 96 G = 70%** (era 68,6%); o 1,4 ponto a
mais é o cache, e agora ele tem teto — no pior caso ~71,5% e para de subir. Migration
`Version20260817180000` aplicada, site respondendo **302**.

🔑 **O token do composer foi instalado na VPS pela primeira vez** (fine-grained, só leitura de
repositório público) e o GitHub aceitou (HTTP 200). Este foi o primeiro build autenticado da
história do projeto.

✅ **O ganho foi confirmado em produção logo depois**, com um `compose build` isolado (não toca
container nenhum, o site nem percebe):

```
 => CACHED [worker prod_builder 3/7] RUN --mount=type=secret,id=composer_auth --mount=type=cache,...
real    0m2.096s
```

**~4 min → 2,1 s, com o passo do composer `CACHED` e zero pacote baixado.** É o mesmo formato da
medição de bancada (209,9 s → 1,26 s), agora na máquina de verdade. O ciclo está provado ponta a
ponta: o cache sobrevive à poda, e o deploy seguinte o aproveita.

### ✅ `pasta-cliente-principal` — INTEGRADA em 2026-08-18 (fast-forward para `b6d25e5b`)

Dois commits: a fatia (`4919eb45`) e as correções da revisão (`b6d25e5b`). A frente estava **0 atrás**
do master, então não houve o que trazer para dentro — a suíte da frente já provava `master + frente`.
**Suíte rodada de novo no master depois do merge: 3888/3888**, 14.578 asserções, o mesmo número da
frente. Conferido por `git cherry master pasta-cliente-principal` (vazio) — a worktree pode ser
fechada. ⏳ **Falta o smoke do dono e a publicação** (`push`); nada foi enviado ao remoto.

🎉 **EM PRODUÇÃO em 18/08** — publicado (`cd2756f4..0b66dd96`, 6 commits) e deployado pelo
`deploy-prod-tls.sh`. Backup do banco feito ANTES (15 MB, `~/backup-prime-antes-cliente-principal-*.dump`),
as 2 migrations aplicadas na janela de manutenção, nginx OK, site no ar.

✅ **A promessa da migration foi conferida no dado REAL, não só na bancada.** Medido na prod pelo
canal somente-leitura, depois do deploy: 2 migrations do dia aplicadas · 1 pasta com cliente · 1 com
principal · **0 violando o invariante**. E a linha que fecha: o backfill gravou o **cliente 4 na
pasta 1025**, que é exatamente `MIN(cliente_id)` — ou seja, **o mesmo cliente que a tela já
mostrava**. Nenhum número mudou de valor com o deploy, que era a promessa.

🔑 **O conserto do cache de build se provou de novo:** poda rodou e removeu `0B`, cache preservado em
**2,963 GB** (teto 4 GB), disco em 71%. Com o script antigo isso seria `0B` e o próximo deploy voltaria
a baixar as 122 dependências.

✅ **Smoke do dono feito e APROVADO** (antes do deploy). Com isso a frente fecha ponta a ponta:
implementada, revisada (12 achados), critério trocado pelo dono, integrada, deployada, provada no
dado real e conferida na tela. **Nada pendente.**

### 🔄 O critério automático foi TROCADO depois da entrega (mesmo dia, 18/08)

O dono não gostou do automático e reescreveu a regra em uma frase: **"ou é o primeiro vínculo, ou
outro marcado manualmente; não existe pasta com cliente e sem principal."** Entrou em `8d950a57`,
integrado por merge (`58329654`) — **suíte 3881/3881 no master depois do merge**, e o invariante
conferido no banco: 1 pasta com cliente, 1 com principal, **0 violações**.

🔑 **A ideia dele é melhor que a que estava lá, e vale entender por quê.** O defeito de origem não
era *qual* critério o sistema usava — era o fato de a resposta ser **recalculada a cada leitura**.
Enquanto houver recálculo, sempre existe um jeito de vincular alguém e o número mudar sozinho.
Gravando uma vez, some a categoria inteira do problema. A mutação que prova isso: tirar a guarda
`=== null` do `addCliente()` faz cada vínculo novo roubar o principal — o mesmo defeito por outra
porta — e derruba 6 testes.

Saíram: o critério "cliente de cadastro mais antigo" como resposta, a rota
`pasta_cliente_principal_limpar`, o `LimparClientePrincipalUseCase`, dois métodos da entidade e um
arquivo de teste inteiro. **A estrela voltou a dois estados.** Saldo do commit: **−360 linhas**.

⚠️ **`clienteMaisAntigo()` continua no código, mas NÃO é mais critério** — é o desempate usado ao
promover (quando o principal é desvinculado) e o fallback para um caso só: o cliente marcado ser
**excluído do sistema**, quando a FK `ON DELETE SET NULL` zera a coluna sem passar por método nenhum
da entidade. Sem ele a média sumiria da tela nesse caso.

🔴 **A ordem de vínculo do passado NÃO é recuperável, e por isso o backfill não a usa.**
`pasta_cliente` tem só `pasta_id` e `cliente_id` — nenhuma data, nenhuma sequência. A
`Version20260818190000` grava `MIN(cliente_id)`, que é **exatamente quem a tela já mostrava**, então
nenhum número muda. Inventar uma ordem para decidir de quem é a média seria dado chutado movendo
número na tela — o mesmo defeito da data dos acordos. "Primeiro vinculado" vale dos vínculos
**novos** em diante, onde é fato.

📊 **Medido na PROD antes de decidir (18/08):** 1.070 pastas, **1** vínculo pasta↔cliente no total,
**0** pastas com 2+ clientes e **0** pastas com `valor_causa` preenchido. A aba Financeiro ainda não
tem uso real — foi o que tornou a troca de critério gratuita agora, e o que a tornaria cara depois.

🔴 **A integração custou uma suíte vermelha que NÃO era código** — a migration foi aplicada só no
`saas_ux` e não no `saas_test`. Detalhe completo no bloco "Integrar frente com migration são DOIS
bancos" acima; é o mesmo tropeço do `valor_causa`, repetido com o aviso já escrito nesta página.


A "Média por CPF" da aba Financeiro passa a seguir uma marcação explícita do dono, em vez de
escolher sozinha o cliente de cadastro mais antigo — critério que fazia **vincular um cliente mais
antigo trocar o número na tela**, sem ninguém ter pedido.

| | |
|---|---|
| **Migration** | `Version20260818150000` — `pasta.cliente_principal_id`, **aditiva e anulável**, FK `ON DELETE SET NULL`. **Sem backfill** (ver abaixo) |
| **Spec** | [specs/pasta-cliente-principal.md](specs/pasta-cliente-principal.md) |
| **Suíte** | **3874/3874** no banco da frente (+21 testes) |
| **Banco** | `saas_testpasta-cliente-principal` — clonado hoje, já tem `valor_causa` e `data_acordo` anulável |

🔑 **Nenhum número muda no dia do deploy.** A coluna nasce nula em 100% das pastas e
`getClientePrincipal()` cai no critério antigo enquanto ninguém marcar nada. Por isso a migration
dispensa backfill: a tela só muda quando alguém escolher de propósito.

🔑 **O padrão do `PastaProcesso` foi avaliado e recusado, por decisão do dono.** Promover
`pasta_cliente` a entidade de vínculo trocaria a **PK de uma tabela populada** e arrastaria 4
templates, 4 joins DQL, o formulário e 4 arquivos de teste. A coluna entrega o mesmo e ainda dá a
unicidade **no banco** — o precedente a mantém só em memória, sem constraint nenhuma. A comparação
está na spec.

⚠️ **`PastaController::syncClientes()` é código morto** (nunca chamado). Uma revisão o apontou como
"apaga a marcação a cada edição de pasta" — apagaria **se fosse chamado**. Quem o ligar um dia tem
de torná-lo diferencial antes.

⚠️ **`scripts/frente-abrir.sh` abortou de novo** no `cache:clear` (OOM 128M), pela terceira frente
seguida. Os dois passos que faltam (dirs de upload e clone do banco) foram refeitos à mão.

#### Revisão adversarial de 18/08 — 12 achados, 3 que mudavam a entrega

A revisão **não aprovou** a primeira versão. O que ela pegou, e o que virou correção:

🔴 **A justificativa central da guarda era FALSA, e estava em quatro lugares** (spec, docblock da
entidade, comentário de teste e mensagem do commit): dizia que `PastaType` faz o Symfony Form mexer
na coleção de clientes e deixar a coluna órfã. Medido: `PastaType` aparece **uma única vez** em todo
o `app/` — a própria declaração. É código morto, como `syncClientes()`. **Consequência real: zero** —
nenhuma pasta tem coluna órfã e nenhum número está errado. A guarda ficou (é barata e correta); o
texto foi reescrito. *O autor rodou esse grep para `syncClientes()` e afirmou a alegação irmã sem
rodar: prova por simetria não é prova.*

🔴 **`limparClientePrincipal()` estava na spec como entregue e não tinha porta nenhuma** — sem rota,
sem botão, sem teste. Marcar era **via de mão única**. O dono mandou implementar: rota
`pasta_cliente_principal_limpar`, `LimparClientePrincipalUseCase`, e a estrela virou **três estados
clicáveis** (desmarcar / fixar / marcar). *Nota: o precedente dos processos **não** tem desmarcar —
cliente e processo ficam diferentes de propósito.*

🔴 **Cliente vinculado por AJAX nascia sem estrela e invisível para o JS** — o container montado em
JS não casava com o seletor de layout que a troca de estrela procurava. Efeito: não dava para marcar
o cliente recém-vinculado até um F5. Consertado com gancho próprio (`.js-cliente-acoes`) e uma única
função de montagem para os dois caminhos. A spec chamava isso de "dívida opcional" e subestimava.

🟡 **A contagem de prova do commit não era reproduzível.** Ele afirmava "7 testes vermelhos" ao
ignorar a marcação; a revisão contou 8 por inspeção e não fechou. **Medido agora: 9** — e a spec
passou a registrar *qual* mutação e *contra qual* conjunto de testes, que era o que faltava para
alguém refazer a conta. Também foram medidos os outros três defeitos (2, 1 e 4).

🟡 **A asserção "forte" do SQL ainda tinha brecha**: `fetchOne` devolve `false` para linha ausente e
o helper achatava isso em `null`, o mesmo valor de "coluna limpa" — a asserção decisiva passaria se
a **pasta** sumisse do banco. Fechada.

Corrigidos também: `fetch` sem `catch` (queda de rede reabilitava o botão **em silêncio**, e o
usuário achava que tinha marcado); asserção cross-tenant de **cliente** que faltava; e o
`PeticionarController`, que mudou de comportamento sem um teste sequer.

Registrados **sem corrigir**, por serem decisão do dono ou fora do escopo: as listagens
(`_tabela`, `_card`, `demandas/_resultado`) seguem mostrando `pasta.clientes[0]` em vez do
principal; e os UseCases não validam tenant (o precedente `DefinirProcessoPrincipalUseCase` também
não — a proteção mora no resolver + `TenantFilter`, que só liga por request, então via CLI não há
guarda).

🔴 **O dev NÃO tem a coluna** — medido: `cliente_principal_id` não existe em `saas_ux`. A tela da
pasta **não abre** até a migration rodar lá, então o smoke depende disso. Mesmo tropeço da frente
`pasta-valor-causa`. O roteiro do smoke (6 pontos) está no fim da spec.

⚠️ **Nenhum passo de backup antes do schema change foi escrito.** A migration é aditiva e anulável
e `pasta` tem ~1k linhas (`ADD COLUMN NULL` é instantâneo no PG), mas o passo faltava no registro —
fica anotado aqui para o deploy.

### 🛑 `cobranca-acompanhamento-canonico` — parada, NÃO apagar

Registrada de volta em **2026-08-13** por decisão do dono. Ela estava fora deste arquivo desde que a
sessão que a abriu terminou, e virou o que este registro existe para impedir: **um piloto invisível no
mesmo domínio** — 23 commits de trabalho real que nenhuma outra sessão de cobrança enxergava.

| | |
|---|---|
| **Worktree** | `.claude/worktrees/cobranca-acompanhamento-canonico` |
| **HEAD** | `8ac4d1b6` · árvore limpa · **nada publicado** |
| **Posição** | 23 commits à frente da base, **286 atrás** de `origin/master` de hoje |
| **Suíte** | 2550/2550 na época (`scripts/frente-testar.sh`, banco `saas_testcobranca-acompanhamento-canonico`) |
| **Entregou** | fatias **A0, A1, A1b, A2, A3** + **etapa 1 de 3 da A4** — 5 de 26 |
| **Falta** | A4 etapas 2–3 (partials Twig, CSRF por id, upload — o ponto mais arriscado), A5, B1, B2, C1, C2 até o portão E0 |
| **Retomada** | [gestao-cobrancas/RETOMADA_CANONICA.md](gestao-cobrancas/RETOMADA_CANONICA.md) — leia a entrada "A4 etapa 1/3" do diário antes de escrever uma linha; ela contradiz o PLAN em pontos onde a execução provou o PLAN errado |

⚠️ **Ela tem 4 migrations** (`Version20260725120000` … `20260725180000`). Medido em 13/08: elas estão
aplicadas em **`saas`**, e o banco que o dev de fato usa é **`saas_ux`** (`app/.env.local`), onde há
**zero**. Frente com migration vai uma de cada vez — enquanto esta estiver parada mas viva, qualquer
outra frente de cobrança com migration precisa saber disso antes de gerar a sua.

📌 *Uma versão anterior desta linha dizia "aplicadas no banco de dev", sem dizer qual — e estava
errada. Este arquivo é o que informa outras sessões se elas podem criar migration: informação errada
aqui faz outra pessoa decidir errado sem nunca saber por quê.*

⚠️ **Não apagar a branch** (decisão do dono, 13/08): são 23 commits de trabalho real. Ele decide
depois se revive ou descarta. Até lá ela fica aqui, parada e visível.

`cobranca-espelho-quatro-relatorios` foi **integrada em 2026-08-14** (fast-forward para `0194be63`).
Ela trouxe a migration `Version20260813210000` (5 colunas em `cobranca_relatorio_linha`, 1 em
`cobranca_relatorio_totalizador`, e o `tipo` entrando no índice único `uniq_cobranca_relatorio_arquivo`
— nenhuma toca tabela de dívida). O segundo passo que todo mundo pula foi feito: master trazido para
dentro da frente (limpo, zero conflitos) e **suíte rodada de novo no master depois do merge** —
3771/3771, 14.239 asserções. A carga em produção segue
[runbooks/espelho-carregar-em-producao.md](runbooks/espelho-carregar-em-producao.md).

`sync-sistema-manda` foi **integrada, publicada e deployada** antes disso (`d55ebf16`). ✅ **Fechada
ponta a ponta em 2026-08-18:** cron religado (`0 * * * *`, wrapper da VPS já com `--modo=enviar`),
worker `jusprime_worker_prod` de pé consumindo `async` sem restart-loop, e **smoke da tela feito e
aprovado**. O R2 está provado em produção pelo próprio log: 95 rodadas marcadas `[modo: enviar]` e a
última com `Pastas criadas no sistema: 0` / `Arquivos baixados do Drive: 0`.

⚠️ **Não leia `Divergências de nome: 0` desse log como "o Drive está alinhado".** Sob `--modo=enviar`
o contador vive dentro de `driveParaSistema()`, que não roda — o zero é ausência de medição, não
ausência de divergência (mesma armadilha do §12.4 da spec). Medir exigiria `--modo=ambos --dry-run`;
pelo D12.7 o acervo legado não se alinha mesmo, então provavelmente nunca será preciso.

🔴 **MAS O SYNC ESTÁ PARADO EM PRODUÇÃO — incidente ABERTO, não confunda com o estado da frente.**
A fatia de código está entregue e correta; a **conexão** com o Google Drive é que quebrou. Última
sincronização bem-sucedida: **21/08/2026 09:34:43**. Medido em 02/09: **44 pastas fora do Drive**
(eram 27 em 01/09 — está crescendo), contra 1.072 que estão lá. Erro na fila `failed`:
`Google\Service\Exception 403 — "Method doesn't allow unregistered callers"`, ou seja, a chamada
chega ao Google **sem credencial nenhuma**.

Duas causas possíveis, não distinguíveis sem o servidor: (1) `GOOGLE_DRIVE_OAUTH_CLIENT_ID/SECRET`
sumiram do `.env.prod` — o `%env(string:default::...)%` do `services.yaml` entrega **string vazia em
silêncio**, transformando "config faltando" em "erro do Google"; (2) o refresh token foi revogado.
Comando que separa as duas (não mostra segredo, só o tamanho):

```bash
docker exec jusprime_worker_prod printenv | awk -F= '/^GOOGLE_DRIVE_OAUTH_CLIENT_(ID|SECRET)=/ {print $1 " tem " length($2) " caracteres"}'
```

Nada impresso ou 0 → causa 1. Ambos com tamanho → causa 2 (reconectar o Drive pela tela). ⏳ O dono
decidiu em 01/09 **deixar para depois** — nada disso bloqueia o sistema, só o Drive.

`cobranca-dupla-contagem` foi **integrada, publicada e deployada em 2026-08-13** (`99948524`), e a
reconciliação **rodou em produção**: 25 dívidas corrigidas, R$ 1.429,55 fora do saldo do devedor, e a
régua confirma **zero** dívidas com assinatura de dupla contagem nas três carteiras. Conferido por
`git cherry master cobranca-dupla-contagem` (vazio) antes de tirar daqui — a worktree pode ser fechada.

`cobranca-importar-cadastro-acordos` foi **removida deste registro em 2026-08-13**: conferido por
`git merge-base --is-ancestor`, ela já está inteiramente em `origin/master`. A linha tinha ficado para
trás — *quem integra tira*, e não tirou. Registro desatualizado é pior que registro nenhum: ele fez
esta sessão considerar um conflito de domínio que não existia mais.

`ponto-horas-pagas` foi **integrada e publicada** em 2026-07-31 (merge `8b6ce5fd`), com migration
própria (`ponto_lancamento_horas_pagas`) — por isso a frente de cobrança, que também tem migration,
esperou a vez, como manda a regra abaixo.

`feature/cobranca-ux-rapida` foi **integrada e publicada** em 2026-07-26 (merge `bbc1724`, sem
migration), com a suíte em 2612/2612 no master **depois** do merge. O que ela entregou e a decisão de
rótulo que ficou em aberto: [gestao-cobrancas/HANDOFF_UX_RAPIDA.md](gestao-cobrancas/HANDOFF_UX_RAPIDA.md).

**Estágios:** `implementando` · `em revisão` · `pronta para integrar` · `travada` (diga em quê).

## Como preencher

- **Domínio** — um por frente. Duas frentes no mesmo domínio conflitam quase sempre.
- **Migration?** — sim/não. **Frentes com migration vão uma de cada vez.** Não por impossibilidade:
  o custo de fazer certo (renomear a versão, recriar o banco do zero, ler as duas migrations, rodar
  de novo) supera o de esperar a outra entrar. Frentes de tela, relatório ou lógica paralelizam à
  vontade.
- **Arquivos compartilhados** — neste projeto os que doem: `app/templates/base.html.twig`, CSS
  global, rotas, enums de `app/src/Shared/`, `docs/`. Quem toca um desses vai sozinho ou por último.
- **Base** — `origin/master` no caso normal. Outra branch só no empilhamento declarado (ver abaixo).

## Empilhar uma frente sobre outra

O padrão é cortar de `origin/master`. Empilhar é legítimo em um caso: **a frente A é pré-requisito
da B e A está travada num portão humano** (uma decisão de produto, uma ratificação). Aí B espera
ociosa ou empilha — e empilhar costuma ser a escolha certa.

Quando empilhar, anote a base na tabela e assuma o que vem junto: **o deploy será A+B**, e quem
segura o portão de A segura a pilha inteira.

O que não pode é empilhar por inércia — herdar a história de outra frente sem ninguém ter decidido
isso. Era o que acontecia quando `worktree.baseRef` era `head`; hoje é `fresh` (parte de
`origin/master`), então o empilhamento virou ato explícito.

## Comandos

```bash
scripts/frente-abrir.sh <nome>            # worktree + vendor + uploads + banco de teste isolado
scripts/frente-abrir.sh <nome> <base>     # empilhamento declarado
scripts/frente-testar.sh <nome>           # suíte DA frente, no banco DA frente
scripts/frente-fechar.sh <nome>           # ritual de migration + suíte; para antes do merge
```

Contexto e medições que sustentam este fluxo: [worktrees-frentes-paralelas.md](worktrees-frentes-paralelas.md).
