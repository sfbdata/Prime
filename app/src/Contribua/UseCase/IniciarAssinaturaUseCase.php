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
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Assinar por R$ X/mês" do checkout (desenho 08). Grava o pedido "aguardando confirmação" e pede ao
 * gateway escolhido pelo Master o começo do checkout. Nenhum dado de cartão passa por aqui.
 *
 * Só o primário assina (D4); só plano pago maior que o limite atual; uma assinatura viva por
 * escritório (para trocar de plano, cancela a atual em Meu Perfil: evita duas cobranças no mesmo cartão).
 */
final class IniciarAssinaturaUseCase
{
    public const FALHA_NO_GATEWAY = 'O gateway de pagamento não respondeu. Tente de novo em instantes.';
    public const JA_ASSINA = 'Este escritório já tem uma assinatura ativa. Para trocar de plano, cancele a atual em Meu Perfil.';
    public const EM_CONFIRMACAO = 'Seu pagamento anterior ainda está sendo confirmado pelo gateway. Aguarde alguns minutos e tente de novo.';
    private const MINUTOS_DO_CHECKOUT = 60;
    /** Primeira chave da trava consultiva (a segunda é o escritório): só o checkout do Contribua a usa. */
    private const TRAVA = 8722;

    public function __construct(
        private readonly GatewaysDeAssinatura $gateways,
        private readonly PlanoRepository $planos,
        private readonly SituacaoDoEspaco $situacao,
        private readonly AssinaturaDeEspacoRepository $assinaturas,
        private readonly ConfiguracaoDePagamentoRepository $configuracao,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        private readonly Connection $conexao,
    ) {
    }

    /**
     * Um checkout por escritório de cada vez (trava consultiva do Postgres por escritório): dois cliques
     * simultâneos não criam dois pedidos nem dois checkouts no gateway; o segundo espera e reaproveita.
     */
    public function executar(Solicitante $quem, string $codigoDoPlano, string $documento, ?string $ip): InicioDaAssinatura
    {
        $this->conexao->executeStatement('SELECT pg_advisory_lock(?, ?)', [self::TRAVA, $quem->tenantId()]);
        try {
            return $this->iniciar($quem, $codigoDoPlano, $documento, $ip);
        } finally {
            $this->conexao->executeStatement('SELECT pg_advisory_unlock(?, ?)', [self::TRAVA, $quem->tenantId()]);
        }
    }

    private function iniciar(Solicitante $quem, string $codigoDoPlano, string $documento, ?string $ip): InicioDaAssinatura
    {
        $gateway = $this->exigirGateway($quem);
        $plano = $this->planoQueAmplia($quem->tenantId(), $codigoDoPlano);
        $this->exigirSemAssinaturaViva($quem->tenantId());
        $pagador = new Pagador($quem->escritorio->getName() ?? $quem->nome(), preg_replace('/\D/', '', $documento) ?? '', (string) $quem->usuario->getEmail());

        $pedido = $this->pedidoAberto($quem, $gateway, $plano);
        if ($pedido?->getUrlDoCheckout() !== null) {
            return new InicioDaAssinatura((int) $pedido->getId(), InicioDoCheckout::REDIRECIONAR, $pedido->getUrlDoCheckout(), null, $gateway->codigo());
        }

        $pedido ??= new AssinaturaDeEspaco($quem->escritorio, $plano, $gateway->codigo(), $plano->getPrecoCentavos(), $quem->usuario);
        $this->assinaturas->salvar($pedido, flush: true);
        $inicio = $this->iniciarNoGateway($gateway, $pedido, $this->pedidoDeCheckout($pedido, $pagador, $ip));
        $pedido->registrarInicio($inicio->idNoGateway, $inicio->urlDeRedirecionamento);
        $this->assinaturas->salvar($pedido, flush: true);

        return new InicioDaAssinatura((int) $pedido->getId(), $inicio->modo, $inicio->urlDeRedirecionamento, $inicio->chavePublica, $gateway->codigo());
    }

    /**
     * O checkout aberto há menos de uma hora (o do gateway expira em 60 minutos) que ainda não foi pago:
     * do mesmo plano, é reaproveitado (a página hospedada reabre; no formulário, nova tentativa depois de
     * um cartão recusado). De outro plano: no formulário (nada foi ao gateway) é substituído; na página
     * hospedada fica como está, porque pode ter sido pago e o aviso ainda não chegou (o vínculo é pelo valor),
     * e se o pagador já voltou de lá a compra nova espera a confirmação.
     */
    private function pedidoAberto(Solicitante $quem, GatewayDeAssinatura $gateway, Plano $plano): ?AssinaturaDeEspaco
    {
        $desde = new \DateTimeImmutable('-' . self::MINUTOS_DO_CHECKOUT . ' minutes');
        foreach ($this->assinaturas->doTenant($quem->tenantId()) as $aberto) {
            if (!$aberto->semNadaNoGateway() || $aberto->getCriadaEm() < $desde || $aberto->getGateway() !== $gateway->codigo()) {
                continue;
            }
            if ($aberto->getPlano()->getId() === $plano->getId() && $aberto->getValorCentavos() === $plano->getPrecoCentavos()) {
                return $aberto;
            }
            if ($aberto->getRetornouEm() !== null) {
                throw new \DomainException(self::EM_CONFIRMACAO);
            }
            if ($aberto->getUrlDoCheckout() === null) {
                $aberto->cancelar();
                $this->assinaturas->salvar($aberto, flush: true);
            }
        }

        return null;
    }

    private function exigirGateway(Solicitante $quem): GatewayDeAssinatura
    {
        // D4: quem compra é o primário. O Master não assina pelo cliente: o pagador (e o e-mail da nota)
        // seria ele.
        if (!$quem->ehPrimario) {
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

    /**
     * Uma assinatura viva por escritório: a que ainda vale e a que continua ligada no gateway (Ativa ou
     * Inadimplente, mesmo depois da carência: o gateway seguiria cobrando o cartão antigo). Para trocar,
     * cancela antes.
     */
    private function exigirSemAssinaturaViva(int $tenantId): void
    {
        $agora = new \DateTimeImmutable();
        $carencia = $this->configuracao->atual()->getDiasDeCarencia();
        foreach ($this->assinaturas->doTenant($tenantId) as $assinatura) {
            $situacao = $assinatura->getSituacao();
            $ligada = $assinatura->getIdDaAssinatura() !== null;
            if ($ligada && $situacao === SituacaoDaAssinatura::Aguardando) {
                throw new \DomainException(self::EM_CONFIRMACAO);
            }
            if ($ligada && $situacao === SituacaoDaAssinatura::Inadimplente && $assinatura->getPagaAte() === null) {
                $this->encerrarPrimeiraRecusada($assinatura);
                continue;
            }
            $ligadaNoGateway = $ligada && \in_array($situacao, [SituacaoDaAssinatura::Ativa, SituacaoDaAssinatura::Inadimplente], true);
            if ($ligadaNoGateway || ($situacao !== SituacaoDaAssinatura::Cancelada && $assinatura->vigenteEm($agora, $carencia))) {
                throw new \DomainException(self::JA_ASSINA);
            }
        }
    }

    /**
     * A primeira cobrança foi recusada e nada foi pago: a nova tentativa ("tente de novo com outro cartão")
     * encerra essa assinatura no gateway antes, para ele não seguir tentando o cartão recusado.
     */
    private function encerrarPrimeiraRecusada(AssinaturaDeEspaco $assinatura): void
    {
        $gateway = $this->gateways->porCodigo($assinatura->getGateway());
        try {
            $gateway?->cancelar((string) $assinatura->getIdDaAssinatura());
        } catch (FalhaNoGateway $falha) {
            throw new \DomainException(self::FALHA_NO_GATEWAY, 0, $falha);
        }
        $assinatura->cancelar();
        $this->assinaturas->salvar($assinatura, flush: true);
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
