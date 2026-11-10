<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\DTO\PdfParaEntregar;
use App\Shared\Http\EntregaDeArquivo;
use App\Shared\Http\NomeSeguroDeArquivo;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Lock\LockInterface;

/**
 * A resposta do "Baixar PDF com selo", como download. O guardado sai pela {@see EntregaDeArquivo}; o gerado na hora sai
 * em fluxo, página a página (revisão do 1a). O primeiro pedaço é tirado antes da resposta: a diagramação roda ali, e a
 * falha nele vira erro antes de o cabeçalho sair. Falha num pedaço seguinte, com o 200 já enviado: vai para o log e a
 * escrita para ali, sem o fim do PDF (`%%EOF`); a tela confere o fim do arquivo antes de salvar e avisa a falha. A vaga
 * de geração é liberada quando o último pedaço sai ou a geração falha.
 *
 * {@see limitar()} vale só nas ações do PDF: `set_time_limit(300)` (fora da CLI, como o ChatIa: na CLI o limite vale
 * para o processo inteiro do ParaTest) e `memory_limit` de 256M, como o worker (o FPM tem 128M e 30 s).
 */
final readonly class EntregaDoPdfDaAssinatura
{
    private const MEMORIA = 256 * 1024 * 1024;

    public function __construct(
        private EntregaDeArquivo $entrega,
        private LoggerInterface $logger,
    ) {
    }

    public static function limitar(): void
    {
        if (\PHP_SAPI !== 'cli') {
            set_time_limit(300);
        }

        $atual = self::bytes((string) ini_get('memory_limit'));
        if ($atual !== -1 && $atual < self::MEMORIA) {
            ini_set('memory_limit', '256M');
        }
    }

    public function resposta(PdfParaEntregar $pdf): Response
    {
        if ($pdf->chave !== null) {
            return $this->entrega->resposta($pdf->chave, $pdf->nome, inline: false);
        }

        $pedacos = $pdf->pedacos ?? throw new \LogicException('PDF sem chave e sem pedaços.');
        try {
            $pedacos->current();
        } catch (\Throwable $e) {
            $pdf->vaga?->release();

            throw $e;
        }

        $resposta = new StreamedResponse(function () use ($pedacos, $pdf): void {
            $this->escrever($pedacos, $pdf->vaga);
        });
        $resposta->headers->set('Content-Type', 'application/pdf');
        $resposta->headers->set('X-Content-Type-Options', 'nosniff');
        $nome = NomeSeguroDeArquivo::paraDownload($pdf->nome);
        $ascii = (string) preg_replace('/[^\x20-\x7E]|%/', '_', $nome);
        $resposta->headers->set('Content-Disposition', HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $nome, $ascii));

        return $resposta;
    }

    /** @param \Generator<int, string> $pedacos */
    private function escrever(\Generator $pedacos, ?LockInterface $vaga): void
    {
        try {
            for (; $pedacos->valid(); $pedacos->next()) {
                echo $pedacos->current();
                flush();
            }
        } catch (\Throwable $e) {
            $this->logger->error('PDF com selo cortado: a geração falhou depois do início da resposta: {erro}', [
                'erro' => $e->getMessage(),
                'excecao' => $e::class,
            ]);
        } finally {
            $vaga?->release();
        }
    }

    /** O `memory_limit` em bytes (com K, M ou G); -1 é sem limite. */
    private static function bytes(string $valor): int
    {
        $valor = trim($valor);
        $numero = (int) $valor;

        return match (strtoupper(substr($valor, -1))) {
            'G' => $numero * 1024 * 1024 * 1024,
            'M' => $numero * 1024 * 1024,
            'K' => $numero * 1024,
            default => $numero,
        };
    }
}
