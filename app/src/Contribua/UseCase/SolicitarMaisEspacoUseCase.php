<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\LinhaDoPedidoDeEspacoView;
use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\PedidoDeEspaco;
use App\Contribua\Event\EspacoExtraFoiSolicitado;
use App\Contribua\Event\EventosDoEspacoAposConfirmar;
use App\Contribua\Repository\PedidoDeEspacoRepository;
use App\Contribua\Service\AvisoAoMaster;
use App\Contribua\Service\SituacaoDoEspaco;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Solicitar mais espaço" (desenho Contribua 14 e 15/11, N6): o escritório diz quanto estima precisar (20, 50 ou 100 GB)
 * e, se quiser, por quê. O Master é avisado na Central e decide depois.
 *
 * Só o primário do escritório (ou o Master) pede, como no resto do espaço (D4). Um pedido pendente por escritório. Sem
 * sentido pedir quando as opções de compra já estão liberadas: o escritório já as vê.
 */
final class SolicitarMaisEspacoUseCase
{
    public const PEDIDO_ENVIADO = 'Pedido enviado';

    public function __construct(
        private readonly PedidoDeEspacoRepository $pedidos,
        private readonly SituacaoDoEspaco $situacao,
        private readonly AvisoAoMaster $avisoAoMaster,
        private readonly UrlGeneratorInterface $urls,
        private readonly EventosDoEspacoAposConfirmar $eventos,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(Solicitante $quem, int $gbDesejado, ?string $motivo): string
    {
        if (!$quem->ehPrimario && !$quem->ehMaster) {
            throw new \DomainException('Quem pede mais espaço é o usuário primário do escritório.');
        }
        $retrato = $this->situacao->doEscritorio($quem->tenantId());
        if ($retrato->opcoesLiberadas) {
            throw new \DomainException('As opções de espaço extra já estão liberadas para o seu escritório.');
        }
        // A entidade valida a faixa (20, 50 ou 100) e o tamanho do motivo; fica fora da transação, que não pode lançar.
        $novo = new PedidoDeEspaco(
            $quem->escritorio,
            $quem->usuario,
            $gbDesejado,
            $motivo,
            $retrato->bytesUsados,
            $retrato->bytesLimite,
            \DateTimeImmutable::createFromInterface($this->relogio->now()),
        );
        // Trava do escritório: com clique duplo ou duas abas, o segundo pedido enxerga o primeiro e é recusado.
        $pedido = $this->pedidos->emTransacaoTravada($quem->tenantId(), function () use ($quem, $novo): ?PedidoDeEspaco {
            if ($this->pedidos->pendenteDoEscritorio($quem->tenantId()) instanceof PedidoDeEspaco) {
                return null;
            }
            $this->pedidos->salvar($novo, flush: true);

            return $novo;
        });
        if (!$pedido instanceof PedidoDeEspaco) {
            throw new \DomainException('Já existe um pedido de espaço em análise para o seu escritório.');
        }

        $this->avisoAoMaster->avisar(
            $quem->escritorio,
            AvisoAoMaster::TIPO_PEDIDO_DE_ESPACO,
            sprintf(
                'Pedido de espaço: %s (conta Nº %s) estima precisar de %s. Uso %s.',
                $quem->escritorio->getName(),
                $quem->numeroDaConta(),
                LinhaDoPedidoDeEspacoView::estimativaDe($gbDesejado),
                self::usoEmTexto($retrato),
            ),
            $this->urls->generate('contribua_index') . '#espaco',
        );

        $this->eventos->adiar(new EspacoExtraFoiSolicitado((int) $pedido->getId(), $quem->tenantId(), $gbDesejado, $quem->usuario->getId()));

        return self::PEDIDO_ENVIADO;
    }

    private static function usoEmTexto(RetratoDoEspaco $retrato): string
    {
        $uso = RetratoDoEspaco::formatarGb($retrato->bytesUsados);

        return $retrato->bytesLimite === null ? $uso : $uso . ' de ' . RetratoDoEspaco::formatarGb($retrato->bytesLimite);
    }
}
