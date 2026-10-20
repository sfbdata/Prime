<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\ContribuicoesDaConta;
use App\Contribua\DTO\MesDeContribuicoes;
use App\Contribua\DTO\RelatorioDeContribuicoes;
use App\Contribua\Entity\IntegracaoBancaria;
use App\Contribua\Repository\ApoioRepository;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use Psr\Clock\ClockInterface;

/**
 * Relatório de contribuições do Painel do Master (desenho 08, L253, chave `rel` da Integração bancária).
 *
 * Quem: o Master (ROLE_SUPER_ADMIN no controller), ao abrir o painel. Quer ver quanto a BlueJus recebeu, por
 * mês e por conta. Conta só o CONFIRMADO (pelo banco); o que o escritório só informou ("Já contribuí") aparece
 * à parte, por mês. Os 12 meses vêm sempre completos (mês sem apoio = zero). Com a chave `rel` desligada, ou
 * com a integração nunca salva, nada é consultado: o painel mostra que o relatório está desligado.
 */
final class MontarRelatorioDeContribuicoesUseCase
{
    public const MESES = 12;
    private const ABREVIATURAS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    public function __construct(
        private readonly ApoioRepository $apoios,
        private readonly IntegracaoBancariaRepository $integracoes,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(): RelatorioDeContribuicoes
    {
        $motivo = $this->motivoDeEstarDesligado($this->integracoes->atual());
        if (null !== $motivo) {
            return RelatorioDeContribuicoes::desligado($motivo);
        }
        $contas = $this->contas();

        return new RelatorioDeContribuicoes(
            null,
            $this->meses(),
            $contas,
            array_sum(array_map(static fn (ContribuicoesDaConta $c): int => $c->totalCentavos, $contas)),
        );
    }

    private function motivoDeEstarDesligado(IntegracaoBancaria $integracao): ?string
    {
        if (null === $integracao->getId()) {
            return RelatorioDeContribuicoes::SEM_INTEGRACAO;
        }

        return ($integracao->getRecursos()['rel'] ?? false) ? null : RelatorioDeContribuicoes::CHAVE_DESLIGADA;
    }

    /** @return list<MesDeContribuicoes> */
    private function meses(): array
    {
        $primeiro = $this->relogio->now()->modify('first day of this month')->setTime(0, 0)->modify('-' . (self::MESES - 1) . ' months');
        $somas = [];
        // Limite superior: o 1º dia do mês seguinte ao atual, para o total da grade ser o do período mostrado.
        foreach ($this->apoios->totaisPorMes($primeiro, $primeiro->modify('+' . self::MESES . ' months')) as $linha) {
            $somas[$linha['mes']] = $linha;
        }
        $meses = [];
        for ($i = 0; $i < self::MESES; ++$i) {
            $mes = $primeiro->modify('+' . $i . ' months');
            $chave = $mes->format('Y-m');
            $rotulo = self::ABREVIATURAS[(int) $mes->format('n') - 1] . '/' . $mes->format('y');
            $meses[] = new MesDeContribuicoes($chave, $rotulo, $somas[$chave]['confirmado'] ?? 0, $somas[$chave]['informado'] ?? 0);
        }

        return $meses;
    }

    /** @return list<ContribuicoesDaConta> na ordem do banco: maior soma primeiro; no empate, pelo nome */
    private function contas(): array
    {
        return array_map(static fn (array $l): ContribuicoesDaConta => new ContribuicoesDaConta(
            $l['escritorio'],
            $l['excluido'],
            $l['total'],
            $l['quantidade'],
            new \DateTimeImmutable($l['ultimo']),
        ), $this->apoios->totaisPorConta());
    }
}
