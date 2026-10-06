<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Support;

/**
 * Dá id a uma entidade em memória (teste de unidade, sem banco). O id é privado e gerado pelo
 * Doctrine; aqui ele precisa existir porque as chaves do contexto ('pub:{id}') e a mensagem da
 * fila carregam ids.
 */
final class DefineId
{
    public static function em(object $entidade, int $id): void
    {
        $classe = new \ReflectionClass($entidade);
        while (!$classe->hasProperty('id')) {
            $classe = $classe->getParentClass();
            if ($classe === false) {
                throw new \LogicException(sprintf('%s não tem propriedade id.', $entidade::class));
            }
        }

        $propriedade = $classe->getProperty('id');
        $propriedade->setValue($entidade, $id);
    }
}
