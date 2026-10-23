<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tenant\Entity\ConfiguracaoDeEdicao;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use App\Tenant\Repository\ConfiguracaoDeEdicaoRepository;
use App\Tenant\Service\PoderDeEdicaoSemPrazo;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * Liga ou desliga "Usuário primário pode editar e excluir qualquer registro sem limite de tempo" (spec
 * `edicao-sem-prazo-master-primario.md` §3). Só um primário ativo do escritório (o Master sem vínculo de primário não
 * precisa dela e também é recusado). Auditado pela entidade.
 */
final class AlterarEdicaoSemPrazoUseCase
{
    public const RECUSA = 'Só o usuário primário do escritório pode mudar esta opção.';

    public function __construct(
        private readonly ConfiguracaoDeEdicaoRepository $configuracoes,
        private readonly UserTenantRepository $vinculos,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly PoderDeEdicaoSemPrazo $poder,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @throws OperacaoDePrimarioRecusada */
    public function executar(User $quem, Tenant $tenant, bool $ligado): void
    {
        $vinculo = $this->vinculos->findAtivoPorUserETenant($quem, $tenant);
        if ($vinculo === null || !$this->regraDosPrimarios->ehPrimario($vinculo)) {
            throw new OperacaoDePrimarioRecusada(self::RECUSA);
        }
        $configuracao = $this->configuracoes->doEscritorio($tenant) ?? new ConfiguracaoDeEdicao($tenant);
        $configuracao->alterar($ligado, $quem, $this->clock->now());
        try {
            $this->configuracoes->salvar($configuracao);
        } catch (UniqueConstraintViolationException) {
            // Dois primários gravando a PRIMEIRA escolha ao mesmo tempo: a UNIQUE(tenant_id) segura; o outro venceu.
            throw new OperacaoDePrimarioRecusada('Outra pessoa acabou de mudar esta opção. Recarregue a página.');
        }
        $this->poder->esquecer();
    }
}
