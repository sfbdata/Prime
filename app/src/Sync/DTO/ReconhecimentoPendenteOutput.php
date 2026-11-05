<?php

declare(strict_types=1);

namespace App\Sync\DTO;

/** Uma dúvida do reconhecimento do Drive para a tela "Conectar meu Drive". */
final readonly class ReconhecimentoPendenteOutput
{
    /**
     * @param list<array{id: int, nup: string, cliente: string}> $candidatas pastas sugeridas (vazio: digitar o número)
     */
    public function __construct(
        public int $id,
        public string $nomeNoDrive,
        public array $candidatas,
    ) {
    }
}
