<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\Enum\CategoriaDeCusto;
use App\Master\Enum\OrigemDoCusto;
use App\Master\Repository\LancamentoDeCustoRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um custo mensal lançado pelo Master (spec docs/specs/master-custos.md, D1 e D2). GLOBAL, sem tenant. Valor em
 * centavos, mês como `AAAA-MM`. Auditável.
 */
#[ORM\Entity(repositoryClass: LancamentoDeCustoRepository::class)]
#[ORM\Table(name: 'custo_lancamento')]
#[ORM\Index(name: 'idx_custo_lancamento_mes', columns: ['mes'])]
#[ORM\Index(name: 'idx_custo_lancamento_lancado_por', columns: ['lancado_por_id'])]
class LancamentoDeCusto implements Auditavel
{
    public const FORNECEDOR_MAXIMO = 80;
    public const OBSERVACAO_MAXIMA = 200;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: self::FORNECEDOR_MAXIMO, nullable: true)]
    private ?string $fornecedor;

    #[ORM\Column(length: self::OBSERVACAO_MAXIMA, nullable: true)]
    private ?string $observacao;

    public function __construct(
        #[ORM\Column(type: 'string', length: 7)]
        private string $mes,
        #[ORM\Column(length: 30, enumType: CategoriaDeCusto::class)]
        private CategoriaDeCusto $categoria,
        #[ORM\Column(name: 'valor_centavos', type: 'integer')]
        private int $valorCentavos,
        #[ORM\Column(length: 10, enumType: OrigemDoCusto::class)]
        private OrigemDoCusto $origem,
        ?string $fornecedor,
        ?string $observacao,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $lancadoEm,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'lancado_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $lancadoPor = null,
    ) {
        if ($valorCentavos <= 0) {
            throw new \InvalidArgumentException('O valor do custo deve ser maior que zero.');
        }
        $fornecedor = trim((string) $fornecedor);
        $observacao = trim((string) $observacao);
        $this->fornecedor = $fornecedor === '' ? null : mb_substr($fornecedor, 0, self::FORNECEDOR_MAXIMO);
        $this->observacao = $observacao === '' ? null : mb_substr($observacao, 0, self::OBSERVACAO_MAXIMA);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMes(): string
    {
        return $this->mes;
    }

    public function getCategoria(): CategoriaDeCusto
    {
        return $this->categoria;
    }

    public function getValorCentavos(): int
    {
        return $this->valorCentavos;
    }

    public function getOrigem(): OrigemDoCusto
    {
        return $this->origem;
    }

    public function getFornecedor(): ?string
    {
        return $this->fornecedor;
    }

    public function getObservacao(): ?string
    {
        return $this->observacao;
    }

    public function getLancadoEm(): \DateTimeImmutable
    {
        return $this->lancadoEm;
    }

    public function getLancadoPor(): ?User
    {
        return $this->lancadoPor;
    }
}
