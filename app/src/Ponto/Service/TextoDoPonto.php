<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Um texto de Service do Ponto que a pessoa lê na batida, com a chave no catálogo `ponto_servidor` (spec
 * idioma-do-sistema §32, idioma-ponto-php PR B2): a chave, os parâmetros (pelo nome no catálogo) e o português de
 * sempre, já preenchido, para quem o usa hoje (testes, comparação, log). Quem leva o texto à tela (o controller) chama
 * {@see self::traduzir()}. Um parâmetro pode ser outro {@see self} (o rótulo do tipo da batida, "18 minutos"): ele é
 * traduzido antes e entra como texto.
 */
final readonly class TextoDoPonto
{
    /** @param array<string, scalar|self> $parametros o valor, pelo nome do parâmetro no catálogo ({tipo}) */
    public function __construct(
        public string $chave,
        public array $parametros,
        public string $portugues,
    ) {
    }

    /**
     * O texto de uma constante em português (`$texto`, com %s ou %d onde entra um valor) com a chave dela. Os valores
     * entram no `vsprintf` na ordem dos parâmetros, como o `sprintf` que havia antes.
     *
     * @param array<string, scalar|self> $parametros
     */
    public static function de(string $chave, string $texto, array $parametros = []): self
    {
        $valores = array_map(static fn (bool|float|int|string|self $valor): string => $valor instanceof self ? $valor->portugues : (string) $valor, array_values($parametros));

        return new self($chave, $parametros, $parametros === [] ? $texto : vsprintf($texto, $valores));
    }

    public function traduzir(TranslatorInterface $tradutor): string
    {
        $parametros = [];
        foreach ($this->parametros as $nome => $valor) {
            $parametros[$nome] = $valor instanceof self ? $valor->traduzir($tradutor) : $valor;
        }

        return $tradutor->trans($this->chave, $parametros);
    }
}
