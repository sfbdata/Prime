<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

/**
 * Perfil individual da pessoa no painel BlueJus Intelligence (dc 01 L1098-1190 e L2431-2480), por
 * regras fixas sobre os números reais do Painel. Nada aqui é estimado: o que o sistema não registra
 * (prazos cumpridos, classificação, causa provável) não entra, e a lista `limites` o declara.
 */
final readonly class PerfilDaPessoaOutput
{
    /**
     * @param list<MetricaDaPessoaOutput>    $metricas
     * @param list<ComparacaoDaPessoaOutput> $comparacoes
     * @param list<string>                   $motivos     frases "Observado" (fato lido dos números)
     * @param list<string>                   $dados       "Quais dados foram considerados?"
     * @param list<string>                   $limites     o que o painel não sabe dizer
     */
    public function __construct(
        public int $userId,
        public string $nome,
        public ?string $cargo,
        public ?string $fotoUrl,
        public array $metricas,
        public array $comparacoes,
        public array $motivos,
        public array $dados,
        public array $limites,
        public bool $semMetas,
        public ?string $status,
        public ?string $feedback,
        public ?AnaliseDaPessoaOutput $analise = null,
    ) {
    }

    public function primeiroNome(): string
    {
        return explode(' ', trim($this->nome))[0];
    }
}
