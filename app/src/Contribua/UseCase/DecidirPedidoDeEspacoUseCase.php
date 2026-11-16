<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\PedidoDeEspaco;
use App\Contribua\Enum\OrigemDaLiberacao;
use App\Contribua\Event\EventosDoEspacoAposConfirmar;
use App\Contribua\Event\OpcoesDeEspacoForamLiberadas;
use App\Contribua\Event\PedidoDeEspacoFoiRecusado;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PedidoDeEspacoRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\VisaoDoMaster;
use Psr\Clock\ClockInterface;

/**
 * O Master decide o pedido de espaço de QUALQUER escritório (desenho Contribua 14 e 15/11, N7): libera as opções de compra
 * (a liberação do Master fica gravada no espaço do escritório) ou recusa. Só pedido pendente. O escritório é avisado por
 * e-mail pelos eventos, depois do trabalho dar certo.
 *
 * A rota já exige `ROLE_SUPER_ADMIN`; a checagem aqui é a segunda trava.
 */
final class DecidirPedidoDeEspacoUseCase
{
    public function __construct(
        private readonly PedidoDeEspacoRepository $pedidos,
        private readonly EspacoDoEscritorioRepository $espacos,
        private readonly PlanoRepository $planos,
        private readonly VisaoDoMaster $visaoDoMaster,
        private readonly EventosDoEspacoAposConfirmar $eventos,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function liberar(Solicitante $quem, int $pedidoId): string
    {
        $pedido = $this->pendente($quem, $pedidoId);
        $tenantId = (int) $pedido->getTenant()->getId();
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());

        $pedido->liberar($quem->usuario, $agora);
        $espaco = $this->espacos->doTenantOuNovo($tenantId, $this->planos->free());
        $espaco->liberarOpcoes(OrigemDaLiberacao::Master, $agora);
        $this->espacos->salvar($espaco);
        $this->pedidos->salvar($pedido, flush: true);

        $this->eventos->adiar(new OpcoesDeEspacoForamLiberadas($tenantId, OrigemDaLiberacao::Master, (int) $pedido->getId()));

        return sprintf('Opções de compra liberadas para %s. O escritório será avisado por e-mail.', $pedido->getTenant()->getName());
    }

    public function recusar(Solicitante $quem, int $pedidoId): string
    {
        $pedido = $this->pendente($quem, $pedidoId);

        $pedido->recusar($quem->usuario, \DateTimeImmutable::createFromInterface($this->relogio->now()));
        $this->pedidos->salvar($pedido, flush: true);

        $this->eventos->adiar(new PedidoDeEspacoFoiRecusado((int) $pedido->getId(), (int) $pedido->getTenant()->getId()));

        return 'Pedido recusado. O escritório será avisado por e-mail.';
    }

    private function pendente(Solicitante $quem, int $pedidoId): PedidoDeEspaco
    {
        if (!$quem->ehMaster) {
            throw new \DomainException('Só o Master decide pedidos de espaço.');
        }
        $pedido = $this->visaoDoMaster->deTodosOsEscritorios(fn (): ?PedidoDeEspaco => $this->pedidos->find($pedidoId));
        if (!$pedido instanceof PedidoDeEspaco) {
            throw new \DomainException('Pedido de espaço não encontrado.');
        }
        if (!$pedido->estaPendente()) {
            throw new \DomainException('Este pedido já foi decidido.');
        }

        return $pedido;
    }
}
