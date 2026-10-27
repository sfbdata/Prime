<?php

declare(strict_types=1);

namespace App\Dashboard\Entity;

use App\Dashboard\Repository\AcompanhamentoDaPessoaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Esta pessoa está em acompanhamento": decisão de um gestor (Master ou primário) registrada no perfil
 * individual do painel. Registro mínimo, de propósito: SEM texto livre, sem nota e sem classificação
 * (LGPD art. 20: nada é decidido sozinho, o painel só guarda o que uma pessoa decidiu).
 *
 * Só um acompanhamento aberto por pessoa e escritório (índice único parcial). Encerrar não apaga a linha:
 * ela fica para a auditoria. Renovar muda só a data de revisão.
 *
 * Anatomia:
 *   - `pessoa_id` NOT NULL com CASCADE: se a conta da pessoa sumir, o registro sobre ela some junto;
 *   - `criado_por_id` NOT NULL sem ação: o sistema não apaga usuário (só desativa);
 *   - `tenant_id` NOT NULL sem ação: TenantAware, apagada pela purga do escritório.
 */
#[ORM\Entity(repositoryClass: AcompanhamentoDaPessoaRepository::class)]
#[ORM\Table(name: 'dashboard_acompanhamento_pessoa')]
#[ORM\UniqueConstraint(name: 'uniq_dashboard_acomp_aberto', columns: ['tenant_id', 'pessoa_id'], options: ['where' => '(encerrado_em IS NULL)'])]
#[ORM\Index(name: 'idx_dashboard_acomp_tenant_pessoa', columns: ['tenant_id', 'pessoa_id', 'encerrado_em'])]
class AcompanhamentoDaPessoa implements Auditavel, TenantAware
{
    /** Prazos de revisão aceitos, em dias: 24 horas, 48 horas e 7 dias. */
    public const PRAZOS_EM_DIAS = [1, 2, 7];
    public const PRAZO_PADRAO   = 7;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(name: 'revisar_em', type: 'date_immutable')]
    private \DateTimeImmutable $revisarEm;

    #[ORM\Column(name: 'encerrado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $encerradoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'encerrado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $encerradoPor = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'pessoa_id', nullable: false, onDelete: 'CASCADE')]
        private User $pessoa,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'criado_por_id', nullable: false)]
        private User $criadoPor,
        \DateTimeImmutable $agora,
        int $prazoEmDias,
    ) {
        $this->criadoEm  = $agora;
        $this->revisarEm = self::dataDaRevisao($agora, $prazoEmDias);
    }

    /** A data (sem hora) da revisão: hoje mais o prazo. Prazo fora de 1/2/7 é recusado. */
    public static function dataDaRevisao(\DateTimeImmutable $agora, int $prazoEmDias): \DateTimeImmutable
    {
        if (!in_array($prazoEmDias, self::PRAZOS_EM_DIAS, true)) {
            throw new \DomainException('Prazo de revisão inválido.');
        }

        return $agora->setTime(0, 0)->modify('+' . $prazoEmDias . ' days');
    }

    public function renovar(\DateTimeImmutable $agora, int $prazoEmDias): void
    {
        $this->exigirAberto();
        $this->revisarEm = self::dataDaRevisao($agora, $prazoEmDias);
    }

    public function encerrar(User $por, \DateTimeImmutable $agora): void
    {
        $this->exigirAberto();
        $this->encerradoEm  = $agora;
        $this->encerradoPor = $por;
    }

    public function estaAberto(): bool
    {
        return $this->encerradoEm === null;
    }

    /** Vencido = a data de revisão ficou para trás (hoje já conta como no prazo). Nada acontece sozinho. */
    public function estaVencido(\DateTimeImmutable $agora): bool
    {
        return $this->estaAberto() && $this->revisarEm < $agora->setTime(0, 0);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getPessoa(): User
    {
        return $this->pessoa;
    }

    public function getCriadoPor(): User
    {
        return $this->criadoPor;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getRevisarEm(): \DateTimeImmutable
    {
        return $this->revisarEm;
    }

    public function getEncerradoEm(): ?\DateTimeImmutable
    {
        return $this->encerradoEm;
    }

    public function getEncerradoPor(): ?User
    {
        return $this->encerradoPor;
    }

    private function exigirAberto(): void
    {
        if (!$this->estaAberto()) {
            throw new \DomainException('Este acompanhamento já foi encerrado.');
        }
    }
}
