<?php

declare(strict_types=1);

namespace App\Agenda\Entity;

use App\Agenda\Repository\InscricaoDePushRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um navegador que aceitou receber os lembretes da Agenda por push, mesmo fechado (entrega 5b, prometida no
 * bj-lembretes.js do desenho). O endpoint é do serviço de push do navegador; as chaves cifram o conteúdo.
 * O fuso é o do navegador: o aviso sai na hora de parede que a pessoa vê na Agenda.
 */
#[ORM\Entity(repositoryClass: InscricaoDePushRepository::class)]
#[ORM\Table(name: 'agenda_push_inscricao')]
#[ORM\UniqueConstraint(name: 'uniq_agenda_push_inscricao_endpoint', columns: ['endpoint_hash'])]
#[ORM\Index(name: 'idx_agenda_push_inscricao_usuario', columns: ['usuario_id'])]
class InscricaoDePush implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'endpoint_hash', length: 64)]
    private string $endpointHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(type: 'text')]
        private string $endpoint,
        #[ORM\Column(length: 255)]
        private string $p256dh,
        #[ORM\Column(length: 100)]
        private string $auth,
        #[ORM\Column(length: 64)]
        private string $fuso,
    ) {
        $this->endpointHash = self::hashDo($endpoint);
        $this->criadoEm = new \DateTimeImmutable();
    }

    public static function hashDo(string $endpoint): string
    {
        return hash('sha256', $endpoint);
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
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }
    public function getP256dh(): string
    {
        return $this->p256dh;
    }
    public function getAuth(): string
    {
        return $this->auth;
    }
    public function getFuso(): string
    {
        return $this->fuso;
    }
}
