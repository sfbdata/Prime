<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;

/**
 * "NOTA INTERNA (SÓ MASTER E EQUIPE)" (l.311), gravada pela tela ~800 ms depois da última tecla e
 * ao sair do campo (decisão 8). Escrever exige `talentos`; o dono do currículo nunca a lê.
 */
final class AnotarCurriculoUseCase
{
    public function __construct(
        private readonly LocalizadorDeCurriculo $localizador,
        private readonly PoliticaDoCurriculo $politica,
        private readonly CurriculoRepository $repositorio,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, string $nota): void
    {
        $cv = $this->localizador->localizar($ctx, $codigo);
        if (!$this->politica->podeGerir($ctx)) {
            throw new AcaoNaoPermitida('Só a Central com acesso ao banco de talentos escreve a nota interna.');
        }

        $cv->anotar($nota);
        $this->repositorio->salvar($cv, flush: true);
    }
}
