<?php

declare(strict_types=1);

namespace App\Produto\DTO;

use App\Produto\Enum\Produto;

/**
 * A que parte do sistema uma rota pertence (spec multiproduto, §3.1).
 *
 * - `nucleo`: perfil, equipe, notificações; aberta para todo escritório.
 * - `publica`: link aberto sem conta (cédula, assinatura, vagas); a guarda do produto não age.
 * - `plataforma`: painel do Master.
 * - `produto`: abre só para escritório que inclui um dos `produtos`.
 *
 * `explicita` é falso só quando a rota não casou com nada e caiu no padrão (Expediente, que nasce fechado).
 * O teste de arquitetura exige que toda rota real seja explícita.
 */
final readonly class ClassificacaoDaRota
{
    public const NUCLEO = 'nucleo';
    public const PUBLICA = 'publica';
    public const PLATAFORMA = 'plataforma';
    public const PRODUTO = 'produto';

    /**
     * @param list<Produto> $produtos
     */
    private function __construct(
        public string $tipo,
        public array $produtos,
        public bool $explicita,
    ) {
    }

    public static function nucleo(): self
    {
        return new self(self::NUCLEO, [], true);
    }

    public static function publica(): self
    {
        return new self(self::PUBLICA, [], true);
    }

    public static function plataforma(): self
    {
        return new self(self::PLATAFORMA, [], true);
    }

    /**
     * @param list<Produto> $produtos
     */
    public static function produtos(array $produtos, bool $explicita = true): self
    {
        return new self(self::PRODUTO, $produtos, $explicita);
    }

    /** A guarda do produto age nesta rota? Só nas de produto. */
    public function exigeProduto(): bool
    {
        return self::PRODUTO === $this->tipo;
    }
}
