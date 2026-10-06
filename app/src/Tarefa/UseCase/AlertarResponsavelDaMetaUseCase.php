<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Repository\NotificacaoRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tarefa\Exception\AlertaDeMetaRecusadoException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Sino "Alertar para verificar" da lista de metas da pasta (desenho 1.2.3, `sinoMeta`).
 *
 * Quem alerta escolhe UM responsável da meta; ele recebe uma notificação de verdade no
 * sino do sistema, ligada à meta. Regras:
 *  - só meta aberta (o desenho não desenha o sino na concluída);
 *  - o destinatário tem de ser responsável da meta, com vínculo ativo no escritório, e
 *    não pode ser quem alerta (o desenho tira o próprio usuário da lista);
 *  - anti-spam: o mesmo destinatário não é alertado sobre a mesma meta mais de uma vez
 *    por hora. O "último alerta" é lido da própria `notificacao` (tipo próprio + meta),
 *    sem coluna nova.
 *
 * O relógio é injetado para o teste poder fixar "agora".
 */
final class AlertarResponsavelDaMetaUseCase
{
    /** Tipo da notificação gravada (string livre em `notificacao.tipo`, até 50). */
    public const TIPO_NOTIFICACAO = 'tarefa_alerta_verificar';

    /** Intervalo mínimo entre dois alertas da mesma meta para o mesmo destinatário. */
    public const INTERVALO_MINIMO = 'PT1H';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificacaoService $notificacaoService,
        private readonly NotificacaoRepository $notificacaoRepository,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return User o destinatário alertado
     *
     * @throws AlertaDeMetaRecusadoException
     */
    public function executar(Tarefa $tarefa, User $remetente, Tenant $tenant, int $destinatarioId): User
    {
        $daMeta = $tarefa->getTenant();
        if (!($daMeta === $tenant || ($daMeta !== null && $daMeta->getId() !== null && $daMeta->getId() === $tenant->getId()))) {
            // O TenantFilter já esconde meta de outro escritório; esta é a segunda trava.
            throw new \LogicException('Meta de outro escritório.');
        }

        if ($tarefa->getStatus() === Tarefa::STATUS_CONCLUIDA) {
            throw new AlertaDeMetaRecusadoException('Meta concluída não recebe alerta.');
        }

        $destinatario = null;
        foreach ($tarefa->getResponsaveis() as $responsavel) {
            if ($responsavel->getId() !== null && $responsavel->getId() === $destinatarioId) {
                $destinatario = $responsavel;
                break;
            }
        }

        if ($destinatario === null || !$this->userTenantRepository->existeVinculoAtivo($destinatario, $tenant)) {
            throw new AlertaDeMetaRecusadoException('Só é possível alertar um responsável desta meta.');
        }

        if ($destinatario === $remetente || $destinatario->getId() === $remetente->getId()) {
            throw new AlertaDeMetaRecusadoException('Você é responsável por esta meta; não é preciso alertar a si mesmo.');
        }

        $agora  = $this->clock->now();
        $ultimo = $this->notificacaoRepository->findOneBy(
            ['tarefa' => $tarefa, 'usuario' => $destinatario, 'tipo' => self::TIPO_NOTIFICACAO],
            ['criadaEm' => 'DESC'],
        );
        if ($ultimo !== null && $ultimo->getCriadaEm() > $agora->sub(new \DateInterval(self::INTERVALO_MINIMO))) {
            throw new AlertaDeMetaRecusadoException(sprintf(
                '%s já foi alertado sobre esta meta às %s. Aguarde uma hora para alertar de novo.',
                $destinatario->getFullName(),
                $ultimo->getCriadaEm()->format('H:i'),
            ));
        }

        $this->notificacaoService->criar(
            $destinatario,
            $tenant,
            self::TIPO_NOTIFICACAO,
            'Alerta para verificar a meta',
            $this->mensagem($tarefa, $remetente, $agora),
            $tarefa,
        );
        $this->em->flush();

        return $destinatario;
    }

    /** "Fulano pediu que você verifique a meta "X" (pasta N) · 3 dias em atraso". */
    private function mensagem(Tarefa $tarefa, User $remetente, \DateTimeImmutable $agora): string
    {
        $texto = sprintf('%s pediu que você verifique a meta "%s"', $remetente->getFullName(), $tarefa->getTitulo());

        $nup = $tarefa->getPasta()->getNup();
        if ($nup !== null && $nup !== '') {
            $texto .= sprintf(' (pasta %s)', $nup);
        }

        $prazo = $tarefa->getPrazo();
        if ($prazo === null) {
            return $texto . '.';
        }

        $dias = (int) $agora->setTime(0, 0)->diff($prazo->setTime(0, 0))->format('%r%a');
        if ($dias < 0) {
            return $texto . sprintf(' · %d dia%s em atraso.', -$dias, $dias === -1 ? '' : 's');
        }

        return $texto . sprintf(' · vence %s.', $prazo->format('d/m/Y'));
    }
}
