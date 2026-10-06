<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\DeterminacaoDoJuizoOutput;
use App\Pasta\DTO\LeituraDoJuizoOutput;
use App\Pasta\DTO\TeorDePublicacaoInput;
use App\Pasta\Entity\Pasta;

/**
 * "Exigido pelo juízo" (DOC-79, D10 da aba Documentos): lê POR REGRAS o teor das publicações do
 * Push Processual da pasta e devolve as determinações do juízo — e, entre elas, os documentos que
 * a decisão manda juntar.
 *
 * Não é inteligência artificial e a tela não diz que é (S-4): é a regra de `bj-processo.js`
 * (`VERBOS`, `destinatario`, `ATOS`, `determinacoes`, L140-169) e de `bj-docsug.js` (`exigidos`,
 * L67-75), transcrita. A camada de IA do desenho (`iaDets`, `refinarIA`) fica de fora.
 *
 * REGRA (sobre o texto normalizado: minúsculas, sem acento, sem marcação HTML):
 *
 *  1. Só publicação de tipo que carrega determinação — despacho, decisão, sentença, acórdão,
 *     intimação, citação, ata de audiência, mandado — pelo tipo do documento OU da comunicação
 *     (`determinacoes`, L162).
 *  2. O teor é quebrado em frases (fim em `.`, `;` ou `:` seguido de maiúscula ou dígito) de 9 a
 *     899 caracteres (L163).
 *  3. Frase sem verbo de determinação (`VERBOS`, L140) e sem a palavra "prazo" é ignorada (L165).
 *  4. A frase vira determinação quando é "conclusos", designação de audiência, nomeação de perito,
 *     fala de um prazo em dias/horas (o `prazoDe` do desenho, L150-155, usado só como gatilho), ou
 *     tem destinatário (autor, réu, partes, perito, cartório) E um ato reconhecido (`ATOS`, L160)
 *     — L166-171. O destinatário pode vir da frase anterior quando a frase é de intimação ou
 *     manifestação (L167).
 *  5. PRAZO EXIBIDO, só o que o texto diz com todas as letras: "prazo [comum|legal|sucessivo|
 *     improrrogável] de [até] N [(extenso)] dias|horas [úteis|corridos]", com N em algarismos ou
 *     por extenso (as palavras de `NUM`, L139). DIVERGÊNCIA CONSCIENTE do desenho: "em 15 dias" e
 *     "dentro de 15 dias" qualificam a frase (regra 4) mas não viram prazo na tela, e não há prazo
 *     legal presumido (citação → 15 dias, sentença → apelação, L174-175) — decisão do lote: prazo
 *     só quando explícito, nada inventado.
 *  6. Documento exigido (`exigidos`, L67-75): a determinação cujo "ato + trecho" (trecho = os
 *     primeiros 220 caracteres da frase, como no desenho) tem verbo documental (apresent, junt,
 *     traga, comprov, exib, acost, anex, regulariz) e casa o padrão de um tipo do
 *     `CatalogoDeDocumentos::TIPOS`.
 *
 * ISOLAMENTO: `daPasta` só lê publicações do ESCRITÓRIO da sessão cujo número CNJ é o de um dos
 * processos DESTA pasta — o mesmo casamento por número da aba Push (a FK `processo` da publicação
 * só existe depois da sincronização). Pasta de outro escritório, ou sessão sem escritório, não lê
 * nada.
 */
final class DeterminacoesDoJuizo
{
    /** Mesmo teto da aba Push Processual (`PastaController::PUSH_LIMITE`). */
    public const LIMITE_DE_PUBLICACOES = 100;

    /** Quanto da frase vai para o trecho exibido e para o teste de documento (L171, L72). */
    private const TAMANHO_DO_TRECHO = 220;

    /** L162: tipos de documento que carregam determinação. Comparados pelo começo, normalizados. */
    private const TIPOS_COM_DETERMINACAO = '/^(despacho|decis|senten|acordao|intima|cita|ata de audiencia|mandado)/u';

    /** L140 (`VERBOS`). */
    private const VERBOS = '/(intime-se|intimem-se|intime|manifeste-se|manifestem-se|especifique[m]?|apresente[m]?|junte[m]?|indique[m]?|comprove|recolha|emende|cumpra|cite-se|designo|nomeio|vista [àa]s? partes?|d[eê]-se vista|voltem conclusos|retornem conclusos|fa[cç]am-se conclusos|aguarde-se|cumpra-se|providencie|regularize|deposite|pague|efetue)/u';

    /** L139 (`NUM`). */
    private const NUMEROS_POR_EXTENSO = [
        'um' => 1, 'uma' => 1, 'dois' => 2, 'duas' => 2, 'tres' => 3, 'quatro' => 4, 'cinco' => 5,
        'seis' => 6, 'sete' => 7, 'oito' => 8, 'dez' => 10, 'doze' => 12, 'quinze' => 15,
        'vinte' => 20, 'trinta' => 30, 'sessenta' => 60, 'noventa' => 90,
    ];

    /** Regra 5 acima: "prazo de N dias" explícito. Grupos: N, unidade, contagem. */
    private const PRAZO_EXPLICITO = '/\bprazo (?:comum |legal |sucessivo |improrrogavel )?de (?:ate )?(\d{1,3}|um|uma|dois|duas|tres|quatro|cinco|seis|sete|oito|dez|doze|quinze|vinte|trinta|sessenta|noventa)\b(?:\s*\(\s*[a-z ]+\s*\))?\s*(dias?|horas?)\b(\s+uteis|\s+corridos)?/u';

    /** L151-152 (`prazoDe`): só decide se a frase FALA de prazo (regra 4); não é o prazo exibido. */
    private const FALA_DE_PRAZO = '/(?:prazo|no prazo|em|dentro de)[^.]{0,25}?\b(?:\d{1,3}|um|uma|dois|duas|tres|quatro|cinco|seis|sete|oito|dez|doze|quinze|vinte|trinta|sessenta|noventa)\s*(?:\(\s*[a-z ]+\))?\s*(?:dias?|horas?)/u';

    /** L160 (`ATOS`): [padrão, rótulo]. A ordem decide: o primeiro que casar é o ato. */
    private const ATOS = [
        ['/replica|manifest\w+ sobre a contesta|impugna\w+ a contesta/u', 'Réplica'],
        ['/especifi\w+ (as )?provas/u', 'Especificação de provas'],
        ['/quesitos|assistente tecnico/u', 'Quesitos / assistente técnico'],
        ['/(junte|apresente|traga)\w* .{0,40}documento/u', 'Juntada de documentos'],
        ['/emend\w+ (a )?inicial/u', 'Emenda da inicial'],
        ['/contest(ar|e|acao)|apresent\w+ defesa|responda/u', 'Contestação'],
        ['/custas|preparo|recolh/u', 'Recolhimento de custas'],
        ['/pague|pagamento|deposit/u', 'Pagamento / depósito'],
        ['/contrarraz/u', 'Contrarrazões'],
        ['/sobre o laudo|manifest\w+ .{0,30}laudo/u', 'Manifestação sobre o laudo'],
        ['/manifest/u', 'Manifestação'],
    ];

    /** L72: verbo que pede documento. */
    private const VERBO_DOCUMENTAL = '/(apresent|junt|traga|comprov|exib|acost|anex|regulariz)/u';

    public function __construct(
        private readonly PublicacaoDjenRepository $publicacoes,
    ) {
    }

    /**
     * Lê as publicações dos processos DESTA pasta, do escritório da sessão.
     */
    public function daPasta(Pasta $pasta, ?Tenant $tenant): LeituraDoJuizoOutput
    {
        $tenantId = $tenant?->getId();
        if ($tenant === null || $tenantId === null || $pasta->getTenant()?->getId() !== $tenantId) {
            return LeituraDoJuizoOutput::nada();
        }

        $numeros = [];
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            $processo = $vinculo->getProcesso();
            // Processo de outro escritório pendurado na pasta não abre a porta para as publicações dele.
            if ($processo->getTenant()?->getId() !== $tenantId) {
                continue;
            }

            // A publicação grava o CNJ só com dígitos; o processo pode estar mascarado.
            $digitos = preg_replace('/\D+/', '', (string) $processo->getNumeroProcesso()) ?? '';
            if ($digitos !== '') {
                $numeros[$digitos] = true;
            }
        }

        if ($numeros === []) {
            return LeituraDoJuizoOutput::nada();
        }

        /** @var list<PublicacaoDjen> $encontradas */
        $encontradas = $this->publicacoes->findBy(
            ['tenant' => $tenant, 'numeroProcesso' => array_map('strval', array_keys($numeros))],
            ['dataDisponibilizacao' => 'DESC', 'id' => 'DESC'],
            self::LIMITE_DE_PUBLICACOES,
        );

        $teores = [];
        foreach ($encontradas as $publicacao) {
            if ($publicacao->getTenant()?->getId() !== $tenantId) {
                continue;
            }

            $teores[] = new TeorDePublicacaoInput(
                (int) $publicacao->getId(),
                $publicacao->getTipoDocumento(),
                $publicacao->getTipoComunicacao(),
                $publicacao->getDataDisponibilizacao(),
                $publicacao->getNumeroComunicacao(),
                (string) $publicacao->getTexto(),
            );
        }

        return $this->ler($teores);
    }

    /**
     * A regra, sem banco: os teores, na ordem em que devem aparecer (mais recente primeiro).
     *
     * @param list<TeorDePublicacaoInput> $teores
     */
    public function ler(array $teores): LeituraDoJuizoOutput
    {
        $determinacoes = [];
        $doProcesso    = [];
        $lidas         = 0;

        foreach ($teores as $teor) {
            // "Lida" = havia texto de verdade; teor vazio ou só marcação não conta como leitura.
            if (self::textoCorrido($teor->texto) !== '') {
                ++$lidas;
            }

            foreach ($this->determinacoesDoTeor($teor) as $determinacao) {
                $determinacoes[] = $determinacao;
            }

            foreach ($this->documentosPeloTipo($teor) as $documento) {
                $doProcesso[] = $documento;
            }
        }

        return new LeituraDoJuizoOutput($lidas, $determinacoes, $doProcesso);
    }

    /** @return list<DeterminacaoDoJuizoOutput> */
    public function determinacoesDoTeor(TeorDePublicacaoInput $teor): array
    {
        if (!$this->tipoCarregaDeterminacao($teor)) {
            return [];
        }

        $frases = self::frases($teor->texto);
        $saida  = [];
        $vistos = [];

        foreach ($frases as $i => $frase) {
            $x = SugestorDeDocumentos::normalizar($frase);
            if (preg_match(self::VERBOS, $x) !== 1 && !str_contains($x, 'prazo')) {
                continue;
            }

            $prazo = self::prazo($x);
            $dest  = self::destinatario($x);
            if ($dest === null && $i > 0 && preg_match('/intim|manifest|especifiq|apresent/u', $x) === 1) {
                $dest = self::destinatario(SugestorDeDocumentos::normalizar($frases[$i - 1]));
            }

            $ato = null;
            foreach (self::ATOS as [$padrao, $rotulo]) {
                if (preg_match($padrao, $x) === 1) {
                    $ato = $rotulo;
                    break;
                }
            }

            $especial = match (true) {
                preg_match('/(voltem|retornem|facam-se) conclusos/u', $x) === 1                              => 'Autos conclusos',
                preg_match('/designo .{0,30}audiencia|audiencia .{0,30}(para o dia|designada)/u', $x) === 1 => 'Audiência',
                preg_match('/nomeio .{0,30}perit/u', $x) === 1                                               => 'Perícia',
                default                                                                                      => null,
            };

            $falaDePrazo = $prazo !== null || preg_match(self::FALA_DE_PRAZO, $x) === 1;
            if ($especial === null && !$falaDePrazo && ($dest === null || $ato === null)) {
                continue;
            }

            // L166-170: conclusos, audiência e perícia são registrados sem prazo, como no desenho.
            $atoFinal = $especial ?? $ato ?? 'Manifestação';
            if ($especial !== null) {
                $prazo = null;
            }

            $trecho = mb_substr($frase, 0, self::TAMANHO_DO_TRECHO);
            $chave  = $atoFinal . '|' . ($prazo[0] ?? '') . '|' . $trecho;
            if (isset($vistos[$chave])) {
                continue;
            }
            $vistos[$chave] = true;

            $saida[] = new DeterminacaoDoJuizoOutput(
                $teor->publicacaoId,
                $teor->rotuloDoTipo(),
                $teor->data,
                $teor->idDoDocumento,
                $atoFinal,
                $trecho,
                $prazo[0] ?? null,
                $prazo[1] ?? null,
                $prazo[2] ?? null,
                self::documentosExigidos($atoFinal . ' ' . $trecho),
            );
        }

        return $saida;
    }

    /**
     * Teor → frases, como `determinacoes` (L163), depois de tirar a marcação HTML que alguns
     * tribunais mandam (o teor do DJEN chega em texto corrido OU em HTML).
     *
     * @return list<string>
     */
    public static function frases(string $texto): array
    {
        $corrido = self::textoCorrido($texto);
        if ($corrido === '') {
            return [];
        }

        $frases = [];
        foreach (preg_split('/(?<=[.;:])\s+(?=[A-ZÁ-Ú0-9])/u', $corrido) ?: [] as $frase) {
            $frase   = trim($frase);
            $tamanho = mb_strlen($frase);
            if ($tamanho > 8 && $tamanho < 900) {
                $frases[] = $frase;
            }
        }

        return $frases;
    }

    /** Sem marcação HTML, entidades decodificadas, espaços (inclusive o não separável) colapsados. */
    private static function textoCorrido(string $texto): string
    {
        $semMarcacao = (string) preg_replace('#<\s*(br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>#i', "\n", $texto);
        $semMarcacao = html_entity_decode(strip_tags($semMarcacao), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $semMarcacao));
    }

    /**
     * @return array{0: int, 1: string, 2: ?string}|null [quantidade, 'dias'|'horas', 'uteis'|'corridos'|null]
     */
    private static function prazo(string $normalizada): ?array
    {
        if (preg_match(self::PRAZO_EXPLICITO, $normalizada, $m) !== 1) {
            return null;
        }

        $quantidade = ctype_digit($m[1]) ? (int) $m[1] : (self::NUMEROS_POR_EXTENSO[$m[1]] ?? 0);
        if ($quantidade <= 0) {
            return null;
        }

        $contagem = trim($m[3] ?? '');

        return [
            $quantidade,
            str_starts_with($m[2], 'hora') ? 'horas' : 'dias',
            $contagem === '' ? null : $contagem,
        ];
    }

    /** L141-148 (`destinatario`). */
    private static function destinatario(string $normalizada): ?string
    {
        return match (true) {
            preg_match('/(as partes|ambas as partes|partes,|partes para|intimem-se as partes|vista as partes)/u', $normalizada) === 1                    => 'partes',
            preg_match('/(parte autora|\bautor[a]?\b|requerente|exequente|reclamante|embargante|impetrante)/u', $normalizada) === 1                      => 'autor',
            preg_match('/(parte re|\bre\b|\breu\b|requerid[oa]|executad[oa]|reclamad[oa]|embargad[oa]|impetrad[oa])/u', $normalizada) === 1            => 'reu',
            preg_match('/perit[oa]/u', $normalizada) === 1                                                                                              => 'perito',
            preg_match('/(secretaria|cartorio|serventia|oficial de justica)/u', $normalizada) === 1                                                    => 'cartorio',
            default                                                                                                                                     => null,
        };
    }

    /**
     * L67-75 (`exigidos`): "ato + trecho" com verbo documental → os tipos do catálogo que casam.
     *
     * @return list<string>
     */
    private static function documentosExigidos(string $atoETrecho): array
    {
        $t = SugestorDeDocumentos::normalizar($atoETrecho);
        if (preg_match(self::VERBO_DOCUMENTAL, $t) !== 1) {
            return [];
        }

        $chaves = [];
        foreach (CatalogoDeDocumentos::TIPOS as $chave => [, $padrao]) {
            if (preg_match($padrao, $t) === 1) {
                $chaves[] = $chave;
            }
        }

        return $chaves;
    }

    /**
     * "Processo: Sentença (ID x) · dd/mm/aaaa" — o documento do processo reconhecido pelo TIPO da
     * publicação, como o `localizar` do desenho faz com `an.docs` (bj-docsug.js L66).
     *
     * @return list<array{chave: string, rotulo: string}>
     */
    private function documentosPeloTipo(TeorDePublicacaoInput $teor): array
    {
        $tipo = trim((string) $teor->tipoDocumento);
        if ($tipo === '') {
            return [];
        }

        $normalizado = SugestorDeDocumentos::normalizar($tipo);
        $rotulo      = 'Processo: ' . $tipo
            . ($teor->idDoDocumento !== null && $teor->idDoDocumento !== '' ? ' (ID ' . $teor->idDoDocumento . ')' : '')
            . ($teor->data !== null ? ' · ' . $teor->data->format('d/m/Y') : '');

        $achados = [];
        foreach (CatalogoDeDocumentos::TIPOS as $chave => [, $padrao]) {
            if (preg_match($padrao, $normalizado) === 1) {
                $achados[] = ['chave' => $chave, 'rotulo' => $rotulo];
            }
        }

        return $achados;
    }

    private function tipoCarregaDeterminacao(TeorDePublicacaoInput $teor): bool
    {
        foreach ([$teor->tipoDocumento, $teor->tipoComunicacao] as $tipo) {
            if ($tipo !== null && preg_match(self::TIPOS_COM_DETERMINACAO, SugestorDeDocumentos::normalizar(trim($tipo))) === 1) {
                return true;
            }
        }

        return false;
    }
}
