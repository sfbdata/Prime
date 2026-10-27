<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\Repository\ApoioRepository;

/**
 * Quem é "Apoiador BlueJus" (desenho 08, prêmio do apoiador: selo no perfil e nas propostas, preferência no
 * Trabalho Externo). Leitura pública para outros domínios (contrato com a a7, C6), por ids explícitos.
 *
 * Regra: apoio por PIX CONFIRMADO pelo banco (`Apoio::confirmadoEm`, C4) nos últimos 30 dias; o apoio do
 * desenho é mensal. A janela está com o dono para confirmar (spec, "Estado da frente").
 */
final class ApoiadoresDoBlueJus
{
    public const DIAS_DE_APOIADOR = 30;

    public function __construct(
        private readonly ApoioRepository $apoios,
    ) {
    }

    /**
     * @param list<int> $tenantIds
     *
     * @return list<int> os apoiadores entre eles, numa consulta só
     */
    public function apoiadores(array $tenantIds, \DateTimeImmutable $agora): array
    {
        return $this->apoios->comApoioConfirmadoDesde($tenantIds, $agora->modify('-' . self::DIAS_DE_APOIADOR . ' days'));
    }

    public function ehApoiador(int $tenantId, \DateTimeImmutable $agora): bool
    {
        return $this->apoiadores([$tenantId], $agora) !== [];
    }
}
