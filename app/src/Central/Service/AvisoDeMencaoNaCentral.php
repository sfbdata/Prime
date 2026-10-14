<?php

declare(strict_types=1);

namespace App\Central\Service;

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
 * Só persiste: o `flush` é do UseCase, na mesma transação da mensagem.
 */
final class AvisoDeMencaoNaCentral
{
    public function __construct(
        private readonly NotificacaoService $notificacoes,
        private readonly NotificacaoRepository $repositorio,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @param list<User> $mencionados quem já passou pela regra de mencionável (participante, ativo, não o autor) */
    public function avisar(Conversa $conversa, User $autor, Tenant $tenant, array $mencionados): void
    {
        if ($mencionados === []) {
            return;
        }

        $url = $this->urlDa($conversa);
        $titulo = sprintf('%s mencionou você na Central de Comunicação', self::primeiroNome($autor));

        foreach ($mencionados as $pessoa) {
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

    /** As menções não lidas de `$leitor` nesta conversa viram lidas — quem leu a conversa já viu a menção. */
    public function marcarComoLidas(Conversa $conversa, User $leitor, Tenant $tenant): void
    {
        $this->repositorio->marcarComoLidasPelaUrl($leitor, $tenant, Notificacao::TIPO_CENTRAL_MENCAO, $this->urlDa($conversa));
    }

    private function urlDa(Conversa $conversa): string
    {
        return $this->urls->generate('central_index', ['conversa' => $conversa->getId()]);
    }

    private static function primeiroNome(User $autor): string
    {
        $nome = trim((string) $autor->getFullName());

        return $nome === '' ? 'Um colega' : explode(' ', $nome)[0];
    }
}
