<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Repository\BonusDeEspacoRepository;
use App\Entity\Tenant\Tenant;
use App\Indicacao\Entity\Indicacao;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Espaço extra, temporário, dado a UM escritório (spec `bonus-de-espaco-por-indicacao.md`): hoje só o bônus de
 * 5 GB por 30 dias que a conta nova ganha ao chegar por indicação e confirmar o e-mail.
 *
 * Vigente = `expiraEm` maior que agora: a leitura é por data, sem job; vencido, o limite volta ao do plano e
 * nada é apagado. Uma linha por indicação (UNIQUE), o que torna a concessão idempotente.
 *
 * NÃO é `TenantAware`, pelo mesmo motivo do `EspacoDoEscritorio` (o Master no escritório X lê o Y): só o
 * `BonusDeEspacoRepository` consulta, por tenantId explícito, e `BonusDeEspacoSoNoContribuaArquiteturaTest`
 * impede consulta em outro lugar. A ligação com a `Indicacao` é só o mapeamento da FK.
 *
 * Auditável: a concessão fica no `audit_log`.
 */
#[ORM\Entity(repositoryClass: BonusDeEspacoRepository::class)]
#[ORM\Table(name: 'contribua_bonus_de_espaco')]
#[ORM\UniqueConstraint(name: 'uniq_contribua_bonus_indicacao', columns: ['indicacao_id'])]
#[ORM\Index(name: 'idx_contribua_bonus_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_contribua_bonus_expira', columns: ['expira_em'])]
class BonusDeEspaco implements Auditavel
{
    public const GIGABYTES_DA_INDICACAO = 5;
    public const DIAS_DA_INDICACAO = 30;
    public const ORIGEM_INDICACAO = 'indicacao';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Indicacao::class)]
        #[ORM\JoinColumn(name: 'indicacao_id', nullable: false, onDelete: 'CASCADE')]
        private Indicacao $indicacao,
        #[ORM\Column(type: 'integer')]
        private int $gigabytes,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $concedidoEm,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $expiraEm,
        #[ORM\Column(length: 30)]
        private string $origem = self::ORIGEM_INDICACAO,
    ) {
    }

    /** O bônus da indicação: 5 GB por 30 dias corridos a partir de `$agora`. */
    public static function daIndicacao(Tenant $tenant, Indicacao $indicacao, \DateTimeImmutable $agora): self
    {
        return new self(
            $tenant,
            $indicacao,
            self::GIGABYTES_DA_INDICACAO,
            $agora,
            $agora->modify(sprintf('+%d days', self::DIAS_DA_INDICACAO)),
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getGigabytes(): int
    {
        return $this->gigabytes;
    }

    public function getConcedidoEm(): \DateTimeImmutable
    {
        return $this->concedidoEm;
    }

    public function getExpiraEm(): \DateTimeImmutable
    {
        return $this->expiraEm;
    }

    public function getOrigem(): string
    {
        return $this->origem;
    }
}
