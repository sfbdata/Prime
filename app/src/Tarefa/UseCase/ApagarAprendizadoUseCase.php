<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Repository\AprendizadoDaPendenciaRepository;
use App\Tarefa\Repository\AprendizadoDoDespachoRepository;

/**
 * "Apagar o que foi aprendido" do Meu Perfil (D4 de Minhas Metas): remove tudo o que foi guardado da pessoa NAQUELE
 * escritório (despachos com o texto e linhas de pendência). Os outros escritórios dela e as outras pessoas ficam.
 */
final class ApagarAprendizadoUseCase
{
    public function __construct(
        private readonly AprendizadoDoDespachoRepository $despachos,
        private readonly AprendizadoDaPendenciaRepository $pendencias,
    ) {
    }

    /** @return int quantos registros saíram (DELETE em lote, filtrado por escritório e pessoa) */
    public function executar(Tenant $escritorio, User $usuario): int
    {
        return $this->despachos->apagarDoUsuario($escritorio, $usuario)
            + $this->pendencias->apagarDoUsuario($escritorio, $usuario);
    }
}
