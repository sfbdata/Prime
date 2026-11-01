<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\EnviarMensagemSuporteInput;
use App\Auth\Entity\MensagemSuporte;
use App\Auth\Repository\MensagemSuporteRepository;
use App\Auth\Service\PaginaDeEntrada;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RegraDosMasters;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Falar com suporte" da tela de entrada (desenho 10, spec login-desenho-10 comportamento 5).
 *
 * Quem dispara: qualquer pessoa, em geral sem conseguir entrar. Grava a mensagem com o destinatário escolhido pelo
 * Master e avisa no sino: o escolhido, ou todos os Masters ativos quando não há escolha. O sino é do escritório
 * (Notificacao é TenantAware), então o aviso vai no primeiro escritório ativo de quem recebe; sem escritório, sem
 * aviso (a mensagem continua na lista /suporte/mensagens).
 *
 * A mensagem é gravada ANTES do aviso, de propósito: uma falha no sino só vai para o log e não perde a mensagem.
 */
final class EnviarMensagemSuporteUseCase
{
    public const TIPO_NOTIFICACAO = 'suporte.mensagem';

    public function __construct(
        private readonly MensagemSuporteRepository $mensagens,
        private readonly PaginaDeEntrada $paginaDeEntrada,
        private readonly UserRepository $usuarios,
        private readonly UserTenantRepository $vinculos,
        private readonly NotificacaoService $notificacoes,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        private readonly RegraDosMasters $regraDosMasters,
    ) {
    }

    public function executar(EnviarMensagemSuporteInput $input): void
    {
        $destinatario = $this->paginaDeEntrada->destinatarioDoSuporte();
        $this->mensagens->salvar($this->novaMensagem($input, $destinatario), flush: true);

        $titulo = $this->tituloDoAviso($input);
        $texto = $this->textoDoAviso($input);
        $url = $this->urls->generate('auth_suporte_mensagens');
        foreach ($this->quemRecebe($destinatario) as $user) {
            $this->avisarNoSino($user, $titulo, $texto, $url);
        }
    }

    private function novaMensagem(EnviarMensagemSuporteInput $input, ?User $destinatario): MensagemSuporte
    {
        return new MensagemSuporte(
            nome: $input->nome,
            email: $input->email,
            assunto: $input->assunto,
            mensagem: $input->mensagem,
            ip: $input->ip,
            userAgent: $input->userAgent,
            destinatario: $destinatario,
            origem: $input->origem,
        );
    }

    /** "Suporte · Nome", como o bjSuporteFila do bluejus-central.js (o aviso "Mensagem" da Central). */
    private function tituloDoAviso(EnviarMensagemSuporteInput $input): string
    {
        return mb_substr('Suporte · ' . ($input->nome !== '' ? $input->nome : $input->email), 0, 255);
    }

    /** "💬 Nome (e-mail) pela página de login: mensagem", o texto do desenho; do site, "pelo site". */
    private function textoDoAviso(EnviarMensagemSuporteInput $input): string
    {
        $onde = $input->origem === 'site' ? 'pelo site' : 'pela página de login';

        return '💬 ' . ($input->nome !== '' ? $input->nome : 'Visitante') . ' (' . $input->email . ') ' . $onde . ': ' . $input->mensagem;
    }

    /** @return list<User> */
    private function quemRecebe(?User $destinatario): array
    {
        if ($destinatario !== null) {
            return [$destinatario];
        }
        $masters = $this->usuarios->findComPapelGlobal('ROLE_SUPER_ADMIN');

        return array_values(array_filter($masters, static fn (User $master): bool => $master->isActive()));
    }

    private function avisarNoSino(User $user, string $titulo, string $texto, string $url): void
    {
        try {
            $escritorio = $this->primeiroEscritorioAtivo($user);
            if ($escritorio === null) {
                return;
            }
            $this->notificacoes->criarNotificacao($user, $escritorio, self::TIPO_NOTIFICACAO, $titulo, $url, $texto);
        } catch (\Throwable $e) {
            $this->logger->error('Suporte da tela de entrada: aviso no sino falhou.', [
                'usuario' => $user->getId(),
                'erro' => $e->getMessage(),
            ]);
        }
    }

    /**
     * O vínculo ativo mais antigo (menor id) numa CONTA DO MASTER, para o aviso cair sempre no mesmo escritório. Nunca
     * no de um cliente: a Notificacao é auditada, e o texto do visitante iria parar no audit_log que o cliente lê.
     */
    private function primeiroEscritorioAtivo(User $user): ?Tenant
    {
        $vinculos = array_values(array_filter(
            $this->vinculos->findActiveByUser($user),
            fn (UserTenant $v): bool => $this->regraDosMasters->ehContaDoMaster($v->getTenant()),
        ));
        usort($vinculos, static fn (UserTenant $a, UserTenant $b): int => ($a->getId() ?? 0) <=> ($b->getId() ?? 0));

        return ($vinculos[0] ?? null)?->getTenant();
    }
}
