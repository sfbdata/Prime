<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Voto\DTO\FormularioDaOcorrencia;
use App\Voto\Entity\OcorrenciaDaVotacao;
use App\Voto\Repository\OcorrenciaDaVotacaoRepository;
use App\Voto\Service\QualificacaoDoEleitor;
use App\Voto\Service\UnidadeDoLink;

/**
 * Dados da página "Registrar ocorrência" do eleitor (`/votar/{token}/ocorrencia`): a qualificação dele, as pautas que a
 * unidade vê e quantas ocorrências ainda pode registrar. Null quando o link não leva a nenhuma unidade (a página dá 404).
 */
final class MontarFormularioDaOcorrenciaUseCase
{
    public function __construct(
        private readonly UnidadeDoLink $link,
        private readonly OcorrenciaDaVotacaoRepository $ocorrencias,
    ) {
    }

    public function executar(string $token): ?FormularioDaOcorrencia
    {
        $unidade = $this->link->localizar($token);
        if (null === $unidade) {
            return null;
        }
        $assembleia = $unidade->getAssembleia();
        $pautas = [];
        foreach ($assembleia->getPautas() as $pauta) {
            if (!$pauta->retirada() && $pauta->aceitaBloco($unidade->getBloco())) {
                $pautas[(int) $pauta->getId()] = $pauta->getTitulo();
            }
        }
        $restantes = max(0, OcorrenciaDaVotacao::MAXIMO_POR_UNIDADE - $this->ocorrencias->contarDaUnidade($unidade));

        return new FormularioDaOcorrencia($assembleia->getTitulo(), QualificacaoDoEleitor::nome($unidade), QualificacaoDoEleitor::rotulo($unidade), $pautas, $restantes);
    }
}
