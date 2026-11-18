<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\DTO\PainelDeUnidadesDaPauta;
use App\Voto\DTO\UnidadeNoPainel;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\SemPermissaoParaVerAsUnidadesException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\AcessoAAssembleia;
use App\Voto\Service\IdentificacaoDaUnidade;

/**
 * O "Painel de unidades" de uma pauta (aba Acompanhar): todas as unidades que a pauta aceita, cada uma como votou,
 * não votou ou inapta. Mostra SÓ a identificação da unidade (a mesma regra da ata): nunca o nome do responsável, o voto
 * ou a hora, por isso não grava trilha de auditoria.
 *
 * Não encontrada (404): assembleia de outro escritório ou inexistente, rascunho, arquivada, pauta de outra assembleia
 * ou retirada, e cenário sem lista de votantes. Sem permissão (403): quem não pode ver a relação de votantes
 * ({@see AcessoAAssembleia::podeVerRelacao()}).
 */
final class MontarPainelDeUnidadesUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly ParticipacaoNaPautaRepository $participacoes,
        private readonly AcessoAAssembleia $acesso,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, int $pautaId, User $ator): PainelDeUnidadesDaPauta
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        if (EstadoDaAssembleia::Publicada !== $assembleia->getEstado() || $assembleia->arquivada()) {
            throw new AssembleiaNaoEncontradaException();
        }
        $pauta = null;
        foreach ($assembleia->getPautas() as $candidata) {
            if ($candidata->getId() === $pautaId) {
                $pauta = $candidata;
            }
        }
        if (null === $pauta || $pauta->retirada() || !$assembleia->getCenario()->temListaDeVotantes()) {
            throw new AssembleiaNaoEncontradaException();
        }
        if (!$this->acesso->podeVerRelacao($ator, $assembleia)) {
            throw new SemPermissaoParaVerAsUnidadesException();
        }

        $todas = $this->unidades->listarDaAssembleia($assembleia);
        $votaram = [];
        foreach ($this->participacoes->listarNominalDaPauta($pauta) as $participacao) {
            $votaram[(int) $participacao->getUnidade()->getId()] = true;
        }
        $repetidas = IdentificacaoDaUnidade::repetidas($todas);
        $daPauta = array_values(array_filter($todas, static fn (UnidadeDaAssembleia $u): bool => $pauta->aceitaBloco($u->getBloco())));
        usort($daPauta, static fn (UnidadeDaAssembleia $x, UnidadeDaAssembleia $y): int => strnatcasecmp($x->getBloco(), $y->getBloco()) ?: strnatcasecmp($x->getIdentificacao(), $y->getIdentificacao()));

        return new PainelDeUnidadesDaPauta(
            $pauta->getTitulo(),
            $assembleia->getTitulo(),
            self::situacaoDaPauta($pauta),
            $pauta->cancelada() ? 'cancelada' : ($pauta->suspensa() ? 'suspensa' : 'normal'),
            array_map(
                static fn (UnidadeDaAssembleia $u): UnidadeNoPainel => new UnidadeNoPainel(
                    IdentificacaoDaUnidade::exibir($u, isset($repetidas[$u->getIdentificacao()])),
                    isset($votaram[(int) $u->getId()]) ? UnidadeNoPainel::VOTOU : ($u->isApta() ? UnidadeNoPainel::NAO_VOTOU : UnidadeNoPainel::INAPTA),
                ),
                $daPauta,
            ),
        );
    }

    private static function situacaoDaPauta(PautaDaAssembleia $pauta): string
    {
        return match ($pauta->getEstado()) {
            EstadoDaPauta::Aguardando => 'publicada',
            EstadoDaPauta::Aberta => 'aberta',
            EstadoDaPauta::Encerrada => 'encerrada',
        };
    }
}
