<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class Cnpj extends Constraint
{
    public string $message = 'O CNPJ "{{ value }}" é inválido.';
}
