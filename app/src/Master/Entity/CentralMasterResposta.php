<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\Repository\CentralMasterRespostaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma resposta dada pela Central de Notificações (spec master-central-de-notificacoes.md, D1, D5 e D6). O histórico
 * é COMPARTILHADO entre os Masters: "Respondida" vale para todos, e nenhum responde duas vezes sem saber.
 *
 * O texto fica aqui (o conteúdo do envio no Comercial é apagado depois de enviado). `canal` diz por onde foi:
 * `email` (pelo Carteiro, `envio_id` aponta para `comercial_envio`) ou `central` (aviso no sino de quem escreveu).
 * `destino` é o e-mail usado ou o id do usuário avisado. `contribua` é a resposta que já estava na página do
 * Contribua, guardada aqui antes de a Central gravar outra por cima na sugestão (sem destino).
 *
 * NÃO é TenantAware (D3); `tenant_id` é o escritório da origem, só para a purga. Fora da auditoria: o `audit_log`
 * gravaria o texto no escritório da SESSÃO do Master.
 */
#[ORM\Entity(repositoryClass: CentralMasterRespostaRepository::class)]
#[ORM\Table(name: 'central_master_resposta')]
#[ORM\Index(name: 'idx_central_master_resposta_origem', columns: ['origem', 'id_origem'])]
#[ORM\Index(name: 'idx_central_master_resposta_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_central_master_resposta_por', columns: ['por_id'])]
class CentralMasterResposta
{
    public const CANAL_EMAIL = 'email';
    public const CANAL_CENTRAL = 'central';
    /** A resposta que já estava na página do Contribua, preservada antes de a Central gravar uma nova por cima. */
    public const CANAL_CONTRIBUA = 'contribua';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $em;

    #[ORM\Column(length: 180)]
    private string $porNome;

    public function __construct(
        #[ORM\Column(length: 20)]
        private string $origem,
        #[ORM\Column(name: 'id_origem', type: 'integer')]
        private int $idOrigem,
        #[ORM\Column(name: 'tenant_id', type: 'integer', nullable: true)]
        private ?int $tenantId,
        #[ORM\Column(type: 'text')]
        private string $texto,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $por,
        #[ORM\Column(length: 10)]
        private string $canal,
        #[ORM\Column(length: 180, nullable: true)]
        private ?string $destino,
        #[ORM\Column(name: 'envio_id', type: 'integer', nullable: true)]
        private ?int $envioId = null,
        ?\DateTimeImmutable $em = null,
    ) {
        $this->texto = trim($texto);
        if ($this->texto === '') {
            throw new \DomainException('Escreva a resposta.');
        }
        if (!in_array($canal, [self::CANAL_EMAIL, self::CANAL_CENTRAL, self::CANAL_CONTRIBUA], true)) {
            throw new \DomainException('Canal de resposta inválido.');
        }
        $this->porNome = mb_substr((string) ($por?->getFullName() ?: $por?->getEmail() ?: 'Master'), 0, 180);
        $this->destino = $destino === null ? null : mb_substr($destino, 0, 180);
        $this->em = $em ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrigem(): string
    {
        return $this->origem;
    }

    public function getIdOrigem(): int
    {
        return $this->idOrigem;
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function getTexto(): string
    {
        return $this->texto;
    }

    public function getPor(): ?User
    {
        return $this->por;
    }

    public function getPorNome(): string
    {
        return $this->porNome;
    }

    public function getEm(): \DateTimeImmutable
    {
        return $this->em;
    }

    public function getCanal(): string
    {
        return $this->canal;
    }

    public function getEnvioId(): ?int
    {
        return $this->envioId;
    }

    public function getDestino(): ?string
    {
        return $this->destino;
    }
}
