<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Exception\CaixaNaoConfiguravelException;
use App\Tarefa\Repository\CaixaDaEsteiraRepository;
use App\Tarefa\Service\QuemConfiguraACaixa;

/**
 * "Restaurar padrão" do modal "Configurar caixa" (desenho 04 de 22/10, dc L190): a caixa volta a "CAIXA N",
 * com o nome de etapa do catálogo e sem avisados. Mesma régua de quem pode do `ConfigurarCaixaDaEsteiraUseCase`
 * (D2). Caixa que já era a padrão: nada a fazer, sem erro (dois cliques não falham).
 */
final class RestaurarCaixaDaEsteiraUseCase
{
    public function __construct(
        private readonly CaixaDaEsteiraRepository $caixas,
        private readonly QuemConfiguraACaixa $quemConfigura,
    ) {
    }

    public function executar(User $usuario, Tenant $escritorio, EtapaDaPasta $etapa): void
    {
        if (!$this->quemConfigura->pode($usuario, $escritorio)) {
            throw CaixaNaoConfiguravelException::semPermissao();
        }

        $caixa = $this->caixas->daEtapa($escritorio, $etapa);
        if ($caixa !== null) {
            $this->caixas->remover($caixa, flush: true);
        }
    }
}
