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
```

A fábrica de arquivo novo (`ChavesDe*::novo*`) tira o escopo de onde a **leitura** vai tirá-lo
depois — a gravação e a leitura têm de montar a mesma chave. `tests/Arquitetura/FabricasDeArquivoNovoTest`
trava isso e proíbe `new NovoArquivo(` fora das fábricas.

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
- remoção física de arquivo substituído só **depois** do COMMIT (INV-6): órfão recuperável é
  aceitável, registro apontando para arquivo inexistente não é;
- `ArquivoEmprestado` (o caminho do arquivo persistido) nunca é apagado; só
  `ArquivoTemporarioPossuido` tem cleanup (D9).

## Serviços compartilhados atuais

- `Armazenamento\ArmazenamentoDeArquivos` + `Http\EntregaDeArquivo` + `Http\FonteDeUploadHttp` —
  armazenamento, entrega e upload por chave.
- `Service\ArquivoStorageInterface` / `ArquivoStorageService` — **em extinção** (shim de D2): ainda
  atende as gravações internas (`salvarConteudo()`/`moverParaArmazenamento()`, até a E2.4B),
  `excluir()` (até a E2.5) e `caminho()` (até a E2.6); sai na E2.8. Não usar em código novo.

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
