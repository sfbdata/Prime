<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class CnpjValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Cnpj) {
            throw new UnexpectedTypeException($constraint, Cnpj::class);
        }

        if ($value === null || $value === '') {
            return;
        }

        $digits = preg_replace('/\D/', '', (string) $value);

        if (strlen($digits) !== 14 || preg_match('/^(\d)\1{13}$/', $digits)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
            return;
        }

        foreach ([12, 13] as $len) {
            $sum = 0;
            $pos = $len - 7;
            for ($i = 0; $i < $len; $i++) {
                $sum += (int) $digits[$i] * $pos--;
                if ($pos < 2) {
                    $pos = 9;
                }
            }
            $result = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
            if ((int) $digits[$len] !== $result) {
                $this->context->buildViolation($constraint->message)
                    ->setParameter('{{ value }}', $value)
                    ->addViolation();
                return;
            }
        }
    }
}
