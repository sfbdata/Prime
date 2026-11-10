<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Entity\Tenant\Sede;

/**
 * Uma sede como a aba Configurações mostra (spec `docs/specs/ponto-desenho-0811.md`, E6): a coordenada sem os zeros
 * que a coluna decimal acrescenta (-9.66599900 aparece -9.665999), para a pessoa reconhecer o que digitou.
 */
final class SedeDoPontoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly string $latitude,
        public readonly string $longitude,
        public readonly int $raio,
    ) {
    }

    public static function daSede(Sede $sede): self
    {
        return new self(
            (int) $sede->getId(),
            (string) $sede->getNome(),
            self::semZerosAMais($sede->getLatitude()),
            self::semZerosAMais($sede->getLongitude()),
            (int) $sede->getRaioPermitido(),
        );
    }

    /** @return array{id: int, nome: string, latitude: string, longitude: string, raio: int} */
    public function paraJson(): array
    {
        return ['id' => $this->id, 'nome' => $this->nome, 'latitude' => $this->latitude, 'longitude' => $this->longitude, 'raio' => $this->raio];
    }

    private static function semZerosAMais(?string $coordenada): string
    {
        if ($coordenada === null || !str_contains($coordenada, '.')) {
            return (string) $coordenada;
        }

        return rtrim(rtrim($coordenada, '0'), '.');
    }
}
