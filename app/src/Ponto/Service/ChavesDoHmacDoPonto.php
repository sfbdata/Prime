<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * As chaves HMAC do Ponto, derivadas do segredo `PONTO_HMAC_SECRET` (spec `ponto-desenho-0811.md`, E9), uma por
 * finalidade: `hash_hkdf('sha256', segredo, 32, finalidade)`. Hoje há uma versão de chave (1); a versão gravada junto
 * de cada selo é a que a conferência usa para recalculá-lo, então uma troca de chave futura não invalida o que já foi
 * selado.
 *
 * Sem o segredo (variável vazia ou ausente) nada quebra: `disponivel()` é falso, `versaoAtual()` e `chave()` devolvem
 * nulo e quem grava guarda a linha sem selo. Versão desconhecida também devolve nulo, nunca exceção.
 *
 * Usado pela trilha de auditoria (E9) e reaproveitado pelas etapas que selam outros registros do Ponto, cada uma com a
 * sua finalidade.
 */
final class ChavesDoHmacDoPonto
{
    /** A finalidade da chave do selo da trilha de auditoria. */
    public const FINALIDADE_TRILHA = 'ponto-trilha';

    private const VERSAO_ATUAL = 1;

    public function __construct(
        #[Autowire(env: 'default::PONTO_HMAC_SECRET')]
        private readonly ?string $segredo,
    ) {
    }

    /** Verdadeiro só com o segredo configurado (não vazio). */
    public function disponivel(): bool
    {
        return $this->segredo !== null && $this->segredo !== '';
    }

    /** A versão com que os selos novos são feitos; nula sem segredo. */
    public function versaoAtual(): ?int
    {
        return $this->disponivel() ? self::VERSAO_ATUAL : null;
    }

    /** A chave de 32 bytes da finalidade na versão pedida; nula sem segredo ou com versão desconhecida. */
    public function chave(string $finalidade, int $versao): ?string
    {
        if ($this->segredo === null || $this->segredo === '' || $versao !== self::VERSAO_ATUAL) {
            return null;
        }

        return hash_hkdf('sha256', $this->segredo, 32, $finalidade);
    }
}
