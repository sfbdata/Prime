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
 * do motor): objetos diferentes lidos, trabalho somado (bytes varridos, copiados, inflados e analisados), bytes
 * guardados (o que fica em memória) e entradas dos índices. Passar de um deles lança `\OverflowException`, que o
 * localizador transforma em página não localizada.
 */
final class CarimboVisivelObjetos
{
    /** Objetos diferentes lidos numa procura: catálogo, nós, filhos vazios pulados, página, caixas e anotações. */
    public const OBJETOS_MAXIMOS = 64;
    /** Trabalho somado da procura inteira: o teto de uma leitura do motor (M22), aqui para a procura toda. */
    public const TRABALHO_MAXIMO_EM_BYTES = LimitesDaConferencia::TRABALHO_MAXIMO_EM_BYTES;
    /** Bytes guardados em memória: corpos lidos e dados dos /ObjStm (inflados ou não). */
    public const GUARDADO_MAXIMO_EM_BYTES = 16 * 1024 * 1024;
    /** Entradas dos índices: cabeçalhos soltos do arquivo e objetos dos /ObjStm lidos (M27). */
    public const ENTRADAS_MAXIMAS = LimitesDaConferencia::CABECALHOS_MAXIMOS_DO_ARQUIVO;

    /** Até onde o dicionário de um /ObjStm é procurado, para trás e para a frente do marcador, como no motor. */
    private const JANELA_DO_DICIONARIO = 4096;

    private int $trabalho = 0;
    private int $guardado = 0;
    private int $entradas = 0;
    /** @var array<string, int>|null "numero geracao" => posição do último cabeçalho solto */
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
        $corpo = $this->solto($chave) ?? $this->emFluxo($numero);
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

    public function trabalho(): int
    {
        return $this->trabalho;
    }

    public function guardado(): int
    {
        return $this->guardado;
    }

    private function guardar(int $bytes): void
    {
        $this->guardado += $bytes;
        if ($this->guardado > self::GUARDADO_MAXIMO_EM_BYTES) {
            throw new \OverflowException('Dados demais guardados na procura da página.');
        }
    }

    private function contarEntrada(): void
    {
        if (++$this->entradas > self::ENTRADAS_MAXIMAS) {
            throw new \OverflowException('Objetos demais no arquivo para a procura da página.');
        }
    }

    private function solto(string $chave): ?string
    {
        $this->soltos ??= $this->indexarSoltos();
        $cabecalho = $this->soltos[$chave] ?? null;
        if ($cabecalho === null) {
            return null;
        }
        $inicio = (int) strpos($this->pdf, 'obj', $cabecalho) + 3;
        $fim = strpos($this->pdf, 'endobj', $inicio);
        $this->gastar(($fim === false ? \strlen($this->pdf) : $fim) - $inicio);

        return $fim === false ? null : trim(substr($this->pdf, $inicio, $fim - $inicio));
    }

    /**
     * Cada cabeçalho solto do arquivo, numa varredura só, com o padrão do leitor do motor; vale o último.
     *
     * @return array<string, int>
     */
    private function indexarSoltos(): array
    {
        $this->gastar(\strlen($this->pdf));
        $soltos = [];
        $posicao = 0;
        while (preg_match('/(?:^|[\s>])(\d++)\s++(\d++)\s++obj\b/', $this->pdf, $partes, \PREG_OFFSET_CAPTURE, $posicao) === 1) {
            $this->contarEntrada();
            $soltos[$partes[1][0] . ' ' . $partes[2][0]] = $partes[0][1];
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
        for ($i = 0; $i < $quantidade; ++$i) {
            if (($cabecalho[2 * $i] ?? null) === $numero) {
                $inicio = $primeiro + ($cabecalho[2 * $i + 1] ?? 0);
                $fim = $i + 1 < $quantidade ? $primeiro + ($cabecalho[2 * $i + 3] ?? 0) : \strlen($dados);

                return $fim >= $inicio ? trim(substr($dados, $inicio, $fim - $inicio)) : null;
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
     * Os dados do fluxo (inflados se for FlateDecode, com o teto do que ainda cabe guardar), o /N e o /First.
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
        if ($fim === false) {
            return null;
        }
        $dados = substr($this->pdf, $inicio, $fim - $inicio);
        if (str_contains($dicionario, 'FlateDecode')) {
            $limite = min(LimitesDaConferencia::INFLADO_MAXIMO_EM_BYTES, self::GUARDADO_MAXIMO_EM_BYTES - $this->guardado);
            $dados = InfladorLimitado::inflar(rtrim($dados, "\r\n"), max(0, $limite));
            if ($dados === null) {
                return null;
            }
            $this->gastar(\strlen($dados));
        }
        $this->guardar(\strlen($dados));

        return (int) $f[1] <= \strlen($dados) ? [$dados, (int) $n[1], (int) $f[1]] : null;
    }

    /** @param array{string, int, int} $corpo */
    private function registrar(array $corpo, int $comeco): void
    {
        [$dados, $quantidade, $primeiro] = $corpo;
        $cabecalho = $this->cabecalho($dados, $quantidade, $primeiro);
        for ($i = 0; $i < $quantidade; ++$i) {
            $numero = $cabecalho[2 * $i] ?? null;
            if ($numero !== null && !isset($this->doFluxo[$numero])) {
                $this->contarEntrada();
                $this->doFluxo[$numero] = $comeco;
            }
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
