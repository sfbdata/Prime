<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O que a leitura por regras dos teores do Push da pasta devolveu: quantas publicações foram
 * lidas, as determinações achadas (da publicação mais recente para a mais antiga) e os documentos
 * do processo reconhecidos pelo TIPO da publicação ("Sentença", "Acórdão") — o "Processo: …" do
 * "Já existente" (DOC-82).
 *
 * `publicacoesLidas = 0` é "o processo não foi lido": a tela continua dizendo isso.
 */
final class LeituraDoJuizoOutput
{
    /**
     * @param list<DeterminacaoDoJuizoOutput>            $determinacoes
     * @param list<array{chave: string, rotulo: string}> $documentosDoProcesso
     */
    public function __construct(
        public readonly int $publicacoesLidas,
        public readonly array $determinacoes,
        public readonly array $documentosDoProcesso,
    ) {
    }

    public static function nada(): self
    {
        return new self(0, [], []);
    }
}
