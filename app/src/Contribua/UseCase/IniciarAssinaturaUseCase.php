<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\InicioDaAssinatura;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\AssinaturaDeEspaco;
use App\Contribua\Entity\Plano;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\GatewayDeAssinatura;
use App\Contribua\Gateway\GatewaysDeAssinatura;
use App\Contribua\Gateway\InicioDoCheckout;
use App\Contribua\Gateway\Pagador;
use App\Contribua\Gateway\PedidoDeCheckout;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;
use App\Contribua\Repository\ConfiguracaoDePagamentoRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Assinar por R$ X/mês" do checkout (desenho 08). Grava o pedido "aguardando confirmação" e pede ao
 * gateway escolhido pelo Master o começo do checkout. Nenhum dado de cartão passa por aqui.
 *
 * Só o primário (ou o Master) assina; só plano pago maior que o limite atual; uma assinatura viva por
 * escritório (para trocar de plano, cancela a atual em Meu Perfil: evita duas cobranças no mesmo cartão).
 */
final class IniciarAssinaturaUseCase
{
    public const FALHA_NO_GATEWAY = 'O gateway de pagamento não respondeu. Tente de novo em instantes.';
    public const JA_ASSINA = 'Este escritório já tem uma assinatura ativa. Para trocar de plano, cancele a atual em Meu Perfil.';

    public function __construct(
        private readonly GatewaysDeAssinatura $gateways,
        private readonly PlanoRepository $planos,
        private readonly SituacaoDoEspaco $situacao,
        private readonly AssinaturaDeEspacoRepository $assinaturas,
        private readonly ConfiguracaoDePagamentoRepository $configuracao,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function executar(Solicitante $quem, string $codigoDoPlano, string $documento, ?string $ip): InicioDaAssinatura
    {
        $gateway = $this->exigirGateway($quem);
        $plano = $this->planoQueAmplia($quem->tenantId(), $codigoDoPlano);
        $this->exigirSemAssinaturaViva($quem->tenantId());
        $pagador = new Pagador($quem->escritorio->getName() ?? $quem->nome(), preg_replace('/\D/', '', $documento) ?? '', (string) $quem->usuario->getEmail());

        $pedido = new AssinaturaDeEspaco($quem->escritorio, $plano, $gateway->codigo(), $plano->getPrecoCentavos(), $quem->usuario);
        $this->assinaturas->salvar($pedido, flush: true);
        $inicio = $this->iniciarNoGateway($gateway, $pedido, $this->pedidoDeCheckout($pedido, $pagador, $ip));
        $pedido->registrarInicio($inicio->idNoGateway);
        $this->assinaturas->salvar($pedido, flush: true);

        return new InicioDaAssinatura((int) $pedido->getId(), $inicio->modo, $inicio->urlDeRedirecionamento, $inicio->chavePublica, $gateway->codigo());
    }

    private function exigirGateway(Solicitante $quem): GatewayDeAssinatura
    {
        if (!$quem->ehPrimario && !$quem->ehMaster) {
            throw new \DomainException('Quem amplia o espaço é o usuário primário do escritório.');
        }

        return $this->gateways->configurado() ?? throw new \DomainException('A assinatura por cartão ainda não foi liberada.');
    }

    private function planoQueAmplia(int $tenantId, string $codigo): Plano
    {
        $plano = $this->planos->findOneBy(['codigo' => $codigo, 'ativo' => true]);
        $limite = $this->situacao->doEscritorio($tenantId)->bytesLimite;
        if (!$plano instanceof Plano || $plano->ehGratuito() || $limite === null || $plano->getCotaGb() * 1024 ** 3 <= $limite) {
            throw new \DomainException('Escolha um plano maior que o espaço atual.');
        }

        return $plano;
    }

    private function exigirSemAssinaturaViva(int $tenantId): void
    {
        foreach ($this->assinaturas->doTenant($tenantId) as $assinatura) {
            if (\in_array($assinatura->getSituacao(), [SituacaoDaAssinatura::Ativa, SituacaoDaAssinatura::Inadimplente], true)) {
                throw new \DomainException(self::JA_ASSINA);
            }
        }
    }

    private function pedidoDeCheckout(AssinaturaDeEspaco $pedido, Pagador $pagador, ?string $ip): PedidoDeCheckout
    {
        $plano = $pedido->getPlano();
        $pagina = $this->urls->generate('contribua_index', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return new PedidoDeCheckout(
            $pedido->referencia(),
            (int) $pedido->getTenant()->getId(),
            $plano->getCodigo(),
            $plano->getNome(),
            $pedido->getValorCentavos(),
            $pagador,
            $pagina . '?assinatura=ok',
            $pagina . '#espaco',
            $ip,
            $this->configuracao->atual()->linkDoPacote($plano->getCodigo()),
        );
    }

    /** Gateway fora do ar: o pedido sai (não fica um "aguardando" que o Master poderia confirmar por engano). */
    private function iniciarNoGateway(GatewayDeAssinatura $gateway, AssinaturaDeEspaco $pedido, PedidoDeCheckout $checkout): InicioDoCheckout
    {
        try {
            return $gateway->iniciar($checkout);
        } catch (FalhaNoGateway $falha) {
            $this->assinaturas->remover($pedido, flush: true);
            $this->logger->error('Contribua: o gateway recusou o início do checkout', ['gateway' => $gateway->codigo(), 'referencia' => $checkout->referencia, 'erro' => $falha->getMessage()]);

            throw new \DomainException(self::FALHA_NO_GATEWAY, 0, $falha);
        }
    }
}
