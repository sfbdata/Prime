<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Shared\Service\ArquivoStorageInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serve as imagens do editor de peças, que ficam embutidas no HTML das peças como
 * `<img src="/uploads/pastas/<hex>.<ext>">` (e o ExportarPecaTextoUseCase reescreve `/uploads/`
 * para caminho de disco no export). Antes do C5 essa URL era servida ESTÁTICA pelo nginx, sem
 * qualquer auth; agora o nginx roteia `/uploads/` ao front controller e esta rota entrega o
 * arquivo SOMENTE a usuário autenticado (firewall `^/ ROLE_USER`), via ArquivoStorageInterface.
 *
 * Restrita a extensões de imagem: documentos/peças (pdf/html/docx) NÃO são servidos por aqui —
 * eles têm rotas próprias por entidade (pasta_documento_*), com checagem de tenant/posse.
 *
 * Residual conhecido (fecha no C5.2, ao mover pastas para var/ + servir imagens por entidade):
 * a checagem é só de autenticação, não de tenant/posse — um usuário logado consegue buscar uma
 * imagem de pasta pelo nome (hex aleatório de 16 bytes). É uma redução grande frente ao acesso
 * anônimo anterior, mas ainda não isola por tenant.
 */
final class PecaImagemController extends AbstractController
{
    public function __construct(
        private readonly ArquivoStorageInterface $storage,
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

        $caminho = $this->storage->caminho($this->uploadsDir, $nome);
        if (!$this->storage->existe($caminho)) {
            throw $this->createNotFoundException();
        }

        return $this->storage->servir($caminho, $nome, inline: true);
    }
}
