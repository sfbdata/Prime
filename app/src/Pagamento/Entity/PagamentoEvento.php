<?php

declare(strict_types=1);

namespace App\Pagamento\Entity;

use App\Entity\Tenant\Tenant;
use App\Pagamento\Repository\PagamentoEventoRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * A trilha imutável de um pagamento: criado, aviso recebido, confirmado, divergente, manual. É o "rastreio
 * automático" de quem, quando e quanto. Só se acrescenta; nada aqui é editado depois. O `resumo` nunca leva PII.
 */
#[ORM\Entity(repositoryClass: PagamentoEventoRepository::class)]
#[ORM\Table(name: 'pagamento_evento')]
#[ORM\Index(name: 'idx_pagamento_evento_pagamento', columns: ['pagamento_id', 'em'])]
class PagamentoEvento implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $em;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Pagamento::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Pagamento $pagamento,
        #[ORM\Column(type: 'string', length: 30)]
        private string $tipo,
        /** `banco`, `sistema` ou `manual:<id do usuário>`. */
        #[ORM\Column(type: 'string', length: 60)]
        private string $quem,
        #[ORM\Column(type: 'string', length: 300)]
        private string $resumo,
    ) {
        $this->em = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getPagamento(): Pagamento
    {
        return $this->pagamento;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function getQuem(): string
    {
        return $this->quem;
    }

    public function getResumo(): string
    {
        return $this->resumo;
    }

    public function getEm(): \DateTimeImmutable
    {
        return $this->em;
    }
}
