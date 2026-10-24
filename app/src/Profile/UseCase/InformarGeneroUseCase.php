<?php

declare(strict_types=1);

namespace App\Profile\UseCase;

use App\Entity\Auth\User;
use App\Profile\Enum\Genero;
use App\Profile\Repository\UserProfileRepository;

/**
 * "Como devemos tratar você?" (spec genero-no-cadastro, regra 3): a janela de quem ainda não informou o gênero.
 *
 * Quem: a própria pessoa logada, só para si (não há id no pedido). Pré-condição: nenhuma além do login; o perfil é
 * criado se faltar, como no Meu Perfil. Fluxo: valida o valor (só Feminino ou Masculino), grava no perfil e salva.
 * Recusa: vazio, desconhecido ou o legado `nao_informado` lançam \InvalidArgumentException("Informe o gênero.").
 */
final class InformarGeneroUseCase
{
    public function __construct(
        private readonly ObterOuCriarPerfilUseCase $obterOuCriarPerfil,
        private readonly UserProfileRepository $repository,
    ) {
    }

    public function executar(User $user, ?string $valor): Genero
    {
        $genero = Genero::informado($valor);
        $perfil = $this->obterOuCriarPerfil->executar($user);
        $perfil->setGenero($genero);
        $this->repository->salvar($perfil, flush: true);

        return $genero;
    }
}
