<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\ConfiguracaoDaPublicacao;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * Publica a assembleia: exige ao menos uma pauta com duas opções e uma unidade apta (regra da entidade) e trava as
 * pautas. Depois disso a mesa abre e encerra cada pauta.
 */
final class PublicarAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly SerializacaoDaConfiguracao $serializacao,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, ?\DateTimeImmutable $agora = null, ?string $conferencia = null): void
    {
        $this->serializacao->executar($tenant, $assembleiaId, function () use ($tenant, $assembleiaId, $agora, $conferencia): void {
            $this->executarComConfiguracaoReservada($tenant, $assembleiaId, $agora, $conferencia);
        });
    }

    private function executarComConfiguracaoReservada(Tenant $tenant, int $assembleiaId, ?\DateTimeImmutable $agora = null, ?string $conferencia = null): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        if ($conferencia !== null && !hash_equals(ConfiguracaoDaPublicacao::codigo($assembleia), $conferencia)) {
            throw new OperacaoInvalidaNaAssembleiaException('A configuração mudou ou ainda não foi conferida. Confira o resumo antes de publicar.');
        }
        $assembleia->publicar($agora);
        $this->assembleias->salvar($assembleia, flush: true);
    }
}
