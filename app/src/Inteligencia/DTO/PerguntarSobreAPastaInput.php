<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Entity\PerguntaDaConversa;
use App\Inteligencia\Exception\PerguntaInvalidaException;

/**
 * Pedido da aba "Perguntar": a pasta e o texto, já aparado e conferido (1 a 2000 caracteres,
 * spec inteligencia-drawer-da-pasta, F3). Fora disso, {@see PerguntaInvalidaException} (422).
 */
final readonly class PerguntarSobreAPastaInput
{
    public string $texto;

    /**
     * @throws PerguntaInvalidaException
     */
    public function __construct(
        public int $pastaId,
        string $texto,
    ) {
        $this->texto = self::exigirValido($texto);
    }

    private static function exigirValido(string $texto): string
    {
        $aparado = trim($texto);
        if ($aparado === '') {
            throw PerguntaInvalidaException::vazia();
        }
        if (mb_strlen($aparado) > PerguntaDaConversa::TAMANHO_MAXIMO) {
            throw PerguntaInvalidaException::longaDemais(PerguntaDaConversa::TAMANHO_MAXIMO);
        }

        return $aparado;
    }
}
