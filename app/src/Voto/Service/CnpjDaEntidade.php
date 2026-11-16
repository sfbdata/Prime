<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Validator\Cnpj;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * O CNPJ da entidade da assembleia (opcional): grava só os 14 dígitos e exibe com a máscara. Os dígitos verificadores
 * saem do validador do sistema ({@see Cnpj}), o mesmo do cadastro do escritório.
 */
final class CnpjDaEntidade
{
    public function __construct(private readonly ValidatorInterface $validador)
    {
    }

    /** Vazio vira null; com valor, tira a máscara e recusa o que não for um CNPJ válido. */
    public function normalizar(?string $informado): ?string
    {
        $informado = trim((string) $informado);
        if ('' === $informado) {
            return null;
        }
        $digitos = self::somenteDigitos($informado);
        if (14 !== \strlen($digitos) || 0 !== \count($this->validador->validate($digitos, new Cnpj()))) {
            throw new OperacaoInvalidaNaAssembleiaException('Informe um CNPJ válido, com 14 dígitos, ou deixe em branco.');
        }

        return $digitos;
    }

    public static function somenteDigitos(string $texto): string
    {
        return (string) preg_replace('/\D/', '', $texto);
    }

    /** "00.000.000/0000-00" para 14 dígitos; qualquer outra coisa volta como veio. */
    public static function formatar(?string $digitos): string
    {
        $digitos = (string) $digitos;
        if (1 !== preg_match('/^\d{14}$/', $digitos)) {
            return $digitos;
        }

        return substr($digitos, 0, 2) . '.' . substr($digitos, 2, 3) . '.' . substr($digitos, 5, 3) . '/' . substr($digitos, 8, 4) . '-' . substr($digitos, 12, 2);
    }
}
