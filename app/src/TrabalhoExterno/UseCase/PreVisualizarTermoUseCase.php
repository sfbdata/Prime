<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDoTermo;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\HtmlDoTermoDeVoluntariado;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use App\TrabalhoExterno\Service\PoliticaDoTermo;

/**
 * A prévia do RASCUNHO (`tmDoc`, desenho 06 de 25/10, l.1255): o HTML dos campos ainda não enviados, para o iframe do
 * modal, o Word e a impressão do rascunho. Não grava nada. Quem: o interno que gere o banco.
 */
final class PreVisualizarTermoUseCase
{
    public function __construct(
        private readonly LocalizadorDeCurriculo $localizador,
        private readonly PoliticaDoTermo $politica,
        private readonly HtmlDoTermoDeVoluntariado $html,
    ) {
    }

    /** @param array<string, mixed> $dados */
    public function executar(Contexto $ctx, string $codigoDoCurriculo, array $dados): string
    {
        $cv = $this->localizador->localizar($ctx, $codigoDoCurriculo);
        if (!$this->politica->podeGerar($ctx, $cv)) {
            throw new AcaoNaoPermitida('Só a Central com acesso ao banco de talentos vê a prévia do termo.');
        }

        return $this->html->montar(DadosDoTermo::deArray($dados)->campos);
    }
}
