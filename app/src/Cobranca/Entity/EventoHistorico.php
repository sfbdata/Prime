<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Evento da linha do tempo OPERACIONAL do Caso de Cobrança (SPEC §13). É o histórico de
 * domínio, visível ao usuário, escrito EXPLICITAMENTE pelos UseCases — NÃO é auditoria técnica
 * (invariável 26), por isso NÃO implementa Auditavel (seria log de log). Append-only: registra
 * o que aconteceu no trabalho de cobrança (data, usuário, descrição e dados necessários).
 */
#[ORM\Entity(repositoryClass: EventoHistoricoRepository::class)]
#[ORM\Table(name: 'cobranca_evento_historico')]
#[ORM\Index(name: 'idx_cobranca_evento_tenant_caso', columns: ['tenant_id', 'caso_id'])]
class EventoHistorico implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: CasoCobranca::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?CasoCobranca $caso = null;

    #[ORM\Column(enumType: TipoEventoHistorico::class)]
    private TipoEventoHistorico $tipo = TipoEventoHistorico::CasoAberto;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $ocorridoEm;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $usuario = null;

    #[ORM\Column(type: 'text')]
    private string $descricao = '';

    /** Dados estruturados do evento (ex.: valor solicitado, novo prazo, de→para). */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dados = null;

    public function __construct()
    {
        $this->ocorridoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getCaso(): ?CasoCobranca
    {
        return $this->caso;
    }

    public function setCaso(CasoCobranca $caso): self
    {
        $this->caso = $caso;

        return $this;
    }

    public function getTipo(): TipoEventoHistorico
    {
        return $this->tipo;
    }

    public function setTipo(TipoEventoHistorico $tipo): self
    {
        $this->tipo = $tipo;

        return $this;
    }

    public function getOcorridoEm(): \DateTimeImmutable
    {
        return $this->ocorridoEm;
    }

    public function setOcorridoEm(\DateTimeImmutable $ocorridoEm): self
    {
        $this->ocorridoEm = $ocorridoEm;

        return $this;
    }

    public function getUsuario(): ?User
    {
        return $this->usuario;
    }

    public function setUsuario(?User $usuario): self
    {
        $this->usuario = $usuario;

        return $this;
    }

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function setDescricao(string $descricao): self
    {
        $this->descricao = $descricao;

        return $this;
    }

    public function getDados(): ?array
    {
        return $this->dados;
    }

    public function setDados(?array $dados): self
    {
        $this->dados = $dados;

        return $this;
    }
}
