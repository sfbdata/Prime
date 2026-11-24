<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Master\Enum\AbrangenciaDoConvenio;
use App\Master\Enum\BeneficioDoConvenio;

/**
 * O que o Master preenche ao criar ou editar um convênio (spec docs/specs/convenios.md, §7.3). `chave` e `prova` não
 * estão aqui: não mudam depois de criado. As regras são da entidade {@see \App\Master\Entity\Convenio}.
 */
final class DadosDoConvenio
{
    public function __construct(
        public readonly string $nome,
        public readonly string $instituicao,
        public readonly string $instituicaoChave,
        public readonly AbrangenciaDoConvenio $abrangencia,
        public readonly ?string $uf,
        public readonly BeneficioDoConvenio $beneficio,
        public readonly int $beneficioValor,
        public readonly string $textoSelo,
        public readonly \DateTimeImmutable $validoDe,
        public readonly ?\DateTimeImmutable $validoAte,
        public readonly ?int $limiteDeUsos,
    ) {
    }
}
