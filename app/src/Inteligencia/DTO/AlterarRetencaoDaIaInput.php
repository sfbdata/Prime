<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Entity\ConfiguracaoDaPlataformaDeIa;
use Symfony\Component\Validator\Constraints as Assert;

/** Por quantos meses as respostas e perguntas da IA ficam guardadas (1 a 120). A entidade recusa de novo. */
final class AlterarRetencaoDaIaInput
{
    public function __construct(
        #[Assert\Range(
            notInRangeMessage: 'Guarde as respostas da IA por 1 a 120 meses.',
            min: ConfiguracaoDaPlataformaDeIa::MESES_DE_RETENCAO_MINIMO,
            max: ConfiguracaoDaPlataformaDeIa::MESES_DE_RETENCAO_MAXIMO,
        )]
        public readonly int $meses,
    ) {
    }
}
