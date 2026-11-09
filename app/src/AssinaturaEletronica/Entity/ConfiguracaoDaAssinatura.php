<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Entity;

use App\AssinaturaEletronica\Enum\TipoDeLogo;
use App\AssinaturaEletronica\Repository\ConfiguracaoDaAssinaturaRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * As preferências da tela Assinatura de cada escritório. Hoje só a logo padrão dos próximos documentos ("Usar esta
 * logo nos próximos documentos"), guardada aqui para não mexer em `Tenant` (contrato da etapa 1, seção 2).
 */
#[ORM\Entity(repositoryClass: ConfiguracaoDaAssinaturaRepository::class)]
#[ORM\Table(name: 'assinatura_eletronica_configuracao')]
#[ORM\UniqueConstraint(name: 'uniq_assinatura_eletronica_configuracao_tenant', columns: ['tenant_id'])]
class ConfiguracaoDaAssinatura implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Tenant $tenant;

    #[ORM\Column(name: 'logo_padrao_tipo', length: 20, enumType: TipoDeLogo::class)]
    private TipoDeLogo $logoPadraoTipo = TipoDeLogo::Bluejus;

    #[ORM\Column(name: 'logo_padrao_chave', length: 255, nullable: true)]
    private ?string $logoPadraoChave = null;

    public function __construct(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }

    /** A chave só existe na logo própria: nas outras, a imagem não é deste escritório nesta tela. */
    public function definirLogoPadrao(TipoDeLogo $tipo, ?string $chave): void
    {
        $this->logoPadraoTipo = $tipo;
        $this->logoPadraoChave = $tipo === TipoDeLogo::Propria ? $chave : null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getLogoPadraoTipo(): TipoDeLogo
    {
        return $this->logoPadraoTipo;
    }

    public function getLogoPadraoChave(): ?string
    {
        return $this->logoPadraoChave;
    }
}
