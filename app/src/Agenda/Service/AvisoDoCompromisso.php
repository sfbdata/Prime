<?php

declare(strict_types=1);

namespace App\Agenda\Service;

use App\Agenda\Entity\Evento;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Pasta\Entity\PastaPrazo;
use App\Service\NotificacaoService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Avisar no Chat I.A" do desenho: cada colega marcado recebe o resumo do compromisso e o atalho para
 * abri-lo na agenda. Vai pelos avisos da Central (Notificacao), o mesmo caminho das menções em pasta. O
 * prazo e a audiência de pasta também são avisados (desenho 09, L238: todo item que não é data do sistema),
 * com o atalho para a pasta.
 */
final class AvisoDoCompromisso
{
    private const DIAS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
    /** Notificacao::titulo é VARCHAR(255). */
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
            $this->notificacoes->criarNotificacao($pessoa, $evento->getTenant(), Notificacao::TIPO_EVENTO_CRIADO, $this->texto($evento, $quem), $this->link($evento), $this->titulo($evento));
            ++$n;
        }

        return $n;
    }

    /**
     * @param iterable<User> $pessoas
     *
     * @return int quantos foram avisados (quem avisa não avisa a si mesmo)
     */
    public function avisarSobrePrazo(PastaPrazo $prazo, iterable $pessoas, User $quem): int
    {
        $n = 0;
        foreach ($pessoas as $pessoa) {
            if ($pessoa->getId() === $quem->getId()) {
                continue;
            }
            $this->notificacoes->criarNotificacao($pessoa, $prazo->getTenant(), Notificacao::TIPO_EVENTO_CRIADO, $this->textoDoPrazo($prazo, $quem), $this->urls->generate('pasta_show', ['id' => $prazo->getPasta()->getId()]), $this->tituloDoPrazo($prazo));
            ++$n;
        }

        return $n;
    }

    public function textoDoPrazo(PastaPrazo $prazo, User $quem): string
    {
        $hora = (string) $prazo->getHora();
        $quando = self::DIAS[(int) $prazo->getData()->format('w')] . ', ' . $prazo->getData()->format('d/m') . ('' === $hora ? '' : ' às ' . $hora);
        $texto = '📅 ' . $this->primeiroNome($quem) . ' marcou você em ' . ($prazo->isAudiencia() ? 'uma audiência' : 'um prazo') . ': '
            . $this->nomeDoPrazo($prazo) . ' · ' . $quando . ' · Pasta ' . $prazo->getPasta()->getNup();

        return mb_strlen($texto) > self::MAX ? mb_substr($texto, 0, self::MAX - 1) . '…' : $texto;
    }

    public function tituloDoPrazo(PastaPrazo $prazo): string
    {
        $titulo = 'Agenda: ' . $this->nomeDoPrazo($prazo) . ' · Pasta ' . $prazo->getPasta()->getNup();

        return mb_strlen($titulo) > self::MAX ? mb_substr($titulo, 0, self::MAX - 1) . '…' : $titulo;
    }

    /** O nome que a grade mostra: "Audiência" para audiência, senão o título (ou "Prazo"). */
    private function nomeDoPrazo(PastaPrazo $prazo): string
    {
        return $prazo->isAudiencia() ? 'Audiência' : ($prazo->getTitulo() ?: 'Prazo');
    }

    private function primeiroNome(User $quem): string
    {
        return explode(' ', trim((string) $quem->getFullName()))[0] ?: 'Um colega';
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

    /** Como no desenho (BJCentral.notificar): "Agenda: <título>". */
    public function titulo(Evento $evento): string
    {
        $titulo = 'Agenda: ' . $evento->getTitulo();

        return mb_strlen($titulo) > self::MAX ? mb_substr($titulo, 0, self::MAX - 1) . '…' : $titulo;
    }

    private function link(Evento $evento): string
    {
        $dia = ($evento->getDataInicio() ?? new \DateTimeImmutable())->format('Y-m-d');

        return $this->urls->generate('agenda_index') . '#ev=ev-' . $evento->getId() . '-' . $dia;
    }
}
