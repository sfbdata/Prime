<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\DTO\OrigemRemocao;
use App\Tenant\DTO\RemoverColaboradorInput;

/**
 * Saída voluntária: a própria pessoa deixa o escritório. Mesma regra da remoção
 * pelo painel (o vínculo é apagado), só que sem substituto: o que era dela fica
 * desatribuído. RemoverColaboradorDoEscritorioUseCase trata as diferenças da
 * porta OrigemRemocao::Saida: sem trava de "remover a si mesmo", e a herança dos
 * quadros de Kanban vai para o administrador ativo de vínculo mais antigo.
 *
 * O TITULAR (Tenant.criadoPor) não sai: decisão D4 do dono (spec perfis-master),
 * "o titular nunca é rebaixado nem removido". O UseCase de remoção recusa com
 * "O titular do escritório não pode sair. Fale com o suporte do BlueJus." e a
 * barra superior nem mostra o botão "Sair deste escritório" para ele. A trava do
 * último administrador (RN06) continua valendo para os demais.
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
