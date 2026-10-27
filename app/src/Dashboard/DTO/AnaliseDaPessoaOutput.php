<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

/**
 * A análise por regras fixas do perfil da pessoa (dc 01 L1113-1160): leituras com etiqueta, matriz,
 * fatores, "O que isso pode causar?" e plano de ação, todos derivados de números reais do período. Apoio
 * ao gestor: nada aqui é nota, punição nem decisão automática sobre a pessoa (LGPD art. 20).
 *
 * `plano` é nulo quando nenhuma regra de atenção disparou; senão traz as chaves problema, causa, acao,
 * responsavel, revisao e sucesso.
 */
final readonly class AnaliseDaPessoaOutput
{
    /**
     * @param list<LeituraDaPessoaOutput> $leituras
     * @param list<MatrizDaPessoaOutput>  $matriz
     * @param list<string>                $fatores
     * @param list<string>                $impactos
     * @param array<string, string>|null  $plano
     */
    public function __construct(
        public array $leituras,
        public array $matriz,
        public array $fatores,
        public array $impactos,
        public ?array $plano,
    ) {
    }
}
