<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Repository\NotificacaoRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Exception\LembreteRecusadoException;
use App\Tarefa\Service\TextoDoDespacho;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Botão "Lembrar" da faixa Aguardando (desenho 04B L50, L131-133; spec `minhas-metas-esteira-2210.md`, L10).
 *
 * Avisa na Central cada pessoa com quem a meta está ({@see MontarSituacaoDaMetaUseCase::quemAguarda()}): os
 * responsáveis, ou o autor quando a meta está em revisão. Título "Lembrete: meta aguardando revisão|despacho · Pasta X"
 * e texto "<título> está com você há N dia(s).", como o `aguCobrar` do desenho. Grava no histórico da meta
 * "Lembrete enviado para A, B." (mensagem com a ação `Lembrete`: sem código de registro, sem natureza nem prazo, e
 * não se edita).
 *
 * Regras, as do sino "Alertar para verificar" (`AlertarResponsavelDaMetaUseCase`): só meta aberta; só quem tem vínculo
 * ativo no escritório e vê a pasta da meta; nunca quem pede; e no máximo um lembrete por hora para a mesma pessoa
 * sobre a mesma meta, lido da própria `notificacao` (sem coluna nova).
 */
final class LembrarMetaUseCase
{
    /** Tipo do aviso (string livre em `notificacao.tipo`), mapeado na Central em `TiposDeAvisoDaCentral`. */
    public const TIPO_NOTIFICACAO = 'tarefa_lembrete';

    public const INTERVALO_MINIMO = 'PT1H';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificacaoService $notificacoes,
        private readonly NotificacaoRepository $notificacaoRepository,
        private readonly UserTenantRepository $vinculos,
        private readonly TextoDoDespacho $texto,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @return list<string> os nomes de quem foi lembrado, como aparecem no histórico */
    public function executar(Tarefa $meta, User $quemPede, Tenant $escritorio, \DateTimeImmutable $agora): array
    {
        if ($meta->getTenant()?->getId() !== $escritorio->getId()) {
            throw new \LogicException('Meta de outro escritório.');
        }
        if ($meta->getStatus() === Tarefa::STATUS_CONCLUIDA) {
            throw new LembreteRecusadoException('Meta concluída não recebe lembrete.');
        }

        $destinatarios = $this->destinatarios($meta, $quemPede, $escritorio);
        $this->exigirIntervalo($meta, $destinatarios, $agora);

        $emRevisao = $meta->getStatus() === Tarefa::STATUS_EM_REVISAO;
        $titulo = sprintf('Lembrete: meta aguardando %s · Pasta %s', $emRevisao ? 'revisão' : 'despacho', $meta->getPasta()->getNup() ?? '#' . $meta->getPasta()->getId());
        $texto = sprintf('%s está com você há %d dia(s).', $meta->getTitulo(), MontarSituacaoDaMetaUseCase::diasAguardando($meta, $agora));
        $url = $this->urls->generate('tarefa_show', ['id' => $meta->getId()]);
        foreach ($destinatarios as $pessoa) {
            $this->notificacoes->criar($pessoa, $escritorio, self::TIPO_NOTIFICACAO, $titulo, $texto, $meta)->setUrl($url);
        }

        $nomes = array_map(self::nome(...), $destinatarios);
        $this->registrarNoHistorico($meta, $quemPede, $escritorio, $nomes, $agora);
        $this->em->flush();

        return $nomes;
    }

    /** @return list<User> com quem a meta está, menos quem pede, quem saiu do escritório e quem não vê a pasta */
    private function destinatarios(Tarefa $meta, User $quemPede, Tenant $escritorio): array
    {
        $quem = array_values(array_filter(
            MontarSituacaoDaMetaUseCase::quemAguarda($meta),
            fn (User $p): bool => $p->getId() !== $quemPede->getId()
                && $this->vinculos->existeVinculoAtivo($p, $escritorio)
                && $this->notificacoes->veAPastaDaMeta($meta, $p),
        ));
        if ($quem === []) {
            throw new LembreteRecusadoException('Não há a quem lembrar: a meta está só com você.');
        }

        return $quem;
    }

    /** @param list<User> $destinatarios */
    private function exigirIntervalo(Tarefa $meta, array $destinatarios, \DateTimeImmutable $agora): void
    {
        $limite = $agora->sub(new \DateInterval(self::INTERVALO_MINIMO));
        foreach ($destinatarios as $pessoa) {
            $ultimo = $this->notificacaoRepository->findOneBy(
                ['tarefa' => $meta, 'usuario' => $pessoa, 'tipo' => self::TIPO_NOTIFICACAO],
                ['criadaEm' => 'DESC'],
            );
            if ($ultimo !== null && $ultimo->getCriadaEm() > $limite) {
                throw new LembreteRecusadoException(sprintf(
                    '%s já recebeu um lembrete desta meta às %s. Aguarde uma hora para lembrar de novo.',
                    self::nome($pessoa),
                    $ultimo->getCriadaEm()->format('H:i'),
                ));
            }
        }
    }

    /** @param list<string> $nomes */
    private function registrarNoHistorico(Tarefa $meta, User $quemPede, Tenant $escritorio, array $nomes, \DateTimeImmutable $agora): void
    {
        $lembrete = (new TarefaMensagem())
            ->setUsuario($quemPede)
            ->setTenant($escritorio)
            ->setMensagem($this->texto->paraHtml(sprintf('Lembrete enviado para %s.', implode(', ', $nomes))))
            ->setAcao(AcaoDoDespacho::Lembrete)
            ->setCriadoEm($agora);
        $meta->addMensagem($lembrete);
        $this->em->persist($lembrete);
    }

    /** O nome com as iniciais maiúsculas, como o `cap` do desenho (L131). */
    private static function nome(User $pessoa): string
    {
        return mb_convert_case(trim((string) ($pessoa->getFullName() ?? $pessoa->getEmail())), MB_CASE_TITLE);
    }
}
