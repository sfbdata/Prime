<?php

declare(strict_types=1);

namespace App\Backup\Controller;

use App\Backup\Exception\BackupIndisponivel;
use App\Backup\Exception\PedidoDeBackupNaoPermitido;
use App\Backup\UseCase\BaixarBackupUseCase;
use App\Backup\UseCase\PedirBackupUseCase;
use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use App\Shared\Http\EntregaDeArquivo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Backup e Exportação de Dados" no Meu Perfil (spec backup-e-exportacao-de-dados, seção 6): pedir o backup e baixar o
 * ZIP pronto. Sempre o escritório da SESSÃO e a pessoa logada, nunca ids do formulário. O pedido sai em JSON (a tela
 * recarrega ao ter sucesso, como a exclusão da conta); o download é rota autenticada e responde 404 a tudo que não for
 * da pessoa, pronto e dentro da validade. As duas rotas são só do DONO do escritório (`DonoDoEscritorio`): outra pessoa
 * recebe 403.
 */
#[Route('/perfil/backup')]
final class BackupController extends AbstractController
{
    public const CSRF = 'backup_pedir';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PedirBackupUseCase $pedir,
        private readonly BaixarBackupUseCase $baixar,
        private readonly EntregaDeArquivo $entrega,
        // `limiter.backup_pedido`: freia o clique repetido; a regra de 1 pedido por dia é do UseCase.
        private readonly RateLimiterFactoryInterface $backupPedidoLimiter,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/pedir', name: 'backup_pedir', methods: ['POST'])]
    public function pedirBackup(Request $request): JsonResponse
    {
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || null === $tenant) {
            return $this->json(['erro' => $this->t('selecione_escritorio')], Response::HTTP_FORBIDDEN);
        }
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => $this->t('token_invalido')], Response::HTTP_FORBIDDEN);
        }
        // Ordem: guardas (sessão, CSRF) → limiter → UseCase; pedido recusado por guarda não gasta a cota de ninguém.
        if (!$this->backupPedidoLimiter->create((string) $usuario->getId())->consume()->isAccepted()) {
            return $this->json(['erro' => $this->t('muitos_pedidos')], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $this->pedir->executar($usuario, $tenant);
        } catch (AccessDeniedException) {
            return $this->json(['erro' => $this->t('so_o_dono')], Response::HTTP_FORBIDDEN);
        } catch (PedidoDeBackupNaoPermitido $e) {
            return $this->json(['erro' => $this->tradutor->trans($e->chaveDeTraducao)], Response::HTTP_CONFLICT);
        }

        return $this->json(['ok' => true, 'aviso' => $this->t('pedido_recebido')]);
    }

    #[Route('/{id}/baixar', name: 'backup_baixar', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function baixarBackup(int $id): Response
    {
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || null === $tenant) {
            throw new NotFoundHttpException();
        }

        try {
            $backup = $this->baixar->executar($id, $usuario, $tenant);
        } catch (BackupIndisponivel) {
            throw new NotFoundHttpException();
        }

        // Se a entrega lançar (404/503 do armazenamento), nada é auditado: o `backup_dl` só vale para download que saiu.
        $resposta = $this->entrega->resposta($backup->chave, $backup->nomeDoArquivo, inline: false);
        $this->baixar->registrarDownload($backup, $usuario, $tenant);
        // Dado de cliente: nunca guardado por cache do navegador ou de proxy (a EntregaDeArquivo compartilhada não é alterada).
        $resposta->headers->set('Cache-Control', 'no-store, private');
        $resposta->headers->set('X-Content-Type-Options', 'nosniff');

        return $resposta;
    }

    private function t(string $chave): string
    {
        return $this->tradutor->trans('perfil.backup.recusa.' . $chave);
    }
}
