<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use Psr\Log\LoggerInterface;

/**
 * Avisa os usuários primários do escritório (sino e Central) do que aconteceu com a assinatura de espaço:
 * pagamento confirmado, cobrança recusada, cancelamento. Quem compra e cancela espaço é o primário (D4).
 *
 * Nunca lança, como o `AvisoAoMaster`: a assinatura já mudou quando o aviso sai. Chame DEPOIS do flush da
 * mudança, porque o `NotificacaoService` faz o próprio flush.
 */
final class AvisoAoPrimario
{
    public const TIPO_ASSINATURA = 'contribua.assinatura';

    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly NotificacaoService $notificacoes,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function avisar(Tenant $escritorio, string $mensagem, string $url): void
    {
        $mensagem = AvisoAoMaster::caber($mensagem);
        try {
            foreach ($this->vinculos->findUsuariosAdminsAtivos($escritorio) as $primario) {
                $this->notificacoes->criarNotificacao($primario, $escritorio, self::TIPO_ASSINATURA, $mensagem, $url);
            }
        } catch (\Throwable $falha) {
            $this->logger->error('Contribua: aviso ao primário não foi criado', ['tenant' => $escritorio->getId(), 'erro' => $falha->getMessage()]);
        }
    }
}
