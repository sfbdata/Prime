<?php

declare(strict_types=1);

namespace App\Recompensa\UseCase;

use App\Recompensa\Armazenamento\ChavesDeRecompensa;
use App\Recompensa\Exception\IndicadorNaoEncontrado;
use App\Recompensa\Repository\LancamentoDeRecompensaRepository;
use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * O comprovante de um pagamento de recompensa para o Master baixar (extrato › ícone do comprovante; spec F4). Quem
 * dispara: o Master (o controller exige ROLE_SUPER_ADMIN, antes de montar a chave). Lançamento sem comprovante ou que não
 * existe vira {@see IndicadorNaoEncontrado} (404).
 */
final class ObterComprovanteDeRecompensaUseCase
{
    public function __construct(
        private readonly LancamentoDeRecompensaRepository $lancamentos,
    ) {
    }

    /**
     * @return array{chave: ChaveDeArquivo, nome: string}
     *
     * @throws IndicadorNaoEncontrado
     */
    public function executar(int $lancamentoId): array
    {
        $lancamento = $this->lancamentos->find($lancamentoId);
        if ($lancamento === null || !$lancamento->temComprovante()) {
            throw new IndicadorNaoEncontrado('Comprovante não encontrado.');
        }

        return ['chave' => ChavesDeRecompensa::comprovante($lancamento), 'nome' => (string) $lancamento->getComprovanteNome()];
    }
}
