<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

use App\Comercial\Entity\CampanhaDeEmail;
use App\Comercial\Exception\CampanhaRecusada;

/**
 * O "Enviar campanha" confirmado na tela (dc L.560; spec §8.1 e §8.4): a chave de idempotência gerada pela tela, o
 * modelo em edição (a cópia que a campanha congela), o público, a frequência (dc L.253) e a data de "Agendar" ou
 * "Primeiro envio" (dc L.254, `datetime-local`, hora local).
 */
final class PedidoDeCampanha
{
    public const DATA_INVALIDA = 'Data de agendamento inválida.';
    private const PADRAO_DA_CHAVE = '/^[A-Za-z0-9-]{16,64}$/';

    public function __construct(
        public readonly string $chave,
        public readonly MensagemDaCampanha $mensagem,
        public readonly PedidoDePublico $publico,
        public readonly string $frequencia,
        public readonly ?\DateTimeImmutable $agendarPara,
    ) {
        if (preg_match(self::PADRAO_DA_CHAVE, $chave) !== 1) {
            throw new CampanhaRecusada('Pedido inválido.');
        }
        if (!\in_array($frequencia, CampanhaDeEmail::FREQUENCIAS, true)) {
            throw new CampanhaRecusada('Frequência inválida.');
        }
    }

    /** @param array<mixed> $dados */
    public static function deArray(array $dados): self
    {
        $modelo = $dados['modelo'] ?? null;

        return new self(
            \is_string($dados['chave'] ?? null) ? $dados['chave'] : '',
            MensagemDaCampanha::deArray(\is_array($modelo) ? $modelo : throw new CampanhaRecusada('Pedido inválido.')),
            PedidoDePublico::deArray($dados),
            \is_string($dados['frequencia'] ?? null) ? $dados['frequencia'] : CampanhaDeEmail::UNICA,
            self::data($dados['agendar'] ?? ''),
        );
    }

    private static function data(mixed $valor): ?\DateTimeImmutable
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        // O `datetime-local` manda "AAAA-MM-DDTHH:MM" (com os segundos, se o navegador incluir).
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s'] as $formato) {
            $data = \is_string($valor) ? \DateTimeImmutable::createFromFormat('!' . $formato, $valor) : false;
            if ($data !== false && $data->format($formato) === $valor) {
                return $data;
            }
        }

        throw new CampanhaRecusada(self::DATA_INVALIDA);
    }
}
