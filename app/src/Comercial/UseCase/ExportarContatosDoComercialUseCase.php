<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\DTO\ContatoComercialOutput;

/**
 * O CSV da Base de contatos: as mesmas linhas da tela (com a busca atual), todas as páginas. Colunas
 * "E-mail;Nome;Origem;Situação;Último convite;Criado em", separador ";" e BOM para o Excel abrir os acentos.
 */
final class ExportarContatosDoComercialUseCase
{
    public const ARQUIVO = 'base-de-contatos-bluejus.csv';
    private const POR_LOTE = 1000;
    private const ORIGENS = ['importacao' => 'Importação', 'manual' => 'Manual'];
    private const SITUACOES = [ContatoComercialOutput::ATIVO => 'Ativo', ContatoComercialOutput::DESCADASTRADO => 'Descadastrado'];

    public function __construct(
        private readonly ListarContatosDoComercialUseCase $listar,
    ) {
    }

    public function executar(?string $busca): string
    {
        $linhas = [self::linha(['E-mail', 'Nome', 'Origem', 'Situação', 'Último convite', 'Criado em'])];
        $pagina = 1;
        $lidos = 0;
        do {
            $lista = $this->listar->executar($busca, $pagina, self::POR_LOTE);
            foreach ($lista['itens'] as $c) {
                $linhas[] = self::linha([
                    $c->email,
                    $c->nome ?? '',
                    self::ORIGENS[$c->origem] ?? $c->origem,
                    self::SITUACOES[$c->situacao] ?? $c->situacao,
                    $c->ultimoConviteEm?->format('d/m/Y H:i') ?? '',
                    $c->criadoEm->format('d/m/Y H:i'),
                ]);
            }
            $lidos += \count($lista['itens']);
            ++$pagina;
        } while ($lidos < $lista['total'] && $lista['itens'] !== []);

        return "\u{FEFF}" . implode("\n", $linhas);
    }

    /**
     * Campo que começa com = + - @ ganha um apóstrofo: nome e e-mail vêm de planilhas alheias, e a planilha não pode
     * executá-los como fórmula.
     *
     * @param list<string> $campos
     */
    private static function linha(array $campos): string
    {
        return implode(';', array_map(static function (string $c): string {
            $c = preg_match('/^[=+\-@]/', $c) === 1 ? "'" . $c : $c;

            return '"' . str_replace('"', '""', $c) . '"';
        }, $campos));
    }
}
