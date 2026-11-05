<?php

declare(strict_types=1);

namespace App\Inteligencia\Contract;

/**
 * A porta do Chat I.A em página inteira (`docs/specs/chat-pagina-inteira.md`) para a retenção da Inteligência
 * ({@see \App\Inteligencia\UseCase\ExpirarConteudoDaInteligenciaUseCase}): a rotina diária não conhece as tabelas do
 * chat, só pede que o conteúdo mais antigo que o prazo do Master saia. A implementação mora em
 * `ChatIa\Service\ExpiracaoDoChatIa`. Varre TODOS os escritórios de propósito (roda no console, sem o TenantFilter
 * e sem sessão), como {@see ConversaDaIaNaCentral::apagarMensagensDaIaAntesDe}.
 */
interface RetencaoDoChatIa
{
    /**
     * Apaga TODA fala do Chat I.A criada antes de `$limite`: a pergunta, a resposta, o registro de custo de resposta
     * substituída e a cópia que a importação trouxe da Central com a data original. Os anexos dessas falas saem junto
     * (a linha na transação, o ARQUIVO só depois do COMMIT), e a conversa que ficou sem fala nenhuma é removida (com os
     * anexos soltos dela, também linha e arquivo). Conversa que nunca teve fala não é tocada. Devolve as contagens.
     *
     * @return array{mensagens: int, anexos: int, conversas: int}
     */
    public function apagarAntesDe(\DateTimeImmutable $limite): array;
}
