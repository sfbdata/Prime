<?php

declare(strict_types=1);

namespace App\Conector\Service;

/**
 * O e-mail de destinatário que sai pelo conector (`docs/specs/mcp-remoto-oauth.md`, 6.9, decisão 35): a letra inicial
 * do nome e do domínio, mais o final do domínio (`joao@gmail.com` vira `j***@g***.com`; `ana@silva.adv.br`,
 * `a***@s***.adv.br`). O domínio também é mascarado porque, num escritório, ele identifica o cliente; por isso não
 * serve a máscara da tela das automações (`ListarAutomacoesUseCase::mascarar`), que mostra o domínio inteiro.
 * Texto que não parece e-mail vira só `***`.
 */
final class MascaraDeEmailDoConector
{
    public const ESCONDIDO = '***';

    private function __construct()
    {
    }

    public static function mascarar(string $email): string
    {
        $email = trim($email);
        $arroba = strrpos($email, '@');
        if ($arroba === false || $arroba === 0) {
            return self::ESCONDIDO;
        }
        $nome = substr($email, 0, $arroba);
        $dominio = substr($email, $arroba + 1);
        $ponto = strpos($dominio, '.');
        if ($ponto === false || $ponto === 0 || $ponto === strlen($dominio) - 1) {
            return self::ESCONDIDO;
        }

        return mb_substr($nome, 0, 1) . self::ESCONDIDO . '@' . mb_substr($dominio, 0, 1) . self::ESCONDIDO . substr($dominio, $ponto);
    }
}
