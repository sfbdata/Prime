<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Entity\AssinaturaDeEspaco;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;
use App\Contribua\Service\AvisoAoPrimario;
use App\Contribua\Service\PlanoVigenteDoEscritorio;
use App\Contribua\Service\VisaoDoMaster;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Confirmar pagamento" na lista de pedidos do Master (desenho 08: "Sem webhook, o pedido entra como
 * aguardando confirmação e o Master confirma no painel"). O espaço vale um mês a partir de agora; quem
 * confirmou fica gravado e auditado. O controller exige `ROLE_SUPER_ADMIN`.
 */
final class ConfirmarPagamentoUseCase
{
    public function __construct(
        private readonly AssinaturaDeEspacoRepository $assinaturas,
        private readonly VisaoDoMaster $visaoDoMaster,
        private readonly PlanoVigenteDoEscritorio $planoVigente,
        private readonly AvisoAoPrimario $avisoAoPrimario,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function executar(User $master, int $pedidoId): void
    {
        $pedido = $this->visaoDoMaster->deTodosOsEscritorios(fn (): ?AssinaturaDeEspaco => $this->assinaturas->find($pedidoId));
        if ($pedido === null) {
            throw new \DomainException('Pedido não encontrado.');
        }

        $agora = new \DateTimeImmutable();
        $pedido->confirmarManualmente($master, $agora);
        $this->planoVigente->atualizar((int) $pedido->getTenant()->getId(), $agora);
        $this->em->flush();

        $this->avisoAoPrimario->avisar(
            $pedido->getTenant(),
            sprintf('Pagamento confirmado: seu espaço passou para %d GB (plano %s).', $pedido->getPlano()->getCotaGb(), $pedido->getPlano()->getNome()),
            $this->urls->generate('contribua_index') . '#espaco',
        );
    }
}
