<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\Enum\BlocoRelatorio;

/**
 * O arquivo inteiro, lido e classificado — o resultado do {@see LeitorEspelhoRelatorio}.
 *
 * INV-A1: **toda linha do arquivo está aqui, em exatamente um balde.** `contarPorBloco()` tem que
 * somar `$linhasTotal`. É a primeira prova de que o leitor não perdeu nada, e o teste assere essa
 * IDENTIDADE — nunca contagens literais, que variam por arquivo (SPEC §5.4).
 */
final readonly class ArquivoEspelhado
{
    /**
     * @param list<LinhaEspelhada>       $linhas       todas as linhas, de todos os blocos
     * @param list<TotalizadorEspelhado> $totalizadores
     * @param array<string, int>|null    $configDeclarada taxas em basis points, como a contabilidade declarou
     */
    public function __construct(
        public string $arquivoNome,
        public string $arquivoHash,
        public ?\DateTimeImmutable $emitidoEm,
        public ?\DateTimeImmutable $dadosAte,
        public ?array $configDeclarada,
        public ?int $unidadesDeclaradas,
        public int $linhasTotal,
        public array $linhas,
        public array $totalizadores,
    ) {
    }

    /**
     * Quantas linhas em cada balde. A soma tem que dar `$linhasTotal` — ver INV-A1.
     *
     * @return array<string, int>
     */
    public function contarPorBloco(): array
    {
        $contagem = [];

        foreach (BlocoRelatorio::cases() as $bloco) {
            $contagem[$bloco->value] = 0;
        }

        foreach ($this->linhas as $linha) {
            ++$contagem[$linha->bloco->value];
        }

        return $contagem;
    }

    /**
     * As linhas de dado — as que viram matéria da conferência.
     *
     * @return list<LinhaEspelhada>
     */
    public function linhasDeDados(): array
    {
        return array_values(array_filter(
            $this->linhas,
            static fn (LinhaEspelhada $l): bool => $l->bloco === BlocoRelatorio::Dados,
        ));
    }

    /**
     * A soma das colunas monetárias das linhas de DADOS — o lado esquerdo da reconciliação interna
     * (SPEC §4.3). Tem que bater com o totalizador da própria planilha.
     *
     * Só o bloco `Dados` entra: somar o totalizador junto contaria o mesmo dinheiro duas vezes.
     *
     * @return array{valor: int, juros: int, multa: int, correcao: int, honorarios: int, total: int}
     */
    public function somarDados(): array
    {
        $soma = ['valor' => 0, 'juros' => 0, 'multa' => 0, 'correcao' => 0, 'honorarios' => 0, 'total' => 0];

        foreach ($this->linhasDeDados() as $linha) {
            $soma['valor'] += $linha->valor ?? 0;
            $soma['juros'] += $linha->juros ?? 0;
            $soma['multa'] += $linha->multa ?? 0;
            $soma['correcao'] += $linha->correcao ?? 0;
            $soma['honorarios'] += $linha->honorarios ?? 0;
            $soma['total'] += $linha->total ?? 0;
        }

        return $soma;
    }

    /**
     * A linha "Total de inadimplência" do rodapé — o lado direito da reconciliação interna.
     *
     * Existe em duas formas no mesmo arquivo, carregando os mesmos números (medido no TL1 de 12/08:
     * a linha 4130 na forma `Larga` e a 4140 na `Estreita`, ambas com 441.975,94). Devolve a última,
     * que é a do bloco por classe de conta.
     */
    public function totalGeral(): ?TotalizadorEspelhado
    {
        $candidatos = array_values(array_filter(
            $this->totalizadores,
            static fn (TotalizadorEspelhado $t): bool => str_starts_with(
                mb_strtolower(trim($t->rotulo)),
                'total'
            ),
        ));

        return $candidatos === [] ? null : $candidatos[array_key_last($candidatos)];
    }
}
