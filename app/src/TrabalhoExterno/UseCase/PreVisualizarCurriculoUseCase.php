<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDoCurriculo;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\DocumentoDoCurriculo;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;

/**
 * "Pré-visualizar" do formulário (spec VS 18.10.26 §4.5): o HTML do currículo com os dados ainda
 * não salvos, sem validar nem gravar nada. A foto é a já salva, se houver (a nova sobe pela rota de
 * arquivos). Só para quem tem "Meu currículo".
 */
final class PreVisualizarCurriculoUseCase
{
    public function __construct(
        private readonly PoliticaDoCurriculo $politica,
        private readonly CurriculoRepository $repositorio,
        private readonly DocumentoDoCurriculo $documento,
    ) {
    }

    public function executar(Contexto $ctx, DadosDoCurriculo $dados): string
    {
        if (!$this->politica->temMeuCurriculo($ctx)) {
            throw new AcaoNaoPermitida('"Meu currículo" é de quem procura vaga: a Central não tem currículo próprio.');
        }

        return $this->documento->html($this->documento->dadosDaPrevia($dados), $this->repositorio->doUsuario($ctx->usuario));
    }
}
