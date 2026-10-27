<?php

declare(strict_types=1);

namespace App\Recompensa\UseCase;

use App\Indicacao\Repository\IndicacaoRepository;
use App\Recompensa\DTO\CondicaoIndividualOutput;
use App\Recompensa\DTO\DadosDeRecebimentoOutput;
use App\Recompensa\DTO\IndicadorDeRecompensaOutput;
use App\Recompensa\DTO\PainelDeRecompensasOutput;
use App\Recompensa\Entity\CondicaoDeRecompensa;
use App\Recompensa\Entity\DadosDeRecebimentoDeRecompensa;
use App\Recompensa\Entity\ProgramaDeRecompensa;
use App\Recompensa\Repository\CondicaoDeRecompensaRepository;
use App\Recompensa\Repository\DadosDeRecebimentoDeRecompensaRepository;
use App\Recompensa\Repository\LancamentoDeRecompensaRepository;
use App\Recompensa\Repository\ProgramaDeRecompensaRepository;
use App\Recompensa\Service\SuspeitaDeConta;
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
 * é calculado na leitura, nunca fica negativo (estorno maior que o crédito zera) e desconta o que já foi pago pela baixa
 * manual (F4). Seis consultas no total (indicadores, contas, condições, lançamentos de bônus e de pago, dados de
 * recebimento), sem consulta por indicador.
 * Antifraude (F3): conta suspeita (regra em {@see SuspeitaDeConta}) que o Master não aprovou não conta, nem como ativa
 * nem como carência, e entra só na contagem de suspeitas com os motivos agregados. Aceitas nos últimos 7 dias em número
 * igual ou acima do alerta do programa mostram "Alerta" (só sinaliza). Indicador apagado da lista não aparece (nem nos
 * KPIs); o bloqueado aparece e sai só do filtro "A pagar", no front. Situação, na ordem do desenho: bloqueado, em
 * análise, alerta, liberado, em carência, em andamento.
 */
final class ConsultarPainelDeRecompensasUseCase
{
    public function __construct(
        private readonly IndicacaoRepository $indicacoes,
        private readonly ProgramaDeRecompensaRepository $programas,
        private readonly CondicaoDeRecompensaRepository $condicoes,
        private readonly LancamentoDeRecompensaRepository $lancamentos,
        private readonly DadosDeRecebimentoDeRecompensaRepository $recebimentos,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(): PainelDeRecompensasOutput
    {
        $programa = $this->programas->buscarUnico() ?? new ProgramaDeRecompensa();
        $condicoes = $this->condicoes->todasPorIndicador();
        $contas = $this->contasPorIndicador();
        $bonus = $this->lancamentos->somaDoBonusPorIndicador();
        $pagos = $this->lancamentos->somaDoPagoPorIndicador();
        $recebimentos = $this->recebimentos->todosPorIndicador();
        $agora = $this->relogio->now();

        $linhas = [];
        foreach ($this->indicacoes->indicadoresParaRecompensas() as $i) {
            $condicao = $condicoes[$i['user_id']] ?? null;
            if ($condicao?->isApagada()) {
                continue;
            }
            $linhas[] = $this->linha($i, $programa, $condicao, $contas[$i['user_id']] ?? [], $bonus[$i['user_id']] ?? 0, $pagos[$i['user_id']] ?? 0, $recebimentos[$i['user_id']] ?? null, $agora);
        }
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

    /** @return array<int, list<array{criada_em: \DateTimeImmutable, tem_pasta: bool, suspeita: list<string>}>> `suspeita` vazia: conta limpa ou já aprovada */
    private function contasPorIndicador(): array
    {
        $porIndicador = [];
        foreach ($this->indicacoes->contasParaRecompensas() as $conta) {
            $motivos = $conta['aprovada'] ? [] : SuspeitaDeConta::daLinha($conta);
            $porIndicador[$conta['indicador_id']][] = ['criada_em' => new \DateTimeImmutable($conta['conta_criada_em']), 'tem_pasta' => $conta['tem_pasta'], 'suspeita' => $motivos];
        }

        return $porIndicador;
    }

    /**
     * @param array{user_id: int, nome: string, email: string, escritorio: ?string, envios: int}                  $indicador
     * @param list<array{criada_em: \DateTimeImmutable, tem_pasta: bool, suspeita: list<string>}> $contas
     * @param int                                                                                                  $bonus  soma dos créditos e estornos manuais, com sinal
     * @param int                                                                                                  $pago   soma dos pagamentos já registrados (baixa manual)
     */
    private function linha(array $indicador, ProgramaDeRecompensa $programa, ?CondicaoDeRecompensa $condicao, array $contas, int $bonus, int $pago, ?DadosDeRecebimentoDeRecompensa $recebimento, \DateTimeImmutable $agora): IndicadorDeRecompensaOutput
    {
        $carencia = $condicao?->getCarenciaDias() ?? $programa->getCarenciaDias();
        $minimo = $condicao?->getMinEscritorios() ?? $programa->getMinEscritorios();
        $valor = $condicao?->getValorCentavos() ?? $programa->getValorCentavos();
        $a = $this->apurar($contas, $agora->modify('-' . $carencia . ' days'), $agora->modify('-7 days'), $programa->isExigePasta());
        $liberado = $a['ativos'] >= $minimo;
        $bloqueado = $condicao?->isBloqueada() ?? false;

        return new IndicadorDeRecompensaOutput(
            $indicador['user_id'], $indicador['nome'], $indicador['email'], $indicador['escritorio'], $indicador['envios'],
            count($contas), $a['ativos'], $a['carencia'], $minimo, $valor, max(0, max(0, $a['ativos'] * $valor + $bonus) - $pago), $pago,
            $this->situacao($bloqueado, $a['suspeitas'], $a['seteDias'] >= $programa->getAlertaQtd(), $liberado, $a['carencia']),
            $condicao?->temCondicaoPropria() ? CondicaoIndividualOutput::da($condicao) : null,
            $liberado, $bloqueado, $a['suspeitas'], $a['motivos'], $a['seteDias'],
            $recebimento === null ? null : DadosDeRecebimentoOutput::da($recebimento),
        );
    }

    /**
     * @param list<array{criada_em: \DateTimeImmutable, tem_pasta: bool, suspeita: list<string>}> $contas
     *
     * @return array{ativos: int, carencia: int, suspeitas: int, motivos: array<string, int>, seteDias: int}
     */
    private function apurar(array $contas, \DateTimeImmutable $limiteDaCarencia, \DateTimeImmutable $limiteDosSeteDias, bool $exigePasta): array
    {
        $r = ['ativos' => 0, 'carencia' => 0, 'suspeitas' => 0, 'motivos' => [], 'seteDias' => 0];
        foreach ($contas as $conta) {
            if ($conta['criada_em'] > $limiteDosSeteDias) {
                ++$r['seteDias'];
            }
            if ($conta['suspeita'] !== []) {
                ++$r['suspeitas'];
                foreach ($conta['suspeita'] as $motivo) {
                    $r['motivos'][$motivo] = ($r['motivos'][$motivo] ?? 0) + 1;
                }
            } elseif ($conta['criada_em'] > $limiteDaCarencia) {
                ++$r['carencia'];
            } elseif (!$exigePasta || $conta['tem_pasta']) {
                ++$r['ativos'];
            }
        }

        return $r;
    }

    private function situacao(bool $bloqueado, int $suspeitas, bool $alerta, bool $liberado, int $emCarencia): string
    {
        return match (true) {
            $bloqueado => IndicadorDeRecompensaOutput::BLOQUEADO,
            $suspeitas > 0 => IndicadorDeRecompensaOutput::ANALISE,
            $alerta => IndicadorDeRecompensaOutput::ALERTA,
            $liberado => IndicadorDeRecompensaOutput::LIBERADO,
            $emCarencia > 0 => IndicadorDeRecompensaOutput::CARENCIA,
            default => IndicadorDeRecompensaOutput::ANDAMENTO,
        };
    }
}
