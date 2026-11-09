<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * O selo de produção desta fase (E15, fase A): ainda não há segredo do HMAC, então nada é selado e nada confere.
 * A fase B troca o alias por uma implementação que usa as chaves derivadas da E9 (`ChavesDoHmacDoPonto`, finalidade
 * `ponto-comprovante`, `hash_hmac('sha256', ...)`).
 */
#[AsAlias(SeloDoComprovante::class)]
final class SeloDoComprovanteIndisponivel implements SeloDoComprovante
{
    public function disponivel(): bool
    {
        return false;
    }

    public function selar(string $dadosCanonicos): array
    {
        throw new \LogicException('O selo do comprovante do ponto não está disponível: falta o segredo do HMAC.');
    }

    public function conferir(string $dadosCanonicos, string $hmac, int $versao): bool
    {
        return false;
    }
}
