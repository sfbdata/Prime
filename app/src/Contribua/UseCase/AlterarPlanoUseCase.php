<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\AlterarPlanoInput;
use App\Contribua\Entity\Plano;
use App\Contribua\Repository\PlanoRepository;

/**
 * O Master muda preço, espaço, limite de usuários, nome, descrição ou situação de um plano (desenho 05,
 * "Planos e limites"; decisão do dono, 19/10/2026, de editar por tela). As regras moram em `Plano`.
 * A autorização (`ROLE_SUPER_ADMIN`) e o escopo de auditoria de plataforma são do controller (Perfis).
 *
 * Mudar a cota de um plano não apaga nada: quem passar a ficar acima só deixa de enviar arquivo novo, e só
 * com o bloqueio ligado.
 */
final class AlterarPlanoUseCase
{
    public function __construct(
        private readonly PlanoRepository $planos,
    ) {
    }

    public function executar(AlterarPlanoInput $input): void
    {
        $plano = $this->planos->findOneBy(['codigo' => $input->codigo]);
        if (!$plano instanceof Plano) {
            throw new \DomainException('Plano não encontrado.');
        }

        $plano->alterar($input->nome, $input->cotaGb, $input->precoCentavos, $input->limiteUsuarios, $input->descricao, $input->ativo);
        $this->planos->salvar($plano, flush: true);
    }
}
