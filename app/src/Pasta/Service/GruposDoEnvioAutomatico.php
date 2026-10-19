<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Cliente\Entity\Cliente;
use App\Cliente\Repository\EnvioAutomaticoDoClienteRepository;
use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\ChavesDoEnvioAutomatico;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;

/**
 * A entrada comum do envio automático ao cliente (spec push-envio-automatico §3): as publicações
 * vinculadas a processos do escritório viram um grupo por PASTA VIVA (o mesmo agrupamento do aviso do
 * P4b), cada uma com o cliente principal dela (do escritório; pasta sem cliente fica de fora) e as
 * chaves do envio automático desse cliente (sem linha, os padrões: tudo desligado). Tenant explícito
 * em tudo: roda no CLI.
 */
final class GruposDoEnvioAutomatico
{
    public function __construct(
        private readonly PastaRepository $pastas,
        private readonly EmailsDoClienteDaPasta $emails,
        private readonly EnvioAutomaticoDoClienteRepository $automaticos,
    ) {
    }

    /**
     * @param list<PublicacaoDjen> $publicacoes vinculadas a processo do escritório
     *
     * @return list<array{pasta: Pasta, cliente: Cliente, chaves: ChavesDoEnvioAutomatico, publicacoes: non-empty-list<PublicacaoDjen>}>
     */
    public function agrupar(Tenant $tenant, array $publicacoes): array
    {
        if ($publicacoes === []) {
            return [];
        }

        $processoIds = array_map(static fn (PublicacaoDjen $p): int => (int) $p->getProcesso()?->getId(), $publicacoes);
        $grupos = [];
        foreach (AgrupadorDoAvisoDoPush::agrupar($this->pastas->listarVinculosDePastasVivasDosProcessos($tenant, $processoIds), $publicacoes) as $grupo) {
            $cliente = $this->emails->cliente($grupo['pasta'], $tenant);
            if ($cliente !== null) {
                $grupos[] = ['pasta' => $grupo['pasta'], 'cliente' => $cliente, 'publicacoes' => $grupo['publicacoes']];
            }
        }
        $linhas = $this->automaticos->porClientesDoTenant($tenant, array_map(static fn (array $g): int => (int) $g['cliente']->getId(), $grupos));

        return array_map(static fn (array $g): array => $g + ['chaves' => ChavesDoEnvioAutomatico::da($linhas[(int) $g['cliente']->getId()] ?? null)], $grupos);
    }
}
