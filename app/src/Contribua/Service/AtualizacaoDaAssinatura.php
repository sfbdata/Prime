<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\Entity\AssinaturaDeEspaco;
use App\Contribua\Gateway\EmiteNotaFiscalDaAssinatura;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\GatewayDeAssinatura;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\SituacaoNoGateway;
use App\Contribua\Entity\NotificacaoRecebida;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;
use App\Contribua\Repository\NotificacaoRecebidaRepository;
use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O único caminho por onde o gateway muda o espaço de um escritório: recebe a situação CONSULTADA no
 * gateway (webhook, conciliação ou a resposta do `assinarComToken`), acha o pedido, aplica, recalcula o
 * plano vigente, grava e avisa o primário.
 *
 * Achar o pedido: pelo id da assinatura no gateway; na primeira vez (a assinatura acabou de nascer no
 * gateway), pelo checkout de onde ela veio (o id que o pedido guardou ao abrir a página hospedada) e, quando
 * o gateway não diz o checkout, pelo escritório da referência (`BJ<conta>...`), no pedido aguardando mais
 * novo daquele gateway, de preferência o de mesmo valor.
 */
final class AtualizacaoDaAssinatura
{
    public function __construct(
        private readonly AssinaturaDeEspacoRepository $assinaturas,
        private readonly PlanoVigenteDoEscritorio $planoVigente,
        private readonly AvisoAoPrimario $avisoAoPrimario,
        private readonly AvisoAoMaster $avisoAoMaster,
        private readonly NotificacaoRecebidaRepository $notificacoes,
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

    /**
     * Tudo numa transação, com o pedido travado (`FOR UPDATE`): o Asaas manda `SUBSCRIPTION_CREATED` e
     * `PAYMENT_CONFIRMED` juntos, e o webhook pode cruzar com a conciliação. Quem chega depois vê o pedido
     * já mudado e não avisa de novo. A nota fiscal e os avisos saem depois do COMMIT.
     */
    /** `primeiraVez`: o pedido acabou de ser ligado por quem chama (token), então a nota fiscal ainda falta. */
    public function aplicar(GatewayDeAssinatura $gateway, SituacaoNoGateway $noGateway, ?AssinaturaDeEspaco $pedido = null, bool $primeiraVez = false): ?AssinaturaDeEspaco
    {
        $resultado = $this->em->wrapInTransaction(fn (): ?array => $this->aplicarTravado($gateway->codigo(), $noGateway, $pedido));
        if ($resultado === null) {
            $this->avisarSemPedido($gateway->codigo(), $noGateway);

            return null;
        }

        [$pedido, $mudou, $acabouDeLigar] = $resultado;
        if ($acabouDeLigar || $primeiraVez) {
            $this->ligarNotaFiscal($gateway, $noGateway->idDaAssinatura);
        }
        if ($mudou) {
            $this->avisarPrimario($pedido);
            $this->conferirDuplicata($pedido);
        }

        return $pedido;
    }

    /** @return array{AssinaturaDeEspaco, bool, bool}|null */
    private function aplicarTravado(string $codigoDoGateway, SituacaoNoGateway $noGateway, ?AssinaturaDeEspaco $pedido): ?array
    {
        $pedido ??= $this->localizar($codigoDoGateway, $noGateway);
        if ($pedido === null) {
            return null;
        }

        $this->em->refresh($pedido, LockMode::PESSIMISTIC_WRITE);
        $acabouDeLigar = $pedido->getIdDaAssinatura() === null;
        $pedido->vincularNoGateway($noGateway->idDaAssinatura, $noGateway->idDoCliente);
        $mudou = $pedido->aplicar($noGateway);
        $this->planoVigente->atualizar((int) $pedido->getTenant()->getId(), new \DateTimeImmutable());

        return [$pedido, $mudou, $acabouDeLigar];
    }

    /**
     * Assinatura paga no gateway sem pedido no BlueJus (checkout substituído e pago mesmo assim, valor
     * diferente): o espaço não é liberado por palpite, mas o Master precisa ver para ligar à mão ou estornar.
     */
    private function avisarSemPedido(string $codigoDoGateway, SituacaoNoGateway $noGateway): void
    {
        $this->logger->warning('Contribua: assinatura do gateway sem pedido no BlueJus', ['gateway' => $codigoDoGateway, 'assinatura' => $noGateway->idDaAssinatura]);
        // Sem referência (o Asaas pode não a levar à assinatura), o escritório vem do pedido que abriu o checkout.
        $tenantId = self::tenantDaReferencia($noGateway->referencia) ?? $this->pedidoDoCheckout($codigoDoGateway, $noGateway)?->getTenant()->getId();
        if ($tenantId === null || $noGateway->situacao !== SituacaoDaAssinatura::Ativa) {
            return;
        }
        // Uma vez por assinatura (o gateway avisa a cada cobrança): a trava de idempotência dos avisos serve.
        $primeiraVez = $this->notificacoes->registrarSeNova(new NotificacaoRecebida('contribua', 'sem-pedido:' . $codigoDoGateway . ':' . $noGateway->idDaAssinatura, 'assinatura_sem_pedido', $noGateway->idDaAssinatura));
        $escritorio = $primeiraVez ? $this->em->find(Tenant::class, $tenantId) : null;
        if ($escritorio !== null) {
            $this->avisoAoMaster->avisar($escritorio, AvisoAoPrimario::TIPO_ASSINATURA, sprintf('Assinatura paga sem pedido no BlueJus: %s, %s (%s). Confira no painel do gateway.', (string) $escritorio->getName(), $noGateway->idDaAssinatura, $codigoDoGateway), $this->urls->generate('contribua_index') . '#espaco');
        }
    }

    /**
     * Duas assinaturas pagas no mesmo escritório (dois checkouts abertos e pagos) seriam duas cobranças no
     * cartão. O BlueJus não cancela sozinho qual delas: avisa o Master para cancelar a que sobra.
     */
    private function conferirDuplicata(AssinaturaDeEspaco $pedido): void
    {
        if ($pedido->getSituacao() !== SituacaoDaAssinatura::Ativa) {
            return;
        }
        $outras = array_filter(
            $this->assinaturas->doTenant((int) $pedido->getTenant()->getId()),
            static fn (AssinaturaDeEspaco $a): bool => $a->getId() !== $pedido->getId() && \in_array($a->getSituacao(), [SituacaoDaAssinatura::Ativa, SituacaoDaAssinatura::Inadimplente], true),
        );
        if ($outras !== []) {
            $this->avisoAoMaster->avisar($pedido->getTenant(), AvisoAoPrimario::TIPO_ASSINATURA, sprintf('Atenção: %s tem %d assinaturas de espaço ativas no cartão. Cancele a que sobra no painel do gateway.', (string) $pedido->getTenant()->getName(), \count($outras) + 1), $this->urls->generate('contribua_index') . '#espaco');
        }
    }

    /**
     * A nota mensal é ligada na primeira vez que a assinatura aparece. Falha aqui não impede o espaço de
     * ser liberado: vira log de erro para o Master ligar a nota no painel do gateway.
     */
    public function ligarNotaFiscal(GatewayDeAssinatura $gateway, string $idDaAssinatura): void
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
        if ($ligado !== null) {
            return $ligado;
        }

        // O checkout de origem é o vínculo exato. O pedido que o abriu recebe a assinatura só se ainda espera
        // uma (e se a referência, quando vem, é do mesmo escritório); cancelado ou já ligado a outra, a
        // assinatura não vai a outro pedido por palpite.
        $doCheckout = $this->pedidoDoCheckout($codigoDoGateway, $noGateway);
        if ($doCheckout !== null) {
            $mesmoEscritorio = $tenantId === null || $tenantId === $doCheckout->getTenant()->getId();

            return $mesmoEscritorio && self::esperaAssinatura($doCheckout) ? $doCheckout : null;
        }
        if ($tenantId === null) {
            return null;
        }

        $candidatos = $this->assinaturas->semAssinaturaNoGateway($tenantId, $codigoDoGateway);
        foreach ($candidatos as $candidato) {
            if ($noGateway->valorCentavos === null || $candidato->getValorCentavos() === $noGateway->valorCentavos) {
                return $candidato;
            }
        }

        // Valor diferente do de qualquer pedido: não libera plano nenhum por palpite (fica no log para o Master).
        return null;
    }

    private function pedidoDoCheckout(string $codigoDoGateway, SituacaoNoGateway $noGateway): ?AssinaturaDeEspaco
    {
        return $noGateway->idDoCheckout === null ? null : $this->assinaturas->porIdDoCheckout($codigoDoGateway, $noGateway->idDoCheckout);
    }

    /** Sem assinatura ligada e ainda aguardando (ou confirmado à mão pelo Master): o critério do `semAssinaturaNoGateway`. */
    private static function esperaAssinatura(AssinaturaDeEspaco $pedido): bool
    {
        return $pedido->getIdDaAssinatura() === null
            && \in_array($pedido->getSituacao(), [SituacaoDaAssinatura::Aguardando, SituacaoDaAssinatura::Ativa], true);
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
            SituacaoDaAssinatura::Ativa => sprintf('Pagamento confirmado: seu espaço passou para %d GB (plano %s). Próxima cobrança em %s.', $pedido->gigabytes(), $plano->getNome(), $ate),
            SituacaoDaAssinatura::Inadimplente => $ate === '' ? sprintf('A primeira cobrança do plano %s não foi aprovada. Tente de novo em Contribua, com outro cartão.', $plano->getNome()) : sprintf('A cobrança do plano %s não foi aprovada. Atualize o cartão no gateway: o espaço continua por alguns dias de carência.', $plano->getNome()),
            SituacaoDaAssinatura::Cancelada => $ate === '' ? null : sprintf('Assinatura do plano %s cancelada. O espaço vale até %s e nenhum arquivo é apagado.', $plano->getNome(), $ate),
            SituacaoDaAssinatura::Aguardando => null,
        };
        if ($mensagem !== null) {
            $this->avisoAoPrimario->avisar($pedido->getTenant(), $mensagem, $this->urls->generate('contribua_index') . '#espaco');
        }
    }
}
