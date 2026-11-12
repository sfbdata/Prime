<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * O voto da unidade numa pauta, vindo da cédula pública (`POST /votar/{token}/pauta/{pauta}`). O token identifica a
 * unidade; a pauta e as opções são ids e só valem se forem da assembleia da unidade. Pauta de uma vaga leva uma opção;
 * pauta de várias vagas aceita até esse tanto de nomes, ou só a abstenção.
 */
final readonly class RegistrarVotoInput
{
    public function __construct(
        public string $token,
        public int $pautaId,
        /** @var list<int> */
        public array $opcoesIds,
    ) {
    }

    /** O voto de uma opção só (pauta de vaga única, abstenção). */
    public static function deUma(string $token, int $pautaId, int $opcaoId): self
    {
        return new self($token, $pautaId, [$opcaoId]);
    }
}
