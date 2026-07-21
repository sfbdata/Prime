<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\DTO\EntradaTaxaEncargos;
use App\Cobranca\Enum\BaseEncargo;

/**
 * Traduz a entrada do modal (modo/%/R$ por encargo) em overrides de taxa (bp) para gravar na obrigação.
 * Puro (sem I/O). Para o modo 'reais', deriva a taxa que reproduz o R$ digitado À DATA DE REFERÊNCIA,
 * encadeando as bases na MESMA ordem do motor (juros → multa → correção → honorários), porque a base de
 * multa/correção/honorários pode incluir os encargos anteriores do dia. É o que sustenta a promessa
 * "editei o R$ hoje = fixei a % equivalente à data de hoje" (spec §5). 'herda' → null; 'percent' → bp direto.
 */
final class ConversorTaxaEncargo
{
    public function __construct(private readonly CalculadoraEncargos $calculadora)
    {
    }

    /**
     * @return array{taxaJurosMensalBp:?int, taxaMultaBp:?int, taxaCorrecaoBp:?int, taxaHonorariosBp:?int}
     */
    public function overrides(
        EntradaTaxaEncargos $e,
        ConfigEncargos $baseCaso,
        int $principal,
        \DateTimeImmutable $vencimento,
        \DateTimeImmutable $dataRef,
    ): array {
        $dias = $this->calculadora->diasDeAtraso($vencimento, $dataRef);

        // JUROS
        $jurosBp = $this->bpDe(
            $e->modoJuros,
            $e->jurosBp,
            fn (): int => CalculadoraEncargos::taxaJurosBpDeValor($principal, $dias, (int) $e->jurosReais),
        );
        $jurosBpEfetivo = $jurosBp ?? $baseCaso->taxaJurosMensalBp;
        // juros do dia via motor (usa o mesmo arredondamento forward do juros); o `calcular` já respeita
        // tolerância/carência internamente, então sai 0 quando dias==0 ou dentro da tolerância.
        $jurosHoje = $this->calculadora->calcular(
            $principal,
            $vencimento,
            new ConfigEncargos(taxaJurosMensalBp: $jurosBpEfetivo, regimeJuros: $baseCaso->regimeJuros, toleranciaJurosMultaDias: $baseCaso->toleranciaJurosMultaDias),
            $dataRef,
        )['juros'];

        // MULTA (base Principal ou Principal+juros do dia)
        $baseMultaEnum = $baseCaso->baseMulta;
        $baseMulta = $baseMultaEnum === BaseEncargo::Principal ? $principal : $principal + $jurosHoje;
        $multaBp = $this->bpDe(
            $e->modoMulta,
            $e->multaBp,
            fn (): int => CalculadoraEncargos::taxaDeValor($baseMulta, (int) $e->multaReais),
        );
        $multaBpEfetivo = $multaBp ?? $baseCaso->taxaMultaBp;
        $multaHoje = CalculadoraEncargos::valorDeTaxa($baseMulta, $multaBpEfetivo);

        // CORREÇÃO (base Principal ou Principal+juros+multa do dia)
        $baseCorrecao = $baseCaso->baseCorrecao === BaseEncargo::Principal ? $principal : $principal + $jurosHoje + $multaHoje;
        $correcaoBp = $this->bpDe(
            $e->modoCorrecao,
            $e->correcaoBp,
            fn (): int => CalculadoraEncargos::taxaDeValor($baseCorrecao, (int) $e->correcaoReais),
        );
        $correcaoBpEfetivo = $correcaoBp ?? $baseCaso->taxaCorrecaoBp;
        $correcaoHoje = CalculadoraEncargos::valorDeTaxa($baseCorrecao, $correcaoBpEfetivo);

        // HONORÁRIOS (base composta = P+juros+multa+correção do dia, ou principal)
        $baseHon = $baseCaso->baseHonorarios === BaseEncargo::Composta
            ? $principal + $jurosHoje + $multaHoje + $correcaoHoje
            : $principal;
        $honorariosBp = $this->bpDe(
            $e->modoHonorarios,
            $e->honorariosBp,
            fn (): int => CalculadoraEncargos::taxaDeValor($baseHon, (int) $e->honorariosReais),
        );

        return [
            'taxaJurosMensalBp' => $jurosBp,
            'taxaMultaBp' => $multaBp,
            'taxaCorrecaoBp' => $correcaoBp,
            'taxaHonorariosBp' => $honorariosBp,
        ];
    }

    /**
     * Resolve o bp de um encargo pelo modo: 'herda' → null; 'percent' → bp submetido; 'reais' → deriva via
     * o callback (só chamado no modo reais, para não computar base à toa). Modo desconhecido ESTOURA alto
     * em vez de virar 'herda' silenciosamente: `modo` vem de campo hidden, e um valor inesperado não pode
     * descartar a taxa que o usuário digitou.
     *
     * @param callable(): int $derivarDeReais
     */
    private function bpDe(string $modo, ?int $bpSubmetido, callable $derivarDeReais): ?int
    {
        return match ($modo) {
            'percent' => $bpSubmetido,
            'reais' => $derivarDeReais(),
            'herda' => null,
            default => throw new \InvalidArgumentException(sprintf('Modo de taxa desconhecido: "%s".', $modo)),
        };
    }
}
