<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Análise conservadora do acréscimo de cada assinatura (M27): os bytes entre a versão anterior (o fim da faixa da
 * assinatura de antes, ou a versão anterior informada) e o fim da faixa dela. O signatário assina o acréscimo que ele
 * mesmo escreveu, então a assinatura íntegra não prova que o acréscimo só trouxe a assinatura.
 *
 * Lista os objetos que o acréscimo define (cabeçalhos `N G obj` e o que há dentro dos /ObjStm dele) e os índices dele
 * (tabela `xref` e fluxo /XRef). Aceita só o que uma assinatura precisa:
 * - objeto novo (número que não existia antes) que não seja página, árvore de páginas, catálogo, ação, anotação ou
 *   campo de formulário; campo de assinatura (/FT /Sig) sem ação é aceito;
 * - redefinição igual à anterior; catálogo que só muda /AcroForm, /DSS e /Extensions; página que só acrescenta
 *   campos de assinatura em /Annots; formulário que só muda /Fields (acrescentando campos de assinatura), /SigFlags,
 *   /DR e /DA; campo de assinatura que continua campo de assinatura sem ação; dados de validação (DSS e VRI);
 *   informações do documento (/Info só com as chaves da norma) e metadados XMP, que não aparecem na página;
 * - índice que aponta cada objeto para o cabeçalho dele no acréscimo ou para a versão mais nova de antes.
 * O resto deixa o conteúdo "não conferido" com motivo que nomeia o objeto. A comparação completa das versões (inclusive
 * os ataques de sombra, preparados na versão anterior) é do pyHanko, na etapa 6.
 */
final class AnalisadorDeAcrescimo
{
    private const CHAVES_DO_FORMULARIO = ['/Fields', '/SigFlags', '/DR', '/DA'];
    private const CHAVES_DO_DSS = ['/VRI', '/Certs', '/OCSPs', '/CRLs'];
    private const CHAVES_DO_VRI = ['/Cert', '/CRL', '/OCSP', '/TU', '/TS'];
    private const CHAVES_DE_INFORMACAO = ['/Title', '/Author', '/Subject', '/Keywords', '/Creator', '/Producer', '/CreationDate', '/ModDate', '/Trapped'];
    private const SUBTIPOS_DE_ANOTACAO = [
        '/Text', '/Link', '/FreeText', '/Line', '/Square', '/Circle', '/Polygon', '/PolyLine', '/Highlight',
        '/Underline', '/Squiggly', '/StrikeOut', '/Stamp', '/Caret', '/Ink', '/Popup', '/FileAttachment', '/Sound',
        '/Movie', '/Widget', '/Screen', '/PrinterMark', '/TrapNet', '/Watermark', '/3D', '/Redact', '/Projection',
        '/RichMedia',
    ];
    private const TIPOS_DE_ACAO = [
        '/GoTo', '/GoToR', '/GoToE', '/GoToDp', '/Launch', '/Thread', '/URI', '/Sound', '/Movie', '/Hide', '/Named',
        '/SubmitForm', '/ResetForm', '/ImportData', '/JavaScript', '/SetOCGState', '/Rendition', '/Trans',
        '/GoTo3DView', '/RichMediaExecute',
    ];
    private const CHAVES_NO_MOTIVO = 5;
    private const BRANCO = '[\0\t\n\f\r ]';

    /** @var list<ObjetoDoPdf> objetos definidos no acréscimo, na ordem */
    private array $lidos = [];
    /** @var array<int, ObjetoDoPdf> número => a última definição no acréscimo */
    private array $definidos = [];
    /** @var array<int, int> posição do cabeçalho => número, dos objetos soltos do acréscimo */
    private array $cabecalhos = [];
    /** @var array<int, list<int>> número do /ObjStm do acréscimo => números que ele tem */
    private array $fluxos = [];
    /** @var list<array{int, int, int}> número, tipo (0 livre, 1 solto, 2 em /ObjStm) e posição ou /ObjStm */
    private array $entradas = [];
    private ?string $mudanca = null;

    private function __construct(
        private readonly string $pdf,
        private readonly LeitorDeSintaxeDoPdf $sintaxe,
        private readonly IndiceDoPdf $indice,
        private readonly int $inicio,
        private readonly int $fim,
        private readonly int $ordem,
    ) {
    }

    /**
     * Devolve as assinaturas com `acrescimo` e `motivoDoAcrescimo` preenchidos. Não lança.
     *
     * @param list<AssinaturaConferida> $assinaturas
     *
     * @return list<AssinaturaConferida>
     */
    public static function analisar(string $pdf, array $assinaturas, ?int $tamanhoAnterior): array
    {
        $faixas = self::faixas($assinaturas, $tamanhoAnterior);
        if ($faixas === []) {
            return $assinaturas;
        }

        $sintaxe = new LeitorDeSintaxeDoPdf();
        try {
            $indice = IndiceDoPdf::montar($pdf, $sintaxe, array_values(array_unique(array_column($faixas, 0))));
        } catch (\Throwable) {
            $indice = null;
        }

        foreach ($faixas as $posicao => [$inicio, $fim]) {
            $assinatura = $assinaturas[$posicao];
            [$conferencia, $motivo] = $indice === null || !$indice->completo()
                ? [ConferenciaDoAcrescimo::NaoConferido, self::naoConferido($assinatura->ordem, 'não foi possível montar o índice do arquivo')]
                : (new self($pdf, $sintaxe, $indice, $inicio, $fim, $assinatura->ordem))->conferir();
            $assinaturas[$posicao] = $assinatura->comAcrescimo($conferencia, $motivo);
        }

        return $assinaturas;
    }

    /**
     * Começo e fim do acréscimo de cada assinatura que tem versão anterior. Assinatura alterada fica de fora: o
     * resultado já é "alterado".
     *
     * @param list<AssinaturaConferida> $assinaturas
     *
     * @return array<int, array{int, int}> posição na lista => começo e fim
     */
    private static function faixas(array $assinaturas, ?int $tamanhoAnterior): array
    {
        $fins = [];
        foreach ($assinaturas as $posicao => $assinatura) {
            if ($assinatura->faixaValida && $assinatura->fim > 0) {
                $fins[$posicao] = $assinatura->fim;
            }
        }

        $faixas = [];
        foreach ($fins as $posicao => $fim) {
            $inicio = $tamanhoAnterior !== null && $tamanhoAnterior < $fim ? $tamanhoAnterior : 0;
            foreach ($fins as $outro) {
                if ($outro < $fim) {
                    $inicio = max($inicio, $outro);
                }
            }
            if ($inicio > 0 && $assinaturas[$posicao]->veredito !== VereditoDaAssinatura::Alterada) {
                $faixas[$posicao] = [$inicio, $fim];
            }
        }

        return $faixas;
    }

    /** @return array{ConferenciaDoAcrescimo, string} */
    private function conferir(): array
    {
        try {
            $falha = $this->ler() ?? $this->conferirObjetos() ?? $this->conferirIndices();
        } catch (LimiteExcedido) {
            $falha = 'o acréscimo passa dos limites de conferência';
        } catch (\Throwable) {
            $falha = 'não foi possível ler o acréscimo';
        }

        if ($this->mudanca !== null) {
            return [ConferenciaDoAcrescimo::MudaConteudo, 'O acréscimo da assinatura ' . $this->ordem . ' ' . $this->mudanca];
        }
        if ($falha !== null) {
            return [ConferenciaDoAcrescimo::NaoConferido, self::naoConferido($this->ordem, $falha)];
        }

        return [ConferenciaDoAcrescimo::SoAssinatura, 'O acréscimo da assinatura ' . $this->ordem . ' só traz o que a assinatura precisa'];
    }

    private static function naoConferido(int $ordem, string $razao): string
    {
        return 'O acréscimo da assinatura ' . $ordem . ' não foi conferido: ' . $razao;
    }

    /** Registra a primeira mudança no conteúdo (o texto vem depois de "O acréscimo da assinatura N"). */
    private function mudou(string $texto): void
    {
        $this->mudanca ??= $texto;
    }

    /**
     * Percorre o acréscimo: cada cabeçalho de objeto (pulando o objeto inteiro, para não ler o que há dentro dos
     * fluxos) e, entre um objeto e outro, as tabelas `xref`. Devolve a razão de não conferir, ou null.
     *
     * @throws FormatoNaoReconhecido
     */
    private function ler(): ?string
    {
        $padrao = '/' . LeitorDeSintaxeDoPdf::PADRAO_DO_CABECALHO . '/';
        $posicao = $this->inicio;
        while (true) {
            $achou = preg_match($padrao, $this->pdf, $partes, \PREG_OFFSET_CAPTURE, $posicao);
            if ($achou === false) {
                return 'não foi possível ler o acréscimo';
            }
            $cabecalho = $achou === 1 && $partes[0][1] < $this->fim ? $partes[0][1] : $this->fim;
            $falha = $this->lerTabelas(substr($this->pdf, $posicao, $cabecalho - $posicao));
            if ($falha !== null || $cabecalho >= $this->fim) {
                return $falha;
            }

            $numeros = LeitorDeSintaxeDoPdf::numerosDoCabecalho($partes[1][0], $partes[2][0]);
            if ($numeros === null) {
                return 'um cabeçalho de objeto não dá para ler';
            }
            [$numero, $geracao] = $numeros;
            $objeto = $this->indice->objeto($numero, $geracao, $cabecalho + \strlen($partes[0][0]), $this->fim);
            if ($objeto === null) {
                return 'não foi possível ler o objeto ' . $numero;
            }
            if ($geracao !== 0) {
                return 'o objeto ' . $numero . ' tem geração diferente de zero';
            }
            $this->cabecalhos[$cabecalho] = $numero;
            $falha = $this->guardar($objeto);
            if ($falha === null && $objeto->tipo() === '/ObjStm') {
                $falha = $this->abrirFluxo($objeto);
            }
            if ($falha === null && $objeto->tipo() === '/XRef') {
                $falha = $this->lerIndiceEmFluxo($objeto);
            }
            if ($falha !== null) {
                return $falha;
            }
            $posicao = $objeto->depoisDoObjeto;
        }
    }

    private function guardar(ObjetoDoPdf $objeto): ?string
    {
        if (\count($this->lidos) >= LimitesDaConferencia::OBJETOS_MAXIMOS) {
            return 'o acréscimo tem objetos demais';
        }
        $this->lidos[] = $objeto;
        $this->definidos[$objeto->numero] = $objeto;

        return null;
    }

    /** @throws FormatoNaoReconhecido */
    private function abrirFluxo(ObjetoDoPdf $fluxo): ?string
    {
        $dados = $fluxo->fluxo ? $this->sintaxe->dados($this->pdf, $fluxo) : null;
        $objetos = $dados === null ? null : $this->sintaxe->objetosDoFluxo($dados, $fluxo);
        if ($objetos === null) {
            return 'não foi possível ler o fluxo de objetos ' . $fluxo->numero;
        }
        $this->fluxos[$fluxo->numero] = array_keys($objetos);
        foreach ($objetos as $objeto) {
            $falha = $this->guardar($objeto);
            if ($falha !== null) {
                return $falha;
            }
        }

        return null;
    }

    /**
     * Tabelas `xref` no texto entre dois objetos do acréscimo.
     *
     * @throws LimiteExcedido
     */
    private function lerTabelas(string $texto): ?string
    {
        $posicao = 0;
        while (preg_match('/(?<![A-Za-z0-9])xref(?=' . self::BRANCO . ')/', $texto, $partes, \PREG_OFFSET_CAPTURE, $posicao) === 1) {
            $posicao = $this->lerTabela($texto, $partes[0][1] + 4);
            if ($posicao === null) {
                return 'o índice do acréscimo não dá para ler';
            }
        }

        return null;
    }

    /**
     * As subseções de uma tabela `xref` (`primeiro quantidade` e as entradas `posição geração n|f`). Devolve onde
     * parou, ou null se uma entrada não dá para ler.
     *
     * @throws LimiteExcedido
     */
    private function lerTabela(string $texto, int $posicao): ?int
    {
        $branco = self::BRANCO;
        while (preg_match('/\G' . $branco . '*+(\d{1,10})' . $branco . '++(\d{1,10})(?=' . $branco . ')/', $texto, $subsecao, 0, $posicao) === 1) {
            $posicao += \strlen($subsecao[0]);
            for ($i = 0, $quantidade = (int) $subsecao[2]; $i < $quantidade; ++$i) {
                if (preg_match('/\G' . $branco . '*+(\d{1,10})' . $branco . '++\d{1,5}' . $branco . '++([nf])(?![A-Za-z0-9])/', $texto, $entrada, 0, $posicao) !== 1) {
                    return null;
                }
                $posicao += \strlen($entrada[0]);
                $this->entrada((int) $subsecao[1] + $i, $entrada[2] === 'n' ? 1 : 0, (int) $entrada[1]);
            }
        }

        return $posicao;
    }

    /** @throws LimiteExcedido */
    private function entrada(int $numero, int $tipo, int $campo): void
    {
        if (\count($this->entradas) >= LimitesDaConferencia::OBJETOS_MAXIMOS) {
            throw new LimiteExcedido('Entradas demais no índice do acréscimo.');
        }
        $this->entradas[] = [$numero, $tipo, $campo];
    }

    /**
     * Fluxo de índice (/Type /XRef): /W com até 7 bytes por campo, /Index ou `[0 /Size]`.
     *
     * @throws FormatoNaoReconhecido
     */
    private function lerIndiceEmFluxo(ObjetoDoPdf $fluxo): ?string
    {
        $falha = 'o índice do acréscimo não dá para ler';
        $dados = $fluxo->fluxo ? $this->sintaxe->dados($this->pdf, $fluxo) : null;
        $tamanho = $fluxo->valor('/Size') ?? '';
        if ($dados === null || preg_match('/^\[ (\d) (\d) (\d) \]$/', $fluxo->valor('/W') ?? '', $w) !== 1
            || !ctype_digit($tamanho) || \strlen($tamanho) > 9) {
            return $falha;
        }
        $larguras = [(int) $w[1], (int) $w[2], (int) $w[3]];
        $faixas = $fluxo->valor('/Index') ?? '[ 0 ' . $tamanho . ' ]';
        if (max($larguras) > 7 || preg_match('/^\[(?: \d{1,9})*+ \]$/', $faixas) !== 1) {
            return $falha;
        }
        preg_match_all('/\d++/', $faixas, $partes);
        $numeros = array_map('intval', $partes[0]);
        $linha = array_sum($larguras);
        $total = 0;
        for ($i = 1; $i < \count($numeros); $i += 2) {
            $total += $numeros[$i];
        }
        if (\count($numeros) % 2 !== 0 || $linha === 0) {
            return $falha;
        }
        if ($total + \count($this->entradas) > LimitesDaConferencia::OBJETOS_MAXIMOS) {
            throw new LimiteExcedido('Entradas demais no índice do acréscimo.');
        }
        if (\strlen($dados) < $total * $linha) {
            return $falha;
        }

        $posicao = 0;
        for ($i = 0; $i < \count($numeros); $i += 2) {
            for ($j = 0; $j < $numeros[$i + 1]; ++$j) {
                $campos = [];
                foreach ($larguras as $largura) {
                    $campos[] = $largura === 0 ? null : self::inteiro($dados, $posicao, $largura);
                    $posicao += $largura;
                }
                $tipo = $campos[0] ?? 1;
                if ($tipo <= 2) {
                    $this->entrada($numeros[$i] + $j, $tipo, $campos[1] ?? 0);
                }
            }
        }

        return null;
    }

    private static function inteiro(string $dados, int $posicao, int $largura): int
    {
        $valor = 0;
        for ($i = 0; $i < $largura; ++$i) {
            $valor = ($valor << 8) | \ord($dados[$posicao + $i]);
        }

        return $valor;
    }

    /** @throws FormatoNaoReconhecido */
    private function conferirObjetos(): ?string
    {
        $proximo = $this->indice->proximoNumero($this->inicio);
        foreach ($this->lidos as $objeto) {
            $falha = null;
            if ($objeto->numero < $proximo) {
                $falha = $this->conferirRedefinicao($objeto);
            } else {
                $this->conferirNovo($objeto);
            }
            if ($falha !== null || $this->mudanca !== null) {
                return $falha;
            }
        }

        return null;
    }

    /** @throws FormatoNaoReconhecido */
    private function conferirNovo(ObjetoDoPdf $objeto): void
    {
        $dicionario = $objeto->dicionario;
        if ($dicionario === null) {
            return;
        }
        $proibido = \in_array($dicionario['/Type'] ?? null, ['/Page', '/Pages', '/Catalog'], true)
            || self::acao($dicionario)
            || ((isset($dicionario['/FT']) || self::anotacao($dicionario)) && !$this->assinaturaSemAcao($dicionario));
        if ($proibido) {
            $this->mudou('cria o objeto ' . $objeto->numero . ', ' . $this->descricao($objeto) . self::comAcao($dicionario));
        }
    }

    /** @throws FormatoNaoReconhecido */
    private function conferirRedefinicao(ObjetoDoPdf $novo): ?string
    {
        $antigo = $this->indice->objetoAnterior($novo->numero, $this->inicio);
        if ($antigo === null) {
            return 'não foi achada a versão anterior do objeto ' . $novo->numero;
        }
        $chaves = $this->mudancaNaoAceita($antigo, $novo);
        if ($chaves !== null) {
            $detalhe = $chaves === [] ? self::comAcao($novo->dicionario) : ' (' . self::listaDeChaves($chaves) . ')';
            $this->mudou('muda o objeto ' . $novo->numero . ', ' . $this->descricao($antigo) . $detalhe);
        }

        return null;
    }

    /**
     * Null se a redefinição é aceita; senão, as chaves que mudaram sem poder (vazio quando não se aplica).
     *
     * @return list<string>|null
     *
     * @throws FormatoNaoReconhecido
     */
    private function mudancaNaoAceita(ObjetoDoPdf $antigo, ObjetoDoPdf $novo): ?array
    {
        $velho = $antigo->dicionario;
        $atual = $novo->dicionario;
        if ($antigo->tipo() === '/Metadata' && $novo->tipo() === '/Metadata') {
            return null;
        }
        if ($antigo->fluxo || $novo->fluxo) {
            return $antigo->fluxo && $novo->fluxo && $velho === $atual && $this->mesmosDados($antigo, $novo) ? null : [];
        }
        if ($velho === null || $atual === null) {
            $aceito = $velho === null && $atual === null && $antigo->conteudo !== null && $novo->conteudo !== null
                && $this->referenciasAceitas($antigo->conteudo, $novo->conteudo);

            return $aceito ? null : [];
        }

        $chaves = self::diferencas($velho, $atual);
        if ($chaves === []) {
            return null;
        }
        if (($velho['/Type'] ?? null) === '/Catalog') {
            $aceitas = ['/DSS', '/Extensions'];
            if ($this->formularioDoCatalogoAceito($velho['/AcroForm'] ?? null, $atual['/AcroForm'] ?? null)) {
                $aceitas[] = '/AcroForm';
            }

            return self::semAsAceitas($chaves, $aceitas);
        }
        if (($velho['/Type'] ?? null) === '/Page') {
            $aceitas = $this->referenciasAceitas($velho['/Annots'] ?? null, $atual['/Annots'] ?? null) ? ['/Annots'] : [];

            return self::semAsAceitas($chaves, $aceitas);
        }
        $aceito = match (true) {
            self::dadosDeValidacao($velho) => self::dadosDeValidacao($atual),
            self::informacao($velho) => self::informacao($atual),
            $this->campoDeAssinatura($velho) => $this->assinaturaSemAcao($atual)
                && (!isset($velho['/V']) || ($atual['/V'] ?? null) === $velho['/V']),
            !isset($velho['/Type']) && isset($velho['/Fields']) => $this->formularioAceito($velho, $atual),
            default => false,
        };

        return $aceito ? null : $chaves;
    }

    private function mesmosDados(ObjetoDoPdf $antigo, ObjetoDoPdf $novo): bool
    {
        $tamanho = $antigo->fimDosDados - $antigo->inicioDosDados;
        if ($tamanho !== $novo->fimDosDados - $novo->inicioDosDados) {
            return false;
        }
        $this->sintaxe->trabalhar(2 * $tamanho);

        return substr($this->pdf, $antigo->inicioDosDados, $tamanho) === substr($this->pdf, $novo->inicioDosDados, $tamanho);
    }

    /**
     * @param list<string> $chaves
     * @param list<string> $aceitas
     *
     * @return list<string>|null
     */
    private static function semAsAceitas(array $chaves, array $aceitas): ?array
    {
        $proibidas = array_values(array_diff($chaves, $aceitas));

        return $proibidas === [] ? null : $proibidas;
    }

    /**
     * O /AcroForm do catálogo, antes e depois: só /Fields (com campos de assinatura a mais), /SigFlags, /DR e /DA.
     *
     * @throws FormatoNaoReconhecido
     */
    private function formularioDoCatalogoAceito(?string $antes, ?string $depois): bool
    {
        if ($antes === $depois) {
            return true;
        }
        if ($depois === null) {
            return false;
        }
        $velho = $antes === null ? [] : $this->dicionarioDoValor($antes, true);
        $atual = $this->dicionarioDoValor($depois, false);

        return $velho !== null && $atual !== null && $this->formularioAceito($velho, $atual);
    }

    /**
     * @param array<string, string> $velho
     * @param array<string, string> $atual
     *
     * @throws FormatoNaoReconhecido
     */
    private function formularioAceito(array $velho, array $atual): bool
    {
        return array_diff(self::diferencas($velho, $atual), self::CHAVES_DO_FORMULARIO) === []
            && $this->referenciasAceitas($velho['/Fields'] ?? null, $atual['/Fields'] ?? null);
    }

    /**
     * Vetor de referências (/Annots, /Fields) que só ganhou campos de assinatura sem ação, sem perder nenhuma.
     *
     * @throws FormatoNaoReconhecido
     */
    private function referenciasAceitas(?string $antes, ?string $depois): bool
    {
        if ($antes === $depois) {
            return true;
        }
        if ($depois === null) {
            return false;
        }
        $velhas = $antes === null ? [] : LeitorDeSintaxeDoPdf::referencias($antes);
        $novas = LeitorDeSintaxeDoPdf::referencias($depois);
        if ($velhas === null || $novas === null || array_diff($velhas, $novas) !== []) {
            return false;
        }
        foreach (array_diff($novas, $velhas) as $numero) {
            if (!$this->assinaturaSemAcao($this->resolver($numero))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Dicionário direto (`<< ... >>`) ou o do objeto referido: o de antes do acréscimo ou o mais novo.
     *
     * @return array<string, string>|null
     *
     * @throws FormatoNaoReconhecido
     */
    private function dicionarioDoValor(string $valor, bool $anterior): ?array
    {
        if (str_starts_with($valor, '<<')) {
            $fim = 0;

            return $this->sintaxe->dicionario($valor, 0, $fim);
        }
        $numero = LeitorDeSintaxeDoPdf::referencia($valor);
        if ($numero === null) {
            return null;
        }

        return $anterior ? $this->indice->objetoAnterior($numero, $this->inicio)?->dicionario : $this->resolver($numero);
    }

    /**
     * O dicionário mais novo do objeto: o do acréscimo ou, se ele não o define, o de antes.
     *
     * @return array<string, string>|null
     *
     * @throws FormatoNaoReconhecido
     */
    private function resolver(int $numero): ?array
    {
        return ($this->definidos[$numero] ?? $this->indice->objetoAnterior($numero, $this->inicio))?->dicionario;
    }

    /**
     * @param array<string, string>|null $dicionario
     *
     * @throws FormatoNaoReconhecido
     */
    private function assinaturaSemAcao(?array $dicionario): bool
    {
        if ($dicionario === null || isset($dicionario['/A']) || isset($dicionario['/AA'])) {
            return false;
        }
        $subtipo = $dicionario['/Subtype'] ?? null;
        $anotacaoSemSubtipo = isset($dicionario['/Rect']) || ($dicionario['/Type'] ?? null) === '/Annot';
        if ($subtipo === null ? $anotacaoSemSubtipo : $subtipo !== '/Widget') {
            return false;
        }

        return $this->campoDeAssinatura($dicionario);
    }

    /**
     * /FT /Sig no próprio dicionário ou, no widget sem /FT, no /Parent.
     *
     * @param array<string, string> $dicionario
     *
     * @throws FormatoNaoReconhecido
     */
    private function campoDeAssinatura(array $dicionario): bool
    {
        if (isset($dicionario['/FT'])) {
            return $dicionario['/FT'] === '/Sig';
        }
        $pai = LeitorDeSintaxeDoPdf::referencia($dicionario['/Parent'] ?? '');
        if (($dicionario['/Subtype'] ?? null) !== '/Widget' || $pai === null) {
            return false;
        }

        return ($this->resolver($pai)['/FT'] ?? null) === '/Sig';
    }

    /** @param array<string, string> $dicionario */
    private static function anotacao(array $dicionario): bool
    {
        return ($dicionario['/Type'] ?? null) === '/Annot' || isset($dicionario['/Rect'])
            || \in_array($dicionario['/Subtype'] ?? null, self::SUBTIPOS_DE_ANOTACAO, true);
    }

    /** @param array<string, string> $dicionario */
    private static function acao(array $dicionario): bool
    {
        return ($dicionario['/Type'] ?? null) === '/Action' || isset($dicionario['/JS'])
            || \in_array($dicionario['/S'] ?? null, self::TIPOS_DE_ACAO, true);
    }

    /** @param array<string, string>|null $dicionario */
    private static function comAcao(?array $dicionario): string
    {
        return isset($dicionario['/A']) || isset($dicionario['/AA']) ? ' (com ação)' : '';
    }

    /**
     * DSS (/Certs, /CRLs, /OCSPs, /VRI), uma entrada do VRI ou a tabela do VRI (chaves que são resumos).
     *
     * @param array<string, string> $dicionario
     */
    private static function dadosDeValidacao(array $dicionario): bool
    {
        $tipo = $dicionario['/Type'] ?? null;
        if ($tipo === '/DSS' || $tipo === '/VRI') {
            return true;
        }
        if ($tipo !== null || $dicionario === []) {
            return false;
        }
        $chaves = array_keys($dicionario);

        return array_diff($chaves, self::CHAVES_DO_DSS) === [] || array_diff($chaves, self::CHAVES_DO_VRI) === []
            || preg_grep('/^\/[0-9A-Fa-f]{40,128}$/', $chaves, \PREG_GREP_INVERT) === [];
    }

    /**
     * Dicionário de informações do documento (/Info) só com as chaves da norma.
     *
     * @param array<string, string> $dicionario
     */
    private static function informacao(array $dicionario): bool
    {
        return $dicionario !== [] && array_diff(array_keys($dicionario), self::CHAVES_DE_INFORMACAO) === [];
    }

    /**
     * Chaves com valor diferente (ou que só um dos dois tem), em ordem.
     *
     * @param array<string, string> $velho
     * @param array<string, string> $atual
     *
     * @return list<string>
     */
    private static function diferencas(array $velho, array $atual): array
    {
        $chaves = [];
        foreach (array_keys($velho + $atual) as $chave) {
            if (($velho[$chave] ?? null) !== ($atual[$chave] ?? null)) {
                $chaves[] = (string) $chave;
            }
        }
        sort($chaves);

        return $chaves;
    }

    /** @param list<string> $chaves */
    private static function listaDeChaves(array $chaves): string
    {
        $nomes = array_map(self::nomeLegivel(...), \array_slice($chaves, 0, self::CHAVES_NO_MOTIVO));

        return implode(', ', $nomes) . (\count($chaves) > self::CHAVES_NO_MOTIVO ? ' e outras' : '');
    }

    /** Nome do PDF só com letras, números e `/._-`, para o motivo não levar bytes estranhos. */
    private static function nomeLegivel(string $nome): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9\/._-]/', '?', $nome), 0, 40);
    }

    /** O que o objeto é, em português simples. */
    private function descricao(ObjetoDoPdf $objeto): string
    {
        $dicionario = $objeto->dicionario;
        if ($dicionario === null) {
            return 'um objeto que não é dicionário';
        }
        $tipo = $dicionario['/Type'] ?? null;
        $subtipo = $dicionario['/Subtype'] ?? null;
        try {
            $assinatura = $this->campoDeAssinatura($dicionario);
        } catch (FormatoNaoReconhecido) {
            $assinatura = false;
        }

        return match (true) {
            $tipo === '/Page' => 'uma página',
            $tipo === '/Pages' => 'a árvore de páginas',
            $tipo === '/Catalog' => 'o catálogo do documento',
            $tipo === '/Sig' || $tipo === '/DocTimeStamp' => 'uma assinatura',
            self::dadosDeValidacao($dicionario) => 'os dados de validação (DSS)',
            $tipo === '/Metadata' => 'os metadados (XMP)',
            self::informacao($dicionario) => 'as informações do documento',
            $tipo === '/ObjStm' => 'um fluxo de objetos',
            $tipo === '/XRef' => 'um índice de objetos',
            self::acao($dicionario) => 'uma ação',
            $assinatura => 'um campo de assinatura',
            isset($dicionario['/FT']) || $subtipo === '/Widget' => 'um campo de formulário',
            self::anotacao($dicionario) => 'uma anotação',
            $tipo === '/XObject' || ($objeto->fluxo && \in_array($subtipo, ['/Form', '/Image', '/PS'], true)) => 'um desenho ou imagem (XObject)',
            $tipo === '/Font' || $tipo === '/FontDescriptor' => 'uma fonte',
            !isset($dicionario['/Type']) && isset($dicionario['/Fields']) => 'o formulário',
            $objeto->fluxo && $tipo === null => 'um fluxo de conteúdo',
            $tipo !== null => 'um objeto do tipo ' . ltrim(self::nomeLegivel($tipo), '/'),
            default => 'um dicionário sem tipo',
        };
    }

    /**
     * As entradas dos índices do acréscimo e a raiz do documento.
     *
     * @throws FormatoNaoReconhecido
     */
    private function conferirIndices(): ?string
    {
        $raiz = $this->indice->raizAntes($this->inicio);
        foreach ($this->indice->raizesEntre($this->inicio, $this->fim) as $numero) {
            if ($numero !== $raiz) {
                $this->mudou('troca a raiz do documento (/Root)');

                return null;
            }
        }

        foreach ($this->entradas as [$numero, $tipo, $campo]) {
            $falha = $this->conferirEntrada($numero, $tipo, $campo);
            if ($falha !== null || $this->mudanca !== null) {
                return $falha;
            }
        }

        return null;
    }

    /** @throws FormatoNaoReconhecido */
    private function conferirEntrada(int $numero, int $tipo, int $campo): ?string
    {
        $fora = 'o índice do acréscimo aponta o objeto ' . $numero . ' para onde ele não está';
        if ($tipo === 0) {
            if ($numero !== 0 && $this->indice->existiaAntes($numero, $this->inicio)) {
                $antigo = $this->indice->objetoAnterior($numero, $this->inicio);
                $this->mudou('apaga o objeto ' . $numero . ', ' . ($antigo === null ? 'um objeto' : $this->descricao($antigo)));
            }

            return null;
        }
        if ($tipo === 2) {
            $noAcrescimo = isset($this->fluxos[$campo]) && \in_array($numero, $this->fluxos[$campo], true);
            $antes = !isset($this->fluxos[$campo]) && $this->indice->noFluxoMaisNovo($numero, $campo, $this->inicio);

            return $noAcrescimo || $antes ? null : $fora;
        }
        if ($campo >= $this->inicio && $campo < $this->fim) {
            return ($this->cabecalhos[$campo] ?? null) === $numero ? null : $fora;
        }
        if ($campo < $this->inicio && $this->indice->cabecalhoMaisNovo($numero, $campo, $this->inicio)) {
            return null;
        }
        if ($campo < $this->inicio && $this->indice->temCabecalho($numero, $campo)) {
            $this->mudou('volta o objeto ' . $numero . ' para uma versão anterior');

            return null;
        }

        return $fora;
    }
}
