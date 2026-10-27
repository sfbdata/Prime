<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\DTO\Solicitante;

/**
 * O "PIX copia e cola" que a página mostra para a conta e o valor escolhido. Ponto de extensão: sem
 * integração bancária vale o estático (`CodigoPixDaContaEstatico`); a C4 (Cora) o decora com o QR
 * dinâmico por conta, caindo no estático quando não dá. A página e o `/contribua/pix` só conhecem esta
 * interface.
 */
interface CodigoPixDaConta
{
    /** @return array{codigo: string, valido: bool} */
    public function para(Solicitante $quem, int $valorCentavos): array;
}
