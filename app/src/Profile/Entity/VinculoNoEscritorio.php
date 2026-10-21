<?php

declare(strict_types=1);

namespace App\Profile\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Enum\VinculoColaborador;
use App\Profile\Repository\VinculoNoEscritorioRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tipo de vínculo da pessoa com UM escritório e as datas do contrato (desenho "07 - Meu Perfil",
 * VS 18.10.26). Uma linha por escritório e pessoa. Mora no domínio Profile, ao lado do UserTenant
 * legado, para não crescer a entidade de identidade; a data de admissão continua no UserTenant,
 * de onde o espelho de ponto já lê. Spec: docs/specs/meu-perfil-pagina.md.
 * Auditável: valor antigo e novo, autor e data vão para o audit_log (o secundário edita as
 * próprias datas do contrato, decisão de 19/10/2026).
 */
#[ORM\Entity(repositoryClass: VinculoNoEscritorioRepository::class)]
#[ORM\Table(name: 'vinculo_no_escritorio')]
#[ORM\UniqueConstraint(name: 'uniq_vinculo_no_escritorio_tenant_user', columns: ['tenant_id', 'user_id'])]
#[ORM\Index(name: 'idx_vinculo_no_escritorio_user', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
class VinculoNoEscritorio implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'tipo', length: 20, enumType: VinculoColaborador::class)]
    private VinculoColaborador $tipo = VinculoColaborador::Clt;

    #[ORM\Column(name: 'contrato_inicio', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $contratoInicio = null;

    #[ORM\Column(name: 'cnpj', length: 14, nullable: true)]
    private ?string $cnpj = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(name: 'atualizado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
    ) {
        $this->criadoEm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getTipo(): VinculoColaborador
    {
        return $this->tipo;
    }

    public function definirTipo(VinculoColaborador $tipo): void
    {
        $this->tipo = $tipo;
    }

    public function getContratoInicio(): ?\DateTimeImmutable
    {
        return $this->contratoInicio;
    }

    public function definirContratoInicio(?\DateTimeImmutable $contratoInicio): void
    {
        $this->contratoInicio = $contratoInicio;
    }

    public function getCnpj(): ?string
    {
        return $this->cnpj;
    }

    /** Guarda só os dígitos; vazio vira null. */
    public function definirCnpj(?string $cnpj): void
    {
        $digitos     = preg_replace('/\D/', '', (string) $cnpj) ?? '';
        $this->cnpj  = $digitos === '' ? null : $digitos;
    }
}
