<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * As contagens do relatório de pastas sem processo (desenho 1.2.3, `spResumo` L4565): sem processo
 * vinculado, deveriam ter, rever cadastro (rever + inconsistente), sem classificação
 * (classificar + sugAdmin) e administrativas coerentes. É o que vai no aviso e no histórico.
 */
final class ResumoSemProcesso
{
    public function __construct(
        public readonly int $sem,
        public readonly int $deveria,
        public readonly int $rever,
        public readonly int $classificar,
        public readonly int $admin,
    ) {
    }

    /**
     * @param list<AnaliseSemProcessoDaPasta> $analises
     */
    public static function deAnalises(array $analises): self
    {
        $conta = static fn (string ...$categorias): int => \count(array_filter(
            $analises,
            static fn (AnaliseSemProcessoDaPasta $a): bool => \in_array($a->categoria, $categorias, true),
        ));

        return new self(
            \count(array_filter($analises, static fn (AnaliseSemProcessoDaPasta $a): bool => $a->processoNumero === null)),
            $conta(AnaliseSemProcessoDaPasta::DEVERIA),
            $conta(AnaliseSemProcessoDaPasta::REVER, AnaliseSemProcessoDaPasta::INCONSISTENTE),
            $conta(AnaliseSemProcessoDaPasta::CLASSIFICAR, AnaliseSemProcessoDaPasta::SUGERE_ADMINISTRATIVA),
            $conta(AnaliseSemProcessoDaPasta::ADMINISTRATIVA),
        );
    }

    /** O valor de uma das colunas da Evolução, pelo nome. */
    public function valor(string $campo): int
    {
        return match ($campo) {
            'sem'         => $this->sem,
            'deveria'     => $this->deveria,
            'rever'       => $this->rever,
            'classificar' => $this->classificar,
            'admin'       => $this->admin,
            default       => throw new \InvalidArgumentException('Campo desconhecido: ' . $campo),
        };
    }
}
