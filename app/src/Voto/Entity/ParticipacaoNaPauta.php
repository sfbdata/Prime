<?php

declare(strict_types=1);

namespace App\Voto\Entity;

use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A unidade votou na pauta (spec docs/specs/voto-assembleia-condominio.md, seções 2 e 3): unidade, pauta e hora, e NADA
 * sobre a opção. O voto vai para o contador da opção na mesma transação, por SQL direto.
 *
 * Um voto por pauta: UNIQUE(unidade_id, pauta_id). Não é `Auditavel` (sigilo, seção 2): a hora no audit_log
 * coincidiria com a mudança do contador. O tenant vem da unidade (nunca de fora).
 */
#[ORM\Entity(repositoryClass: ParticipacaoNaPautaRepository::class)]
#[ORM\Table(name: 'assembleia_participacao', schema: 'voto')]
#[ORM\UniqueConstraint(name: 'uniq_voto_assembleia_participacao', columns: ['unidade_id', 'pauta_id'])]
class ParticipacaoNaPauta implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $registradaEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: UnidadeDaAssembleia::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private UnidadeDaAssembleia $unidade,
        #[ORM\ManyToOne(targetEntity: PautaDaAssembleia::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private PautaDaAssembleia $pauta,
        ?\DateTimeImmutable $registradaEm = null,
    ) {
        if ($unidade->getAssembleia() !== $pauta->getAssembleia()) {
            throw new OperacaoInvalidaNaAssembleiaException('A unidade não é desta assembleia.');
        }
        $this->tenant = $unidade->getTenant();
        $this->registradaEm = $registradaEm ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getUnidade(): UnidadeDaAssembleia
    {
        return $this->unidade;
    }

    public function getPauta(): PautaDaAssembleia
    {
        return $this->pauta;
    }

    public function getRegistradaEm(): \DateTimeImmutable
    {
        return $this->registradaEm;
    }
}
