<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\DTO\MovimentacaoAoClienteOutput;

/**
 * Os textos do e-mail automático ao cliente (spec push-envio-automatico §3): o assunto e o miolo HTML
 * (já escapado) das novas movimentações e do resumo semanal. Puro, sem dependência.
 *
 * Segredo de justiça (D4): a movimentação sigilosa não aparece (nem tipo, data, órgão, número ou
 * texto); o e-mail diz só {@see MovimentacaoAoClienteOutput::TEXTO_SIGILOSA}, uma vez, e o assunto
 * não leva o número do processo.
 */
final class TextoDoEnvioAutomatico
{
    public const TEXTO_CORTADO = 'Texto completo disponível com o escritório.';
    public const FECHO = 'Qualquer dúvida, fale com o escritório respondendo a este e-mail.';

    private const P = '<p style="margin:0 0 14px;">';

    /** @param non-empty-list<MovimentacaoAoClienteOutput> $movimentacoes */
    public static function assuntoDasNovas(array $movimentacoes): string
    {
        $n = \count($movimentacoes);
        $base = $n === 1 ? 'Nova movimentação no seu processo' : $n . ' novas movimentações no seu processo';

        return trim($base . ' ' . self::numeroUnico($movimentacoes));
    }

    /** @param non-empty-list<MovimentacaoAoClienteOutput> $movimentacoes */
    public static function assuntoDoResumo(array $movimentacoes): string
    {
        return trim('Resumo semanal do seu processo ' . self::numeroUnico($movimentacoes));
    }

    public static function introducaoDasNovas(): string
    {
        return 'Chegou movimentação nova no seu processo, publicada no Diário de Justiça Eletrônico.';
    }

    public static function introducaoDoResumo(\DateTimeImmutable $inicio, \DateTimeImmutable $fim): string
    {
        return 'Estas são as movimentações do seu processo publicadas na semana de ' . $inicio->format('d/m') . ' a ' . $fim->format('d/m') . '.';
    }

    /**
     * O miolo do e-mail: saudação, introdução, um bloco por movimentação não sigilosa, a linha do
     * segredo de justiça (se houver) e o fecho. Todo texto vem escapado.
     *
     * @param non-empty-list<MovimentacaoAoClienteOutput> $movimentacoes
     */
    public static function html(?string $nomeDoCliente, string $introducao, array $movimentacoes): string
    {
        $abertas = array_values(array_filter($movimentacoes, static fn (MovimentacaoAoClienteOutput $m): bool => !$m->sigilosa));
        $nome = TextoDoEnvioAoCliente::primeiroNome($nomeDoCliente);
        $html = self::P . self::e('Olá' . ($nome !== '' ? ', ' . $nome : '') . '.') . '</p>';
        if ($abertas !== []) {
            $html .= self::P . self::e($introducao) . '</p>' . implode('', array_map(self::bloco(...), $abertas));
        }
        if (\count($abertas) < \count($movimentacoes)) {
            $html .= self::P . self::e(MovimentacaoAoClienteOutput::TEXTO_SIGILOSA) . '</p>';
        }

        return $html . self::P . self::e(self::FECHO) . '</p>';
    }

    private static function bloco(MovimentacaoAoClienteOutput $m): string
    {
        $cabecalho = $m->tipo . ($m->data !== null ? ' de ' . $m->data->format('d/m/Y') : '');
        $origem = implode(' · ', array_filter([$m->orgao, $m->numeroDoProcesso !== '' ? 'Processo ' . $m->numeroDoProcesso : null]));

        return '<div style="margin:0 0 16px;padding:12px 14px;background-color:#f6f9fb;border-radius:4px;">'
            . '<p style="margin:0 0 4px;font-weight:bold;color:#12242f;">' . self::e($cabecalho) . '</p>'
            . ($origem !== '' ? '<p style="margin:0 0 8px;font-size:13px;color:#5f7684;">' . self::e($origem) . '</p>' : '')
            . ($m->texto !== null ? '<p style="margin:0;">' . self::e($m->texto) . '</p>' : '')
            . ($m->cortado ? '<p style="margin:8px 0 0;font-size:13px;color:#5f7684;">' . self::e(self::TEXTO_CORTADO) . '</p>' : '')
            . '</div>';
    }

    /**
     * O número do processo quando todas as movimentações são do mesmo processo e nenhuma é sigilosa;
     * senão vazio (o assunto fica sem número).
     *
     * @param list<MovimentacaoAoClienteOutput> $movimentacoes
     */
    private static function numeroUnico(array $movimentacoes): string
    {
        $numeros = array_unique(array_map(static fn (MovimentacaoAoClienteOutput $m): string => $m->sigilosa ? '' : $m->numeroDoProcesso, $movimentacoes));

        return \count($numeros) === 1 ? (string) reset($numeros) : '';
    }

    private static function e(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
