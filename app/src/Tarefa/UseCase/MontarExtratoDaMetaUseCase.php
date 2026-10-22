<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Tarefa\DTO\ExtratoDaMetaOutput;
use App\Tarefa\Service\TarefaTimelineAssembler;

/**
 * Monta o Extrato de tramitação da meta (desenho 04B de 22/10, L150; spec L9) a partir da MESMA linha do tempo da tela
 * (`MontarLinhaDoTempoDaMetaUseCase`), para a numeração, a natureza, o "quem → para", o "Cumprir até", a ciência e a
 * certidão saírem iguais nos dois lugares. Só leitura: abrir o extrato não dá ciência de nada.
 */
final class MontarExtratoDaMetaUseCase
{
    public function __construct(
        private readonly TarefaTimelineAssembler $assembler,
        private readonly MontarDespachosDaMetaUseCase $despachos,
        private readonly MontarLinhaDoTempoDaMetaUseCase $linhaDoTempo,
    ) {
    }

    public function executar(Tarefa $meta, User $quemEmite, Tenant $escritorio, \DateTimeImmutable $agora): ExtratoDaMetaOutput
    {
        if ($meta->getTenant()?->getId() !== $escritorio->getId()) {
            throw new \LogicException('Meta de outro escritório.');
        }

        $itens = $this->linhaDoTempo->executar(
            $this->assembler->montar($meta, (int) $escritorio->getId()),
            $this->despachos->executar($meta, $quemEmite, $escritorio, $agora),
        );
        $criador = $meta->getCriadoPor();
        $pasta = $meta->getPasta();

        return new ExtratoDaMetaOutput(
            numero: $meta->getNumero() ?? (int) $meta->getId(),
            titulo: $meta->getTitulo(),
            pasta: (string) ($pasta->getNup() ?? '#' . $pasta->getId()),
            emitidoEm: $agora,
            criadaEm: $meta->getDataCriacao(),
            autor: (string) ($criador?->getFullName() ?? $criador?->getEmail()),
            descricao: trim($meta->getDescricao()),
            itens: array_reverse($itens),
        );
    }
}
