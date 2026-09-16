# Spec — E2: abstração de storage do BlueJus Core

> **Risco: ALTO** — a frente toca ponto eletrônico (`SubstituirAnexoDoLoteUseCase`,
> `PontoController`) e identidade de Tenant (`PurgarEscritorioUseCase`).
> Frente: `e2-abstracao-storage`. Base: `origin/master` @ `c365fe72` (merge da E1).
> Origem: investigação de 2026-09-15 sobre o master pós-E1, decisões D1–D6 ratificadas pelo dono na
> mesma data, e revisão adversarial da própria spec contra o código (24 achados, incorporados).
>
> **O que a E2 entrega:** o código de negócio deixa de endereçar arquivo por caminho de disco e
> passa a endereçá-lo por uma **chave**. O backend continua sendo **exclusivamente local**.
>
> ```
> Aplicação → ArmazenamentoDeArquivos → ArmazenamentoLocal → os MESMOS arquivos, nos MESMOS caminhos
> ```
>
> **Fora de escopo (lista completa no §14):** Cloudflare R2, SDK S3, presigned URL, colunas
> `storage_backend`/`storage_key`/`checksum`, migration, backfill, mover ou renomear arquivo,
> alterar qualquer registro do banco.
>
> **Decisões D1–D9 todas ratificadas** (D1–D6 em 15/09; D7, D8 e D9 nasceram da revisão adversarial
> da própria spec e foram ratificadas na sequência) — ver §8.

## Por que esta frente existe

A E0 mediu o acervo (24,38 GB, 22.650 documentos, zero apontando para arquivo inexistente) e a E1
fechou os quatro mecanismos que produziam inconsistência entre banco e disco. O que sobra, e que
esta frente resolve, é **arquitetural**: hoje 26 arquivos de produção pedem ao storage um
**caminho de sistema de arquivos** e trabalham com ele na mão. Enquanto isso for verdade, a entrada
do R2 não é uma implementação nova — é uma refatoração do sistema inteiro, feita sob pressão, com
dado real de cliente em jogo.

A E2 troca a ordem: primeiro o contrato, com o backend que já existe e comportamento preservado;
depois, na E4, o R2 entra como **mais uma** implementação do mesmo contrato.

---

## 1. Estado medido (master @ `c365fe72`)

### 1.1 A interface atual

`app/src/Shared/Service/ArquivoStorageInterface.php` — 7 métodos, 1 implementação de produção
(`ArquivoStorageService.php`) e **5 dublês na suíte que implementam a interface inteira**
(`app/tests/Ponto/Doubles/StorageDeDiscoParaTeste.php:15`, `ArquivoStorageStub` em
`app/tests/Profile/Unit/AtualizarFotoPerfilUseCaseTest.php:165`, e classes anônimas em
`PastaFinanceiroControllerTest.php:99`, `SalvarFotoPerfilControllerTest.php:72`,
`ServirFotoControllerTest.php:51`).

| Método | Linha | Pressupõe FS local? | Problema |
|---|---|---|---|
| `salvar(UploadedFile, $dir)` | `:12` | **sim** (`UploadedFile::move()`) | acopla o contrato ao HTTP do Symfony; **invalida** o `UploadedFile` (causa do 500 do Kanban, ver `AdicionarAnexoUseCase.php:26-33`); não devolve metadados |
| `salvarConteudo(string,$dir,$ext)` | `:14` | sim (`file_put_contents` sem checar retorno, `ArquivoStorageService.php:28`) | erro de escrita passa em silêncio |
| `moverParaArmazenamento($origem,$dir,$ext)` | `:21` | **sim** (`rename`/`copy`, fallback EXDEV) | o nome descreve a mecânica local, não a intenção |
| `servir($caminho,$nome,$inline)` | `:23` | **sim** (`BinaryFileResponse`) | o storage decide HTTP: Content-Disposition e nome de download |
| `excluir($caminho)` | `:25` | não conceitualmente | — |
| `existe($caminho)` | `:27` | não conceitualmente | confunde "não existe" com "não consegui ler" |
| `caminho($dir,$nome)` | `:29` | **sim, por definição** | **é o vazamento**: devolve string de path e toda dependência de filesystem escapa por ela |

**Não existem** `tamanho()`, leitura, stream, substituição de conteúdo nem operação de prefixo.
É exatamente por isso que os consumidores contornam com PHP cru.

**Quem cunha o nome do arquivo hoje é o storage**, e ele o devolve: `bin2hex(random_bytes(16))` mais
a extensão, em `ArquivoStorageService.php:17` (de `guessExtension()`), `:27` e `:37` (da extensão
recebida). Quem chama nunca escolhe o nome. O contrato novo precisa preservar isso — ver **D8**.

O `$diretorio` **vem do chamador**, de 7 parâmetros do container (`app/config/services.yaml:11-28`,
binds em `:76-82`). O conhecimento de onde cada categoria mora está espalhado por 33 arquivos, e o
prefixo de tenant é *ad hoc*: **duas** categorias concatenam `'/' . $tenant->getId()` (documento de
Cobrança e imagem do editor); as outras **sete** são planas — inclusive `TAREFA_ANEXO`, que não vem
de parâmetro nenhum (`CaminhoDeAnexoDeTarefa.php:32`).

### 1.2 Consumidores

**33 arquivos de produção** chamam o storage; **26 usam `caminho()` ou `servir()`**.
Chamadas: `caminho()` 40 · `existe()` 27 · `excluir()` 19 · `servir()` 15 · `salvar()` 14 ·
`salvarConteudo()` 2 · `moverParaArmazenamento()` 1.
Por arquivo: 22 usam `existe()`, 15 usam `excluir()`, 11 usam `servir()`, 16 escrevem.

- **17 rotas entregam arquivo persistido.** 15 por `servir()`, distribuídas em **11 controllers**;
  2 fora da abstração (`TarefaController.php:490` e `:515`).
  **Todas checam tenant. Nem todas checam permissão de módulo:** `PecaImagemController::servir`
  (`:45-63`) e `ProfileController::servirFoto` (`:244-264`) resolvem só pelo tenant da sessão, sem
  `permissionChecker` — qualquer usuário logado do escritório alcança qualquer imagem de editor ou
  foto de perfil daquele escritório. Não é regressão introduzida aqui e **não é escopo da E2
  corrigir**; está registrado para que nenhum teste da E2 seja escrito contra uma afirmação falsa.
- **18 pontos de escrita persistente:** 14 `salvar(UploadedFile)`, 2 `salvarConteudo(string)`
  (`SalvarPecaTextoUseCase.php:43`, `CopiarArquivosAcervoCommand.php:381`), 1
  `moverParaArmazenamento` (`ReconciliadorDePasta.php:444`) — **e 1 escrita crua**,
  `EditarPecaTextoUseCase.php:21-22` (`caminho()` + `file_put_contents`), que não passa por método
  nenhum da interface. São 17 chamadas de interface em 16 arquivos, mais essa.
- **Nenhum template Twig** referencia `/uploads/` — tudo é `path()`. O nginx bloqueia `/uploads/`
  como estático em dev e prod (`nginx.conf:17-19`, `nginx/conf.d/nginx.prod.conf:67-69`).
- **Não há `X-Accel-Redirect`/`X-Sendfile`** em lugar nenhum: hoje o PHP entrega o byte.
- **`DocumentoProcesso` é consumidor de LEITURA, não de escrita.** Nada em `app/src/` grava
  `documento_processo.caminho_arquivo` fora de `DataFixtures`, mas
  `PurgarEscritorioUseCase.php:319` **consulta a coluna** e joga os nomes na limpeza de disco. Em
  `saas_ux` a tabela tem 0 linhas. A E2.5 tem de decidir o que fazer com ela (§10).
- **A purga também lê caminhos de Tarefa.** `PurgarEscritorioUseCase.php:322` consulta
  `tarefa_mensagem.arquivo_anexo`, cujo valor é `/uploads/tarefas/<sub>/<arquivo>` — **contém
  barra**. Isso colide com a fronteira de `ChaveDeArquivo` (§3.1) duas fatias antes de a E2.7
  decidir Tarefa. Tratamento em §10.

### 1.3 Dependências diretas de filesystem, classificadas

**(A) Deve passar pela abstração** — arquivo persistente acessado por path:

| Ponto | Operação |
|---|---|
| `app/src/Pasta/UseCase/EditarPecaTextoUseCase.php:21-22` | `caminho()` + `file_put_contents` — **escrita** sem método no contrato |
| `app/src/Pasta/Service/ArquivosReferenciadosEmPecas.php:49-55` | `caminho()` + `existe()` + `file_get_contents` |
| `app/src/Pasta/UseCase/ExportarPecaTextoUseCase.php:30-31` | leitura do HTML da peça |
| `app/src/Pasta/UseCase/ExportarPecaTextoUseCase.php:33`, `:59-68`, `:143` | reescreve `<img>` para disco — **alimenta DOCX, ODT e PDF**, não só o PDF — e fixa `chroot` do Dompdf em `public/` |
| `app/src/Shared/Service/CompressorArquivo.php` (inteiro) | `is_file`/`filesize`/GD/Ghostscript; `rename` in-place sobre o persistido (`:58`); temporário criado **dentro** do dir de uploads (`:169`) |
| `app/src/Sync/Service/GoogleDriveClient.php:169,190,211` | `filesize`+`fopen`+`fread` do persistido para subir ao Drive |
| `app/src/Tenant/UseCase/PurgarEscritorioUseCase.php:383-396` | `is_dir`/`glob`/`rmdir` |
| `app/src/Controller/TarefaController.php:486,511,568,582` + `app/src/Tarefa/Service/CaminhoDeAnexoDeTarefa.php:63-64` | domínio inteiro fora da abstração |
| `app/src/Shared/Service/ArquivoStorageService.php:18-97` | é o adapter — esses acessos a filesystem são **legítimos** e só mudam de lugar |

**(B) Temporário legítimo, continua local:** `import-tmp` de cobrança
(`ImportacaoController.php:186,240,262`) · `tempnam` do sync (`ReconciliadorDePasta.php:428,481-483`)
· 4 *lock files* em `sys_get_temp_dir()` (`ImportarIndicesMonetariosCommand.php:298`,
`PurgarDadosExpiradosCommand.php:159`, `SincronizarDjenCommand.php:184`,
`Sync/Command/ReconciliarCommand.php:207`) · `ImportarRelatorioInput.php:52` (magic bytes no tmp do
PHP) · os 10 `IOFactory::createReaderForFile()` de planilha.

**(C) Infra/boot/CLI one-off, fora do domínio de storage:** `CopiarArquivosAcervoCommand`,
`MapearAcervoCommand`, `ParsearAcervoCommand`, `ImportarAcervoCommand`, `BackfillPastasCommand`,
`CarregarEspelhoRelatorioCommand`, os `Importar*Command` (path vem de `--arquivo`/`--diretorio` do
operador) e `GoogleDriveClient.php:58` (`is_file` no JSON de credencial).

**(D) Precisa de análise na fatia correspondente:**

1. `CopiarArquivosAcervoCommand` é C na entrada e **A na saída** (`:381`), e carrega o arquivo
   inteiro em memória (`:374`).
2. `import-tmp` mora **dentro do volume persistido** de uploads — B pela intenção, A pelo lugar.
   Ver **D3**: fica como está nesta E2.
3. `.compress_<hex>` do compressor idem (`CompressorArquivo.php:169`). Ver **D4**.
4. `PeticionarController.php:334` devolve a URL literal `/uploads/pastas/<hex>` ao TinyMCE, e ela é
   **gravada dentro do HTML da peça** — dado persistido com formato de path.
5. `ArquivosDeAnexoDoKanban::diretorio()` (`:40-43`) devolve o diretório cru e **não tem consumidor
   em `app/src/`** — vazamento de path sem uso. **Removido na E2.2.**

---

## 2. Invariantes — verdadeiros ao fim de CADA fatia

Condições de parada, não metas finais. Se uma cair, a fatia não está pronta.

- **INV-1 — Nenhum arquivo físico se move.** Todo byte continua no mesmo caminho do master
  `c365fe72`, com os parâmetros de **produção**. Provado por `MapaDeChaveParaCaminhoLocalTest`
  (§11.2), que precisa fixar os parâmetros explicitamente: em `APP_ENV=test` os 7 apontam para
  `var/uploads-test/*` (`app/config/services.yaml:161-169`), então um teste que só leia o container
  prova concatenação, não layout de produção.
- **INV-2 — Nenhum registro de banco muda.** Nenhuma migration, nenhum `UPDATE` de coluna de
  arquivo, nenhuma normalização de chave legada.
- **INV-3 — A suíte fica verde entre fatias.** Cada fatia é integrável sozinha (**D2**).
- **INV-4 — Download não regride.** Status, `Content-Type`, `Content-Disposition`, `Accept-Ranges`,
  resposta 206 a `Range`, e **nenhum arquivo carregado inteiro em memória**.
- **INV-5 — Autorização permanece acima do storage.** O storage nunca decide quem pode ler; as 17
  rotas mantêm **exatamente** as checagens que têm hoje — nem mais, nem menos (ver §1.2 sobre as
  duas que não checam permissão de módulo).
- **INV-6 — Ordem de operações da E1 preservada.** É aceitável deixar arquivo órfão recuperável;
  **não** é aceitável deixar registro válido apontando para arquivo inexistente. Remoção física
  sempre **depois** do COMMIT.
- **INV-7 — Falha de compressão nunca destrói o arquivo persistente válido** (**D4**).
- **INV-8 — O núcleo de armazenamento não importa `Symfony\Component\HttpFoundation`.**
- **INV-9 — Nada apaga um arquivo emprestado** (**D9**). No `ArmazenamentoLocal`, `paraLeitura()`
  devolve **o caminho do arquivo de produção** como `ArquivoEmprestado`: referência emprestada, sem
  `liberar()`, cujo destrutor **não** apaga. Só `ArquivoTemporarioPossuido` tem cleanup que remove,
  e ele remove apenas a própria cópia. `EntregaDeArquivo` **nunca** chama
  `deleteFileAfterSend(true)` sobre arquivo persistido. O risco é real e não teórico:
  `BinaryFileResponse` só abre o arquivo em `sendContent()` — `new \SplFileObject(...)`,
  `BinaryFileResponse.php:321` — **depois** que o controller retornou, e apaga se
  `deleteFileAfterSend` estiver ligado (`:332-334`). Falha durante a materialização também não pode
  tocar o original.
- **INV-10 — A semântica de upload não regride.** `UploadedFile::move()`
  (`vendor/symfony/http-foundation/File/UploadedFile.php`) faz três coisas que um `rename()` não
  faz: `isValid()` com exceções tipadas por `UPLOAD_ERR_*`, **`move_uploaded_file()`** (prova de que
  o arquivo veio de upload HTTP) e `@chmod($target, 0666 & ~umask())` — sem o chmod o arquivo nasce
  `0600` e o nginx/worker não lê. A ponte HTTP (§3.5) preserva os três.

---

## 3. Contrato alvo

Quatro peças, deliberadamente separadas. A pergunta que decidiu cada corte foi sempre a mesma:
*o R2 responde isto de um jeito diferente?* Se responde, é do storage; se não responde de jeito
nenhum, é de cima.

### 3.1 Valores (`App\Shared\Armazenamento\`)

| Tipo | Conteúdo | Regra |
|---|---|---|
| `CategoriaDeArquivo` (enum) | 9 categorias: `PASTA_DOCUMENTO`, `PASTA_IMAGEM_EDITOR`, `CLIENTE_DOCUMENTO`, `CHAMADO_ANEXO`, `JUSTIFICATIVA_ANEXO`, `FOTO_PERFIL`, `COBRANCA_DOCUMENTO`, `KANBAN_ANEXO`, `TAREFA_ANEXO` | fechada; categoria nova exige entrada no resolvedor e no teste do mapa |
| `CategoriaComIsolamentoFisico` (enum **separado**) | **só** `PASTA_IMAGEM_EDITOR` e `COBRANCA_DOCUMENTO`, com `paraCategoria(): CategoriaDeArquivo` | é o **tipo** aceito pelas operações de prefixo. Categoria plana não é representável ali — o compilador recusa, não o runtime (**D7**) |
| `EscopoDeArquivo` | `deTenant(int $id)` \| `global()` | **semântico**, não físico (**D1**) |
| `ChaveDeArquivo` | `(EscopoDeArquivo, CategoriaDeArquivo, string $nome)` | `$nome` é a string do banco **byte a byte**. Ver a regra de não-normalização abaixo. Recusa: vazio, `/`, `\`, `..`, byte nulo, caractere de controle. Fronteira de segurança do storage (**D5**) |
| `NovoArquivo` | `(EscopoDeArquivo, CategoriaDeArquivo, string $extensao)` | destino de escrita **sem nome ainda** — o storage cunha (**D8**) |
| `FonteDeConteudo` | `deTexto(string)` \| `deArquivoLocal(string $path, bool $consumirOrigem)` \| `deStream($resource)` | **três representações genéricas, nenhuma do Symfony** (INV-8) |
| `ArquivoArmazenado` | `chave` (já com o nome cunhado), `tamanhoBytes`, `mimeType` | retorno da escrita — medido **depois** de gravar |
| `MetadadosDeArquivo` | `tamanhoBytes`, `mimeType`, `atualizadoEm`, `checksum` (**nullable**) | `checksum` previsto, **não calculado** na E2 (**D6**) |
| `ArquivoEmprestado` | um caminho local que o materializador **não possui** | sem `liberar()`, sem destrutor que apague. Só o Local produz (**D9**) |
| `ArquivoTemporarioPossuido` | um caminho local **possuído** | tem `liberar()` explícito; o destrutor apaga **só** essa cópia (**D9**) |

**Regra de não-normalização da chave (D8).** Sobre o `$nome` de uma `ChaveDeArquivo`, o construtor
**nunca**: aplica `trim()`, normaliza Unicode, mexe em espaços, remove ponto final, sanitiza de
forma destrutiva, nem exige formato de hash. As 184 chaves legadas da E0 precisam continuar
endereçáveis **exatamente como estão** — medido em `saas_ux`: 165 terminam em `.` e 3 têm espaço
nas bordas (§11.1). A normalização física delas segue reservada ao backfill da E3. O que o
construtor faz é **recusar** (nunca corrigir) os cinco casos que quebrariam a fronteira de storage.

**Regra de entropia do nome novo (D8).** `NovoArquivo` faz o storage cunhar um nome **opaco**, com
entropia equivalente à de hoje — `bin2hex(random_bytes(16))`, 128 bits
(`ArquivoStorageService.php:17`). Nome derivado de dado do usuário, sequencial ou previsível está
fora: a imprevisibilidade da chave é parte da defesa, já que o bucket do R2 é privado mas a chave
circula.

### 3.2 Núcleo — `ArmazenamentoDeArquivos` (6 métodos)

```
gravar(ChaveDeArquivo|NovoArquivo, FonteDeConteudo) : ArquivoArmazenado
abrir(ChaveDeArquivo)                               : resource
ler(ChaveDeArquivo)                                 : string
existe(ChaveDeArquivo)                              : bool
excluir(ChaveDeArquivo)                             : void     // idempotente
metadados(ChaveDeArquivo)                           : ?MetadadosDeArquivo
```

`gravar` aceita os dois destinos: `NovoArquivo` (o storage cunha o nome, como hoje) ou
`ChaveDeArquivo` (sobrescrever conteúdo de arquivo existente — é o caso de
`EditarPecaTextoUseCase`, o 18º ponto de escrita). Em ambos, `ArquivoArmazenado.chave` traz a chave
final. **É isto que substitui `salvar()`, `salvarConteudo()`, `moverParaArmazenamento()` e o
`file_put_contents` cru, os quatro.**

**Por que estes seis, e por que não é uma God Interface:** cada um corresponde a um verbo já
exercido hoje e que **um backend remoto responde de forma diferente**. Nada mais entra: nem HTTP,
nem autorização, nem MIME de apresentação, nem auditoria, nem transação.

### 3.3 Abstrações segregadas

- **`ArmazenamentoComPrefixo`** (**D7**) —
  `listar(EscopoDeArquivo, CategoriaComIsolamentoFisico): iterable<ChaveDeArquivo>` e
  `excluirPrefixo(EscopoDeArquivo, CategoriaComIsolamentoFisico)`.
  **A barreira é de tipo, não de runtime:** o parâmetro é o enum `CategoriaComIsolamentoFisico`,
  que só tem dois casos, então categoria plana **não é representável** na chamada. Não existe API
  que "aceite qualquer categoria e dependa do chamador lembrar quais são seguras". Cinto e
  suspensório: o adapter ainda assim afirma, antes de apagar, que o prefixo resolvido pertence
  exclusivamente ao escopo informado — e lança se não puder provar.
  O motivo é concreto: em `public/uploads/clientes` moram os documentos de **todos** os
  escritórios, e `PASTA_DOCUMENTO` divide raiz com `PASTA_IMAGEM_EDITOR`. Isolamento lógico **não
  se infere de diretório fisicamente compartilhado**.
  Único consumidor: `PurgarEscritorioUseCase`, que nas 7 categorias planas continua fazendo o que
  já faz — identificar os arquivos **pelos registros do tenant** e apagar **um a um** via
  `excluir()` do núcleo (`PurgarEscritorioUseCase.php:348-375`).
- **`MaterializadorDeArquivo`** (**D9**) — `paraLeitura(ChaveDeArquivo): ArquivoEmprestado` e
  `copiaGravavel(ChaveDeArquivo): ArquivoTemporarioPossuido`. **São dois tipos distintos, não um
  booleano:** ownership e lifetime estão no sistema de tipos, então passar um emprestado para algo
  que apaga não compila. `ArquivoEmprestado` não tem `liberar()` nem destrutor que remova;
  `ArquivoTemporarioPossuido` tem ciclo de vida explícito e seu cleanup apaga **somente** a cópia.
  No Local, `paraLeitura()` é **cópia zero** — devolve o caminho do arquivo de produção.
  Existe porque quatro consumidores exigem caminho real: Ghostscript via `Process`, GD,
  Dompdf/PhpWord com imagens locais, e o upload em blocos do Drive.
  **Entrou em duas partes:** `paraLeitura()` na E2.3, porque a entrega HTTP precisa dele;
  `copiaGravavel()` fica para a E2.6, junto dos consumidores e dos testes de modo de falha de D4.
- **`EntregaDeArquivo`** (camada HTTP, `App\Shared\Http\`, fora de `Shared\Armazenamento`) —
  `resposta(ChaveDeArquivo, string $nomeParaDownload, bool $inline): Response`. Entregue na E2.3
  **sem** o parâmetro `PoliticaDeEntrega`: nenhum backend sabe redirecionar ainda, e decidir a
  política é da E4 (ver §5 e o bloco da E2.3 no §10).

### 3.4 Responsabilidades

| Peça | É responsabilidade | **Não** é responsabilidade |
|---|---|---|
| `gravar` | cunhar o nome quando o destino é `NovoArquivo`; persistir os bytes; devolver tamanho e MIME medidos depois de gravar | validar MIME de negócio, escolher categoria, abrir transação |
| `abrir` / `ler` | entregar os bytes; lançar se ausente | saber quem pode ler |
| `existe` | presença; **lançar** em erro de I/O (≠ ausência) | traduzir ausência em 404 |
| `excluir` | remover, idempotente | decidir o momento em relação ao COMMIT (é do UseCase — INV-6) |
| `metadados` | tamanho / MIME / `atualizadoEm` / `checksum` | interpretar o tamanho |
| `ArmazenamentoComPrefixo` | varrer/remover **só** `CategoriaComIsolamentoFisico`, e provar o pertencimento do prefixo antes de apagar | decidir o que é órfão — quem responde isso continua sendo `ArquivosReferenciadosEmPecas`; e apagar nas categorias planas, que é da purga, um a um |
| `MaterializadorDeArquivo` | produzir o path e declarar no **tipo** se ele é emprestado ou possuído; remover só o possuído (INV-9) | o conteúdo |
| `EntregaDeArquivo` | escolher a `Response` e montar cabeçalhos | autorização, tenant, auditoria |
| **Camada de cima (controller/UseCase)** | autorização, tenant do request, auditoria, nome para download, Content-Disposition, transação | mecânica de armazenamento |

### 3.5 Ponte HTTP → núcleo

O núcleo **não conhece** `UploadedFile`. Um adaptador na borda HTTP — `FonteDeUploadHttp`, em
`App\Shared\Http\` — faz a tradução, e é **ele** que preserva INV-10:

```
UploadedFile
  → isValid() (exceções tipadas por UPLOAD_ERR_*)
  → lê tamanho, MIME e extensão ANTES de qualquer movimentação
  → move_uploaded_file() para um temporário controlado + chmod(0666 & ~umask())
  → FonteDeConteudo::deArquivoLocal($temporario, consumirOrigem: true)
  + NovoArquivo(escopo, categoria, extensao)
```

**Não é "três linhas".** `UploadedFile::move()` faz `isValid()`, `move_uploaded_file()` e o `chmod` —
um `rename()` cru perderia a prova de origem do upload e deixaria o arquivo `0600`.

Quatro razões medidas para o núcleo não depender de `UploadedFile`:

1. **4 dos 18 pontos de escrita não têm `UploadedFile`** (string HTML, string do acervo, path
   temporário do Drive, reescrita da peça) — teriam de fabricar um.
2. **`UploadedFile::move()` é justamente a primitiva que não existe em objeto remoto.**
3. **O contrato atual invalida o objeto** e não devolve metadados — bug já materializado em
   produção no Kanban.
4. **Os 5 dublês da suíte reimplementam a semântica de `move()`.**

Ler tamanho, MIME e extensão **antes** de mover, dentro do adaptador, elimina a classe de bug do
Kanban: nenhum chamador volta a tocar o `UploadedFile` depois da escrita.

**Como ficou na E2.4A.** A ponte não chama `move_uploaded_file()` nem `chmod` por conta própria:
move o upload para o temporário com o **próprio** `UploadedFile::move()`, que faz os três itens de
INV-10 e ainda respeita o modo de teste do framework (a flag `$test` é privada). O temporário é um
`ArquivoTemporarioPossuido` num diretório privado do uid efetivo (DT-7). Detalhes e provas no bloco
"E2.4A — entregue" do §10.

---

## 4. Como o LocalStorage preserva os arquivos atuais (INV-1 e INV-2)

| Categoria | Caminho hoje | Escopo físico? |
|---|---|---|
| `PASTA_DOCUMENTO` | `%uploads_dir%/<nome>` | não |
| `PASTA_IMAGEM_EDITOR` | `%uploads_dir%/<tenantId>/<nome>` | **sim** |
| `CLIENTE_DOCUMENTO` | `%clientes_uploads_dir%/<nome>` | não |
| `CHAMADO_ANEXO` | `%chamados_uploads_dir%/<nome>` | não |
| `JUSTIFICATIVA_ANEXO` | `%justificativas_uploads_dir%/<nome>` | não |
| `FOTO_PERFIL` | `%fotos_perfil_dir%/<nome>` | não (escopo semântico **global**) |
| `COBRANCA_DOCUMENTO` | `%cobrancas_uploads_dir%/<tenantId>/<nome>` | **sim** |
| `KANBAN_ANEXO` | `%kanban_uploads_dir%/<nome>` | não |
| `TAREFA_ANEXO` | `%kernel.project_dir%/public/uploads/tarefas/<sub>/<arquivo>` — **sem parâmetro próprio** (`CaminhoDeAnexoDeTarefa.php:32`) | não — **caso aberto, D5** |

`PASTA_DOCUMENTO` e `PASTA_IMAGEM_EDITOR` **dividem a mesma raiz**: a segunda mora em subpastas da
primeira. Qualquer listagem de `PASTA_DOCUMENTO` enxerga as subpastas de tenant de todos os
escritórios — é uma das razões de D7.

A coluna do banco continua guardando o mesmo `<nome>`. Depois da E2, os 7 parâmetros são consumidos
**somente** pelo resolvedor; nenhum domínio os recebe.

---

## 5. Leitura e download

Preservação exigida (INV-4): autorização acima do storage; `BinaryFileResponse` continua **na camada
HTTP**; Range Requests continuam funcionando; `Content-Type`, `Content-Disposition` e
inline/attachment idênticos; arquivo grande **não** entra inteiro em memória.

Verificado no vendor instalado (Symfony 7.4.5): `BinaryFileResponse` trata `Accept-Ranges`, `Range` e
`If-Range` sozinho (`BinaryFileResponse.php:213-280`, devolvendo 206 com `Content-Range`) e suporta
`X-Sendfile`/`X-Accel-Redirect` quando habilitado (`:218-231`, hoje desligado).
**Trocar `servir()` por um `StreamedResponse` ingênuo perderia Range de graça.** Na E2,
`EntregaDeArquivo` com backend local devolve `BinaryFileResponse` sobre o caminho materializado em
cópia zero — comportamento bit a bit igual ao de hoje, **sob INV-9**: esse path é o arquivo de
produção, e nada pode apagá-lo.

O contrato **previa** `PoliticaDeEntrega` (`SEMPRE_PELO_BACKEND` | `PERMITE_REDIRECT`), com o storage
declarando como consegue entregar. A E2.3 entregou a assinatura **sem** esse parâmetro: todas as 15
rotas passariam o mesmo valor, e nenhuma implementação o leria — a E4 o acrescenta quando houver o
que decidir. **Nenhuma implementação de redirect ou presigned entra na E2** — o
espaço existe para a E4 decidir, com o trade-off já levantado: presigned economiza banda da VPS
(egress do R2 é grátis) e serve arquivo grande e futura gravação de videoconferência, ao custo de
perder o evento de auditoria por request.

---

## 6. Temporários

Regra: **persistente é endereçado por `ChaveDeArquivo` e nunca por path; temporário é path local,
sempre apagado por quem o possui.**

| Consumidor | Precisa de path porque | Como fica na E2 |
|---|---|---|
| Ghostscript + GD (`CompressorArquivo`) | binário externo e GD só escrevem em path | **o compressor não muda de assinatura** — continua recebendo path. Quem muda é o chamador: `copiaGravavel()` → `comprimir(<temp>)` → `gravar()` na mesma chave (fatia E2.6, sob **D4**). Falha em qualquer etapa não chega ao `gravar()`, e o persistido fica intacto (INV-7) |
| **Dompdf, PhpWord (DOCX) e ODT** no export | `ExportarPecaTextoUseCase:33` reescreve as `<img>` para disco **antes** do `match` de formato (`:36-38`) — os três formatos leem imagem local | `paraLeitura()` de cada imagem referenciada; o `chroot` do Dompdf passa a apontar para o diretório que contiver os materializados. No Local, cópia zero mantém isso em `public/` |
| Upload ao Drive (`GoogleDriveClient.php:190`) | `fread` em blocos | `ReconciliadorDePasta` materializa e passa o path. **`GoogleDriveClientInterface` não muda** (§14) |
| PhpSpreadsheet **leitura** | `createReaderForFile` exige path | nada muda: já lê temporário ou path de CLI |
| PhpWord/PhpSpreadsheet **escrita** do arquivo final | — | nada muda (`php://output` / `ob_get_clean`) |

---

## 7. Tenant e escopo

O escopo é **semântico**: `ChaveDeArquivo` carrega `EscopoDeArquivo` e `CategoriaDeArquivo`. O
`ArmazenamentoLocal` **ignora** o escopo nas 7 categorias planas e o usa nas 2 com escopo físico.
**Nenhum formato físico remoto é fixado nesta E2** (**D1**).

Por que não passar `tenantId` em cada operação: seriam 40+ assinaturas com dois argumentos que
precisam casar, sem nada impedindo o descasamento — que é **silencioso** no Local e viraria 404 no
R2. Com o VO o par é inseparável.

`FOTO_PERFIL` é `global()` porque pertence ao `User`, não ao tenant — é por isso que a purga a poupa
de propósito (`PurgarEscritorioUseCase.php:344`).

---

## 8. Decisões

### Ratificadas pelo dono (2026-09-15)

| # | Decisão | Consequência |
|---|---|---|
| **D1** | `foto_perfil` pode ser `EscopoDeArquivo::global()`. **Não fixar** o formato remoto `g/{categoria}/{chave}` na E2. Para tenant, compatibilidade **conceitual** com `t/{tenant_id}/{categoria}/{chave}` | §3.1, §7 |
| **D2** | Transição **gradual com shim**: `caminho()` e `servir()` deprecated enquanto houver consumidor; suíte verde entre fatias | INV-3, §10 |
| **D3** | **`import-tmp` não muda nesta E2** — atravessa requisições e sobrevive a deploy por estar no volume persistido. Dívida técnica registrada | §6, DT-1 |
| **D4** | Compressor: cópia gravável → comprimir → gravar de volta. Só na fatia do compressor, **depois** dos testes de modo de falha | INV-7, E2.6 |
| **D5** | **Não relaxar `ChaveDeArquivo` para aceitar `/`** por causa do legado de Tarefa. Investigar na E2.7; se exigir alterar dados, Tarefa fica no mecanismo atual e a normalização vai para a E3 | §3.1, §4, E2.7 |
| **D6** | `checksum` nullable; LocalStorage não calcula SHA-256 na E2 | §3.1 |
| **Ajuste** | O núcleo não depende de `UploadedFile`; a conversão vive na borda HTTP | INV-8, §3.5 |

### Nascidas da revisão adversarial, ratificadas na sequência

| # | Decisão | Consequência |
|---|---|---|
| **D7** | **Operação por prefixo só existe quando o adapter consegue PROVAR que o prefixo físico pertence exclusivamente ao escopo.** A barreira é de **tipo** (`CategoriaComIsolamentoFisico`, 2 casos), não de runtime — a API não pode aceitar qualquer categoria e depender de o chamador lembrar quais são seguras. Nas categorias de layout plano/compartilhado: **não** usar `excluirPrefixo`; identificar os arquivos pelos **registros do tenant** e excluir um a um. Isolamento lógico não se infere de diretório fisicamente compartilhado | §3.1, §3.3, §3.4, E2.5, teste em §11.2 |
| **D8 — política de extensão, RATIFICADA em 15/09 (antes da E2.2)** | A extensão de arquivo NOVO **nunca é recusada**: extensão válida/segura é preservada; o que não serve vira `bin`; o nome do usuário continua em `nome_original`; o MIME é autoritativo para entrega quando aplicável. A revisão mediu contra os 20.954 `nome_original` reais: a versão que recusava derrubaria **18** arquivos (`açaí - 02 junho 2025`, `pdf canvelado por atraso - refeito`, …), e o único chamador que deriva extensão de dado do usuário é o sync do Drive (`ReconciliadorDePasta.php:440`). Consequência aceita: um arquivo que hoje vira `hash.açaí - 02 junho 2025` passará a `hash.bin` na E2.4. Nada se perde, mas é mudança de comportamento no sync |
| **D8** | **O storage cunha nomes opacos** para arquivo novo (`NovoArquivo(escopo, categoria, extensao)`), com entropia equivalente à atual (128 bits). A gravação distingue **novo** (storage gera) de **chave existente** (`ChaveDeArquivo` = o nome persistido no banco). Para chave legada: **sem `trim()`, sem normalização Unicode, sem mexer em espaço, sem remover ponto final, sem sanitização destrutiva, sem exigir formato hash** — as 184 da E0 continuam endereçáveis byte a byte | §3.1 (duas regras próprias), §3.2, §11.1 |
| **D9** | **Ownership e lifetime são explícitos no contrato, em dois tipos distintos.** Emprestado (`ArquivoEmprestado`, o path persistido do Local): não é propriedade, cleanup não apaga, destrutor não apaga, nunca `deleteFileAfterSend`. Possuído (`ArquivoTemporarioPossuido`): lifecycle explícito, cleanup apaga só ele. A distinção tem de ser **impossível de ignorar acidentalmente** — por isso tipos, não flag | INV-9, §3.3, §5, 4 testes em §11.2 |

### Ratificadas pelo dono antes da E2.3 (2026-09-16)

| # | Decisão | Consequência |
|---|---|---|
| **D10** | **Política de erro na entrega.** Nas rotas de visualização e download: (1) chave inválida vinda da requisição/URL → **404**; (2) chave válida, arquivo inexistente → **404**; (3) erro operacional real do storage (permissão, I/O, backend indisponível, falha inesperada) → **não** é mascarado como 404. A camada preserva a distinção entre "o arquivo não existe" e "não consegui consultar/ler o storage"; nenhum catch genérico transforma `FalhaDeArmazenamento` em 404. Autorização e permissão de negócio continuam acima do storage | `ArquivoNaoEncontrado` ≠ `FalhaDeArmazenamento` no materializador; `EntregaDeArquivo` só captura a primeira; teste de arquitetura trava o catch |
| **D11** | **Entrega endereçada por chave.** A camada de entrega recebe `ChaveDeArquivo`, nunca caminho físico pronto. `Controller → ChaveDeArquivo → EntregaDeArquivo → abstração de armazenamento → backend (Local hoje, R2 depois)`. O controller não conhece `/public/uploads`, raiz física, resolvedor local, caminho absoluto nem detalhe S3/R2; `ResolvedorDeCaminhoLocal` é detalhe do backend Local | `paraLeitura()` mora no `ArmazenamentoLocal`, que mantém o resolvedor privado; teste de arquitetura proíbe resolvedor e backend concreto fora do núcleo |

### Ratificadas pelo dono antes da E2.4B (2026-09-16)

| # | Decisão | Consequência |
|---|---|---|
| **D12** | **Falha operacional do storage ao salvar/editar peça não vira 404.** Erro de I/O ou de storage continua sendo erro operacional; nenhum catch genérico transforma essa falha em ausência | `SalvarPecaTextoUseCase`/`EditarPecaTextoUseCase` deixam `FalhaDeArmazenamento` propagar; o controller não a captura (500) |
| **D13** | **Peça ausente no export → 404.** Com chave válida e autorização aprovada, arquivo inexistente responde 404. Falha operacional ao consultar/ler o storage **não** vira 404. Ausente ≠ storage indisponível | `ler()`/`abrir()` do `ArmazenamentoLocal` passam a provar a cadeia de diretórios antes de lançar `ArquivoNaoEncontrado`; o controller captura só essa |
| **D14** | **Editar peça cujo arquivo sumiu: falhar fechado, sem recriar.** Um registro que afirma haver peça persistida, sem o arquivo correspondente, é perda ou inconsistência do acervo, e recriar esconderia isso | `EditarPecaTextoUseCase` pergunta `existe()` antes de gravar; ausência → 404 JSON com mensagem fixa e log de erro. **Muda comportamento:** antes o arquivo era recriado em silêncio |
| **D15** | **`CopiarArquivosAcervoCommand` migra; não é aposentado.** O comando não tinha teste: a cobertura nasce junto com a migração. `consumirOrigem=false` é obrigatório: o arquivo do operador é emprestado e não pode ser movido nem apagado, nem no sucesso nem na falha | `CopiarArquivosAcervoCommandTest` fotografa a origem (inode, modo, mtime, tamanho, SHA-256) antes e depois |
| **D16** | **Sync do Drive: tamanho medido, MIME do Drive.** O tamanho persistido é o do conteúdo efetivamente gravado; o `size` da API não é autoritativo quando diverge. O MIME válido informado pela API é preservado; não se infere MIME só pela extensão | `ReconciliadorDePasta` usa `ArquivoArmazenado::tamanhoBytes`, registra aviso na divergência e usa o MIME do Drive quando ele é válido |

---

## 9. Riscos e dívidas

- **R1 — o tenant errado é invisível no Local.** O `ArmazenamentoLocal` ignora o escopo em 7 das 9
  categorias, então chave com tenant errado passa despercebida na E2 e vira 404 na E4 — ou, com
  prefixo, exclusão indevida (por isso D7). **Não confiar no path resultante para provar
  isolamento.** Mitigação (§11.2): (a) testes unitários das fábricas de chave afirmando o escopo
  **contra a entidade**; (b) o dublê `ArmazenamentoEmMemoria` **materializa o escopo na chave
  interna**, então tenant errado quebra o teste funcional mesmo onde o Local seria omisso.
- **R2 — concorrência entre frentes.** Duas correções de método, ambas aprendidas errando:
  **(a)** o predicado não é "toca `Shared/`", é "toca um dos **35** arquivos da E2";
  **(b)** não basta `git diff master...<branch>`, que mostra o que a branch adicionou **desde que
  divergiu** e acusa como conflito uma branch cujo trabalho já está no master. O filtro autoritativo
  é `git cherry origin/master <branch>`: só `+` é commit realmente pendente.

  Varredura das **24 branches locais** com os dois filtros, nesta data:

  | Branch | Pendentes | Colide com a E2 |
  |---|---|---|
  | `cobranca-acompanhamento-canonico` | 23 | 🔴 `AcordoController`, `DocumentoCobrancaController`, `EnviarDocumentoUseCase`, **`PurgarEscritorioUseCase`** |
  | `import/acervo-pastas` | 3 | 🔴 `PastaController`, `SalvarPecaTextoUseCase`, `UploadPecaUseCase` |
  | `cobranca-reconciliar-data-acordo`, `expediente-ux`, `fix/*` (3), `integracao-sync-master`, `polimento-objeto-show-cabecalho`, `worktree-agent-…` | 1–7 | 🟢 nenhum alvo da E2 |
  | 13 branches, entre elas `pasta-push-processual`, `pasta-*`, `ponto-batida-nao-trava`, `sync-sistema-manda` | **0** | ✅ já no master por conteúdo — são worktrees-resto |

  **`pasta-push-processual` NÃO colide** — os 5 commits dela estão todos no master por conteúdo
  (`git cherry` devolve `-` para os cinco). Uma versão anterior desta spec a listou como colisão a
  partir de um diff de três pontos; era alarme falso, do tipo exato que o CLAUDE.md manda evitar.

  **Da E2.1 em diante a E2 vai sozinha**, e as duas frentes em vermelho precisam ser integradas ou
  explicitamente congeladas antes. Em compensação, a E2 **não tem migration**.
- **DT-1 (D3)** — `import-tmp` em `%cobrancas_uploads_dir%/import-tmp/<tenantId>/`
  (`ImportacaoController.php:268`): temporário pela intenção, persistente pelo lugar.
- **DT-2** — `CopiarArquivosAcervoCommand.php:374` carrega o arquivo inteiro em memória.
- **DT-3** — `PeticionarController.php:334` grava a URL `/uploads/pastas/<hex>` dentro do HTML da
  peça: dado persistido com formato de path.
- **DT-4** — `PecaImagemController` e `ProfileController::servirFoto` servem por tenant sem checagem
  de permissão de módulo (§1.2). Fora do escopo da E2; registrado para não virar teste falso.
- **DT-6 (nasceu na E2.1)** — a escrita atômica do `ArmazenamentoLocal` cria
  `<destino>.parcial-<hex>` no **mesmo diretório** do destino, porque `rename()` só é atômico
  dentro do mesmo sistema de arquivos. O caminho de erro apaga o temporário e há teste para
  isso, mas um processo morto entre o `fopen` e o `rename` deixa resíduo no volume persistido.
  Quem escrever a varredura da E3 precisa saber que `*.parcial-*` é lixo de escrita, não órfão.
  Desde a E2.4A o caminho rápido (origem consumível) também publica a partir do `.parcial-`
  vizinho, e o ramo de falha dupla — publicação e devolução à origem falhando juntas — deixa ali,
  de propósito, a única cópia do conteúdo: nesse caso o resíduo **não** é lixo.
- **DT-7 (nasceu na E2.4A)** — depois do `UploadedFile::move()` o PHP deixa de tratar o arquivo como
  upload e não o apaga mais no fim da requisição. Se o processo morrer entre o `move()` e a
  gravação (fatal, falta de memória, `request_terminate_timeout`), sobra um `upload-*` em
  `sys_get_temp_dir()/jusprime-upload-<uid>` — fora do backup, da purga e de qualquer varredura. O
  diretório é `0700` e conferido a cada upload (dono e modo), então o resíduo não fica legível por
  outros usuários, mas também não é limpo por ninguém. Candidato a uma limpeza por idade junto da
  varredura da E3.
- **DT-8 (achado na E2.4B, anterior à E2)** — `GoogleDriveClient::baixarArquivo` usa o cliente
  autorizado do Google, que herda `'http_errors' => false` (`vendor/google/apiclient/src/Client.php`,
  `AuthHandler/Guzzle6AuthHandler.php`). Um 403 (cota, arquivo bloqueado), 404 ou 5xx no download
  **não lança**: o corpo do erro vai para o `sink` e é gravado como se fosse o arquivo do cliente,
  com o `drive_file_id` preenchido — e a idempotência por esse id impede que ele seja baixado de
  novo. Lido no vendor, não executado. A E2.4B não piora (o tamanho gravado passa a ser o do corpo
  recebido e a divergência gera `[aviso]`), mas não corrige: o arquivo está fora dos seis pontos.
  A Via B só roda com `--modo=importar|ambos`. **Decisão do dono** (ver o bloco da E2.4B).
- **DT-5** — 3 docblocks em `app/src/` citam `ArquivoStorageInterface` pelo nome
  (`AcordoDocumento.php:20`, `CarteiraDocumento.php:21`, `ArquivosDeAnexoDoKanban.php:18`) e ficam
  obsoletos quando a interface sair na E2.8.

---

## 10. Fatias

Cada fatia termina com **suíte completa verde na frente** (`scripts/frente-testar.sh
e2-abstracao-storage`) e é integrável sozinha (D2). Risco ALTO: `/review` explícito antes de
integrar.

| Fatia | Entregável | Arquivos | Prova |
|---|---|---|---|
| **E2.0** | esta spec + registro da frente | 2 docs | revisão adversarial feita |
| **E2.1** | VOs, `ArmazenamentoDeArquivos`, `ArmazenamentoComPrefixo` (contrato só), `ArmazenamentoLocal`, `ResolvedorDeCaminhoLocal`, `ArmazenamentoEmMemoria`. `ArquivoStorageInterface` e `ArquivoStorageService` **intactos e ainda ligados** aos 33 consumidores; o `ArmazenamentoLocal` implementa **só a interface nova** | só arquivos novos | suíte verde sem tocar consumidor + `MapaDeChaveParaCaminhoLocalTest` + suíte de contrato |
| **E2.2** — ✅ **entregue em 15/09** | fábricas de chave por domínio (`app/src/<Dominio>/Armazenamento/ChavesDe*`, 7 classes); `existe()` migrado em **26 chamadas / 21 arquivos**; **`ArquivosDeAnexoDoKanban::diretorio()` removido**. Fora, por decisão do dono: `PurgarEscritorioUseCase` (a 27ª chamada) fica **inteiro** para a E2.5; "tamanho" **não tem consumidor** nesta fatia (`metadados()` já existe desde a E2.1; os 3 `filesize()` seguem nas fatias previstas). Detalhes no bloco "E2.2 — entregue", abaixo | 21 + 7 fábricas | testes de fábrica (categoria, tenant da entidade, tenant nulo, nome byte a byte, sem parâmetro de tenant, este por reflexão) + materialização do escopo nos UseCases contra `ArmazenamentoEmMemoria` + 404 nas 2 rotas com nome pela URL + reconciliador com arquivo ausente, com nome inválido e com disco ilegível + exclusão de seção com disco ilegível pós-commit; **8 provas por reintrodução de defeito**; revisão adversarial feita e os 6 achados de código corrigidos |
| **E2.3** — ✅ **entregue em 16/09** | `MaterializadorDeArquivo::paraLeitura()` (Local, cópia zero) + `EntregaDeArquivo` + as **15 rotas** de `servir()` migradas; `ArquivosDeAnexoDoKanban::caminhoDe()` privado. Detalhes no bloco "E2.3 — entregue", abaixo | 11 controllers + 2 classes novas (`MaterializadorDeArquivo`, `EntregaDeArquivo`) + 2 alteradas (`ArmazenamentoLocal`, `ArquivosDeAnexoDoKanban`) | equivalência de cabeçalhos com o `servir()` antigo + `Range` → 206 + INV-9 #3 + arquivo de 64 MB sem ir para a memória + D10 (ausente → 404, pane ≠ 404, inclusive pela rota) + teste de arquitetura; 14 provas por reintrodução; revisão adversarial feita, sem bloqueante, achados corrigidos |
| **E2.4A** — ✅ **entregue em 16/09** | os **14 uploads HTTP** (`salvar(UploadedFile)`) pela ponte `FonteDeUploadHttp` + `gravar(NovoArquivo)`, com fábricas `ChavesDe*::novo*`; publicação em dois passos no `ArmazenamentoLocal`. Detalhes no bloco "E2.4A — entregue", abaixo | 13 consumidores + ponte + 7 fábricas + backend | unit/funcional de cada ponto (válido, inválido, R1 pela rota contra dublê em memória, falha do storage sem linha, cleanup do flush onde existe) + INV-10 + testes de arquitetura; 22 provas por reintrodução; revisão (4) e re-revisão feitas |
| **E2.4B** — ✅ **entregue em 16/09** | as **4 escritas internas** — `SalvarPecaTextoUseCase`, `CopiarArquivosAcervoCommand`, `ReconciliadorDePasta`, `EditarPecaTextoUseCase` (sobrescrita por chave) — e as leituras em `ArquivosReferenciadosEmPecas`/`ExportarPecaTextoUseCase`; `ler()`/`abrir()` do backend provam a cadeia de diretórios (D13). Detalhes no bloco "E2.4B — entregue", abaixo | 6 + `PeticionarController` + backend + contrato | unit e funcional de cada ponto (os 12 itens pedidos pelo dono) + primeiro teste do comando do acervo + guarda estrutural do shim; 31 provas por reintrodução; revisão (4) e re-revisão feitas |
| **E2.5** | `excluir()` (19 chamadas) + `ArmazenamentoComPrefixo` nas 2 categorias com escopo físico | 15 | purga + isolamento cross-tenant + allowlist do arch test |
| **E2.6** | `MaterializadorDeArquivo::copiaGravavel()` (o `paraLeitura()` já existe desde a E2.3); os 4 chamadores do compressor, o export e o `ReconciliadorDePasta` | 6 (todos já entre os 33) | **pré-requisito D4**: testes de modo de falha do compressor verdes **antes** |
| **E2.7** | investigação de Tarefa e, se viável sem migration, entrada na abstração (**D5**) | `TarefaController`, `CaminhoDeAnexoDeTarefa` | teste de travessia atacando o VO, não a rota |
| **E2.8** | remoção de `caminho()`/`servir()`; docblocks de DT-5; teste de arquitetura | limpeza | suíte + arch test |

**E2.2 — entregue em 15/09/2026.** O que foi decidido e feito ao executar:

- **A purga fica inteira para a E2.5.** O `existe()` de `PurgarEscritorioUseCase:363` mistura nomes
  de sete tabelas, duas sem tradução para chave (`tarefa_mensagem`, com `/`, e `documento_processo`,
  sem categoria); está acoplado ao `excluir()` no mesmo laço; roda pós-commit **sem `try/catch`**, e o
  `existe()` novo lança em diretório ilegível; e o laço de hoje **não inclui** `kanbanUploadsDir`,
  então uma conversão fiel por categoria mudaria comportamento. Nada ali foi tocado. Contagem efetiva
  da fatia: **21 arquivos, 26 chamadas**.
- **"Tamanho" sem consumidor.** `MetadadosDeArquivo`/`metadados()` vieram na E2.1 e nenhum dos 21
  arquivos lê tamanho do disco. Os `filesize()` de `CompressorArquivo`, `GoogleDriveClient` e
  `CopiarArquivosAcervoCommand` ficam onde a spec já os colocou (E2.6 e categoria C).
- **Fábricas em `app/src/<Dominio>/Armazenamento/`**, uma classe final por domínio: `ChavesDePasta`,
  `ChavesDeCliente`, `ChavesDeServiceDesk` (primeiro arquivo de `App\ServiceDesk\` — o domínio ainda
  mora em `src/Entity/ServiceDesk/`, e arquivo novo não entra em pasta legada), `ChavesDePonto`,
  `ChavesDeCobranca`, `ChavesDeKanban`, `ChavesDePerfil`. Recebem a entidade e tiram dela tenant,
  categoria e nome; falham com `ChaveDeArquivoInvalida` se o tenant obrigatório estiver nulo ou sem
  id; `ChamadoAnexo` chega ao tenant pelo `Chamado`, como no modelo. Nenhuma recebe `Tenant` por
  parâmetro — os testes afirmam isso por reflexão. Onde há `$tenant` por parâmetro e tenant na
  entidade (UseCases de Cobrança, lote do ponto), a chave usa a **entidade**. Três formas sem
  entidade, documentadas como exceção: `ChavesDePasta::documentoPorNome(int, string)` para projeção
  escalar já filtrada por tenant (`ArquivosReferenciadosEmPecas`, `ReconciliadorDePasta`);
  `ChavesDePasta::imagemDoEditor(Tenant, string)` para a imagem sem linha no banco; e
  `ChavesDePonto::anexoDeJustificativaPorNome(JustificativaPonto, string)` para o lote, cujo anexo
  antigo sai por projeção sob a trava e cujo anexo novo ainda não foi persistido no rollback.
- **Estado misto, de propósito (D2):** presença por chave no armazenamento novo; `caminho()`,
  `servir()`, `excluir()` e `salvar()` continuam na interface antiga. Em Cobrança o diretório do
  `excluir()` segue `cobrancasUploadsDir/<tenant do parâmetro>`, exatamente como antes. O
  `KanbanAnexoController` pergunta a presença ao serviço `ArquivosDeAnexoDoKanban::existe()`, que
  substituiu o `diretorio()` removido.
- **Nome que `ChaveDeArquivo` recusa — medido em PRODUÇÃO em 15/09, não inferido.** As nove colunas
  da E2.2 em prod: `pasta_documento` 22.676 linhas, `justificativa_ponto` 61, `user_profiles` 9,
  `cliente_documento` 4 — **22.750 chaves reais, zero recusável** (nenhuma vazia, com `/`, `\`,
  `..`, byte nulo ou caractere de controle). As outras cinco (`chamado_anexo`, `cobranca_documento`,
  `cobranca_acordo_documento`, `cobranca_carteira_documento`, `kanban_anexo`) têm **0 linhas em
  prod**: ali "zero recusável" é vácuo, não medição. Confirmadas também as 165 chaves terminadas em
  `.` e as 3 com espaço na borda de `pasta_documento` — as que um `trim()` tornaria inalcançáveis
  (D8). Fora da E2.2: `tarefa_mensagem` tem 12 anexos, todos com `/` (é o caso reservado à E2.7 por
  D5), e `documento_processo` está vazia.

  Onde o nome vem da **URL** (`PecaImagemController`, `ProfileController::servirFoto`): **404**, com
  teste e prova por reintrodução. Onde é varredura (`ArquivosReferenciadosEmPecas`,
  `ReconciliadorDePasta`): o item é pulado / conta erro, e a rodada segue.

  ⚠️ **Nos demais pontos a recusa PROPAGA, e isso é mudança de comportamento** — improvável, não
  impossível: o único nome derivado de dado do usuário é o do sync do Drive
  (`ReconciliadorDePasta`, via `pathinfo(...)`, que devolve `\` e caractere de controle se
  estiverem no nome do arquivo no Drive). São **13 rotas de download** (500 onde antes era 404 ou
  flash+redirect) e os **UseCases de exclusão**, que passam a **abortar** a operação inteira onde
  antes pulavam o arquivo e removiam a linha: `ExcluirDocumento{,Acordo,Carteira}UseCase`,
  `ExcluirSecaoUseCase` (Cobrança), `ExcluirPastaUseCase` (**dentro** da transação → rollback, a
  pasta não é excluída), `ArquivosDeAnexoDoKanban::removerDoAnexo` (e com ele `ExcluirAnexoUseCase`,
  `ExcluirCardUseCase`, `ExcluirBoardUseCase`) e `PastaSecaoController::coletarArquivosDaArvore`
  (roda **antes** do UseCase). Todos falham **fechado**: nada é excluído do banco. A política de
  entrega fica reservada à E2.3 (`EntregaDeArquivo`).
- **Falha de I/O (`FalhaDeArmazenamento`) onde a decisão já foi tomada.** O `existe()` novo lança
  quando não consegue **determinar** a presença (diretório ilegível), onde o antigo devolvia false.
  Nos dois pontos em que isso aconteceria **depois** de o banco já ter mudado, ou numa varredura em
  lote, a exceção é absorvida e registrada, preservando o comportamento anterior — cada um com
  teste que torna o diretório ilegível de verdade (não dublê) e prova por reintrodução:
  `PastaSecaoController::excluir` (laço pós-commit: a seção já foi apagada, 500 seria erro sem
  reparo possível) e `ReconciliadorDePasta` (uma rodada de cron não pode morrer inteira, sem
  contabilizar nada e sem marcar `fatal`, por causa de um documento). Nos pontos **antes** da
  mudança de banco a propagação fica: falhar fechado ali é melhor que apagar linha às cegas.
- `ProdutoresDeAnexoPathTest` ganhou `ChavesDePonto` na allowlist de leitores de `anexo_path`: a
  fábrica lê o getter só para montar a chave e nunca copia o valor para outro registro.
- `ServirFotoControllerTest` deixou de trocar o storage antigo por um dublê num diretório
  temporário: com a presença perguntada ao armazenamento novo, os dois storages precisam enxergar o
  mesmo lugar, e o teste passou a gravar em `fotos_perfil_dir`.
- **O que a materialização do escopo prova, e o que não prova.** Os testes de exclusão contra
  `ArmazenamentoEmMemoria` (documento do escritório 99 invisível para o 7) provam que a chave
  carrega o escopo — o que o disco plano de hoje esconderia. Eles **não** distinguem "escopo da
  entidade" de "escopo do parâmetro", porque a guarda `getTenant() !== $tenant` obriga os dois a
  serem iguais; essa distinção é provada nos testes de reflexão das fábricas, que afirmam que
  nenhuma aceita `Tenant` por parâmetro.
- **No `ReconciliadorDePasta` o escopo sai do `tenant_id` do DOCUMENTO**, não do da pasta. Em dados
  é o mesmo (medido em `saas_ux`: 0 de 20.954 divergindo), mas quem responde pela linha é ela.

**E2.3 — entregue em 16/09/2026.** O que foi decidido e feito ao executar:

- **Escopo conferido antes de escrever:** 15 chamadas de `servir()` em 11 controllers, exatamente
  a contagem da spec. As duas entregas cruas do `TarefaController` ficam na E2.7 (D5); o
  `StreamedResponse` do ponto é exportação gerada, não arquivo persistido.
- **Ajuste de ordenação: o materializador de leitura entrou aqui, não na E2.6.** A entrega por
  chave precisa de um caminho real, e o §5 já dizia que ela seria montada "sobre o caminho
  materializado em cópia zero". Entrou só `paraLeitura()`; `copiaGravavel()` continua na E2.6.
  `paraLeitura()` mora no `ArmazenamentoLocal` para o resolvedor seguir privado (D11).
- **Ausente ≠ ilegível (D10), no próprio backend.** Sem arquivo, o `paraLeitura()` primeiro prova
  que dava para olhar a cadeia de diretórios e só então lança `ArquivoNaoEncontrado`; diretório
  ilegível ou arquivo presente e ilegível lançam `FalhaDeArmazenamento`. A `EntregaDeArquivo` só
  transforma a primeira em 404; a segunda passa direto e vira 500 na rota. **Qual barreira cada
  teste de rota exercita** (achado da revisão): com o **diretório** ilegível, e com o arquivo
  **ausente**, quem responde é a checagem de presença que o controller faz antes (E2.2) — a entrega
  nem é alcançada. A prova de D10 **pela entrega**, via HTTP, é o arquivo **presente e ilegível**:
  a checagem diz "existe", o materializador lança, e a rota responde 500. Com a entrega
  capturando `FalhaDeArmazenamento` como 404, só esse teste de rota falha — medido. O 404 da
  própria entrega (ausente) é provado no teste unitário, porque pela rota ele só aparece na janela
  entre a checagem e o envio.
- **Risco residual de D10(3) no disco local:** `is_file()` não distingue "não existe" de erro de
  I/O no próprio arquivo (EIO, ESTALE, ELOOP). Com a cadeia de diretórios legível, esses erros
  ainda viram `ArquivoNaoEncontrado` → 404. Não é regressão — o `file_exists()` antigo fazia o
  mesmo, e o `existe()` da E2.1 também —, mas é um limite conhecido da distinção.
- **Equivalência com o `servir()` antigo, não só "parece igual".** Para oito casos (PDF e PNG,
  inline e anexo, nome com acento, com `%`, hash como nome, chave legada sem extensão) os
  cabeçalhos `Content-Type`, `Content-Disposition`, `Accept-Ranges`, `Content-Length`,
  `Last-Modified`, `Cache-Control` e `ETag`, o status e o corpo são idênticos entre as duas
  implementações. O serviço antigo continua existindo para essa comparação até a E2.8.
- **INV-9:** a entrega nunca liga `deleteFileAfterSend` (teste por reflexão e teste de
  arquitetura), e o arquivo persistido continua lá, com o mesmo conteúdo, depois de
  `sendContent()`. **INV-9 #4 não tem conteúdo na cópia zero:** `paraLeitura()` não escreve
  nada, então não há como afetar o original. O teste que existe só trava essa propriedade (se a
  cópia zero virar cópia, ele passa a provar algo); **a obrigação real — disco cheio, origem
  sumida, falha no meio da cópia — é da `copiaGravavel()`, na E2.6**. **Temporário possuído não
  se aplica nesta fatia:** o
  materializador devolve só `ArquivoEmprestado`, então a entrega não tem como receber um
  possuído; o ciclo de vida dele segue provado pelos testes da E2.1.
- **Memória:** arquivo esparso de 64 MB enviado por inteiro com pico abaixo de 8 MB — a resposta
  sai em blocos, como antes.
- **Os controllers mantiveram a checagem de presença da E2.2** antes de entregar. Duas rotas
  (download de documento da pasta e do cliente) respondem arquivo ausente com aviso e
  redirecionamento, não com 404, e as mensagens de cada rota continuam as mesmas. O 404 da
  própria entrega cobre a janela entre a checagem e o envio.
- **Seis controllers deixaram de conhecer disco por completo:** `PecaImagemController`,
  `ProfileController`, `KanbanAnexoController` e os três de Cobrança perderam o storage antigo; os
  cinco que recebiam parâmetro de diretório o perderam também (o do Kanban nunca teve — o
  diretório dele mora no serviço `ArquivosDeAnexoDoKanban`). Os outros cinco ainda os usam para gravar e excluir (E2.4 e E2.5). As
  três ações de download de Cobrança passaram a declarar `Response`, não `BinaryFileResponse`.
  `ArquivosDeAnexoDoKanban::caminhoDe()` virou privado — era o wrapper que o critério de aceite
  1 (§12) mandava fechar, e o único uso que sobrou é a remoção interna.
- **Cobertura que não existia:** sete das quinze rotas não tinham nenhum teste de sucesso — as
  quatro de documento da pasta, as duas de documento do cliente e o atestado do colaborador, que
  não tinha teste algum. Ganharam `EntregaDeArquivoRotasTest`, escrito **antes** da migração e
  verde contra o código antigo. As outras oito ganharam a asserção exata de disposição e nome — a
  da imagem do editor só depois da revisão, que apontou que ela tinha ficado de fora; agora ela
  confere também `Content-Type: image/png`, `Accept-Ranges` e o corpo, com um PNG de verdade.
- **Interpretação de D10 registrada:** o item (1) fala de chave inválida vinda da requisição ou
  URL, e é assim nas duas rotas em que o nome vem da URL. Nas 13 rotas em que o nome vem do banco,
  uma chave recusada continua **propagando** (500): não é "arquivo ausente", é dado que o sistema
  nunca gravou (medido em prod: zero em 22.750 chaves), e mascará-lo iria contra o item (3).
- **Teste de arquitetura estreito já nesta fatia** (`EntregaDeArquivoArquiteturaTest`): nenhum
  `->servir(` em `src/`; arquivo despejado (`BinaryFileResponse`, `file()`, `readfile()`,
  `fpassthru()`) só pela entrega — exceções datadas: `TarefaController` até a E2.7,
  `ArquivoStorageService` até a E2.8; resolvedor e backend concreto invisíveis fora do núcleo;
  **só a entrega injeta o materializador** (que devolve caminho físico — a porta pela qual um
  controller voltaria a conhecer disco; a E2.6 amplia a lista com justificativa); nenhum controller
  captura `FalhaDeArmazenamento`; entrega sem `deleteFileAfterSend` e com um único catch. Não pega
  `StreamedResponse` montado à mão — hoje o único do sistema é exportação gerada. O teste amplo do
  §11.3 continua sendo da E2.8.
- **O teste de rota que torna o diretório ilegível** faz `chmod` no diretório **compartilhado** de
  uploads de teste e restaura em `finally` (mesmo padrão do `PastaSecaoControllerTest` da E2.2).
  Duas suítes simultâneas na mesma worktree podem colidir nessa janela.
- ✅ **`app/src/Shared/CLAUDE.md` ensinava `servir()`**, que o teste de arquitetura agora
  proíbe, e não listava `Http/`. A atualização, proposta ao dono nesta fatia (critério 8 do §12),
  foi autorizada e aplicada na E2.4A.

**E2.4A — entregue em 16/09/2026.** A E2.4 foi dividida: **E2.4A** = os 14 uploads HTTP;
**E2.4B** = as 4 escritas internas (`SalvarPecaTextoUseCase`, `CopiarArquivosAcervoCommand`,
`ReconciliadorDePasta`, `EditarPecaTextoUseCase`) e as leituras de `ArquivosReferenciadosEmPecas` /
`ExportarPecaTextoUseCase`, que não foram tocadas. O que foi decidido e feito ao executar:

- **Escopo conferido antes de escrever:** 14 chamadas de `salvar(UploadedFile)` em **13 arquivos**
  (o `PastaController` tem duas), exatamente a contagem do §1.2. Fora delas, `UploadedFile::move()`
  só em `TarefaController` (E2.7) e `ImportacaoController` (`import-tmp`, D3).
- **A ponte é `App\Shared\Http\FonteDeUploadHttp`**, em duas etapas: `de(UploadedFile)` confere
  `isValid()` e lê a extensão (`guessExtension() ?? 'bin'`, a mesma regra do `salvar()` antigo)
  **antes** de qualquer movimentação; `gravarEm(ArmazenamentoDeArquivos, NovoArquivo)` move o
  upload para um `ArquivoTemporarioPossuido` **com o próprio `UploadedFile::move()`** e grava com
  `consumirOrigem: true`, liberando o temporário em `finally`. O temporário mora em
  `sys_get_temp_dir()/jusprime-upload-<uid efetivo>`, criado `0700` (o mesmo sistema de arquivos do
  upload do PHP, então o `move()` é um `rename()` barato) — ver DT-7. "Privado" é conferido: o
  diretório tem de ser do uid efetivo e sem permissão para grupo/outros, e o temporário tem de nascer
  nele (o `tempnam()` cai em silêncio no `/tmp` quando não consegue escrever); qualquer desvio lança,
  **antes** de o upload sair do lugar. Reimplementar o `move()` perderia o
  modo de teste (a flag `$test` é privada) e copiaria código do framework; delegar preserva os três
  itens de INV-10 por construção. Upload inválido delega ao `move()` só para lançar a exceção
  tipada — nada é lido nem movido antes dela. Mudança observável: nenhuma nos 14 pontos, porque em
  todos a validação de MIME/tamanho (ou o `FileValidator`) já recusava upload com erro antes do
  `salvar()`; na ponte isolada, upload com erro passa a dar sempre a exceção tipada, onde antes o
  `guessExtension()` estourava primeiro com a do componente Mime.
- **A prova de origem tem duas barreiras** (o `isValid()` de `de()` e o `move()` de `gravarEm()`, que
  também chama `is_uploaded_file()`). Medido: tirar só uma deixa o teste verde; o teste cai quando as
  duas somem. Registrado no docblock do teste para ninguém "simplificar" uma delas.
- **Fábricas de arquivo novo nos domínios (`ChavesDe*::novo*`)**, par de escrita das fábricas da E2.2.
  O escopo sai de onde a **leitura** vai tirá-lo depois: caso/acordo/carteira (a guarda do UseCase
  iguala ao `$tenant` que o documento recebe), card (o anexo copia no construtor), chamado (a
  leitura passa por ele), cliente (o documento copia), justificativa dona (lote) e, em Pasta, o
  **próprio documento** — criado e com escritório atribuído antes da gravação, persistido depois.
  A primeira versão tirava o escopo da pasta; a revisão apontou que o documento recebe o escritório
  da sessão e que só o TenantFilter iguala os dois. Com o documento como fonte, gravação e leitura
  usam o mesmo getter, e sessão sem escritório falha **antes** de gravar (antes: 500 no flush, com
  órfão).
  Duas exceções recebem `Tenant`, documentadas: `ChavesDePasta::novaImagemDoEditor()` (sem linha no
  banco, mesmo endereçamento da leitura) e `ChavesDePonto::novoAnexoDeLote()` (o arquivo nasce
  **antes** das N justificativas — ordem da E1 —, e o tenant é a mesma variável que vai para o
  `setTenant()` de cada uma). `ChavesDePerfil::novaFoto()` é global (D1). O inventário é travado por
  `FabricasDeArquivoNovoTest` (abaixo).
- **Só a gravação mudou.** Ordem de validação, persistência e cleanup idêntica em todos os pontos.
  Onde o arquivo ainda precisa de `caminho()` (compressor: Cliente, Pasta ×2, `UploadPecaUseCase`,
  `EnviarDocumentoUseCase`) ou de `excluir(caminho())` (cleanup do flush nos dois controllers de
  Ponto e no lote; foto anterior do perfil), a interface antiga continua injetada e recebe o nome
  cunhado — são da E2.6 e da E2.5. As 19 chamadas de `excluir()` da E2.5 seguem intactas.
  Largaram o storage antigo e o parâmetro de diretório: `EnviarDocumentoAcordoUseCase`,
  `EnviarDocumentoCarteiraUseCase`, `AdicionarAnexoUseCase`, `UploadImagemEditorUseCase`,
  `ServiceDeskController` e `PeticionarController` (que perdeu `$uploadsDir`; o DTO
  `UploadImagemEditorInput` leva o `Tenant` em vez do diretório já montado).
- **Defeito vivo corrigido de carona — ServiceDesk.** `processarAnexos` lia `$arquivo->getSize()`
  **depois** do `move()`: "stat failed", todo chamado com anexo terminava em 500 e deixava órfão
  (mesma classe do bug do Kanban; em prod `chamado_anexo` tem 0 linhas). O tamanho passou a vir de
  `ArquivoArmazenado::tamanhoBytes`. O MIME gravado continua o informado pelo cliente
  (`getClientMimeType()`), como antes.
- **Ajuste no backend — a publicação é sempre um `rename()` vizinho.** Medido no container: entre
  sistemas de arquivos o `rename()` do PHP **não falha** com EXDEV; copia por dentro, direto no nome
  que recebeu, e devolve `true` (`/tmp` no dispositivo 100, `var/` no 2096). O comentário da E2.1
  supunha o contrário. Para o upload isso é o caso normal de produção (o PHP guarda o upload em
  `/tmp`, os uploads moram num volume): com `rename($origem, $destino)`, a cópia não é atômica — um
  parcial com nome final sobraria num processo morto, fora do alcance da varredura de DT-6, e numa
  sobrescrita por chave (E2.6) o inode publicado seria truncado no lugar. O caminho rápido passou a
  ser `publicarMovendo()`: `rename()` da origem para um `.parcial-` vizinho (onde a eventual cópia
  acontece sob nome temporário) e, dali, para o destino, no mesmo diretório. O modo é acertado no
  vizinho, **antes** de o arquivo ficar visível (nos dois caminhos). Se o segundo passo falhar, o
  conteúdo volta para a origem (provado com um diretório no lugar do destino: a gravação falha, a
  origem fica intacta e não sobra `.parcial-`); se nem a devolução funcionar, o conteúdo fica no
  vizinho e a exceção diz onde — **nunca é apagado**: nesse ramo, raríssimo, conteúdo vale mais que
  limpeza, e o resíduo é o `.parcial-` que a varredura de DT-6 já reconhece. A primeira versão perguntava o dispositivo (`st_dev`); a revisão lembrou que dois pontos
  de montagem do mesmo sistema de arquivos têm o mesmo `st_dev` e ainda dão EXDEV, e os dois passos
  dispensam a heurística. O `move_uploaded_file` antigo tinha a mesma cópia não atômica — não era
  regressão, mas a E2.4A é quem passa a rotear produção por esse atalho. Provado com vínculo físico
  (o `link()` enxerga o inode antigo; a cópia no lugar o reescreve; a origem vem de
  `sys_get_temp_dir()` ou `/dev/shm`, o primeiro que estiver em outro dispositivo que `var/`) e com
  o inode preservado no mesmo dispositivo.
- **Cleanup em falha de flush: só onde já existia.** Nove pontos não removem o arquivo novo se o
  banco recusar (Cobrança ×3, Kanban, Perfil, Cliente, ServiceDesk, Pasta ×2, `UploadPecaUseCase`) —
  órfão recuperável, aceito por INV-6 e pré-existente. Acrescentar esse cleanup é `excluir()`, e fica
  para a E2.5, junto da política de `FalhaDeArmazenamento` no caminho de erro (o `excluir()` novo
  lança; o antigo só emitia warning em produção).
- **Cobertura que não existia:** upload com arquivo real em Cliente, Pasta (`uploadDocumento`),
  ServiceDesk e nas duas portas de criação de justificativa com atestado, incluindo o cleanup do
  catch do flush nos dois controllers de Ponto, que nunca tinha sido exercitado (falha injetada por
  um listener `onFlush`, que fotografa o anexo e prova que o arquivo existia na hora da recusa). Os
  unitários trocaram `salvar()` mockado por `ArmazenamentoEmMemoria` + `UploadedFile` real, e
  afirmam escopo e categoria da chave gravada (R1). O dublê ganhou `falhaAoGravar` (o "dublê que
  lança em `gravar()`" do §11.2), `gravadas` e `ultimaGravada()`.
- **R1 e "falha do storage → nenhuma linha" provados pela ROTA nos seis pontos que são controller**
  (§11.2 pede os funcionais contra o dublê em memória — a primeira versão os tinha só contra o disco
  plano, onde o escopo não aparece). `ArmazenamentoEmMemoriaNoContainer` substitui o serviço
  **concreto** `ArmazenamentoLocal` no container de teste: trocar só o alias da interface não chega
  aos controllers (o container resolve o alias na compilação), e o mesmo serviço é o materializador
  da `EntregaDeArquivo` — por isso o dublê implementa as duas interfaces. A asserção é a mais forte
  disponível: a chave gravada é **igual** à que a fábrica de leitura monta a partir do registro
  persistido. No administrador do Ponto, a sessão fica num escritório e a URL em outro — é o caso em
  que tirar o escopo da sessão passaria calado. Os 500 de falha conferem a mensagem da falha
  injetada, para não provarem outra barreira. O lote (`SubstituirAnexoDoLoteUseCase`) prova o mesmo
  no unitário, com um espião sobre o backend real, e ganhou o caso de falha do storage na fase 1.
- **Testes de arquitetura.** `UploadPorChaveArquiteturaTest`: ninguém chama o `salvar()` antigo
  (duas redes: `storage->salvar(` e a assinatura `->salvar($x, $...dir)`); `->move(` só na ponte e
  nas três exceções datadas (`ArquivoStorageService` até a E2.8, `ImportacaoController` por D3,
  `TarefaController` até a E2.7); ninguém chama `move_uploaded_file()` direto.
  `FabricasDeArquivoNovoTest`: descobre as fábricas em `src/*/Armazenamento/ChavesDe*.php`, confere
  cada `novo*` contra a dona, recusa escritório recebido por fora (tipo `Tenant`, anulável, união ou
  parâmetro com "tenant" no nome) fora das duas exceções, e proíbe `new NovoArquivo(` fora das
  fábricas e do núcleo — senão a rede toda seria contornável.
- **Provas por reintrodução (22 sobre o código final):** `rename()` direto para o destino (vínculo
  físico); ponte sem `isValid()`; ponte sem as duas barreiras de origem; ServiceDesk lendo
  `getSize()` depois de mover; escopo global no acordo; falha do storage engolida no Kanban; sem
  cleanup no `PontoController`; sem cleanup no `TenantController`; sem `removerBestEffort` no lote;
  categoria trocada na peça; imagem do editor em categoria plana; `salvar()`/`move()` reintroduzidos;
  administrador tirando o escopo da sessão; `PastaController` com escopo de outro escritório (caem o
  R1 da rota **e** o guarda de `new NovoArquivo(`); `salvar()` antigo por outra propriedade;
  `UploadPecaUseCase` lendo `getSize()` depois de gravar; ServiceDesk com escopo global; ponte sem a
  checagem de diretório privado; ponte aceitando `tempnam()` desviado; backend sem devolver a origem
  quando a publicação falha; `new NovoArquivo` escondido atrás de apelido (`use … as`); `salvar()`
  antigo com diretório concatenado. Todas derrubaram o teste que diziam derrubar. **Uma prova pegou um teste vazio:** o guarda de
  `new NovoArquivo(` tinha um escape de aspas simples do PHP que transformava `\\?` em `?` literal e
  passava verde com a violação no código; corrigido e provado de novo.
- **Revisão:** quatro revisores (arquitetura, consistência banco × disco, segurança/tenant, testes)
  e uma re-revisão focada nas correções. Nenhum bloqueante. O que mudou por causa deles: R1 e falha
  do storage provados pela rota; escopo de Pasta pelo documento; publicação em dois passos; modo antes
  de publicar; diretório privado conferido; inventário das fábricas por descoberta; testes vazios
  retirados ou reforçados (o guarda de `NovoArquivo` e o teste de "não sobra temporário").
- **Riscos residuais registrados, sem mudança de comportamento nesta fatia:**
  - o lote de justificativas continua gravando **dentro** da transação, sob a trava — irrelevante
    com disco local, transação longa com backend remoto (E4);
  - ⚠️ **para a E2.5 — ambiguidade de COMMIT nos três cleanups de Ponto** (`PontoController`,
    `TenantController`, `SubstituirAnexoDoLoteUseCase`, herdados da E1): o catch apaga o arquivo novo
    em qualquer `Throwable` do flush/commit, inclusive quando o COMMIT chegou ao banco e só a
    resposta se perdeu (queda de conexão). Aí sobra registro válido apontando para arquivo
    inexistente — o que INV-6 proíbe. É raro e anterior à E2, e a E2.4A não mudou nada disso; a
    E2.5, que migra esses cleanups para `excluir()` por chave, é o lugar de decidir a política
    (recontar as referências numa conexão nova antes de apagar; na dúvida, deixar o órfão);
  - os funcionais que limpam "tudo o que surgiu no diretório durante a requisição" supõem **uma
    suíte por worktree** (a mesma restrição já registrada para os testes de `chmod` da E2.3);
  - o anexo de chamado passa a funcionar em produção pela primeira vez (smoke: abrir chamado com
    anexo e baixá-lo).

**E2.4B — entregue em 16/09/2026.** As 4 escritas internas e as 2 leituras, sob D12–D16. O que foi
decidido e feito ao executar:

- **Escopo conferido antes de escrever** (quatro investigações em paralelo): 2 `salvarConteudo` + 1
  `moverParaArmazenamento` + 1 `file_put_contents` = 4 escritas; 2 `file_get_contents` sobre
  `caminho()` = 2 leituras. Nenhum escritor interno fora da lista. Dos 26 `caminho()` que restavam,
  3 saíram; sobram 6 da E2.6 e 17 ligados a `excluir()`. As 19 `excluir()` seguem intactas.
- **Núcleo: `ler()` e `abrir()` provam a cadeia de diretórios antes de responder "não encontrado"**
  (`ArmazenamentoLocal::exigirArquivoPresente`, o mesmo caminho do `paraLeitura()`). Antes só
  perguntavam `is_file()`: com um diretório ilegível, o export responderia 404 a uma pane. Os únicos
  chamadores em `src/` são os dois desta fatia. A regra foi para o docblock do contrato — um backend
  remoto que confunda "não existe" com "não posso ver" (o 403 do S3 sem `ListBucket`) terá de
  respeitá-la. Limite conhecido, herdado de `existe()`: a prova exige `r` em cada ancestral, então
  um diretório `0711` dá 500 em vez de 404 (falha fechada).
- **Peça nova.** O documento recebe o escritório, o título e o nome original; o storage grava e
  cunha o nome; só então o documento é completado e persistido. `FalhaDeArmazenamento` propaga antes
  do `persist` (500, D12) — antes, o `file_put_contents` falhava calado em produção e a peça era
  salva apontando para arquivo ausente. O MIME continua `text/html` fixo: medido no container, o
  libmagic devolve `text/plain` para fragmentos (`<p>`, `<div>`, `<img>`) e `text/html` só com
  `<html>`, `<!DOCTYPE>` ou `<table>`; editar, exportar e a listagem de peças decidem por
  `text/html`. O tamanho vem do `ArquivoArmazenado`.
- **Título que não cabe na coluna é recusado antes de gravar** (achado da revisão, anterior à E2):
  `titulo` e `nome_original` são VARCHAR(255) e o campo da tela aceita 255 caracteres, então um
  título de 251 a 255 passava pela validação, o arquivo era gravado e o banco o recusava no `flush`
  — órfão por falha previsível. Na edição era pior: o conteúdo novo já estava publicado e o usuário
  recebia 500. Agora `TituloDePecaLongoDemaisException` (estende `InvalidArgumentException`) antes de
  tocar no storage, 400 com a mensagem nos dois endpoints; a conta usa o título já em maiúsculas
  (`ß` vira `SS`). A edição captura **só** essa exceção — um `catch` de `InvalidArgumentException`
  pegaria também o `ORMInvalidArgumentException` do `flush` (achado da re-revisão). O campo da tela
  passou de `maxlength="255"` para `250`. Consequência: uma peça importada com título de 251 a 255
  caracteres só aceita edição de conteúdo depois de o título ser encurtado (antes, a edição dava
  500 com o conteúdo já sobrescrito).
- **Peça editada (D14).** `existe()` antes de `gravar(ChaveDeArquivo)`. Ausente →
  `ArquivoNaoEncontrado` → 404 JSON com mensagem fixa (a da exceção expõe escritório e chave) e
  `logger->error` com o id do documento. Pane → 500. **Mudança de comportamento:** antes o arquivo
  era recriado em silêncio e a resposta era sucesso. A sobrescrita passou a ser atômica (vizinho +
  `rename()`); o arquivo ganha inode e modo novos, e a escrita passa a exigir permissão no
  diretório, não só no arquivo. Os campos da entidade só mudam depois de o conteúdo estar publicado.
  **Janela residual:** os seis verbos não têm "gravar só se existir"; se o arquivo sumir entre o
  `existe()` e o `gravar()`, ele é recriado.
- **Export (D13).** `ler()` por chave. Ausente → 404 (antes: 200 com arquivo VAZIO em produção,
  onde o warning não vira exceção) e log. Pane → 500. Uma chave recusada vinda do banco
  (`ChaveDeArquivoInvalida`, que estende `InvalidArgumentException`) é relançada antes do `catch`
  do formato inválido — continua 500, não 400 com a mensagem interna (regra da E2.3); o mesmo no
  criar e no editar. As `<img>` e o `chroot` do Dompdf seguem no disco até a E2.6.
- **`ArquivosReferenciadosEmPecas`.** `ler()` com `catch (ArquivoNaoEncontrado)` → a peça é pulada;
  pane propaga e a consulta inteira falha. Antes, em produção, uma peça presente e ilegível virava
  `''` e parecia "sem imagens" — a limpeza que confiasse nisso apagaria imagem em uso. O `existe()`
  que a preparação mandava manter antes do `ler()` saiu: a prova da cadeia agora está no próprio
  `ler()`. O serviço ainda não tem consumidor em produção.
- **Acervo (D15).** `gravar(novoDocumento, deArquivoLocal($path, consumirOrigem: false))` — `false`
  **explícito**. Cópia em streaming (resolve DT-2). O documento recebe o escritório antes, e o
  `persist` só acontece depois da gravação: uma falha do storage é erro do item, sem linha, e o
  lote segue. Tamanho e MIME vêm do `ArquivoArmazenado` (o mesmo libmagic de antes, agora sobre o
  que foi gravado). Extensão saneada por `NovoArquivo` (D8): sem extensão vira `hash.bin` em vez de
  `hash.` — a origem das 165 chaves legadas —, e maiúscula vira minúscula. Se o `flush` da pasta for
  recusado, o comando lista os nomes gravados ("podem ter ficado sem linha — confira no banco antes
  de apagar"; um COMMIT que falha não diz se as linhas chegaram) e relança; não apaga nada (E2.5, e
  `LimpezaDeArquivosArquiteturaTest` recusa `excluir` num arquivo com `scandir`). Primeiro teste do
  comando: `CopiarArquivosAcervoCommandTest`, que fotografa a origem (inode, modo, mtime, tamanho,
  SHA-256) no sucesso, na origem ilegível, na falha depois de gravar e na recusa do banco.
- **Drive (D16).** O documento recebe o escritório (`getReference` do `tenant_id` da pasta) e a
  pasta é buscada **antes** de gravar; `gravar(novoDocumento, deArquivoLocal($tmp, consumirOrigem:
  true))`; o `finally` do `tempnam` continua (download que falha, origem devolvida pela publicação).
  Tamanho = `ArquivoArmazenado::tamanhoBytes`; quando diverge do `size` da listagem, `[aviso]` no
  resultado. Guarda do INT4 também sobre o tamanho **recebido** (sem ela, um `size` errado deixaria
  o INSERT estourar, o EntityManager fechar e a rodada virar fatal). MIME: o do Drive, quando casa
  `tipo/subtipo` da RFC 6838 (sem parâmetros, sem distinção de caixa, com `D`) e cabe nos 100 da
  coluna — medido em `saas_ux`: 26 valores distintos em 20.954 linhas, a regra recusa zero. Sem MIME
  válido: `application/octet-stream`, o valor que o sync já gravava para MIME vazio (antes, um valor
  não vazio ia cru, e um maior que a coluna derrubava a rodada). **Não** se usa o MIME medido como
  fallback: seria política nova — um `text/html` medido transformaria o arquivo em peça editável.
  Extensão saneada (D8). A limpeza do item que falha continua no storage antigo, por caminho (E2.5);
  agora provada no disco (INT4 e banco recusado).
- **Guarda estrutural** (`EscritaInternaPorChaveArquiteturaTest`): a lista de quem ainda depende de
  `ArquivoStorageInterface`/`ArquivoStorageService` é fechada (19 arquivos, cada um com a fatia de
  saída) e só diminui — a revisão lembrou que travar primitiva por primitiva (`file_put_contents`)
  é fácil de contornar com `copy()`. Além dela: ninguém chama `salvarConteudo()`/
  `moverParaArmazenamento()`; `file_put_contents()` só no shim; `file_get_contents()` só nas 5
  exceções justificadas.
- **Dublês:** `ArmazenamentoEmMemoria` ganhou `tamanhoRelatado`/`mimeRelatado` (prova que o chamador
  persiste o que o storage mediu, e não a própria conta, que num teste comum coincide);
  `ArmazenamentoEspiao` (novo) decora qualquer backend, inclusive o disco; `FakeGoogleDriveClient`
  registra onde escreveu cada download.
- **Os 12 itens pedidos pelo dono:** peça nova, edição, edição sem arquivo (unit + rota, arquivo não
  recriado), export sem arquivo (404 + mensagem + log), pane ≠ 404 (diretório e arquivo ilegíveis,
  falha de gravação, contrato de `ler`/`abrir`), acervo preserva a origem (sucesso), acervo preserva a
  origem na falha (origem ilegível, falha depois de gravar, recusa do banco, e a publicação recusada
  no contrato), conteúdo idêntico (SHA-256), chave do Drive (R1), tamanho gravado (Drive, acervo e
  peça, com valor relatado distinto), MIME válido do Drive preservado, falha do storage sem estado
  errado no banco (peça, edição, acervo, Drive — e sem órfão no Drive).
- **Provas por reintrodução (32 mutações, todas derrubaram o teste certo):** acervo com `consumirOrigem:true`
  (duas vezes, antes e depois das correções); backend consumindo origem sem a flag; edição sem
  `existe()`; export capturando `RuntimeException` como 404; `ler()` e `abrir()` sem a prova da
  cadeia; Drive com o tamanho da API, com MIME sempre medido, com a regra antiga de MIME, com o
  fallback medido, com escopo de outro escritório, sem a guarda INT4 e sem a limpeza do item; peça
  com MIME medido; `persist` antes de gravar (peça e acervo); referências engolindo pane; export sem
  o relançamento de `ChaveDeArquivoInvalida`; edição e export sem o `catch` de ausência; acervo sem a
  lista de órfãos, com tamanho e com MIME da origem; `file_put_contents` e `salvarConteudo`
  reintroduzidos; storage antigo reinjetado na peça; limite de título removido (criar e editar), sem
  as maiúsculas, e o editar sem o 400 ou com o `catch` largo; log da D14 removido. **Uma prova estava errada na própria
  mutação** (`false && A || B` ainda checava `B`) — refeita.
- **Revisão:** quatro revisores (arquitetura, banco × arquivo, Drive/acervo/metadados, testes) e uma
  re-revisão focada nas correções (sem bloqueante e sem regressão; os MENOR dela foram corrigidos:
  exceção própria do título, `maxlength`, umask fixo no teste de publicação, limpeza em `finally`).
  Nenhum bloqueante. O que mudou por causa deles: limpeza do Drive
  provada no disco; título longo recusado antes de gravar; fallback de MIME conservador; guarda
  estrutural do shim; mensagem do acervo sem afirmar o que não sabe; teste de publicação recusada
  com origem emprestada; tamanho/MIME relatados; log da D14 testado; temporário do Drive conferido
  de forma determinística; testes que engoliam `fail()` ou vazavam arquivo corrigidos.
- **Riscos residuais, sem mudança nesta fatia:**
  - ⚠️ **DT-8** (download do Drive grava corpo de erro HTTP como arquivo) — decisão do dono;
  - órfão sem registro se o `filesize()` falhar depois da publicação (núcleo, desde a E2.1; raro);
  - acervo: a lista de órfãos cobre só a recusa do `flush`; a deduplicação por `nome_original` só
    enxerga o que já passou por `flush`; `getRealPath()` devolvendo `false` derruba o comando com
    `TypeError` fora do `try` do item (os três anteriores à E2);
  - `ler()` carrega o HTML inteiro em memória, como o `file_get_contents` de antes;
  - o Drive checa `mb_strlen($nome) > 255` sem considerar que `mb_strtoupper` pode crescer o texto,
    e o acervo não checa: um nome desses estouraria o INSERT (no Drive, rodada fatal). Anterior à E2;
  - a prova de "rodada segue" com o banco recusando o item no Drive é simulada no `onFlush`, antes
    do BEGIN; uma recusa real fecha o EntityManager e a rodada vira fatal (comportamento anterior);
  - os testes de `chmod` e o do log supõem uma suíte por worktree.

**Preparação da E2.4B e da E2.5 — investigação antecipada em 16/09, read-only, nada implementado.**
*(A parte da E2.4B foi respondida pela fatia acima: as decisões (1)–(5) viraram D12–D16, e o
`existe()` antes do `ler()` foi substituído pela prova da cadeia no próprio `ler()`.)*
Levantada em paralelo à E2.4A para encurtar as próximas fatias. Linhas conferidas no código daquele
dia; confira de novo antes de usar.

- **E2.4B — as 4 escritas internas e as 2 leituras.**
  - Linhas que mudaram desde a spec: `ReconciliadorDePasta` `tempnam` :456, extensão :468,
    `moverParaArmazenamento` :472 (a spec dizia :428/:440/:444); leitura crua de
    `ArquivosReferenciadosEmPecas` em :71.
  - `SalvarPecaTextoUseCase:43` → `gravar(ChavesDePasta::novoDocumento($doc, 'html'), deTexto())`.
    **Não** usar o `mimeType` medido: HTML curto sai `text/plain`, e editar/exportar exigem
    `text/html` — o tipo continua fixo. Hoje uma falha do `file_put_contents` passa em silêncio e o
    documento é salvo apontando para arquivo ausente ou truncado; depois, `FalhaDeArmazenamento` vem
    antes do `persist`, mas o controller só captura `InvalidArgumentException` (vira 500).
  - `CopiarArquivosAcervoCommand:381` → `deArquivoLocal($path, consumirOrigem: **false**)` — `true`
    apagaria o acervo do operador. Resolve DT-2 (streaming em vez de `file_get_contents`).
    Extensão vazia deixa de gerar `hash.` (a origem das 165 chaves legadas) e vira `hash.bin`;
    maiúscula vira minúscula. **Não tem teste nenhum.** Já casa o padrão de varredura de
    `LimpezaDeArquivosArquiteturaTest`: qualquer `unlink`/`excluir` ali derruba o teste.
  - `ReconciliadorDePasta:472` → `deArquivoLocal($tmp, consumirOrigem: true)`; o `finally` que apaga o
    `tempnam` continua valendo. Muda: nome esquisito → `bin` (D8); o arquivo deixa de nascer `0600`.
    O cleanup do catch (:500) é `excluir()` (E2.5) e, se migrar antes, precisa de `try` próprio para
    não derrubar a rodada. A Via A (:215 `caminho`, :255 envio ao Drive) é da E2.6.
    `FakeGoogleDriveClient` não sabe simular falha.
  - `EditarPecaTextoUseCase:21-22` → `gravar(ChavesDePasta::documento($doc), deTexto())`: passa a ser
    atômico (hoje trunca no lugar) e ganha inode e modo novos. `EditarPecaTextoUseCaseTest` usa
    documento sem tenant — a fábrica vai lançar.
  - Leituras: `ArquivosReferenciadosEmPecas` → `ler()`, **mantendo o `existe()` antes** (o `ler()` do
    Local não prova a cadeia de diretórios; sem isso, peça ilegível pareceria sem imagens e uma
    limpeza apagaria as imagens dela). `ExportarPecaTextoUseCase:30-31` → `ler()`; hoje peça ausente
    dá warning e export vazio com 200. As `<img>` e o `chroot` do Dompdf são da E2.6.
  - Sem dependência técnica da E2.5/E2.6, mas `Reconciliador` e `Exportar` aparecem em mais de uma
    fatia: sequencial.
  - **Decisões do dono antes de implementar:** (1) resposta HTTP para `FalhaDeArmazenamento` ao
    salvar/editar peça; (2) peça ausente no export: 404 ou 500; (3) editar peça cujo arquivo sumiu:
    recriar (hoje) ou falhar; (4) `CopiarArquivosAcervoCommand`: migrar e escrever o primeiro teste,
    ou aposentar; (5) tamanho/MIME do sync: metadado do Drive (hoje) ou medido na gravação.
- **E2.5 — exclusões e purga.**
  - **19 `excluir()` em 15 arquivos**, confirmados: 9 **antes** do commit (`ExcluirDocumento{,Acordo,
    Carteira}UseCase`, `ExcluirSecaoUseCase`, `ExcluirPastaUseCase`, `PastaController:1696`,
    `ClienteController:191` e `:443`, `ArquivosDeAnexoDoKanban`), 6 depois (`PastaSecaoController`,
    `PastaController:2008`, foto anterior, antigo do lote, 2 da purga) e 4 em catch (cleanup do lote,
    `TenantController`, `PontoController`, `ReconciliadorDePasta:500`).
  - O `excluir()` novo **lança sempre**; o antigo, em produção (`APP_DEBUG=0`), só registrava warning.
    Precisa de política por grupo — sugestão: pós-commit e cleanups registram e seguem (sem mascarar
    o `$e` original); pré-commit propaga. Controller não pode capturar `FalhaDeArmazenamento`
    (`EntregaDeArquivoArquiteturaTest`): `catch (\Throwable)` ou mover para UseCase.
  - **INV-6 é ambígua para os 9 pré-commit** (o título diz "ordem da E1 preservada", o texto diz
    "remoção sempre pós-COMMIT"); nos laços, arquivo já apagado + rollback = registro apontando para
    o vazio, hoje. **Decisão do dono:** migrar 1:1 ou passar para depois do commit.
  - Defeito anterior: `ClienteController:191` apaga os arquivos e depois captura a violação de FK — o
    cliente fica, sem os arquivos. Ambiguidade de COMMIT nos cleanups de Ponto: ver o bloco da E2.4A.
  - Purga (`PurgarEscritorioUseCase`): não injeta `kanbanUploadsDir` (anexos de Kanban do tenant
    purgado ficam órfãos hoje); `tarefa_mensagem` é **no-op de fato** (o valor tem `/`) e deve ficar
    no-op **explícito** até a E2.7; `documento_processo` não tem escritor em `src/` nem diretório —
    parece mais honesto tirar a consulta com justificativa e um teste-guarda (**decisão do dono**);
    produto cartesiano nomes × 4 diretórios (restringir por categoria muda comportamento);
    `rmdir` falha calado com dotfile (`.compress_*`); falha de disco pós-commit não tem nova
    tentativa e o comando reporta "Falha ao purgar" com o banco já purgado.
  - `excluirPrefixo` — prova de pertencimento proposta: só o enum restrito; `basename` do prefixo
    igual ao id (`^[1-9]\d*$`); `!is_link` (hoje `pastas/5 -> pastas` apagaria a raiz compartilhada);
    `realpath` igual a `realpath(raiz)/id`; prefixo diferente de toda raiz e não ancestral dela;
    não recursivo; política para dotfiles e para nomes que `ChaveDeArquivo` recusa. `import-tmp` é
    **irmão** (`cobrancas/import-tmp/<id>`), não filho: o prefixo de Cobrança não o alcança (D3).
  - `LimpezaDeArquivosArquiteturaTest`: entra `ArmazenamentoLocal` na allowlist (D7); a entrada da
    purga fica inútil quando o `glob` sair — remover, e endurecer o teste contra entrada morta.
  - Lacunas de teste frente ao §11.2: o cenário literal "purga do tenant 5 não apaga arquivo do
    tenant 1 em `uploads/clientes`" não existe em disco; nem `cobrancas/<id>`, symlink, escopo
    global, Kanban, Tarefa, `documento_processo`, disco ilegível na limpeza.
  - R2: `cobranca-acompanhamento-canonico` toca a purga e segue congelada (`80ac07cb`).
- **E2.5 — rodada 2 da investigação (16/09, read-only, durante a E2.4B).** Nada implementado.
  - **Contagem confirmada:** 19 `excluir()` em 15 arquivos (9 pré-commit, 6 pós-commit, 4 em
    `catch`). Linhas que mudaram: `PastaController` :1702 e :2018, `ClienteController` :192 e :448.
    Nenhum `unlink` cru sobre arquivo persistido fora do shim.
  - **O antigo, medido na configuração:** `framework.php_errors` não é configurado, então vale
    `throw = kernel.debug`. Em dev/test o warning do `unlink` vira `ErrorException`; em produção
    (`APP_DEBUG=0`) só vai para o log e a execução segue. O novo lança `FalhaDeArmazenamento` sempre
    que o arquivo continua lá depois do `unlink`.
  - **Compartilhamento, medido em `saas_ux`:** nenhum nome repetido em `pasta_documento` (20.954),
    `cliente_documento`, `cobranca_documento`, `user_profiles`; zero nomes repetidos ENTRE tabelas.
    A única referência múltipla é o anexo do lote do Ponto (4 nomes em 13 linhas, máximo 7, sempre
    dentro do mesmo lote) — e o `SubstituirAnexoDoLoteUseCase` já conta referências sob trava. Não há
    "duplicar pasta" nem cópia de `caminho_arquivo` em `src/`.
  - **Defeito concreto do `ClienteController:192`:** a FK que derruba a exclusão é
    `cobranca_carteira → cliente` (NO ACTION), não "pré-cadastro" como diz o flash. Em `saas_ux`, 3
    clientes têm carteira e 2 deles têm documentos: excluí-los apaga os arquivos e depois o cliente
    fica, sem eles.
  - **COMMIT duvidoso — são QUATRO cleanups, não três:** `ReconciliadorDePasta` (catch do download)
    tem o mesmo defeito dos três do Ponto. Fato que ajuda a decidir: no `flush()` simples o ORM 3.6
    embrulha a falha do COMMIT em `OptimisticLockException('Commit failed')` (`UnitOfWork.php`
    :431-441), enquanto falhas de INSERT/UPDATE saem cruas (antes do COMMIT, seguras para limpar). No
    `wrapInTransaction` do lote não há essa marca. O tipo da exceção DBAL não serve de critério
    (queda do lado do cliente vem como `DriverException` genérica). PG 15 oferece
    `pg_current_xact_id()` + `pg_xact_status()` para perguntar o destino da transação. Nenhum
    precedente no projeto.
  - **Kanban fora da purga:** confirmado; `kanban_anexo` some por CASCADE (board → card → anexo) e os
    arquivos ficam órfãos. 0 anexos em `saas_ux`; produção não medida nesta rodada.
  - **`documento_processo`:** entidade e repositório existem, **nenhum escritor em `src/`** (só a
    fixture, com `fixtures/<nome>`, que a chave recusaria), 0 linhas, nenhum diretório; cai por
    CASCADE de `processo`.
  - **Purga:** `excluirPrefixo` entra exatamente no lugar de `removerDiretorioDeTenant` (as duas
    categorias com diretório por escritório); as planas seguem um a um pelos registros. Hoje: produto
    cartesiano nomes × 4 diretórios, Kanban e Tarefa são no-op de fato, `glob` segue symlink e ignora
    dotfile, o contador conta antes do `excluir`. Com o `excluir()` novo lançando, a exceção sai
    **depois** do COMMIT: o comando reporta "Falha ao purgar" com o banco já purgado, e o que sobrou
    no disco vira órfão definitivo (a próxima rodada não acha mais as linhas).
  - A matriz de decisão por chamada está no relatório da E2.4B ao dono (16/09).

**Três armadilhas de ordenação, já mapeadas:**

1. **A E2.5 esbarra em Tarefa duas fatias antes da hora.** `PurgarEscritorioUseCase.php:322` lê
   `tarefa_mensagem.arquivo_anexo`, que **contém `/`** e seria recusado por `ChaveDeArquivo`
   (medido: 3 de 3 valores em `saas_ux` têm barra). Regra: na E2.5 essa lista **continua sendo
   tratada pelo mecanismo atual**, sem virar chave; a conversão espera a E2.7. Isso tem de estar
   explícito no código, não implícito.
2. **A E2.5 também esbarra em `documento_processo`** (`:319`), que não tem categoria no enum.
   A E2.5 decide entre criar a 10ª categoria ou documentar por que a consulta pode sair — **não
   pode sumir em silêncio**.
3. **`LimpezaDeArquivosArquiteturaTest` fica vermelho na E2.5.** Ele barra `glob|scandir` +
   `unlink|->excluir(` no mesmo arquivo (`:31-36`), com allowlist de **um** arquivo. O
   `ArmazenamentoLocal` com `listar()` + `excluir()` casa os dois padrões. A entrada na allowlist,
   com justificativa, é entregável da E2.5 — senão INV-3 cai.

**E2.7 pode terminar sem código.** Se a tradução segura do formato legado exigir alterar dados, a
saída correta é registrar o achado, manter `CaminhoDeAnexoDeTarefa` e empurrar a normalização para
a E3 — não relaxar o contrato.

⚠️ As contagens por fatia **se sobrepõem** (os 4 chamadores do compressor aparecem na E2.4 e na
E2.6). O total da E2 é **35 arquivos de produção**: os 33 consumidores mais `TarefaController` e
`CaminhoDeAnexoDeTarefa`.

---

## 11. Testes exigidos

### 11.1 Suíte de contrato (`ArmazenamentoContratoTest`, abstrata)

Rodada contra `ArmazenamentoLocal` e `ArmazenamentoEmMemoria` na E2; contra `R2Storage` na E4.

gravar → ler → existe → tamanho → excluir · excluir **idempotente** · **arquivo de 0 byte** (114 em
produção) · sobrescrever a mesma chave · `abrir`/`ler` de chave inexistente lança · `gravar` a
partir das três `FonteDeConteudo` · `NovoArquivo` cunha nome único e o devolve na chave.

**Chaves legadas — medidas, não imaginadas.** Em `saas_ux`, `pasta_documento` tem **165 chaves
terminadas em `.`** (hash sem extensão) e **3 com espaço nas bordas**; zero com `/`, `\`, `..` ou
caractere de controle. A E0 contou 184 fora do padrão em produção. O teste precisa cobrir esses
dois formatos, e provar que o construtor **não aplica `trim()`** — um `trim()` "inofensivo" torna
3 arquivos inalcançáveis para sempre.

### 11.2 Prova de que a E2 não mudou comportamento

- **`MapaDeChaveParaCaminhoLocalTest`** — para as 9 categorias, o caminho resolvido é idêntico ao
  que o código produz hoje, **com os parâmetros de produção fixados no próprio teste** (em
  `APP_ENV=test` eles apontam para `var/uploads-test/*`, `services.yaml:161-169`). São **8
  categorias resolvidas**; `TAREFA_ANEXO` é a nona e o teste afirma que ela **lança** com
  mensagem apontando para a E2.7 — a coluna guarda caminho público com `/`, que `ChaveDeArquivo`
  recusa por D5, então não existe chave a resolver. Deixar a categoria no enum, lançando, mantém
  o buraco visível; tirá-la do enum o esconderia.
- **Golden de download** — um PDF e uma imagem por rota-tipo: status, `Content-Type`,
  `Content-Disposition` (inline e attachment), `Accept-Ranges`; e `Range: bytes=0-99` → **206** com
  `Content-Range` correto.
- **INV-9 / D9 — quatro testes, obrigatórios e nominais:**
  1. destruir ou fechar um `ArquivoEmprestado` **não remove** o arquivo persistido;
  2. o cleanup de um `ArquivoTemporarioPossuido` remove **somente** o temporário — o persistido
     continua lá;
  3. a resposta de download do backend Local **não** tem `deleteFileAfterSend = true` sobre arquivo
     persistido, e depois de `sendContent()` executado o arquivo **ainda existe**;
  4. falha no meio da materialização (disco cheio, permissão, origem sumida) **não afeta o
     original** — nem apaga, nem trunca, nem muda o modo.
- **INV-10** — upload por HTTP resulta em arquivo com modo `0666 & ~umask()`, e `UploadedFile`
  inválido produz a mesma exceção tipada de hoje.
- **Arquivo grande não vai para a memória** — assert sobre o tipo de resposta e ausência de leitura
  integral.
- **Isolamento cross-tenant** — chave de outro escritório → 404, no padrão de
  `DocumentosCobrancaIsolamentoTenantTest`.
- **Prefixo em categoria plana é irrepresentável** (D7) — o teste prova a barreira nos dois níveis:
  (a) `CategoriaComIsolamentoFisico` tem exatamente 2 casos e não há conversão a partir de
  `CategoriaDeArquivo` plana; (b) forçando o adapter por reflexão, ele **recusa e nada apaga**.
  Cenário de regressão a prova explicitamente: "purga do tenant 5 apaga documento do tenant 1 em
  `uploads/clientes`" — o teste monta dois tenants no mesmo diretório plano e afirma que a purga
  de um deixa o arquivo do outro intacto.
- **Escopo correto na construção da chave (R1)** — por ponto de escrita, unit afirmando que o
  escopo vem da **entidade**; e os funcionais rodando contra `ArmazenamentoEmMemoria`, que
  materializa o escopo, de modo que tenant errado quebra mesmo nas categorias planas.
- **`existe()` que lança** — hoje `file_exists` devolve `false` em erro de I/O
  (`ArquivoStorageService.php:86`); as 27 chamadas mudam de comportamento. Testar os dois pontos
  sensíveis: `PurgarEscritorioUseCase:363` (dentro da limpeza pós-commit) e
  `ReconciliadorDePasta:210` (conta erro e segue) continuam se comportando como hoje diante de
  arquivo **ausente**.
- **Falha de I/O na escrita** — dublê que lança em `gravar()`: **nenhuma linha** fica no banco
  (invariante herdado da E1).
- **Modos de falha do compressor (D4, pré-requisito da E2.6)** — Ghostscript ausente, saída vazia,
  timeout, resultado maior que o original, falha na regravação: em **todos**, o arquivo persistido
  continua íntegro e legível e o tamanho no banco corresponde ao arquivo real.
- **Travessia** — unit no construtor de `ChaveDeArquivo` com `../`, `/`, `\`, vazio e byte nulo.
  Ataca o VO diretamente: o roteador normaliza `../` antes do controller, então teste funcional de
  travessia passa verde com ou sem guarda (lição registrada em `ServirFotoControllerTest`).
- **Regressão** — os 28 arquivos de teste que referenciam a interface em código seguem verdes; os 5
  dublês migram para o `ArmazenamentoEmMemoria` compartilhado.

### 11.3 Teste de arquitetura (E2.8)

Nos moldes de `app/tests/Arquitetura/LimpezaDeArquivosArquiteturaTest.php`, com **allowlist
explícita por arquivo e justificativa em comentário**. Barra em `src/`:

- `file_get_contents|file_put_contents|fopen|filesize|unlink|rename|copy|glob|scandir|realpath|is_file|file_exists|is_dir|mkdir|rmdir|move_uploaded_file|SplFileObject|->move(`
  — exceto adapter de storage, serviço de temporários, ponte HTTP de upload e Commands one-off;
- `BinaryFileResponse` — exceto na `EntregaDeArquivo` (que é quem deve usá-lo) e nos Commands;
- os **nomes reais** dos 7 parâmetros, que **não** casam com `*_uploads_dir`:
  `uploads_dir`, `justificativas_uploads_dir`, `chamados_uploads_dir`, `clientes_uploads_dir`,
  `fotos_perfil_dir`, `cobrancas_uploads_dir`, `kanban_uploads_dir` — e os binds `$uploadsDir`,
  `$justificativasUploadsDir`, `$chamadosUploadsDir`, `$clientesUploadsDir`, `$fotosPerfilDir`,
  `$cobrancasUploadsDir`, `$kanbanUploadsDir`. Um padrão `*_uploads_dir` deixaria passar
  **`uploads_dir`**, que é a categoria de metade das 40 chamadas de `caminho()`, e
  `fotos_perfil_dir`.

**Allowlist obrigatória:** `sys_get_temp_dir()`/`tempnam`, os 4 lock files, os 10
`createReaderForFile`, e — enquanto a E2.7 não concluir — `TarefaController` e
`CaminhoDeAnexoDeTarefa`. **Filesystem temporário legítimo não pode ser proibido.** Regex não faz
análise de fluxo: por isso a allowlist é por arquivo, como no teste que já existe.

---

## 12. Critérios de aceite da E2

1. Nenhum consumidor obtém caminho de arquivo persistido. Além de `storage->caminho(` = zero, o
   critério cobre **os wrappers**: `ArquivosDeAnexoDoKanban::caminhoDe()` (`:35-38`, consumido em
   `KanbanAnexoController:87`) e `CaminhoDeAnexoDeTarefa::resolver()` — um grep só pela chamada
   direta fecharia verde com o vazamento vivo.
2. `ArquivoStorageInterface` e `ArquivoStorageService` não existem mais; os 3 docblocks de DT-5
   atualizados.
3. Os 7 parâmetros (nomes literais do §11.3) são consumidos **somente** pelo
   `ResolvedorDeCaminhoLocal`.
4. Nenhuma migration na frente: o diff contra `origin/master` não toca `migrations/`.
5. `doctrine:schema:validate` continua limpo.
6. Suíte completa verde na frente **e** de novo no master depois do merge.
7. Os testes do §11.2 falham se o comportamento regredir — provado por **reintrodução do defeito**,
   não por suíte verde.
8. `docs/frentes-ativas.md` e `app/src/Shared/CLAUDE.md` atualizados (o segundo documenta a
   interface literalmente, com a assinatura antiga — documento de arquitetura, proposto ao dono,
   não reescrito sozinho).
9. **D7 e D9 são barreiras de tipo, não convenção** — e isso se verifica lendo as assinaturas, não
   só rodando teste: nenhuma operação de prefixo aceita `CategoriaDeArquivo`; nenhum método devolve
   um tipo único de "arquivo temporário" cujo ownership dependa de flag ou de documentação.
   Se um desenvolvedor novo conseguir passar categoria plana para `excluirPrefixo`, ou apagar um
   `ArquivoEmprestado` sem escrever nada estranho, a E2 não cumpriu D7/D9.

---

## 13. Rollback

- **Dentro de uma fatia:** worktree isolada com banco próprio (`saas_teste2-abstracao-storage`);
  desfazer é operação local, sem tocar o master.
- **Depois de integrar uma fatia:** como nenhuma fatia move arquivo nem altera banco (INV-1,
  INV-2), reverter é reverter o commit de código. **Uma ressalva honesta:** arquivos *criados*
  enquanto a fatia esteve no ar permanecem, e se INV-10 tiver falhado eles nascem com modo `0600` —
  reverter o código não conserta o modo. Por isso INV-10 tem teste próprio.
- **Se a E2 for abandonada no meio:** com o shim de D2 o sistema fica misto porém **funcional** —
  parte dos consumidores usando chave, parte usando `caminho()`, os dois apontando para os mesmos
  arquivos.
- **Fatia E2.6** é a única com risco de dado: por isso INV-7 e os testes de modo de falha são
  **pré-requisito**, não consequência.

---

## 14. Fora de escopo (explícito)

Cloudflare R2 · SDK S3 / SigV4 · bucket, credencial ou configuração de backend remoto · presigned
URL · `X-Accel-Redirect` / `X-Sendfile` · colunas `storage_backend`, `storage_key`, `checksum` ·
qualquer migration · backfill · cálculo de SHA-256 (D6) · normalização das 184 chaves legadas ·
exclusão dos 79 órfãos · exclusão dos 114 arquivos de 0 byte · mover ou renomear arquivo físico ·
alterar qualquer registro do banco · mover `import-tmp` (D3) · **alterar `GoogleDriveClientInterface`
ou `CompressorArquivoInterface`** (ambos continuam recebendo path; quem muda é o chamador) ·
corrigir a ausência de checagem de permissão em `PecaImagemController`/`ProfileController` (DT-4) ·
normalizar a coluna de anexo de Tarefa, caso a E2.7 conclua que exige dado (D5) · deploy · qualquer
toque em produção.

---

## 15. Preparação para E3 e E4 (confirmada, não implementada)

**E3 cabe sem redesenho:** `storage_backend` e `storage_key` são os campos que `ChaveDeArquivo` já
materializa em código; `tamanho real` e `checksum` já têm lugar em `MetadadosDeArquivo`; o backfill
vira "para cada linha, montar a chave e comparar com o Local".

**E4 cabe:** `R2Storage` implementa os mesmos 6 métodos traduzindo a chave no layout remoto que a
E3/E4 definir; a presigned entra como terceira forma de resposta da `EntregaDeArquivo`, sem tocar
consumidor nenhum; o materializador ganha uma implementação que baixa para temporário — e nela
`paraLeitura()` passa a ser **possuído**, ao contrário do Local (INV-9), o que o contrato já
distingue.

⚠️ **Nota da E2.3 — isto não cabe mais sem decisão.** `MaterializadorDeArquivo::paraLeitura()`
declara `ArquivoEmprestado`, e um teste trava isso; um backend remoto não pode devolver emprestado.
A E4 terá de escolher entre um método novo ou um retorno em união — e, se a entrega passar a
receber um possuído, `deleteFileAfterSend` volta a ser legítimo **sobre o temporário**, o que o
teste de arquitetura da E2.3 hoje proíbe na entrega inteira e terá de ser refinado. "Sem tocar
consumidor nenhum" também só vale se `PoliticaDeEntrega` entrar com valor padrão.

A E2 **não conhece nada da Cloudflare**. O único traço de formato remoto vive dentro do resolvedor
de cada adapter — nunca no contrato.
