<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Entity\Evento;
use App\Agenda\Exception\CompromissoNaoEncontradoException;
use App\Agenda\Exception\SemPermissaoNoCompromissoException;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * História: no detalhe do compromisso, quem o criou (ou administra usuários) marca "Concluir",
 * "Cancelar compromisso" ou "Reabrir", como na agenda antiga (status agendado, concluído, cancelado).
 * Ao cancelar, cada participante recebe "O evento foi cancelado: <título>", como antes.
 */
final class AlterarStatusDoCompromissoUseCase
{
    public const STATUS = [Evento::STATUS_AGENDADO, Evento::STATUS_CONCLUIDO, Evento::STATUS_CANCELADO];

    public function __construct(
        private readonly EventoDaAgendaRepository $eventos,
        private readonly PermissionChecker $permissoes,
        private readonly NotificacaoService $notificacoes,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function executar(int $id, string $status, User $eu, Tenant $tenant): void
    {
        if (!in_array($status, self::STATUS, true)) {
            throw new \DomainException('Situação inválida.');
        }
        $evento = $this->eventos->visivelPara($id, $tenant, $eu) ?? throw new CompromissoNaoEncontradoException();
        if ($evento->getCriador()?->getId() !== $eu->getId() && !$this->permissoes->canAdminister($eu, $tenant, 'admin.users.manage')) {
            throw new SemPermissaoNoCompromissoException();
        }
        $cancelouAgora = Evento::STATUS_CANCELADO === $status && Evento::STATUS_CANCELADO !== $evento->getStatus();
        $evento->setStatus($status);
        $this->eventos->salvar($evento);
        if ($cancelouAgora) {
            $this->avisarCancelamento($evento, $eu);
        }
    }

    /** Notificacao::titulo é VARCHAR(255) e recebe a mensagem inteira; corta como o AvisoDoCompromisso. */
    private function texto(Evento $evento): string
    {
        $texto = 'O evento foi cancelado: ' . $evento->getTitulo();

        return mb_strlen($texto) > 255 ? mb_substr($texto, 0, 254) . '…' : $texto;
    }

    private function avisarCancelamento(Evento $evento, User $eu): void
    {
        $link = $this->urls->generate('agenda_index') . '#ev=ev-' . $evento->getId() . '-' . $evento->getDataInicio()?->format('Y-m-d');
        foreach ($evento->getParticipantes() as $participante) {
            if ($participante->getId() !== $eu->getId()) {
                $this->notificacoes->criarNotificacao($participante, $evento->getTenant(), Notificacao::TIPO_EVENTO_CANCELADO, $this->texto($evento), $link);
            }
        }
    }
}
