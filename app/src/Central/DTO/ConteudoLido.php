<?php

declare(strict_types=1);

namespace App\Central\DTO;

/**
 * O texto de uma mensagem como sai para UM leitor, decidido por `LeituraDasMensagens` (o ponto único de leitura do
 * despacho). Mensagem comum: o texto gravado, sem despacho. Despacho que o leitor vê: o texto e a referência.
 * Despacho que ele não vê: "Item de pasta sem acesso", sem referência, `oculto = true`.
 */
final class ConteudoLido
{
    private function __construct(
        public readonly string $conteudo,
        public readonly ?DespachoOutput $despacho,
        public readonly bool $oculto,
    ) {
    }

    public static function liberado(string $conteudo, ?DespachoOutput $despacho = null): self
    {
        return new self($conteudo, $despacho, false);
    }

    public static function oculto(string $aviso): self
    {
        return new self($aviso, null, true);
    }
}
