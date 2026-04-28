<?php
declare(strict_types=1);
namespace App\Twig;

use App\Entity\Auth\User;
use App\Profile\Repository\UserProfileRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

final class ProfileExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly UserProfileRepository $profileRepository,
        private readonly Security $security,
    ) {}

    public function getGlobals(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return ['fotoPerfilUrl' => null];
        }

        $perfil = $this->profileRepository->buscarPorUsuario($user);

        return ['fotoPerfilUrl' => $perfil?->getFotoUrl()];
    }
}
