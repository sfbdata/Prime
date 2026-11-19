<?php

declare(strict_types=1);

namespace App\Ponto\Service;

/**
 * O que as regras de intervalo dizem de uma batida, no momento dela ({@see AlertasAntesDaBatida}; spec
 * docs/specs/ponto-desenho-0811.md, P21 e E11). Duas respostas, que podem vir juntas na entrada; nenhuma recusa:
 *
 * - `alerta`: a janela "Antes de registrar" do desenho. A batida só grava com a confirmação da pessoa, ou quando vem da
 *   fila sem internet, que não tem como mostrar a janela;
 * - `avisoDepois`: a batida grava e a resposta avisa (a interjornada contada de uma saída vinda de esquecimento, spec
 *   ponto-esquecimento-nao-trava R4).
 *
 * `faltamDoIntervalo` marca o alerta do retorno antes do mínimo do intervalo, com os segundos que faltam na hora do
 * servidor: a janela troca o texto por um contador regressivo e acerta por eles a conta do aviso do dia no cartão (os
 * dois mostram o mesmo número, mesmo com o relógio do aparelho fora da hora).
 */
final readonly class IntervaloDaBatida
{
    public function __construct(
        public ?string $alerta = null,
        public ?string $avisoDepois = null,
        public ?int $faltamDoIntervalo = null,
    ) {
    }

    public static function livre(): self
    {
        return new self();
    }
}
