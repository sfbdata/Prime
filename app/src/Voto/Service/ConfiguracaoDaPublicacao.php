<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;

/** Identifica a configuração conferida. Mudança em pauta, regra ou lista exige outra revisão. */
final class ConfiguracaoDaPublicacao
{
    public static function codigo(Assembleia $assembleia): string
    {
        $dados = [
            'id' => $assembleia->getId(),
            'titulo' => $assembleia->getTitulo(),
            'peso' => $assembleia->getPeso()->value,
            'pautas' => array_map(self::pauta(...), $assembleia->getPautas()->toArray()),
            'unidades' => array_map(self::unidade(...), $assembleia->getUnidades()->toArray()),
        ];

        return hash('sha256', json_encode($dados, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private static function pauta(PautaDaAssembleia $pauta): array
    {
        return [
            'id' => $pauta->getId(),
            'ordem' => $pauta->getOrdem(),
            'titulo' => $pauta->getTitulo(),
            'descricao' => $pauta->getDescricao(),
            'criterio' => $pauta->getCriterio()->value,
            'base' => $pauta->getBase()->value,
            'vagas' => $pauta->getVagas(),
            'digitos' => $pauta->getDigitos(),
            'quorumPrimeira' => $pauta->getQuorumPrimeira(),
            'quorumSegunda' => $pauta->getQuorumSegunda(),
            'opcoes' => array_map(static fn (OpcaoDaPauta $opcao): array => [
                'numero' => $opcao->getNumero(),
                'rotulo' => $opcao->getRotulo(),
            ], $pauta->getOpcoesDeVoto()),
        ];
    }

    /** @return array<string, mixed> */
    private static function unidade(UnidadeDaAssembleia $unidade): array
    {
        return [
            'id' => $unidade->getId(),
            'bloco' => $unidade->getBloco(),
            'identificacao' => $unidade->getIdentificacao(),
            'apta' => $unidade->isApta(),
            'fracao' => $unidade->getFracao(),
        ];
    }
}
