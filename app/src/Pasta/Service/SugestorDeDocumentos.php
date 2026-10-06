<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\DTO\DocumentoSugeridoOutput;
use App\Pasta\DTO\SugestaoDeDocumentosOutput;

/**
 * "Documentos sugeridos" da aba Documentos: a partir da ação, da classe do processo, dos documentos
 * enviados e do checklist da pasta, diz quais documentos o catálogo espera e quais FALTAM.
 *
 * Serviço puro: recebe dados simples, não consulta banco, não lê o processo, não chama modelo de
 * linguagem. É a regra de `bj-docsug.js` (`analisar`, L86-128) SEM a parte que depende da análise do
 * processo (`an`): sem `an` não há "exigido pelo juízo", não há fase lida das movimentações, e a
 * confiança do desenho seria sempre "Baixa".
 *
 * REGRA DE CASAMENTO (a mesma do JS, com o que o sistema tem a mais):
 *
 *  1. Normalização: minúsculas e sem acento (NFD sem as marcas U+0300–U+036F) — `norm` do JS, L9.
 *     Os setters das entidades gravam em MAIÚSCULAS; a normalização torna isso irrelevante.
 *  2. Fase: classe processual que casa `/cumprimento|execu/` → Cumprimento de sentença; qualquer
 *     outra → Fase inicial (presumida). É o ramo `!an` de `faseDe`, L52-61.
 *  3. Lista: documentos da fase (`FASE[fase]`, L40-50) e, depois, os do tipo de ação casados em
 *     "ação + classe" (L103-106). Uma chave entra uma vez só (`vistos`, L91).
 *  4. "Já existente": o padrão do tipo (`CAT`, L12-38) casa o TÍTULO ou o NOME DO ARQUIVO original
 *     de algum documento da pasta (cada um isoladamente, como o JS testa cada `f.nome`, L65), OU a
 *     categoria escolhida no upload aponta para o tipo (`CatalogoDeDocumentos::POR_CATEGORIA_DO_UPLOAD`).
 *  5. "Não aplicável" (L95-98): acórdão e comprovante de pagamento só fazem sentido com a leitura do
 *     processo — sem ela ficam "não aplicável", a não ser que já estejam na pasta; preparo fica "não
 *     aplicável" quando algum arquivo fala em gratuidade/hipossuficiência.
 *  6. "Já no checklist": algum item do checklist tem similaridade ≥ 0,6 com o NOME do documento
 *     (`similar`, L62; `ckTem`, L90): palavras de 3+ letras, sem extensão, sem números e sem
 *     "assinado/final/cópia/versão/vN"; similaridade = palavras em comum ÷ tamanho do MENOR conjunto.
 *  7. Faltantes = obrigatórios e recomendáveis que não estão na pasta nem no checklist (L4075).
 *
 * Sem ação e sem classe processual não há base para escolher catálogo: devolve `semCatalogo()`.
 */
final class SugestorDeDocumentos
{
    private const SIMILARIDADE_MINIMA_NO_CHECKLIST = 0.6;

    /** bj-docsug.js L116 (`ord`), sem o "juizo". */
    private const ORDEM = [
        DocumentoSugeridoOutput::OBRIGATORIO   => 0,
        DocumentoSugeridoOutput::RECOMENDAVEL  => 1,
        DocumentoSugeridoOutput::OPCIONAL      => 2,
        DocumentoSugeridoOutput::JA_EXISTE     => 3,
        DocumentoSugeridoOutput::NAO_APLICAVEL => 4,
    ];

    /**
     * @param list<array{titulo: string, nomeOriginal: string, categoria: string, data: string}> $arquivos
     * @param list<array{titulo: string, concluido: bool}>                                         $checklist
     */
    public function sugerir(
        ?string $acao,
        ?string $classe,
        ?string $numeroProcesso,
        array $arquivos,
        array $checklist,
    ): SugestaoDeDocumentosOutput {
        $acao   = self::vazioParaNulo($acao);
        $classe = self::vazioParaNulo($classe);

        if ($acao === null && $classe === null) {
            return SugestaoDeDocumentosOutput::semCatalogo();
        }

        $fase = $classe !== null && preg_match(CatalogoDeDocumentos::CLASSE_DE_CUMPRIMENTO, self::normalizar($classe)) === 1
            ? 'cumprimento'
            : 'inicial';

        $base = $fase === 'cumprimento'
            ? 'pela classe processual, sem leitura do processo'
            : 'presumida: sem leitura do processo, vale a lista da fase inicial';

        /** @var array<string, array{0: string, 1: string}> $pedidos chave => [classe padrão, porquê] */
        $pedidos = [];
        foreach (CatalogoDeDocumentos::FASES[$fase] as [$chave, $classePadrao, $porque]) {
            // O desenho põe "(Fase X)" só no que é costume da fase; o obrigatório vem de lei (dsVals, L4030).
            $pedidos[$chave] ??= [
                $classePadrao,
                $classePadrao === CatalogoDeDocumentos::OBRIGATORIO
                    ? $porque
                    : $porque . ' (' . CatalogoDeDocumentos::NOMES_DAS_FASES[$fase] . ')',
            ];
        }

        $textoDaAcao = self::normalizar(($acao ?? '') . ' ' . ($classe ?? ''));
        foreach (CatalogoDeDocumentos::POR_TIPO_DE_ACAO as [$padrao, $chave, $classePadrao, $porque]) {
            if (preg_match($padrao, $textoDaAcao) === 1) {
                $pedidos[$chave] ??= [$classePadrao, $porque];
            }
        }

        $falaEmGratuidade = false;
        foreach ($arquivos as $arquivo) {
            if (preg_match('/gratuit|hipossuf/u', self::normalizar($arquivo['titulo'] . ' ' . $arquivo['nomeOriginal'])) === 1) {
                $falaEmGratuidade = true;
                break;
            }
        }

        $itens = [];
        foreach ($pedidos as $chave => [$classePadrao, $porque]) {
            $nome       = CatalogoDeDocumentos::TIPOS[$chave][0];
            $localizado = $this->localizar($chave, $arquivos);
            $status     = $localizado !== [] ? DocumentoSugeridoOutput::JA_EXISTE : $classePadrao;

            if ($localizado === []
                && ($chave === 'pagamento' || $chave === 'acordao' || ($chave === 'preparo' && $falaEmGratuidade))
            ) {
                $status = DocumentoSugeridoOutput::NAO_APLICAVEL;
            }

            $noChecklist = $this->itemDoChecklist($nome, $checklist);

            $itens[] = new DocumentoSugeridoOutput(
                $chave,
                $nome,
                $status,
                $porque,
                $localizado,
                $noChecklist !== null,
                $noChecklist !== null && $noChecklist['concluido'],
            );
        }

        usort($itens, static fn (DocumentoSugeridoOutput $a, DocumentoSugeridoOutput $b): int => self::ORDEM[$a->status] <=> self::ORDEM[$b->status]);

        return new SugestaoDeDocumentosOutput(
            true,
            $fase,
            CatalogoDeDocumentos::NOMES_DAS_FASES[$fase],
            $base,
            $acao,
            $classe,
            self::vazioParaNulo($numeroProcesso),
            $itens,
        );
    }

    /** Minúsculas e sem acento — `norm` de bj-docsug.js L9. */
    public static function normalizar(string $texto): string
    {
        $decomposto = \Normalizer::normalize($texto, \Normalizer::FORM_D);
        if ($decomposto === false) {
            $decomposto = $texto;
        }

        return mb_strtolower((string) preg_replace('/[\x{0300}-\x{036f}]/u', '', $decomposto));
    }

    /**
     * Palavras em comum ÷ tamanho do menor conjunto — `similar` de bj-docsug.js L62.
     */
    public static function similaridade(string $a, string $b): float
    {
        $palavrasA = self::palavras($a);
        $palavrasB = self::palavras($b);

        if ($palavrasA === [] || $palavrasB === []) {
            return 0.0;
        }

        $comuns = \count(array_intersect_key($palavrasA, $palavrasB));

        return $comuns / min(\count($palavrasA), \count($palavrasB));
    }

    /**
     * @param list<array{titulo: string, nomeOriginal: string, categoria: string, data: string}> $arquivos
     *
     * @return list<string>
     */
    private function localizar(string $chave, array $arquivos): array
    {
        $padrao     = CatalogoDeDocumentos::TIPOS[$chave][1];
        $localizado = [];

        foreach ($arquivos as $arquivo) {
            $casou = preg_match($padrao, self::normalizar($arquivo['titulo'])) === 1
                || preg_match($padrao, self::normalizar($arquivo['nomeOriginal'])) === 1
                || (CatalogoDeDocumentos::POR_CATEGORIA_DO_UPLOAD[$arquivo['categoria']] ?? null) === $chave;

            if ($casou) {
                $rotulo       = $arquivo['titulo'] !== '' ? $arquivo['titulo'] : $arquivo['nomeOriginal'];
                $localizado[] = 'Pasta: ' . $rotulo . ($arquivo['data'] !== '' ? ' · ' . $arquivo['data'] : '');
            }
        }

        return $localizado;
    }

    /**
     * @param list<array{titulo: string, concluido: bool}> $checklist
     *
     * @return array{titulo: string, concluido: bool}|null
     */
    private function itemDoChecklist(string $nome, array $checklist): ?array
    {
        foreach ($checklist as $item) {
            if (self::similaridade($item['titulo'], $nome) >= self::SIMILARIDADE_MINIMA_NO_CHECKLIST) {
                return $item;
            }
        }

        return null;
    }

    /** @return array<string, true> */
    private static function palavras(string $texto): array
    {
        $semExtensao = (string) preg_replace('/\.\w+$/u', '', self::normalizar($texto));
        $palavras    = [];

        foreach (preg_split('/[^a-z0-9]+/', $semExtensao) ?: [] as $palavra) {
            if (\strlen($palavra) > 2 && preg_match('/^(\d+|assinad[oa]|final|copia|versao|v\d)$/', $palavra) !== 1) {
                $palavras[$palavra] = true;
            }
        }

        return $palavras;
    }

    private static function vazioParaNulo(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }

        $texto = trim($texto);

        return $texto === '' ? null : $texto;
    }
}
