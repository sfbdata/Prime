<?php

declare(strict_types=1);

namespace App\Agenda\Service;

use App\Agenda\Entity\Evento;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Service\NotificacaoService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Avisar no Chat I.A" do desenho: cada colega marcado recebe o resumo do compromisso e o atalho para
 * abri-lo na agenda. Vai pelos avisos da Central (Notificacao), o mesmo caminho das menções em pasta.
 */
final class AvisoDoCompromisso
{
    private const DIAS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
    /** Notificacao::titulo é VARCHAR(255) e recebe a mensagem inteira. */
    private const MAX = 255;

    public function __construct(
        private readonly NotificacaoService $notificacoes,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param iterable<User> $pessoas
     *
     * @return int quantos foram avisados (quem avisa não avisa a si mesmo)
     */
    public function avisar(Evento $evento, iterable $pessoas, User $quem): int
    {
        $n = 0;
        foreach ($pessoas as $pessoa) {
            if ($pessoa->getId() === $quem->getId()) {
                continue;
            }
            $this->notificacoes->criarNotificacao($pessoa, $evento->getTenant(), Notificacao::TIPO_EVENTO_CRIADO, $this->texto($evento, $quem), $this->link($evento));
            ++$n;
        }

        return $n;
    }

    public function texto(Evento $evento, User $quem): string
    {
        $inicio = $evento->getDataInicio() ?? new \DateTimeImmutable();
        $quando = self::DIAS[(int) $inicio->format('w')] . ', ' . $inicio->format('d/m')
            . ($evento->isDiaInteiro() ? ' (dia inteiro)' : ' às ' . $inicio->format('H:i'));
        $primeiro = explode(' ', trim((string) $quem->getFullName()))[0] ?: 'Um colega';
        $obs = trim((string) $evento->getDescricao());

        $texto = '📅 ' . $primeiro . ' marcou você em um compromisso: ' . $evento->getTitulo() . ' · ' . $quando
            . (null !== $evento->getPasta() ? ' · Pasta ' . $evento->getPasta()->getNup() : '')
            . ('' !== $obs ? ' · ' . mb_substr($obs, 0, 80) : '');

        return mb_strlen($texto) > self::MAX ? mb_substr($texto, 0, self::MAX - 1) . '…' : $texto;
    }

    private function link(Evento $evento): string
    {
        $dia = ($evento->getDataInicio() ?? new \DateTimeImmutable())->format('Y-m-d');

        return $this->urls->generate('agenda_index') . '#ev=ev-' . $evento->getId() . '-' . $dia;
    }
}
