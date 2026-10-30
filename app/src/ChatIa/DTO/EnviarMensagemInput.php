<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

use App\ChatIa\Entity\MensagemDeChat;
use App\ChatIa\Exception\AnexoDoChatInvalidoException;
use App\ChatIa\Exception\TextoDoChatInvalidoException;
use App\Inteligencia\Service\RegraDaLeituraDeDocumentos;

/**
 * O corpo de `POST /chat-ia/conversas/{id}/mensagens`: `{texto, pastaId?, anexoIds?, regenerar?, editarUltima?, contexto?}`.
 * Valida só a FORMA (tipos e tamanhos); posse, aceite e contexto são do UseCase.
 *
 * `texto` de 1 a 8000 caracteres; em `regenerar` (reenvia a última pergunta) ele é ignorado. Os dois modos juntos são
 * um pedido contraditório.
 *
 * `contexto` é só um SINAL (`metas`): o servidor monta ele mesmo o bloco das metas da própria pessoa; o cliente nunca
 * manda o texto do contexto.
 */
final readonly class EnviarMensagemInput
{
    public const CONTEXTO_METAS = 'metas';

    /** @param list<int>|null $anexoIds nulo = o cliente não informou (na edição da última pergunta, ficam os anexos dela) */
    public function __construct(
        public string $texto,
        public ?int $pastaId = null,
        public ?array $anexoIds = null,
        public bool $regenerar = false,
        public bool $editarUltima = false,
        public ?string $contexto = null,
    ) {
        if ($regenerar && $editarUltima) {
            throw new TextoDoChatInvalidoException('Escolha regenerar ou editar a última pergunta, não os dois.');
        }
        if (!$regenerar && (mb_strlen(trim($texto)) < 1 || mb_strlen($texto) > MensagemDeChat::TAMANHO_MAXIMO_DO_TEXTO)) {
            throw new TextoDoChatInvalidoException();
        }
        if ($contexto !== null && $contexto !== self::CONTEXTO_METAS) {
            throw new TextoDoChatInvalidoException('Contexto inválido.');
        }
        if ($pastaId !== null && $pastaId <= 0) {
            throw new TextoDoChatInvalidoException('Pasta inválida.');
        }
        if ($anexoIds !== null && \count($anexoIds) > RegraDaLeituraDeDocumentos::MAXIMO_POR_ANALISE) {
            throw new AnexoDoChatInvalidoException(sprintf('Anexe no máximo %d arquivos por mensagem.', RegraDaLeituraDeDocumentos::MAXIMO_POR_ANALISE));
        }
    }

    /** @param array<string, mixed> $corpo o JSON da requisição */
    public static function deCorpo(array $corpo): self
    {
        $texto = $corpo['texto'] ?? '';
        $pastaId = $corpo['pastaId'] ?? null;
        $ids = $corpo['anexoIds'] ?? null;
        $contexto = $corpo['contexto'] ?? null;
        if (!\is_string($texto) || ($contexto !== null && !\is_string($contexto)) || ($pastaId !== null && !\is_int($pastaId)) || ($ids !== null && !\is_array($ids))) {
            throw new TextoDoChatInvalidoException();
        }
        foreach ($ids ?? [] as $id) {
            if (!\is_int($id) || $id <= 0) {
                throw new AnexoDoChatInvalidoException();
            }
        }

        return new self(
            $texto,
            $pastaId,
            $ids === null ? null : array_values(array_unique($ids)),
            ($corpo['regenerar'] ?? false) === true,
            ($corpo['editarUltima'] ?? false) === true,
            $contexto,
        );
    }
}
