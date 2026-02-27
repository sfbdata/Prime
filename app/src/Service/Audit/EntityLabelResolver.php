<?php

namespace App\Service\Audit;

class EntityLabelResolver
{
    /**
     * @var string[]
     */
    private const CANDIDATE_METHODS = [
        'getNomeCompleto',
        'getRazaoSocial',
        'getNomeFantasia',
        'getNomeArquivo',
        'getNumeroProcesso',
        'getTitulo',
    ];

    public function resolve(object $entity): ?string
    {
        foreach (self::CANDIDATE_METHODS as $method) {
            if (!method_exists($entity, $method)) {
                continue;
            }

            $value = $entity->{$method}();
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
