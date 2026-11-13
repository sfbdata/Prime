<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\DTO\AssembleiaNoPainel;
use App\Voto\DTO\PautaNoPainel;
use App\Voto\DTO\ResultadoDaPauta;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Monta a assembleia como o gestor a vê (spec voto-assembleia-condominio, seção 1): participação por pauta (quantas
 * unidades votaram e o peso delas) e, só na pauta ENCERRADA, o resultado pela ApuracaoDaPauta da base (a regra não é
 * reescrita aqui). Pauta aberta nunca leva parcial de votos.
 *
 * O contador da opção muda por SQL direto ({@see \App\Voto\Repository\OpcaoDaPautaRepository::somarVoto()}): antes
 * de apurar, as opções são relidas do banco para não apurar um contador velho que ficou na memória.
 */
final class LeituraDaAssembleia
{
    private const SEM_VOTO = ['unidades' => 0, 'peso' => '0.000000'];

    public function __construct(
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly ParticipacaoNaPautaRepository $participacoes,
        private readonly ApuracaoDaPauta $apuracao,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function montar(Assembleia $assembleia): AssembleiaNoPainel
    {
        $totais = $this->unidades->totaisAptos($assembleia);
        $contagem = $this->participacoes->contarPorPauta($assembleia);
        $pautas = [];
        foreach ($assembleia->getPautas() as $pauta) {
            $pautas[] = $this->pauta($pauta, $contagem[(int) $pauta->getId()] ?? self::SEM_VOTO, $totais);
        }

        return new AssembleiaNoPainel(
            id: (int) $assembleia->getId(),
            titulo: $assembleia->getTitulo(),
            peso: $assembleia->getPeso(),
            estado: $assembleia->getEstado(),
            criadaPorNome: $assembleia->getCriadaPorNome(),
            criadaEm: $assembleia->getCriadaEm(),
            publicadaEm: $assembleia->getPublicadaEm(),
            podeAlterarPautas: $assembleia->podeAlterarPautas(),
            podeAlterarUnidades: $assembleia->podeAlterarUnidades(),
            unidadesAptas: $totais['unidadesAptas'],
            unidadesInaptas: max(0, $assembleia->getUnidades()->count() - $totais['unidadesAptas']),
            pesoApto: $totais['pesoApto'],
            pautas: $pautas,
        );
    }

    /**
     * @param array{unidades: int, peso: string}           $contagem
     * @param array{pesoApto: string, unidadesAptas: int} $totais
     */
    private function pauta(PautaDaAssembleia $pauta, array $contagem, array $totais): PautaNoPainel
    {
        return new PautaNoPainel(
            id: (int) $pauta->getId(),
            ordem: $pauta->getOrdem(),
            titulo: $pauta->getTitulo(),
            descricao: $pauta->getDescricao(),
            estado: $pauta->getEstado(),
            assembleiaPublicada: EstadoDaAssembleia::Publicada === $pauta->getAssembleia()->getEstado(),
            convocacao: $pauta->getConvocacao(),
            quorumExigido: $pauta->getQuorumExigido(),
            abertaEm: $pauta->getAbertaEm(),
            abertaPorNome: $pauta->getAbertaPorNome(),
            prazoAte: $pauta->getPrazoAte(),
            encerradaEm: $pauta->getEncerradaEm(),
            encerradaPorNome: $pauta->getEncerradaPorNome(),
            motivoEncerramento: $pauta->getMotivoEncerramento(),
            opcoes: array_map(static fn (OpcaoDaPauta $o): array => ['numero' => $o->getNumero(), 'rotulo' => $o->getRotulo()], $pauta->getOpcoesDeVoto()),
            votantes: $contagem['unidades'],
            pesoVotante: $contagem['peso'],
            unidadesAptas: $totais['unidadesAptas'],
            pesoApto: $totais['pesoApto'],
            resultado: $this->resultado($pauta, $totais, $contagem),
            criterio: $pauta->getCriterio(),
            base: $pauta->getBase(),
            digitos: $pauta->getDigitos(),
            vagas: $pauta->getVagas(),
            quorumPrimeira: $pauta->getQuorumPrimeira(),
            quorumSegunda: $pauta->getQuorumSegunda(),
        );
    }

    /**
     * @param array{pesoApto: string, unidadesAptas: int} $totais
     * @param array{unidades: int, peso: string}          $contagem quem participou da pauta (cada unidade uma vez)
     */
    private function resultado(PautaDaAssembleia $pauta, array $totais, array $contagem): ?ResultadoDaPauta
    {
        if (EstadoDaPauta::Encerrada !== $pauta->getEstado()) {
            return null;
        }
        foreach ($pauta->getOpcoes() as $opcao) {
            $this->em->refresh($opcao);
        }

        return $this->apuracao->apurar($pauta, $totais['pesoApto'], $totais['unidadesAptas'], $contagem);
    }
}
