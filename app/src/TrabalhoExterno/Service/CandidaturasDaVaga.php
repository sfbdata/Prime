<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Candidatura;

/**
 * O que a vaga mostra das candidaturas a quem lê (montado pelo {@see ResumoDasCandidaturas}): os
 * números do cartão, a candidatura do próprio externo, a compatibilidade com o currículo dele e, só
 * no detalhe, os candidatos e quem aceitou a oferta nesta rodada.
 */
final readonly class CandidaturasDaVaga
{
    /**
     * @param list<Candidatura> $candidatos  só no detalhe; o recorte de quem lê é do apresentador
     * @param list<string>      $aceitosPor  nomes de quem aceitou na rodada atual (só no detalhe)
     */
    public function __construct(
        public int $total = 0,
        public int $recebidas = 0,
        public int $enviados = 0,
        public int $aceites = 0,
        public ?Candidatura $minha = null,
        public ?int $compat = null,
        public array $candidatos = [],
        public array $aceitosPor = [],
        /** Só no detalhe do externo: ele recusou esta oferta (D11b). */
        public bool $recusei = false,
    ) {
    }
}
