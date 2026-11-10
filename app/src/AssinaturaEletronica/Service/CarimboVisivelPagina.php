<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

/**
 * A página que recebe o carimbo visível, como o `LocalizadorDaUltimaPagina` a achou: o objeto, o corpo (o texto entre
 * `obj` e `endobj` da versão mais nova), a caixa e o /Annots dela.
 *
 * O /Annots vem de um destes jeitos: ausente (os quatro campos de anotação nulos); vetor direto (`anotacoesDiretas` é
 * o miolo do vetor, sem os colchetes); referência a um vetor (`referenciaDasAnotacoes`, e `vetorDasAnotacoes` é o
 * corpo do objeto apontado, ou null se ele não foi achado); ou outro valor (só `trechoDasAnotacoes`).
 */
final readonly class CarimboVisivelPagina
{
    /**
     * @param array{float, float, float, float} $caixa              a área visível (`page.view` do pdf.js: o /CropBox
     *                                                              recortado pelo /MediaBox), normalizada: x0, y0, x1, y1
     * @param array{int, int}|null              $trechoDasAnotacoes início do nome `/Annots` e fim do valor, no corpo
     * @param array{int, int}|null              $referenciaDasAnotacoes número e geração do vetor indireto
     * @param int                               $aberturaDoDicionario   onde está o `<<` do dicionário no corpo, depois de
     *                                                                  espaços e comentários
     */
    public function __construct(
        public int $numero,
        public int $geracao,
        public string $corpo,
        public array $caixa,
        public ?array $trechoDasAnotacoes = null,
        public ?string $anotacoesDiretas = null,
        public ?array $referenciaDasAnotacoes = null,
        public ?string $vetorDasAnotacoes = null,
        public int $aberturaDoDicionario = 0,
    ) {
    }
}
