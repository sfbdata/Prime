<?php

declare(strict_types=1);

namespace App\Djen\Service;

use App\Entity\Tenant\Tenant;

/**
 * Contrato do notificador de novas publicações do DJEN. Existir como interface mantém o
 * SincronizarPublicacoesDjenUseCase desacoplado da implementação (e mockável nos testes por abstração).
 */
interface NotificadorPublicacoesDjenInterface
{
    /**
     * Notifica os destinatários do escritório sobre $quantidadeNovas publicações. Retorna quantos
     * foram notificados.
     */
    public function notificar(Tenant $tenant, int $quantidadeNovas): int;
}
