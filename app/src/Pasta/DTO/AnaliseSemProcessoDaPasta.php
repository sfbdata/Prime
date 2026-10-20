<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O resultado da análise de UMA pasta (desenho 1.2.3, objeto devolvido por `spAnalisar` L4536).
 *
 * Categorias (L4525-4530): `inconsistente` (tem processo e está marcada), `com` (tem processo),
 * `rever` (marcada, conteúdo judicial), `admin` (marcada, coerente), `deveria` (conteúdo indica
 * processo), `sugAdmin` (parece administrativa, falta marcar) e `classificar` (sem dados).
 */
final class AnaliseSemProcessoDaPasta
{
    public const INCONSISTENTE         = 'inconsistente';
    public const COM_PROCESSO          = 'com';
    public const REVER                 = 'rever';
    public const ADMINISTRATIVA        = 'admin';
    public const DEVERIA               = 'deveria';
    public const SUGERE_ADMINISTRATIVA = 'sugAdmin';
    public const CLASSIFICAR           = 'classificar';

    /**
     * @param list<array{0: string, 1: string}>                          $termos     [termo, fonte]
     * @param list<array{0: string, 1: string}>                          $adm        [termo, fonte]
     * @param list<array{titulo: string, responsavel: string, prazo: string}> $metas
     * @param array{decisao: string, por: string}|null                    $decisaoAnterior
     */
    public function __construct(
        public readonly int $pastaId,
        public readonly string $numero,
        public readonly string $cliente,
        public readonly string $responsavel,
        public readonly ?int $responsavelId,
        public readonly ?string $processoNumero,
        public readonly ?string $processoTribunal,
        public readonly bool $marcada,
        public readonly string $categoria,
        public readonly string $sugestao,
        public readonly string $confianca,
        public readonly array $termos,
        public readonly array $adm,
        public readonly ?string $cnj,
        public readonly int $totalFontes,
        public readonly ?array $decisaoAnterior,
        public readonly string $resumo,
        public readonly string $proximoPasso,
        public readonly array $metas,
    ) {
    }

    /** Rótulo e classe de cor do selo de confiança (desenho `conf`, L4540). */
    public function seloDeConfianca(): array
    {
        return match ($this->confianca) {
            'confirmado' => ['Confirmado', 'confirmado'],
            'provavel'   => ['Provável', 'provavel'],
            'hipotese'   => ['Hipótese', 'hipotese'],
            'declarado'  => ['Declarado', 'declarado'],
            'sem dados'  => ['Sem dados', 'sem-dados'],
            default      => [$this->confianca, 'declarado'],
        };
    }

    /** Pede ação de alguém (fora: com processo e administrativa coerente), como o envio do desenho (L4568). */
    public function pedeAcao(): bool
    {
        return $this->categoria !== self::COM_PROCESSO && $this->categoria !== self::ADMINISTRATIVA;
    }
}
