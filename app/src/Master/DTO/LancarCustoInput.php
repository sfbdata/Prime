<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;
use App\Master\Entity\LancamentoDeCusto;
use App\Master\Enum\CategoriaDeCusto;
use App\Master\Enum\OrigemDoCusto;
use App\Master\Exception\CustoRecusado;
use App\Master\Service\ValorEmReais;

/**
 * Lançar custo (POST /master/custos/lancamentos, JSON `{mes, categoria, valor, origem, fornecedor?, observacao?}`).
 * `valor` é o texto digitado em reais. `doJson()` recusa com a mensagem do desenho ("Informe o valor mensal.") ou com
 * a do campo que faltou.
 */
final readonly class LancarCustoInput
{
    public function __construct(
        public ?User $ator,
        public string $mes,
        public CategoriaDeCusto $categoria,
        public int $valorCentavos,
        public OrigemDoCusto $origem,
        public ?string $fornecedor = null,
        public ?string $observacao = null,
    ) {
    }

    /** @param array<mixed> $corpo */
    public static function doJson(?User $ator, array $corpo): self
    {
        $mes = is_string($corpo['mes'] ?? null) ? $corpo['mes'] : '';
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) !== 1) {
            throw new CustoRecusado('Escolha o mês.');
        }
        $categoria = CategoriaDeCusto::tryFrom(is_string($corpo['categoria'] ?? null) ? $corpo['categoria'] : '');
        if ($categoria === null) {
            throw new CustoRecusado('Escolha a categoria.');
        }
        $valor = ValorEmReais::paraCentavos($corpo['valor'] ?? null);
        if ($valor === null) {
            throw new CustoRecusado('Informe o valor mensal.');
        }
        $origem = OrigemDoCusto::tryFrom(is_string($corpo['origem'] ?? null) ? $corpo['origem'] : '');
        if ($origem === null) {
            throw new CustoRecusado('Escolha a origem do valor.');
        }

        return new self(
            $ator,
            $mes,
            $categoria,
            $valor,
            $origem,
            is_string($corpo['fornecedor'] ?? null) ? mb_substr(trim($corpo['fornecedor']), 0, LancamentoDeCusto::FORNECEDOR_MAXIMO) : null,
            is_string($corpo['observacao'] ?? null) ? mb_substr(trim($corpo['observacao']), 0, LancamentoDeCusto::OBSERVACAO_MAXIMA) : null,
        );
    }
}
