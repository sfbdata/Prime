<?php

declare(strict_types=1);

namespace App\Master\Twig;

use App\Entity\Auth\User;
use App\Master\Service\CatalogoDeTemas;
use App\Master\Service\RegraDosMasters;
use App\Master\Service\TemaComemorativo;
use App\Master\Service\TemaDoDia;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Funções Twig dos temas comemorativos (spec docs/specs/temas-comemorativos.md):
 *
 *  - `tema_comemorativo()`: o tema que vale agora (array do {@see TemaComemorativo::paraJson()}) ou null. O parcial
 *    layout/_globais/tema_comemorativo.html.twig só desenha algo quando há tema.
 *  - `tema_comemorativo_previa()`: o catálogo inteiro, só para o Master (null para os demais), para a prévia do
 *    card de controle funcionar em qualquer página sem nova requisição.
 *  - `tema_comemorativo_painel()`: o estado do card de controle ({@see TemaDoDia::estado()}).
 */
final class TemaComemorativoExtension extends AbstractExtension
{
    public function __construct(
        private readonly TemaDoDia $temaDoDia,
        private readonly Security $security,
        private readonly RegraDosMasters $regraDosMasters,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tema_comemorativo', $this->temaAtivo(...)),
            new TwigFunction('tema_comemorativo_previa', $this->catalogoParaPrevia(...)),
            new TwigFunction('tema_comemorativo_painel', $this->temaDoDia->estado(...)),
        ];
    }

    /** @return array<string, mixed>|null */
    public function temaAtivo(): ?array
    {
        return $this->temaDoDia->ativo()?->paraJson();
    }

    /** @return list<array<string, mixed>>|null */
    public function catalogoParaPrevia(): ?array
    {
        $usuario = $this->security->getUser();
        if (!$usuario instanceof User || !$this->regraDosMasters->ehMaster($usuario)) {
            return null;
        }

        return array_values(array_map(static fn (TemaComemorativo $t): array => $t->paraJson(), CatalogoDeTemas::todos()));
    }
}
