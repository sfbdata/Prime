<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;
use App\TrabalhoExterno\Service\RemovedorDeCurriculo;

/** "Excluir currículo" do menu do banco (l.1000): o de outra pessoa, só o Master. */
final class ExcluirCurriculoUseCase
{
    public function __construct(
        private readonly LocalizadorDeCurriculo $localizador,
        private readonly PoliticaDoCurriculo $politica,
        private readonly RemovedorDeCurriculo $removedor,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo): void
    {
        $cv = $this->localizador->localizar($ctx, $codigo);
        if (!$this->politica->podeExcluir($ctx, $cv)) {
            throw new AcaoNaoPermitida('Só o Master exclui o currículo de outra pessoa.');
        }

        $this->removedor->remover($cv, 'trabalho_externo_curriculo_excluido');
    }
}
