<?php

declare(strict_types=1);

namespace App\Sync\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use App\Sync\Enum\EstadoReconhecimento;
use App\Sync\Repository\ReconhecimentoPendenteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dúvida do reconhecimento do Drive (spec `docs/specs/drive-reconhecimento.md`): uma subpasta direta da pasta-raiz
 * que o sistema não conseguiu ligar sozinho a uma pasta do escritório, porque nenhuma ou mais de uma pasta casou
 * pelo nome. Uma linha por subpasta do Drive e escritório (unique): reexecutar o reconhecimento não duplica, e a
 * dúvida decidida não volta.
 *
 * Enquanto estiver pendente, o envio automático não cria pasta nova no Drive para as candidatas (senão o Drive
 * ficaria com duas pastas do mesmo caso) e a importação não cria pasta no sistema a partir da subpasta.
 */
#[ORM\Entity(repositoryClass: ReconhecimentoPendenteRepository::class)]
#[ORM\Table(name: 'sync_drive_reconhecimento')]
#[ORM\UniqueConstraint(name: 'uniq_sync_drive_reconhecimento_folder', columns: ['tenant_id', 'drive_folder_id'])]
class ReconhecimentoPendente implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(enumType: EstadoReconhecimento::class, length: 20)]
    private EstadoReconhecimento $estado = EstadoReconhecimento::Pendente;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decididoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decididoPor = null;

    /** Pasta do sistema ligada na confirmação (id; a pasta pode sumir depois sem apagar o histórico). */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $pastaLigadaId = null;

    /**
     * @param list<int> $candidatos ids das pastas do escritório que casaram pelo nome (vazio quando nenhuma)
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\Column(length: 255)]
        private string $driveFolderId,
        #[ORM\Column(type: 'text')]
        private string $nomeNoDrive,
        #[ORM\Column(type: 'json', options: ['jsonb' => true])]
        private array $candidatos,
    ) {
        $this->candidatos = array_map('intval', $candidatos);
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function confirmar(int $pastaId, ?User $por): void
    {
        $this->exigirPendente();
        $this->estado = EstadoReconhecimento::Confirmado;
        $this->pastaLigadaId = $pastaId;
        $this->registrarDecisao($por);
    }

    public function ignorar(?User $por): void
    {
        $this->exigirPendente();
        $this->estado = EstadoReconhecimento::Ignorado;
        $this->registrarDecisao($por);
    }

    public function estaPendente(): bool
    {
        return $this->estado === EstadoReconhecimento::Pendente;
    }

    public function citaComoCandidata(int $pastaId): bool
    {
        return in_array($pastaId, $this->candidatos, true);
    }

    private function exigirPendente(): void
    {
        if (!$this->estaPendente()) {
            throw new \DomainException('Esta pasta do Drive já foi decidida.');
        }
    }

    private function registrarDecisao(?User $por): void
    {
        $this->decididoEm = new \DateTimeImmutable();
        $this->decididoPor = $por;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getDriveFolderId(): string
    {
        return $this->driveFolderId;
    }

    public function getNomeNoDrive(): string
    {
        return $this->nomeNoDrive;
    }

    /** @return list<int> */
    public function getCandidatos(): array
    {
        return array_map('intval', $this->candidatos);
    }

    public function getEstado(): EstadoReconhecimento
    {
        return $this->estado;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getDecididoEm(): ?\DateTimeImmutable
    {
        return $this->decididoEm;
    }

    public function getDecididoPor(): ?User
    {
        return $this->decididoPor;
    }

    public function getPastaLigadaId(): ?int
    {
        return $this->pastaLigadaId;
    }
}
