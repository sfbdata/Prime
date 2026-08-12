<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\Entity\RelatorioImportado;
use App\Cobranca\Repository\RelatorioLinhaRepository;
use App\Cobranca\Service\Importacao\IdentificacaoDaUnidade;
use App\Cobranca\Service\Importacao\ReferenciaSubstituta;

/**
 * Junta as linhas do espelho em BOLETOS, do jeito que a contabilidade os cobra
 * (SPEC docs/specs/cobranca-espelho-da-contabilidade.md §5.2 e §5.3).
 *
 * Existe num lugar só porque a conferência e a calibração precisam do MESMO agrupamento, e a regra
 * de ramo é regra de dinheiro: duas cópias divergem em silêncio — foi a lição do D10, quando a régua
 * de exigibilidade apareceu copiada verbatim em dois repositórios.
 *
 * 🔑 INV-A1 — **A ARMADILHA.** O principal depende do ramo:
 *   - **boleto comum**: Σ da coluna Valor apenas das classes 1.1 / 1.14 / 1.6;
 *   - **parcela de acordo**: Σ da coluna Valor de TODAS as classes.
 *
 * Igualar os dois — fazer o Σ cru fechar também no boleto comum — reintroduz no caminho normal a
 * dupla contagem que a Fase 1 existe para matar. Medido no TL1 de 12/08: com a regra por ramo a soma
 * dá 43.681.747, idêntica ao `valor_original` do sistema.
 *
 * INV-A2 — **classifica sem reusar o adapter.** O ramo sai da coluna do acordo, que o espelho guarda
 * como texto cru. Reusar o `TopLifeInadimplenciaAdapter` faria a conferência herdar os defeitos de
 * quem ela precisa conferir. A chave, essa sim, é a do banco ({@see ReferenciaSubstituta}) — não uma
 * segunda definição.
 */
final class AgrupadorDeBoletos
{
    /** O `1.6` é desconto (valor negativo) e entra somando, como no importador. */
    private const CLASSES_DE_PRINCIPAL = ['1.1', '1.14', '1.6'];

    /**
     * O que caracteriza uma parcela de acordo na coluna "Informações do acordo".
     *
     * Escrito aqui de propósito (INV-A2). `LeitorEspelhoRegexAcordoTest` confronta este padrão com o
     * do adapter contra as strings reais, para que a duplicação não vire divergência silenciosa.
     */
    private const PADRAO_ACORDO = '/Acordo\s+(\d+)\s*-\s*Parc\.\s*(\d+)\s*\/\s*(\d+)/iu';

    public function __construct(private readonly RelatorioLinhaRepository $linhas)
    {
    }

    /**
     * @return array<string, array{unidade: string, nn: ?string, competencia: ?string,
     *                             vencimento: ?\DateTimeImmutable, ehAcordo: bool, principal: int,
     *                             juros: int, multa: int, correcao: int, honorarios: int}>
     */
    public function agrupar(RelatorioImportado $relatorio): array
    {
        $grupos = [];
        $linhasPorGrupo = [];

        foreach ($this->linhasComChave($relatorio) as ['chave' => $chave, 'linha' => $linha]) {
            [$identificacao] = IdentificacaoDaUnidade::separar((string) $linha['unidade']);

            $grupos[$chave] ??= [
                'unidade' => $identificacao,
                'nn' => $linha['nn'],
                'competencia' => $linha['competencia'],
                'vencimento' => $linha['vencimento'],
                'ehAcordo' => false,
                'principal' => 0,
                'juros' => 0,
                'multa' => 0,
                'correcao' => 0,
                'honorarios' => 0,
            ];

            if ($this->ehParcelaDeAcordo($linha['acordoTexto'])) {
                $grupos[$chave]['ehAcordo'] = true;
            }

            $grupos[$chave]['vencimento'] ??= $linha['vencimento'];
            $grupos[$chave]['juros'] += $linha['juros'] ?? 0;
            $grupos[$chave]['multa'] += $linha['multa'] ?? 0;
            $grupos[$chave]['correcao'] += $linha['correcao'] ?? 0;
            $grupos[$chave]['honorarios'] += $linha['honorarios'] ?? 0;

            $linhasPorGrupo[$chave][] = [
                'classe' => $this->codigoDaClasse($linha['classe']),
                'valor' => $linha['valor'] ?? 0,
            ];
        }

        // INV-A1 — só com o grupo inteiro montado dá para saber o ramo e somar o principal certo.
        foreach ($grupos as $chave => $grupo) {
            $principal = 0;

            foreach ($linhasPorGrupo[$chave] ?? [] as $linha) {
                if ($grupo['ehAcordo'] || in_array($linha['classe'], self::CLASSES_DE_PRINCIPAL, true)) {
                    $principal += $linha['valor'];
                }
            }

            $grupos[$chave]['principal'] = $principal;
        }

        return $grupos;
    }

    /**
     * As linhas de dado do lote, cada uma já com a chave do boleto a que pertence — e **só** as que
     * têm chave derivável (unidade preenchida e referência obtível).
     *
     * Existe porque a calibração precisa das linhas UMA A UMA (SPEC §6.4: *"para cada linha do
     * espelho"*) enquanto a conferência precisa delas somadas por boleto, e as duas têm de concordar
     * sobre **qual linha pertence a qual boleto**. Derivar a chave em dois lugares seria uma segunda
     * régua de casamento — a mesma classe de defeito que o D10 fechou do lado da exigibilidade.
     *
     * @return list<array{chave: string, linha: array{unidade: ?string, nn: ?string, classe: ?string,
     *                    competencia: ?string, vencimento: ?\DateTimeImmutable, valor: ?int,
     *                    juros: ?int, multa: ?int, correcao: ?int, honorarios: ?int,
     *                    acordoTexto: ?string}}>
     */
    public function linhasComChave(RelatorioImportado $relatorio): array
    {
        $comChave = [];

        foreach ($this->linhas->dadosDoRelatorio($relatorio) as $linha) {
            if ($linha['unidade'] === null) {
                continue;
            }

            [$identificacao] = IdentificacaoDaUnidade::separar($linha['unidade']);
            $referencia = $this->referencia($linha);

            if ($referencia === null) {
                continue;
            }

            $comChave[] = [
                'chave' => self::chave($identificacao, $referencia, $linha['competencia']),
                'linha' => $linha,
            ];
        }

        return $comChave;
    }

    /** A chave de dedup do banco: unidade + referência + competência. */
    public static function chave(string $unidade, string $referencia, ?string $competencia): string
    {
        return $unidade . '|' . $referencia . '|' . ($competencia ?? '');
    }

    /** Só para o teste que confronta este padrão com o do adapter. */
    public static function padraoDeAcordo(): string
    {
        return self::PADRAO_ACORDO;
    }

    /** @param array{nn: ?string, vencimento: ?\DateTimeImmutable} $linha */
    private function referencia(array $linha): ?string
    {
        $nn = $linha['nn'];

        if ($nn !== null && trim($nn) !== '' && trim($nn) !== '-') {
            return $nn;
        }

        // Boleto que nunca teve número entra pela referência sintética do importador.
        return $linha['vencimento'] === null ? null : ReferenciaSubstituta::para($linha['vencimento']);
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
}
