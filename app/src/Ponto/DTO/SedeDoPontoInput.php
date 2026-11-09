<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use Symfony\Component\HttpFoundation\Request;

/**
 * Uma linha da tabela "Sedes e raio de registro" da aba Configurações (spec `docs/specs/ponto-desenho-0811.md`, E6),
 * como chegou do navegador: texto cru. Quem normaliza (vírgula na coordenada, raio vazio) e valida é o
 * `SalvarSedeDoPontoUseCase`.
 */
final class SedeDoPontoInput
{
    public function __construct(
        public readonly string $nome,
        public readonly string $latitude,
        public readonly string $longitude,
        public readonly string $raio,
    ) {
    }

    public static function daRequisicao(Request $request): self
    {
        $campo = static fn (string $nome): string => trim((string) $request->request->get($nome, ''));

        return new self($campo('nome'), $campo('latitude'), $campo('longitude'), $campo('raio'));
    }
}
