<?php

declare(strict_types=1);

namespace App\Expediente\Service;

use App\Cobranca\Service\ResolvedorIdentificadorDaPasta;
use App\Pasta\Entity\Pasta;

/**
 * "Exportar colunas visíveis (CSV)" da Lista do Expediente, desenho 1.1 (dc `colCsv`, E12).
 *
 * Mesmo formato do protótipo: separador `;`, todo valor entre aspas, BOM UTF-8 (o Excel em português abre
 * com acento certo), quebra CRLF. Os textos são os da tela: o cliente é o identificador da pasta, a
 * prioridade e a situação com os rótulos do desenho, os réus juntos por " / ". Metas não entra, como no
 * desenho. NUP sempre entra, primeiro.
 */
final class CsvDaListaDoExpediente
{
    public const COLUNAS = [
        'nup'     => 'NUP',
        'cliente' => 'Cliente',
        'reu'     => 'Réu',
        'acao'    => 'Ação',
        'prio'    => 'Prioridade',
        'resp'    => 'Responsável',
        'marc'    => 'Marcadores',
        'sit'     => 'Situação',
    ];

    private const PRIORIDADE = ['normal' => 'Normal', 'prioridade' => 'Alta', 'urgente' => 'Urgente'];

    private const SITUACAO = [
        Pasta::SITUACAO_ATIVA     => 'Ativo',
        Pasta::SITUACAO_SUSPENSA  => 'Suspenso',
        Pasta::SITUACAO_CANCELADA => 'Cancelado',
        Pasta::SITUACAO_ARQUIVADA => 'Arquivado',
    ];

    public function __construct(
        private readonly ResolvedorIdentificadorDaPasta $identificador,
    ) {
    }

    /**
     * @param Pasta[]                $pastas
     * @param string[]               $colunas chaves de COLUNAS; desconhecidas são ignoradas
     * @param array<int, string[]>   $reusPorPasta
     */
    public function gerar(array $pastas, array $colunas, array $reusPorPasta): string
    {
        $ks = array_values(array_filter(
            array_keys(self::COLUNAS),
            static fn (string $k): bool => $k === 'nup' || in_array($k, $colunas, true),
        ));

        $this->identificador->primeParaPastas($pastas);
        $linhas = [implode(';', array_map(static fn (string $k): string => self::COLUNAS[$k], $ks))];
        foreach ($pastas as $pasta) {
            $linhas[] = implode(';', array_map(
                fn (string $k): string => '"' . str_replace('"', '""', self::semFormula($this->valor($pasta, $k, $reusPorPasta))) . '"',
                $ks,
            ));
        }

        return "\u{FEFF}" . implode("\r\n", $linhas);
    }

    /**
     * Texto que começa com = + - @ (ou tabulação/retorno) vira fórmula no Excel: o nome da pasta é texto livre e
     * os réus vêm de importação. Um apóstrofo na frente faz a planilha mostrar o texto como texto.
     */
    private static function semFormula(string $valor): string
    {
        return $valor !== '' && str_contains("=+-@\t\r", $valor[0]) ? "'" . $valor : $valor;
    }

    /** @param array<int, string[]> $reusPorPasta */
    private function valor(Pasta $pasta, string $coluna, array $reusPorPasta): string
    {
        return match ($coluna) {
            'nup'     => (string) $pasta->getNup(),
            'cliente' => $this->cliente($pasta),
            'reu'     => implode(' / ', $reusPorPasta[(int) $pasta->getId()] ?? []),
            'acao'    => (string) ($pasta->getNomeAcao() ?: $pasta->getProcessoPrincipal()?->getClasseProcessual() ?: ''),
            'prio'    => self::PRIORIDADE[$pasta->getPrioridade()->value] ?? '',
            'resp'    => $pasta->getResponsavel()?->getFullName() ?? 'Sem responsável',
            'marc'    => implode(', ', array_map(static fn ($m): string => $m->getNome(), $pasta->getMarcadores()->toArray())),
            'sit'     => $pasta->estaExcluida() ? 'Excluída' : (self::SITUACAO[$pasta->getSituacao()] ?? $pasta->getSituacao()),
            default   => '',
        };
    }

    private function cliente(Pasta $pasta): string
    {
        $id = $this->identificador->para($pasta);
        if ($id !== null && $id !== '') {
            return $id;
        }

        return implode('; ', array_map(static fn ($c): string => (string) $c->getNomeExibicao(), $pasta->getClientes()->toArray()));
    }
}
