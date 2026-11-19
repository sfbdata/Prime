<?php

declare(strict_types=1);

namespace App\Shared\Formato;

/**
 * Datas, horas e números no formato do idioma de quem lê (spec docs/specs/idioma-do-sistema.md, entrega 2). Os
 * padrões vêm do ICU pelo esqueleto (`ddMMyyyy`, `jm`): em português dão exatamente o que a tela mostra hoje
 * (`dd/MM/yyyy`, `HH:mm`, `1.234,56`), e cada idioma ganha o seu (`MM/dd/yyyy` e `h:mm a` em inglês). Moeda
 * continua sendo real; só a forma de escrever o número muda.
 *
 * As "Opções avançadas" do Meu Perfil (spec docs/specs/sistema-em-ingles.md, §2) podem fixar o formato da data
 * (`dmy`, `mdy`, `ymd`) e o dos números (`br`, `us`) no lugar do automático, pelo idioma. Os códigos são os valores
 * dos enums `FormatoDeData` e `FormatoDeNumero` do Profile (o Shared não depende do domínio); código desconhecido
 * vale como automático.
 */
final class FormatoDoIdioma
{
    /** Padrão do ICU de cada formato de data fixo. */
    private const DATAS_FIXAS = ['dmy' => 'dd/MM/yyyy', 'mdy' => 'MM/dd/yyyy', 'ymd' => 'yyyy-MM-dd'];

    /** Locale do ICU que escreve o número de cada jeito fixo. */
    private const NUMEROS_FIXOS = ['br' => 'pt_BR', 'us' => 'en_US'];

    /** @var array<string, \IntlDateFormatter> */
    private array $datas = [];

    /** @var array<string, string> padrão do ICU por locale e esqueleto */
    private array $padroes = [];

    /** @var array<string, \NumberFormatter> */
    private array $numeros = [];

    public function data(\DateTimeInterface $momento, string $locale, ?string $formatoData = null): string
    {
        $fixo = self::DATAS_FIXAS[$formatoData ?? ''] ?? null;

        return $fixo !== null ? $this->formatarComPadrao($momento, $locale, $fixo) : $this->formatar($momento, $locale, 'ddMMyyyy');
    }

    public function dataHora(\DateTimeInterface $momento, string $locale, ?string $formatoData = null): string
    {
        return $this->data($momento, $locale, $formatoData) . ' ' . $this->formatar($momento, $locale, 'jm');
    }

    public function hora(\DateTimeInterface $momento, string $locale): string
    {
        return $this->formatar($momento, $locale, 'jm');
    }

    public function numero(float|int $valor, string $locale, int $casas = 0, ?string $formatoNumero = null): string
    {
        $locale = self::NUMEROS_FIXOS[$formatoNumero ?? ''] ?? $locale;
        $chave = $locale . '|' . $casas;
        if (!isset($this->numeros[$chave])) {
            $formatador = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
            $formatador->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $casas);
            $formatador->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $casas);
            $this->numeros[$chave] = $formatador;
        }

        return (string) $this->numeros[$chave]->format($valor);
    }

    /** O momento é escrito no fuso dele: quem chama converte antes (o Twig converte para o fuso do sistema). */
    private function formatar(\DateTimeInterface $momento, string $locale, string $esqueleto): string
    {
        $chave = $locale . '|' . $esqueleto;
        $this->padroes[$chave] ??= (string) (new \IntlDatePatternGenerator($locale))->getBestPattern($esqueleto);

        return $this->formatarComPadrao($momento, $locale, $this->padroes[$chave]);
    }

    private function formatarComPadrao(\DateTimeInterface $momento, string $locale, string $padrao): string
    {
        $chave = $locale . '|' . $padrao . '|' . $momento->getTimezone()->getName();
        if (!isset($this->datas[$chave])) {
            $this->datas[$chave] = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $momento->getTimezone(), \IntlDateFormatter::GREGORIAN, $padrao);
        }

        return (string) $this->datas[$chave]->format($momento);
    }
}
