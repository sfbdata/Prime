<?php

declare(strict_types=1);

namespace App\Conector\Service;

/**
 * Código de autorização, token de acesso e renovação do conector: 256 bits aleatórios em base64url sem `=` (43
 * caracteres), e o banco guarda só o SHA-256 em hexadecimal (`docs/specs/mcp-remoto-oauth.md`, 3.2 e 13.2). O valor
 * sai uma vez, na resposta ao app; depois, só o hash existe.
 */
final class SegredoOpaco
{
    private const BYTES = 32;

    /**
     * Um segredo novo e o hash dele. Grave o `hash`; entregue o `valor` ao app e esqueça-o.
     *
     * @return array{valor: string, hash: string}
     */
    public function gerar(): array
    {
        $valor = rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');

        return ['valor' => $valor, 'hash' => $this->hashDe($valor)];
    }

    /** O hash com que o banco guarda (e busca) o segredo que o app apresentou: SHA-256 em hexadecimal minúsculo. */
    public function hashDe(string $valor): string
    {
        return hash('sha256', $valor);
    }
}
