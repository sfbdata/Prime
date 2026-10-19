<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\AssinaturaDeEspaco;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\GatewayDeAssinatura;
use App\Contribua\Gateway\GatewaysDeAssinatura;
use App\Contribua\Gateway\Pagador;
use App\Contribua\Gateway\PedidoDeCheckout;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\SituacaoNoGateway;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;
use App\Contribua\Service\AtualizacaoDaAssinatura;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Segundo passo do checkout nos gateways de formulário (Stripe, Mercado Pago, Pagar.me): o navegador já
 * transformou o cartão em token com a chave pública; aqui só o token chega. A resposta do gateway passa
 * pelo mesmo caminho do webhook (`AtualizacaoDaAssinatura`).
 *
 * Devolve a data da próxima cobrança (mensagem "Pagamento enviado" do desenho) e, quando o banco pede 3-D
 * Secure, o segredo que o navegador usa para concluir a autenticação.
 */
final class AssinarComTokenUseCase
{
    public function __construct(
        private readonly AssinaturaDeEspacoRepository $assinaturas,
        private readonly GatewaysDeAssinatura $gateways,
        private readonly AtualizacaoDaAssinatura $atualizacao,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{proxima: string, acaoNoNavegador: ?string} */
    public function executar(Solicitante $quem, int $pedidoId, string $token, string $documento, ?string $ip): array
    {
        $pedido = $this->pedidoAguardando($quem, $pedidoId);
        $gateway = $this->gateways->porCodigo($pedido->getGateway()) ?? throw new \DomainException('A assinatura por cartão ainda não foi liberada.');
        if (trim($token) === '') {
            throw new \DomainException('Número do cartão inválido.');
        }

        $pagador = new Pagador($quem->escritorio->getName() ?? $quem->nome(), preg_replace('/\D/', '', $documento) ?? '', (string) $quem->usuario->getEmail());
        $resposta = $this->assinarNoGateway($gateway, $pedido, $this->checkout($pedido, $pagador, $ip), $token);
        $proxima = $pedido->getPagaAte() ?? new \DateTimeImmutable('+1 month');

        return ['proxima' => $proxima->format('d/m/Y'), 'acaoNoNavegador' => $resposta->acaoNoNavegador];
    }

    private function pedidoAguardando(Solicitante $quem, int $pedidoId): AssinaturaDeEspaco
    {
        if (!$quem->ehPrimario && !$quem->ehMaster) {
            throw new \DomainException('Quem amplia o espaço é o usuário primário do escritório.');
        }
        $pedido = $this->assinaturas->doEscritorio($quem->tenantId(), $pedidoId);
        if ($pedido === null || $pedido->getSituacao() !== SituacaoDaAssinatura::Aguardando || $pedido->getIdDaAssinatura() !== null) {
            throw new \DomainException('Pedido não encontrado. Abra o checkout de novo.');
        }

        return $pedido;
    }

    private function checkout(AssinaturaDeEspaco $pedido, Pagador $pagador, ?string $ip): PedidoDeCheckout
    {
        $pagina = $this->urls->generate('contribua_index', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $plano = $pedido->getPlano();

        return new PedidoDeCheckout($pedido->referencia(), (int) $pedido->getTenant()->getId(), $plano->getCodigo(), $plano->getNome(), $pedido->getValorCentavos(), $pagador, $pagina . '?assinatura=ok', $pagina . '#espaco', $ip);
    }

    private function assinarNoGateway(GatewayDeAssinatura $gateway, AssinaturaDeEspaco $pedido, PedidoDeCheckout $checkout, string $token): SituacaoNoGateway
    {
        try {
            $resposta = $gateway->assinarComToken($checkout, $token);
            $this->atualizacao->aplicar($gateway, $resposta, $pedido);

            return $resposta;
        } catch (FalhaNoGateway $falha) {
            $this->logger->error('Contribua: o gateway recusou a assinatura com token', ['gateway' => $gateway->codigo(), 'referencia' => $checkout->referencia, 'erro' => $falha->getMessage()]);

            throw new \DomainException(IniciarAssinaturaUseCase::FALHA_NO_GATEWAY, 0, $falha);
        } catch (\DomainException $recusa) {
            throw $recusa;
        } catch (\LogicException $soHospedado) {
            throw new \DomainException('Este gateway recebe o cartão na página de pagamento dele.', 0, $soHospedado);
        }
    }
}
