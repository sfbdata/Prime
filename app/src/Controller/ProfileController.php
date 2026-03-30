<?php

namespace App\Controller;

use App\Repository\ResourceAccessRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ProfileController extends AbstractController
{
    #[Route('/perfil', name: 'app_profile')]
    public function index(ResourceAccessRepository $resourceAccessRepository): Response
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException('Você precisa estar logado.');
        }

        return $this->render('profile/index.html.twig', [
            'user'             => $user,
            'resourceAccesses' => $resourceAccessRepository->findByUser($user),
        ]);
    }
}
