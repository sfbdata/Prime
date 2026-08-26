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
 * A `ConfigEncargos` recebida já chega RESOLVIDA como BASE DO CASO (o chamador resolve 1× por caso via
 * `resolverDoCaso`, evitando N+1), mas o serviço deixou de ser um aplicador puro de config única: por
 * OBRIGAÇÃO, aplica o overlay do 3º nível da cascata (`ResolvedorConfigEncargos::aplicarObrigacao`,
 * spec "taxa por-obrigação") sobre essa base ANTES de calcular — a taxa própria da obrigação (se houver)
 * vence; campo ausente herda da base do caso. Sem I/O novo: `aplicarObrigacao` só lê campos já
 * carregados na entidade. `hoje` vem do relógio injetado (`ClockInterface`) — determinístico e
 * testável; nunca `new \DateTimeImmutable()` no caminho do dinheiro.
 */
final class EncargosVivos
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly CalculadoraEncargos $calculadora,
        private readonly ResolvedorConfigEncargos $resolvedor,
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
     * Exigível vivo de UMA obrigação para a DATA DE REFERÊNCIA informada, em centavos, SEM mutar a
     * entidade (não chama `definirEncargos`, não deixa a instância managed suja). É o caminho para
     * contextos de CÁLCULO dentro de fluxos de ESCRITA (ex.: alocação FIFO num pagamento): ali as
     * obrigações Vivas NÃO podem ser persistidas (INV-V1), então lê-se o vivo sem tocar as colunas.
     *
     * A data é EXPLÍCITA (spec §5) porque quem aloca um pagamento tem de medir o exigível na MESMA data
     * em que ele será liquidado (a data do pagamento) — senão a alocação (base "hoje") e a quitação/
     * snapshot (base "data do pagamento") divergem e um pagamento retroativo/futuro quita errado. Para
     * a leitura ao vivo comum (prévia, "hoje"), o chamador passa `agora()`. Congelada
     * (Liquidada/Substituída) devolve o snapshot persistido — não recalcula.
     *
     * `$baseCaso` é a config resolvida do CASO (`resolverDoCaso`, 1× por caso — sem N+1); aqui aplicamos
     * por cima o overlay da própria obrigação (spec "taxa por-obrigação") antes de calcular.
     */
    public function exigivelVivo(ConfigEncargos $baseCaso, Obrigacao $obrigacao, \DateTimeImmutable $dataReferencia): int
    {
        if ($obrigacao->encargosCongelados()) {
            return $obrigacao->valorExigivel();
        }

        $config = $this->resolvedor->aplicarObrigacao($baseCaso, $obrigacao);

        $e = $this->calculadora->calcular(
            $obrigacao->getValorOriginal(),
            $obrigacao->getVencimentoOriginal(),
            $config,
            $dataReferencia,
        );

        // Regra ÚNICA do exigível (`Obrigacao::exigivelDe`) — aqui havia uma segunda cópia da soma, e
        // era por ela que o `AutoAlocadorFifo` enxergava a dívida. Ver a spec do honorário §2.
        return Obrigacao::exigivelDe($obrigacao->getValorOriginal(), $e['juros'], $e['multa'], $e['correcao'], $e['honorarios']);
    }

    /**
     * `$baseCaso` é a config resolvida do CASO (`resolverDoCaso`, 1× por caso — sem N+1); por obrigação,
     * aplicamos por cima o overlay da própria obrigação (spec "taxa por-obrigação") antes de calcular.
     *
     * @param iterable<Obrigacao> $obrigacoes
     */
    public function hidratar(ConfigEncargos $baseCaso, iterable $obrigacoes): void
    {
        $hoje = $this->clock->now();

        foreach ($obrigacoes as $obrigacao) {
            if ($obrigacao->encargosCongelados()) {
                continue; // Liquidada/Substituída: o snapshot persistido é a verdade.
            }

            $config = $this->resolvedor->aplicarObrigacao($baseCaso, $obrigacao);

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
