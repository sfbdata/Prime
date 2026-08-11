<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\Carteira;

/**
 * Leitura da Carteira para a visão da carteira (Etapa 8): cabeçalho de configuração (modo, honorários,
 * tolerância, vínculo preferido, rótulo do objeto) + agregados (nº de objetos, casos e saldo
 * consolidado derivado, centavos int). A lista de casos da carteira é passada à parte
 * (`CasoResumoOutput[]`). Agregados calculados na origem (repos/serviço); a DTO não consulta nada.
 */
final class CarteiraDetalheOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly string $clienteNome,
        public readonly string $modoLabel,
        public readonly string $formaHonorariosLabel,
        public readonly ?string $percentualHonorarios,
        public readonly int $toleranciaAtrasoDias,
        public readonly ?string $tipoVinculoPreferidoLabel,
        public readonly ?string $rotuloObjeto,
        public readonly int $totalObjetos,
        public readonly int $totalCasos,
        public readonly int $saldoConsolidado,
        /**
         * Quanto do consolidado já está VENCIDO, e quantos casos carregam esse atraso.
         *
         * Não custa consulta: o `CalculadoraSaldo::saldosDosCasos` já devolve o vencido por caso em
         * lote (é ele que acende o realce de linha via `temVencido`), e o UseCase só o descartava no
         * agregado. Mesma regra do `saldoConsolidado`: soma a carteira INTEIRA, não a página nem o
         * que a busca deixou visível — buscar ou virar de página não muda o quanto está em atraso.
         */
        public readonly int $saldoVencido = 0,
        public readonly int $totalComAtraso = 0,
        /**
         * Até quando os dados desta carteira estão em dia — a emissão MAIS ANTIGA entre os relatórios
         * importados. Null enquanto nada foi importado.
         */
        public readonly ?\DateTimeImmutable $dadosAtualizadosAte = null,
        /** @var array<string, \DateTimeImmutable> tipo de relatório => emissão, para o detalhamento */
        public readonly array $emissaoPorTipo = [],
    ) {
    }

    public static function fromEntity(
        Carteira $c,
        int $totalObjetos,
        int $totalCasos,
        int $saldoConsolidado,
        int $saldoVencido = 0,
        int $totalComAtraso = 0,
    ): self {
        $cliente = $c->getCliente();
        $vinculo = $c->getTipoVinculoPreferido();

        return new self(
            id: $c->getId() ?? 0,
            nome: $c->getNome(),
            clienteNome: $cliente !== null ? $cliente->getNomeExibicao() : '—',
            modoLabel: $c->getModo()->label(),
            formaHonorariosLabel: $c->getFormaHonorarios()->label(),
            percentualHonorarios: $c->getPercentualHonorarios(),
            toleranciaAtrasoDias: $c->getToleranciaAtrasoDias(),
            tipoVinculoPreferidoLabel: $vinculo?->label(),
            rotuloObjeto: $c->getRotuloObjeto(),
            totalObjetos: $totalObjetos,
            totalCasos: $totalCasos,
            saldoConsolidado: $saldoConsolidado,
            saldoVencido: $saldoVencido,
            totalComAtraso: $totalComAtraso,
            dadosAtualizadosAte: $c->getDadosAtualizadosAte(),
            emissaoPorTipo: $c->getEmissaoPorTipoDeRelatorio(),
        );
    }
}
