<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/** Um alerta da Controladoria da pasta (desenho 02 de 31/10/2026): nível, título, o fato e a providência sugerida. */
final readonly class AlertaDaPasta
{
    public const ALTA = 'alta';
    public const MEDIA = 'media';
    public const BAIXA = 'baixa';

    public function __construct(
        public string $nivel,
        public string $titulo,
        public string $detalhe,
        public string $sugestao,
    ) {
    }

    /** @return array{nivel: string, titulo: string, detalhe: string, sugestao: string} */
    public function paraArray(): array
    {
        return ['nivel' => $this->nivel, 'titulo' => $this->titulo, 'detalhe' => $this->detalhe, 'sugestao' => $this->sugestao];
    }
}
