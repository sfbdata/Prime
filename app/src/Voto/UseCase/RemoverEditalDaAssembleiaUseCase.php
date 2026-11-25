<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Voto\Armazenamento\ChavesDoVoto;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * Remove o edital da assembleia, só enquanto nenhum resultado saiu e a votação não está arquivada. Outro escritório é
 * tratado como inexistente. O arquivo só sai do armazenamento DEPOIS do COMMIT (INV-6).
 */
final class RemoverEditalDaAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly SerializacaoDaConfiguracao $serializacao,
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, ?\DateTimeImmutable $agora = null): void
    {
        $this->serializacao->executar($tenant, $assembleiaId, function () use ($tenant, $assembleiaId, $agora): void {
            $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
                ?? throw new AssembleiaNaoEncontradaException();
            if (!$assembleia->temEdital()) {
                throw new OperacaoInvalidaNaAssembleiaException('Esta votação não tem edital anexado.');
            }
            $chave = ChavesDoVoto::edital($assembleia);
            $assembleia->removerEdital($agora);
            $this->assembleias->salvar($assembleia, flush: true);
            $this->remocao->remover([$chave], 'RemoverEditalDaAssembleia: edital removido');
        });
    }
}
