<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\OrdemDasCandidaturas;
use App\Voto\Enum\SigiloDoVoto;

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
        $dados = [
            'id' => $pauta->getId(),
            'ordem' => $pauta->getOrdem(),
            'titulo' => $pauta->getTitulo(),
            'descricao' => $pauta->getDescricao(),
            'tipo' => $pauta->getTipo()->value,
            'criterio' => $pauta->getCriterio()->value,
            'base' => $pauta->getBase()->value,
            'vagas' => $pauta->getVagas(),
            'digitos' => $pauta->getDigitos(),
            'quorumPrimeira' => $pauta->getQuorumPrimeira(),
            'quorumSegunda' => $pauta->getQuorumSegunda(),
            'opcoes' => array_map(self::opcao(...), $pauta->getOpcoesDeVoto()),
        ];

        return $dados + self::regrasDeParticipacao($pauta);
    }

    /**
     * Horário programado, grupos, ordem das candidaturas e sigilo só entram quando saem do padrão: a pauta sem essas
     * regras mantém o código de antes.
     *
     * @return array<string, mixed>
     */
    private static function regrasDeParticipacao(PautaDaAssembleia $pauta): array
    {
        $dados = [];
        if ($pauta->programada()) {
            $dados['abreEm'] = $pauta->getAbreEm()?->format(\DATE_ATOM);
            $dados['encerraEm'] = $pauta->getEncerraEm()?->format(\DATE_ATOM);
        }
        if ([] !== $pauta->getGrupos()) {
            $dados['grupos'] = $pauta->getGrupos();
        }
        if (OrdemDasCandidaturas::Fixa !== $pauta->getOrdemDasCandidaturas()) {
            $dados['ordemDasCandidaturas'] = $pauta->getOrdemDasCandidaturas()->value;
        }
        if (SigiloDoVoto::Secreto !== $pauta->getSigilo()) {
            $dados['sigilo'] = $pauta->getSigilo()->value;
        }

        return $dados;
    }

    /**
     * Os dados da candidatura só entram quando preenchidos: opção sem eles mantém o resumo de antes (a foto, de outra
     * etapa, fica fora; o texto da urna é configuração e entra).
     *
     * @return array<string, mixed>
     */
    private static function opcao(OpcaoDaPauta $opcao): array
    {
        $dados = ['numero' => $opcao->getNumero(), 'rotulo' => $opcao->getRotulo()];
        foreach (['descricao' => $opcao->getDescricao(), 'propostas' => $opcao->getPropostas(), 'composicao' => $opcao->getComposicao(), 'unidadeCandidato' => $opcao->getUnidadeCandidato()] as $chave => $valor) {
            if (null !== $valor) {
                $dados[$chave] = $valor;
            }
        }

        return $dados;
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
