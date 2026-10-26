<?php

declare(strict_types=1);

namespace App\Recompensa\UseCase;

use App\Indicacao\Repository\IndicacaoRepository;
use App\Recompensa\DTO\CondicaoIndividualOutput;
use App\Recompensa\DTO\IndicadorDeRecompensaOutput;
use App\Recompensa\DTO\PainelDeRecompensasOutput;
use App\Recompensa\Entity\CondicaoDeRecompensa;
use App\Recompensa\Entity\ProgramaDeRecompensa;
use App\Recompensa\Repository\CondicaoDeRecompensaRepository;
use App\Recompensa\Repository\LancamentoDeRecompensaRepository;
use App\Recompensa\Repository\ProgramaDeRecompensaRepository;
use Psr\Clock\ClockInterface;

/**
 * Lê a seção Recompensas do /master: regra geral, KPIs e lista de Indicadores (desenho 05 Perfis 1.2 VS 25.10.26; spec
 * docs/specs/perfis-1.2-vs-25-10-recompensas-e-planos.md, F1).
 *
 * Quem dispara: o Master, ao abrir a seção (o controller exige ROLE_SUPER_ADMIN). Dado de plataforma, sem tenant.
 * Fluxo principal: indicador é quem tem ao menos uma indicação. Contas = indicações com conta criada, cada conta uma vez
 * (para quem indicou primeiro). "Ativa" = a carência (dias desde a criação da conta) já passou e, se o programa exige
 * pasta, o escritório da conta tem ao menos uma. A condição individual sobrepõe a regra geral campo a campo.
 * Crédito = ativas x valor + soma dos lançamentos manuais (F2, com sinal); liberado com ativas >= "libera com". O saldo
 * é calculado na leitura, nunca fica negativo (estorno maior que o crédito zera) e o pago é zero até a F4. Quatro
 * consultas no total (indicadores, contas, condições, lançamentos), sem consulta por indicador.
 */
final class ConsultarPainelDeRecompensasUseCase
{
    public function __construct(
        private readonly IndicacaoRepository $indicacoes,
        private readonly ProgramaDeRecompensaRepository $programas,
        private readonly CondicaoDeRecompensaRepository $condicoes,
        private readonly LancamentoDeRecompensaRepository $lancamentos,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(): PainelDeRecompensasOutput
    {
        $programa = $this->programas->buscarUnico() ?? new ProgramaDeRecompensa();
        $condicoes = $this->condicoes->todasPorIndicador();
        $contas = $this->contasPorIndicador();
        $bonus = $this->lancamentos->somaDoBonusPorIndicador();
        $agora = $this->relogio->now();

        $linhas = array_map(
            fn (array $i): IndicadorDeRecompensaOutput => $this->linha($i, $programa, $condicoes[$i['user_id']] ?? null, $contas[$i['user_id']] ?? [], $bonus[$i['user_id']] ?? 0, $agora),
            $this->indicacoes->indicadoresParaRecompensas(),
        );
        usort($linhas, static fn (IndicadorDeRecompensaOutput $a, IndicadorDeRecompensaOutput $b): int
            => [$b->saldoCentavos, $b->ativos, $a->userId] <=> [$a->saldoCentavos, $a->ativos, $b->userId]);

        return new PainelDeRecompensasOutput(
            $programa->isAtivo(),
            $programa->getValorCentavos(),
            $programa->getMinEscritorios(),
            $programa->getCarenciaDias(),
            $programa->getAlertaQtd(),
            $programa->isExigePasta(),
            $linhas,
        );
    }

    /** @return array<int, list<array{criada_em: \DateTimeImmutable, tem_pasta: bool}>> */
    private function contasPorIndicador(): array
    {
        $porIndicador = [];
        foreach ($this->indicacoes->contasParaRecompensas() as $conta) {
            $porIndicador[$conta['indicador_id']][] = ['criada_em' => new \DateTimeImmutable($conta['conta_criada_em']), 'tem_pasta' => $conta['tem_pasta']];
        }

        return $porIndicador;
    }

    /**
     * @param array{user_id: int, nome: string, email: string, escritorio: ?string, envios: int} $indicador
     * @param list<array{criada_em: \DateTimeImmutable, tem_pasta: bool}>                         $contas
     * @param int                                                                                 $bonus  soma dos créditos e estornos manuais, com sinal
     */
    private function linha(array $indicador, ProgramaDeRecompensa $programa, ?CondicaoDeRecompensa $condicao, array $contas, int $bonus, \DateTimeImmutable $agora): IndicadorDeRecompensaOutput
    {
        $carencia = $condicao?->getCarenciaDias() ?? $programa->getCarenciaDias();
        $minimo = $condicao?->getMinEscritorios() ?? $programa->getMinEscritorios();
        $valor = $condicao?->getValorCentavos() ?? $programa->getValorCentavos();
        [$ativos, $emCarencia] = $this->apurar($contas, $agora->modify('-' . $carencia . ' days'), $programa->isExigePasta());

        return new IndicadorDeRecompensaOutput(
            $indicador['user_id'],
            $indicador['nome'],
            $indicador['email'],
            $indicador['escritorio'],
            $indicador['envios'],
            count($contas),
            $ativos,
            $emCarencia,
            $minimo,
            $valor,
            max(0, $ativos * $valor + $bonus),
            0,
            $this->situacao($ativos, $minimo, $emCarencia),
            $condicao === null ? null : CondicaoIndividualOutput::da($condicao),
        );
    }

    /**
     * @param list<array{criada_em: \DateTimeImmutable, tem_pasta: bool}> $contas
     *
     * @return array{int, int} [ativas, em carência]
     */
    private function apurar(array $contas, \DateTimeImmutable $limiteDaCarencia, bool $exigePasta): array
    {
        $ativos = 0;
        $emCarencia = 0;
        foreach ($contas as $conta) {
            if ($conta['criada_em'] > $limiteDaCarencia) {
                ++$emCarencia;
            } elseif (!$exigePasta || $conta['tem_pasta']) {
                ++$ativos;
            }
        }

        return [$ativos, $emCarencia];
    }

    private function situacao(int $ativos, int $minimo, int $emCarencia): string
    {
        return match (true) {
            $ativos >= $minimo => IndicadorDeRecompensaOutput::LIBERADO,
            $emCarencia > 0 => IndicadorDeRecompensaOutput::CARENCIA,
            default => IndicadorDeRecompensaOutput::ANDAMENTO,
        };
    }
}
