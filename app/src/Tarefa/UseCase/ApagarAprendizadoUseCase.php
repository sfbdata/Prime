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

    /** @return int quantos registros saíram */
    public function executar(Tenant $escritorio, User $usuario): int
    {
        $apagados = 0;
        foreach ($this->despachos->doUsuario($escritorio, $usuario) as $registro) {
            $this->despachos->remover($registro);
            ++$apagados;
        }
        foreach ($this->pendencias->doUsuario($escritorio, $usuario) as $registro) {
            $this->pendencias->remover($registro);
            ++$apagados;
        }
        $this->despachos->confirmar();

        return $apagados;
    }
}
