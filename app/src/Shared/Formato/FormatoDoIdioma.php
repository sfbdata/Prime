<?php

declare(strict_types=1);

namespace App\Shared\Formato;

/**
 * Datas, horas e números no formato do idioma de quem lê (spec docs/specs/idioma-do-sistema.md, entrega 2). Os
 * padrões vêm do ICU pelo esqueleto (`ddMMyyyy`, `jm`): em português dão exatamente o que a tela mostra hoje
 * (`dd/MM/yyyy`, `HH:mm`, `1.234,56`), e cada idioma ganha o seu (`MM/dd/yyyy` e `h:mm a` em inglês). Moeda
 * continua sendo real; só a forma de escrever o número muda.
 */
final class FormatoDoIdioma
{
    /** @var array<string, \IntlDateFormatter> */
    private array $datas = [];

    /** @var array<string, \NumberFormatter> */
    private array $numeros = [];

    public function data(\DateTimeInterface $momento, string $locale): string
    {
        return $this->formatar($momento, $locale, 'ddMMyyyy');
    }

    public function dataHora(\DateTimeInterface $momento, string $locale): string
    {
        return $this->formatar($momento, $locale, 'ddMMyyyy') . ' ' . $this->formatar($momento, $locale, 'jm');
    }

    public function hora(\DateTimeInterface $momento, string $locale): string
    {
        return $this->formatar($momento, $locale, 'jm');
    }

    public function numero(float|int $valor, string $locale, int $casas = 0): string
    {
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
        $chave = $locale . '|' . $esqueleto . '|' . $momento->getTimezone()->getName();
        if (!isset($this->datas[$chave])) {
            $padrao = (new \IntlDatePatternGenerator($locale))->getBestPattern($esqueleto);
            $this->datas[$chave] = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $momento->getTimezone(), \IntlDateFormatter::GREGORIAN, (string) $padrao);
        }

        return (string) $this->datas[$chave]->format($momento);
    }
}
