<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use Doctrine\DBAL\Connection;

/**
 * O passo do Comercial no `app:purgar-dados-expirados` (spec comercial-e-emails.md §3.3 e §3.5): as confirmações de
 * endereço nunca confirmadas e vencidas há mais de 30 dias, e os disparos simulados ("Simular novo cadastro") com
 * mais de 7 dias. A retenção de 24 meses dos envios (P4) espera a decisão do dono.
 */
final class PurgarDadosDoComercialUseCase
{
    public const CARENCIA_DA_SIMULACAO = '-7 days';

    public function __construct(
        private readonly PurgarConfirmacoesVencidasUseCase $confirmacoes,
        private readonly Connection $conexao,
    ) {
    }

    public function executar(\DateTimeImmutable $agora, bool $dryRun): int
    {
        return $this->confirmacoes->executar($agora, $dryRun) + $this->simulacoes($agora, $dryRun);
    }

    private function simulacoes(\DateTimeImmutable $agora, bool $dryRun): int
    {
        $onde = "situacao = 'simulado' AND criado_em < :limite";
        $limite = ['limite' => $agora->modify(self::CARENCIA_DA_SIMULACAO)->format('Y-m-d H:i:s')];

        return $dryRun
            ? (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_envio WHERE ' . $onde, $limite)
            : (int) $this->conexao->executeStatement('DELETE FROM comercial_envio WHERE ' . $onde, $limite);
    }
}
