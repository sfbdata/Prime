<?php

declare(strict_types=1);

namespace App\Shared\Texto;

/**
 * Lê os fluxos de um container OLE (Compound File Binary) que está em MEMÓRIA, sem tocar o disco.
 *
 * Toda leitura é conferida contra o tamanho do conteúdo, as cadeias da FAT não aceitam ciclo e os laços têm teto:
 * qualquer inconsistência vira {@see ArquivoSemTextoException}.
 */
final class LeitorDeContainerOle
{
    private const ASSINATURA = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    private const FIM_DA_CADEIA = 0xFFFFFFFE;
    private const LIMITE_DE_ESPECIAIS = 0xFFFFFFFA;
    private const MAXIMO_DE_ENTRADAS = 100_000;
    private const MENSAGEM = 'O arquivo não é um DOC válido.';

    private int $tamanhoDoSetor;
    private int $tamanhoDoMini;
    private int $corteDoMini;

    /** @var list<int> */
    private array $fat = [];

    /** @var list<int>|null */
    private ?array $miniFat = null;

    private ?string $miniFluxo = null;

    /** @var array<string, array{int, int}> nome => [setor inicial, tamanho] */
    private array $fluxos = [];

    /** @var array{int, int} */
    private array $raiz = [self::FIM_DA_CADEIA, 0];

    /** @throws ArquivoSemTextoException */
    public function __construct(private readonly string $bytes)
    {
        if (\strlen($bytes) < 512 || !str_starts_with($bytes, self::ASSINATURA)) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }
        $this->conferirOCabecalho();
        $this->montarAFat();
        $this->lerODiretorio();
    }

    /** O conteúdo do fluxo com esse nome, ou null se ele não existe. */
    public function fluxo(string $nome): ?string
    {
        if (!isset($this->fluxos[$nome])) {
            return null;
        }
        [$inicio, $tamanho] = $this->fluxos[$nome];

        return $tamanho < $this->corteDoMini
            ? $this->lerFluxoPequeno($inicio, $tamanho)
            : $this->lerFluxoGrande($inicio, $tamanho);
    }

    private function conferirOCabecalho(): void
    {
        $deslocamentoDoSetor = $this->u16(30);
        $deslocamentoDoMini = $this->u16(32);
        if ($this->u16(28) !== 0xFFFE || !\in_array($deslocamentoDoSetor, [9, 12], true) || $deslocamentoDoMini !== 6) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }
        $this->tamanhoDoSetor = 1 << $deslocamentoDoSetor;
        $this->tamanhoDoMini = 1 << $deslocamentoDoMini;
        $this->corteDoMini = $this->u32(56);
    }

    private function totalDeSetores(): int
    {
        return intdiv(\strlen($this->bytes), $this->tamanhoDoSetor);
    }

    private function montarAFat(): void
    {
        $setoresDaFat = [];
        for ($i = 0; $i < 109; ++$i) {
            $id = $this->u32(76 + $i * 4);
            if ($id < self::LIMITE_DE_ESPECIAIS) {
                $setoresDaFat[] = $id;
            }
        }

        $proximo = $this->u32(68);
        $voltas = 0;
        while ($proximo < self::LIMITE_DE_ESPECIAIS) {
            if (++$voltas > $this->totalDeSetores()) {
                throw new ArquivoSemTextoException(self::MENSAGEM);
            }
            $setor = $this->setor($proximo);
            for ($i = 0; $i < $this->tamanhoDoSetor / 4 - 1; ++$i) {
                $id = $this->u32Em($setor, $i * 4);
                if ($id < self::LIMITE_DE_ESPECIAIS) {
                    $setoresDaFat[] = $id;
                }
            }
            $proximo = $this->u32Em($setor, $this->tamanhoDoSetor - 4);
        }

        if (\count($setoresDaFat) > $this->totalDeSetores()) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }
        $this->fat = $this->lerTabela($setoresDaFat);
    }

    /**
     * @param list<int> $setores
     *
     * @return list<int>
     */
    private function lerTabela(array $setores): array
    {
        $conteudo = '';
        foreach ($setores as $id) {
            $conteudo .= $this->setor($id);
        }
        $valores = unpack('V*', $conteudo);

        return $valores === false ? [] : array_values($valores);
    }

    private function lerODiretorio(): void
    {
        $diretorio = $this->lerFluxoGrande($this->u32(48), null);
        $total = min(intdiv(\strlen($diretorio), 128), self::MAXIMO_DE_ENTRADAS);
        for ($i = 0; $i < $total; ++$i) {
            $entrada = substr($diretorio, $i * 128, 128);
            $tipo = \ord($entrada[66]);
            $tamanhoDoNome = $this->u16Em($entrada, 64);
            if (!\in_array($tipo, [2, 5], true) || $tamanhoDoNome < 2 || $tamanhoDoNome > 64) {
                continue;
            }
            $nome = mb_convert_encoding(substr($entrada, 0, $tamanhoDoNome - 2), 'UTF-8', 'UTF-16LE');
            $dados = [$this->u32Em($entrada, 116), $this->u32Em($entrada, 120)];
            if ($tipo === 5) {
                $this->raiz = $dados;
            } elseif (!isset($this->fluxos[$nome])) {
                $this->fluxos[$nome] = $dados;
            }
        }
    }

    /** @return list<int> */
    private function cadeia(int $inicio, int $maximoDeSetores): array
    {
        $ids = [];
        $visto = [];
        $id = $inicio;
        while ($id < self::LIMITE_DE_ESPECIAIS) {
            if (isset($visto[$id]) || \count($ids) >= $maximoDeSetores || !isset($this->fat[$id])) {
                throw new ArquivoSemTextoException(self::MENSAGEM);
            }
            $visto[$id] = true;
            $ids[] = $id;
            $id = $this->fat[$id];
        }

        return $ids;
    }

    private function lerFluxoGrande(int $inicio, ?int $tamanho): string
    {
        if ($tamanho !== null && $tamanho > \strlen($this->bytes)) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }
        $conteudo = '';
        foreach ($this->cadeia($inicio, $this->totalDeSetores()) as $id) {
            $conteudo .= $this->setor($id);
        }
        if ($tamanho === null) {
            return $conteudo;
        }
        if (\strlen($conteudo) < $tamanho) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }

        return substr($conteudo, 0, $tamanho);
    }

    private function lerFluxoPequeno(int $inicio, int $tamanho): string
    {
        $miniFluxo = $this->miniFluxo ??= $this->lerFluxoGrande($this->raiz[0], $this->raiz[1]);
        $miniFat = $this->miniFat ??= $this->lerMiniFat();
        $conteudo = '';
        $visto = [];
        $id = $inicio;
        while ($id < self::LIMITE_DE_ESPECIAIS && \strlen($conteudo) < $tamanho) {
            $pedaco = substr($miniFluxo, $id * $this->tamanhoDoMini, $this->tamanhoDoMini);
            if (isset($visto[$id]) || !isset($miniFat[$id]) || \strlen($pedaco) !== $this->tamanhoDoMini) {
                throw new ArquivoSemTextoException(self::MENSAGEM);
            }
            $visto[$id] = true;
            $conteudo .= $pedaco;
            $id = $miniFat[$id];
        }
        if (\strlen($conteudo) < $tamanho) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }

        return substr($conteudo, 0, $tamanho);
    }

    /** @return list<int> */
    private function lerMiniFat(): array
    {
        $inicio = $this->u32(60);
        if ($inicio >= self::LIMITE_DE_ESPECIAIS) {
            return [];
        }

        return $this->lerTabela($this->cadeia($inicio, $this->totalDeSetores()));
    }

    private function setor(int $id): string
    {
        $deslocamento = ($id + 1) * $this->tamanhoDoSetor;
        if ($deslocamento + $this->tamanhoDoSetor > \strlen($this->bytes)) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }

        return substr($this->bytes, $deslocamento, $this->tamanhoDoSetor);
    }

    private function u16(int $posicao): int
    {
        return $this->u16Em($this->bytes, $posicao);
    }

    private function u32(int $posicao): int
    {
        return $this->u32Em($this->bytes, $posicao);
    }

    private function u16Em(string $dados, int $posicao): int
    {
        if ($posicao < 0 || $posicao + 2 > \strlen($dados)) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }

        return \ord($dados[$posicao]) | (\ord($dados[$posicao + 1]) << 8);
    }

    private function u32Em(string $dados, int $posicao): int
    {
        return $this->u16Em($dados, $posicao) | ($this->u16Em($dados, $posicao + 2) << 16);
    }
}
