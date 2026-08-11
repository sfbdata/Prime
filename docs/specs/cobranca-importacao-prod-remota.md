# Importação remota do lote da contábil em produção

**Risco: ALTO.** Grava dinheiro no banco de produção (obrigações, pagamentos, acordos) das 3 carteiras
do tenant 1. Escrito em 2026-08-11.

## 1. O problema

Hoje o ciclo "baixar o lote da contábil → importar em produção" é feito à mão: 15 arquivos emitidos pelo
`scripts/emitir-relatorios-contabil.sh`, copiados para a VPS, e 15 comandos digitados numa ordem que só
existe na memória de quem já fez. Rodou assim em 08/08 e em 10/08.

Automatizar isso pelo Claude Code esbarra numa escolha de acesso, e é essa escolha que esta spec resolve.

## 2. A escolha de acesso — e por que não é "liberar o SSH"

Três caminhos foram avaliados:

| Caminho | Contenção | Custo |
|---|---|---|
| Liberar `Bash(ssh bluejus:*)` | **nenhuma** — é root irrestrito na VPS | zero |
| Construir um segundo MCP, de escrita | boa | semanas; e continua rodando por cima do SSH |
| **Chave dedicada com `command=` forçado** | **boa** | uma tarde |

O MCP não elimina o SSH: o `jusprime-prod` que já existe é lançado por
`ssh bluejus 'docker exec -i … php bin/console mcp:server'`. O que o MCP entrega é *o modelo não digitar
o comando* — e isso a chave restrita entrega igual, por muito menos.

**Decisão: chave dedicada + `command=` forçado.**

### 2.1 Onde mora a tranca — e por que não pode morar aqui

A restrição fica no `authorized_keys` da VPS, apontando para `/usr/local/bin/bluejus-importar`, que é
propriedade do root e não é gravável pelo agente.

Um script **no repositório** não serviria como tranca: o Claude Code tem permissão de escrita nos
arquivos do projeto e poderia reescrever o próprio script antes de executá-lo. O
`scripts/importar-lote-prod.sh` é **conveniência de operação, não fronteira de segurança** — toda a
contenção real está do outro lado do cano.

### 2.2 A chave é aditiva

Medido em 11/08: o `/root/.ssh/authorized_keys` da VPS tem **uma única chave**, a pessoal do dono
(`sfb.samuell@gmail.com`), que é também a que o MCP de leitura usa. Pôr `command=` nela quebraria o
acesso do dono e o MCP. A chave da importação entra como **segunda linha**, sem tocar na primeira.

## 3. Contrato do wrapper (`/usr/local/bin/bluejus-importar`)

Recebe o pedido em `$SSH_ORIGINAL_COMMAND`. **Aceita exatamente cinco formas**; qualquer outra coisa é
recusada com código 64, sem executar nada:

| Forma | O que faz |
|---|---|
| `estado` | sha256 do próprio wrapper, emissão das 3 carteiras, lotes recebidos e o fim do registro |
| `receber-lote <AAAA-MM-DD>` | lê um `tar.gz` da stdin, valida e grava o lote |
| `simular <AAAA-MM-DD> <carteira>` | dry-run dos 5 passos da carteira — **não persiste** |
| `importar <AAAA-MM-DD> <carteira> --confirmar` | persiste os 5 passos da carteira |
| `ajuda` | imprime as formas aceitas |

**Pedido com mais de uma linha é recusado.** O parse por lista leria só a primeira e descartaria o
resto em silêncio: o pedido registrado no log não seria o pedido feito, e a quebra de linha permitiria
forjar uma entrada no registro.

As três formas que mexem em estado tomam um `flock` — duas operações simultâneas não se atropelam.

> ⚠️ **O que o `flock` NÃO cobre:** a trava é do wrapper. Se a sessão SSH cair, o wrapper morre, a
> trava é liberada, e o `bin/console` **continua rodando dentro do container** (medido em rodadas
> anteriores desta frente). Um segundo pedido seria aceito e correria em paralelo com o órfão. Contra
> esse caso a defesa é operacional, não mecânica — o runbook manda conferir `/proc` antes de repetir.

**Carteira** é sempre um dos três nomes canônicos: `top_life_1`, `top_life_2`, `amli_br_060`.

> **Por que nome e não número.** Existem dois esquemas de numeração que se cruzam: no sistema da
> contábil os condomínios são `1`/`4`/`3`; nas carteiras daqui são `1`/`2`/`3`. TOP LIFE 2 é **4 lá e 2
> aqui**. Aceitar número na interface do wrapper é convidar a importação na carteira errada. A tradução
> nome → `carteira-id` acontece uma vez só, dentro do wrapper.

**Data do lote** só casa com `^\d{4}-\d{2}-\d{2}$`. Não há concatenação de argumento em comando de
shell, não há `eval`, e o parse é por lista (`read -ra`), não por expansão.

### 3.1 Validação do lote recebido

A stdin é bufferizada em disco **antes** de qualquer extração, com teto de 64 MB. Nada é extraído antes
de o índice passar em três conferências, e a falha de qualquer uma recusa o lote inteiro:

1. **Tipo** — todo membro tem de ser arquivo regular. Um *symlink* chamado
   `top_life_1_Dados_cadastrais.xlsx` apontando para `/etc/shadow` passaria por uma conferência que
   olhasse só o nome, e o `-f` pós-extração seguiria o link. Por isso o índice é lido com `tar -tvzf`,
   não `-tzf`.
2. **Tamanho descomprimido** — soma dos membros com teto de 512 MB, mais uma guarda de espaço livre.
   Sem isso, 15 membros com nome perfeitamente canônico podem descomprimir para o que quiserem e
   encher o disco da máquina que roda o Postgres de produção.
3. **Nome** — conferência exata contra a lista das 15 formas canônicas, o que exclui `..`, caminho
   absoluto e qualquer arquivo a mais.

O empacotamento é por nomes explícitos (o `scripts/importar-lote-prod.sh` monta assim). `tar -cz .`
inclui a entrada de diretório e é recusado pela conferência de tipo — a mensagem de recusa diz isso.

### 3.1.1 A carteira é conferida contra o banco antes de gravar

O mapa `nome → carteira-id` é **dependente de ambiente**: no banco `saas` do dev o id 1 é TOP LIFE II,
não TOP LIFE I. Se o wrapper um dia apontar para outro banco, importaria na carteira errada sem
reclamar — e o `ValidadorRodapeFiltros` confere o **recorte**, não o condomínio.

Por isso, antes do passo 1, o wrapper faz `SELECT nome FROM cobranca_carteira WHERE id = <id>` e exige
que o banco concorde com o nome pedido (`TOP LIFE I`, `TOP LIFE II`, `AMLI BR 060`). Divergiu, recusa.

### 3.2 Ordem dos 5 passos, por carteira

1. `app:cobranca:importar-cadastro` — `<pre>_Dados_cadastrais.xlsx`
2. `app:cobranca:importar` — `<pre>_Inadimplencias_detalhadas.xlsx`
3. `app:cobranca:importar-receitas` — `<pre>_Receitas_detalhadas.xlsx`
4. `app:cobranca:importar-acordos` — `<pre>_Acordos_detalhados_EM_ANDAMENTO.xlsx`
5. `app:cobranca:importar-acordos` — `<pre>_Acordos_detalhados_LIQUIDADO.xlsx`

**No modo `--confirmar`, a falha de um passo interrompe a carteira** — não segue para o próximo.

`app:cobranca:registrar-emissao` **não entra no fluxo**: os quatro importadores gravam a emissão
sozinhos quando persistem (verificado no código, não suposto). O comando existe só para backfill.

### 3.3 Invariantes de execução

- `-w /var/www/app` no `docker exec` — o container de prod não tem esse cwd por padrão.
- `-d memory_limit=1G`. O docblock do comando pede 512M; o replay em dev usou 3G para a carga grande.
  1G é o meio-termo escolhido, num único ponto do wrapper. Se a TL1 crescer e estourar, é aqui que muda.
- `-e APP_DEBUG=0`. Em prod o debug já vem desligado, mas em modo debug o `BacktraceDebugDataHolder`
  do Doctrine acumula backtrace por query e estoura no meio do lote — a defesa é barata.
- Saída filtrada por `grep -v entity_audit`, senão o log de auditoria engole o resumo. **O código de
  saída é o do comando, não o do grep.**
- Toda invocação é registrada em `/var/log/bluejus-importar.log` com o `$SSH_ORIGINAL_COMMAND` cru,
  **inclusive as recusadas** — e o registro é do **desfecho**, não só do pedido: início, código de
  saída de cada passo, e o fim (`5/5 OK`, `INTERROMPIDA no passo N`, `N passo(s) com falha`).
- A saída completa de cada passo fica em
  `/var/log/bluejus-importar.d/<data>_<carteira>_<simulacao|valendo>_<n>.log`. **O modo faz parte do
  nome**: sem ele uma simulação sobrescreveria o registro do `importar` valendo do mesmo dia — o
  arquivo que o runbook manda consultar justamente depois de uma queda de conexão.
  Isto existe por um motivo concreto: se a sessão SSH cair no meio, o processo dentro do container
  **sobrevive** (já aconteceu), mas a saída dele ia embora junto com o terminal de quem chamou — e
  não sobrava nada na VPS que respondesse "o lote entrou?".

> ⚠️ **Armadilha registrada, custou um teste para achar:** `exec 9>"$TRAVA" 2>/dev/null` — num `exec`
> **sem comando** o redirecionamento é permanente para o script inteiro. Isso silenciava a stderr de
> tudo, e toda recusa saía muda: código 64 e nenhuma explicação para quem chamou.

## 4. O que esta spec NÃO cobre

- **Deploy.** Medido em 11/08: os 5 comandos já existem no container de prod, e o `master` local está
  idêntico ao `origin/master`. A importação não depende de código novo. Se um dia depender, o deploy vem
  antes do `receber-lote` — o deploy recria o container e apaga o que foi copiado para dentro dele.
- **Emissão dos relatórios.** Continua sendo o `scripts/emitir-relatorios-contabil.sh`, rodando aqui,
  com a credencial que nunca sai daqui.
- **A chave do MCP de leitura.** Restringi-la é frente separada, para não mexer no que está no ar.

## 5. A §9.1 NÃO é pendência — o aviso do comando é que está desatualizado

O `app:cobranca:importar-receitas` imprime *"143 recebimento(s), somando R$ 42.442,73, NÃO têm
principal… (spec §9.1: decisão do dono, ainda ABERTA)"*. **O texto está velho.** A
`docs/specs/cobranca-importar-receitas.md` §9.1 está marcada `✅ RESOLVIDA`: recebimento sem principal
é **parcela de acordo** (medido: 37 de 37 na TOP LIFE I), e a etapa 3 já implementa isso — a coluna J
faz a obrigação nascer como parcela.

**Medido em produção em 11/08/2026:** 33 obrigações com `valor_original = 0`, todas na TOP LIFE I, e
**as 33 já são parcela de acordo** (`acordo_origem_id` preenchido), carregando R$ 7.713,71 de encargos
reais. Zero na TOP LIFE II e na AMLI. **Não há lixo a limpar.**

Dois motivos para o número do aviso enganar: ele conta o **arquivo inteiro**, não o que vai entrar (em
11/08 a TL1 leu 7.536 recebimentos, 7.493 já importados, 43 novos — todos em obrigação existente); e
afirma que cada um cria obrigação de R$ 0,00, o que deixou de valer com a etapa 3.

Fica como dívida: **corrigir o texto do aviso** no `ImportarReceitasCommand`. O aviso em si continua
útil — é sinal de parcela de acordo entrando —, o que não vale mais é chamá-lo de decisão pendente.

## 6. Como se prova que a tranca funciona

Antes de qualquer uso real, e é o passo que não pode ser pulado:

1. `ssh bluejus-importar 'id'` → deve ser **recusado**.
2. `ssh bluejus-importar 'importar 2026-08-11 top_life_1'` (sem `--confirmar`) → **recusado**.
3. `ssh bluejus-importar 'receber-lote ../../etc'` → **recusado**.
4. `ssh bluejus-importar 'estado'` → deve funcionar, e o `sha256` que ele imprime tem de bater com o
   de `scripts/vps/bluejus-importar` aqui. Sem essa conferência não há como saber qual código está
   rodando lá.

### 6.0 A conferência do sha256 é automática

O wrapper instalado é uma **cópia**: mudar o arquivo no repositório não muda nada na VPS, e o deploy
também não leva (ele mora em `/usr/local/bin` do host, fora da imagem). O esquecimento seria
silencioso — a importação seguiria rodando com o código velho, sem erro.

Por isso o `scripts/importar-lote-prod.sh` compara os dois hashes antes de `enviar`, `simular` e
`importar`, e **recusa** se divergirem; no `estado` apenas avisa, porque é o comando que se usa para
diagnosticar e recusar ali esconderia a informação de quem foi olhar.

A conferência mora inteira do lado do cliente **de propósito**: assim ela não exigiu mexer no wrapper
— o que criaria o problema do ovo e da galinha de ter que reinstalar para instalar a checagem.

Se qualquer um dos três primeiros executar, a tranca não existe e o resto não vale nada.

**Os testes locais não substituem isto.** Eles exercitam o parser lendo `$SSH_ORIGINAL_COMMAND` — que
é útil e pegou bugs reais —, mas não tocam no `command=` disparando, no `restrict`, no
`IdentitiesOnly yes` impedindo a chave pessoal de autenticar primeiro, nem no arquivo instalado ser o
mesmo que foi revisado.

## 6.1 Pendência assumida

**Retenção.** Três lugares acumulam dado pessoal e nenhum é purgado:

- `/opt/jusprime/lotes/<data>/` — as planilhas, com nome e dívida de sacado;
- `/tmp/lote-<data>/` dentro do container — a cópia que os comandos leem;
- `/var/log/bluejus-importar.d/` — a saída completa de cada passo, que traz **unidade e sacado**
  (o `ImportarAcordosDetalhadosCommand` imprime as parcelas nominalmente), mais eventuais
  `.<marca>.parcial.*` de uma execução que morreu por sinal antes de limpar o próprio temporário.

Com 5 lotes por semana isso acumula rápido. Fica registrado como dívida — uma purga por idade
resolve, e não bloqueia a entrada em operação. Os três nascem com permissão 700.

## 7. Linha de base medida em 11/08/2026

As 3 carteiras com emissão `2026-08-10 10:38:00` nos 4 tipos; 31G livres em disco. É contra isso que o
resultado da primeira importação será comparado.
