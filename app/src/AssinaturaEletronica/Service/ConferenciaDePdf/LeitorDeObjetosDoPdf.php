<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Porta do `objeto` do protótipo, para o carimbo da etapa 5: o corpo de um objeto do PDF, entre `obj` e `endobj`.
 * Procura primeiro a última ocorrência de `numero geracao obj` (a versão mais nova, depois dos acréscimos); sem ela,
 * os fluxos de objetos (`/ObjStm`), do último para o primeiro, inflando os comprimidos com teto.
 *
 * Sem estado e sem disco. Nunca lança: objeto ausente, PDF malformado ou limite passado devolvem null.
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
            $corpo = $this->corpoDoFluxo($pdf, $tipo);
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
     * Os bytes do fluxo (inflados se for FlateDecode), o /N e o /First. Null se o fluxo não der para ler.
     *
     * @return array{string, int, int}|null
     */
    private function corpoDoFluxo(string $pdf, int $tipo): ?array
    {
        $janela = max(0, $tipo - self::JANELA_DO_DICIONARIO);
        $obj = strrpos(substr($pdf, $janela, $tipo - $janela), ' obj');
        $comeco = strpos($pdf, 'stream', $tipo);
        if ($obj === false || $comeco === false) {
            return null;
        }
        $dicionario = substr($pdf, $janela + $obj + 4, $comeco - $janela - $obj - 4);
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
        $dados = substr($pdf, $inicio, $fim - $inicio);
        if (str_contains($dicionario, 'FlateDecode')) {
            $dados = InfladorLimitado::inflar(rtrim($dados, "\r\n"), LimitesDaConferencia::INFLADO_MAXIMO_EM_BYTES);
            if ($dados === null) {
                return null;
            }
        }

        return $primeiro <= \strlen($dados) ? [$dados, $quantidade, $primeiro] : null;
    }
}
