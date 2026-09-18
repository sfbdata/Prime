<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

/**
 * Um wrapper de stream que ocupa o lugar do `http://` e REGISTRA toda tentativa — sem rede.
 *
 * É assim que o SSRF do export é provado (E2.6C, D32): se alguma biblioteca resolver buscar a URL
 * escrita na peça, a tentativa aparece em {@see $pedidos} e o teste falha. Nada sai da máquina, e o
 * teste não depende de servidor nenhum.
 *
 * `instalar()` troca o wrapper embutido; `desinstalar()` o devolve — sempre no `tearDown`, senão o
 * resto da suíte fica sem `http://`.
 */
final class EspiaoDeHttp
{
    /** @var list<string> as URLs que alguém tentou abrir ou consultar */
    public static array $pedidos = [];

    /** O corpo devolvido a quem buscar: uma imagem de verdade, para o caminho "deu certo" existir. */
    public static string $corpo = '';

    private static bool $instalado = false;

    /** @var resource|null exigido pelo contrato de wrapper do PHP */
    public $context;

    private int $posicao = 0;

    public static function instalar(string $corpo): void
    {
        self::$pedidos = [];
        self::$corpo   = $corpo;

        if (self::$instalado) {
            return;
        }

        stream_wrapper_unregister('http');
        stream_wrapper_unregister('https');
        stream_wrapper_register('http', self::class);
        stream_wrapper_register('https', self::class);
        self::$instalado = true;
    }

    public static function desinstalar(): void
    {
        if (!self::$instalado) {
            return;
        }

        stream_wrapper_restore('http');
        stream_wrapper_restore('https');
        self::$instalado = false;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$pedidos[] = $path;
        $this->posicao   = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $pedaco = substr(self::$corpo, $this->posicao, $count);
        $this->posicao += \strlen($pedaco);

        return $pedaco;
    }

    public function stream_eof(): bool
    {
        return $this->posicao >= \strlen(self::$corpo);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => \strlen(self::$corpo)];
    }

    public function stream_close(): void
    {
    }

    /**
     * `is_file()`/`file_exists()` sobre a URL também são tentativa: registram e respondem "não é
     * arquivo", que é o que o wrapper real responderia.
     *
     * @return false
     */
    public function url_stat(string $path, int $flags): bool
    {
        self::$pedidos[] = $path;

        return false;
    }
}
