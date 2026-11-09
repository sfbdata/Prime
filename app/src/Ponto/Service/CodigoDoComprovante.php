<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Ponto\Repository\ComprovanteDoPontoRepository;

/**
 * O código do comprovante, `BJP-XXXX-XXXX-XXXX` (desenho 16 L424 e L525): 12 caracteres do alfabeto do desenho, sem
 * os que se confundem (I, O, 0 e 1). No desenho cada caractere sai do hash calculado no navegador; aqui sai do
 * servidor (D9), sorteado com `random_int` (32 símbolos, sem viés): 60 bits que não se adivinham a partir da batida.
 */
final class CodigoDoComprovante
{
    public const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const FORMATO = '/^BJP(-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}){3}$/';
    private const TENTATIVAS = 5;

    public function __construct(
        private readonly ComprovanteDoPontoRepository $comprovantes,
    ) {
    }

    /** O que a pessoa digitou ou o QR trouxe, no formato gravado: sem espaços nas pontas e em maiúsculas. */
    public static function normalizar(string $digitado): string
    {
        return strtoupper(trim($digitado));
    }

    public static function temFormato(string $codigo): bool
    {
        return preg_match(self::FORMATO, $codigo) === 1;
    }

    /**
     * Um código que ainda não existe. A colisão em 60 bits é improvável; a conferência evita que o índice único
     * derrube a transação da batida.
     *
     * @throws \RuntimeException se cinco sorteios seguidos colidirem
     */
    public function novo(): string
    {
        for ($tentativa = 0; $tentativa < self::TENTATIVAS; ++$tentativa) {
            $codigo = self::sortear();
            if (!$this->comprovantes->existeCodigo($codigo)) {
                return $codigo;
            }
        }

        throw new \RuntimeException('Não foi possível sortear um código de comprovante livre.');
    }

    private static function sortear(): string
    {
        $grupos = [];
        for ($grupo = 0; $grupo < 3; ++$grupo) {
            $letras = '';
            for ($i = 0; $i < 4; ++$i) {
                $letras .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
            }
            $grupos[] = $letras;
        }

        return 'BJP-' . implode('-', $grupos);
    }
}
