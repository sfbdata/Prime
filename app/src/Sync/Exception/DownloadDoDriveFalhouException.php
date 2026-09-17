<?php

declare(strict_types=1);

namespace App\Sync\Exception;

/**
 * O download de um arquivo do Drive não produziu um documento válido (DT-8): a resposta final não foi
 * 200, ou a transferência falhou antes de haver resposta. Quem lança já removeu o destino; o chamador
 * conta o erro do item e a próxima rodada tenta de novo.
 *
 * A mensagem nunca carrega credencial: o {@see \App\Sync\Service\GoogleDriveClient} entrega os textos
 * já limpos. A exceção de origem NÃO é encadeada — a do Guzzle carrega a requisição, com o
 * `Authorization` —; dela ficam só o nome da classe e a mensagem limpa.
 */
final class DownloadDoDriveFalhouException extends \RuntimeException
{
    private function __construct(
        string $mensagem,
        public readonly string $fileId,
        public readonly ?int $status,
        public readonly ?string $motivo,
    ) {
        parent::__construct($mensagem);
    }

    /** Houve resposta, e ela não era 200. `$motivo` é o `reason: message` do erro do Google, quando havia. */
    public static function respostaInvalida(string $fileId, int $status, ?string $motivo): self
    {
        $mensagem = sprintf('O Drive respondeu %d ao baixar o arquivo %s', $status, $fileId);
        if ($motivo !== null) {
            $mensagem .= sprintf(' (%s)', $motivo);
        }

        return new self($mensagem . '.', $fileId, $status, $motivo);
    }

    /**
     * Não houve resposta utilizável: conexão, corpo incompleto, redirects demais, falha ao renovar o
     * token no caminho. `$causa` é o nome curto da classe de origem; `$descricao` já vem limpa.
     */
    public static function semResposta(string $fileId, string $causa, string $descricao): self
    {
        return new self(sprintf('Falha ao baixar o arquivo %s do Drive (%s): %s', $fileId, $causa, $descricao), $fileId, null, null);
    }
}
