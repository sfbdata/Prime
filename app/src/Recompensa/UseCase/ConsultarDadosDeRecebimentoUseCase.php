<?php

declare(strict_types=1);

namespace App\Recompensa\UseCase;

use App\Recompensa\Entity\DadosDeRecebimentoDeRecompensa;
use App\Recompensa\Repository\DadosDeRecebimentoDeRecompensaRepository;

/**
 * Os dados de recebimento de um indicador COM o documento completo, só para preencher o diálogo "Dados para pagamento"
 * do Master (a lista mostra o documento mascarado). Quem dispara: o Master (ROLE_SUPER_ADMIN no controller). Nulo se
 * ainda não há cadastro.
 */
final class ConsultarDadosDeRecebimentoUseCase
{
    public function __construct(
        private readonly DadosDeRecebimentoDeRecompensaRepository $dados,
    ) {
    }

    public function executar(int $userId): ?DadosDeRecebimentoDeRecompensa
    {
        return $this->dados->buscarDoIndicador($userId);
    }
}
