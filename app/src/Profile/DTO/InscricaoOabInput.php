<?php

declare(strict_types=1);

namespace App\Profile\DTO;

use App\Auth\Enum\TipoInscricaoOab;

/**
 * Modal "Inscrição na OAB" do Meu Perfil (desenho 07, VS 18.10.26, `ofTipos`/`ofNum`/`ofUf`). `id` nulo cria;
 * com valor, edita uma inscrição da própria pessoa. As regras (formato, uma por UF, uma principal) ficam no
 * SalvarInscricaoOabUseCase, porque dependem das inscrições gravadas.
 */
final readonly class InscricaoOabInput
{
    public function __construct(
        public ?int $id,
        public string $numero,
        public string $uf,
        public TipoInscricaoOab $tipo,
    ) {
    }
}
