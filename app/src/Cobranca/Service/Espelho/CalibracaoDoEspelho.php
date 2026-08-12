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
 *
 * 🔑 INV-CB4 — **a unidade da medição é a LINHA, não o boleto** (§6.4: *"para cada linha do espelho
 * com atraso > 0"*). Isto não é detalhe de forma: **a contabilidade calcula linha a linha e arredonda
 * por linha**; somar as linhas e rodar a fórmula uma vez sobre o total produz diferença de
 * arredondamento que não existe na conta deles. Medido em produção (TL1, 12/08): por boleto a
 * calibração acusava 301 diferenças "de 1 centavo"; **por linha elas são ZERO**, e o que sobra é uma
 * divergência de regra só.
 *
 * A prova de que a régua deles é por linha, num boleto real de 4 linhas: a conta por linha reproduz
 * juros 73,33 · multa 3,03 · honorário 45,52 ao centavo; a conta agregada erra os três.
 *
 * ⚠️ Consequência a não esquecer: por linha, a base do encargo inclui as linhas de classe `1.4`/`1.5`
 * (que são elas mesmas encargo de ciclo anterior). Isso mede a régua DELES, e é o que a §6.1 pergunta.
 * **Não é licença para o sistema cobrar assim** — o principal do sistema continua saindo do ramo do
 * {@see AgrupadorDeBoletos} (INV-A1), e igualar os dois reintroduziria a dupla contagem.
 *
 * ⚠️ INV-CB3 — **a linha de base não positiva sai da calibração, e isto é um BURACO CONHECIDO.**
 * A contabilidade lança encargo **negativo** na linha de desconto (classe `1.6`); a
 * `CalculadoraEncargos` degrada para zero em base não positiva, de propósito. Comparar os dois por
 * linha fabricaria divergência que não é de fórmula — medido: até R$ 3,92 num único desconto.
 *
 * A razão de descartar, e não de comparar, é medida: **no boleto com desconto a conta AGREGADA fecha
 * com a deles dentro de 1 centavo** (3 boletos em prod, 12/08: um exato, dois com 1 centavo). O
 * encargo negativo por linha é decomposição, não cobrança — ele se reconcilia no boleto.
 *
 * 🔴 **O que o descarte esconde:** sob a premissa do módulo (§16.3 — *o sistema espelha a
 * contabilidade, esteja ela certa ou errada*), essas linhas são não-espelhamento que a calibração
 * deixa de contar. Medido na TL1 de 12/08: **2 linhas, R$ 4,00**. Por isso `semBase` é contador
 * PRÓPRIO no {@see \App\Cobranca\DTO\ResultadoCalibracao} e aparece separado na saída do comando — o
 * buraco é aceito, mas nunca invisível. Se esse número crescer, a decisão tem de ser revista.
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

        $porChave = $this->indexarObrigacoes($carteira, $dadosAte);

        $faixas = array_fill_keys(self::FAIXAS, 0);
        $comparadas = 0;
        $semPar = 0;
        $semAtraso = 0;
        $semBase = 0;
        $piores = [];

        foreach ($this->agrupador->linhasComChave($relatorio) as ['chave' => $chave, 'linha' => $linha]) {
            $obrigacao = $porChave[$chave] ?? null;

            if ($obrigacao === null) {
                ++$semPar; // INV-CB2

                continue;
            }

            // §6.1 pergunta "se rodarmos a nossa fórmula COM OS DADOS DA PLANILHA". Valor e
            // vencimento vêm dela; do sistema vem só a CONFIGURAÇÃO (a cascata), que é a nossa parte
            // da conta. Usar o `valorOriginal` do sistema misturaria divergência de PRINCIPAL —
            // matéria da conferência — com divergência de FÓRMULA, e a medição perderia o sentido.
            $vencimento = $linha['vencimento'];
            $base = $linha['valor'] ?? 0;

            if ($vencimento === null || $vencimento >= $dadosAte) {
                ++$semAtraso;

                continue;
            }

            // INV-CB3, e ele é um BURACO CONHECIDO, não uma regra limpa — ver o docblock da classe.
            if ($base <= 0) {
                ++$semBase;

                continue;
            }

            $nosso = $this->calculadora->calcular(
                $base,
                $vencimento,
                $this->resolvedor->resolver($obrigacao),
                $dadosAte,
            );

            ++$comparadas;
            $maiorDiferenca = 0;

            foreach (['juros', 'multa', 'correcao', 'honorarios'] as $campo) {
                $diferenca = $nosso[$campo] - ($linha[$campo] ?? 0);

                if (abs($diferenca) > abs($maiorDiferenca)) {
                    $maiorDiferenca = $diferenca;
                }

                if ($diferenca !== 0) {
                    // A IDENTIFICAÇÃO, não a unidade crua: `01-01 (05-03,06-01)` sai como `01-01`,
                    // que é a forma do `ObjetoCobranca` e a que a conferência imprime. 16 das 96
                    // unidades da TL1 têm a forma com parênteses — sem isto, a mesma dívida aparece
                    // com dois nomes em dois relatórios do mesmo módulo.
                    [$identificacao] = IdentificacaoDaUnidade::separar((string) ($linha['unidade'] ?? ''));

                    $piores[] = [
                        'unidade' => $identificacao,
                        'nn' => $linha['nn'],
                        'classe' => $linha['classe'],
                        'campo' => $campo,
                        'nosso' => $nosso[$campo],
                        'deles' => $linha[$campo] ?? 0,
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
            semParNoSistema: $semPar,
            semAtraso: $semAtraso,
            semBase: $semBase,
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
