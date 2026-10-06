<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\DTO\DeterminacaoDoJuizoOutput;
use App\Pasta\DTO\DocumentoSugeridoOutput;
use App\Pasta\DTO\LeituraDoJuizoOutput;
use App\Pasta\DTO\SugestaoDeDocumentosOutput;

/**
 * "Documentos sugeridos" da aba Documentos: a partir da ação, da classe do processo, dos documentos
 * enviados e do checklist da pasta, diz quais documentos o catálogo espera e quais FALTAM.
 *
 * Serviço puro: recebe dados simples, não consulta banco, não chama modelo de linguagem. É a regra
 * de `bj-docsug.js` (`analisar`, L86-128). O "an" do desenho (análise do processo) aqui é só o que
 * `DeterminacoesDoJuizo` leu POR REGRAS no teor das publicações do Push, quando houver: dele saem o
 * "Exigido pelo juízo" (L93), o "Processo: …" do já existente (L66) e os "Prazos em curso". A fase
 * continua vindo da classe processual — não há fase lida das movimentações.
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
 *  7. Faltantes = exigidos pelo juízo, obrigatórios e recomendáveis que não estão na pasta nem no
 *     checklist (L4075).
 *  8. Exigido pelo juízo (L93): cada tipo do catálogo que uma determinação lida manda juntar entra
 *     PRIMEIRO, com o trecho da decisão no porquê e a origem; a determinação mais recente vence.
 *     Já localizado (na pasta ou no processo) vira "já existente", como no desenho. As regras de
 *     "não aplicável" (5) não rebaixam o que o juízo exigiu.
 *
 * Sem ação e sem classe processual não há base para escolher catálogo: devolve `semCatalogo()`.
 */
final class SugestorDeDocumentos
{
    private const SIMILARIDADE_MINIMA_NO_CHECKLIST = 0.6;

    /** bj-docsug.js L116 (`ord`). */
    private const ORDEM = [
        DocumentoSugeridoOutput::EXIGIDO_PELO_JUIZO => -1,
        DocumentoSugeridoOutput::OBRIGATORIO        => 0,
        DocumentoSugeridoOutput::RECOMENDAVEL       => 1,
        DocumentoSugeridoOutput::OPCIONAL           => 2,
        DocumentoSugeridoOutput::JA_EXISTE          => 3,
        DocumentoSugeridoOutput::NAO_APLICAVEL      => 4,
    ];

    /**
     * @param list<array{titulo: string, nomeOriginal: string, categoria: string, data: string}> $arquivos
     * @param list<array{titulo: string, concluido: bool}>                                         $checklist
     * @param ?LeituraDoJuizoOutput                                                                $leitura   o que foi lido no Push (null = nada)
     * @param ?\DateTimeImmutable                                                                  $hoje      para "Prazos em curso" (null = não mostra)
     */
    public function sugerir(
        ?string $acao,
        ?string $classe,
        ?string $numeroProcesso,
        array $arquivos,
        array $checklist,
        ?LeituraDoJuizoOutput $leitura = null,
        ?\DateTimeImmutable $hoje = null,
    ): SugestaoDeDocumentosOutput {
        $leitura ??= LeituraDoJuizoOutput::nada();
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

        // Com teor do Push lido, "sem leitura do processo" deixaria de ser verdade: o que continua
        // sem leitura é a FASE, que segue vindo da classe processual.
        if ($leitura->publicacoesLidas > 0) {
            $base = $fase === 'cumprimento'
                ? 'pela classe processual; a fase não é lida das publicações'
                : 'presumida: a fase não é lida das publicações, vale a lista da fase inicial';
        }

        /** @var array<string, DeterminacaoDoJuizoOutput> $exigidos chave => determinação (a mais recente vence) */
        $exigidos = [];
        foreach ($leitura->determinacoes as $determinacao) {
            foreach ($determinacao->documentos as $chave) {
                $exigidos[$chave] ??= $determinacao;
            }
        }

        /** @var array<string, array{0: string, 1: string}> $pedidos chave => [classe padrão, porquê] */
        $pedidos = [];
        foreach ($exigidos as $chave => $determinacao) {
            // L93: 'Determinação expressa do juízo' + prazo + trecho (160 caracteres, L72).
            $prazo           = $determinacao->prazoTexto();
            $pedidos[$chave] = [
                DocumentoSugeridoOutput::EXIGIDO_PELO_JUIZO,
                'Determinação expressa do juízo' . ($prazo !== null ? ', ' . $prazo : '')
                    . ': "' . mb_substr($determinacao->trecho, 0, 160) . '"',
            ];
        }

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
            $localizado = $this->localizar($chave, $arquivos, $leitura);
            $status     = $localizado !== [] ? DocumentoSugeridoOutput::JA_EXISTE : $classePadrao;

            if ($localizado === []
                && $classePadrao !== DocumentoSugeridoOutput::EXIGIDO_PELO_JUIZO
                && ($chave === 'pagamento' || $chave === 'acordao' || ($chave === 'preparo' && $falaEmGratuidade))
            ) {
                $status = DocumentoSugeridoOutput::NAO_APLICAVEL;
            }

            $noChecklist = $this->itemDoChecklist($nome, $checklist);
            $origem      = $exigidos[$chave] ?? null;

            $itens[] = new DocumentoSugeridoOutput(
                $chave,
                $nome,
                $status,
                $porque,
                $localizado,
                $noChecklist !== null,
                $noChecklist !== null && $noChecklist['concluido'],
                $origem?->publicacaoId,
                $origem?->origemTexto(),
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
            $leitura->publicacoesLidas,
            self::prazosEmCurso($leitura, $hoje),
        );
    }

    /**
     * "Prazos em curso" (dc L2134): só prazo EXPLÍCITO no teor que certamente ainda corre hoje
     * (ver `DeterminacaoDoJuizoOutput::prazoCertamenteEmCursoEm`). Sem `hoje`, nada.
     *
     * @return list<string>
     */
    private static function prazosEmCurso(LeituraDoJuizoOutput $leitura, ?\DateTimeImmutable $hoje): array
    {
        if ($hoje === null) {
            return [];
        }

        $linhas = [];
        foreach ($leitura->determinacoes as $determinacao) {
            if ($determinacao->prazoCertamenteEmCursoEm($hoje)) {
                $linhas[$determinacao->linhaDePrazo()] = true;
            }
        }

        return array_keys($linhas);
    }

    /** Minúsculas e sem acento — `norm` de bj-docsug.js L9 (a regra mora em {@see SimilaridadeDeNomes}). */
    public static function normalizar(string $texto): string
    {
        return SimilaridadeDeNomes::normalizar($texto);
    }

    /**
     * Palavras em comum ÷ tamanho do menor conjunto — `similar` de bj-docsug.js L62 (a regra mora
     * em {@see SimilaridadeDeNomes}).
     */
    public static function similaridade(string $a, string $b): float
    {
        return SimilaridadeDeNomes::similaridade($a, $b);
    }

    /**
     * @param list<array{titulo: string, nomeOriginal: string, categoria: string, data: string}> $arquivos
     *
     * @return list<string>
     */
    private function localizar(string $chave, array $arquivos, LeituraDoJuizoOutput $leitura): array
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

        // L66: o documento também "existe" quando uma publicação do processo É dele (pelo tipo).
        foreach ($leitura->documentosDoProcesso as $doProcesso) {
            if ($doProcesso['chave'] === $chave && !\in_array($doProcesso['rotulo'], $localizado, true)) {
                $localizado[] = $doProcesso['rotulo'];
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

    private static function vazioParaNulo(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }

        $texto = trim($texto);

        return $texto === '' ? null : $texto;
    }
}
