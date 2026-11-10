<?php

declare(strict_types=1);

namespace App\Ponto\Model;

use App\Ponto\Enum\TipoDoAjuste;

/**
 * O ajuste que deu à batida a forma que a folha mostra (a cabeça da linhagem): quem ajustou e quando, para a dica
 * "Ajustado por NOME em dd/mm/aaaa. Registro original preservado.", e a justificativa quando foi o abono de um
 * esquecimento.
 */
final readonly class AjusteDaMarcacao
{
    public function __construct(
        public TipoDoAjuste $tipo,
        public string $autorNome,
        public \DateTimeImmutable $em,
        public ?int $justificativaId,
    ) {
    }
}
