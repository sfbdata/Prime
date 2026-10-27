<?php

declare(strict_types=1);

namespace App\Inteligencia\Contract;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * A porta do chat da Central (F3, `docs/specs/ia-chat-central.md`) para a Inteligência: o fluxo do chat não
 * conhece as tabelas da Central, só pede o contexto da pergunta e entrega a resposta. A implementação mora em
 * `Central\Service\ConversaDaIaDaCentral`. O isolamento é por tenant EXPLÍCITO (o worker roda sem sessão).
 */
interface ConversaDaIaNaCentral
{
    /**
     * Relê a pergunta e o histórico no banco. Devolve null quando algo não confere: conversa que não é a IA do
     * escritório e do solicitante, mensagem que não é uma pergunta dele nessa conversa, ou vínculo encerrado.
     * O histórico são as até 12 mensagens ANTERIORES à pergunta (respeitando "excluir" da pessoa), cada uma
     * cortada em 400 caracteres, da mais antiga para a mais nova.
     *
     * @return array{texto: string, nomeDaPessoa: string, historico: list<array{de: 'usuario'|'ia', texto: string}>}|null
     */
    public function contextoDaPergunta(Tenant $tenant, int $conversaId, int $mensagemId, User $solicitante): ?array;

    /**
     * Grava a fala da IA na conversa do `$dono` (texto até 4000 caracteres visíveis, cortado com reticências; `@[`
     * desarmado). Não faz flush: quem chama controla a transação.
     */
    public function gravarResposta(Tenant $tenant, int $conversaId, User $dono, string $texto, \DateTimeImmutable $agora): void;

    /**
     * Retenção: apaga TODA mensagem das conversas com a IA criada antes de `$limite`, as duas pontas (a pergunta da
     * pessoa e a fala da IA são conteúdo). Conversa individual e grupo não são tocadas. Devolve quantas apagou.
     */
    public function apagarMensagensDaIaAntesDe(\DateTimeImmutable $limite): int;
}
