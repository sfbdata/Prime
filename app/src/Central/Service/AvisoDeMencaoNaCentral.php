<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\Contract\TravaDosAvisosDeMencao;
use App\Central\Entity\Conversa;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Repository\NotificacaoRepository;
use App\Service\NotificacaoService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O sino de quem foi @mencionado numa conversa da Central (`docs/specs/central-comunicacao-a2.md` §2).
 *
 * A `Notificacao` é `Auditavel` — o `audit_log` grava o estado inteiro e é lido por quem administra o escritório.
 * Por isso a notificação leva só quem mencionou e o link, **nunca** o texto da conversa (`mensagem = null`).
 *
 * Sem enxurrada: enquanto a pessoa tiver uma menção NÃO LIDA desta conversa, não ganha outra. Ler a conversa lê a
 * menção ({@see marcarComoLidas}), e a próxima volta a avisar.
 *
 * Sem duplicata na corrida: a checagem e a gravação de cada destinatário acontecem sob a trava dele
 * ({@see TravaDosAvisosDeMencao}), tomada ANTES de olhar se já há aviso. O segundo envio simultâneo espera o
 * primeiro commitar e então vê o aviso dele. As travas são tomadas em ordem de id, para dois envios que avisam as
 * mesmas pessoas nunca se esperarem em cruz. Um índice único no banco foi descartado de propósito: a colisão
 * derrubaria o flush inteiro — e com ele a MENSAGEM, que é legítima.
 *
 * Só persiste: o `flush` é do UseCase, na mesma transação da mensagem e das travas.
 */
final class AvisoDeMencaoNaCentral
{
    public function __construct(
        private readonly NotificacaoService $notificacoes,
        private readonly NotificacaoRepository $repositorio,
        private readonly UrlGeneratorInterface $urls,
        private readonly TravaDosAvisosDeMencao $trava,
    ) {
    }

    /** @param list<User> $mencionados quem já passou pela regra de mencionável (participante, ativo, não o autor) */
    public function avisar(Conversa $conversa, User $autor, Tenant $tenant, array $mencionados): void
    {
        if ($mencionados === []) {
            return;
        }

        $url = $this->urlDa($conversa);
        $titulo = $conversa->isGrupo()
            ? mb_substr(sprintf('%s mencionou você no grupo %s', PrimeiroNome::de($autor), (string) $conversa->getNome()), 0, 255)
            : sprintf('%s mencionou você na Central de Comunicação', PrimeiroNome::de($autor));

        usort($mencionados, static fn (User $a, User $b): int => (int) $a->getId() <=> (int) $b->getId());
        foreach ($mencionados as $pessoa) {
            $this->trava->travar($pessoa);
            $pendente = $this->repositorio->findOneBy([
                'usuario' => $pessoa,
                'tenant' => $tenant,
                'tipo' => Notificacao::TIPO_CENTRAL_MENCAO,
                'url' => $url,
                'lida' => false,
            ]);
            if ($pendente !== null) {
                continue;
            }

            $this->notificacoes->criar($pessoa, $tenant, Notificacao::TIPO_CENTRAL_MENCAO, $titulo)->setUrl($url);
        }
    }

    /**
     * Os avisos não lidos de `$leitor` sobre esta conversa viram lidos — quem leu a conversa já viu a menção (e, no
     * grupo, já sabe que foi adicionado).
     */
    public function marcarComoLidas(Conversa $conversa, User $leitor, Tenant $tenant): void
    {
        foreach ([Notificacao::TIPO_CENTRAL_MENCAO, Notificacao::TIPO_CENTRAL_GRUPO_ADICIONADO] as $tipo) {
            $this->repositorio->marcarComoLidasPelaUrl($leitor, $tenant, $tipo, $this->urlDa($conversa));
        }
    }

    private function urlDa(Conversa $conversa): string
    {
        return $this->urls->generate('central_index', ['conversa' => $conversa->getId()]);
    }
}
