<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * Tudo o que a folha do mês em PDF mostra (spec ponto-desenho-0811, E16, P34 a P36; desenho 16 L634 a L705), já em
 * texto: o cabeçalho, as linhas do mês, o resumo, o controle de intervalos, as frases fixas, a base legal, o carimbo do
 * aceite (só quando o aceite vale para a folha de agora) e a faixa de validação (só na folha emitida com código).
 * Quem desenha a imagem só desenha: nenhuma conta mora lá.
 *
 * A impressão dos registros (decisão 3) é o sha256 do resumo dia a dia e dos totais do mês, na forma canônica (JSON com
 * as chaves sempre na mesma ordem): os mesmos registros dão sempre o mesmo número. Ficam fora a hora da emissão, o
 * código, o emissor, o aceite e o que depende de outros meses (o banco anterior e o atual) ou do cadastro.
 */
final readonly class ConteudoDaFolha
{
    /** As três frases do quadro "OBSERVAÇÕES GERAIS" (P36; desenho L695). As duas primeiras vão ao pacote jurídico. */
    public const OBSERVACOES_GERAIS = [
        'As horas extras foram autorizadas previamente pela gestão e serão compensadas conforme acordo de banco de horas.',
        'Feriados trabalhados serão compensados.',
        'Registros realizados pelo sistema eletrônico de ponto BlueJus.',
    ];
    public const DECLARACAO = 'Declaro que os registros acima representam fielmente minha jornada de trabalho.';
    public const BASE_LEGAL = 'Art. 74, §2º da CLT  |  Portaria MTP nº 671/2021  |  Art. 13 da Portaria nº 3.626/91';
    public const NOTA_DA_BASE_LEGAL = '(Esta folha de ponto substitui o Quadro de Horário de Trabalho, inclusive o de menores.)';

    /** @param list<LinhaDaFolhaImpressa> $linhas um dia por linha, do dia 1 ao último do mês */
    public function __construct(
        public int $ano,
        public int $mes,
        public CabecalhoDaFolha $cabecalho,
        public array $linhas,
        public ResumoDoMesDaFolha $resumo,
        public IntervalosDaFolha $intervalos,
        public ?CarimboDoAceiteNaFolha $carimbo = null,
        public ?ValidacaoDaFolha $validacao = null,
    ) {
    }

    /** "10/2026". */
    public function competenciaFormatada(): string
    {
        return sprintf('%02d/%04d', $this->mes, $this->ano);
    }

    /** "FOLHA DE PONTO · 10/2026" (L660). */
    public function titulo(): string
    {
        return 'FOLHA DE PONTO · ' . $this->competenciaFormatada();
    }

    /**
     * O resumo dos registros impressos que a folha emitida guarda nos dados assinados e que a impressão cobre: cada dia
     * ({@see LinhaDaFolhaImpressa::paraAImpressao()}) e os totais do mês.
     *
     * @return array{dias: list<array<string, mixed>>, totais: array<string, int|string>}
     */
    public function resumoDosRegistros(): array
    {
        return [
            'dias'   => array_map(static fn (LinhaDaFolhaImpressa $linha): array => $linha->paraAImpressao(), $this->linhas),
            'totais' => [
                'trabalhadas' => $this->resumo->trabalhadas,
                'extras'      => $this->resumo->extras,
                'incompletos' => $this->resumo->diasIncompletos,
                'a_revisar'   => $this->resumo->diasARevisar,
            ],
        ];
    }

    /** sha256 em hexadecimal do resumo dos registros na forma canônica. */
    public function impressaoDosRegistros(): string
    {
        return hash('sha256', json_encode($this->resumoDosRegistros(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
    }

    public function comCarimbo(?CarimboDoAceiteNaFolha $carimbo): self
    {
        return new self($this->ano, $this->mes, $this->cabecalho, $this->linhas, $this->resumo, $this->intervalos, $carimbo, $this->validacao);
    }

    public function comValidacao(ValidacaoDaFolha $validacao): self
    {
        return new self($this->ano, $this->mes, $this->cabecalho, $this->linhas, $this->resumo, $this->intervalos, $this->carimbo, $validacao);
    }
}
