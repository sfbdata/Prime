<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\TipoRelatorioContabil;
use App\Cobranca\Repository\RelatorioImportadoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um relatório da contabilidade, lido e guardado como veio (SPEC espelho §4.2).
 *
 * A premissa do módulo (SPEC §1, decisão do dono em 12/08) é que **a contabilidade é a verdade
 * absoluta** e o sistema traduz o estado dela. Esta tabela é o registro dessa verdade: antes dela,
 * o único vestígio de um import no banco era um campo JSON em `Carteira`, sobrescrito a cada
 * importação e sem histórico.
 *
 * INV-E1: **imutável**. Nunca sofre UPDATE. Corrigiu-se o leitor? Incrementa `versaoLeitor`, relê e
 * gera um lote novo — por isso a versão entra na chave única, senão o mesmo arquivo (mesmo hash)
 * seria recusado pelo índice.
 *
 * INV-E2: **NÃO implementa `Auditavel` de propósito.** O `AuditLogSubscriber` grava uma linha de
 * auditoria por entidade marcada (`shouldAudit()`: `$entity instanceof Auditavel`), e a carga do
 * histórico traz ~38,5 mil linhas nas 23 emissões — seriam 38,5 mil registros de auditoria sem
 * informação nova. A procedência já está aqui: `arquivoHash`, `lidoEm` e `lidoPor`.
 *
 * INV-E3: **`dadosAte` é a âncora da calibração.** Vem do campo `Inadimplência até:` da linha
 * `Filtros:` do rodapé — que o importador atual lê e **descarta de propósito** (`RecorteEsperado`
 * a documenta como fora, `ResultadoRodape` a chama de "payload morto"). Sob a premissa nova ela
 * deixou de ser morta: é a data em que a contabilidade calculou os encargos, e sem ela não há como
 * comparar a nossa conta com a dela.
 */
#[ORM\Entity(repositoryClass: RelatorioImportadoRepository::class)]
#[ORM\Table(name: 'cobranca_relatorio_importado')]
#[ORM\Index(name: 'idx_cobranca_relatorio_tenant_carteira', columns: ['tenant_id', 'carteira_id'])]
#[ORM\Index(name: 'idx_cobranca_relatorio_dados_ate', columns: ['dados_ate'])]
/**
 * 🔑 **O `tipo` entra na chave única** desde a SPEC quatro-relatórios (§4.1).
 *
 * Sem ele, o índice do banco e a checagem de idempotência do código diziam coisas diferentes:
 * `RelatorioImportadoRepository::findOnePorHash()` filtra por tipo, o índice não filtrava. O mesmo
 * arquivo recarregado com `--tipo` diferente passava pela checagem em memória e batia no índice, e a
 * `UniqueConstraintViolationException` **não** está entre as capturadas pelo comando — viraria stack
 * trace no meio do lote, com os arquivos anteriores já gravados.
 *
 * E há a razão de fundo: cada leitor versiona a si mesmo, então `versao_leitor = 1` significa coisas
 * diferentes conforme o layout. Sem o tipo, a versão de um leitor colide com a de outro.
 */
#[ORM\UniqueConstraint(
    name: 'uniq_cobranca_relatorio_arquivo',
    columns: ['tenant_id', 'carteira_id', 'arquivo_hash', 'versao_leitor', 'tipo']
)]
#[ORM\HasLifecycleCallbacks]
class RelatorioImportado implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: Carteira::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Carteira $carteira = null;

    #[ORM\Column(length: 20, enumType: TipoRelatorioContabil::class)]
    private TipoRelatorioContabil $tipo = TipoRelatorioContabil::Inadimplencia;

    #[ORM\Column(length: 255)]
    private string $arquivoNome = '';

    /** sha256 do conteúdo — é a chave de idempotência da leitura. */
    #[ORM\Column(length: 64)]
    private string $arquivoHash = '';

    /** Rodapé `Emissão: dd/mm/aaaa hh:mm` — quando a contabilidade gerou o arquivo. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $emitidoEm = null;

    /** Campo `Inadimplência até:` — até quando ela calculou. Ver INV-E3. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dadosAte = null;

    /**
     * Taxas que a CONTABILIDADE declarou nesta emissão (linha 4 do cabeçalho), em basis points:
     * `{"juros": 100, "multa": 200, "honorarios": 2000}`. Guardar isto é o que permite perceber que
     * ela mudou uma regra — comparar com a config da carteira é da calibração, não daqui.
     *
     * @var array<string, int>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $configDeclarada = null;

    /**
     * Campo `Número de unidades` do cabeçalho.
     *
     * ⚠️ É a contagem de unidades INADIMPLENTES da emissão, não o tamanho da carteira, e diverge da
     * contagem real de unidades distintas do arquivo (medido: TL2 declara 116 e tem 123; AMLI
     * declara 15 e tem 21). Guardar sim; **nunca asserir igualdade sobre ele**.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $unidadesDeclaradas = null;

    /** Linhas do arquivo. A soma dos seis baldes de `BlocoRelatorio` tem que dar exatamente isto. */
    #[ORM\Column(type: 'integer')]
    private int $linhasTotal = 0;

    #[ORM\Column(type: 'integer')]
    private int $linhasDados = 0;

    #[ORM\Column(type: 'integer')]
    private int $linhasTotalizador = 0;

    /** Incrementado quando o leitor muda. Entra na chave única — ver INV-E1. */
    #[ORM\Column(type: 'integer')]
    private int $versaoLeitor = 1;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lidoEm;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $lidoPor = null;

    #[ORM\PrePersist]
    public function aoInserir(): void
    {
        $this->lidoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getCarteira(): ?Carteira
    {
        return $this->carteira;
    }

    public function setCarteira(Carteira $carteira): self
    {
        $this->carteira = $carteira;

        return $this;
    }

    public function getTipo(): TipoRelatorioContabil
    {
        return $this->tipo;
    }

    public function setTipo(TipoRelatorioContabil $tipo): self
    {
        $this->tipo = $tipo;

        return $this;
    }

    public function getArquivoNome(): string
    {
        return $this->arquivoNome;
    }

    public function setArquivoNome(string $arquivoNome): self
    {
        $this->arquivoNome = $arquivoNome;

        return $this;
    }

    public function getArquivoHash(): string
    {
        return $this->arquivoHash;
    }

    public function setArquivoHash(string $arquivoHash): self
    {
        $this->arquivoHash = $arquivoHash;

        return $this;
    }

    public function getEmitidoEm(): ?\DateTimeImmutable
    {
        return $this->emitidoEm;
    }

    public function setEmitidoEm(?\DateTimeImmutable $emitidoEm): self
    {
        $this->emitidoEm = $emitidoEm;

        return $this;
    }

    public function getDadosAte(): ?\DateTimeImmutable
    {
        return $this->dadosAte;
    }

    public function setDadosAte(?\DateTimeImmutable $dadosAte): self
    {
        $this->dadosAte = $dadosAte;

        return $this;
    }

    /** @return array<string, int>|null */
    public function getConfigDeclarada(): ?array
    {
        return $this->configDeclarada;
    }

    /** @param array<string, int>|null $configDeclarada */
    public function setConfigDeclarada(?array $configDeclarada): self
    {
        $this->configDeclarada = $configDeclarada;

        return $this;
    }

    public function getUnidadesDeclaradas(): ?int
    {
        return $this->unidadesDeclaradas;
    }

    public function setUnidadesDeclaradas(?int $unidadesDeclaradas): self
    {
        $this->unidadesDeclaradas = $unidadesDeclaradas;

        return $this;
    }

    public function getLinhasTotal(): int
    {
        return $this->linhasTotal;
    }

    public function setLinhasTotal(int $linhasTotal): self
    {
        $this->linhasTotal = $linhasTotal;

        return $this;
    }

    public function getLinhasDados(): int
    {
        return $this->linhasDados;
    }

    public function setLinhasDados(int $linhasDados): self
    {
        $this->linhasDados = $linhasDados;

        return $this;
    }

    public function getLinhasTotalizador(): int
    {
        return $this->linhasTotalizador;
    }

    public function setLinhasTotalizador(int $linhasTotalizador): self
    {
        $this->linhasTotalizador = $linhasTotalizador;

        return $this;
    }

    public function getVersaoLeitor(): int
    {
        return $this->versaoLeitor;
    }

    public function setVersaoLeitor(int $versaoLeitor): self
    {
        $this->versaoLeitor = $versaoLeitor;

        return $this;
    }

    public function getLidoEm(): \DateTimeImmutable
    {
        return $this->lidoEm;
    }

    public function getLidoPor(): ?User
    {
        return $this->lidoPor;
    }

    public function setLidoPor(?User $lidoPor): self
    {
        $this->lidoPor = $lidoPor;

        return $this;
    }
}
