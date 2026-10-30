<?php

declare(strict_types=1);

namespace App\Calculo\Entity;

use App\Calculo\Repository\CalculoEmitidoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Registro de um PDF do Cálculo emitido (spec calculo-atualizacao-monetaria §7). Guarda só o que o próprio PDF já
 * mostra (escritório, data de emissão, data final e total) mais o SHA-256 dos bytes entregues, para a página pública
 * `/calculo/verificar` confirmar que o código existe e que o arquivo apresentado é o original. Não guarda processo,
 * partes nem valores: o conteúdo do cálculo continua só no navegador de quem o fez. Só se acrescenta, nunca se altera.
 */
#[ORM\Entity(repositoryClass: CalculoEmitidoRepository::class)]
#[ORM\Table(name: 'calculo_emitido')]
#[ORM\Index(name: 'idx_calculo_emitido_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_calculo_emitido_codigo', columns: ['codigo'])]
class CalculoEmitido implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $usuario,
        #[ORM\Column(length: 16)]
        private string $codigo,
        #[ORM\Column(name: 'emitido_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $emitidoEm,
        #[ORM\Column(name: 'atualizado_ate', type: 'date_immutable')]
        private \DateTimeImmutable $atualizadoAte,
        /** Total do demonstrativo, em reais com 2 casas, como impresso no PDF. */
        #[ORM\Column(type: 'decimal', precision: 16, scale: 2)]
        private string $total,
        #[ORM\Column(name: 'hash_pdf', length: 64)]
        private string $hashPdf,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getUsuario(): ?User
    {
        return $this->usuario;
    }

    public function getCodigo(): string
    {
        return $this->codigo;
    }

    public function getEmitidoEm(): \DateTimeImmutable
    {
        return $this->emitidoEm;
    }

    public function getAtualizadoAte(): \DateTimeImmutable
    {
        return $this->atualizadoAte;
    }

    public function getTotal(): string
    {
        return $this->total;
    }

    public function getHashPdf(): string
    {
        return $this->hashPdf;
    }

    /** O arquivo apresentado é o mesmo que saiu daqui? Comparação em tempo constante. */
    public function confereComOHash(string $hashApresentado): bool
    {
        return hash_equals($this->hashPdf, strtolower($hashApresentado));
    }
}
