<?php

declare(strict_types=1);

namespace App\Conector\Entity;

use App\Conector\Enum\VersaoDoAceiteDoConector;
use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * O conector de I.A. em UM escritório (`docs/specs/mcp-remoto-oauth.md`, decisão 14.a e 13.2): os níveis 2 e 3 da
 * liberação. O nível 1 (chave geral `conector_ia` do Master) mora no `CatalogoDeRecursos`, sem tabela.
 *
 * - Nível 2: o Master libera o escritório (`liberado_pelo_master`, nasce falso). Sem isso, o aceite do administrador
 *   não basta.
 * - Nível 3: o administrador do escritório liga o conector e dá o aceite próprio do conector, com versão
 *   ({@see VersaoDoAceiteDoConector}). Não reaproveita o aceite da BlueJus I.A.
 *
 * Uma linha por escritório: constraint única NOMEADA em `tenant_id`, como a `ConfiguracaoDeInteligencia`. Desligar ou
 * bloquear não apaga o aceite: ele é histórico. Auditável: quem liberou, ligou ou aceitou, e quando, fica no
 * `audit_log`.
 */
#[ORM\Entity(repositoryClass: EscritorioDoConectorRepository::class)]
#[ORM\Table(name: 'conector_ia_escritorio')]
#[ORM\UniqueConstraint(name: 'uniq_conector_ia_escritorio_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_conector_ia_escritorio_aceito_por', columns: ['aceito_por_user_id'])]
class EscritorioDoConector implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'liberado_pelo_master', type: 'boolean', options: ['default' => false])]
    private bool $liberadoPeloMaster = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $ligado = false;

    #[ORM\Column(name: 'aceite_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $aceiteEm = null;

    /** A versão do texto ({@see VersaoDoAceiteDoConector}) com que o aceite acima foi dado; inteiro, como na BlueJus I.A. */
    #[ORM\Column(name: 'aceite_versao', type: 'integer', nullable: true)]
    private ?int $aceiteVersao = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'aceito_por_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $aceitoPor = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
    ) {
    }

    public function liberarPeloMaster(): void
    {
        $this->liberadoPeloMaster = true;
    }

    /** Bloquear não mexe no aceite: ele volta a valer se o Master liberar de novo. */
    public function bloquearPeloMaster(): void
    {
        $this->liberadoPeloMaster = false;
    }

    /**
     * O administrador liga o conector vendo o texto do aceite vigente. Grava o aceite quando não há nenhum ou quando o
     * gravado é de versão anterior à vigente: é o aceite mais recente que vale.
     */
    public function ligar(User $por, \DateTimeImmutable $agora): void
    {
        if (!$this->aceiteCobreVersaoVigente()) {
            $this->aceiteEm = $agora;
            $this->aceiteVersao = VersaoDoAceiteDoConector::atual()->value;
            $this->aceitoPor = $por;
        }

        $this->ligado = true;
    }

    public function desligar(): void
    {
        $this->ligado = false;
    }

    /** Os níveis 2 e 3 juntos: liberado pelo Master, ligado e com o aceite da versão vigente. */
    public function liberadoELigado(): bool
    {
        return $this->liberadoPeloMaster && $this->ligado && $this->aceiteCobreVersaoVigente();
    }

    public function aceiteCobreVersaoVigente(): bool
    {
        return $this->aceiteEm !== null
            && $this->aceiteVersao !== null
            && $this->aceiteVersao >= VersaoDoAceiteDoConector::atual()->value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function isLiberadoPeloMaster(): bool
    {
        return $this->liberadoPeloMaster;
    }

    public function isLigado(): bool
    {
        return $this->ligado;
    }

    public function getAceiteEm(): ?\DateTimeImmutable
    {
        return $this->aceiteEm;
    }

    public function getAceiteVersao(): ?int
    {
        return $this->aceiteVersao;
    }

    public function getAceitoPor(): ?User
    {
        return $this->aceitoPor;
    }
}
