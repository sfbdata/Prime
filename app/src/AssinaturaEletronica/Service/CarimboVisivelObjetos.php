<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Service\ConferenciaDePdf\InfladorLimitado;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;

/**
 * Interna do `LocalizadorDaUltimaPagina`: os objetos do PDF numa procura da última página, com um orçamento para a
 * procura inteira. A regra de qual versão vale é a do `objeto` do protótipo (bj-assina-pdf.js:21) e do
 * `LeitorDeObjetosDoPdf` do motor: a última ocorrência solta de `N G obj`; sem ela, o primeiro /ObjStm que tem o
 * número, do último para o primeiro. Como lá, um objeto solto antigo vence a cópia mais nova dentro de um /ObjStm.
 *
 * O leitor do motor varre o arquivo e infla os /ObjStm a cada objeto; aqui o arquivo é varrido uma vez, cada /ObjStm
 * é inflado no máximo uma vez e cada objeto é lido e analisado uma vez só. Tetos da procura (no estilo dos M22 e M27
 * do motor):
 * - objetos diferentes lidos, e o tamanho do corpo de cada um;
 * - trabalho somado: bytes varridos, copiados, inflados e analisados, mais cada passo da análise e cada volta no
 *   cabeçalho de um /ObjStm, com um peso em bytes;
 * - memória guardada: corpos, dados dos /ObjStm, entradas dos índices e o que a análise dos dicionários guarda (estas
 *   três por estimativa do custo no PHP);
 * - entradas dos índices: cabeçalhos soltos e o /N de cada /ObjStm, somado antes de percorrer o cabeçalho dele.
 * Passar de um deles lança `\OverflowException`, que o localizador transforma em página não localizada.
 *
 * O teto do inflado de um /ObjStm é o menor entre o do motor e o que ainda cabe guardar (descontada a cópia do
 * comprimido): mais restrito que o motor, pode recusar um PDF que o motor leria. É uma recusa conservadora.
 */
final class CarimboVisivelObjetos
{
    /** Objetos diferentes lidos numa procura: catálogo, nós, filhos vazios pulados, página, caixas e anotações. */
    public const OBJETOS_MAXIMOS = 64;
    /** Corpo de um objeto: catálogo, nó /Pages e página são pequenos; um /Kids de 10.000 referências cabe em ~150 KB. */
    public const CORPO_MAXIMO_EM_BYTES = 1024 * 1024;
    /** Trabalho somado da procura inteira: o teto de uma leitura do motor (M22), aqui para a procura toda. */
    public const TRABALHO_MAXIMO_EM_BYTES = LimitesDaConferencia::TRABALHO_MAXIMO_EM_BYTES;
    /**
     * Peso de um passo da análise (uma entrada, um pedaço de vetor ou dicionário, uma volta num texto ou comentário):
     * uma volta no PHP custa o tempo de ~1 KB varrido por uma função nativa.
     */
    public const PASSO_EM_BYTES = 1024;
    /** Peso de uma volta no cabeçalho de um /ObjStm, mais leve que um passo da análise. */
    public const VOLTA_DO_CABECALHO_EM_BYTES = 64;
    /** Memória guardada na procura: corpos, dados dos /ObjStm, índices e análises. */
    public const GUARDADO_MAXIMO_EM_BYTES = 16 * 1024 * 1024;
    /** Estimativa da memória de uma entrada de índice (número => posição) num vetor do PHP (medido: ~52 a 80 bytes). */
    public const ENTRADA_DO_INDICE_EM_BYTES = 80;
    /** Entradas dos índices: cabeçalhos soltos do arquivo e o /N dos /ObjStm lidos (M27). */
    public const ENTRADAS_MAXIMAS = LimitesDaConferencia::CABECALHOS_MAXIMOS_DO_ARQUIVO;

    /** Até onde o dicionário de um /ObjStm é procurado, para trás e para a frente do marcador, como no motor. */
    private const JANELA_DO_DICIONARIO = 4096;
    /** Número e geração numa chave inteira: a geração tem até 5 algarismos e o número até 9. */
    private const GERACOES = 100_000;
    private const NUMEROS = 1_000_000_000;

    private int $trabalho = 0;
    private int $guardado = 0;
    private int $entradas = 0;
    /** @var array<int, int>|null número * GERACOES + geração => posição do último cabeçalho solto */
    private ?array $soltos = null;
    /** @var list<int>|null marcadores /Type/ObjStm que faltam ler, do primeiro ao último */
    private ?array $marcadores = null;
    private bool $semMaisFluxos = false;
    /** @var array<int, int> número => posição do `stream` do /ObjStm que vale para ele */
    private array $doFluxo = [];
    /** @var array<int, array{string, int, int}> posição do `stream` => dados, /N e /First */
    private array $fluxos = [];
    /** @var array<string, string|null> "numero geracao" => corpo lido */
    private array $lidos = [];
    /** @var array<string, mixed> "numero geracao" => resultado da análise */
    private array $analisados = [];

    public function __construct(
        private readonly string $pdf,
    ) {
    }

    /** O corpo do objeto (entre `obj` e `endobj`, sem os espaços das pontas), lido uma vez; null se não há. */
    public function objeto(int $numero, int $geracao): ?string
    {
        $chave = $numero . ' ' . $geracao;
        if (\array_key_exists($chave, $this->lidos)) {
            return $this->lidos[$chave];
        }
        if (\count($this->lidos) >= self::OBJETOS_MAXIMOS) {
            throw new \OverflowException('Objetos demais na procura da página.');
        }
        $corpo = $this->solto($numero, $geracao) ?? $this->emFluxo($numero);
        $this->guardar(\strlen((string) $corpo));

        return $this->lidos[$chave] = $corpo;
    }

    /**
     * A análise do corpo do objeto, feita uma vez por objeto, com o tamanho do corpo somado ao trabalho antes dela.
     *
     * @template T
     *
     * @param \Closure(string): T $analisar
     *
     * @return T|null null se o objeto não existe
     */
    public function analisado(int $numero, int $geracao, \Closure $analisar): mixed
    {
        $chave = $numero . ' ' . $geracao;
        if (\array_key_exists($chave, $this->analisados)) {
            return $this->analisados[$chave];
        }
        $corpo = $this->objeto($numero, $geracao);
        if ($corpo !== null) {
            $this->gastar(\strlen($corpo));
        }

        return $this->analisados[$chave] = $corpo === null ? null : $analisar($corpo);
    }

    /** Soma ao trabalho da procura; passar do teto lança. */
    public function gastar(int $bytes): void
    {
        $this->trabalho += $bytes;
        if ($this->trabalho > self::TRABALHO_MAXIMO_EM_BYTES) {
            throw new \OverflowException('Trabalho demais na procura da página.');
        }
    }

    /** Soma passos da análise ao trabalho, cada um com o peso `PASSO_EM_BYTES`. */
    public function passos(int $quantos = 1): void
    {
        $this->gastar($quantos * self::PASSO_EM_BYTES);
    }

    /** Soma ao que a procura guarda em memória, antes de guardar; passar do teto lança. */
    public function guardar(int $bytes): void
    {
        $this->guardado += $bytes;
        if ($this->guardado > self::GUARDADO_MAXIMO_EM_BYTES) {
            throw new \OverflowException('Dados demais guardados na procura da página.');
        }
    }

    public function trabalho(): int
    {
        return $this->trabalho;
    }

    public function guardado(): int
    {
        return $this->guardado;
    }

    private function contarEntradas(int $quantas): void
    {
        $this->entradas += $quantas;
        if ($this->entradas > self::ENTRADAS_MAXIMAS) {
            throw new \OverflowException('Objetos demais no arquivo para a procura da página.');
        }
        $this->guardar($quantas * self::ENTRADA_DO_INDICE_EM_BYTES);
    }

    private function exigirCorpoPequeno(int $tamanho): void
    {
        if ($tamanho > self::CORPO_MAXIMO_EM_BYTES) {
            throw new \OverflowException('Objeto grande demais na procura da página.');
        }
    }

    private function solto(int $numero, int $geracao): ?string
    {
        $this->soltos ??= $this->indexarSoltos();
        $cabecalho = $numero < self::NUMEROS && $geracao < self::GERACOES ? $this->soltos[$numero * self::GERACOES + $geracao] ?? null : null;
        if ($cabecalho === null) {
            return null;
        }
        $inicio = (int) strpos($this->pdf, 'obj', $cabecalho) + 3;
        $fim = strpos($this->pdf, 'endobj', $inicio);
        $this->gastar(($fim === false ? \strlen($this->pdf) : $fim) - $inicio);
        if ($fim === false) {
            return null;
        }
        $this->exigirCorpoPequeno($fim - $inicio);

        return trim(substr($this->pdf, $inicio, $fim - $inicio));
    }

    /**
     * Cada cabeçalho solto do arquivo, numa varredura só, com o padrão do leitor do motor; vale o último. Número de mais
     * de 9 algarismos ou geração de mais de 5 não entram (nenhuma referência da procura chega neles).
     *
     * @return array<int, int>
     */
    private function indexarSoltos(): array
    {
        $this->gastar(\strlen($this->pdf));
        $soltos = [];
        $posicao = 0;
        while (preg_match('/(?:^|[\s>])(\d++)\s++(\d++)\s++obj\b/', $this->pdf, $partes, \PREG_OFFSET_CAPTURE, $posicao) === 1) {
            $this->contarEntradas(1);
            if (\strlen($partes[1][0]) <= 9 && \strlen($partes[2][0]) <= 5) {
                $soltos[(int) $partes[1][0] * self::GERACOES + (int) $partes[2][0]] = $partes[0][1];
            }
            $posicao = $partes[0][1] + \strlen($partes[0][0]);
        }

        return $soltos;
    }

    /** O objeto dentro de um /ObjStm, lendo os fluxos do último para o primeiro só até achá-lo. */
    private function emFluxo(int $numero): ?string
    {
        while (!isset($this->doFluxo[$numero])) {
            if (!$this->lerProximoFluxo()) {
                return null;
            }
        }
        [$dados, $quantidade, $primeiro] = $this->fluxos[$this->doFluxo[$numero]];
        $cabecalho = $this->cabecalho($dados, $quantidade, $primeiro);
        foreach (array_keys($cabecalho, $numero, true) as $posicao) {
            if ($posicao % 2 === 0 && $posicao < 2 * $quantidade) {
                $inicio = $primeiro + ($cabecalho[$posicao + 1] ?? 0);
                $fim = $posicao + 2 < 2 * $quantidade ? $primeiro + ($cabecalho[$posicao + 3] ?? 0) : \strlen($dados);
                if ($fim < $inicio) {
                    return null;
                }
                $this->exigirCorpoPequeno($fim - $inicio);

                return trim(substr($dados, $inicio, $fim - $inicio));
            }
        }

        return null;
    }

    /**
     * Lê o próximo /ObjStm (de trás para a frente) e registra os números dele que ainda não têm fluxo. Falso quando
     * não há mais, ou quando um deles não dá para ler: aí, como no motor, a procura em fluxos para.
     */
    private function lerProximoFluxo(): bool
    {
        $this->marcadores ??= $this->marcadoresDeFluxo();
        while (!$this->semMaisFluxos && $this->marcadores !== []) {
            $dicionario = $this->dicionarioDoFluxo(array_pop($this->marcadores));
            if ($dicionario === null) {
                break;
            }
            [$texto, $comeco] = $dicionario;
            if (isset($this->fluxos[$comeco])) {
                continue;
            }
            $corpo = $this->corpoDoFluxo($texto, $comeco);
            if ($corpo === null) {
                break;
            }
            $this->fluxos[$comeco] = $corpo;
            $this->registrar($corpo, $comeco);

            return true;
        }
        $this->semMaisFluxos = true;

        return false;
    }

    /** @return list<int> */
    private function marcadoresDeFluxo(): array
    {
        $this->gastar(\strlen($this->pdf));
        $marcadores = [];
        $posicao = 0;
        while (preg_match('/\/Type\s*+\/ObjStm/', $this->pdf, $partes, \PREG_OFFSET_CAPTURE, $posicao) === 1) {
            if (\count($marcadores) >= LimitesDaConferencia::OBJETOS_MAXIMOS) {
                $this->semMaisFluxos = true;

                return [];
            }
            $marcadores[] = $partes[0][1];
            $posicao = $partes[0][1] + \strlen($partes[0][0]);
        }

        return $marcadores;
    }

    /**
     * O texto do dicionário do fluxo e a posição da palavra `stream`, nas janelas do motor (M22).
     *
     * @return array{string, int}|null
     */
    private function dicionarioDoFluxo(int $marcador): ?array
    {
        $janela = max(0, $marcador - self::JANELA_DO_DICIONARIO);
        $antes = substr($this->pdf, $janela, $marcador - $janela);
        $depois = substr($this->pdf, $marcador, self::JANELA_DO_DICIONARIO);
        $this->gastar(\strlen($antes) + \strlen($depois));
        $obj = strrpos($antes, ' obj');
        $stream = strpos($depois, 'stream');
        if ($obj === false || $stream === false) {
            return null;
        }
        $comeco = $marcador + $stream;

        return [substr($this->pdf, $janela + $obj + 4, $comeco - $janela - $obj - 4), $comeco];
    }

    /**
     * Os dados do fluxo (inflados se for FlateDecode), o /N e o /First. A cópia do comprimido e o inflado, juntos,
     * cabem no que ainda resta guardar; senão, null.
     *
     * @return array{string, int, int}|null
     */
    private function corpoDoFluxo(string $dicionario, int $comeco): ?array
    {
        if (preg_match('/\/N\s++(\d{1,9})/', $dicionario, $n) !== 1 || preg_match('/\/First\s++(\d{1,9})/', $dicionario, $f) !== 1
            || (int) $n[1] > LimitesDaConferencia::OBJETOS_MAXIMOS) {
            return null;
        }
        $inicio = $comeco + 6;
        $inicio += ($this->pdf[$inicio] ?? '') === "\r" ? 1 : 0;
        $inicio += ($this->pdf[$inicio] ?? '') === "\n" ? 1 : 0;
        $fim = strpos($this->pdf, 'endstream', $inicio);
        $this->gastar(($fim === false ? \strlen($this->pdf) : $fim) - $inicio);
        $restante = self::GUARDADO_MAXIMO_EM_BYTES - $this->guardado;
        if ($fim === false || $fim - $inicio > $restante) {
            return null;
        }
        $dados = substr($this->pdf, $inicio, $fim - $inicio);
        if (str_contains($dicionario, 'FlateDecode')) {
            $limite = min(LimitesDaConferencia::INFLADO_MAXIMO_EM_BYTES, $restante - \strlen($dados));
            $dados = InfladorLimitado::inflar(rtrim($dados, "\r\n"), max(0, $limite));
            if ($dados === null) {
                return null;
            }
            $this->gastar(\strlen($dados));
        }
        $this->guardar(\strlen($dados));

        return (int) $f[1] <= \strlen($dados) ? [$dados, (int) $n[1], (int) $f[1]] : null;
    }

    /**
     * Registra os números do fluxo. O /N entra no teto de entradas antes do laço, e o laço só percorre os pares que o
     * cabeçalho tem (no máximo /N), cada volta somada ao trabalho.
     *
     * @param array{string, int, int} $corpo
     */
    private function registrar(array $corpo, int $comeco): void
    {
        [$dados, $quantidade, $primeiro] = $corpo;
        $this->contarEntradas($quantidade);
        $cabecalho = $this->cabecalho($dados, $quantidade, $primeiro);
        $voltas = min($quantidade, intdiv(\count($cabecalho) + 1, 2));
        $this->gastar($voltas * self::VOLTA_DO_CABECALHO_EM_BYTES);
        for ($i = 0; $i < $voltas; ++$i) {
            $this->doFluxo[$cabecalho[2 * $i]] ??= $comeco;
        }
    }

    /** @return list<int> os pares número e deslocamento do começo do fluxo, como o motor lê */
    private function cabecalho(string $dados, int $quantidade, int $primeiro): array
    {
        $this->gastar($primeiro);
        $partes = preg_split('/\s++/', trim(substr($dados, 0, $primeiro)), 2 * $quantidade + 1);

        return $partes === false ? [] : array_map('intval', $partes);
    }
}
