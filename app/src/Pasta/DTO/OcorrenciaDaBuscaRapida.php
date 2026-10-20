<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Uma linha da Busca rápida da pasta (Ctrl + K, desenho 02 EXPEDIENTES L405-421), como o banco a
 * devolve: o que casou (a própria pasta, um cliente dela ou um processo dela) e os dados da pasta
 * que o resultado mostra. A formatação para a tela fica com o UseCase.
 *
 * - `grupo`: GRUPO_PASTA, GRUPO_CLIENTE ou GRUPO_PROCESSO.
 * - `nome` e `documento`: do cliente que casou (grupo Cliente); `documento` cru, como gravado.
 * - `numeroProcesso` e `classeProcessual`: do processo que casou (grupo Processo).
 * - `restrito`: a pasta está OCULTA pelo cadeado para quem busca (spec pasta-cadeado D1). Ela só casa por
 *   número e cliente, e o banco já a devolve sem a ação (`nomeAcao` nulo).
 */
final class OcorrenciaDaBuscaRapida
{
    public const GRUPO_PASTA    = 'Pasta';
    public const GRUPO_CLIENTE  = 'Cliente';
    public const GRUPO_PROCESSO = 'Processo';

    public function __construct(
        public readonly int $pastaId,
        public readonly string $grupo,
        public readonly string $nup,
        public readonly bool $restrito,
        public readonly ?string $nomeAcao = null,
        public readonly ?string $nomeClienteDaPasta = null,
        public readonly ?string $nomeDoClientePrincipal = null,
        public readonly ?string $nome = null,
        public readonly ?string $documento = null,
        public readonly ?string $numeroProcesso = null,
        public readonly ?string $classeProcessual = null,
    ) {
    }
}
