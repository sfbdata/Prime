# Shared/ — Código Compartilhado entre Domínios

## Responsabilidade

Código verdadeiramente transversal que não pertence a nenhum domínio específico.
Se um código só é usado por um domínio, ele vai no próprio domínio — não aqui.

## O que pertence aqui

- `Service/` — serviços de infraestrutura (storage, email, PDF, etc.)
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
// src/Shared/Service/ArquivoStorageInterface.php
interface ArquivoStorageInterface
{
    public function salvar(UploadedFile $arquivo, string $diretorio): string;
    public function servir(string $caminhoCompleto, string $nomeOriginal, bool $inline = true): BinaryFileResponse;
    public function excluir(string $caminhoCompleto): void;
    public function existe(string $caminhoCompleto): bool;
    public function caminho(string $diretorio, string $nomeArquivo): string;
}

// src/Shared/Service/ArquivoStorageService.php — implementação concreta
```

A interface no `Shared/` permite trocar a implementação (S3, local, GCS) sem mudar os domínios.

## Serviços compartilhados atuais

- `ArquivoStorageService` / `ArquivoStorageInterface` — upload e acesso a arquivos

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
