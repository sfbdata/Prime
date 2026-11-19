<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** CPF do Desafio: validação e hash. O número em si nunca é guardado (D4). */
final class CpfDaCampanha
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $segredo,
    ) {
    }

    public function somenteDigitos(string $cpf): string
    {
        return preg_replace('/\D/', '', $cpf) ?? '';
    }

    /** 11 dígitos, dígitos verificadores corretos e não todos iguais. */
    public function valido(string $cpf): bool
    {
        $d = $this->somenteDigitos($cpf);
        if (\strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d) === 1) {
            return false;
        }
        for ($t = 9; $t < 11; ++$t) {
            $soma = 0;
            for ($i = 0; $i < $t; ++$i) {
                $soma += (int) $d[$i] * ($t + 1 - $i);
            }
            $digito = ((10 * $soma) % 11) % 10;
            if ((int) $d[$t] !== $digito) {
                return false;
            }
        }

        return true;
    }

    public function hash(string $cpf): string
    {
        return hash_hmac('sha256', $this->somenteDigitos($cpf), $this->segredo);
    }

    public function doisUltimos(string $cpf): string
    {
        return substr($this->somenteDigitos($cpf), -2);
    }
}
