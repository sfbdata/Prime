<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\RegimeJuros;

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
        /**
         * Id do cliente CREDOR — só para o link do cabeçalho ("Credor: <a>"). Null quando a carteira
         * está sem cliente (o `clienteNome` já cai em "—" nesse caso); a tela então não linka.
         */
        public readonly ?int $clienteId = null,
        /**
         * Encargos por atraso já em forma de FRASE, para o trilho de Configuração ("1% ao mês,
         * simples" · "2% sobre o principal"). Taxa e regime/base são dois campos no domínio e uma
         * linha só na tela: quem junta é a DTO, porque é decisão de apresentação — e assim o Twig não
         * precisa saber que a taxa está gravada em pontos-base.
         */
        public readonly string $jurosLabel = 'Sem juros',
        public readonly string $multaLabel = 'Sem multa',
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
            clienteId: $cliente?->getId(),
            jurosLabel: self::juros($c->getTaxaJurosMensalBp(), $c->getRegimeJuros()),
            multaLabel: self::multa($c->getTaxaMultaBp(), $c->getBaseMulta()),
        );
    }

    /** "1% ao mês, simples" — ou "Sem juros" quando a carteira não cobra juros. */
    private static function juros(int $taxaBp, RegimeJuros $regime): string
    {
        if ($taxaBp <= 0) {
            return 'Sem juros';
        }

        return sprintf(
            '%s%% ao mês, %s',
            self::percentual($taxaBp),
            $regime === RegimeJuros::Composto ? 'composto' : 'simples',
        );
    }

    /** "2% sobre o principal" — ou "Sem multa" quando a carteira não cobra multa. */
    private static function multa(int $taxaBp, BaseEncargo $base): string
    {
        if ($taxaBp <= 0) {
            return 'Sem multa';
        }

        return sprintf(
            '%s%% sobre %s',
            self::percentual($taxaBp),
            $base === BaseEncargo::Composta ? 'o principal + encargos' : 'o principal',
        );
    }

    /**
     * Pontos-base → percentual brasileiro sem zero à toa: 100 → "1", 150 → "1,5", 233 → "2,33".
     * Cortar o decimal vazio importa porque a linha do trilho é estreita e "1%" cabe onde "1,00%"
     * já começa a disputar espaço com o rótulo.
     */
    private static function percentual(int $bp): string
    {
        $texto = number_format($bp / 100, 2, ',', '.');

        return str_contains($texto, ',') ? rtrim(rtrim($texto, '0'), ',') : $texto;
    }
}
