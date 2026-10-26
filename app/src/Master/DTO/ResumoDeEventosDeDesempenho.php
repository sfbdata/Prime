<?php

declare(strict_types=1);

namespace App\Master\DTO;

/**
 * Contagens dos eventos de uma janela de tempo, o insumo de
 * {@see \App\Master\Service\ApuracaoDeDesempenho}. `p95Ms` e `cargasMedidas` vêm dos eventos `carga`.
 */
final readonly class ResumoDeEventosDeDesempenho
{
    public function __construct(
        public int $pings = 0,
        public int $pingsOk = 0,
        public int $cargasMedidas = 0,
        public ?int $p95Ms = null,
        public int $erros = 0,
        public int $logins = 0,
        public int $loginsFalhos = 0,
        public int $uploads = 0,
        public int $uploadsFalhos = 0,
    ) {
    }
}
