<?php

declare(strict_types=1);

namespace App\Ajuda\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ajuda: o manual do usuário aberto inteiro na tela (leitor de PDF do navegador) e o botão "Baixar manual".
 * O PDF é o mesmo para todos os escritórios: arquivo estático do sistema em public/manual/, servido direto pelo
 * servidor web (nada de armazenamento de escritório nem de EntregaDeArquivo). Por isso não há filtro de tenant.
 * Entra pelo "?" do cabeçalho e pelo Service Desk.
 */
#[IsGranted('ROLE_USER')]
final class AjudaController extends AbstractController
{
    /** Caminho público do manual (relativo a public/) e o nome com que o navegador salva o arquivo. */
    public const MANUAL = 'manual/manual-do-usuario.pdf';
    public const NOME_DO_DOWNLOAD = 'Manual_do_Usuario_BlueJus_2026.pdf';

    #[Route('/ajuda', name: 'ajuda_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('ajuda/index.html.twig', [
            'manual' => self::MANUAL,
            'nomeDoDownload' => self::NOME_DO_DOWNLOAD,
        ]);
    }
}
