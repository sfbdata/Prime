<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\LinhaDoPedidoDeEspacoView;
use App\Contribua\DTO\PainelDePedidosDeEspacoView;
use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\PedidoDeEspaco;
use App\Contribua\Repository\PedidoDeEspacoRepository;
use App\Contribua\Service\VisaoDoMaster;

/**
 * O painel "Pedidos de espaço · só Master" (N7): os 30 pedidos mais recentes de todos os escritórios e o resumo
 * "N pendente(s) · M liberado(s)". Quem não é Master não recebe nada (a tela também esconde o painel).
 */
final class ListarPedidosDeEspacoParaOMasterUseCase
{
    public function __construct(
        private readonly PedidoDeEspacoRepository $pedidos,
        private readonly VisaoDoMaster $visaoDoMaster,
    ) {
    }

    public function executar(Solicitante $quem): PainelDePedidosDeEspacoView
    {
        if (!$quem->ehMaster) {
            throw new \DomainException('Só o Master vê os pedidos de espaço.');
        }

        [$pedidos, $contagem] = $this->visaoDoMaster->deTodosOsEscritorios(fn (): array => [
            $this->pedidos->maisRecentesParaOMaster(PedidoDeEspacoRepository::LIMITE_DO_PAINEL),
            $this->pedidos->contarParaOMaster(),
        ]);

        return new PainelDePedidosDeEspacoView($contagem['pendentes'], $contagem['liberados'], array_map(self::linha(...), $pedidos));
    }

    private static function linha(PedidoDeEspaco $p): LinhaDoPedidoDeEspacoView
    {
        $uso = RetratoDoEspaco::formatarGb($p->getUsoBytes());
        $usuario = $p->getPedidoPor();

        return new LinhaDoPedidoDeEspacoView(
            (int) $p->getId(),
            (int) $p->getTenant()->getId(),
            (string) $p->getTenant()->getName(),
            $usuario === null ? null : ($usuario->getFullName() ?: null),
            $usuario?->getEmail(),
            $p->getGbDesejado(),
            LinhaDoPedidoDeEspacoView::estimativaDe($p->getGbDesejado()),
            'uso ' . $uso . ($p->getLimiteBytes() === null ? '' : ' de ' . RetratoDoEspaco::formatarGb($p->getLimiteBytes())),
            $p->getCriadoEm(),
            $p->getDecididoEm(),
            $p->getMotivo(),
            $p->getSituacao(),
        );
    }
}
