<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\Enum\TipoDeRegistroDeBackup;
use App\Master\Repository\RegistroDeBackupRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Backup ou teste de restauração registrado à mão pelo Master (spec docs/specs/master-desempenho.md §2, D3).
 * GLOBAL, sem tenant. Auditável.
 */
#[ORM\Entity(repositoryClass: RegistroDeBackupRepository::class)]
#[ORM\Table(name: 'desempenho_backup')]
#[ORM\Index(name: 'idx_desempenho_backup_registrado_por', columns: ['registrado_por_id'])]
#[ORM\Index(name: 'idx_desempenho_backup_tipo_em', columns: ['tipo', 'registrado_em'])]
class RegistroDeBackup implements Auditavel
{
    public const OBSERVACAO_MAXIMA = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: self::OBSERVACAO_MAXIMA, nullable: true)]
    private ?string $observacao;

    public function __construct(
        #[ORM\Column(length: 12, enumType: TipoDeRegistroDeBackup::class)]
        private TipoDeRegistroDeBackup $tipo,
        #[ORM\Column(type: 'boolean')]
        private bool $ok,
        ?string $observacao,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $registradoEm,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'registrado_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $registradoPor = null,
    ) {
        $observacao = trim((string) $observacao);
        $this->observacao = $observacao === '' ? null : mb_substr($observacao, 0, self::OBSERVACAO_MAXIMA);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTipo(): TipoDeRegistroDeBackup
    {
        return $this->tipo;
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function getObservacao(): ?string
    {
        return $this->observacao;
    }

    public function getRegistradoEm(): \DateTimeImmutable
    {
        return $this->registradoEm;
    }

    public function getRegistradoPor(): ?User
    {
        return $this->registradoPor;
    }
}
