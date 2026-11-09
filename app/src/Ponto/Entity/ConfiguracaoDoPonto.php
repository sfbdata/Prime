<?php

declare(strict_types=1);

namespace App\Ponto\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * A configuração do Ponto de UM escritório (spec docs/specs/ponto-desenho-0811.md, E7 e seção 6): a camada
 * "Escritório" das chaves de função e os ajustes que as etapas seguintes leem daqui (raio da sede nova, P70; limite e
 * prazo do banco, P73; trabalho externo, P74). Nenhuma tela da E7 edita os ajustes: só o mapa de chaves.
 *
 * Uma linha por escritório, criada só no primeiro salvamento. Sem linha valem os padrões das constantes abaixo, e
 * toda chave vale ligada (P96: chave sem registro nasce ligada). A unicidade é uma constraint NOMEADA em `tenant_id`
 * (molde de ConfiguracaoDeInteligencia), e não `OneToOne`, cujo índice nasceria com nome de hash.
 *
 * O valor efetivo de uma função é o Global (Master, `recurso_estado`) E o Escritório (este mapa): quem junta as duas
 * camadas é {@see \App\Ponto\Service\FuncoesDoPonto}. Auditável: cada mudança vai para o `audit_log`.
 */
#[ORM\Entity(repositoryClass: ConfiguracaoDoPontoRepository::class)]
#[ORM\Table(name: 'ponto_config_escritorio')]
#[ORM\UniqueConstraint(name: 'uniq_ponto_config_escritorio_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_ponto_config_escritorio_atualizado_por', columns: ['atualizado_por_id'])]
class ConfiguracaoDoPonto implements TenantAware, Auditavel
{
    /** P70 (a): a sede nova nasce com 150 m; as sedes que já existem ficam com o raio delas. */
    public const RAIO_PADRAO_METROS = 150;
    /** P73: limite do banco de horas (desenho, `bancoLim`). */
    public const BANCO_LIMITE_HORAS_PADRAO = 120;
    /** P73: prazo de compensação do banco (desenho, `bancoMeses`); a faixa de 1 a 24 é validada na E17. */
    public const BANCO_PRAZO_MESES_PADRAO = 6;
    /** P74: "Permitir trabalho externo, com motivo e análise" nasce ligado no desenho. */
    public const PERMITE_TRABALHO_EXTERNO_PADRAO = true;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Chave da função => ligada no escritório. Chave ausente vale ligada.
     *
     * @var array<string, bool>
     */
    #[ORM\Column(type: 'json')]
    private array $chaves = [];

    #[ORM\Column(type: 'integer', options: ['default' => self::RAIO_PADRAO_METROS])]
    private int $raioPadraoMetros = self::RAIO_PADRAO_METROS;

    #[ORM\Column(type: 'integer', options: ['default' => self::BANCO_LIMITE_HORAS_PADRAO])]
    private int $bancoLimiteHoras = self::BANCO_LIMITE_HORAS_PADRAO;

    #[ORM\Column(type: 'smallint', options: ['default' => self::BANCO_PRAZO_MESES_PADRAO])]
    private int $bancoPrazoMeses = self::BANCO_PRAZO_MESES_PADRAO;

    #[ORM\Column(type: 'boolean', options: ['default' => self::PERMITE_TRABALHO_EXTERNO_PADRAO])]
    private bool $permiteTrabalhoExterno = self::PERMITE_TRABALHO_EXTERNO_PADRAO;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadoEm;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $atualizadoPor = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
    ) {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    /** A função está ligada NESTE escritório? Chave sem registro vale ligada (P96). */
    public function estaLigada(string $chave): bool
    {
        return $this->chaves[$chave] ?? true;
    }

    /** Liga ou desliga a função no escritório e carimba quem e quando. */
    public function definir(string $chave, bool $ligada, User $por, \DateTimeImmutable $em): void
    {
        $this->chaves[$chave] = $ligada;
        $this->atualizadoPor = $por;
        $this->atualizadoEm = $em;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    /** @return array<string, bool> */
    public function getChaves(): array
    {
        return $this->chaves;
    }

    public function getRaioPadraoMetros(): int
    {
        return $this->raioPadraoMetros;
    }

    public function getBancoLimiteHoras(): int
    {
        return $this->bancoLimiteHoras;
    }

    public function getBancoPrazoMeses(): int
    {
        return $this->bancoPrazoMeses;
    }

    public function permiteTrabalhoExterno(): bool
    {
        return $this->permiteTrabalhoExterno;
    }

    public function getAtualizadoEm(): \DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    public function getAtualizadoPor(): ?User
    {
        return $this->atualizadoPor;
    }
}
