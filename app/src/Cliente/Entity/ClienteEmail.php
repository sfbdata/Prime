<?php

declare(strict_types=1);

namespace App\Cliente\Entity;

use App\Cliente\Enum\RotuloDoEmail;
use App\Cliente\Repository\ClienteEmailRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um e-mail do cliente para o "Avisar cliente" do Push (spec push-avisar-cliente §1). A lista é POR
 * CLIENTE (vale em todas as pastas dele), no máximo 5, um só principal; quem garante as regras é o
 * {@see \App\Cliente\Service\CadernoDeEmailsDoCliente}. UNIQUE (cliente, email); o cliente apagado
 * leva as linhas (CASCADE).
 *
 * O e-mail da ficha (`Cliente.email`) aparece como item virtual "Cadastro · principal" até a primeira
 * alteração, quando vira linha com o rótulo `Cadastro`. Remover essa linha só a marca (`removido_em`):
 * o endereço continua na ficha do cliente, e sem a marca o item virtual voltaria sozinho. Os demais
 * e-mails saem de fato (minimização: o escritório pediu para esquecer).
 *
 * Fora da auditoria (AuditavelCoberturaTest): acompanha a decisão do Cliente, que não é auditado, e
 * auditar duplicaria o endereço no audit_log. O ato fica no histórico de envios da pasta.
 */
#[ORM\Entity(repositoryClass: ClienteEmailRepository::class)]
#[ORM\Table(name: 'cliente_email')]
#[ORM\Index(name: 'idx_cliente_email_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cliente_email_cliente_email', columns: ['cliente_id', 'email'])]
class ClienteEmail implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'recebe_avisos', type: 'boolean')]
    private bool $recebeAvisos = true;

    #[ORM\Column(name: 'removido_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $removidoEm = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Cliente::class)]
        #[ORM\JoinColumn(name: 'cliente_id', nullable: false, onDelete: 'CASCADE')]
        private Cliente $cliente,
        #[ORM\Column(length: 180)]
        private string $email,
        #[ORM\Column(length: 20, enumType: RotuloDoEmail::class)]
        private RotuloDoEmail $rotulo,
        #[ORM\Column(type: 'boolean')]
        private bool $principal,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'criado_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $criadoPor,
        #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $criadoEm,
    ) {
    }

    public function tornarPrincipal(bool $principal): void
    {
        $this->principal = $principal;
    }

    public function definirRecebimento(bool $recebe): void
    {
        $this->recebeAvisos = $recebe;
    }

    /** Só a linha do cadastro é marcada (ver a classe); as demais saem do banco. */
    public function marcarRemovido(\DateTimeImmutable $em): void
    {
        $this->removidoEm = $em;
        $this->principal = false;
    }

    /** O endereço do cadastro removido que alguém cadastra de novo: volta com o rótulo escolhido. */
    public function restaurar(RotuloDoEmail $rotulo, bool $principal, ?User $por, \DateTimeImmutable $em): void
    {
        $this->removidoEm = null;
        $this->rotulo = $rotulo;
        $this->principal = $principal;
        $this->recebeAvisos = true;
        $this->criadoPor = $por;
        $this->criadoEm = $em;
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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRotulo(): RotuloDoEmail
    {
        return $this->rotulo;
    }

    public function ehPrincipal(): bool
    {
        return $this->principal;
    }

    public function recebeAvisos(): bool
    {
        return $this->recebeAvisos;
    }

    public function estaRemovido(): bool
    {
        return $this->removidoEm !== null;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
