<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\DTO\OrigemRemocao;
use App\Tenant\DTO\RemoverColaboradorInput;

/**
 * Saída voluntária: a própria pessoa deixa o escritório. Mesma regra da remoção
 * pelo painel (o vínculo é apagado), só que sem substituto — o que era dela fica
 * desatribuído. RemoverColaboradorDoEscritorioUseCase já trata as diferenças da
 * porta OrigemRemocao::Saida: sem trava de "remover a si mesmo", sem trava do
 * criador do escritório, e a herança dos quadros de Kanban vai para o
 * administrador ativo de vínculo mais antigo. A trava do último administrador
 * (RN06) continua valendo — ela não é condicionada à origem.
 */
final class SairDoEscritorioUseCase
{
    public function __construct(
        private readonly RemoverColaboradorDoEscritorioUseCase $remover,
    ) {}

    public function executar(User $usuario, Tenant $tenant): void
    {
        $this->remover->executar(
            new RemoverColaboradorInput($usuario, $usuario, $tenant, null, OrigemRemocao::Saida)
        );
    }
}
