# Spec — E1: consistência entre banco e arquivos físicos

> **Risco: ALTO** (o Bloco A toca ponto eletrônico).
> Frente: `e1-consistencia-arquivos`. Base: `origin/master` @ `33167706`.
> Origem: auditoria E0 (banco × filesystem em produção) + investigação dos quatro pontos.
> **Fora de escopo:** Cloudflare R2, SDK S3, `ArquivoStorageInterface`, migrations de storage,
> `storage_backend`/`storage_key`/`checksum`, backfill, exclusão dos 79 órfãos, dos 114 arquivos
> de 0 byte e normalização das 184 chaves legadas. Tudo isso fica para E2+.

## Por que esta frente existe

A E0 provou que o acervo está íntegro (22.650 registros, **zero** apontando para arquivo
inexistente), mas encontrou quatro mecanismos que produzem inconsistência entre o banco e o disco.
Nenhum deles causou dano ainda. Corrigi-los antes da migração para o R2 é barato; depois, cada um
vira um bug distribuído entre dois sistemas.

## Bloco A — Anexo de justificativa de ponto (ALTO)

### A situação medida

`justificativa_ponto.anexo_path` guarda o **nome** do arquivo. Em produção, 61 registros apontam
para 24 arquivos distintos: um arquivo é referenciado por 27 registros, outro por 7, outro por 3, e
três por 2 cada. **Não é bug de dados** — é abono em lote: o upload acontece uma vez, fora do laço
(`PontoController.php:317`), e a mesma string é gravada em N entidades, uma por dia
(`:325`→`:331`), todas com o mesmo `batchId`, o mesmo usuário e o mesmo tenant. O mesmo padrão
existe no lado admin (`TenantController.php:1447`→`:1459`).

Invariante medido em produção, hoje verdadeiro:

- `anexo_path` ↔ `batch_id` é **1:1** (24 ↔ 24);
- nenhum anexo é compartilhado entre tenants;
- nenhum anexo é compartilhado entre usuários.

### Os três defeitos

**A-D1 — a edição quebra o lote.** `PontoController.php:445-446` troca o `anexo_path` de **um
único** registro (o do `{id}` da rota), e o template sempre abre o modal pelo `batch[0]`
(`_justificativas_list.html.twig:97`). Trocar o atestado de um lote de 27 dias atualizaria 1 dia e
deixaria 26 apontando para o arquivo antigo. Ainda não ocorreu em produção — nenhum `batch_id` tem
dois `anexo_path`.

**A-D2 — duas das três portas não validavam.** Só o formulário do colaborador passava por
`Assert\File` com 10 MB e PDF/JPEG/PNG (`JustificativaPontoType.php:74-81`). A edição
(`PontoController.php:445`) **e a criação pelo admin** (`TenantController.php:1445-1447`) liam
`$request->files->get('anexo')` cru e chamavam `storage->salvar()` direto: qualquer tipo, qualquer
tamanho até o limite do PHP, no mesmo diretório plano servido pela mesma rota de download. As três
portas passam a usar a mesma regra.

**A-D3 — não há contagem de referências.** Não existe exclusão de justificativa em lugar nenhum
(nem rota, nem `remove()`, nem cascade, nem `ON DELETE`); o único caminho que apaga é a purga total
do escritório, onde o tenant inteiro morre junto. O risco é **potencial**: materializa-se na
primeira exclusão que alguém escrever.

### O comportamento novo

Substituir o anexo de uma justificativa passa a atingir **todo o lote** (`batchId`), preservando o
invariante **um `batchId` → um único anexo**. A edição valida MIME e tamanho com as **mesmas**
regras da criação, compartilhadas em um só lugar.

### Ordem de operações (consistência banco × arquivo)

A prioridade é explícita: **é aceitável deixar um arquivo órfão recuperável; não é aceitável deixar
registro válido apontando para arquivo inexistente.** O filesystem não participa da transação do
PostgreSQL, então a remoção física acontece **sempre depois** do commit.

**Pré-condição, antes de qualquer coisa:** a justificativa tem de pertencer ao tenant informado.
Sem essa checagem, um descasamento faria `findLotePorBatchId` voltar vazio, a substituição atingir
um registro só e a fase 2 contar zero referências — **apagando um arquivo que o lote inteiro ainda
usa**. Ou seja, o caminho de erro viraria o caminho destrutivo. Hoje a porta HTTP é fechada pelo
TenantFilter, mas esta classe decide `unlink` e não pode depender só disso. Pelo mesmo motivo, um
lote vazio é **recusado** em vez de cair num fallback de um registro.

**Fase 1 — transação (banco):**

0. validar o arquivo (MIME + tamanho) — **fora** da transação: arquivo recusado não chega a abrir
   transação nem a tomar trava;
1. `wrapInTransaction`;
2. `pg_advisory_xact_lock` com chave derivada de `(tenant, batchId)` — serializa quem mexe no lote;
3. **reler o lote sob o lock** — e tirar dele também o nome do anexo a remover. Capturar esse
   nome antes da trava faria a fase 2 decidir sobre um valor que outra transação já trocou, e o
   arquivo efetivamente substituído nunca seria contado nem removido;
4. ler o anexo a remover **do banco**, por projeção escalar (`anexoNoBancoPorId`) e não pelo
   getter: `findLotePorBatchId()` não relê os campos de uma entidade que já esteja no identity map
   (sem `HINT_REFRESH` o `UnitOfWork` devolve a instância gerenciada como está em memória), e a
   justificativa chega ao UseCase carregada pelo EntityValueResolver, muito antes da trava;
5. `storage->salvar()` → nome novo `Y`;
6. `setAnexoPath(Y)` em **todos** os registros do lote;
7. `COMMIT`.

Se a fase 1 falhar, `Y` é removido em *best-effort* — mesmo padrão já usado em
`ReconciliadorDePasta.php:470-473`. O `try/catch` envolve a **chamada** de `wrapInTransaction`, não
o closure: o Doctrine executa o closure e só depois faz flush e commit, por fora dele, então um
catch interno não veria falha de COMMIT — que é a falha mais provável da fase 1. Se a própria
remoção falhar, `Y` fica órfão recuperável: o banco nunca chegou a apontar para ele.

**Fase 2 — após o commit (disco):**

8. transação curta com `pg_advisory_xact_lock` derivado do **arquivo antigo** `X`;
9. `contarReferenciasAoAnexo(X, tenant)`;
10. se a contagem for `0`, `storage->excluir(caminho(dir, X))`;
11. `COMMIT`.

Falha na fase 2 deixa `X` órfão recuperável. Aceito.

### A janela COMMIT → contagem → DELETE

A janela existiria se, entre a contagem devolver zero e o `unlink` acontecer, algum caminho criasse
uma referência nova a `X`. **Ela está fechada por construção, não por sincronização.**

O argumento é a **monotonicidade decrescente das referências**. Existem exatamente três produtores
de `anexo_path` em todo o repositório — a criação pelo colaborador (`PontoController`), a criação
pelo admin (`TenantController`) e o próprio `SubstituirAnexoDoLoteUseCase` — e os três recebem o
retorno de
`ArquivoStorageService::salvar()`, que gera `bin2hex(random_bytes(16))`: 128 bits de aleatoriedade,
nome novo a cada chamada. **Nenhum caminho do código copia um `anexo_path` existente para outro
registro.** Logo, depois que a transação que removeu a última referência a `X` comita, o conjunto de
referências a `X` só pode diminuir. Uma contagem que devolve zero é definitiva e permanece zero.

Duas defesas em profundidade sustentam essa premissa:

1. **O lock da fase 2 é derivado do arquivo, não do lote.** O recurso disputado é o arquivo. Duas
   fases 2 concorrentes sobre o mesmo `X` serializam; a segunda encontra a contagem já em zero e o
   arquivo já ausente — e `ArquivoStorageService::excluir()` é idempotente (`file_exists` antes do
   `unlink`). Travar pelo `batchId` nesta fase **não** bastaria: se um dia um arquivo passar a ser
   referenciado por dois lotes, o lock do lote A não impediria o lote B de agir sobre o mesmo
   arquivo.
2. **`ProdutoresDeAnexoPathTest` cerca as quatro portas por onde uma cópia de chave entraria**:
   quem escreve (`setAnexoPath`), quem **lê** (`getAnexoPath` — é por aí que a cópia indireta
   passa), quem escreve a coluna por SQL cru, e `clone` da entidade. Também fixa que a entidade
   atribui `$this->anexoPath` num lugar só, para que um helper interno não escape de tudo. Cada
   porta tem allowlist própria: qualquer entrada nova quebra a suíte e força revisão.

Por que a trava da fase 1 não foi simplesmente estendida até o `unlink`: `pg_advisory_xact_lock` só
é liberada no fim da transação (é o que `NumeracaoDePasta.php:47-50` documenta). Manter a transação
aberta durante a escrita em disco colocaria o filesystem dentro da janela transacional — exatamente
o que esta spec quer evitar — e prenderia uma conexão do pool durante I/O.

### Entregáveis do Bloco A

- `JustificativaPontoRepository::contarReferenciasAoAnexo(string $anexoPath, Tenant $tenant): int`
  — DQL com filtro **explícito** de tenant (modelo de `JustificativaPontoRepository.php:30-36`).
- `JustificativaPontoRepository::findLotePorBatchId(string $batchId, Tenant $tenant): array`.
- `App\Ponto\Validacao\RestricoesAnexoJustificativa` — `MAX_LEGIVEL`, `MIMES_PERMITIDOS` e a
  `Assert\File` compartilhada. Consumida pelas TRÊS portas: `JustificativaPontoType`
  (colaborador), `TenantController::novaJustificativaAdmin` (admin) e a edição. O limite é
  declarado só na forma legível — o `File` do Symfony trata `10M` como 10.000.000, não 10.485.760,
  então uma constante em bytes ao lado dela seria uma segunda regra divergente.
- `App\Ponto\UseCase\SubstituirAnexoDoLoteUseCase` — implementa as fases 1 e 2.
- `PontoController::editarJustificativa` delega ao UseCase.

As **duas portas de criação** também passaram a remover o arquivo em best-effort quando o `flush`
falha: é a mesma classe de defeito que a fase 1 resolve na edição — arquivo gravado, banco não
referenciou — e estava aberta nos dois controllers.

**Não** será criada rota de exclusão de justificativa.

## Bloco B — Referências dentro do HTML das peças (MÉDIO)

### A situação medida

Imagens do editor TinyMCE são gravadas em `public/uploads/pastas/<tenantId>/` e **não têm linha
nenhuma no banco** (`UploadImagemEditorUseCase.php:32-34` devolve o nome e não persiste). A única
referência a elas é a URL embutida no HTML da peça.

Pior: o commit `2b176cb7` (30/06/2026) introduziu o isolamento por tenant. Tudo enviado **antes**
ficou solto no diretório plano, misturado aos documentos, e continua referenciado por HTMLs
antigos — `PecaImagemControllerTest.php:91` documenta que esses arquivos já não são serviços.

O formato gravado varia: `ExportarPecaTextoUseCase.php:50-52` registra que o TinyMCE grava a URL
como absoluta (`/uploads/pastas/<hex>`) **ou** relativa (`../../uploads/pastas/<hex>`), e o regex
em `:57-67` aceita as duas.

Em produção hoje não há peça HTML viva (zero registros com chave `.html`), então o risco é
teórico — mas o ambiente de dev tem 1.023 arquivos no diretório plano e o mecanismo morde na
primeira peça com imagem que for criada.

**Não existe nenhuma rotina de limpeza de órfãos** — nem comando, nem cron, nem Scheduler.

### A regra arquitetural

> **"Arquivo sem linha própria no banco" não significa "arquivo órfão".**

### Entregáveis do Bloco B

- `App\Pasta\Service\ReferenciasDePecaHtml::extrair(string $html): string[]` — o regex de
  `ExportarPecaTextoUseCase.php:57-67` extraído para um lugar só. O export passa a consumi-lo com
  **comportamento idêntico**; os 13 testes de `ExportarPecaTextoUseCaseTest` são a prova.
- `App\Pasta\Service\ArquivosReferenciadosEmPecas::doTenant(Tenant): string[]` — conjunto dos
  arquivos citados nos HTMLs das peças do tenant. É a definição executável da regra acima.
- `tests/Arquitetura/LimpezaDeArquivosArquiteturaTest` — varre `src/` e falha se aparecer
  `glob()`/`scandir()` seguido de remoção fora de uma allowlist explícita. Hoje a allowlist tem um
  item: `PurgarEscritorioUseCase::removerDiretorioDeTenant`, onde o escritório inteiro morre.

**Nenhuma rotina de limpeza será escrita nesta E1. Nenhum arquivo da E0 será excluído.**

## Bloco C — Anexos do Kanban (BAIXO)

### A situação medida

`AdicionarAnexoUseCase.php:25` passa o literal `'kanban'` como diretório — caminho **relativo**.
O `WORKDIR` do PHP-FPM em produção é `/var/www/app`, então o arquivo cairia em
`/var/www/app/kanban/`, **fora do volume `uploads_prod`** (montado em
`/var/www/app/public/uploads`): perdido no deploy seguinte. `ExcluirAnexoUseCase.php:21-22` e
`KanbanAnexoController.php:85` tratam o valor do banco como caminho completo, quando ele é só o
nome.

Segundo defeito: `KanbanCard.php:69-71` declara `cascade: ['remove'], orphanRemoval: true` nos
anexos. Excluir um card apaga as linhas **sem passar pelo UseCase** — os arquivos ficam no disco.

**Terceiro defeito, descoberto durante a implementação (não estava na investigação):** o UseCase
salvava o arquivo **antes** de ler os metadados. `ArquivoStorageService::salvar()` chama
`UploadedFile::move()`, que move o temporário do PHP; depois disso `getSize()` e `getMimeType()`
estouram `stat failed` e o request termina em 500. **O upload de anexo do Kanban nunca funcionou** —
e é exatamente por isso que `kanban_anexo` está vazia em produção. A correção lê os metadados antes
do `move()`.

`kanban_anexo` está **vazia em produção**: zero migração de dados.

### Entregáveis do Bloco C

- Parâmetro `kanban_uploads_dir` em `config/services.yaml` (+ override de teste para
  `var/uploads-test/kanban`, como os outros seis), injetado por bind.
- `AdicionarAnexoUseCase` recebe o diretório; some o literal.
- `ExcluirAnexoUseCase` e `KanbanAnexoController::servir` compõem `caminho($dir, $nome)`.
- Exclusão de card **e de mural** remove os arquivos dos anexos antes do `remove()` (a cascata é
  board → colunas → cards → anexos).
- Metadados lidos antes do `move()`, corrigindo o 500 do upload.

## Bloco D — `TarefaController::resolverCaminhoArquivo` (BAIXO)

### A situação medida

`TarefaController.php:544-552` concatena `projectDir + '/public' + valor-do-banco` sem `basename()`,
sem normalização e sem confinamento. O valor vem de `tarefa_mensagem.arquivo_anexo`, gravado pelo
próprio sistema em `:578-582`. **Não é explorável hoje** — medi os 11 registros de produção: todos
têm 57 caracteres, todos batem `/uploads/tarefas/...`, zero contêm `..`. É defesa em profundidade
ausente, e é a única rota de arquivo sem a guarda que `ProfileController.php:248` e
`PecaImagemController.php:48` têm.

### Entregáveis do Bloco D

Validação em três camadas antes de devolver o caminho: allowlist por expressão regular sobre o
valor, normalização, e confirmação via `realpath()` de que o resultado está contido no diretório
permitido. Valor fora do padrão devolve `null` (que o chamador já trata como 404).

### Por que o teste tem de atacar o método, não a rota

`ServirFotoControllerTest.php:224-233` já documenta a armadilha: um GET com `../../` é normalizado
pelo **roteador** antes de chegar ao controller, então o teste passa verde com ou sem a guarda —
prova outra barreira, não a que se quer provar. O teste do Bloco D chama o método/serviço
diretamente com o valor malicioso.

## Testes (o alvo da revisão)

| Bloco | Teste | O que prova |
|---|---|---|
| A | lote de 3 dias, troca de anexo | os **3** registros passam a apontar para o novo |
| A | idem | o arquivo antigo some quando ninguém mais o referencia |
| A | idem | o arquivo antigo **permanece** se outro registro ainda o referencia |
| A | `contarReferenciasAoAnexo` | conta só o próprio escritório (**não** filtra usuário, de propósito: escopo mais largo conta mais e apaga menos) |
| A | validação na edição | arquivo acima de 10 MB é recusado; MIME fora da lista é recusado |
| A | invariante | nenhum `batchId` termina com dois `anexo_path` distintos |
| A | produtores de `anexo_path` | falha se surgir um quarto produtor (sustenta a monotonicidade) |
| B | `ReferenciasDePecaHtml` | acha a imagem nos dois formatos (absoluto e relativo) |
| B | `ArquivosReferenciadosEmPecas` | imagem sem linha no banco aparece como referenciada |
| B | arquitetura | falha ao introduzir `glob`+remoção novo fora da allowlist |
| C | `AdicionarAnexoUseCase` | o diretório usado é o injetado, nunca relativo |
| C | ciclo de vida | upload → servir → excluir remove o arquivo do disco |
| C | cascade | excluir o card remove os arquivos dos anexos |
| D | `resolverCaminhoArquivo` | `../../../.env` é recusado — atacando o método direto |

## Ordem e critério de conclusão

`D → C → B → A`, testes direcionados verdes após cada bloco, suíte completa ao final,
`/review` do Bloco A pelo `feature-review-agent` e re-revisão após as correções (exigência de
risco ALTO).

**Nenhuma migration em nenhum dos quatro blocos.**
