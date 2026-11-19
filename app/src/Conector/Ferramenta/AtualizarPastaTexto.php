<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;

/**
 * O texto que a `atualizar_pasta` põe na resposta e no resumo (`docs/specs/mcp-remoto-oauth.md`, 6.2, regras 4 e 6):
 *  - valor do banco (número, identificador, nome de marcador, réu, colega): {@see protegido()}, em uma linha, pelo
 *    {@see ProtecaoDoTextoDoChat::rotulo()}, que neutraliza e mascara;
 *  - valor que o próprio assistente mandou e volta no resumo para a pessoa conferir: {@see umaLinha()}, sem máscara
 *    (a pessoa precisa ver exatamente o que vai ser gravado) e sem acrescentar nada que o assistente não mandou.
 */
final class AtualizarPastaTexto
{
    public function __construct(private readonly ProtecaoDoTextoDoChat $protecao)
    {
    }

    public function protegido(Tenant $escritorio, ?string $texto, string $padrao = 'sem texto'): string
    {
        $linha = self::umaLinha((string) $texto);

        return $linha === '' ? $padrao : $this->protecao->rotulo($escritorio, $linha, $padrao);
    }

    public function numero(Pasta $pasta, Tenant $escritorio): string
    {
        return $this->protegido($escritorio, $pasta->getNup(), 'sem número');
    }

    public static function umaLinha(string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /** Entre aspas, para o resumo. */
    public static function citado(string $texto): string
    {
        return '"' . $texto . '"';
    }
}
