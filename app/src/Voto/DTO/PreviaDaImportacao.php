<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * O que a importação faria, sem gravar: as linhas que entrariam, as inválidas (número da linha e motivo) e as
 * duplicadas. `texto` é o conteúdo já lido e limpo (só as válidas), que a confirmação reenvia: o arquivo enviado não é
 * guardado.
 */
final readonly class PreviaDaImportacao
{
    /**
     * @param list<LinhaDeUnidade>                    $validas
     * @param list<array{linha: int, motivo: string}> $invalidas
     * @param list<int>                               $duplicadas
     */
    public function __construct(
        public array $validas,
        public array $invalidas,
        public array $duplicadas,
        public string $texto,
    ) {
    }
}
