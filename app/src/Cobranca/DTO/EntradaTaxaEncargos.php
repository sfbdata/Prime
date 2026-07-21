<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Entrada crua das taxas por-obrigação vinda do modal (criar/editar). Por encargo: um `modo`
 * ('herda' | 'percent' | 'reais') + o valor em bp (quando %) e/ou em centavos (quando R$). O
 * `ConversorTaxaEncargo` traduz isto em overrides (bp) à data de hoje. Só transporta — sem lógica.
 */
final class EntradaTaxaEncargos
{
    public function __construct(
        public string $modoJuros = 'herda',
        public ?int $jurosBp = null,
        public ?int $jurosReais = null,
        public string $modoMulta = 'herda',
        public ?int $multaBp = null,
        public ?int $multaReais = null,
        public string $modoCorrecao = 'herda',
        public ?int $correcaoBp = null,
        public ?int $correcaoReais = null,
        public string $modoHonorarios = 'herda',
        public ?int $honorariosBp = null,
        public ?int $honorariosReais = null,
    ) {
    }
}
