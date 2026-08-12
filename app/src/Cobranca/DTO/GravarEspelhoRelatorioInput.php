<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\TipoRelatorioContabil;
use App\Entity\Auth\User;

/**
 * Entrada de {@see \App\Cobranca\UseCase\GravarEspelhoRelatorioUseCase}.
 *
 * Sem `#[Assert\...]`: a entrada vem de comando de console, não de formulário. O que precisa ser
 * validado (o arquivo existe, o layout confere, a reconciliação fecha) é validado pelo leitor e pelo
 * próprio UseCase, com mensagem específica — validação genérica de DTO não diria o que houve.
 */
final readonly class GravarEspelhoRelatorioInput
{
    public function __construct(
        public Carteira $carteira,
        public string $caminhoArquivo,
        public TipoRelatorioContabil $tipo = TipoRelatorioContabil::Inadimplencia,
        public ?User $lidoPor = null,
    ) {
    }
}
