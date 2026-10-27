<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Entity\NotificacaoRecebida;
use App\Contribua\Gateway\AvisoNaoAutenticado;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\GatewayDeAssinatura;
use App\Contribua\Gateway\GatewaysDeAssinatura;
use App\Contribua\Repository\NotificacaoRecebidaRepository;
use App\Contribua\Service\AtualizacaoDaAssinatura;
use Psr\Log\LoggerInterface;

/**
 * Webhook de um gateway de cartão (`/api/webhooks/<codigo>`). O aviso nunca muda nada sozinho:
 *  1. o adaptador autentica e traduz (sem credencial → `AvisoNaoAutenticado`, 401);
 *  2. o aviso é gravado uma vez só (`NotificacaoRecebida`, entrega "pelo menos uma vez");
 *  3. o BlueJus RECONSULTA a assinatura no gateway e só então aplica.
 *
 * Gateway fora do ar na reconsulta não devolve erro ao gateway: o aviso fica pendente e a conciliação
 * tenta de novo. Responder erro faria o gateway reenviar e, no Asaas, pausar a fila depois de 15 falhas.
 */
final class ReceberAvisoDoGatewayUseCase
{
    public const DESCONHECIDO = 'desconhecido';
    public const IGNORADO = 'ignorado';
    public const REPETIDO = 'repetido';
    public const PENDENTE = 'pendente';
    public const PROCESSADO = 'processado';

    public function __construct(
        private readonly GatewaysDeAssinatura $gateways,
        private readonly NotificacaoRecebidaRepository $notificacoes,
        private readonly AtualizacaoDaAssinatura $atualizacao,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $cabecalhos em minúsculas
     *
     * @throws AvisoNaoAutenticado
     */
    public function executar(string $codigo, array $cabecalhos, string $corpo): string
    {
        $gateway = $this->gateways->porCodigo($codigo);
        if ($gateway === null) {
            return self::DESCONHECIDO;
        }

        $aviso = $gateway->interpretarAviso($cabecalhos, $corpo);
        if ($aviso === null) {
            return self::IGNORADO;
        }
        if (!$this->notificacoes->registrarSeNova(new NotificacaoRecebida($codigo, $aviso->idDoEvento, mb_substr($aviso->tipo, 0, 60), $aviso->idDaAssinatura))) {
            return self::REPETIDO;
        }

        return $this->processar($gateway, $aviso->idDoEvento, $aviso->idDaAssinatura);
    }

    /** Também usado pela conciliação, para os avisos que ficaram pendentes. */
    public function processar(GatewayDeAssinatura $gateway, string $idDoEvento, ?string $idDaAssinatura): string
    {
        if ($idDaAssinatura !== null) {
            try {
                $this->atualizacao->reconsultar($gateway, $idDaAssinatura);
            } catch (FalhaNoGateway $falha) {
                $this->logger->warning('Contribua: reconsulta do aviso falhou, fica para a conciliação', ['gateway' => $gateway->codigo(), 'evento' => $idDoEvento, 'erro' => $falha->getMessage()]);

                return self::PENDENTE;
            }
        }
        $this->notificacoes->marcarProcessada($gateway->codigo(), $idDoEvento);

        return self::PROCESSADO;
    }
}
