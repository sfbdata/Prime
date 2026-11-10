<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Tenant\Sede;

/**
 * A cerca da batida, no servidor (spec ponto-desenho-0811 §5 E12, P18 e a linha "Raio da sede validado no servidor com
 * a precisão do GPS" da tabela de riscos): onde a posição informada fica em relação às sedes do escritório.
 *
 * Só contam as sedes com coordenada e raio positivo, as mesmas que a tela recebe de {@see SedesDaCercaDoPonto}. A
 * situação sai da distância (Haversine) de cada uma até a posição e da precisão da leitura:
 *
 * - DENTRO: alguma sede com a distância até o raio, com qualquer precisão (como sempre foi: a regra nunca fica mais
 *   rígida que antes da E12), ou com a distância menos a precisão até o raio e a precisão até o teto. Vale a mais
 *   próxima entre as aceitas.
 * - INCERTA: nenhuma dentro, mas alguma com a distância menos a precisão até o raio e a precisão acima do teto. A
 *   leitura não basta para decidir, e a pessoa atualiza a localização.
 * - FORA: o resto, inclusive o escritório sem sede ou sem sede válida. Leva a sede válida mais próxima, se houver.
 *
 * Limite conhecido (F17, spec seguranca-b-ponto): a posição vem do navegador e pode ser falsa; o servidor não a confirma.
 */
final class CercaDoPonto
{
    /** Acima desta precisão (em metros), a leitura não conta a favor de quem está perto da borda do raio. */
    public const TETO_DA_PRECISAO_METROS = 50;

    private const RAIO_DA_TERRA_METROS = 6371000;

    /** @param iterable<Sede> $sedes as sedes do escritório da batida */
    public function avaliar(iterable $sedes, float $latitude, float $longitude, float $precisao): PosicaoNaCerca
    {
        $medidas = [];
        foreach ($sedes as $sede) {
            if ($sede->getLatitude() === null || $sede->getLongitude() === null || (int) $sede->getRaioPermitido() <= 0) {
                continue;
            }
            $medidas[] = [
                'sede'      => $sede,
                'distancia' => self::distanciaEmMetros($latitude, $longitude, (float) $sede->getLatitude(), (float) $sede->getLongitude()),
                'raio'      => (int) $sede->getRaioPermitido(),
            ];
        }

        return $this->classificar($medidas, $precisao);
    }

    /**
     * A regra, com as distâncias já medidas (só as sedes válidas).
     *
     * @param list<array{sede: Sede, distancia: float, raio: int}> $medidas
     */
    public function classificar(array $medidas, float $precisao): PosicaoNaCerca
    {
        $dentro = null;
        $incerta = null;
        $maisProxima = null;
        foreach ($medidas as $medida) {
            $maisProxima = self::aMaisProxima($maisProxima, $medida);
            $alcancaComAPrecisao = $medida['distancia'] - $precisao <= $medida['raio'];
            if ($medida['distancia'] <= $medida['raio'] || ($alcancaComAPrecisao && $precisao <= self::TETO_DA_PRECISAO_METROS)) {
                $dentro = self::aMaisProxima($dentro, $medida);
            } elseif ($alcancaComAPrecisao) {
                $incerta = self::aMaisProxima($incerta, $medida);
            }
        }

        if ($dentro !== null) {
            return PosicaoNaCerca::dentro($dentro['sede'], $dentro['distancia'], $dentro['raio'], $precisao);
        }
        if ($incerta !== null) {
            return PosicaoNaCerca::incerta($incerta['sede'], $incerta['distancia'], $incerta['raio'], $precisao);
        }

        return PosicaoNaCerca::fora($maisProxima['sede'] ?? null, $maisProxima['distancia'] ?? null, $maisProxima['raio'] ?? null, $precisao);
    }

    /** A distância em metros entre dois pontos (Haversine), a mesma conta que a batida sempre fez. */
    public static function distanciaEmMetros(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLon = deg2rad($lon2 - $lon1);

        $a = sin($deltaLat / 2) * sin($deltaLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($deltaLon / 2) * sin($deltaLon / 2);

        return self::RAIO_DA_TERRA_METROS * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @param array{sede: Sede, distancia: float, raio: int}|null $atual
     * @param array{sede: Sede, distancia: float, raio: int}      $candidata
     *
     * @return array{sede: Sede, distancia: float, raio: int}
     */
    private static function aMaisProxima(?array $atual, array $candidata): array
    {
        return $atual === null || $candidata['distancia'] < $atual['distancia'] ? $candidata : $atual;
    }
}
