<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaAndamentoRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Andamento da pasta em fases (desenho 1.2.3, trilha sob o nome da pasta, `bj-pasta-fases`). Uma linha por
 * pasta e por via: a judicial e a administrativa ("Administrativo sem processo") têm trilhas próprias, como o
 * desenho guarda em `N` e `N:adm`.
 *
 * `atual` é o índice da fase em que a pasta está. Em `marcos`:
 *  - `em`: índice => data e hora (ISO 8601) em que a fase foi alcançada;
 *  - `pul`: índices de fases que ficaram para trás sem registro (aparecem em vermelho, "pulada");
 *  - `extra`: fases acrescentadas depois da base ao reativar a pasta (Reativada, Em andamento, Finalizada).
 * A regra de mudar de fase mora no {@see \App\Pasta\Service\AndamentoDaPasta}.
 */
#[ORM\Entity(repositoryClass: PastaAndamentoRepository::class)]
#[ORM\Table(name: 'pasta_andamento')]
#[ORM\UniqueConstraint(name: 'uniq_pasta_andamento_pasta_via', columns: ['pasta_id', 'via'])]
#[ORM\Index(name: 'idx_pasta_andamento_tenant', columns: ['tenant_id'])]
class PastaAndamento implements TenantAware, Auditavel
{
    public const VIA_JUDICIAL       = 'judicial';
    public const VIA_ADMINISTRATIVA = 'administrativa';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private int $atual = 0;

    /** @var array{em?: array<int|string, string>, pul?: list<int>, extra?: list<array{0: string, 1: string}>} */
    #[ORM\Column(type: 'json')]
    private array $marcos = ['em' => [], 'pul' => [], 'extra' => []];

    #[ORM\Column(name: 'atualizado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadoEm;

    /**
     * A pasta alcançou a fase Protocolo e ela não ficou pulada (desenho `protocolado`: `atual >= 4 && !pul[4]`).
     * Copiado do JSON a cada gravação para a Lista poder filtrar "Protocolar" por consulta.
     */
    #[ORM\Column(name: 'passou_protocolo', type: 'boolean', options: ['default' => false])]
    private bool $passouProtocolo = false;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Pasta::class)]
        #[ORM\JoinColumn(name: 'pasta_id', nullable: false, onDelete: 'CASCADE')]
        private Pasta $pasta,
        #[ORM\Column(length: 20)]
        private string $via,
    ) {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function getPasta(): Pasta { return $this->pasta; }
    public function getVia(): string { return $this->via; }
    public function getAtual(): int { return $this->atual; }
    public function getAtualizadoEm(): \DateTimeImmutable { return $this->atualizadoEm; }
    public function passouProtocolo(): bool { return $this->passouProtocolo; }

    /** @return array{em: array<int, string>, pul: list<int>, extra: list<array{0: string, 1: string}>} */
    public function getMarcos(): array
    {
        $em = [];
        foreach ($this->marcos['em'] ?? [] as $k => $v) {
            $em[(int) $k] = (string) $v;
        }

        return ['em' => $em, 'pul' => array_values(array_map('intval', $this->marcos['pul'] ?? [])), 'extra' => array_values($this->marcos['extra'] ?? [])];
    }

    /** @param array{em: array<int, string>, pul: list<int>, extra: list<array{0: string, 1: string}>} $marcos */
    public function gravar(int $atual, array $marcos): void
    {
        $this->atual = $atual;
        $this->marcos = ['em' => (object) $marcos['em'], 'pul' => array_values(array_unique($marcos['pul'])), 'extra' => array_values($marcos['extra'])];
        $this->passouProtocolo = $atual >= \App\Pasta\Service\AndamentoDaPasta::IDX_PROTOCOLO && !in_array(\App\Pasta\Service\AndamentoDaPasta::IDX_PROTOCOLO, $marcos['pul'], true);
        $this->atualizadoEm = new \DateTimeImmutable();
    }
}
