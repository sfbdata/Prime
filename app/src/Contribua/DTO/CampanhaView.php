<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

/** O que as telas precisam saber da campanha para o usuário logado (função Twig `campanha()`). */
final readonly class CampanhaView
{
    public function __construct(
        public string $nome,
        public string $chamada,
        public string $chamadaSelo,
        public string $preco,
        public string $devolve,
        public string $multTx,
        public int $prazoPedido,
        public int $prazoPagto,
        public string $inicio,
        public string $fim,
        public string $estado,
        public bool $ehMaster,
        public string $statusTexto,
        /** O status na janela da campanha (`stTx` do `abrir()` do desenho): sem o "Prévia do Master" da faixa. */
        public string $statusJanela,
        public bool $mostrarFaixa,
        public bool $mostrarFaq,
        public string $faqPago,
        public string $botao,
    ) {
    }
}
