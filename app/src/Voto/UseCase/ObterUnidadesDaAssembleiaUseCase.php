<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\OcorrenciaNaUnidade;
use App\Voto\DTO\UnidadeNaLista;
use App\Voto\DTO\UnidadesDaAssembleia;
use App\Voto\Entity\OcorrenciaDaUnidade;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\OcorrenciaDaUnidadeRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\LeituraDaAssembleia;
use App\Voto\Service\SomaDasFracoes;
use App\Voto\Service\TokenDaUnidade;

/**
 * A tela "Unidades e links": a lista por bloco e unidade, cada uma com o token do link recalculado da semente (para
 * "Copiar link" e "Copiar mensagem"), e o "faltam N" do envio por e-mail.
 */
final class ObterUnidadesDaAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly LeituraDaAssembleia $leitura,
        private readonly TokenDaUnidade $tokens,
        private readonly OcorrenciaDaUnidadeRepository $ocorrencias,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId): UnidadesDaAssembleia
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();

        $soma = PesoDoVoto::Fracao === $assembleia->getPeso() ? $this->unidades->somarFracoes($assembleia) : null;

        $ultimas = $this->ocorrencias->ultimaPorUnidade($assembleia);

        return new UnidadesDaAssembleia(
            assembleia: $this->leitura->montar($assembleia),
            unidades: array_map(fn (UnidadeDaAssembleia $u): UnidadeNaLista => $this->naLista($u, $ultimas[(int) $u->getId()] ?? null), $this->unidades->listarDaAssembleia($assembleia)),
            faltamEnvio: $this->unidades->contarParaEnvioDoLink($assembleia),
            somaDasFracoes: $soma,
            somaForaDoEsperado: null !== $soma && SomaDasFracoes::foraDoEsperado($soma),
            hashDaLista: $assembleia->getHashDaLista(),
            alteracoesDoConselho: $this->ocorrencias->contarAlteracoesDepoisDaTrava($assembleia),
            aceitaOcorrencia: !$assembleia->acabou(),
        );
    }

    private function naLista(UnidadeDaAssembleia $unidade, ?OcorrenciaDaUnidade $ocorrencia): UnidadeNaLista
    {
        return new UnidadeNaLista(
            id: (int) $unidade->getId(),
            identificacao: $unidade->getIdentificacao(),
            bloco: $unidade->getBloco(),
            situacao: $unidade->getSituacao(),
            apta: $unidade->isApta(),
            fracao: $unidade->getFracao(),
            responsavel: $unidade->getResponsavel(),
            email: $unidade->getEmail(),
            token: $this->tokens->token($unidade->getSemente()),
            linkEnviadoEm: $unidade->getLinkEnviadoEm(),
            ocorrencia: null === $ocorrencia ? null : new OcorrenciaNaUnidade(
                $ocorrencia->getTexto(),
                $ocorrencia->getRegistradaEm(),
                $ocorrencia->getRegistradaPorNome(),
                $ocorrencia->getAvaliacao(),
                $ocorrencia->getDecididaEm(),
                $ocorrencia->getDecididaPorNome(),
                $ocorrencia->getJustificativa(),
            ),
        );
    }
}
