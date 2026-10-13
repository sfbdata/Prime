<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use Symfony\Component\HttpFoundation\InputBag;

/**
 * As opções da janela "Imprimir" (desenho 1.2.3, `impVals`/`impMontar`), lidas da query string.
 *
 * "Incluir na folha": Cabeçalho do escritório (`cabecalho`), Dados da pasta e do processo
 * (`dados` — a faixa Pasta/Cliente/Processo), Autor, data e tipo do registro (`autor`) e
 * Financeiro (`financeiro` — o bloco de lançamentos do `impFinanceiro`). Os padrões são os do
 * desenho (`gmImprimir`): os três primeiros ligados, o Financeiro DESLIGADO. "Tamanho do texto"
 * vai de 9 a 16 pt em passos de 0,5, padrão 11,5.
 *
 * A seção diz QUAL folha: `resumo` (Imprimir resumo), `extrato` (Imprimir extrato do Financeiro)
 * ou `moldura` (só cabeçalho, faixa, financeiro e rodapé: o corpo é posto pela tela — registro e
 * processo). Seção desconhecida é `null`: o controller responde 404.
 *
 * Valor fora do que a tela manda (fonte "abc", 99 pt) não é erro: cai no padrão ou no limite.
 * Quem decide se o usuário VÊ o financeiro não é esta classe — é o controller.
 */
final readonly class PastaImpressaoOpcoes
{
    public const SECAO_RESUMO  = 'resumo';
    public const SECAO_EXTRATO = 'extrato';
    public const SECAO_MOLDURA = 'moldura';

    public const FONTE_MINIMA = 9.0;
    public const FONTE_MAXIMA = 16.0;
    public const FONTE_PADRAO = 11.5;

    private const SECOES = [self::SECAO_RESUMO, self::SECAO_EXTRATO, self::SECAO_MOLDURA];

    public function __construct(
        public ?string $secao,
        public bool $cabecalho,
        public bool $dados,
        public bool $autor,
        public bool $financeiro,
        public float $fonte,
    ) {
    }

    public static function daQuery(InputBag $query): self
    {
        $secao = (string) $query->get('secao', self::SECAO_RESUMO);

        return new self(
            secao: in_array($secao, self::SECOES, true) ? $secao : null,
            cabecalho: self::ligado($query, 'cabecalho', true),
            dados: self::ligado($query, 'dados', true),
            autor: self::ligado($query, 'autor', true),
            financeiro: self::ligado($query, 'financeiro', false),
            fonte: self::fonte($query->get('fonte')),
        );
    }

    /** "11,5 pt", como o desenho escreve entre o − e o + (`fonteTxt`). */
    public function fonteRotulo(): string
    {
        return str_replace('.', ',', self::numero($this->fonte)) . ' pt';
    }

    /** "11.5" — para o CSS (`font-size: 11.5pt`), sem vírgula e sem zero inútil. */
    public function fonteCss(): string
    {
        return self::numero($this->fonte);
    }

    private static function ligado(InputBag $query, string $chave, bool $padrao): bool
    {
        $valor = $query->get($chave);
        if ($valor === null || $valor === '') {
            return $padrao;
        }

        return !in_array((string) $valor, ['0', 'false', 'nao', 'off'], true);
    }

    private static function fonte(mixed $valor): float
    {
        $texto = str_replace(',', '.', trim((string) $valor));
        if ($texto === '' || !is_numeric($texto)) {
            return self::FONTE_PADRAO;
        }

        // Passos de 0,5, dentro de 9–16 (desenho: `menor`/`maior`).
        $passo = round(((float) $texto) * 2) / 2;

        return max(self::FONTE_MINIMA, min(self::FONTE_MAXIMA, $passo));
    }

    private static function numero(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 1, '.', ''), '0'), '.');
    }
}
