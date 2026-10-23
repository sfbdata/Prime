<?php

declare(strict_types=1);

namespace App\Ponto\Twig;

use App\Entity\Auth\User;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Service\ChaveDoPontoEletronico;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `tem_jornada_no_escritorio()`: a pessoa logada tem jornada pessoal no escritório da sessão? Decide o alerta de ponto e
 * o atalho do cabeçalho, que antes liam a jornada única da pessoa (spec seguranca-b-jornada §3.2). Com a chave
 * "Registra ponto eletrônico" desligada neste escritório, devolve false: quem não bate ponto aqui não é chamado a bater.
 */
final class JornadaNoEscritorioExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<string, bool> a resposta por pessoa e escritório, uma consulta por requisição */
    private array $respostas = [];

    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly JornadaColaboradorRepository $jornadas,
        private readonly ChaveDoPontoEletronico $chave,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tem_jornada_no_escritorio', [$this, 'temJornada'])];
    }

    public function reset(): void
    {
        $this->respostas = [];
    }

    public function temJornada(): bool
    {
        $usuario = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null) {
            return false;
        }
        $chave = $usuario->getId() . ':' . $tenant->getId();

        return $this->respostas[$chave] ??= !$this->chave->estaDesligada($usuario, $tenant)
            && $this->jornadas->findByUserETenant($usuario, $tenant) !== null;
    }
}
