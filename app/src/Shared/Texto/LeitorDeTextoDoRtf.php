<?php

declare(strict_types=1);

namespace App\Shared\Texto;

/**
 * Tira o texto de um RTF em memória, sem interpretar nada além de texto: grupos `\*` e destinos de formatação,
 * imagem e objeto saem inteiros; parágrafos, tabulações e células viram quebras e tabulações; `\'hh` e `\uN` viram
 * caracteres. Code pages diferentes de Windows-1252 são lidas como Windows-1252.
 *
 * Guarda estado da leitura em atributos: cada chamada de {@see ler()} o reinicia.
 */
final class LeitorDeTextoDoRtf
{
    private const MENSAGEM = 'O arquivo não é um RTF válido.';
    private const MAXIMO_DE_GRUPOS = 1000;
    private const DESTINOS_IGNORADOS = [
        'fonttbl', 'colortbl', 'stylesheet', 'info', 'pict', 'object', 'themedata', 'datastore', 'colorschememapping',
        'latentstyles', 'listtable', 'listoverridetable', 'rsidtbl', 'generator', 'xmlnstbl', 'fldinst',
        'header', 'headerl', 'headerr', 'headerf', 'footer', 'footerl', 'footerr', 'footerf',
        'bkmkstart', 'bkmkend', 'shpinst', 'nonshppict', 'private',
    ];

    private string $bytes = '';
    private int $posicao = 0;
    private string $saida = '';

    /** @var list<bool> */
    private array $ignorar = [false];

    /** @var list<int> */
    private array $uc = [1];

    private int $pular = 0;
    private int $altaPendente = 0;

    /** @throws ArquivoSemTextoException */
    public function ler(string $bytes): string
    {
        $this->bytes = $bytes;
        $this->posicao = 0;
        $this->saida = '';
        $this->ignorar = [false];
        $this->uc = [1];
        $this->pular = 0;
        $this->altaPendente = 0;

        $tamanho = \strlen($bytes);
        while ($this->posicao < $tamanho) {
            $c = $bytes[$this->posicao];
            if ($c === '{') {
                $this->abrirGrupo();
            } elseif ($c === '}') {
                $this->fecharGrupo();
            } elseif ($c === '\\') {
                ++$this->posicao;
                $this->controle();
            } else {
                $this->literal();
            }
        }

        return $this->saida;
    }

    private function abrirGrupo(): void
    {
        if (\count($this->ignorar) >= self::MAXIMO_DE_GRUPOS) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }
        $this->ignorar[] = $this->ignorar[\count($this->ignorar) - 1];
        $this->uc[] = $this->uc[\count($this->uc) - 1];
        ++$this->posicao;
        if (substr($this->bytes, $this->posicao, 2) === '\\*') {
            $this->ignorar[\count($this->ignorar) - 1] = true;
        }
    }

    private function fecharGrupo(): void
    {
        if (\count($this->ignorar) > 1) {
            array_pop($this->ignorar);
            array_pop($this->uc);
        }
        ++$this->posicao;
    }

    private function literal(): void
    {
        $tamanho = strcspn($this->bytes, "\\{}\r\n", $this->posicao);
        $trecho = substr($this->bytes, $this->posicao, $tamanho);
        $this->posicao += max(1, $tamanho);
        if ($tamanho === 0) {
            return;
        }
        if ($this->pular > 0) {
            $descartar = min($this->pular, $tamanho);
            $this->pular -= $descartar;
            $trecho = substr($trecho, $descartar);
        }
        $this->emitir($this->paraUtf8($trecho));
    }

    private function controle(): void
    {
        $c = $this->bytes[$this->posicao] ?? '';
        if ($c === '') {
            return;
        }
        if (ctype_alpha($c)) {
            $this->palavra();

            return;
        }
        ++$this->posicao;
        match ($c) {
            "'" => $this->caractereHexadecimal(),
            '*' => $this->marcarIgnorado(),
            "\n", "\r" => $this->emitir("\n"),
            '~' => $this->emitir(' '),
            '_', '-' => $this->emitir('-'),
            '\\', '{', '}' => $this->emitirComSubstituicao($c),
            default => null,
        };
    }

    private function marcarIgnorado(): void
    {
        $this->ignorar[\count($this->ignorar) - 1] = true;
    }

    private function caractereHexadecimal(): void
    {
        $hex = substr($this->bytes, $this->posicao, 2);
        if (\strlen($hex) === 2 && ctype_xdigit($hex)) {
            $this->posicao += 2;
            $this->emitirComSubstituicao($this->paraUtf8(\chr((int) hexdec($hex))));
        }
    }

    private function emitirComSubstituicao(string $texto): void
    {
        if ($this->pular > 0) {
            --$this->pular;

            return;
        }
        $this->emitir($texto);
    }

    private function palavra(): void
    {
        $resto = substr($this->bytes, $this->posicao, 48);
        if (preg_match('/^([a-zA-Z]{1,32})(-?\d{1,10})? ?/', $resto, $casou) !== 1) {
            ++$this->posicao;

            return;
        }
        $this->posicao += \strlen($casou[0]);
        $parametro = isset($casou[2]) ? (int) $casou[2] : null;
        $this->aplicar($casou[1], $parametro);
    }

    private function aplicar(string $palavra, ?int $parametro): void
    {
        if (\in_array($palavra, self::DESTINOS_IGNORADOS, true)) {
            $this->marcarIgnorado();

            return;
        }
        match ($palavra) {
            'par', 'line', 'sect', 'page', 'row' => $this->emitir("\n"),
            'tab', 'cell' => $this->emitir("\t"),
            'lquote', 'rquote' => $this->emitir("'"),
            'ldblquote', 'rdblquote' => $this->emitir('"'),
            'endash', 'emdash' => $this->emitir('-'),
            'bullet' => $this->emitir('*'),
            'emspace', 'enspace', 'qmspace' => $this->emitir(' '),
            'uc' => $this->uc[\count($this->uc) - 1] = max(0, $parametro ?? 1),
            'u' => $this->unicode($parametro ?? 0),
            'bin' => $this->posicao += max(0, $parametro ?? 0),
            default => null,
        };
    }

    private function unicode(int $codigo): void
    {
        if ($codigo < 0) {
            $codigo += 65536;
        }
        $this->pular = $this->uc[\count($this->uc) - 1];
        if ($codigo >= 0xD800 && $codigo <= 0xDBFF) {
            $this->altaPendente = $codigo;

            return;
        }
        if ($codigo >= 0xDC00 && $codigo <= 0xDFFF) {
            $codigo = $this->altaPendente === 0 ? 0 : 0x10000 + (($this->altaPendente - 0xD800) << 10) + ($codigo - 0xDC00);
            $this->altaPendente = 0;
        }
        if ($codigo > 0 && $codigo <= 0x10FFFF) {
            $this->emitir((string) mb_chr($codigo, 'UTF-8'));
        }
    }

    private function emitir(string $texto): void
    {
        if (!$this->ignorar[\count($this->ignorar) - 1]) {
            $this->saida .= $texto;
        }
    }

    private function paraUtf8(string $trecho): string
    {
        return mb_check_encoding($trecho, 'UTF-8') ? $trecho : mb_convert_encoding($trecho, 'UTF-8', 'Windows-1252');
    }
}
