<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\CurriculoRepository;

/**
 * O currículo do próprio usuário externo, para as escritas dele (arquivos e exclusão). O interno e a
 * prévia do Master são recusados antes; sem currículo salvo, nada a fazer.
 */
final class MeuCurriculo
{
    public function __construct(
        private readonly PoliticaDoCurriculo $politica,
        private readonly CurriculoRepository $repositorio,
    ) {
    }

    public function paraEscrever(Contexto $ctx, string $semCurriculo): Curriculo
    {
        if (!$this->politica->podeEscreverOMeu($ctx)) {
            throw new AcaoNaoPermitida($ctx->previa
                ? 'Na pré-visualização não é possível alterar nada.'
                : '"Meu currículo" é de quem procura vaga: a Central não tem currículo próprio.');
        }

        return $this->repositorio->doUsuario($ctx->usuario) ?? throw new DadosInvalidos($semCurriculo);
    }
}
