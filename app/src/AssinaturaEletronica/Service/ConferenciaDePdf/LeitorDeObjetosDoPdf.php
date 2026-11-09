<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Porta do `objeto` do protótipo, para o carimbo da etapa 5: o corpo de um objeto do PDF, entre `obj` e `endobj`.
 * Procura primeiro a última ocorrência de `numero geracao obj` (a versão mais nova, depois dos acréscimos); sem ela,
 * os fluxos de objetos (`/ObjStm`), do último para o primeiro, inflando os comprimidos com teto.
 *
 * Cada fluxo é lido uma vez só, mesmo com vários `/Type/ObjStm` apontando para ele, e a leitura inteira tem um teto de
 * trabalho somado (`TRABALHO_MAXIMO_EM_BYTES`, M22). Sem estado e sem disco. Nunca lança: objeto ausente, PDF
 * malformado ou limite passado devolvem null.
 */
final class LeitorDeObjetosDoPdf
{
    /** Até onde o dicionário de um /ObjStm é procurado para trás. */
    private const JANELA_DO_DICIONARIO = 4096;

    public function objeto(string $pdf, int $numero, int $geracao): ?string
    {
        if ($numero < 0 || $geracao < 0 || \strlen($pdf) > LimitesDaConferencia::PDF_MAXIMO_EM_BYTES) {
            return null;
        }

        try {
            return $this->objetoSolto($pdf, $numero, $geracao) ?? $this->objetoEmFluxo($pdf, $numero);
        } catch (\Throwable) {
            return null;
        }
    }

    private function objetoSolto(string $pdf, int $numero, int $geracao): ?string
    {
        $padrao = '/(?:^|[\s>])' . $numero . '\s++' . $geracao . '\s++obj\b/';
        $ultima = null;
        $posicao = 0;
        for ($vistos = 0; preg_match($padrao, $pdf, $partes, \PREG_OFFSET_CAPTURE, $posicao) === 1; ++$vistos) {
            if ($vistos >= LimitesDaConferencia::OBJETOS_MAXIMOS) {
                return null;
            }
            $ultima = $partes[0][1];
            $posicao = $ultima + \strlen($partes[0][0]);
        }
        if ($ultima === null) {
            return null;
        }

        $inicio = (int) strpos($pdf, 'obj', $ultima) + 3;
        $fim = strpos($pdf, 'endobj', $inicio);

        return $fim === false ? null : trim(substr($pdf, $inicio, $fim - $inicio));
    }

    private function objetoEmFluxo(string $pdf, int $numero): ?string
    {
        $trabalho = 0;
        $lidos = [];
        $fluxos = [];
        $posicao = 0;
        while (preg_match('/\/Type\s*+\/ObjStm/', $pdf, $partes, \PREG_OFFSET_CAPTURE, $posicao) === 1) {
            if (\count($fluxos) >= LimitesDaConferencia::OBJETOS_MAXIMOS) {
                return null;
            }
            $fluxos[] = $partes[0][1];
            $posicao = $partes[0][1] + \strlen($partes[0][0]);
        }

        foreach (array_reverse($fluxos) as $tipo) {
            $dicionario = $this->dicionarioDoFluxo($pdf, $tipo, $trabalho);
            if ($dicionario === null || $trabalho > LimitesDaConferencia::TRABALHO_MAXIMO_EM_BYTES) {
                return null;
            }
            [$texto, $comeco] = $dicionario;
            // Vários marcadores podem apontar para o mesmo fluxo: ele já foi procurado e não tem o objeto.
            if (isset($lidos[$comeco])) {
                continue;
            }
            $lidos[$comeco] = true;

            $corpo = $this->corpoDoFluxo($pdf, $texto, $comeco, $trabalho);
            if ($corpo === null) {
                return null;
            }
            [$dados, $quantidade, $primeiro] = $corpo;

            $cabecalho = preg_split('/\s++/', trim(substr($dados, 0, $primeiro)), 2 * $quantidade + 1);
            if ($cabecalho === false) {
                return null;
            }
            $cabecalho = array_map('intval', $cabecalho);
            for ($i = 0; $i < $quantidade; ++$i) {
                if (($cabecalho[2 * $i] ?? null) !== $numero) {
                    continue;
                }
                $inicio = $primeiro + ($cabecalho[2 * $i + 1] ?? 0);
                $fim = $i + 1 < $quantidade ? $primeiro + ($cabecalho[2 * $i + 3] ?? 0) : \strlen($dados);

                return $fim >= $inicio ? trim(substr($dados, $inicio, $fim - $inicio)) : null;
            }
        }

        return null;
    }

    /**
     * O texto do dicionário do fluxo e a posição da palavra `stream`: o ` obj` até a janela antes do marcador e o
     * `stream` até a janela depois dele (M22). Null se algum dos dois não estiver na janela.
     *
     * @return array{string, int}|null
     */
    private function dicionarioDoFluxo(string $pdf, int $tipo, int &$trabalho): ?array
    {
        $janela = max(0, $tipo - self::JANELA_DO_DICIONARIO);
        $antes = substr($pdf, $janela, $tipo - $janela);
        $depois = substr($pdf, $tipo, self::JANELA_DO_DICIONARIO);
        $trabalho += \strlen($antes) + \strlen($depois);
        $obj = strrpos($antes, ' obj');
        $stream = strpos($depois, 'stream');
        if ($obj === false || $stream === false) {
            return null;
        }
        $comeco = $tipo + $stream;

        return [substr($pdf, $janela + $obj + 4, $comeco - $janela - $obj - 4), $comeco];
    }

    /**
     * Os bytes do fluxo (inflados se for FlateDecode), o /N e o /First. Null se o fluxo não der para ler ou se o
     * trabalho somado passar do teto.
     *
     * @return array{string, int, int}|null
     */
    private function corpoDoFluxo(string $pdf, string $dicionario, int $comeco, int &$trabalho): ?array
    {
        if (preg_match('/\/N\s++(\d{1,9})/', $dicionario, $n) !== 1 || preg_match('/\/First\s++(\d{1,9})/', $dicionario, $f) !== 1) {
            return null;
        }
        $quantidade = (int) $n[1];
        $primeiro = (int) $f[1];
        if ($quantidade > LimitesDaConferencia::OBJETOS_MAXIMOS) {
            return null;
        }

        $inicio = $comeco + 6;
        if (($pdf[$inicio] ?? '') === "\r") {
            ++$inicio;
        }
        if (($pdf[$inicio] ?? '') === "\n") {
            ++$inicio;
        }
        $fim = strpos($pdf, 'endstream', $inicio);
        if ($fim === false) {
            return null;
        }
        $trabalho += $fim - $inicio;
        if ($trabalho > LimitesDaConferencia::TRABALHO_MAXIMO_EM_BYTES) {
            return null;
        }
        $dados = substr($pdf, $inicio, $fim - $inicio);
        if (str_contains($dicionario, 'FlateDecode')) {
            $limite = min(LimitesDaConferencia::INFLADO_MAXIMO_EM_BYTES, LimitesDaConferencia::TRABALHO_MAXIMO_EM_BYTES - $trabalho);
            $dados = InfladorLimitado::inflar(rtrim($dados, "\r\n"), $limite);
            if ($dados === null) {
                return null;
            }
            $trabalho += \strlen($dados);
        }

        return $primeiro <= \strlen($dados) ? [$dados, $quantidade, $primeiro] : null;
    }
}
