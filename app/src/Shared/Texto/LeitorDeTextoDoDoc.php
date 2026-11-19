<?php

declare(strict_types=1);

namespace App\Shared\Texto;

/**
 * Tira o texto principal de um Word 97-2003 ([MS-DOC]) que está em memória: FIB, tabela de peças (Clx/Pcdt) e os
 * caracteres de cada peça, comprimidos (Windows-1252) ou UTF-16LE. Cabeçalho, rodapé, nota e caixa de texto ficam
 * de fora (só os caracteres de 0 a ccpText). Arquivo cifrado é recusado.
 *
 * Limite conhecido: o arquivo salvo em "salvamento rápido" com várias peças é lido peça a peça, na ordem da tabela.
 */
final class LeitorDeTextoDoDoc
{
    private const MENSAGEM = 'O arquivo não é um DOC válido.';
    private const MAXIMO_DE_PECAS = 100_000;
    private const MAXIMO_DE_CARACTERES_BRUTOS = 1_000_000;
    private const MAXIMO_DE_CAMPOS_ANINHADOS = 1000;

    /** @throws ArquivoSemTextoException */
    public function ler(string $bytes): string
    {
        $container = new LeitorDeContainerOle($bytes);
        $documento = $container->fluxo('WordDocument');
        if ($documento === null || $this->u16($documento, 0) !== 0xA5EC) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }
        $opcoes = $this->u16($documento, 0x0A);
        if (($opcoes & 0x0100) !== 0) {
            throw new ArquivoSemTextoException('O arquivo está protegido e não pode ser lido.');
        }
        $tabela = $container->fluxo(($opcoes & 0x0200) !== 0 ? '1Table' : '0Table');
        if ($tabela === null) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }

        $bruto = $this->textoDasPecas($documento, $this->plcPcd($documento, $tabela), $this->u32($documento, 0x4C));

        return $this->limpar($bruto);
    }

    private function plcPcd(string $documento, string $tabela): string
    {
        $inicio = $this->u32($documento, 0x01A2);
        $tamanho = $this->u32($documento, 0x01A6);
        if ($inicio + $tamanho > \strlen($tabela)) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }
        $clx = substr($tabela, $inicio, $tamanho);

        $posicao = 0;
        while ($posicao < $tamanho) {
            $tipo = \ord($clx[$posicao]);
            if ($tipo === 0x01) {
                $posicao += 3 + $this->u16($clx, $posicao + 1);

                continue;
            }
            if ($tipo !== 0x02) {
                break;
            }
            $comprimento = $this->u32($clx, $posicao + 1);
            if ($posicao + 5 + $comprimento > $tamanho) {
                break;
            }

            return substr($clx, $posicao + 5, $comprimento);
        }

        throw new ArquivoSemTextoException(self::MENSAGEM);
    }

    private function textoDasPecas(string $documento, string $plc, int $ccpText): string
    {
        $tamanho = \strlen($plc);
        if ($tamanho < 16 || ($tamanho - 4) % 12 !== 0) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }
        $pecas = intdiv($tamanho - 4, 12);
        if ($pecas > self::MAXIMO_DE_PECAS) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }

        $texto = '';
        $quantidade = 0;
        for ($i = 0; $i < $pecas && $quantidade < self::MAXIMO_DE_CARACTERES_BRUTOS; ++$i) {
            $inicio = $this->u32($plc, $i * 4);
            $fim = min($this->u32($plc, ($i + 1) * 4), $ccpText);
            if ($inicio >= $ccpText) {
                break;
            }
            if ($fim <= $inicio) {
                continue;
            }
            $fc = $this->u32($plc, ($pecas + 1) * 4 + $i * 8 + 2);
            $texto .= $this->caracteresDaPeca($documento, $fc, $fim - $inicio);
            $quantidade += $fim - $inicio;
        }

        return $texto;
    }

    private function caracteresDaPeca(string $documento, int $fc, int $caracteres): string
    {
        $comprimido = ($fc & 0x40000000) !== 0;
        $deslocamento = $fc & 0x3FFFFFFF;
        if ($comprimido) {
            $deslocamento = intdiv($deslocamento, 2);
        }
        $tamanho = $comprimido ? $caracteres : $caracteres * 2;
        if ($deslocamento + $tamanho > \strlen($documento)) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }
        $dados = substr($documento, $deslocamento, $tamanho);

        return mb_convert_encoding($dados, 'UTF-8', $comprimido ? 'Windows-1252' : 'UTF-16LE');
    }

    /** Controles do Word viram texto; do campo (`\x13 código \x14 resultado \x15`) fica só o resultado. */
    private function limpar(string $bruto): string
    {
        $partes = preg_split('/([\x00-\x1F])/', $bruto, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($partes === false) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }

        $saida = '';
        $campos = [];
        $noCodigo = 0;
        foreach ($partes as $parte) {
            if ($parte === '') {
                continue;
            }
            if (\strlen($parte) > 1 || \ord($parte) >= 0x20) {
                $saida .= $noCodigo > 0 ? '' : $parte;

                continue;
            }
            if ($parte === "\x13" || $parte === "\x14" || $parte === "\x15") {
                $this->campo($parte, $campos, $noCodigo);

                continue;
            }
            $saida .= $noCodigo > 0 ? '' : $this->controle($parte);
        }

        return $saida;
    }

    /**
     * Pilha de campos: `true` enquanto está no código (texto suprimido), `false` depois do separador `\x14`.
     * `$noCodigo` conta os `true` da pilha, para o texto não varrer a pilha a cada parte.
     *
     * @param list<bool> $campos
     */
    private function campo(string $marca, array &$campos, int &$noCodigo): void
    {
        if ($marca === "\x13") {
            if (\count($campos) >= self::MAXIMO_DE_CAMPOS_ANINHADOS) {
                throw new ArquivoSemTextoException(self::MENSAGEM);
            }
            $campos[] = true;
            ++$noCodigo;
        } elseif ($marca === "\x14" && $campos !== []) {
            $noCodigo -= $campos[\count($campos) - 1] ? 1 : 0;
            $campos[\count($campos) - 1] = false;
        } elseif ($marca === "\x15" && $campos !== []) {
            $noCodigo -= array_pop($campos) ? 1 : 0;
        }
    }

    private function controle(string $caractere): string
    {
        return match ($caractere) {
            "\r", "\x0B", "\x0C" => "\n",
            "\x07" => "\t",
            "\t", "\n" => $caractere,
            default => '',
        };
    }

    private function u16(string $dados, int $posicao): int
    {
        if ($posicao < 0 || $posicao + 2 > \strlen($dados)) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }

        return \ord($dados[$posicao]) | (\ord($dados[$posicao + 1]) << 8);
    }

    private function u32(string $dados, int $posicao): int
    {
        return $this->u16($dados, $posicao) | ($this->u16($dados, $posicao + 2) << 16);
    }
}
