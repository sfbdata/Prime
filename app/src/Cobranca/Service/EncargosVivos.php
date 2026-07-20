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

    /**
     * "Hoje" segundo o MESMO relógio injetado que rege a hidratação (spec §5: `hoje` sempre injetado
     * via `ClockInterface`, nunca `new \DateTimeImmutable()` no caminho do dinheiro). Os consumidores
     * que também filtram por data (ex.: saldo vencido) leem daqui para que a data do encargo e a do
     * filtro de vencido nunca divirjam — uma única fonte de tempo, não duas.
     */
    public function agora(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * Exigível vivo de UMA obrigação para HOJE, em centavos, SEM mutar a entidade (não chama
     * `definirEncargos`, não deixa a instância managed suja). É o caminho para contextos de CÁLCULO
     * dentro de fluxos de ESCRITA (ex.: alocação FIFO num pagamento): ali as obrigações Vivas NÃO
     * podem ser persistidas (INV-V1), então lê-se o vivo sem tocar as colunas. Congelada
     * (Liquidada/Substituída) devolve o snapshot persistido — não recalcula.
     */
    public function exigivelVivo(ConfigEncargos $config, Obrigacao $obrigacao): int
    {
        if ($obrigacao->encargosCongelados()) {
            return $obrigacao->valorExigivel();
        }

        $e = $this->calculadora->calcular(
            $obrigacao->getValorOriginal(),
            $obrigacao->getVencimentoOriginal(),
            $config,
            $this->clock->now(),
        );

        return $obrigacao->getValorOriginal() + $e['juros'] + $e['multa'] + $e['correcao'];
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
