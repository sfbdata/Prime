<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Tenant\Tenant;
use App\Repository\SedeRepository;

/**
 * As sedes que a TELA usa para dizer "você está fora da área" antes do envio: a do ponto e o atalho do cabeçalho
 * (spec `presenca-e-ponto-no-cabecalho.md` §3). Um lugar só, para as duas não divergirem.
 *
 * Isto NÃO é a regra: a regra continua em `PontoController::batida()`, no servidor, que decide. Espelha as duas
 * exclusões do servidor: sede sem coordenada e raio não positivo não delimitam nada e ficam de fora nos dois lados.
 */
final class SedesDaCercaDoPonto
{
    public function __construct(
        private readonly SedeRepository $sedes,
    ) {
    }

    /** @return list<array{nome: string, latitude: float, longitude: float, raio: int}> */
    public function doEscritorio(Tenant $tenant): array
    {
        $daTela = [];
        foreach ($this->sedes->findBy(['tenant' => $tenant]) as $sede) {
            if ($sede->getLatitude() === null || $sede->getLongitude() === null || (int) $sede->getRaioPermitido() <= 0) {
                continue;
            }
            $daTela[] = [
                'nome' => (string) $sede->getNome(),
                'latitude' => (float) $sede->getLatitude(),
                'longitude' => (float) $sede->getLongitude(),
                'raio' => (int) $sede->getRaioPermitido(),
            ];
        }

        return $daTela;
    }
}
