<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\UnidadeNaLista;
use App\Voto\DTO\UnidadesDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\LeituraDaAssembleia;
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
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId): UnidadesDaAssembleia
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();

        return new UnidadesDaAssembleia(
            assembleia: $this->leitura->montar($assembleia),
            unidades: array_map($this->naLista(...), $this->unidades->listarDaAssembleia($assembleia)),
            faltamEnvio: $this->unidades->contarParaEnvioDoLink($assembleia),
        );
    }

    private function naLista(UnidadeDaAssembleia $unidade): UnidadeNaLista
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
        );
    }
}
