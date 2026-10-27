<?php

declare(strict_types=1);

namespace App\Central\DTO;

use App\Inteligencia\Exception\PerguntaInvalidaException;

/**
 * A pergunta ao BlueJus IA na Central (F3): texto aparado, de 1 a 2000 caracteres (a mesma regra da aba Perguntar da
 * pasta). Fora disso, {@see PerguntaInvalidaException} (422); nada é gravado nem conta no limitador.
 */
final readonly class PerguntarABlueJusIaInput
{
    public const TAMANHO_MAXIMO = 2000;

    public string $texto;

    /**
     * @throws PerguntaInvalidaException
     */
    public function __construct(string $texto)
    {
        $aparado = trim($texto);
        if ($aparado === '') {
            throw PerguntaInvalidaException::vazia();
        }
        if (mb_strlen($aparado) > self::TAMANHO_MAXIMO) {
            throw PerguntaInvalidaException::longaDemais(self::TAMANHO_MAXIMO);
        }

        $this->texto = $aparado;
    }
}
