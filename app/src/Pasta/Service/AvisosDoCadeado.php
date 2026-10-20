<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Pasta\Entity\EstadoDoPedidoDeAcesso;
use App\Pasta\Entity\PedidoDeAcessoAPasta;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tenant\Service\RegraDosPrimarios;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Os avisos do pedido de acesso (spec pasta-cadeado K5), no sino e na Caixa da Central. O desenho manda o
 * pedido para a conversa com a BlueJus IA (`pedirAcessoPasta`), que não existe no sistema: a notificação
 * é o canal real, com o link para a pasta, onde quem configura decide. A notificação grava com flush.
 */
final class AvisosDoCadeado
{
    public function __construct(
        private readonly UserTenantRepository $userTenantRepository,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly NotificacaoService $notificacoes,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** Avisa cada primário ativo e cada Master com vínculo ativo no escritório, menos quem pediu. Devolve quantos. */
    public function avisarPedido(PedidoDeAcessoAPasta $pedido): int
    {
        $titulo = self::cabe($this->textoDoPedido($pedido));
        $url    = $this->urlDaPasta($pedido) . '#cadeado-pedidos';
        $avisados = 0;
        foreach ($this->quemDecide($pedido) as $quem) {
            $aviso = $this->notificacoes->criarNotificacao($quem, $pedido->getTenant(), Notificacao::TIPO_PASTA_PEDIDO_ACESSO, $titulo, $url);
            // O motivo (até 300 caracteres) não cabe no título (255): vai no corpo, gravado pelo flush de quem chama.
            if ($pedido->getMotivo() !== null) {
                $aviso->setMensagem($titulo . ' Motivo: "' . $pedido->getMotivo() . '"');
            }
            ++$avisados;
        }

        return $avisados;
    }

    /** A resposta para quem pediu (`cadResolver` do desenho, sem o emoji). */
    public function avisarDecisao(PedidoDeAcessoAPasta $pedido): void
    {
        $numero = (string) $pedido->getPasta()->getNup();
        $texto  = $pedido->foiRecusado()
            ? sprintf('O usuário primário não liberou o acesso à Pasta %s por enquanto.', $numero)
            : sprintf('O usuário primário liberou a Pasta %s para você %s.', $numero, $pedido->getEstado() === EstadoDoPedidoDeAcesso::LiberadoEditar ? 'editar' : 'visualizar');

        $this->notificacoes->criarNotificacao($pedido->getSolicitante(), $pedido->getTenant(), Notificacao::TIPO_PASTA_ACESSO_DECIDIDO, self::cabe($texto), $this->urlDaPasta($pedido));
    }

    private function textoDoPedido(PedidoDeAcessoAPasta $pedido): string
    {
        $cliente = $pedido->getPasta()->getClientePrincipal()?->getNomeExibicao() ?? $pedido->getPasta()->getNomeCliente();

        return sprintf(
            '%s pediu acesso à Pasta %s%s às %s, para %s.',
            $pedido->getSolicitante()->getFullName() ?? $pedido->getSolicitante()->getEmail(),
            (string) $pedido->getPasta()->getNup(),
            $cliente !== null && trim($cliente) !== '' ? ' (' . trim($cliente) . ')' : '',
            $pedido->getPedidoEm()->format('H:i'),
            $pedido->getNivelPedido()->verbo(),
        );
    }

    /** O título da notificação tem 255 caracteres; nome de cliente longo não pode derrubar o pedido. */
    private static function cabe(string $texto): string
    {
        return mb_strimwidth($texto, 0, 255, '…');
    }

    /** @return list<User> */
    private function quemDecide(PedidoDeAcessoAPasta $pedido): array
    {
        $quem = [];
        foreach ($this->userTenantRepository->findAtivosComPerfilPorTenant($pedido->getTenant()) as $vinculo) {
            if ($this->decide($vinculo) && $vinculo->getUser()->getId() !== $pedido->getSolicitante()->getId()) {
                $quem[] = $vinculo->getUser();
            }
        }

        return $quem;
    }

    private function decide(UserTenant $vinculo): bool
    {
        return $this->regraDosPrimarios->ehPrimario($vinculo)
            || ($vinculo->getUser()->isActive() && $this->regraDosPrimarios->ehMaster($vinculo->getUser()));
    }

    private function urlDaPasta(PedidoDeAcessoAPasta $pedido): string
    {
        return $this->urlGenerator->generate('pasta_show', ['id' => $pedido->getPasta()->getId()]);
    }
}
