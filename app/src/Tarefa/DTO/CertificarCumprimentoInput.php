<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/** Texto da certidão de cumprimento de um despacho (spec `minhas-metas-esteira-2210.md`, C15). */
final class CertificarCumprimentoInput
{
    public const INICIO = 'Certifico que';

    public function __construct(
        #[Assert\NotBlank(message: 'Escreva a certidão.')]
        #[Assert\Length(max: 1000, maxMessage: 'A certidão pode ter até 1.000 caracteres.')]
        #[Assert\Regex(pattern: '/^Certifico que\s+\S/u', message: 'A certidão começa por "Certifico que" e diz o que foi feito.')]
        public readonly string $texto,
    ) {
    }
}
