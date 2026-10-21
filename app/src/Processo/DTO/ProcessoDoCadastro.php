<?php

declare(strict_types=1);

namespace App\Processo\DTO;

use App\Processo\Entity\Processo;

/**
 * Resultado do cadastro pela janela da pasta: o processo a vincular e se ele já existia no escritório.
 * Carrega a entidade porque o próximo passo é o vínculo (`VincularProcessoUseCase`), na mesma transação;
 * nunca vai para o template.
 */
final readonly class ProcessoDoCadastro
{
    public function __construct(
        public Processo $processo,
        public bool $jaExistia,
    ) {
    }
}
