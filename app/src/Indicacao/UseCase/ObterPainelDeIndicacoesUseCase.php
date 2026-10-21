<?php

declare(strict_types=1);

namespace App\Indicacao\UseCase;

use App\Indicacao\DTO\AlertaDeIndicacaoOutput;
use App\Indicacao\DTO\IndicadorOutput;
use App\Indicacao\DTO\LimitesDeIndicacaoOutput;
use App\Indicacao\DTO\PainelDeIndicacoesOutput;
use App\Indicacao\DTO\ResumoDeIndicacoesOutput;
use App\Indicacao\Repository\IndicacaoAlertaRepository;
use App\Indicacao\Repository\IndicacaoConfigRepository;
use App\Indicacao\Repository\IndicacaoRepository;
use Psr\Clock\ClockInterface;

/**
 * O card "Indicações do BlueJus" do Painel do Master (desenho 05 L269-275 e L572-575; contrato com a 5d). Só leitura.
 * Quem chama é o controller da 5d, que já exigiu o Master.
 */
final class ObterPainelDeIndicacoesUseCase
{
    public const MAXIMO_POR_PAGINA = 200;
    private const ALERTAS = 3;

    public function __construct(
        private readonly IndicacaoRepository $indicacoes,
        private readonly IndicacaoConfigRepository $configs,
        private readonly IndicacaoAlertaRepository $alertas,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(int $pagina = 1, int $porPagina = 50): PainelDeIndicacoesOutput
    {
        $pagina = max(1, $pagina);
        $porPagina = min(self::MAXIMO_POR_PAGINA, max(1, $porPagina));
        $agora = $this->relogio->now();
        $limites = LimitesDeIndicacaoOutput::fromEntity($this->configs->obter());
        $totais = $this->indicacoes->totais($agora->modify('-24 hours'));

        return new PainelDeIndicacoesOutput(
            enviadas: $totais['enviadas'],
            ultimas24h: $totais['ultimas24h'],
            viraramConta: $totais['viraramConta'],
            limites: $limites,
            alertas: array_map(AlertaDeIndicacaoOutput::fromEntity(...), $this->alertas->ultimos(self::ALERTAS)),
            indicadores: $this->indicadores($agora, $limites->alertaEm24h, $pagina, $porPagina),
            totalDeIndicadores: $this->indicacoes->contarIndicadores(),
            pagina: $pagina,
            porPagina: $porPagina,
        );
    }

    /**
     * Só o cabeçalho e os limites (a seção do Painel do Master de 21/10): sem a página de indicadores nem os alertas,
     * que a tela não mostra.
     */
    public function resumo(): ResumoDeIndicacoesOutput
    {
        $totais = $this->indicacoes->totais($this->relogio->now()->modify('-24 hours'));

        return new ResumoDeIndicacoesOutput(
            enviadas: $totais['enviadas'],
            ultimas24h: $totais['ultimas24h'],
            viraramConta: $totais['viraramConta'],
            limites: LimitesDeIndicacaoOutput::fromEntity($this->configs->obter()),
        );
    }

    /** @return list<IndicadorOutput> */
    private function indicadores(\DateTimeImmutable $agora, int $alerta, int $pagina, int $porPagina): array
    {
        $linhas = $this->indicacoes->indicadores($agora->modify('-24 hours'), $agora->modify('-30 days'), ($pagina - 1) * $porPagina, $porPagina);

        return array_map(static fn (array $l): IndicadorOutput => new IndicadorOutput(
            userId: $l['user_id'],
            nome: $l['nome'],
            email: $l['email'],
            escritorio: $l['escritorio'],
            total: $l['total'],
            ultimas24h: $l['ultimas24h'],
            ultimos30d: $l['ultimos30d'],
            contasCriadas: $l['contas_criadas'],
            ultimaEm: $l['ultima_em'] === null ? null : new \DateTimeImmutable($l['ultima_em']),
            pausado: $l['pausado'],
            acimaDoAlerta: $l['ultimas24h'] >= $alerta,
        ), $linhas);
    }
}
