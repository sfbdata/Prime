<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

use App\ChatIa\Entity\MensagemDeChat;
use App\ChatIa\Exception\AnexoDoChatInvalidoException;
use App\ChatIa\Exception\TextoDoChatInvalidoException;
use App\Inteligencia\Service\RegraDaLeituraDeDocumentos;

/**
 * O corpo de `POST /chat-ia/conversas/{id}/mensagens`: `{texto, pastaId?, anexoIds?, regenerar?, editarUltima?}`.
 * Valida só a FORMA (tipos e tamanhos); posse, aceite e contexto são do UseCase.
 *
 * `texto` de 1 a 8000 caracteres; em `regenerar` (reenvia a última pergunta) ele é ignorado. Os dois modos juntos são
 * um pedido contraditório.
 */
final readonly class EnviarMensagemInput
{
    /** @param list<int> $anexoIds */
    public function __construct(
        public string $texto,
        public ?int $pastaId = null,
        public array $anexoIds = [],
        public bool $regenerar = false,
        public bool $editarUltima = false,
    ) {
        if ($regenerar && $editarUltima) {
            throw new TextoDoChatInvalidoException('Escolha regenerar ou editar a última pergunta, não os dois.');
        }
        if (!$regenerar && (mb_strlen(trim($texto)) < 1 || mb_strlen($texto) > MensagemDeChat::TAMANHO_MAXIMO_DO_TEXTO)) {
            throw new TextoDoChatInvalidoException();
        }
        if ($pastaId !== null && $pastaId <= 0) {
            throw new TextoDoChatInvalidoException('Pasta inválida.');
        }
        if (\count($anexoIds) > RegraDaLeituraDeDocumentos::MAXIMO_POR_ANALISE) {
            throw new AnexoDoChatInvalidoException(sprintf('Anexe no máximo %d arquivos por mensagem.', RegraDaLeituraDeDocumentos::MAXIMO_POR_ANALISE));
        }
    }

    /** @param array<string, mixed> $corpo o JSON da requisição */
    public static function deCorpo(array $corpo): self
    {
        $texto = $corpo['texto'] ?? '';
        $pastaId = $corpo['pastaId'] ?? null;
        $ids = $corpo['anexoIds'] ?? [];
        if (!\is_string($texto) || ($pastaId !== null && !\is_int($pastaId)) || !\is_array($ids)) {
            throw new TextoDoChatInvalidoException();
        }
        foreach ($ids as $id) {
            if (!\is_int($id) || $id <= 0) {
                throw new AnexoDoChatInvalidoException();
            }
        }

        return new self(
            $texto,
            $pastaId,
            array_values(array_unique($ids)),
            ($corpo['regenerar'] ?? false) === true,
            ($corpo['editarUltima'] ?? false) === true,
        );
    }
}
