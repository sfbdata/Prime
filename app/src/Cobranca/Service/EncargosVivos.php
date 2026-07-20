<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\Entity\Obrigacao;
use Psr\Clock\ClockInterface;

/**
 * Preenche EM MEMÓRIA (sem flush) os encargos de cada obrigação VIVA para a data de HOJE, reusando o
 * motor puro `CalculadoraEncargos` (fórmula inalterada, provada ao centavo). Obrigação congelada
 * (Liquidada/Substituída) mantém o snapshot — não é tocada.
 *
 * Aplicador puro: a `ConfigEncargos` já chega RESOLVIDA (o chamador resolve 1× por caso, evitando N+1),
 * então o serviço não depende de repositório nem navega a cascata. `hoje` vem do relógio injetado
 * (`ClockInterface`) — determinístico e testável; nunca `new \DateTimeImmutable()` no caminho do dinheiro.
 */
final class EncargosVivos
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly CalculadoraEncargos $calculadora,
    ) {
    }

    /** @param iterable<Obrigacao> $obrigacoes */
    public function hidratar(ConfigEncargos $config, iterable $obrigacoes): void
    {
        $hoje = $this->clock->now();

        foreach ($obrigacoes as $obrigacao) {
            if ($obrigacao->encargosCongelados()) {
                continue; // Liquidada/Substituída: o snapshot persistido é a verdade.
            }

            $encargos = $this->calculadora->calcular(
                $obrigacao->getValorOriginal(),
                $obrigacao->getVencimentoOriginal(),
                $config,
                $hoje,
            );

            $obrigacao->definirEncargos(
                $encargos['juros'],
                $encargos['multa'],
                $encargos['correcao'],
                $encargos['honorarios'],
                $hoje,
            );
        }
    }
}
