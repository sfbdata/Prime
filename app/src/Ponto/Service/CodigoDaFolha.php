<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Ponto\Repository\FolhaEmitidaDoPontoRepository;

/**
 * O código da folha emitida, `BJF-XXXX-XXXX-XXXX` (desenho 16 L721 a L727; spec ponto-desenho-0811, E16, decisão 2):
 * o mesmo alfabeto do comprovante ({@see CodigoDoComprovante::ALFABETO}, sem I, O, 0 e 1), sorteado no servidor com
 * `random_int` (32 símbolos, sem viés): 60 bits que não se adivinham a partir da folha. Cada emissão ganha um código
 * novo, que nunca repete um já gravado.
 */
final class CodigoDaFolha
{
    public const PREFIXO = 'BJF';
    public const FORMATO = '/^BJF(-[' . CodigoDoComprovante::ALFABETO . ']{4}){3}$/';
    private const TENTATIVAS = 5;

    public function __construct(
        private readonly FolhaEmitidaDoPontoRepository $folhas,
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
     * derrube a transação da emissão.
     *
     * @throws \RuntimeException se cinco sorteios seguidos colidirem
     */
    public function novo(): string
    {
        for ($tentativa = 0; $tentativa < self::TENTATIVAS; ++$tentativa) {
            $codigo = self::sortear();
            if (!$this->folhas->existeCodigo($codigo)) {
                return $codigo;
            }
        }

        throw new \RuntimeException('Não foi possível sortear um código de folha livre.');
    }

    private static function sortear(): string
    {
        $alfabeto = CodigoDoComprovante::ALFABETO;
        $grupos = [];
        for ($grupo = 0; $grupo < 3; ++$grupo) {
            $letras = '';
            for ($i = 0; $i < 4; ++$i) {
                $letras .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
            $grupos[] = $letras;
        }

        return self::PREFIXO . '-' . implode('-', $grupos);
    }
}
