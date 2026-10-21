<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\Repository\RecursoEstadoRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Estado de uma tela ou função do catálogo ({@see \App\Master\Service\CatalogoDeRecursos}), ligada
 * ou desligada pelo Master (spec docs/specs/perfis-1.2-telas-e-recursos.md, R2, R4 e R7).
 *
 * GLOBAL, sem tenant, de propósito: é configuração da PLATAFORMA, não dado de escritório. Uma tela
 * desligada some para todas as contas ao mesmo tempo, e só o Master (ROLE_SUPER_ADMIN, que já está
 * acima dos escritórios) a altera. Não há dado de cliente aqui; por isso também não entra na purga
 * de escritório nem no TenantFilter.
 *
 * Uma linha por chave (constraint única nomeada). Sem linha = ativo. Desligado com `ate` volta a
 * valer como ativo depois das 23:59:59 desse dia, sem cron: quem lê compara com hoje (R4).
 * Auditável (R7): o AuditLogSubscriber grava cada desligar e religar.
 */
#[ORM\Entity(repositoryClass: RecursoEstadoRepository::class)]
#[ORM\Table(name: 'recurso_estado')]
#[ORM\UniqueConstraint(name: 'uniq_recurso_estado_chave', columns: ['chave'])]
#[ORM\Index(name: 'idx_recurso_estado_alterado_por', columns: ['alterado_por_id'])]
class RecursoEstado implements Auditavel
{
    public const MOTIVO_MAXIMO = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $ativo = true;

    #[ORM\Column(length: self::MOTIVO_MAXIMO, nullable: true)]
    private ?string $motivo = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $ate = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradoPor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradoEm;

    public function __construct(
        #[ORM\Column(length: 40)]
        private string $chave,
    ) {
        $this->alteradoEm = new \DateTimeImmutable();
    }

    /** Desliga para quem não é Master. Motivo vazio vira "sem motivo"; `ate` nula = sem previsão. */
    public function desativar(?string $motivo, ?\DateTimeImmutable $ate, ?User $por, \DateTimeImmutable $em): void
    {
        $this->ativo = false;
        $this->motivo = $motivo;
        $this->ate = $ate?->setTime(0, 0);
        $this->registrarAlteracao($por, $em);
    }

    /** Religa: limpa motivo e data, para o próximo desligar começar do zero. */
    public function reativar(?User $por, \DateTimeImmutable $em): void
    {
        $this->ativo = true;
        $this->motivo = null;
        $this->ate = null;
        $this->registrarAlteracao($por, $em);
    }

    /** Estado que vale hoje (R4): desligado com data vencida conta como ativo. */
    public function estaAtivoEm(\DateTimeImmutable $hoje): bool
    {
        if ($this->ativo) {
            return true;
        }

        return $this->ate !== null && $hoje->format('Y-m-d') > $this->ate->format('Y-m-d');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChave(): string
    {
        return $this->chave;
    }

    public function isAtivo(): bool
    {
        return $this->ativo;
    }

    public function getMotivo(): ?string
    {
        return $this->motivo;
    }

    public function getAte(): ?\DateTimeImmutable
    {
        return $this->ate;
    }

    public function getAlteradoPor(): ?User
    {
        return $this->alteradoPor;
    }

    public function getAlteradoEm(): \DateTimeImmutable
    {
        return $this->alteradoEm;
    }

    private function registrarAlteracao(?User $por, \DateTimeImmutable $em): void
    {
        $this->alteradoPor = $por;
        $this->alteradoEm = $em;
    }
}
