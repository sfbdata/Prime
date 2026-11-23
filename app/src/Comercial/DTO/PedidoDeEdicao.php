<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

use App\Comercial\Entity\CampanhaDeEmail;
use App\Comercial\Exception\CampanhaRecusada;

/**
 * "Salvar alterações" da edição de uma campanha ainda não iniciada (pedido do Farlei M5.3, 23/11/2026; spec
 * `comercial-e-emails.md` §8.1): `{modelo, agendar, frequencia}`, o mesmo formato do "Enviar campanha" (sem público nem
 * chave de idempotência: o público e o modelo escolhido não mudam). O `modelo` vai cru: quem monta a
 * {@see MensagemDaCampanha} é o caso de uso, que fixa a chave do modelo da campanha.
 */
final class PedidoDeEdicao
{
    /**
     * @param array<mixed> $modelo
     */
    public function __construct(
        public readonly array $modelo,
        public readonly string $frequencia,
        public readonly ?\DateTimeImmutable $agendarPara,
    ) {
        if (!\in_array($frequencia, CampanhaDeEmail::FREQUENCIAS, true)) {
            throw new CampanhaRecusada('Frequência inválida.');
        }
    }

    /** @param array<mixed> $dados o corpo do POST */
    public static function deArray(array $dados): self
    {
        $modelo = $dados['modelo'] ?? null;

        return new self(
            \is_array($modelo) ? $modelo : throw new CampanhaRecusada('Pedido inválido.'),
            \is_string($dados['frequencia'] ?? null) ? $dados['frequencia'] : CampanhaDeEmail::UNICA,
            PedidoDeCampanha::data($dados['agendar'] ?? ''),
        );
    }
}
