<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\Entity\AssinaturaDeEspaco;
use App\Contribua\Gateway\EmiteNotaFiscalDaAssinatura;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\GatewayDeAssinatura;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\SituacaoNoGateway;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O único caminho por onde o gateway muda o espaço de um escritório: recebe a situação CONSULTADA no
 * gateway (webhook, conciliação ou a resposta do `assinarComToken`), acha o pedido, aplica, recalcula o
 * plano vigente, grava e avisa o primário.
 *
 * Achar o pedido: pelo id da assinatura no gateway; na primeira vez (a assinatura acabou de nascer no
 * gateway), pelo escritório da referência (`BJ<conta>...`), no pedido aguardando mais novo daquele
 * gateway, de preferência o de mesmo valor.
 */
final class AtualizacaoDaAssinatura
{
    public function __construct(
        private readonly AssinaturaDeEspacoRepository $assinaturas,
        private readonly PlanoVigenteDoEscritorio $planoVigente,
        private readonly AvisoAoPrimario $avisoAoPrimario,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Consulta o gateway e aplica. @throws FalhaNoGateway */
    public function reconsultar(GatewayDeAssinatura $gateway, string $idDaAssinatura): ?AssinaturaDeEspaco
    {
        return $this->aplicar($gateway, $gateway->consultar($idDaAssinatura));
    }

    public function aplicar(GatewayDeAssinatura $gateway, SituacaoNoGateway $noGateway, ?AssinaturaDeEspaco $pedido = null): ?AssinaturaDeEspaco
    {
        $pedido ??= $this->localizar($gateway->codigo(), $noGateway);
        if ($pedido === null) {
            $this->logger->warning('Contribua: assinatura do gateway sem pedido no BlueJus', ['gateway' => $gateway->codigo(), 'assinatura' => $noGateway->idDaAssinatura]);

            return null;
        }

        if ($pedido->getIdDaAssinatura() === null) {
            $this->ligarNotaFiscal($gateway, $noGateway->idDaAssinatura);
        }
        $pedido->vincularNoGateway($noGateway->idDaAssinatura, $noGateway->idDoCliente);
        $mudou = $pedido->aplicar($noGateway);
        $this->planoVigente->atualizar((int) $pedido->getTenant()->getId(), new \DateTimeImmutable());
        $this->em->flush();

        if ($mudou) {
            $this->avisarPrimario($pedido);
        }

        return $pedido;
    }

    /**
     * A nota mensal é ligada na primeira vez que a assinatura aparece. Falha aqui não impede o espaço de
     * ser liberado: vira log de erro para o Master ligar a nota no painel do gateway.
     */
    private function ligarNotaFiscal(GatewayDeAssinatura $gateway, string $idDaAssinatura): void
    {
        if (!$gateway instanceof EmiteNotaFiscalDaAssinatura) {
            return;
        }

        try {
            $gateway->configurarNotaFiscalMensal($idDaAssinatura);
        } catch (FalhaNoGateway $falha) {
            $this->logger->error('Contribua: nota fiscal mensal não foi ligada na assinatura', ['gateway' => $gateway->codigo(), 'assinatura' => $idDaAssinatura, 'erro' => $falha->getMessage()]);
        }
    }

    private function localizar(string $codigoDoGateway, SituacaoNoGateway $noGateway): ?AssinaturaDeEspaco
    {
        $ligado = $this->assinaturas->porIdNoGateway($codigoDoGateway, $noGateway->idDaAssinatura);
        $tenantId = self::tenantDaReferencia($noGateway->referencia);
        if ($ligado !== null || $tenantId === null) {
            return $ligado;
        }

        $candidatos = $this->assinaturas->semAssinaturaNoGateway($tenantId, $codigoDoGateway);
        foreach ($candidatos as $candidato) {
            if ($candidato->getValorCentavos() === $noGateway->valorCentavos) {
                return $candidato;
            }
        }

        return $candidatos[0] ?? null;
    }

    /** `BJ000007-essencial-42` ou `BJ000007` → 7. */
    public static function tenantDaReferencia(?string $referencia): ?int
    {
        if ($referencia === null || preg_match('/^BJ(\d{1,10})(?:-|$)/', $referencia, $m) !== 1) {
            return null;
        }

        return (int) $m[1] > 0 ? (int) $m[1] : null;
    }

    private function avisarPrimario(AssinaturaDeEspaco $pedido): void
    {
        $plano = $pedido->getPlano();
        $ate = $pedido->getPagaAte()?->format('d/m/Y') ?? '';
        $mensagem = match ($pedido->getSituacao()) {
            SituacaoDaAssinatura::Ativa => sprintf('Pagamento confirmado: seu espaço passou para %d GB (plano %s). Próxima cobrança em %s.', $plano->getCotaGb(), $plano->getNome(), $ate),
            SituacaoDaAssinatura::Inadimplente => sprintf('A cobrança do plano %s não foi aprovada. Atualize o cartão no gateway: o espaço continua por alguns dias de carência.', $plano->getNome()),
            SituacaoDaAssinatura::Cancelada => $ate === '' ? null : sprintf('Assinatura do plano %s cancelada. O espaço vale até %s e nenhum arquivo é apagado.', $plano->getNome(), $ate),
            SituacaoDaAssinatura::Aguardando => null,
        };
        if ($mensagem !== null) {
            $this->avisoAoPrimario->avisar($pedido->getTenant(), $mensagem, $this->urls->generate('contribua_index') . '#espaco');
        }
    }
}
