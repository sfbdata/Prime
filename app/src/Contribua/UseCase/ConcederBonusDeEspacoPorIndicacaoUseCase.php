<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Repository\BonusDeEspacoRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * Dá à conta nova o bônus de espaço da indicação (spec `bonus-de-espaco-por-indicacao.md`): 5 GB por 30 dias, uma
 * vez por indicação. Quem chama é o ouvinte do domínio Indicacao, que já resolveu a indicação do cadastro
 * (`indicacaoId`) e o escritório da conta nova (`tenantId`); o Contribua não lê a Indicação.
 *
 * Idempotente: repetir o evento não duplica (trava por indicação + UNIQUE). Não concede (devolve falso) com a conta
 * inexistente, com o e-mail ainda não confirmado (anti-fraude com e-mail descartável) ou com o bônus já dado.
 *
 * EntityManager: `wrapInTransaction` fecha o EM se algo lançar. Os quatro pontos que despacham
 * `EmailDaContaConfirmado` e o `ContaCriadaPeloCadastro` despacham depois do commit e não gravam mais nada com o EM
 * no mesmo caso de uso; a trava advisory por indicação serializa as concessões e a checagem `jaConcedido` roda dentro
 * dela, então a UNIQUE só estoura por falha real do banco (a transação já estaria perdida). O `catch` é só rede de
 * segurança. Usar o EM (e não SQL cru) mantém a concessão no `audit_log`.
 */
final class ConcederBonusDeEspacoPorIndicacaoUseCase
{
    public function __construct(
        private readonly BonusDeEspacoRepository $bonus,
        private readonly UserRepository $usuarios,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** Devolve se concedeu agora. */
    public function executar(int $userId, int $indicacaoId, int $tenantId): bool
    {
        $conta = $this->usuarios->find($userId);
        if ($conta === null || !$conta->isEmailConfirmado()) {
            return false;
        }

        try {
            return $this->bonus->emTransacaoTravada($indicacaoId, function () use ($indicacaoId, $tenantId): bool {
                if ($this->bonus->jaConcedidoPorIndicacao($indicacaoId)) {
                    return false;
                }
                $this->bonus->novoDaIndicacao($tenantId, $indicacaoId, \DateTimeImmutable::createFromInterface($this->relogio->now()));

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // Corrida que escapou da trava: o bônus desta indicação já existe.
            return false;
        }
    }
}
