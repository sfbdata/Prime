<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Motor de conferência de PDF já assinado (contrato: `docs/specs/assinatura-documentos-etapa-5-motor.md`). Porta do
 * `analisar` e do `umaAss` do protótipo `bj-assina-pdf.js`: acha cada /ByteRange, confere a faixa, o resumo e a chave
 * de cada assinatura, lê o DocMDP e junta tudo num veredito com motivo. Também confere o prefixo com a versão anterior
 * e o CPF esperado do signatário.
 *
 * Sem estado e sem disco. Nenhuma exceção sai daqui: PDF que não dá para ler vira `ilegivel` com motivo. Confere só a
 * integridade e o vínculo do certificado com a assinatura: o titular é o que o certificado declara, e cadeia,
 * revogação e carimbo do tempo são da etapa 6.
 */
final class ConferenciaDePdf
{
    public const TIPO_CARIMBO_DO_TEMPO = 'Carimbo do tempo';

    public const MOTIVO_FAIXA_INVALIDA = 'Faixa de bytes inválida';
    public const MOTIVO_FAIXA_FORA_DO_TRECHO = 'Faixa de bytes inválida: a declaração da faixa fica fora do trecho assinado';
    public const MOTIVO_TRECHO_NAO_HEXADECIMAL = 'Faixa de bytes inválida: o trecho fora da faixa não contém só a assinatura';
    public const MOTIVO_CONTEUDO_ALTERADO = 'O conteúdo não corresponde ao que foi assinado';
    public const MOTIVO_CHAVE_NAO_CONFERE = 'A assinatura não confere com o certificado';
    public const MOTIVO_CERTIFICADO_TROCADO = 'O certificado guardado na assinatura não é o que o signatário assinou';
    public const MOTIVO_INTEGRA = 'O conteúdo e a assinatura conferem com o certificado';
    public const MOTIVO_ASSINATURA_GRANDE = 'A assinatura passa do tamanho máximo de conferência';
    public const MOTIVO_CARIMBO_DO_TEMPO = 'Carimbo do tempo do documento: a conferência fica para o validador do servidor';

    public const MOTIVO_VAZIO = 'O arquivo está vazio';
    public const MOTIVO_GRANDE = 'O arquivo passa do limite de 20 MB para conferência';
    public const MOTIVO_NAO_E_PDF = 'O arquivo não é um PDF';
    public const MOTIVO_ASSINATURAS_DEMAIS = 'O PDF tem mais assinaturas do que o limite de conferência';
    public const MOTIVO_ILEGIVEL = 'Não foi possível ler o PDF';
    public const MOTIVO_SEM_ASSINATURA = 'O PDF não tem assinatura digital';
    public const MOTIVO_DOCMDP = 'O primeiro signatário bloqueou alterações, mas o arquivo foi alterado depois da assinatura';
    public const MOTIVO_ACRESCIMO = 'O arquivo recebeu acréscimos depois da última assinatura, e esse trecho não foi conferido';

    /** O `%PDF-` pode vir depois de lixo no começo do arquivo, como os leitores aceitam. */
    private const INICIO_DO_CABECALHO = 1024;
    /** Janela do dicionário da assinatura, como o protótipo: até 4.000 bytes antes de `l` e 3.000 depois de `c`. */
    private const JANELA_ANTES = 4000;
    private const JANELA_DEPOIS = 3000;
    /** Onde começa a procura do `/P` antes do `/DocMDP`, como o protótipo. */
    private const RECUO_DO_DOCMDP = 200;
    private const ESPACOS_DO_PDF = " \t\r\n\f\0";

    public function __construct(
        private readonly LeitorDeAssinaturaCms $leitorDeAssinaturaCms = new LeitorDeAssinaturaCms(),
    ) {
    }

    public function conferir(string $pdf, ?string $versaoAnterior = null, ?string $cpfEsperado = null): ResultadoDaConferencia
    {
        $prefixo = self::prefixo($pdf, $versaoAnterior);

        try {
            $motivo = self::motivoDeArquivoIlegivel($pdf);
            if ($motivo !== null) {
                return self::ilegivel($pdf, $motivo, $prefixo, $cpfEsperado);
            }
            $declaracoes = self::declaracoesDeFaixa($pdf);
            if ($declaracoes === null) {
                return self::ilegivel($pdf, self::MOTIVO_ASSINATURAS_DEMAIS, $prefixo, $cpfEsperado);
            }

            $assinaturas = [];
            foreach ($declaracoes as $indice => $declaracao) {
                $assinaturas[] = $this->umaAssinatura($pdf, $declaracao, $indice + 1);
            }

            return self::resultado($pdf, $assinaturas, $prefixo, $versaoAnterior, $cpfEsperado);
        } catch (\Throwable) {
            return self::ilegivel($pdf, self::MOTIVO_ILEGIVEL, $prefixo, $cpfEsperado);
        }
    }

    private static function prefixo(string $pdf, ?string $versaoAnterior): ConferenciaDoPrefixo
    {
        if ($versaoAnterior === null) {
            return ConferenciaDoPrefixo::NaoPedida;
        }

        return $versaoAnterior !== '' && \strlen($pdf) >= \strlen($versaoAnterior) && str_starts_with($pdf, $versaoAnterior)
            ? ConferenciaDoPrefixo::Confere
            : ConferenciaDoPrefixo::NaoConfere;
    }

    private static function motivoDeArquivoIlegivel(string $pdf): ?string
    {
        return match (true) {
            $pdf === '' => self::MOTIVO_VAZIO,
            \strlen($pdf) > LimitesDaConferencia::PDF_MAXIMO_EM_BYTES => self::MOTIVO_GRANDE,
            !str_contains(substr($pdf, 0, self::INICIO_DO_CABECALHO), '%PDF-') => self::MOTIVO_NAO_E_PDF,
            default => null,
        };
    }

    /**
     * Cada `/ByteRange [a l c m]` diferente (pelo texto dos números, como o protótipo), já na ordem do fim da faixa.
     * Null quando passa dos limites de declarações ou de assinaturas.
     *
     * @return list<array{numeros: list<int>, inicio: int, fim: int}>|null
     */
    private static function declaracoesDeFaixa(string $pdf): ?array
    {
        $declaracoes = [];
        $vistas = [];
        $ocorrencias = 0;
        $posicao = 0;
        while (($achada = strpos($pdf, '/ByteRange', $posicao)) !== false) {
            if (++$ocorrencias > LimitesDaConferencia::DECLARACOES_DE_FAIXA_MAXIMAS) {
                return null;
            }
            $posicao = $achada + 10;

            $casou = preg_match('/\G\/ByteRange\s*+\[\s*+(\d++)\s++(\d++)\s++(\d++)\s++(\d++)\s*+\]/', $pdf, $partes, 0, $achada);
            if ($casou === false) {
                throw new \RuntimeException('Falha na leitura do /ByteRange.');
            }
            if ($casou === 0) {
                continue;
            }

            $chave = $partes[1] . ',' . $partes[2] . ',' . $partes[3] . ',' . $partes[4];
            if (isset($vistas[$chave])) {
                continue;
            }
            $vistas[$chave] = true;
            if (\count($declaracoes) >= LimitesDaConferencia::ASSINATURAS_MAXIMAS) {
                return null;
            }

            $declaracoes[] = [
                'numeros' => array_map(
                    static fn (string $n): int => \strlen($n) > LimitesDaConferencia::DIGITOS_MAXIMOS_DA_FAIXA ? -1 : (int) $n,
                    [$partes[1], $partes[2], $partes[3], $partes[4]],
                ),
                'inicio' => $achada,
                'fim' => $achada + \strlen($partes[0]),
            ];
        }

        // Ordem do protótipo (fim da faixa, estável); número absurdo vai para o fim, como o número enorme do JS.
        usort($declaracoes, static fn (array $x, array $y): int => self::chaveDeOrdem($x['numeros']) <=> self::chaveDeOrdem($y['numeros']));

        return $declaracoes;
    }

    /** @param list<int> $numeros */
    private static function chaveDeOrdem(array $numeros): int
    {
        return \in_array(-1, $numeros, true) ? \PHP_INT_MAX : $numeros[2] + $numeros[3];
    }

    /** @param array{numeros: list<int>, inicio: int, fim: int} $declaracao */
    private function umaAssinatura(string $pdf, array $declaracao, int $ordem): AssinaturaConferida
    {
        [$a, $l, $c, $m] = $declaracao['numeros'];
        $tamanho = \strlen($pdf);
        $absurda = \in_array(-1, $declaracao['numeros'], true);
        $fim = $absurda ? -1 : $c + $m;
        $base = [
            'ordem' => $ordem,
            'faixaDeBytes' => $declaracao['numeros'],
            'fim' => $fim,
            'cobreODocumento' => !$absurda && $fim === $tamanho,
            'bytesDepois' => $absurda ? 0 : max(0, $tamanho - $fim),
        ];

        $motivoDaFaixa = self::motivoDeFaixaInvalida($pdf, $declaracao, $absurda);
        if ($motivoDaFaixa !== null) {
            return self::montar($base + ['faixaValida' => false, 'tipo' => ''], null, null, null, VereditoDaAssinatura::Alterada, $motivoDaFaixa);
        }

        $dicionario = self::dicionario($pdf, $l, $c);
        $tipo = match (true) {
            str_contains($dicionario, 'ETSI.RFC3161') => self::TIPO_CARIMBO_DO_TEMPO,
            str_contains($dicionario, 'ETSI.CAdES') => 'PAdES',
            str_contains($dicionario, 'adbe.pkcs7') => 'PKCS#7',
            default => 'Digital',
        };
        $base += [
            'faixaValida' => true,
            'tipo' => $tipo,
            'docMdp' => self::docMdp($dicionario),
        ];
        $horaDoDicionario = self::horaDoDicionario($dicionario);

        if ($c - $l > LimitesDaConferencia::TRECHO_DA_ASSINATURA_MAXIMO_EM_BYTES) {
            return self::montar($base, null, null, $horaDoDicionario, VereditoDaAssinatura::NaoConferida, self::MOTIVO_ASSINATURA_GRANDE);
        }

        try {
            $cms = $this->leitorDeAssinaturaCms->conferir(self::bytesDoTrecho(substr($pdf, $l, $c - $l)), $pdf, $l, $c, $m);
        } catch (FormatoNaoReconhecido $e) {
            return self::montar($base, null, null, $horaDoDicionario, VereditoDaAssinatura::NaoConferida, $e->motivo());
        } catch (\Throwable) {
            return self::montar($base, null, null, $horaDoDicionario, VereditoDaAssinatura::NaoConferida, FormatoNaoReconhecido::MOTIVO);
        }

        // Ordem do protótipo: "conteúdo" antes de "chave" quando os dois falham. No carimbo do tempo o messageDigest é
        // o do TSTInfo, não o da faixa (M15): só a chave decide.
        $carimbo = $tipo === self::TIPO_CARIMBO_DO_TEMPO;
        [$veredito, $motivo] = match (true) {
            !$carimbo && $cms->resumoConfere === false => [VereditoDaAssinatura::Alterada, self::MOTIVO_CONTEUDO_ALTERADO],
            $cms->chaveConfere === false => [VereditoDaAssinatura::Alterada, self::MOTIVO_CHAVE_NAO_CONFERE],
            $cms->vinculo === VinculoDoCertificado::Diferente => [VereditoDaAssinatura::Alterada, self::MOTIVO_CERTIFICADO_TROCADO],
            $carimbo => [VereditoDaAssinatura::NaoConferida, self::MOTIVO_CARIMBO_DO_TEMPO],
            $cms->resumoConfere === true && $cms->chaveConfere === true => [VereditoDaAssinatura::Integra, self::MOTIVO_INTEGRA],
            default => [VereditoDaAssinatura::NaoConferida, $cms->motivo ?? FormatoNaoReconhecido::MOTIVO],
        };

        return self::montar(
            $base,
            $cms,
            $cms->certificado,
            $cms->horaAssinada ?? $horaDoDicionario,
            $veredito,
            $motivo,
            resumoConfere: $carimbo ? null : $cms->resumoConfere,
        );
    }

    /**
     * Regras do protótipo (`a = 0`, `c + m <= tamanho`, `a + l <= c`) e as da decisão M4.
     *
     * @param array{numeros: list<int>, inicio: int, fim: int} $declaracao
     */
    private static function motivoDeFaixaInvalida(string $pdf, array $declaracao, bool $absurda): ?string
    {
        [$a, $l, $c, $m] = $declaracao['numeros'];
        if ($absurda || $a !== 0 || $c + $m > \strlen($pdf) || $a + $l > $c || $c - $l < 2) {
            return self::MOTIVO_FAIXA_INVALIDA;
        }

        $dentroDaPrimeira = $declaracao['fim'] <= $l;
        $dentroDaSegunda = $declaracao['inicio'] >= $c && $declaracao['fim'] <= $c + $m;
        if (!$dentroDaPrimeira && !$dentroDaSegunda) {
            return self::MOTIVO_FAIXA_FORA_DO_TRECHO;
        }

        $trecho = substr($pdf, $l, $c - $l);
        $inicio = $trecho[0] === '<' ? 1 : 0;
        $fimDoTrecho = str_ends_with($trecho, '>') && \strlen($trecho) > $inicio ? \strlen($trecho) - 1 : \strlen($trecho);
        $miolo = $fimDoTrecho - $inicio;
        if ($miolo > 0 && strspn($trecho, '0123456789abcdefABCDEF' . self::ESPACOS_DO_PDF, $inicio, $miolo) !== $miolo) {
            return self::MOTIVO_TRECHO_NAO_HEXADECIMAL;
        }

        return null;
    }

    /** O hexadecimal do trecho de fora em bytes; número ímpar de dígitos perde o último, como o protótipo. */
    private static function bytesDoTrecho(string $trecho): string
    {
        $hexadecimal = str_replace(['<', '>', ' ', "\t", "\r", "\n", "\f", "\0"], '', $trecho);
        $hexadecimal = substr($hexadecimal, 0, \strlen($hexadecimal) - \strlen($hexadecimal) % 2);
        $bytes = SemAvisos::executar(static fn (): string|false => hex2bin($hexadecimal));

        return $bytes === false ? '' : $bytes;
    }

    /** Do último ` obj` antes de `l` (até 4.000 bytes antes) até `l`, mais 3.000 bytes depois de `c`, até o `endobj`. */
    private static function dicionario(string $pdf, int $l, int $c): string
    {
        $janela = max(0, $l - self::JANELA_ANTES);
        $achado = strrpos(substr($pdf, $janela, $l - $janela + 4), ' obj');
        $inicio = $achado === false ? $janela : $janela + $achado;
        $dicionario = substr($pdf, $inicio, $l - $inicio) . substr($pdf, $c, self::JANELA_DEPOIS);
        $corte = strpos($dicionario, 'endobj');

        return $corte === false ? $dicionario : substr($dicionario, 0, $corte);
    }

    /** P do DocMDP (1, 2 ou 3); sem `/P` ou fora da faixa, 2, o padrão da norma (M12). Null sem DocMDP. */
    private static function docMdp(string $dicionario): ?int
    {
        $posicao = strpos($dicionario, '/DocMDP');
        if ($posicao === false) {
            return null;
        }

        $achou = preg_match('/\/P\s++(\d)/', substr($dicionario, max(0, $posicao - self::RECUO_DO_DOCMDP)), $partes);
        $p = $achou === 1 ? (int) $partes[1] : 2;

        return \in_array($p, [1, 2, 3], true) ? $p : 2;
    }

    /** `/M (D:AAAAMMDDHHmmSS)` com o fuso, quando houver (M11). */
    private static function horaDoDicionario(string $dicionario): ?\DateTimeImmutable
    {
        if (preg_match('/\/M\s*+\(D:(\d{4})(\d\d)(\d\d)(\d\d)(\d\d)(\d\d)(?:([Zz+\-])(?:(\d\d)\'?(\d\d)?)?)?/', $dicionario, $p) !== 1) {
            return null;
        }

        $hora = LeitorDer::dataUtc((int) $p[1], (int) $p[2], (int) $p[3], (int) $p[4], (int) $p[5], (int) $p[6]);
        $sinal = $p[7] ?? '';
        if ($hora === null || ($sinal !== '+' && $sinal !== '-')) {
            return $hora;
        }

        $minutos = (int) ($p[8] ?? 0) * 60 + (int) ($p[9] ?? 0);
        if ($minutos > 14 * 60) {
            return $hora;
        }

        // Hora local menos o fuso dá UTC: 12:00-03'00' é 15:00 UTC.
        return $hora->modify(($sinal === '-' ? '+' : '-') . $minutos . ' minutes');
    }

    /**
     * @param array{ordem: int, faixaDeBytes: list<int>, fim: int, cobreODocumento: bool, bytesDepois: int, faixaValida: bool, tipo: string, docMdp?: ?int} $base
     */
    private static function montar(
        array $base,
        ?ConferenciaDoCms $cms,
        ?CertificadoDoSignatario $certificado,
        ?\DateTimeImmutable $hora,
        VereditoDaAssinatura $veredito,
        string $motivo,
        ?bool $resumoConfere = null,
    ): AssinaturaConferida {
        return new AssinaturaConferida(
            ordem: $base['ordem'],
            faixaDeBytes: $base['faixaDeBytes'],
            faixaValida: $base['faixaValida'],
            fim: $base['fim'],
            cobreODocumento: $base['cobreODocumento'],
            bytesDepois: $base['bytesDepois'],
            tipo: $base['tipo'],
            algoritmoDoResumo: $cms?->algoritmoDoResumo,
            resumoConfere: $resumoConfere,
            chaveConfere: $cms?->chaveConfere,
            titular: $certificado?->titular,
            cpf: $certificado?->cpf,
            cnpj: $certificado?->cnpj,
            documentoMascarado: $certificado?->documentoMascarado(),
            emissor: $certificado?->emissor,
            emissorDeclaraIcpBrasil: $certificado?->emissorDeclaraIcpBrasil() ?? false,
            numeroDeSerie: $certificado?->numeroDeSerie,
            validoDesde: $certificado?->validoDesde,
            validoAte: $certificado?->validoAte,
            horaDeclarada: $hora,
            docMdp: $base['docMdp'] ?? null,
            veredito: $veredito,
            motivo: $motivo,
            vinculoDoCertificado: $cms->vinculo ?? VinculoDoCertificado::NaoConferido,
        );
    }

    /** @param list<AssinaturaConferida> $assinaturas */
    private static function resultado(string $pdf, array $assinaturas, ConferenciaDoPrefixo $prefixo, ?string $versaoAnterior, ?string $cpfEsperado): ResultadoDaConferencia
    {
        $certificadora = null;
        foreach ($assinaturas as $assinatura) {
            if ($assinatura->docMdp !== null) {
                $certificadora = $assinatura;
                break;
            }
        }
        $alteradoDepois = $certificadora !== null && $certificadora->docMdp === 1 && $certificadora->bytesDepois > 0;
        $cifrado = preg_match('/\/Encrypt[\s<]/', $pdf) === 1;
        $algumaAlterada = array_filter($assinaturas, static fn (AssinaturaConferida $x): bool => $x->veredito === VereditoDaAssinatura::Alterada) !== [];
        $tamanhoAnterior = $versaoAnterior !== null && $versaoAnterior !== '' ? \strlen($versaoAnterior) : null;
        [$situacao, $motivo] = self::situacao($assinaturas, $alteradoDepois);

        return new ResultadoDaConferencia(
            situacao: $situacao,
            motivo: $motivo,
            tamanho: \strlen($pdf),
            assinaturas: $assinaturas,
            docMdp: $certificadora->docMdp ?? 0,
            alteradoDepoisDaCertificacao: $alteradoDepois,
            cifrado: $cifrado,
            permiteCarimbo: !($certificadora !== null && $certificadora->docMdp < 3) && !$cifrado && !$algumaAlterada,
            prefixo: $prefixo,
            assinaturasNovas: $tamanhoAnterior === null
                ? 0
                : \count(array_filter($assinaturas, static fn (AssinaturaConferida $x): bool => $x->fim > $tamanhoAnterior)),
            cpf: self::cpf($assinaturas, $tamanhoAnterior, $cpfEsperado),
        );
    }

    /**
     * @param list<AssinaturaConferida> $assinaturas
     *
     * @return array{SituacaoDaConferencia, string}
     */
    private static function situacao(array $assinaturas, bool $alteradoDepois): array
    {
        $total = \count($assinaturas);
        if ($total === 0) {
            return [SituacaoDaConferencia::SemAssinatura, self::MOTIVO_SEM_ASSINATURA];
        }

        $sufixo = static fn (AssinaturaConferida $x): string => $total > 1 ? ' (assinatura ' . $x->ordem . ' de ' . $total . ')' : '';
        foreach ($assinaturas as $assinatura) {
            if ($assinatura->veredito === VereditoDaAssinatura::Alterada) {
                return [SituacaoDaConferencia::Alterado, $assinatura->motivo . $sufixo($assinatura)];
            }
        }
        if ($alteradoDepois) {
            return [SituacaoDaConferencia::Alterado, self::MOTIVO_DOCMDP];
        }
        foreach ($assinaturas as $assinatura) {
            if ($assinatura->veredito === VereditoDaAssinatura::NaoConferida) {
                return [SituacaoDaConferencia::NaoConferido, $assinatura->motivo . $sufixo($assinatura)];
            }
        }
        if ($assinaturas[$total - 1]->bytesDepois > 0) {
            return [SituacaoDaConferencia::NaoConferido, self::MOTIVO_ACRESCIMO];
        }

        return [
            SituacaoDaConferencia::Integro,
            $total === 1
                ? 'A assinatura confere com o conteúdo e com o certificado'
                : 'As ' . $total . ' assinaturas conferem com o conteúdo e com os certificados',
        ];
    }

    /** @param list<AssinaturaConferida> $assinaturas */
    private static function cpf(array $assinaturas, ?int $tamanhoAnterior, ?string $cpfEsperado): ConferenciaDoCpf
    {
        if ($cpfEsperado === null) {
            return ConferenciaDoCpf::NaoPedida;
        }

        $alvo = null;
        foreach ($assinaturas as $assinatura) {
            if (!$assinatura->carimboDoTempo()) {
                $alvo = $assinatura;
            }
        }
        if ($alvo === null || ($tamanhoAnterior !== null && $alvo->fim <= $tamanhoAnterior)) {
            return ConferenciaDoCpf::SemAssinatura;
        }
        if ($alvo->cpf === null) {
            return ConferenciaDoCpf::SemCpfNoCertificado;
        }
        // CPF de assinatura alterada, ou de certificado que pode ter sido trocado depois, não prova nada (M26).
        if ($alvo->veredito !== VereditoDaAssinatura::Integra || $alvo->vinculoDoCertificado !== VinculoDoCertificado::Vinculado) {
            return ConferenciaDoCpf::NaoConferido;
        }

        $digitos = (string) preg_replace('/\D++/', '', $cpfEsperado);

        return \strlen($digitos) === 11 && hash_equals($alvo->cpf, $digitos) ? ConferenciaDoCpf::Confere : ConferenciaDoCpf::NaoConfere;
    }

    private static function ilegivel(string $pdf, string $motivo, ConferenciaDoPrefixo $prefixo, ?string $cpfEsperado): ResultadoDaConferencia
    {
        return new ResultadoDaConferencia(
            situacao: SituacaoDaConferencia::Ilegivel,
            motivo: $motivo,
            tamanho: \strlen($pdf),
            assinaturas: [],
            docMdp: 0,
            alteradoDepoisDaCertificacao: false,
            cifrado: false,
            permiteCarimbo: false,
            prefixo: $prefixo,
            assinaturasNovas: 0,
            cpf: $cpfEsperado === null ? ConferenciaDoCpf::NaoPedida : ConferenciaDoCpf::SemAssinatura,
        );
    }
}
