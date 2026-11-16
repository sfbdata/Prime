<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * "Lembrar quem não votou": os aptos sem participação, em ordem de bloco e identificação, e o que a mensagem precisa
 * (título, link público geral e código de conferência). Nunca traz voto, opção ou quem votou em quem.
 */
final readonly class QuemNaoVotouOutput
{
    /** @param list<UnidadeSemVoto> $unidades */
    public function __construct(
        public int $assembleiaId,
        public string $titulo,
        public string $link,
        public string $codigoDeConferencia,
        public array $unidades,
    ) {
    }

    public function comTelefone(): int
    {
        return \count(array_filter($this->unidades, static fn (UnidadeSemVoto $u): bool => null !== $u->telefone && '' !== $u->telefone));
    }

    /** CSV do desenho: `nome;identificacao;grupo;telefone`, campos entre aspas, só de quem não votou. */
    public function csv(): string
    {
        $linhas = ['nome;identificacao;grupo;telefone'];
        foreach ($this->unidades as $u) {
            $linhas[] = implode(';', array_map(self::campo(...), [$u->responsavel, $u->identificacao, $u->bloco, $u->telefone]));
        }

        return implode("\n", $linhas) . "\n";
    }

    /** Aspas duplicadas; texto que começa com = + - @ ganha apóstrofo para a planilha não o tratar como fórmula. */
    private static function campo(?string $valor): string
    {
        $texto = (string) $valor;
        if ('' !== $texto && \in_array($texto[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $texto = "'" . $texto;
        }

        return '"' . str_replace('"', '""', $texto) . '"';
    }
}
