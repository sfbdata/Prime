<?php

declare(strict_types=1);

namespace App\Voto\Entity;

use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\VotoNominalRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Registro nominal do VOTO ABERTO (desenho 18, "Aberto: nominal"): a unidade, a escolha dela e a hora, em segundos.
 * Só existe para pauta configurada com sigilo aberto antes de publicar; a pauta secreta nunca grava linha aqui
 * (a guarda está em {@see PautaDaAssembleia::votoAberto()} e no RegistrarVotoUseCase). Uma linha por escolha: a
 * escolha é uma opção da pauta ou um número nulo (voto em número que não existe), nunca as duas.
 *
 * Não é `Auditavel` (não vai para o audit_log). O tenant vem da pauta (nunca de fora).
 */
#[ORM\Entity(repositoryClass: VotoNominalRepository::class)]
#[ORM\Table(name: 'assembleia_voto_nominal', schema: 'voto')]
class VotoNominal implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: UnidadeDaAssembleia::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private UnidadeDaAssembleia $unidade,
        #[ORM\ManyToOne(targetEntity: PautaDaAssembleia::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private PautaDaAssembleia $pauta,
        #[ORM\ManyToOne(targetEntity: OpcaoDaPauta::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?OpcaoDaPauta $opcao,
        #[ORM\Column(length: 5, nullable: true)]
        private ?string $numeroNulo,
        #[ORM\Column(type: 'decimal', precision: 14, scale: 6)]
        private string $peso,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $registradaEm,
    ) {
        if ($unidade->getAssembleia() !== $pauta->getAssembleia()) {
            throw new OperacaoInvalidaNaAssembleiaException('A unidade não é desta assembleia.');
        }
        if (!$pauta->votoAberto()) {
            throw new OperacaoInvalidaNaAssembleiaException('Só a pauta de voto aberto guarda o voto nominal.');
        }
        if ((null === $opcao) === (null === $numeroNulo)) {
            throw new OperacaoInvalidaNaAssembleiaException('O voto nominal é uma opção ou um número nulo.');
        }
        if (null !== $opcao && $opcao->getPauta() !== $pauta) {
            throw new OperacaoInvalidaNaAssembleiaException('A opção não é desta pauta.');
        }
        $this->tenant = $unidade->getTenant();
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

    public function getOpcao(): ?OpcaoDaPauta
    {
        return $this->opcao;
    }

    public function getNumeroNulo(): ?string
    {
        return $this->numeroNulo;
    }

    public function getPeso(): string
    {
        return $this->peso;
    }

    public function getRegistradaEm(): \DateTimeImmutable
    {
        return $this->registradaEm;
    }
}
