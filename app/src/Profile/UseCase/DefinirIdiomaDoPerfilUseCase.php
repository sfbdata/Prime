<?php

declare(strict_types=1);

namespace App\Profile\UseCase;

use App\Entity\Auth\User;
use App\Profile\Enum\Idioma;
use App\Profile\Exception\IdiomaIndisponivelException;
use App\Profile\Repository\UserProfileRepository;

/**
 * Grava o idioma do sistema escolhido pela pessoa no modal "Idioma do sistema" (spec idioma-do-sistema, entrega 1).
 *
 * Quem: a própria pessoa, logada (vale só para ela; não depende de escritório). Só aceita idioma liberado
 * ({@see Idioma::ativo()}); o desconhecido e o "em breve" são recusados sem gravar. O perfil nasce se ainda não
 * existir, como nas outras preferências.
 */
final class DefinirIdiomaDoPerfilUseCase
{
    public function __construct(
        private readonly ObterOuCriarPerfilUseCase $obterOuCriarPerfil,
        private readonly UserProfileRepository $perfis,
    ) {
    }

    /** @throws IdiomaIndisponivelException */
    public function executar(User $usuario, string $valor): Idioma
    {
        $idioma = Idioma::tryFrom($valor);
        if ($idioma === null || !$idioma->ativo()) {
            throw new IdiomaIndisponivelException();
        }

        $perfil = $this->obterOuCriarPerfil->executar($usuario);
        $perfil->setIdioma($idioma);
        $this->perfis->salvar($perfil, flush: true);

        return $idioma;
    }
}
