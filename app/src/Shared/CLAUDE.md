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
- `EspelhoRemoto/` — o destino da réplica no R2 (spec `docs/specs/p2-gravacao-dupla.md`):
  `LayoutDeChaveRemota` (chave `core/t/{tenant}/uploads/…`, pura), o contrato `EspelhoRemoto` (só
  `enviar`/`consultar`/`existe` — **nenhuma exclusão remota**) e, em `R2/`, o único lugar que conhece o
  `async-aws`. Desde a P2.3 o armazenamento ativo é o `ArmazenamentoEspelhado` (decorador por cima do
  disco local, ligado no `services.yaml`): com `ARMAZENAMENTO_ESPELHO_CATEGORIAS` vazia — o padrão — é
  repasse puro; com categoria ligada, gravar/excluir viram linhas na outbox (`Outbox/`), que o worker
  (`armazenamento:replicar-espelho`) leva ao R2. **Leitura é sempre do disco local**; exclusão remota
  não existe.
- `Doctrine/Transacao/` — `TransacaoComArquivoNovo`: confirma no banco uma operação que acabou de
  gravar arquivo e decide, se ela falhar, se o arquivo pode sair (só com a ausência de COMMIT
  PROVADA — ver "Arquivo novo + transação que pode falhar" na skill `armazenamento-de-arquivos`).
- `Doctrine/Filter/` — os SQLFilters globais: `TenantFilter` (`tenant_id = :tenant`, ligado por
  request em entidade `TenantAware`) e `LixeiraFilter` (`excluido_em IS NULL`, ligado por padrão em
  entidade `Descartavel` — a lixeira da aba Documentos). Quem precisa enxergar a lixeira (restaurar,
  listar, purgar) usa `AcessoALixeira::comLixeiraVisivel()`, nunca `getFilters()` solto. SQL cru
  (DBAL) não passa por filtro nenhum: escreva o `excluido_em IS NULL` à mão.
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

## Arquivos

Tudo sobre gravar, ler, apagar, comprimir e entregar arquivo (chave, fábricas, remoção depois do
COMMIT, transação com arquivo novo, área temporária) está na skill `armazenamento-de-arquivos`, que
carrega em qualquer domínio que mexa com arquivo. Contrato e decisões: `docs/specs/e2-abstracao-de-storage.md`.

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
