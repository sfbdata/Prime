<?php

declare(strict_types=1);

namespace App\Conector\UseCase;

use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * O administrador do escritório liga (com o aceite) ou desliga o conector de I.A. no PRÓPRIO escritório
 * (`docs/specs/mcp-remoto-oauth.md`, 14.a, nível 3). Quem pode é quem tem `admin.inteligencia.manage` (o controller
 * confere), e o escritório é sempre o da sessão, vindo do `TenantContext` pelo controller, nunca da requisição.
 *
 * - Ligar exige a caixa "Li e aceito" marcada E o escritório liberado pelo Master (nível 2; decisão 3 do plano do PR 8:
 *   não se dá o aceite antes da liberação). O aceite só é regravado quando o gravado não cobre a versão vigente
 *   ({@see EscritorioDoConector::ligar()}).
 * - Desligar só suspende (decisão 4): as conexões da equipe param na hora, porque a liberação é conferida a cada
 *   chamada, e voltam se ligar de novo dentro dos 7 dias da concessão. Sem linha, não há o que desligar.
 *
 * Auditado pela interface `Auditavel`, no escritório da sessão.
 */
final class AlterarConectorDoEscritorioUseCase
{
    /** Chaves do catálogo das recusas; o controller as mostra no flash. */
    public const SEM_ACEITE = 'conector.admin.recusa.sem_aceite';
    public const AGUARDANDO_MASTER = 'conector.admin.recusa.aguardando_master';

    public function __construct(
        private readonly EscritorioDoConectorRepository $escritorios,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @throws \DomainException com {@see AGUARDANDO_MASTER} ou {@see SEM_ACEITE} */
    public function ligar(bool $aceiteMarcado, User $por, Tenant $tenant): void
    {
        $escritorio = $this->escritorios->buscarDoEscritorio($tenant);
        if (!$escritorio instanceof EscritorioDoConector || !$escritorio->isLiberadoPeloMaster()) {
            throw new \DomainException(self::AGUARDANDO_MASTER);
        }
        if (!$aceiteMarcado) {
            throw new \DomainException(self::SEM_ACEITE);
        }

        $escritorio->ligar($por, $this->relogio->now());
        $this->escritorios->salvar($escritorio, flush: true);
    }

    public function desligar(Tenant $tenant): void
    {
        $escritorio = $this->escritorios->buscarDoEscritorio($tenant);
        if (!$escritorio instanceof EscritorioDoConector) {
            return;
        }

        $escritorio->desligar();
        $this->escritorios->salvar($escritorio, flush: true);
    }
}
