<?php

declare(strict_types=1);

namespace App\Comercial\Entity;

use App\Comercial\Repository\DestinoDaCampanhaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um destinatário de uma execução de campanha (spec `comercial-e-emails.md` §8.5): o expansor materializa a execução
 * devida aqui (UNIQUE em campanha, execução e e-mail: materializar de novo não duplica) e libera em lotes, gravando o
 * `envio_id` do Carteiro na mesma transação. `envio_id` nulo = ainda não entregue ao Carteiro.
 *
 * Retenção (§3.2, P4): depois de 24 meses, o `email` vira hash SHA-256 com sal da aplicação e o `nome` fica nulo.
 * Plataforma (sem escritório): não é TenantAware nem Auditavel. Gravado e lido por SQL.
 */
#[ORM\Entity(repositoryClass: DestinoDaCampanhaRepository::class)]
#[ORM\Table(name: 'comercial_campanha_destino')]
#[ORM\UniqueConstraint(name: 'uniq_comercial_destino', columns: ['campanha_id', 'execucao', 'email'])]
#[ORM\Index(name: 'idx_comercial_destino_envio', columns: ['campanha_id', 'execucao', 'envio_id'])]
#[ORM\Index(name: 'idx_comercial_destino_criado', columns: ['criado_em'])]
class DestinoDaCampanha
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CampanhaDeEmail::class)]
    #[ORM\JoinColumn(name: 'campanha_id', nullable: false, onDelete: 'CASCADE')]
    private CampanhaDeEmail $campanha;

    #[ORM\Column(type: 'integer')]
    private int $execucao = 1;

    #[ORM\Column(length: 180)]
    private string $email = '';

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $nome = null;

    /** O registro em `comercial_envio` (sem FK: a purga dos envios não pode soltar um destino para sair de novo). */
    #[ORM\Column(name: 'envio_id', type: 'integer', nullable: true)]
    private ?int $envioId = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct(CampanhaDeEmail $campanha)
    {
        $this->campanha = $campanha;
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
