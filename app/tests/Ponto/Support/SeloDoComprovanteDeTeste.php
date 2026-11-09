<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Support;

use App\Ponto\Service\SeloDoComprovante;

/**
 * Selo disponível, para os testes: HMAC-SHA256 com uma chave fixa de teste por versão. A fase B do E15 troca o selo
 * de produção pelo que usa as chaves derivadas da E9; os testes da fase A só precisam de um selo que sela e confere.
 */
final class SeloDoComprovanteDeTeste implements SeloDoComprovante
{
    public const VERSAO = 3;

    public function disponivel(): bool
    {
        return true;
    }

    public function selar(string $dadosCanonicos): array
    {
        return ['hmac' => self::hmac($dadosCanonicos, self::VERSAO), 'versao' => self::VERSAO];
    }

    public function conferir(string $dadosCanonicos, string $hmac, int $versao): bool
    {
        return hash_equals(self::hmac($dadosCanonicos, $versao), $hmac);
    }

    private static function hmac(string $dados, int $versao): string
    {
        return hash_hmac('sha256', $dados, 'chave-de-teste-do-comprovante-v' . $versao);
    }
}
