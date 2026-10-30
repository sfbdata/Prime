<?php

declare(strict_types=1);

namespace App\Calculo\DTO;

/**
 * Resposta da página pública de verificação. Só carrega o que o PDF já mostra (escritório, emissão, data final e
 * total). `confere` é null quando nenhum arquivo foi apresentado.
 */
final readonly class ResultadoDaVerificacao
{
    public const FORMATO_INVALIDO = 'formato_invalido';
    public const NAO_ENCONTRADO = 'nao_encontrado';
    public const ENCONTRADO = 'encontrado';

    public function __construct(
        public string $estado,
        public string $codigo,
        public ?string $escritorio = null,
        public ?\DateTimeImmutable $emitidoEm = null,
        public ?\DateTimeImmutable $atualizadoAte = null,
        public ?string $total = null,
        public ?bool $confere = null,
    ) {
    }
}
