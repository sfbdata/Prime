<?php

declare(strict_types=1);

namespace App\Inteligencia\Contexto;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\ContagensDaPasta;
use App\Pasta\Entity\Pasta;

/**
 * Os totais brutos por trás da grade "O que a IA sabe desta pasta". Interface para o UseCase ser
 * testado por unidade; a implementação Doctrine filtra por pasta E tenant em toda consulta (mesma
 * regra da {@see FonteDeDadosDaPasta}). Vive aqui, e não nos repositórios da Pasta/Tarefa, porque
 * esta frente não edita arquivo de outro domínio.
 */
interface FonteDeContagensDaPasta
{
    public function contar(Tenant $tenant, Pasta $pasta): ContagensDaPasta;
}
