<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Uma pasta ativa já lida do banco, no formato que o {@see \App\Pasta\Service\AnalisadorDePastasParadas}
 * precisa (desenho 02, `paradasDados` L4706-4736; spec docs/specs/pastas-paradas.md, "Cálculo").
 *
 * - `movimentacoes`: as datas candidatas a "última movimentação" que não são do andamento: a mais
 *   recente movimentação dos processos vinculados, a mais recente publicação (Push) pelos números
 *   deles e o registro mais recente escrito por pessoa. A ordem não importa (vale o máximo).
 * - `faseAtual`/`faseDesde`: o andamento GRAVADO (fase atual e quando a pasta entrou nela); nulos
 *   sem andamento gravado. `andamentoEncerrado`: a fase atual é a última da via.
 * - `processoNumero`: o número CNJ do processo principal já formatado; nulo sem processo.
 * - `pagamentos`: quantos lançamentos a pasta tem e quantos estão vencidos.
 */
final readonly class PastaParaAnaliseDeParada
{
    /**
     * @param list<\DateTimeImmutable>               $movimentacoes
     * @param array{total: int, vencidos: int}       $pagamentos
     */
    public function __construct(
        public int $id,
        public string $numero,
        public string $cliente,
        public string $responsavel,
        public ?int $responsavelId,
        public ?\DateTimeImmutable $abertura,
        public ?string $processoNumero,
        public ?string $processoTribunal,
        public string $processoSituacao,
        public array $movimentacoes,
        public ?string $faseAtual,
        public ?\DateTimeImmutable $faseDesde,
        public bool $andamentoEncerrado,
        public bool $proBono,
        public bool $contratoAssinado,
        public int $totalDocumentos,
        public bool $temProcuracao,
        public bool $temContratoDeHonorarios,
        public array $pagamentos,
    ) {
    }
}
