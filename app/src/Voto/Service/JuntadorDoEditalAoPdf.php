<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Pasta\Service\ContadorDePaginasDePdf;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Service\ExecucaoDoGhostscript;
use Psr\Log\LoggerInterface;

/**
 * Junta as páginas do edital ao fim do PDF do resultado da assembleia, com o Ghostscript que o sistema já usa
 * ({@see ExecucaoDoGhostscript}: `TMPDIR` privado e timeout). Os dois PDFs são materializados numa
 * {@see AreaTemporariaPrivada} que se apaga inteira no `finally`.
 *
 * O resultado NUNCA deixa de sair: se o edital não puder ser lido, o Ghostscript falhar, estourar o tempo ou o PDF
 * juntado não tiver as páginas dos dois (conferido pelo contador de páginas), devolve o PDF do resultado como veio e
 * registra o erro. A linha com o hash do edital está na folha do resumo, e por isso o resultado continua verificável.
 */
class JuntadorDoEditalAoPdf
{
    /** Segundos. Juntar é copiar páginas, sem renderizar; um edital de dezenas de páginas termina em poucos segundos. */
    public const TIMEOUT = 60.0;

    public function __construct(
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly ContadorDePaginasDePdf $contador,
        private readonly LoggerInterface $logger,
        private readonly string $ghostscriptBin,
    ) {
    }

    public function juntar(string $pdfDoResultado, ChaveDeArquivo $edital): string
    {
        $area = null;
        try {
            $area = AreaTemporariaPrivada::criar('votoedital');

            return $this->juntarNaArea($area, $pdfDoResultado, $edital) ?? $pdfDoResultado;
        } catch (\Throwable $falha) {
            $this->logger->error('Voto: falha ao juntar o edital ao PDF do resultado; o PDF sai sem as páginas do edital.', [
                'edital' => $edital->nome,
                'erro' => $falha->getMessage(),
            ]);

            return $pdfDoResultado;
        } finally {
            $area?->liberar();
        }
    }

    /** O PDF juntado, ou null (com o motivo no log) quando o Ghostscript não produziu um PDF com as páginas dos dois. */
    private function juntarNaArea(AreaTemporariaPrivada $area, string $pdfDoResultado, ChaveDeArquivo $edital): ?string
    {
        $resultado = $area->gravar($pdfDoResultado, 'pdf');
        $anexo = $area->gravar($this->armazenamento->ler($edital), 'pdf');
        $saida = $area->caminho() . '/juntado-' . bin2hex(random_bytes(6)) . '.pdf';

        $processo = ExecucaoDoGhostscript::rodar([
            $this->ghostscriptBin, '-q', '-dSAFER', '-dNOPAUSE', '-dBATCH', '-sDEVICE=pdfwrite', '-sOutputFile=' . $saida, $resultado, $anexo,
        ], self::TIMEOUT);
        if (!$processo->isSuccessful() || !is_file($saida)) {
            $this->logger->error('Voto: o Ghostscript não juntou o edital ao PDF do resultado; o PDF sai sem as páginas do edital.', [
                'edital' => $edital->nome,
                'saida' => mb_substr(trim($processo->getErrorOutput() . "\n" . $processo->getOutput()), 0, 500),
            ]);

            return null;
        }

        $a = $this->contador->contar($resultado);
        $b = $this->contador->contar($anexo);
        if (null === $a || null === $b || $this->contador->contar($saida) !== $a + $b) {
            $this->logger->error('Voto: o PDF juntado não tem as páginas do resultado e do edital; o PDF sai sem as páginas do edital.', [
                'edital' => $edital->nome,
            ]);

            return null;
        }

        return $this->ler($saida);
    }

    private function ler(string $caminho): ?string
    {
        $f = fopen($caminho, 'rb');
        if (false === $f) {
            return null;
        }
        $conteudo = stream_get_contents($f);
        fclose($f);

        return \is_string($conteudo) && str_starts_with($conteudo, '%PDF-') ? $conteudo : null;
    }
}
