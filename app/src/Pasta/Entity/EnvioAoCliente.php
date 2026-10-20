<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Cliente\Entity\Cliente;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Enum\TipoDeEnvioAoCliente;
use App\Pasta\Repository\EnvioAoClienteRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma linha do "Histórico de envios" do Avisar cliente (spec push-avisar-cliente §1): a Análise IA
 * enviada ao cliente (com a cópia da Controladoria), o envio automático (P4d) ou o cadastro de um
 * e-mail. `envio_ids` guarda os ids do Carteiro (um por destinatário) quando ele entrar; a
 * implementação provisória não tem id e grava `situacao` = enviado. `referencia` é o id da análise
 * (ou da movimentação, no P4d).
 *
 * Nunca é editada: é o próprio registro do ato (como o EventoHistorico da Cobrança); por isso fica fora
 * da auditoria (AuditavelCoberturaTest), que só duplicaria os endereços no audit_log.
 */
#[ORM\Entity(repositoryClass: EnvioAoClienteRepository::class)]
#[ORM\Table(name: 'envio_ao_cliente')]
#[ORM\Index(name: 'idx_envio_ao_cliente_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_envio_ao_cliente_pasta_em', columns: ['pasta_id', 'em'])]
class EnvioAoCliente implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * @param list<string>   $para
     * @param list<string>   $cc
     * @param list<int|null> $envioIds
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Pasta::class)]
        #[ORM\JoinColumn(name: 'pasta_id', nullable: false, onDelete: 'CASCADE')]
        private Pasta $pasta,
        #[ORM\ManyToOne(targetEntity: Cliente::class)]
        #[ORM\JoinColumn(name: 'cliente_id', nullable: true, onDelete: 'SET NULL')]
        private ?Cliente $cliente,
        #[ORM\Column(length: 20, enumType: TipoDeEnvioAoCliente::class)]
        private TipoDeEnvioAoCliente $tipo,
        #[ORM\Column(type: 'json')]
        private array $para,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $por,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $em,
        #[ORM\Column(type: 'json')]
        private array $cc = [],
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $assunto = null,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $descricao = null,
        #[ORM\Column(length: 30)]
        private string $situacao = 'enviado',
        #[ORM\Column(nullable: true)]
        private ?int $referencia = null,
        #[ORM\Column(name: 'envio_ids', type: 'json')]
        private array $envioIds = [],
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getPasta(): Pasta
    {
        return $this->pasta;
    }

    public function getCliente(): ?Cliente
    {
        return $this->cliente;
    }

    public function getTipo(): TipoDeEnvioAoCliente
    {
        return $this->tipo;
    }

    /** @return list<string> */
    public function getPara(): array
    {
        return $this->para;
    }

    /** @return list<string> */
    public function getCc(): array
    {
        return $this->cc;
    }

    public function getAssunto(): ?string
    {
        return $this->assunto;
    }

    public function getDescricao(): ?string
    {
        return $this->descricao;
    }

    public function getPor(): ?User
    {
        return $this->por;
    }

    public function getEm(): \DateTimeImmutable
    {
        return $this->em;
    }

    public function getSituacao(): string
    {
        return $this->situacao;
    }

    public function getReferencia(): ?int
    {
        return $this->referencia;
    }

    /** @return list<int|null> */
    public function getEnvioIds(): array
    {
        return $this->envioIds;
    }

    /**
     * Mais um destinatário que recebeu o mesmo envio (o automático grava cada um logo depois que o
     * e-mail sai, para nada sair sem registro; spec push-envio-automatico §3).
     */
    public function acrescentarDestinatario(string $email, ?int $envioId): void
    {
        $this->para[] = $email;
        $this->envioIds[] = $envioId;
    }
}
