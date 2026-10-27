<?php

declare(strict_types=1);

namespace App\Djen\DTO;

use Symfony\Component\HttpFoundation\Request;

final class RegistrarPublicacaoDoDiarioInput
{
    public function __construct(
        public readonly string $data,
        public readonly string $seccional,
        public readonly string $tipo,
        public readonly string $ato,
        public readonly string $numeroProcesso,
        public readonly string $orgao,
        public readonly string $prazoDias,
        public readonly string $texto,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $campo = static fn (string $nome): string => trim((string) $request->request->get($nome, ''));

        return new self($campo('data'), $campo('seccional'), $campo('tipo'), $campo('ato'), $campo('numero_processo'), $campo('orgao'), $campo('prazo_dias'), $campo('texto'));
    }
}
