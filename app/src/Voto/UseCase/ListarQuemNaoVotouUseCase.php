<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\QuemNaoVotouOutput;
use App\Voto\DTO\UnidadeSemVoto;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Service\EnderecoPublicoDaAssembleia;

/**
 * "Lembrar quem não votou" (desenho de 14 e 15/11, naoVotaram): os aptos que ainda não participaram das pautas em
 * votação. A lista sai da participação (a unidade votou ou não na pauta), nunca da opção escolhida: a participação
 * não guarda a opção e este UseCase nem a lê.
 *
 * Pautas consideradas: as abertas; se nenhuma está aberta, as que aguardam a mesa (ninguém votou ainda). Entra quem
 * está sem participação em ao menos uma delas. Pauta encerrada não conta. Outro escritório, rascunho ou assembleia
 * inexistente: AssembleiaNaoEncontradaException (404).
 */
final class ListarQuemNaoVotouUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly ParticipacaoNaPautaRepository $participacoes,
        private readonly EnderecoPublicoDaAssembleia $endereco,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId): QuemNaoVotouOutput
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        if (EstadoDaAssembleia::Publicada !== $assembleia->getEstado()) {
            throw new AssembleiaNaoEncontradaException();
        }

        $pautas = self::pautasEmVotacao($assembleia->getPautas()->toArray());
        $participou = [];
        foreach ($this->participacoes->paraConferencia($assembleia) as $linha) {
            $participou[$linha['pautaId']][$linha['unidadeId']] = true;
        }

        $faltam = [];
        foreach ($assembleia->getUnidades() as $unidade) {
            if ($unidade->isApta() && self::faltaEmAlguma($unidade, $pautas, $participou)) {
                $faltam[] = $unidade;
            }
        }
        usort($faltam, static fn (UnidadeDaAssembleia $a, UnidadeDaAssembleia $b): int => strnatcasecmp($a->getBloco() . ' ' . $a->getIdentificacao(), $b->getBloco() . ' ' . $b->getIdentificacao()));

        $link = $this->endereco->gerar($assembleia->getCodigoPublico());
        $codigo = $assembleia->getCodigoPublico();

        return new QuemNaoVotouOutput(
            (int) $assembleia->getId(),
            $assembleia->getTitulo(),
            $link,
            strtoupper(substr($codigo, 0, 4) . '-' . substr($codigo, -4)),
            array_map(static fn (UnidadeDaAssembleia $u): UnidadeSemVoto => new UnidadeSemVoto($u->getIdentificacao(), $u->getBloco(), $u->getResponsavel(), $u->getTelefone()), $faltam),
        );
    }

    /**
     * @param list<PautaDaAssembleia> $pautas
     *
     * @return list<PautaDaAssembleia>
     */
    private static function pautasEmVotacao(array $pautas): array
    {
        $abertas = array_values(array_filter($pautas, static fn (PautaDaAssembleia $p): bool => EstadoDaPauta::Aberta === $p->getEstado()));
        if ([] !== $abertas) {
            return $abertas;
        }

        return array_values(array_filter($pautas, static fn (PautaDaAssembleia $p): bool => EstadoDaPauta::Aguardando === $p->getEstado()));
    }

    /**
     * @param list<PautaDaAssembleia>        $pautas
     * @param array<int, array<int, true>>   $participou pauta => unidade => true
     */
    private static function faltaEmAlguma(UnidadeDaAssembleia $unidade, array $pautas, array $participou): bool
    {
        foreach ($pautas as $pauta) {
            if (!isset($participou[(int) $pauta->getId()][(int) $unidade->getId()])) {
                return true;
            }
        }

        return false;
    }
}
