<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Entity\AssinaturaDeEspaco;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;
use App\Contribua\Repository\ConfiguracaoDePagamentoRepository;
use App\Contribua\Service\VisaoDoMaster;
use Psr\Clock\ClockInterface;

/**
 * As contas que pagam o Contribua hoje, para o filtro "Pagantes" de "Contas e escritórios" do Painel do Master.
 * Só leitura.
 *
 * Pagante é a conta com assinatura Ativa ou Inadimplente que ainda vale (`vigenteEm`, com a carência configurada):
 * a mesma regra que dá o espaço do plano. Cancelada e aguardando confirmação não entram. Com mais de uma valendo,
 * fica a maior (pelo GB já multiplicado), como no `PlanoVigenteDoEscritorio`.
 *
 * `carencia` marca a conta que ainda vale só pela carência: cobrança recusada (Inadimplente) ou período pago já
 * vencido à espera da renovação. Quem chama garante que é o Master (`ROLE_SUPER_ADMIN` no controller).
 */
final class ListarContasPagantesUseCase
{
    public function __construct(
        private readonly AssinaturaDeEspacoRepository $assinaturas,
        private readonly ConfiguracaoDePagamentoRepository $configuracao,
        private readonly VisaoDoMaster $visaoDoMaster,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * @return array<int, array{plano: string, gb: int, valorCentavos: int, valeAte: string, carencia: bool}> pelo id da conta
     */
    public function executar(): array
    {
        $todas = $this->visaoDoMaster->deTodosOsEscritorios(fn (): array => $this->assinaturas->pagasQuePodemValer());

        return self::pagantes($todas, $this->relogio->now(), $this->configuracao->atual()->getDiasDeCarencia());
    }

    /**
     * A regra, sem banco: das assinaturas dadas, as que valem em `$agora`, a maior por conta.
     *
     * @param list<AssinaturaDeEspaco> $todas
     *
     * @return array<int, array{plano: string, gb: int, valorCentavos: int, valeAte: string, carencia: bool}> pelo id da conta
     */
    public static function pagantes(array $todas, \DateTimeImmutable $agora, int $carencia): array
    {
        $porConta = [];
        foreach ($todas as $assinatura) {
            $paga = \in_array($assinatura->getSituacao(), [SituacaoDaAssinatura::Ativa, SituacaoDaAssinatura::Inadimplente], true);
            if (!$paga || !$assinatura->vigenteEm($agora, $carencia)) {
                continue;
            }
            $conta = (int) $assinatura->getTenant()->getId();
            $atual = $porConta[$conta] ?? null;
            if ($atual === null || $assinatura->gigabytes() > $atual->gigabytes()) {
                $porConta[$conta] = $assinatura;
            }
        }

        return array_map(static fn (AssinaturaDeEspaco $a): array => self::pagante($a, $agora), $porConta);
    }

    /** @return array{plano: string, gb: int, valorCentavos: int, valeAte: string, carencia: bool} */
    private static function pagante(AssinaturaDeEspaco $a, \DateTimeImmutable $agora): array
    {
        $pagaAte = $a->getPagaAte() ?? $agora;

        return [
            'plano' => $a->getPlano()->getNome() . ($a->getMultiplicador() > 1 ? ' ×' . $a->getMultiplicador() : ''),
            'gb' => $a->gigabytes(),
            'valorCentavos' => $a->getValorCentavos(),
            'valeAte' => $pagaAte->format('Y-m-d'),
            'carencia' => $a->getSituacao() === SituacaoDaAssinatura::Inadimplente || $pagaAte < $agora,
        ];
    }
}
