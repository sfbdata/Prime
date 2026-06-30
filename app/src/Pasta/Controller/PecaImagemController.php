<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Service\Tenant\TenantContext;
use App\Shared\Service\ArquivoStorageInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serve as imagens do editor de peças, que ficam embutidas no HTML das peças como
 * `<img src="/uploads/pastas/<hex>.<ext>">` (e o ExportarPecaTextoUseCase reescreve `/uploads/`
 * para caminho de disco no export). Antes do C5 essa URL era servida ESTÁTICA pelo nginx, sem
 * qualquer auth; agora o nginx roteia `/uploads/` ao front controller e esta rota entrega o
 * arquivo via ArquivoStorageInterface.
 *
 * Isolamento por tenant (M5): a imagem mora em `%uploads_dir%/<tenantId>/` (gravada lá pelo upload
 * em `PeticionarController::uploadImagemEditor`) e esta rota só resolve sob a subpasta do tenant da
 * SESSÃO. Um logado do escritório A não baixa a imagem de B mesmo sabendo o nome hex → 404. A URL
 * embutida no HTML continua `/uploads/pastas/<hex>` (o tenant é injetado aqui, no disco, não na URL).
 * Fail-closed: sem tenant na sessão (super-admin) → 404. Fecha de quebra o caminho paralelo às
 * imagens de documento (que só ficam acessíveis pela rota de entidade `pasta_documento_*`).
 *
 * Restrita a extensões de imagem: documentos/peças (pdf/html/docx) NÃO são servidos por aqui —
 * eles têm rotas próprias por entidade (pasta_documento_*), com checagem de tenant/posse.
 */
final class PecaImagemController extends AbstractController
{
    public function __construct(
        private readonly ArquivoStorageInterface $storage,
        private readonly TenantContext $tenantContext,
        private readonly string $uploadsDir,
    ) {
    }

    #[Route(
        '/uploads/pastas/{nome}',
        name: 'pasta_imagem_editor',
        methods: ['GET'],
        requirements: ['nome' => '[A-Za-z0-9._-]+\.(?:jpg|jpeg|png|gif|webp)'],
    )]
    public function servir(string $nome): Response
    {
        // Anti path-traversal (a requirement já exclui '/', isto é cinto + suspensório).
        if (basename($nome) !== $nome) {
            throw $this->createNotFoundException();
        }

        // Isolamento por tenant: só resolve sob a subpasta do tenant da sessão (fail-closed se ausente).
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException();
        }

        $caminho = $this->storage->caminho($this->uploadsDir . '/' . $tenant->getId(), $nome);
        if (!$this->storage->existe($caminho)) {
            throw $this->createNotFoundException();
        }

        return $this->storage->servir($caminho, $nome, inline: true);
    }
}
