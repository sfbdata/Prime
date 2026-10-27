<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

/**
 * O extrato em CSV para o Master: BOM UTF-8 (o Excel abre os acentos), separador ";", toda célula entre aspas duplas
 * (aspas internas dobradas). Célula de TEXTO que começa com = + - @ (ou tab e CR) ganha um apóstrofo: nome e observação
 * são digitados por gente e a planilha não pode executá-los como fórmula. O valor é número gerado aqui, então sai
 * como está (o sinal de menos do estorno não é fórmula).
 */
final class ExtratoEmCsv
{
    public const ARQUIVO = 'extrato-recompensas-bluejus.csv';

    /**
     * @param list<LancamentoDoExtratoOutput> $linhas
     * @param list<string>                    $cabecalho Data, Usuário, E-mail, Tipo, Valor, Observação (e Forma, com `$rotulosDaForma`), já no idioma
     * @param array{credito: string, estorno: string, pago?: string} $rotulosDoTipo
     * @param array<string, string>|null      $rotulosDaForma forma (pix...) => rótulo; com ele a coluna Forma vai ao fim de cada linha
     */
    public static function gerar(array $linhas, array $cabecalho, array $rotulosDoTipo, ?array $rotulosDaForma = null): string
    {
        $saida = [self::linha($cabecalho, [])];
        foreach ($linhas as $l) {
            $celulas = [
                $l->em->format('d/m/Y H:i'),
                $l->nome ?? '',
                $l->email ?? '',
                self::rotuloDoTipo($l, $rotulosDoTipo),
                self::reais($l->valorCentavos),
                $l->observacao ?? '',
            ];
            if ($rotulosDaForma !== null) {
                $celulas[] = $l->forma === null ? '' : ($rotulosDaForma[$l->forma] ?? $l->forma);
            }
            $saida[] = self::linha($celulas, [4]);
        }

        return "\u{FEFF}" . implode("\n", $saida) . "\n";
    }

    /** @param array{credito: string, estorno: string, pago?: string} $rotulos */
    private static function rotuloDoTipo(LancamentoDoExtratoOutput $l, array $rotulos): string
    {
        return match (true) {
            $l->tipo === 'pago' => $rotulos['pago'] ?? 'Pagamento',
            $l->valorCentavos < 0 => $rotulos['estorno'],
            default => $rotulos['credito'],
        };
    }

    private static function reais(int $centavos): string
    {
        return ($centavos < 0 ? '-' : '') . intdiv(abs($centavos), 100) . ',' . str_pad((string) (abs($centavos) % 100), 2, '0', \STR_PAD_LEFT);
    }

    /**
     * @param list<string> $campos
     * @param list<int>    $numericos posições que são número gerado pelo sistema
     */
    private static function linha(array $campos, array $numericos): string
    {
        $celulas = [];
        foreach ($campos as $i => $c) {
            if (!in_array($i, $numericos, true) && preg_match('/^[=+\-@\t\r]/', $c) === 1) {
                $c = "'" . $c;
            }
            $celulas[] = '"' . str_replace('"', '""', $c) . '"';
        }

        return implode(';', $celulas);
    }
}
