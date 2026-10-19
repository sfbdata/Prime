<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\AssinaturaDeEspaco;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\GatewaysDeAssinatura;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;
use App\Contribua\Service\AvisoAoMaster;
use App\Contribua\Service\AvisoAoPrimario;
use App\Contribua\Service\PlanoVigenteDoEscritorio;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Cancele quando quiser em Meu Perfil" (desenho 08). Cancela primeiro no gateway (a próxima cobrança não
 * acontece) e só então aqui; o espaço continua até o fim do mês pago e depois volta ao Free. Nada é
 * apagado. Só o primário (ou o Master) cancela.
 *
 * Gateway fora do ar: nada muda e a pessoa tenta de novo (cancelar só aqui deixaria o cartão sendo
 * cobrado).
 */
final class CancelarAssinaturaUseCase
{
    public function __construct(
        private readonly AssinaturaDeEspacoRepository $assinaturas,
        private readonly GatewaysDeAssinatura $gateways,
        private readonly PlanoVigenteDoEscritorio $planoVigente,
        private readonly AvisoAoMaster $avisoAoMaster,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function executar(Solicitante $quem): string
    {
        $assinatura = $this->assinaturaViva($quem);
        $noGateway = $this->cancelarNoGateway($assinatura);
        $assinatura->cancelar();
        $this->planoVigente->atualizar($quem->tenantId(), new \DateTimeImmutable());
        $this->em->flush();

        $this->avisarMaster($quem, $assinatura, $noGateway);
        $ate = $assinatura->getPagaAte()?->format('d/m/Y');
        $resposta = $ate === null ? 'Assinatura cancelada.' : sprintf('Assinatura cancelada. O espaço do plano vale até %s e nenhum arquivo é apagado.', $ate);

        return $noGateway ? $resposta : $resposta . ' O Master encerra a cobrança no gateway e confirma com você.';
    }

    /** Sem cancelamento automático no gateway (assinatura não ligada ou gateway desinstalado), o Master encerra à mão. */
    private function avisarMaster(Solicitante $quem, AssinaturaDeEspaco $assinatura, bool $noGateway): void
    {
        $texto = sprintf('Assinatura cancelada: %s (conta Nº %s), plano %s.', $quem->escritorio->getName(), $quem->numeroDaConta(), $assinatura->getPlano()->getNome());
        if (!$noGateway) {
            $texto .= ' Cancele também no painel do gateway: o BlueJus não conseguiu cancelar lá.';
        }
        $this->avisoAoMaster->avisar($quem->escritorio, AvisoAoPrimario::TIPO_ASSINATURA, $texto, $this->urls->generate('contribua_index') . '#espaco');
    }

    private function assinaturaViva(Solicitante $quem): AssinaturaDeEspaco
    {
        if (!$quem->ehPrimario && !$quem->ehMaster) {
            throw new \DomainException('Quem cancela a assinatura é o usuário primário do escritório.');
        }
        foreach ($this->assinaturas->doTenant($quem->tenantId()) as $assinatura) {
            if (\in_array($assinatura->getSituacao(), [SituacaoDaAssinatura::Ativa, SituacaoDaAssinatura::Inadimplente], true)) {
                return $assinatura;
            }
        }

        throw new \DomainException('Não há assinatura ativa para cancelar.');
    }

    /** Devolve true quando o próprio BlueJus cancelou no gateway. */
    private function cancelarNoGateway(AssinaturaDeEspaco $assinatura): bool
    {
        $id = $assinatura->getIdDaAssinatura();
        $gateway = $this->gateways->porCodigo($assinatura->getGateway());
        if ($id === null || $gateway === null) {
            return false;
        }

        try {
            $gateway->cancelar($id);

            return true;
        } catch (FalhaNoGateway $falha) {
            $this->logger->error('Contribua: cancelamento no gateway falhou', ['gateway' => $gateway->codigo(), 'assinatura' => $id, 'erro' => $falha->getMessage()]);

            throw new \DomainException('O gateway de pagamento não respondeu e a assinatura continua ativa. Tente de novo em instantes.', 0, $falha);
        }
    }
}
