<?php

declare(strict_types=1);

namespace App\Cliente\Entity;

use App\Cliente\Repository\ClienteHistoricoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma linha da aba "Histórico" da janela de Cadastro do cliente (desenho 1.2.3, `cq.hist`; E12 fatia 3d): quando e o
 * quê, como "Ana Souza alterou nome, CPF, estado civil". É o histórico PRÓPRIO do cadastro, separado do audit_log de
 * propósito: o texto leva só os RÓTULOS dos campos, nunca o valor (CPF, RG e endereço não são copiados para cá).
 *
 * Só nasce, nunca é editada. O texto já traz o nome de quem fez, então a linha continua legível quando o usuário sai
 * do escritório (o autor vira null por SET NULL). Some com o cliente (CASCADE) e com o escritório (purga).
 */
#[ORM\Entity(repositoryClass: ClienteHistoricoRepository::class)]
#[ORM\Table(name: 'cliente_historico')]
#[ORM\Index(name: 'idx_cliente_historico_cliente_em', columns: ['cliente_id', 'em'])]
class ClienteHistorico implements TenantAware
{
    public const TAMANHO_DO_TEXTO = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'em', type: 'datetime_immutable')]
    private \DateTimeImmutable $em;

    #[ORM\Column(length: self::TAMANHO_DO_TEXTO)]
    private string $texto;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Cliente::class)]
        #[ORM\JoinColumn(name: 'cliente_id', nullable: false, onDelete: 'CASCADE')]
        private Cliente $cliente,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'autor_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $autor,
        string $texto,
    ) {
        $texto = trim($texto);
        if ($texto === '') {
            throw new \DomainException('Linha de histórico sem texto.');
        }
        $this->texto = mb_substr($texto, 0, self::TAMANHO_DO_TEXTO);
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

    public function getCliente(): Cliente
    {
        return $this->cliente;
    }

    public function getAutor(): ?User
    {
        return $this->autor;
    }

    public function getEm(): \DateTimeImmutable
    {
        return $this->em;
    }

    public function getTexto(): string
    {
        return $this->texto;
    }
}
