<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\MeuCurriculo;
use App\TrabalhoExterno\Service\RemovedorDeCurriculo;

/** "Excluir meu currículo" (l.1007): o dono apaga o dele e os arquivos, a qualquer momento (LGPD). */
final class ExcluirMeuCurriculoUseCase
{
    public function __construct(
        private readonly MeuCurriculo $meu,
        private readonly RemovedorDeCurriculo $removedor,
    ) {
    }

    public function executar(Contexto $ctx): void
    {
        $this->removedor->remover($this->meu->paraEscrever($ctx, 'Você ainda não tem currículo.'), 'trabalho_externo_curriculo_excluido_pelo_dono');
    }
}
