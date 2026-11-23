<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

/** O painel da campanha no Contribua (só o Master): valores crus para os campos e os resumos prontos. */
final readonly class PainelDaCampanhaView
{
    /**
     * @param list<string>                         $regras
     * @param list<LinhaDeDevolucaoDaCampanhaView> $devolucoes
     * @param list<LinhaAguardandoDaCampanhaView>  $aguardando
     * @param array<string, string>                $urls       configurar, padrao, confirmar, cancelar e devolvida (com id 0)
     */
    public function __construct(
        public string $nome,
        public string $chamada,
        /** Reais com ponto: "1.00". */
        public string $preco,
        public int $multiplicador,
        /** Y-m-d */
        public string $inicio,
        /** Y-m-d */
        public string $fim,
        public int $prazoPedido,
        public int $prazoPagto,
        public int $vagas,
        public bool $mostrarContador,
        public bool $ligada,
        /** Um benefício por linha. */
        public string $beneficiosTexto,
        public string $estadoRotulo,
        public string $estadoCor,
        public string $custo,
        public string $pedidosResumo,
        public array $regras,
        public array $devolucoes,
        public array $aguardando,
        public array $urls,
    ) {
    }
}
