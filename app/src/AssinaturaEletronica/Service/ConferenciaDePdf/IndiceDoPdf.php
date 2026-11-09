<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Índice do arquivo inteiro para a análise do acréscimo (M27), montado uma vez por conferência: onde cada número de
 * objeto tem cabeçalho solto, que números estão em cada /ObjStm, onde ficam as palavras `endobj` e `endstream`, cada
 * `/Root` declarado e o maior número de objeto que já existia antes de cada fronteira (o começo de cada acréscimo).
 *
 * Passar dos tetos (cabeçalhos, fluxos, trabalho) deixa o índice incompleto, e a análise vira "não conferido".
 */
final class IndiceDoPdf
{
    /** @var array<int, list<int>> número => posições dos cabeçalhos soltos, em ordem */
    private array $soltos = [];
    /** @var array<int, list<int>> número => índices dos /ObjStm que o têm, em ordem */
    private array $emFluxos = [];
    /** @var list<array{int, int}> posição do cabeçalho e número de cada /ObjStm lido */
    private array $fluxos = [];
    /** @var list<int> */
    private array $fimDeObjetos = [];
    /** @var list<int> */
    private array $fimDeFluxos = [];
    /** @var array<int, int> fronteira => um a mais que o maior número de objeto antes dela */
    private array $proximoNumero = [];
    /** @var list<array{int, int}> posição e número de cada `/Root N G R` do arquivo */
    private array $raizes = [];
    private bool $completo = true;
    /** @var array{int, array<int, ObjetoDoPdf>}|null o último /ObjStm aberto */
    private ?array $fluxoAberto = null;

    private function __construct(
        private readonly string $pdf,
        private readonly LeitorDeSintaxeDoPdf $sintaxe,
    ) {
    }

    /** @param list<int> $fronteiras */
    public static function montar(string $pdf, LeitorDeSintaxeDoPdf $sintaxe, array $fronteiras): self
    {
        $indice = new self($pdf, $sintaxe);
        try {
            $indice->indexar($fronteiras);
        } catch (FormatoNaoReconhecido) {
            $indice->completo = false;
        }

        return $indice;
    }

    public function completo(): bool
    {
        return $this->completo;
    }

    /** Um a mais que o maior número de objeto que existia antes de `$fronteira` (pelos cabeçalhos, /ObjStm e /Size). */
    public function proximoNumero(int $fronteira): int
    {
        return $this->proximoNumero[$fronteira] ?? 0;
    }

    /**
     * A versão mais nova do objeto antes de `$antesDe`: a do último cabeçalho solto ou a do último /ObjStm que o tem,
     * o que vier depois no arquivo. Null se não achar ou não der para ler.
     *
     * @throws FormatoNaoReconhecido
     */
    public function objetoAnterior(int $numero, int $antesDe): ?ObjetoDoPdf
    {
        $solto = self::ultimaAntes($this->soltos[$numero] ?? [], $antesDe);
        $fluxo = null;
        foreach (array_reverse($this->emFluxos[$numero] ?? []) as $indice) {
            if ($this->fluxos[$indice][0] < $antesDe) {
                $fluxo = $indice;
                break;
            }
        }

        if ($fluxo !== null && ($solto === null || $this->fluxos[$fluxo][0] > $solto)) {
            return $this->abrirFluxo($fluxo)[$numero] ?? null;
        }
        if ($solto === null) {
            return null;
        }
        $cabecalho = LeitorDeSintaxeDoPdf::cabecalhoEm($this->pdf, $solto);

        return $cabecalho === null ? null : $this->objeto($cabecalho[0], $cabecalho[1], $cabecalho[2], $antesDe);
    }

    /** O número do último `/Root` declarado antes de `$antesDe` (trailer ou dicionário de índice). */
    public function raizAntes(int $antesDe): ?int
    {
        $raiz = self::ultimaAntesEmPares($this->raizes, $antesDe);

        return $raiz === null ? null : $raiz[1];
    }

    /**
     * Os números dos `/Root` declarados entre `$de` e `$ate`.
     *
     * @return list<int>
     */
    public function raizesEntre(int $de, int $ate): array
    {
        $numeros = [];
        foreach ($this->raizes as [$posicao, $numero]) {
            if ($posicao >= $de && $posicao < $ate) {
                $numeros[] = $numero;
            }
        }

        return $numeros;
    }

    /** Se há um cabeçalho solto do número nessa posição. */
    public function temCabecalho(int $numero, int $posicao): bool
    {
        return \in_array($posicao, $this->soltos[$numero] ?? [], true);
    }

    /** Se o número tem cabeçalho solto ou está num /ObjStm antes de `$antesDe`. */
    public function existiaAntes(int $numero, int $antesDe): bool
    {
        if (($this->soltos[$numero][0] ?? \PHP_INT_MAX) < $antesDe) {
            return true;
        }
        foreach ($this->emFluxos[$numero] ?? [] as $indice) {
            if ($this->fluxos[$indice][0] < $antesDe) {
                return true;
            }
        }

        return false;
    }

    /** Se a posição é o cabeçalho solto mais novo do número antes de `$antesDe`. */
    public function cabecalhoMaisNovo(int $numero, int $posicao, int $antesDe): bool
    {
        $solto = self::ultimaAntes($this->soltos[$numero] ?? [], $antesDe);
        if ($solto !== $posicao) {
            return false;
        }
        foreach ($this->emFluxos[$numero] ?? [] as $indice) {
            if ($this->fluxos[$indice][0] > $posicao && $this->fluxos[$indice][0] < $antesDe) {
                return false;
            }
        }

        return true;
    }

    /** Se a versão mais nova do número antes de `$antesDe` está no /ObjStm de número `$fluxo`. */
    public function noFluxoMaisNovo(int $numero, int $fluxo, int $antesDe): bool
    {
        $solto = self::ultimaAntes($this->soltos[$numero] ?? [], $antesDe);
        foreach (array_reverse($this->emFluxos[$numero] ?? []) as $indice) {
            [$posicao, $numeroDoFluxo] = $this->fluxos[$indice];
            if ($posicao < $antesDe) {
                return $numeroDoFluxo === $fluxo && ($solto === null || $posicao > $solto);
            }
        }

        return false;
    }

    /**
     * O objeto cujo cabeçalho termina em `$depoisDoCabecalho`, que precisa fechar antes de `$limite`. Null se não fechar.
     * Objeto que não é dicionário vem com o valor em `conteudo` (null se não der para ler ou não fechar no `endobj`).
     *
     * @throws FormatoNaoReconhecido
     */
    public function objeto(int $numero, int $geracao, int $depoisDoCabecalho, int $limite): ?ObjetoDoPdf
    {
        $endobj = self::primeiraDesde($this->fimDeObjetos, $depoisDoCabecalho);
        if ($endobj === null || $endobj + 6 > $limite) {
            return null;
        }

        $fimDoDicionario = $depoisDoCabecalho;
        $dicionario = $this->sintaxe->dicionario($this->pdf, $depoisDoCabecalho, $fimDoDicionario, $endobj);
        if ($dicionario === null) {
            $fimDoValor = $depoisDoCabecalho;
            $conteudo = $this->sintaxe->valorEm($this->pdf, $depoisDoCabecalho, $fimDoValor, $endobj);
            $fechou = LeitorDeSintaxeDoPdf::pularEspacos($this->pdf, $fimDoValor, $endobj) === $endobj;

            return new ObjetoDoPdf($numero, $geracao, null, conteudo: $fechou ? $conteudo : null, depoisDoObjeto: $endobj + 6);
        }

        $depois = LeitorDeSintaxeDoPdf::pularEspacos($this->pdf, $fimDoDicionario, $limite);
        if (substr($this->pdf, $depois, 6) !== 'stream') {
            return $depois === $endobj ? new ObjetoDoPdf($numero, $geracao, $dicionario, depoisDoObjeto: $endobj + 6) : null;
        }

        $inicioDosDados = $depois + 6;
        $inicioDosDados += strspn($this->pdf, "\r", $inicioDosDados, 1);
        $inicioDosDados += strspn($this->pdf, "\n", $inicioDosDados, 1);
        $fimDosDados = $this->fimDosDados($dicionario, $inicioDosDados, $limite);
        if ($fimDosDados === null) {
            return null;
        }
        $depoisDoFluxo = LeitorDeSintaxeDoPdf::pularEspacos($this->pdf, $fimDosDados + 9, $limite);
        if (substr($this->pdf, $depoisDoFluxo, 6) !== 'endobj') {
            return null;
        }

        return new ObjetoDoPdf($numero, $geracao, $dicionario, true, $inicioDosDados, $fimDosDados, depoisDoObjeto: $depoisDoFluxo + 6);
    }

    /** @param list<int> $fronteiras */
    private function indexar(array $fronteiras): void
    {
        $this->fimDeObjetos = $this->posicoes('endobj');
        $this->fimDeFluxos = $this->posicoes('endstream');

        // Cabeçalhos soltos, em ordem; o maior número antes de cada fronteira vai junto.
        sort($fronteiras);
        $maiores = [];
        $maior = -1;
        $proxima = 0;
        $ordem = [];
        $posicao = 0;
        $vistos = 0;
        while (preg_match('/' . LeitorDeSintaxeDoPdf::PADRAO_DO_CABECALHO . '/', $this->pdf, $partes, \PREG_OFFSET_CAPTURE, $posicao) === 1) {
            if (++$vistos > LimitesDaConferencia::CABECALHOS_MAXIMOS_DO_ARQUIVO) {
                throw new LimiteExcedido('Cabeçalhos demais no arquivo.');
            }
            $achado = $partes[0][1];
            $posicao = $achado + \strlen($partes[0][0]);
            while ($proxima < \count($fronteiras) && $fronteiras[$proxima] <= $achado) {
                $maiores[$fronteiras[$proxima++]] = $maior;
            }
            $numeros = LeitorDeSintaxeDoPdf::numerosDoCabecalho($partes[1][0], $partes[2][0]);
            if ($numeros === null) {
                continue;
            }
            $this->soltos[$numeros[0]][] = $achado;
            $ordem[] = [$achado, $numeros[0]];
            $maior = max($maior, $numeros[0]);
        }
        while ($proxima < \count($fronteiras)) {
            $maiores[$fronteiras[$proxima++]] = $maior;
        }

        foreach ($this->tamanhosDeclarados() as [$onde, $tamanho]) {
            foreach ($fronteiras as $fronteira) {
                if ($onde < $fronteira) {
                    $maiores[$fronteira] = max($maiores[$fronteira], $tamanho - 1);
                }
            }
        }

        $this->raizes = $this->raizesDeclaradas();

        $guardados = 0;
        foreach ($this->fluxosDeObjetos($ordem) as $indice => [$onde, $numeros]) {
            $guardados += \count($numeros);
            if ($guardados > LimitesDaConferencia::CABECALHOS_MAXIMOS_DO_ARQUIVO) {
                throw new LimiteExcedido('Objetos demais nos fluxos de objetos.');
            }
            foreach ($numeros as $numero) {
                $this->emFluxos[$numero][] = $indice;
            }
            $maiorNoFluxo = $numeros === [] ? -1 : max($numeros);
            foreach ($fronteiras as $fronteira) {
                if ($onde < $fronteira) {
                    $maiores[$fronteira] = max($maiores[$fronteira], $maiorNoFluxo);
                }
            }
        }

        foreach ($maiores as $fronteira => $numero) {
            $this->proximoNumero[$fronteira] = $numero + 1;
        }
    }

    /** @return list<int> */
    private function posicoes(string $palavra): array
    {
        $posicoes = [];
        $posicao = 0;
        while (($achada = strpos($this->pdf, $palavra, $posicao)) !== false) {
            if (\count($posicoes) >= LimitesDaConferencia::CABECALHOS_MAXIMOS_DO_ARQUIVO) {
                throw new LimiteExcedido('Objetos demais no arquivo.');
            }
            $posicoes[] = $achada;
            $posicao = $achada + \strlen($palavra);
        }

        return $posicoes;
    }

    /**
     * Cada `/Size` do arquivo (trailer e dicionário de índice) e a posição.
     *
     * @return list<array{int, int}>
     */
    private function tamanhosDeclarados(): array
    {
        $tamanhos = [];
        $posicao = 0;
        while (preg_match('/\/Size[\0\t\n\f\r ]*+(\d{1,10})/', $this->pdf, $partes, \PREG_OFFSET_CAPTURE, $posicao) === 1) {
            if (\count($tamanhos) >= LimitesDaConferencia::OBJETOS_MAXIMOS) {
                throw new LimiteExcedido('/Size demais no arquivo.');
            }
            $tamanhos[] = [$partes[0][1], (int) $partes[1][0]];
            $posicao = $partes[0][1] + \strlen($partes[0][0]);
        }

        return $tamanhos;
    }

    /**
     * Cada `/Root N G R` do arquivo e a posição.
     *
     * @return list<array{int, int}>
     */
    private function raizesDeclaradas(): array
    {
        $raizes = [];
        $posicao = 0;
        while (preg_match('/\/Root[\0\t\n\f\r ]*+(\d{1,10})[\0\t\n\f\r ]++\d{1,5}[\0\t\n\f\r ]++R(?![A-Za-z0-9])/', $this->pdf, $partes, \PREG_OFFSET_CAPTURE, $posicao) === 1) {
            if (\count($raizes) >= LimitesDaConferencia::OBJETOS_MAXIMOS) {
                throw new LimiteExcedido('/Root demais no arquivo.');
            }
            $raizes[] = [$partes[0][1], (int) $partes[1][0]];
            $posicao = $partes[0][1] + \strlen($partes[0][0]);
        }

        return $raizes;
    }

    /**
     * Cada /ObjStm do arquivo (o objeto solto que contém o texto `/ObjStm`), aberto uma vez: posição e números que tem.
     * O que não dá para abrir fica de fora; a análise não acha a versão anterior e não confere.
     *
     * @param list<array{int, int}> $ordem posição e número de cada cabeçalho solto, em ordem
     *
     * @return list<array{int, list<int>}>
     */
    private function fluxosDeObjetos(array $ordem): array
    {
        $encontrados = [];
        $vistos = [];
        $posicao = 0;
        while (($achada = strpos($this->pdf, '/ObjStm', $posicao)) !== false) {
            $posicao = $achada + 7;
            $cabecalho = self::ultimaAntesEmPares($ordem, $achada);
            if ($cabecalho === null || isset($vistos[$cabecalho[0]])) {
                continue;
            }
            $vistos[$cabecalho[0]] = true;
            if (\count($vistos) > LimitesDaConferencia::OBJETOS_MAXIMOS) {
                throw new LimiteExcedido('Fluxos de objetos demais.');
            }

            $numeros = LeitorDeSintaxeDoPdf::cabecalhoEm($this->pdf, $cabecalho[0]);
            $objeto = $numeros === null ? null : $this->objeto($numeros[0], $numeros[1], $numeros[2], \strlen($this->pdf));
            if ($objeto === null || !$objeto->fluxo || $objeto->tipo() !== '/ObjStm') {
                continue;
            }
            $dados = $this->sintaxe->dados($this->pdf, $objeto);
            $objetos = $dados === null ? null : $this->sintaxe->objetosDoFluxo($dados, $objeto);
            if ($objetos === null) {
                continue;
            }
            $this->fluxos[] = [$cabecalho[0], $numeros[0]];
            $encontrados[] = [$cabecalho[0], array_keys($objetos)];
        }

        return $encontrados;
    }

    /**
     * @return array<int, ObjetoDoPdf>|null
     *
     * @throws FormatoNaoReconhecido
     */
    private function abrirFluxo(int $indice): ?array
    {
        if ($this->fluxoAberto !== null && $this->fluxoAberto[0] === $indice) {
            return $this->fluxoAberto[1];
        }
        $cabecalho = LeitorDeSintaxeDoPdf::cabecalhoEm($this->pdf, $this->fluxos[$indice][0]);
        $objeto = $cabecalho === null ? null : $this->objeto($cabecalho[0], $cabecalho[1], $cabecalho[2], \strlen($this->pdf));
        if ($objeto === null) {
            return null;
        }
        $dados = $this->sintaxe->dados($this->pdf, $objeto);
        $objetos = $dados === null ? null : $this->sintaxe->objetosDoFluxo($dados, $objeto);
        if ($objetos !== null) {
            $this->fluxoAberto = [$indice, $objetos];
        }

        return $objetos;
    }

    /**
     * Fim dos dados do fluxo: pelo /Length direto quando ele cai num `endstream`; senão, o primeiro `endstream`.
     *
     * @param array<string, string> $dicionario
     */
    private function fimDosDados(array $dicionario, int $inicio, int $limite): ?int
    {
        $tamanho = $dicionario['/Length'] ?? null;
        if ($tamanho !== null && ctype_digit($tamanho) && \strlen($tamanho) <= 9 && $inicio + (int) $tamanho <= $limite) {
            $depois = $inicio + (int) $tamanho;
            $depois += strspn($this->pdf, "\r\n", $depois, min(2, $limite - $depois));
            if (substr($this->pdf, $depois, 9) === 'endstream') {
                return $depois;
            }
        }
        $fim = self::primeiraDesde($this->fimDeFluxos, $inicio);

        return $fim === null || $fim + 9 > $limite ? null : $fim;
    }

    /** @param list<int> $posicoes em ordem */
    private static function primeiraDesde(array $posicoes, int $desde): ?int
    {
        $baixo = 0;
        $alto = \count($posicoes);
        while ($baixo < $alto) {
            $meio = intdiv($baixo + $alto, 2);
            if ($posicoes[$meio] < $desde) {
                $baixo = $meio + 1;
            } else {
                $alto = $meio;
            }
        }

        return $posicoes[$baixo] ?? null;
    }

    /** @param list<int> $posicoes em ordem */
    private static function ultimaAntes(array $posicoes, int $antesDe): ?int
    {
        $ultima = null;
        foreach ($posicoes as $posicao) {
            if ($posicao >= $antesDe) {
                break;
            }
            $ultima = $posicao;
        }

        return $ultima;
    }

    /**
     * @param list<array{int, int}> $pares em ordem de posição
     *
     * @return array{int, int}|null
     */
    private static function ultimaAntesEmPares(array $pares, int $antesDe): ?array
    {
        $baixo = 0;
        $alto = \count($pares);
        while ($baixo < $alto) {
            $meio = intdiv($baixo + $alto, 2);
            if ($pares[$meio][0] < $antesDe) {
                $baixo = $meio + 1;
            } else {
                $alto = $meio;
            }
        }

        return $pares[$baixo - 1] ?? null;
    }
}
