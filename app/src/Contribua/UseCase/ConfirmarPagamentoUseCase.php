<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Entity\AssinaturaDeEspaco;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;
use App\Contribua\Service\AvisoAoPrimario;
use App\Contribua\Service\PlanoVigenteDoEscritorio;
use App\Contribua\Service\VisaoDoMaster;
use App\Entity\Auth\User;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
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
        private readonly EscopoDeAuditoriaDePlataforma $auditoria,
    ) {
    }

    public function executar(User $master, int $pedidoId): void
    {
        // O Master pode estar navegando em outro escritório: o filtro da sessão fica suspenso no trabalho
        // inteiro (leitura das assinaturas do plano vigente inclusive), e a auditoria vai para a conta do pedido.
        $pedido = $this->visaoDoMaster->deTodosOsEscritorios(function () use ($master, $pedidoId): AssinaturaDeEspaco {
            $pedido = $this->assinaturas->find($pedidoId) ?? throw new \DomainException('Pedido não encontrado.');
            $agora = new \DateTimeImmutable();
            $pedido->confirmarManualmente($master, $agora);
            $tenantId = (int) $pedido->getTenant()->getId();
            $this->planoVigente->atualizar($tenantId, $agora);
            $this->auditoria->executarComoConta($tenantId, fn () => $this->em->flush());

            return $pedido;
        });

        $this->avisoAoPrimario->avisar(
            $pedido->getTenant(),
            sprintf('Pagamento confirmado: seu espaço passou para %d GB (plano %s).', $pedido->getPlano()->getCotaGb(), $pedido->getPlano()->getNome()),
            $this->urls->generate('contribua_index') . '#espaco',
        );
    }
}
