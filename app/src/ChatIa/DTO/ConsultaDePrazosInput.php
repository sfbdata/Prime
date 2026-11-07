<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

use App\ChatIa\Enum\PeriodoDaConsulta;
use App\Tarefa\Enum\AbaMetas;

/**
 * O que a consulta de prazos e metas pede: o período e o papel da pessoa (as abas da tela Minhas Metas). Nunca leva pessoa nem
 * escritório: a consulta é sempre de quem está na sessão.
 *
 * {@see self::daEntrada()} lê a entrada da chamada de ferramenta, que é dado NÃO confiável (veio do modelo): chave fora da
 * lista, valor fora do `enum` ou tipo errado = nula (a consulta não roda).
 */
final readonly class ConsultaDePrazosInput
{
    private const CHAVES = ['periodo', 'papel'];

    public function __construct(
        public PeriodoDaConsulta $periodo,
        public AbaMetas $papel = AbaMetas::RESPONSAVEL,
    ) {
    }

    /** @param array<string, mixed> $entrada */
    public static function daEntrada(array $entrada): ?self
    {
        if (array_diff(array_keys($entrada), self::CHAVES) !== [] || !\is_string($entrada['periodo'] ?? null)) {
            return null;
        }
        $periodo = PeriodoDaConsulta::tryFrom($entrada['periodo']);
        $papel = \array_key_exists('papel', $entrada)
            ? (\is_string($entrada['papel']) ? AbaMetas::tryFrom($entrada['papel']) : null)
            : AbaMetas::RESPONSAVEL;

        return $periodo === null || $papel === null ? null : new self($periodo, $papel);
    }

    /** A mesma consulta pedida duas vezes na resposta roda uma vez só. */
    public function chave(): string
    {
        return $this->periodo->value . '/' . $this->papel->value;
    }
}
