<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\DTO\ResultadoCalibracao;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\RelatorioImportado;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\Importacao\IdentificacaoDaUnidade;
use App\Cobranca\Service\ResolvedorConfigEncargos;

/**
 * Responde: **se rodarmos a nossa fórmula com os dados da planilha, na data que a contabilidade
 * usou, dá o número dela?** (SPEC docs/specs/cobranca-espelho-da-contabilidade.md §6).
 *
 * É a pergunta que decide o desenho do módulo. Sob a premissa do dono — a contabilidade é a verdade,
 * o sistema traduz —, o cálculo ao vivo é uma **projeção** entre um import e o próximo. Se a
 * projeção reproduz a conta deles, ela é confiável e o sistema pode seguir sozinho por dias. Se não
 * reproduz, o erro é nosso e precisa ser entendido.
 *
 * **Somente leitura, e sem tocar no núcleo.** `EncargosVivos::hidratar()` não serve aqui por dois
 * motivos: não aceita data (calcula sempre até agora) e **muta a entidade**. A `CalculadoraEncargos`
 * recebe a data explicitamente e é pura — é ela que se usa.
 *
 * INV-CB1: **a config sai da cascata COMPLETA** (`ResolvedorConfigEncargos::resolver`), não do preset
 * da carteira. Medido em produção: 58 obrigações têm ajuste próprio de encargo, e calibrar contra o
 * preset as reportaria como divergência falsa.
 *
 * INV-CB2: **linha do espelho que não casa com obrigação nenhuma fica FORA da calibração** — não
 * vira zero, não vira divergência. Ela é matéria da conferência (balde "falta no sistema"), e
 * misturar as duas perguntas produziria número sem significado.
 */
final class CalibracaoDoEspelho
{
    private const FAIXAS = ['exato', 'ate 1 centavo', 'ate 1 real', 'acima de 1 real'];

    private const PIORES = 20;

    public function __construct(
        private readonly AgrupadorDeBoletos $agrupador,
        private readonly ObrigacaoRepository $obrigacoes,
        private readonly CalculadoraEncargos $calculadora,
        private readonly ResolvedorConfigEncargos $resolvedor,
    ) {
    }

    public function calibrar(RelatorioImportado $relatorio): ResultadoCalibracao
    {
        $carteira = $relatorio->getCarteira();
        $dadosAte = $relatorio->getDadosAte();

        if ($carteira === null || $dadosAte === null) {
            throw new \LogicException(
                'Lote sem carteira ou sem a data "Inadimplência até" — sem ela não há em que data calcular.'
            );
        }

        $doRelatorio = $this->agrupador->agrupar($relatorio);
        $porChave = $this->indexarObrigacoes($carteira, $dadosAte);

        $faixas = array_fill_keys(self::FAIXAS, 0);
        $comparadas = 0;
        $fora = 0;
        $piores = [];

        foreach ($doRelatorio as $chave => $grupo) {
            $obrigacao = $porChave[$chave] ?? null;

            if ($obrigacao === null) {
                ++$fora; // INV-CB2

                continue;
            }

            // §6.1 pergunta "se rodarmos a nossa fórmula COM OS DADOS DA PLANILHA". Principal e
            // vencimento vêm dela; do sistema vem só a CONFIGURAÇÃO (a cascata), que é a nossa parte
            // da conta. Usar o `valorOriginal` do sistema misturaria divergência de PRINCIPAL —
            // matéria da conferência — com divergência de FÓRMULA, e a medição perderia o sentido.
            $vencimento = $grupo['vencimento'];

            if ($vencimento === null || $vencimento >= $dadosAte) {
                // Sem atraso na data do relatório não há encargo a comparar.
                ++$fora;

                continue;
            }

            $nosso = $this->calculadora->calcular(
                $grupo['principal'],
                $vencimento,
                $this->resolvedor->resolver($obrigacao),
                $dadosAte,
            );

            ++$comparadas;
            $maiorDiferenca = 0;

            foreach (['juros', 'multa', 'correcao', 'honorarios'] as $campo) {
                $diferenca = $nosso[$campo] - $grupo[$campo];

                if (abs($diferenca) > abs($maiorDiferenca)) {
                    $maiorDiferenca = $diferenca;
                }

                if ($diferenca !== 0) {
                    $piores[] = [
                        'unidade' => $grupo['unidade'],
                        'nn' => $grupo['nn'],
                        'campo' => $campo,
                        'nosso' => $nosso[$campo],
                        'deles' => $grupo[$campo],
                        'diferenca' => $diferenca,
                    ];
                }
            }

            ++$faixas[$this->faixaDe(abs($maiorDiferenca))];
        }

        usort($piores, static fn (array $a, array $b): int => abs($b['diferenca']) <=> abs($a['diferenca']));

        return new ResultadoCalibracao(
            carteira: $carteira->getNome() ?? '?',
            dadosAte: $dadosAte,
            comparadas: $comparadas,
            foraDaCalibracao: $fora,
            faixas: $faixas,
            piores: array_slice($piores, 0, self::PIORES),
        );
    }

    private function faixaDe(int $diferencaEmCentavos): string
    {
        return match (true) {
            $diferencaEmCentavos === 0 => 'exato',
            $diferencaEmCentavos <= 1 => 'ate 1 centavo',
            $diferencaEmCentavos <= 100 => 'ate 1 real',
            default => 'acima de 1 real',
        };
    }

    /**
     * @return array<string, Obrigacao>
     */
    private function indexarObrigacoes(\App\Cobranca\Entity\Carteira $carteira, \DateTimeImmutable $dadosAte): array
    {
        $porChave = [];

        foreach ($this->obrigacoes->emAbertoDaCarteiraAte($carteira, $dadosAte) as $linha) {
            $obrigacao = $this->obrigacoes->find($linha['id']);

            if ($obrigacao === null) {
                continue;
            }

            [$identificacao] = IdentificacaoDaUnidade::separar($linha['unidade']);
            $chave = AgrupadorDeBoletos::chave($identificacao, $linha['referenciaExterna'] ?? '', $linha['competencia']);

            $porChave[$chave] = $obrigacao;
        }

        return $porChave;
    }
}
