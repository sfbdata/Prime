<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\ModeloDePeca;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\ContextoDaPecaDaPasta;
use App\Pasta\Service\PreenchedorDeModelo;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * "DADOS DA PEÇA" do desenho 02: as variáveis que o modelo usa e o que a pasta já sabe de cada uma.
 * Sem dado na pasta, o valor vem vazio e a pessoa completa antes de gerar.
 */
final class PrepararDadosDaPecaUseCase
{
    public function __construct(
        private readonly ContextoDaPecaDaPasta $contexto,
    ) {
    }

    /** @return array{chaves: list<string>, valores: array<string, string>} */
    public function executar(ModeloDePeca $modelo, Pasta $pasta, User $usuario, Tenant $tenant, \DateTimeImmutable $agora): array
    {
        if ($modelo->getTenant() !== $tenant || $pasta->getTenant() !== $tenant) {
            throw new AccessDeniedException('Modelo ou pasta não pertence ao escritório.');
        }

        $chaves  = PreenchedorDeModelo::chavesDo($modelo->getCorpo());
        $contexto = $this->contexto->montar($pasta, $usuario, $agora);
        $valores = [];
        foreach ($chaves as $chave) {
            $valores[$chave] = $contexto[$chave] ?? '';
        }

        return ['chaves' => $chaves, 'valores' => $valores];
    }
}
