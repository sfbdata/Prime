<?php

namespace App\Service\Ponto;

use App\Entity\Tenant\Sede;

class GeofencingService
{
    /**
     * Calcula a distância entre dois pontos em metros usando a fórmula de Haversine.
     */
    public function calcularDistancia(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // Raio da Terra em metros

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Verifica se as coordenadas estão dentro do raio de uma sede.
     */
    public function estaNaSede(float $lat, float $lon, Sede $sede): bool
    {
        $distancia = $this->calcularDistancia($lat, $lon, (float)$sede->getLatitude(), (float)$sede->getLongitude());
        return $distancia <= $sede->getRaioPermitido();
    }

    /**
     * Encontra a sede mais próxima dentro do raio permitido.
     * @param Sede[] $sedes
     */
    public function encontrarSedeProxima(float $lat, float $lon, array $sedes): ?Sede
    {
        foreach ($sedes as $sede) {
            if ($this->estaNaSede($lat, $lon, $sede)) {
                return $sede;
            }
        }
        return null;
    }
}
