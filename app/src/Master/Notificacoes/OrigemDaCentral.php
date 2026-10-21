<?php

declare(strict_types=1);

namespace App\Master\Notificacoes;

use App\Entity\Auth\User;
use App\Master\DTO\DestinoDaResposta;
use App\Master\DTO\MensagemDaCentral;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * O leitor de UMA origem da Central de Notificações do Master (spec master-central-de-notificacoes.md, D4). A
 * mensagem continua na tabela de origem; o leitor a devolve no formato comum ({@see MensagemDaCentral}) e diz para
 * onde vai a resposta. Uma origem nova (pedido de espaço, exclusão de conta) entra com um leitor novo, sem mexer na
 * caixa: toda implementação é registrada pela tag e chega ao {@see OrigensDaCentral}.
 *
 * Quem chama garante que é o Master (`ROLE_SUPER_ADMIN` no controller).
 */
#[AutoconfigureTag(self::TAG)]
interface OrigemDaCentral
{
    public const TAG = 'app.master.origem_da_central';

    /** Teto de leitura por origem: acima disso, as mais antigas ficam de fora da caixa. */
    public const LIMITE = 5000;

    /** A chave gravada em `origem` (até 20 caracteres). */
    public function origem(): string;

    /** @return list<MensagemDaCentral> as mais novas primeiro; o que sumiu da origem não volta */
    public function todas(): array;

    public function uma(int $idOrigem): ?MensagemDaCentral;

    /** Nulo = a origem não aceita resposta (D7) ou não há mais a quem responder. */
    public function destinoDaResposta(int $idOrigem): ?DestinoDaResposta;

    /** O que a própria origem guarda da resposta (D6), sem flush; nada, na maioria. */
    public function registrarResposta(int $idOrigem, string $texto, User $master): void;
}
