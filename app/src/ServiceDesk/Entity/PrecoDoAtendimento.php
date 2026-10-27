<?php

declare(strict_types=1);

namespace App\ServiceDesk\Entity;

use App\ServiceDesk\Enum\TipoDeChamado;
use App\ServiceDesk\Repository\PrecoDoAtendimentoRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Preço de um tipo de atendimento ("a partir de R$ ..."). Catálogo GLOBAL, sem tenant: o Master define
 * (decisão do dono, 27/10). Tipo sem preço = sem estimativa e sem cobrança: nenhum valor é inventado.
 */
#[ORM\Entity(repositoryClass: PrecoDoAtendimentoRepository::class)]
#[ORM\Table(name: 'service_desk_preco')]
#[ORM\UniqueConstraint(name: 'uniq_service_desk_preco_tipo', columns: ['tipo'])]
class PrecoDoAtendimento implements Auditavel
{
    /** R$ 100.000,00: acima disso é engano de digitação. */
    public const VALOR_MAXIMO_CENTAVOS = 10_000_000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadoEm;

    public function __construct(
        #[ORM\Column(enumType: TipoDeChamado::class, length: 20)]
        private TipoDeChamado $tipo,
        #[ORM\Column(type: 'integer')]
        private int $valorCentavos,
    ) {
        $this->exigirValorValido($valorCentavos);
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function alterarValor(int $valorCentavos): void
    {
        $this->exigirValorValido($valorCentavos);
        $this->valorCentavos = $valorCentavos;
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function getTipo(): TipoDeChamado
    {
        return $this->tipo;
    }

    public function getValorCentavos(): int
    {
        return $this->valorCentavos;
    }

    private function exigirValorValido(int $valorCentavos): void
    {
        if ($valorCentavos <= 0 || $valorCentavos > self::VALOR_MAXIMO_CENTAVOS) {
            throw new \DomainException('Informe um preço entre R$ 0,01 e R$ 100.000,00.');
        }
    }
}
