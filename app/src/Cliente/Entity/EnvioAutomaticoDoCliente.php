<?php

declare(strict_types=1);

namespace App\Cliente\Entity;

use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Cliente\Repository\EnvioAutomaticoDoClienteRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * As chaves do "Envio automático" de UM cliente (spec push-envio-automatico §1; desenho `reg.auto`):
 * novas movimentações, audiências e intimações, resumo semanal. Valem POR CLIENTE, em todas as pastas
 * de que ele é o cliente principal, como a lista de e-mails dele.
 *
 * A linha só nasce quando alguém mexe numa chave: sem linha, valem os padrões (todas desligadas, D1).
 * A criação é um INSERT idempotente no repositório (`garantirDoCliente`): duas abas gravando ao mesmo
 * tempo não estouram o UNIQUE (cliente_id). O cliente apagado leva a linha (CASCADE).
 *
 * Auditável: ligar o envio automático de e-mails ao cliente é ato de alguém do escritório, com efeito
 * fora dele. Sem dado pessoal na linha (só as chaves), então a auditoria não copia endereço nenhum.
 */
#[ORM\Entity(repositoryClass: EnvioAutomaticoDoClienteRepository::class)]
#[ORM\Table(name: 'cliente_envio_automatico')]
#[ORM\Index(name: 'idx_cliente_envio_automatico_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cliente_envio_automatico_cliente', columns: ['cliente_id'])]
class EnvioAutomaticoDoCliente implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'novas_movimentacoes', type: 'boolean')]
    private bool $novasMovimentacoes = ChaveDoEnvioAutomatico::PADRAO;

    #[ORM\Column(name: 'audiencias_intimacoes', type: 'boolean')]
    private bool $audienciasIntimacoes = ChaveDoEnvioAutomatico::PADRAO;

    #[ORM\Column(name: 'resumo_semanal', type: 'boolean')]
    private bool $resumoSemanal = ChaveDoEnvioAutomatico::PADRAO;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Cliente::class)]
        #[ORM\JoinColumn(name: 'cliente_id', nullable: false, onDelete: 'CASCADE')]
        private Cliente $cliente,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'atualizado_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $atualizadoPor,
        #[ORM\Column(name: 'atualizado_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $atualizadoEm,
    ) {
    }

    /** Põe a chave no estado pedido e registra quem mexeu. */
    public function definir(ChaveDoEnvioAutomatico $chave, bool $ligada, User $por, \DateTimeImmutable $em): void
    {
        match ($chave) {
            ChaveDoEnvioAutomatico::NovasMovimentacoes => $this->novasMovimentacoes = $ligada,
            ChaveDoEnvioAutomatico::AudienciasIntimacoes => $this->audienciasIntimacoes = $ligada,
            ChaveDoEnvioAutomatico::ResumoSemanal => $this->resumoSemanal = $ligada,
        };
        $this->atualizadoPor = $por;
        $this->atualizadoEm = $em;
    }

    public function ligada(ChaveDoEnvioAutomatico $chave): bool
    {
        return match ($chave) {
            ChaveDoEnvioAutomatico::NovasMovimentacoes => $this->novasMovimentacoes,
            ChaveDoEnvioAutomatico::AudienciasIntimacoes => $this->audienciasIntimacoes,
            ChaveDoEnvioAutomatico::ResumoSemanal => $this->resumoSemanal,
        };
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

    public function getAtualizadoPor(): ?User
    {
        return $this->atualizadoPor;
    }

    public function getAtualizadoEm(): \DateTimeImmutable
    {
        return $this->atualizadoEm;
    }
}
