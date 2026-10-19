<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Enum\StatusCurriculo;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;

/** "SITUAÇÃO NO BANCO DE TALENTOS" e "Marcar como …" do menu (l.1000, 1002): Master ou Equipe com `talentos`. */
final class MudarStatusDoCurriculoUseCase
{
    public function __construct(
        private readonly LocalizadorDeCurriculo $localizador,
        private readonly PoliticaDoCurriculo $politica,
        private readonly CurriculoRepository $repositorio,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, StatusCurriculo $status): Curriculo
    {
        $cv = $this->localizador->localizar($ctx, $codigo);
        if (!$this->politica->podeGerir($ctx)) {
            throw new AcaoNaoPermitida('Só a Central com acesso ao banco de talentos muda a situação do currículo.');
        }

        $cv->mudarStatus($status);
        $this->repositorio->salvar($cv, flush: true);

        return $cv;
    }
}
