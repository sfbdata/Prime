<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * A competência (`MM/AAAA`) de uma linha importável é obrigatória e válida — verificada no construtor do
 * VO, não só no adapter.
 *
 * Parece redundante (o `AcordosDetalhadosAdapter` já rejeita linha sem `MM/AAAA`), e é justamente por isso
 * que precisa estar aqui: o UseCase se declara **fonte-agnóstico**, então a garantia não pode morar num
 * adapter específico. Sem esta checagem, um segundo adapter que passasse `'jan/26'` produziria silêncio em
 * cascata — `Obrigacao::setCompetencia` converte inválido em NULL, o `#[Assert\Regex]` do
 * `RegistrarObrigacaoInput` não é executado por nenhum validador no caminho da importação, e a obrigação
 * nasceria com competência nula. A partir daí o fallback do legado a alcançaria por QUALQUER competência
 * da planilha, e a prévia voltaria a divergir da confirmação no principal reconciliado.
 *
 * É a invariante que sustenta o índice `obr:` do `ObrigacoesTocadasNaImportacao`: toda linha CRIADA pela
 * importação tem competência não-nula, logo o fallback nunca a alcança por um trio diferente.
 */
final class CompetenciaImportada
{
    private const FORMATO = '#^\d{2}/\d{4}$#';

    /** @throws \InvalidArgumentException quando a competência não é `MM/AAAA` */
    public static function exigirValida(string $competencia, string $referencia): void
    {
        if (preg_match(self::FORMATO, $competencia) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Competência inválida ("%s") na linha %s: o formato é MM/AAAA. Sem ela o casamento com o '
                . 'boleto do sistema seria feito só pelo Nosso Número, que a contábil reaproveita.',
                $competencia,
                $referencia,
            ));
        }
    }
}
