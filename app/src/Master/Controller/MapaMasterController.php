<?php

declare(strict_types=1);

namespace App\Master\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Os dois mapas do "Onde estamos atuando" (desenho: mapa-brasil.html e mapa-mundo.html), servidos
 * para o iframe do painel. As contagens chegam pela query string, como no protótipo; as malhas são
 * arquivos locais e os municípios/população vêm das rotas /master/ibge/*.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class MapaMasterController extends AbstractController
{
    #[Route('/master/mapa/brasil', name: 'master_mapa_brasil', methods: ['GET'])]
    public function brasil(): Response
    {
        return $this->render('master/mapa_brasil.html.twig');
    }

    #[Route('/master/mapa/mundo', name: 'master_mapa_mundo', methods: ['GET'])]
    public function mundo(): Response
    {
        return $this->render('master/mapa_mundo.html.twig');
    }
}
