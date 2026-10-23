<?php

declare(strict_types=1);

namespace App\Ponto\Twig;

use App\Entity\Auth\User;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Service\ChaveDoPontoEletronico;
use App\Ponto\Service\PublicoDosAvisosDePonto;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `tem_jornada_no_escritorio()`: a pessoa logada tem jornada pessoal no escritório da sessão? Decide o alerta de ponto e
 * o atalho do cabeçalho, que antes liam a jornada única da pessoa (spec seguranca-b-jornada §3.2). Com a chave
 * "Registra ponto eletrônico" desligada neste escritório, devolve false: quem não bate ponto aqui não é chamado a bater.
 * E só para o público dos avisos de ponto (`PublicoDosAvisosDePonto`, decisão do dono 23/10).
 *
 * `recebe_avisos_de_ponto()`: a pessoa logada é do público dos avisos de ponto no escritório da sessão (o cartão diário
 * de pendência de registro).
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
        private readonly PublicoDosAvisosDePonto $publico,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tem_jornada_no_escritorio', [$this, 'temJornada']),
            new TwigFunction('recebe_avisos_de_ponto', [$this, 'recebeAvisos']),
        ];
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
            && $this->jornadas->findByUserETenant($usuario, $tenant) !== null
            && $this->recebeAvisos();
    }

    public function recebeAvisos(): bool
    {
        $usuario = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null) {
            return false;
        }

        return $this->respostas['publico:' . $usuario->getId() . ':' . $tenant->getId()] ??= $this->publico->recebe($usuario, $tenant);
    }
}
