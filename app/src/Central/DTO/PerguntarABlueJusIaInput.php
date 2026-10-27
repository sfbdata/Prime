<?php

declare(strict_types=1);

namespace App\Central\DTO;

use App\Inteligencia\Exception\PerguntaInvalidaException;

/**
 * A pergunta ao BlueJus IA na Central (F3): texto aparado, de 1 a 2000 caracteres (a mesma regra da aba Perguntar da
 * pasta). Fora disso, {@see PerguntaInvalidaException} (422); nada é gravado nem conta no limitador.
 *
 * `pastaId` (PR 4, contexto da tela): opcional; presente, é inteiro positivo. Só o id vem do cliente, nunca texto de
 * contexto: o servidor revalida a pasta (escritório, cadeado, segredo de justiça).
 */
final readonly class PerguntarABlueJusIaInput
{
    public const TAMANHO_MAXIMO = 2000;

    public string $texto;

    public ?int $pastaId;

    /**
     * @throws PerguntaInvalidaException
     */
    public function __construct(string $texto, ?int $pastaId = null)
    {
        if ($pastaId !== null && ($pastaId <= 0 || $pastaId > 2147483647)) {   // pasta.id é integer (int4)
            throw PerguntaInvalidaException::pastaInvalida();
        }

        $aparado = trim($texto);
        if ($aparado === '') {
            throw PerguntaInvalidaException::vazia();
        }
        if (mb_strlen($aparado) > self::TAMANHO_MAXIMO) {
            throw PerguntaInvalidaException::longaDemais(self::TAMANHO_MAXIMO);
        }

        $this->texto = $aparado;
        $this->pastaId = $pastaId;
    }
}
