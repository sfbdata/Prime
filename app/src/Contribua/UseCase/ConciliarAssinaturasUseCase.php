<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Gateway\GatewaysDeAssinatura;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;
use App\Contribua\Repository\NotificacaoRecebidaRepository;
use App\Contribua\Service\AtualizacaoDaAssinatura;
use App\Contribua\Service\PlanoVigenteDoEscritorio;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * A rede de segurança do webhook (`app:contribua:conciliar-assinaturas`, de hora em hora no cron):
 *  1. reprocessa os avisos que ficaram pendentes;
 *  2. reconsulta no gateway toda assinatura que ainda pode mudar (aviso perdido, renovação, recusa);
 *  3. revê o plano de quem teve assinatura paga: o fim do mês pago (ou da carência) devolve ao Free
 *     mesmo sem aviso nenhum do gateway.
 *
 * Um gateway fora do ar (ou um item com erro) não para o resto: conta como falha e segue.
 */
final class ConciliarAssinaturasUseCase
{
    public function __construct(
        private readonly GatewaysDeAssinatura $gateways,
        private readonly NotificacaoRecebidaRepository $notificacoes,
        private readonly AssinaturaDeEspacoRepository $assinaturas,
        private readonly ReceberAvisoDoGatewayUseCase $avisos,
        private readonly AtualizacaoDaAssinatura $atualizacao,
        private readonly PlanoVigenteDoEscritorio $planoVigente,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{avisos: int, reconsultadas: int, falhas: int, escritorios: int} */
    public function executar(\DateTimeImmutable $agora): array
    {
        $avisos = $this->reprocessarAvisos();
        [$reconsultadas, $falhas] = $this->reconsultarAssinaturas();

        if (!$this->em->isOpen()) {
            return ['avisos' => $avisos, 'reconsultadas' => $reconsultadas, 'falhas' => $falhas, 'escritorios' => 0];
        }
        $escritorios = $this->assinaturas->escritoriosComAssinaturaPaga();
        foreach ($escritorios as $tenantId) {
            $this->planoVigente->atualizar($tenantId, $agora);
        }
        $this->em->flush();

        return ['avisos' => $avisos, 'reconsultadas' => $reconsultadas, 'falhas' => $falhas, 'escritorios' => \count($escritorios)];
    }

    private function reprocessarAvisos(): int
    {
        $processados = 0;
        foreach ($this->notificacoes->pendentes($this->gateways->codigosInstalados()) as $aviso) {
            $gateway = $this->gateways->porCodigo($aviso->getOrigem());
            if ($gateway !== null && $this->avisos->processar($gateway, $aviso->getIdEvento(), $aviso->getIdRecurso()) === ReceberAvisoDoGatewayUseCase::PROCESSADO) {
                ++$processados;
            }
        }

        return $processados;
    }

    /** @return array{int, int} */
    private function reconsultarAssinaturas(): array
    {
        $ok = 0;
        $falhas = 0;
        foreach ($this->assinaturas->paraConciliar() as $assinatura) {
            $gateway = $this->gateways->porCodigo($assinatura->getGateway());
            if ($gateway === null) {
                continue;
            }
            try {
                $this->atualizacao->reconsultar($gateway, (string) $assinatura->getIdDaAssinatura());
                ++$ok;
            } catch (\Throwable $falha) {
                ++$falhas;
                $this->logger->warning('Contribua: conciliação não conseguiu reconsultar', ['gateway' => $gateway->codigo(), 'assinatura' => $assinatura->getIdDaAssinatura(), 'erro' => $falha->getMessage()]);
                // Um erro de banco dentro da transação fecha o EntityManager: os itens seguintes ficam para a
                // próxima rodada (de hora em hora), em vez de falharem um a um.
                if (!$this->em->isOpen()) {
                    break;
                }
            }
        }

        return [$ok, $falhas];
    }
}
