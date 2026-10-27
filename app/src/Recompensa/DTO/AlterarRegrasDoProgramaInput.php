<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

use App\Entity\Auth\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Pedido de mudança na regra geral do programa (POST /master/recompensas/regras, JSON com as seis regras). A recompensa
 * vem em reais ("10,00") e vira centavos inteiros em `doJson()`; valor que não é número recusa lá.
 */
final readonly class AlterarRegrasDoProgramaInput
{
    public function __construct(
        /** Quem altera: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        #[Assert\NotNull(message: 'Informe se o programa fica ativo ou pausado.')]
        public ?bool $ativo,
        #[Assert\NotNull(message: 'Informe a recompensa por escritório.')]
        #[Assert\Range(min: 0, max: 10000000, notInRangeMessage: 'A recompensa por escritório deve ficar entre R$ 0,00 e R$ 100.000,00.')]
        public ?int $valorCentavos,
        #[Assert\NotNull(message: 'Informe quantos escritórios ativos liberam a recompensa.')]
        #[Assert\Range(min: 1, max: 100000, notInRangeMessage: 'O número de escritórios ativos para liberar deve ser de 1 a 100000.')]
        public ?int $minEscritorios,
        #[Assert\NotNull(message: 'Informe a carência em dias.')]
        #[Assert\Range(min: 0, max: 3650, notInRangeMessage: 'A carência deve ser de 0 a 3650 dias.')]
        public ?int $carenciaDias,
        #[Assert\NotNull(message: 'Informe o alerta antifraude.')]
        #[Assert\Range(min: 1, max: 100000, notInRangeMessage: 'O alerta antifraude deve ser de 1 a 100000 contas.')]
        public ?int $alertaQtd,
        #[Assert\NotNull(message: 'Informe se só conta escritório com pastas abertas.')]
        public ?bool $exigePasta,
    ) {
    }

    /**
     * @param array<mixed> $corpo `{ativo, valor, minEscritorios, carenciaDias, alertaQtd, exigePasta}`
     *
     * @throws \App\Recompensa\Exception\RecompensaRecusada número que não é número
     */
    public static function doJson(?User $ator, array $corpo): self
    {
        return new self(
            $ator,
            is_bool($corpo['ativo'] ?? null) ? $corpo['ativo'] : null,
            LeitorDeValoresDeRecompensa::centavos($corpo['valor'] ?? null),
            LeitorDeValoresDeRecompensa::inteiro($corpo['minEscritorios'] ?? null),
            LeitorDeValoresDeRecompensa::inteiro($corpo['carenciaDias'] ?? null),
            LeitorDeValoresDeRecompensa::inteiro($corpo['alertaQtd'] ?? null),
            is_bool($corpo['exigePasta'] ?? null) ? $corpo['exigePasta'] : null,
        );
    }
}
