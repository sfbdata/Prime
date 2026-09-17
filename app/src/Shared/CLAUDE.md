# Shared/ — Código Compartilhado entre Domínios

## Responsabilidade

Código verdadeiramente transversal que não pertence a nenhum domínio específico.
Se um código só é usado por um domínio, ele vai no próprio domínio — não aqui.

## O que pertence aqui

- `Service/` — serviços de infraestrutura (email, PDF, compressão, etc.)
- `Armazenamento/` — o núcleo de armazenamento de arquivos, endereçado por chave:
  `ArmazenamentoDeArquivos` (seis verbos), `MaterializadorDeArquivo`, os valores (`ChaveDeArquivo`,
  `NovoArquivo`, `EscopoDeArquivo`, `CategoriaDeArquivo`, `FonteDeConteudo`, …) e o backend local.
  **Não importa Symfony, Doctrine, AWS nem Cloudflare** (INV-8, travado por
  `tests/Arquitetura/NucleoDeArmazenamentoArquiteturaTest`).
- `Http/` — a borda HTTP do armazenamento: `EntregaDeArquivo` (download e visualização) e
  `FonteDeUploadHttp` (upload → armazenamento).
- `Doctrine/Transacao/` — `TransacaoComArquivoNovo`: confirma no banco uma operação que acabou de
  gravar arquivo e decide, se ela falhar, se o arquivo pode sair (só com a ausência de COMMIT
  PROVADA — ver "Arquivo novo + transação que pode falhar" abaixo).
- `Trait/` — traits utilitários (ex.: `TimestampableTrait`, `TenantAwareTrait`)
- `DTO/` — DTOs genéricos reutilizáveis entre domínios
- `Exception/` — exceções base do sistema
- `Interface/` — interfaces de contratos de infraestrutura

## Regras

**Shared não depende de domínios:**
```
Shared ← qualquer domínio pode usar Shared
Shared ✗ não importa de src/Cliente/, src/Processo/, etc.
```

**Services de infraestrutura devem ter interface:**
```php
// src/Shared/Armazenamento/ArmazenamentoDeArquivos.php — o contrato
interface ArmazenamentoDeArquivos
{
    public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado;
    public function abrir(ChaveDeArquivo $chave): mixed;
    public function ler(ChaveDeArquivo $chave): string;
    public function existe(ChaveDeArquivo $chave): bool;
    public function excluir(ChaveDeArquivo $chave): void;
    public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo;
}

// src/Shared/Armazenamento/ArmazenamentoLocal.php — implementação concreta (disco)
```

A interface no `Shared/` permite trocar a implementação (disco hoje, R2 depois) sem mudar os domínios.

## Arquivos — endereçados por CHAVE, nunca por caminho

Contrato e decisões: `docs/specs/e2-abstracao-de-storage.md`.

A chave sai da fábrica do domínio, `app/src/<Dominio>/Armazenamento/ChavesDe<Dominio>`. A fábrica
recebe a **entidade** e tira dela o escritório, a categoria e o nome — nunca um `Tenant` solto,
salvo as exceções documentadas na própria fábrica. No disco de hoje o escritório é ignorado em sete
das nove categorias, então um tenant errado passaria despercebido aqui e só quebraria no backend
remoto (R1).

**Ler / baixar:**
```php
$chave = ChavesDePasta::documento($doc);

if (!$this->armazenamento->existe($chave)) {                                     // ArmazenamentoDeArquivos
    throw $this->createNotFoundException();
}

return $this->entrega->resposta($chave, $doc->getNomeOriginal(), inline: true); // EntregaDeArquivo
```

**Gravar um upload:**
```php
$doc = new PastaDocumento();
$doc->setTenant($tenant);                        // o escritório antes da gravação: é dele que sai o escopo

$upload     = FonteDeUploadHttp::de($arquivo);  // isValid() e extensão pelo conteúdo, antes de mover
$armazenado = $upload->gravarEm(
    $this->armazenamento,
    ChavesDePasta::novoDocumento($doc, $upload->extensao),                      // NovoArquivo
);

$doc->setCaminhoArquivo($armazenado->chave->nome);  // só então completa e persiste
$doc->setTamanhoBytes($armazenado->tamanhoBytes);   // quem responde o tamanho é o storage (D30)
```

**Comprimir o que acabou de ser gravado** (só onde o usuário pede, pela chave):
```php
$compressao = $this->compressao->comprimir($armazenado->chave, $mimeType);  // CompressaoDeArquivoArmazenado
$doc->setTamanhoBytes($compressao->tamanhoFinal);   // medido depois da regravação
```

Compressão é **melhor esforço**: falha de compressão ou do temporário mantém o arquivo como estava e
vira log. O que **não** é engolido: não conseguir ler ou medir o arquivo persistido é pane e sobe
(D26) — o mesmo critério do D10.

A fábrica de arquivo novo (`ChavesDe*::novo*`) tira o escopo de onde a **leitura** vai tirá-lo
depois — a gravação e a leitura têm de montar a mesma chave. `tests/Arquitetura/FabricasDeArquivoNovoTest`
trava isso e proíbe `new NovoArquivo(` fora das fábricas.

**Excluir — o arquivo sai DEPOIS do COMMIT (INV-6):**
```php
$chave = ChavesDePasta::documento($doc);          // 1. a chave antes: depois do flush a dona some

$this->em->remove($doc);
$this->em->flush();                               // 2. o banco decide

$this->remocao->remover([$chave], 'contexto');    // 3. RemocaoAposTransacao: nunca lança
```

Numa `wrapInTransaction`, colete as chaves dentro do closure e remova **fora** dele — o COMMIT
acontece depois que o closure retorna. Falha física depois do COMMIT não desfaz nada nem vira 500:
vira `logger->error` e órfão recuperável. Ninguém chama `->excluir(` fora da `RemocaoAposTransacao`
(`tests/Arquitetura/ExclusaoAposTransacaoArquiteturaTest`).

**Arquivo novo + transação que pode falhar:**
```php
$chave = $upload->gravarEm($this->armazenamento, ChavesDePonto::novoAnexoDeLote(...))->chave;
// ... persist das linhas que apontam para o arquivo ...
$this->transacao->confirmar(static fn (): array => [$chave], 'contexto');   // TransacaoComArquivoNovo
```

`confirmar()` abre a transação, faz o `flush` e o COMMIT; `executar($trabalho, …)` roda o trabalho
antes do `flush` (a semântica do `wrapInTransaction`). Em qualquer falha o EntityManager é fechado,
a exceção original sobe, e o arquivo só sai se o COMMIT comprovadamente não aconteceu: falha antes
do COMMIT, ou `pg_xact_status = aborted`. COMMIT de resultado incerto (a resposta se perdeu)
preserva o arquivo — as linhas podem ter sido confirmadas apontando para ele —, e dentro de
transação aberta por fora nunca apaga.

`RemocaoAposTransacao` e `TransacaoComArquivoNovo` são concretas de propósito: são política, não
infraestrutura trocável. A costura para teste é a interface de baixo (`ArmazenamentoDeArquivos`,
`ConsultaDeDestinoDaTransacao`).

**Apagar um escritório inteiro** é só da purga: `ArmazenamentoComPrefixo::excluirPrefixo()`, que
aceita apenas `CategoriaComIsolamentoFisico` (D7), prova o pertencimento antes de apagar (e lança se
não conseguir) e devolve removidos e sobras. Nas categorias planas a purga identifica os arquivos
pelos registros do escritório — só os que nenhum registro de outro escritório referencia — e os
apaga um a um.

Regras que acompanham os exemplos:

- controller não conhece diretório de upload, raiz física, `ResolvedorDeCaminhoLocal` nem
  `ArmazenamentoLocal` (D11, `EntregaDeArquivoArquiteturaTest`);
- autorização e tenant são checados **antes** de montar a chave (INV-5) — o storage nunca decide
  quem pode ler;
- arquivo ausente vira 404; pane do storage (`FalhaDeArmazenamento`) **não** vira 404, e controller
  não a captura (D10);
- `UploadedFile` não entra em `Shared/Armazenamento`: quem o converte é a `FonteDeUploadHttp`, que
  preserva `isValid()`, `move_uploaded_file()` e o `chmod` (INV-8, INV-10). Não toque no
  `UploadedFile` depois de gravar — tamanho e MIME medidos vêm em `ArquivoArmazenado`;
- arquivo novo não escolhe nome: o storage cunha (`NovoArquivo`, D8). Chave de arquivo que já existe
  vai byte a byte, sem `trim()` nem normalização;
- remoção física de arquivo só **depois** do COMMIT (INV-6), pela `RemocaoAposTransacao`: órfão
  recuperável é aceitável, registro apontando para arquivo inexistente não é;
- `ArquivoEmprestado` (o caminho do arquivo persistido) nunca é apagado; só
  `ArquivoTemporarioPossuido` tem cleanup (D9).

## Serviços compartilhados atuais

- `Armazenamento\ArmazenamentoDeArquivos` + `Http\EntregaDeArquivo` + `Http\FonteDeUploadHttp` —
  armazenamento, entrega e upload por chave.
- `Armazenamento\RemocaoAposTransacao` — remoção física depois da transação;
  `Doctrine\Transacao\TransacaoComArquivoNovo` — COMMIT com decisão sobre o arquivo novo.
- `Service\CompressaoDeArquivoArmazenado` — comprimir arquivo JÁ persistido, **pela chave**: ele
  materializa uma cópia gravável fora do volume, chama o compressor nela, regrava na mesma chave e
  devolve os tamanhos MEDIDOS pelo storage. É o único lugar que junta materializador e compressor;
  nenhum controller ou UseCase toca em `CompressorArquivoInterface` direto.
- `Service\ArquivoStorageInterface` / `ArquivoStorageService` — **em extinção** (shim de D2): desde a
  E2.6B só resta `caminho()` para o envio ao Drive (Via A do `ReconciliadorDePasta`), que sai na
  E2.6C; a interface e o serviço saem na E2.8. Não usar em código novo — gravar, ler, excluir,
  entregar e comprimir já são por chave.

## Traits

Sufixo obrigatório `Trait`:
```php
trait TimestampableTrait
{
    // Projeto usa 'datetime_immutable'; migração para 'datetimetz_immutable' (com timezone) é backlog
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    #[ORM\PrePersist]
    public function aoInserir(): void
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }
}
```
