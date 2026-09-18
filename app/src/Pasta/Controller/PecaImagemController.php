<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Pasta\Armazenamento\ChavesDePasta;
use App\Service\Tenant\TenantContext;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Http\EntregaDeArquivo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serve as imagens do editor de peças, que ficam embutidas no HTML das peças como
 * `<img src="/uploads/pastas/<hex>.<ext>">` (e o ExportarPecaTextoUseCase reescreve `/uploads/`
 * para caminho de disco no export). Antes do C5 essa URL era servida ESTÁTICA pelo nginx, sem
 * qualquer auth; agora o nginx roteia `/uploads/` ao front controller e esta rota entrega o
 * arquivo pela EntregaDeArquivo, endereçado por chave — sem conhecer o diretório físico (E2.3).
 *
 * Isolamento por tenant (M5): a imagem é da categoria `PASTA_IMAGEM_EDITOR`, que o backend isola
 * fisicamente por escritório (gravada pelo upload em `PeticionarController::uploadImagemEditor`), e
 * esta rota monta a chave com o tenant da SESSÃO. Um logado do escritório A não baixa a imagem de B
 * mesmo sabendo o nome hex → 404. A URL embutida no HTML continua `/uploads/pastas/<hex>` (o tenant
 * entra na chave, não na URL). Fail-closed: sem tenant na sessão (super-admin) → 404. Fecha de
 * quebra o caminho paralelo às imagens de documento (que só ficam acessíveis pela rota de entidade
 * `pasta_documento_*`).
 *
 * Restrita a extensões de imagem: documentos/peças (pdf/html/docx) NÃO são servidos por aqui —
 * eles têm rotas próprias por entidade (pasta_documento_*), com checagem de tenant/posse.
 */
final class PecaImagemController extends AbstractController
{
    public function __construct(
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly EntregaDeArquivo $entrega,
        private readonly TenantContext $tenantContext,
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

        // O nome vem da URL, não do banco: se o armazenamento se recusa a endereçá-lo (".."
        // embutido, por exemplo), para quem pede é o mesmo que não existir — 404, nunca 500.
        try {
            $chave = ChavesDePasta::imagemDoEditor($tenant, $nome);
        } catch (ChaveDeArquivoInvalida) {
            throw $this->createNotFoundException();
        }

        if (!$this->armazenamento->existe($chave)) {
            throw $this->createNotFoundException();
        }

        return $this->entrega->resposta($chave, $nome, inline: true);
    }
}
