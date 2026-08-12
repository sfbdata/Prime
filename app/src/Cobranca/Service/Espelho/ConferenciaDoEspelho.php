<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\DTO\ResultadoConferencia;
use App\Cobranca\Entity\RelatorioImportado;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\RelatorioLinhaRepository;
use App\Cobranca\Service\Importacao\IdentificacaoDaUnidade;
use App\Cobranca\Service\Importacao\ReferenciaSubstituta;

/**
 * Compara o que a contabilidade cobra com o que o sistema tem
 * (SPEC docs/specs/cobranca-espelho-da-contabilidade.md §5).
 *
 * É a resposta para a pergunta que originou toda esta frente — *"o sistema está alinhado com a
 * contabilidade?"* —, e até aqui ela só podia ser respondida abrindo o Excel na mão.
 *
 * **Somente leitura.** Não escreve nada, em nenhuma tabela.
 *
 * 🔑 INV-C1 — **A ARMADILHA, e é a mais fácil de cair.** O principal esperado depende do ramo:
 *   - **boleto comum**: Σ da coluna Valor apenas das classes 1.1 / 1.14 / 1.6;
 *   - **parcela de acordo**: Σ da coluna Valor de TODAS as classes.
 *
 * Quem "consertar" a diferença igualando os dois — fazendo o Σ cru fechar também no boleto comum —
 * reintroduz no caminho normal exatamente a dupla contagem que a Fase 1 existe para matar. Medido no
 * TL1 de 12/08: com a regra por ramo, a soma dá 43.681.747, idêntica ao `valor_original` do sistema.
 *
 * INV-C2 — **decide o ramo sozinha, sem reusar o adapter.** O espelho guarda a coluna do acordo como
 * texto cru; a classificação sai dela e das classes de conta. Reusar o `TopLifeInadimplenciaAdapter`
 * aqui faria a conferência herdar os defeitos de quem ela precisa conferir.
 */
final class ConferenciaDoEspelho
{
    /**
     * As classes que compõem o principal de um BOLETO COMUM. O `1.6` é desconto (valor negativo) e
     * entra somando, como no importador.
     */
    private const CLASSES_DE_PRINCIPAL = ['1.1', '1.14', '1.6'];

    /**
     * O que caracteriza uma parcela de acordo na coluna "Informações do acordo".
     *
     * Escrito aqui de propósito, e não reaproveitado do adapter (INV-C2). Um teste fixa a mesma
     * string real nos dois lados, para que a duplicação não vire divergência silenciosa.
     */
    private const PADRAO_ACORDO = '/Acordo\s+(\d+)\s*-\s*Parc\.\s*(\d+)\s*\/\s*(\d+)/iu';

    public function __construct(
        private readonly RelatorioLinhaRepository $linhas,
        private readonly ObrigacaoRepository $obrigacoes,
    ) {
    }

    public function conferir(RelatorioImportado $relatorio): ResultadoConferencia
    {
        $carteira = $relatorio->getCarteira();
        $dadosAte = $relatorio->getDadosAte();

        if ($carteira === null || $dadosAte === null) {
            throw new \LogicException(
                'Lote sem carteira ou sem a data "Inadimplência até" — não há como recortar o universo do sistema.'
            );
        }

        $grupos = $this->agruparRelatorio($relatorio);
        $sistema = $this->indexarSistema($carteira, $dadosAte);

        $confere = 0;
        $falta = [];
        $principalDiferente = [];
        $ambiguidade = [];
        $vistos = [];
        $principalRelatorio = 0;

        foreach ($grupos as $chave => $grupo) {
            $principalRelatorio += $grupo['principal'];

            if (count($sistema['casosPorUnidade'][$grupo['unidade']] ?? []) > 1) {
                // D11: objeto com mais de um caso. Escolher um produziria divergência falsa e
                // silenciosa — reportar é a única saída honesta.
                $ambiguidade[] = $chave;

                continue;
            }

            if (!isset($sistema['porChave'][$chave])) {
                $falta[] = [
                    'chave' => $chave,
                    'unidade' => $grupo['unidade'],
                    'nn' => $grupo['nn'],
                    'competencia' => $grupo['competencia'],
                    'motivo' => $this->motivoDaFalta($grupo['unidade'], $sistema),
                ];

                continue;
            }

            $vistos[$chave] = true;
            $noSistema = $sistema['porChave'][$chave];

            if ($noSistema['valorOriginal'] !== $grupo['principal']) {
                $principalDiferente[] = [
                    'chave' => $chave,
                    'unidade' => $grupo['unidade'],
                    'relatorio' => $grupo['principal'],
                    'sistema' => $noSistema['valorOriginal'],
                    'diferenca' => $grupo['principal'] - $noSistema['valorOriginal'],
                ];

                continue;
            }

            ++$confere;
        }

        $sobra = [];
        $principalSistema = 0;

        foreach ($sistema['porChave'] as $chave => $linha) {
            $principalSistema += $linha['valorOriginal'];

            if (!isset($vistos[$chave])) {
                $sobra[] = [
                    'chave' => $chave,
                    'unidade' => $linha['unidade'],
                    'nn' => $linha['referenciaExterna'],
                    'competencia' => $linha['competencia'],
                ];
            }
        }

        return new ResultadoConferencia(
            carteira: $carteira->getNome() ?? '?',
            dadosAte: $dadosAte,
            gruposNoRelatorio: count($grupos),
            obrigacoesNoSistema: count($sistema['porChave']),
            confere: $confere,
            faltaNoSistema: $falta,
            sobraNoSistema: $sobra,
            principalDiferente: $principalDiferente,
            ambiguidadeDeCaso: $ambiguidade,
            principalRelatorio: $principalRelatorio,
            principalSistema: $principalSistema,
        );
    }

    /**
     * Agrupa as linhas do relatório em BOLETOS, pela mesma chave que o banco usa para deduplicar:
     * unidade + referência + competência. Boleto sem Nosso Número entra pela referência sintética,
     * a mesma do importador ({@see ReferenciaSubstituta}) — não uma segunda definição da chave.
     *
     * @return array<string, array{unidade: string, nn: ?string, competencia: ?string, principal: int}>
     */
    private function agruparRelatorio(RelatorioImportado $relatorio): array
    {
        $grupos = [];

        foreach ($this->linhas->dadosDoRelatorio($relatorio) as $linha) {
            if ($linha['unidade'] === null) {
                continue;
            }

            [$identificacao] = IdentificacaoDaUnidade::separar($linha['unidade']);

            $referencia = $linha['nn'];

            if ($referencia === null || trim($referencia) === '' || trim($referencia) === '-') {
                if ($linha['vencimento'] === null) {
                    continue;
                }

                $referencia = ReferenciaSubstituta::para($linha['vencimento']);
            }

            $chave = $this->chave($identificacao, $referencia, $linha['competencia']);

            $grupos[$chave] ??= [
                'unidade' => $identificacao,
                'nn' => $linha['nn'],
                'competencia' => $linha['competencia'],
                'principal' => 0,
                'ehAcordo' => false,
                'linhas' => [],
            ];

            if ($this->ehParcelaDeAcordo($linha['acordoTexto'])) {
                $grupos[$chave]['ehAcordo'] = true;
            }

            $grupos[$chave]['linhas'][] = [
                'classe' => $this->codigoDaClasse($linha['classe']),
                'valor' => $linha['valor'] ?? 0,
            ];
        }

        // INV-C1 — só agora, com o grupo inteiro montado, dá para saber o ramo e somar certo.
        foreach ($grupos as $chave => $grupo) {
            $principal = 0;

            foreach ($grupo['linhas'] as $linha) {
                if ($grupo['ehAcordo'] || in_array($linha['classe'], self::CLASSES_DE_PRINCIPAL, true)) {
                    $principal += $linha['valor'];
                }
            }

            $grupos[$chave]['principal'] = $principal;
            unset($grupos[$chave]['linhas'], $grupos[$chave]['ehAcordo']);
        }

        return $grupos;
    }

    /**
     * @return array{porChave: array<string, array<string, mixed>>, casosPorUnidade: array<string, list<int>>, unidades: list<string>}
     */
    private function indexarSistema(\App\Cobranca\Entity\Carteira $carteira, \DateTimeImmutable $dadosAte): array
    {
        $porChave = [];
        $casosPorUnidade = [];

        foreach ($this->obrigacoes->emAbertoDaCarteiraAte($carteira, $dadosAte) as $linha) {
            [$identificacao] = IdentificacaoDaUnidade::separar($linha['unidade']);

            $chave = $this->chave($identificacao, $linha['referenciaExterna'] ?? '', $linha['competencia']);
            $porChave[$chave] = ['unidade' => $identificacao] + $linha;

            $casosPorUnidade[$identificacao][$linha['casoId']] = true;
        }

        return [
            'porChave' => $porChave,
            'casosPorUnidade' => array_map(static fn (array $c): array => array_keys($c), $casosPorUnidade),
            'unidades' => array_keys($casosPorUnidade),
        ];
    }

    /**
     * Por que a dívida falta — três causas diferentes, com três consertos diferentes (SPEC §5.5).
     * Somá-las num balde só esconderia qual é.
     *
     * @param array{casosPorUnidade: array<string, list<int>>, unidades: list<string>, porChave: array<string, mixed>} $sistema
     */
    private function motivoDaFalta(string $unidade, array $sistema): string
    {
        if (!in_array($unidade, $sistema['unidades'], true)) {
            return 'unidade sem obrigação em aberto no sistema';
        }

        return 'unidade existe, esta dívida não';
    }

    private function ehParcelaDeAcordo(?string $acordoTexto): bool
    {
        return $acordoTexto !== null && preg_match(self::PADRAO_ACORDO, $acordoTexto) === 1;
    }

    /** `1.1 - Taxa de condomínio` → `1.1`. Compara o código INTEIRO: `1.1` não pode casar `1.14`. */
    private function codigoDaClasse(?string $classe): string
    {
        if ($classe === null) {
            return '';
        }

        $partes = preg_split('/\s*-\s*/', trim($classe), 2);

        return $partes === false ? '' : trim($partes[0]);
    }

    private function chave(string $unidade, string $referencia, ?string $competencia): string
    {
        return $unidade . '|' . $referencia . '|' . ($competencia ?? '');
    }
}
