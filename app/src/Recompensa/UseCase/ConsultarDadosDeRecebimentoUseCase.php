<?php

declare(strict_types=1);

namespace App\Recompensa\UseCase;

use App\Recompensa\Entity\DadosDeRecebimentoDeRecompensa;
use App\Profile\Repository\UserProfileRepository;
use App\Recompensa\Repository\DadosDeRecebimentoDeRecompensaRepository;

/**
 * Os dados de recebimento de um indicador COM o documento completo, só para preencher o diálogo "Dados para pagamento"
 * do Master (a lista mostra o documento mascarado). Quem dispara: o Master (ROLE_SUPER_ADMIN no controller). Nulo se
 * ainda não há cadastro.
 *
 * `chavePixDoPerfil`: a chave que a PRÓPRIA pessoa informou no Meu Perfil (spec meu-perfil-pix-recompensas, P2). É só
 * uma sugestão para o Master; nada é preenchido ou pago sozinho, e só o Master chega aqui.
 */
final class ConsultarDadosDeRecebimentoUseCase
{
    public function __construct(
        private readonly DadosDeRecebimentoDeRecompensaRepository $dados,
        private readonly UserProfileRepository $perfis,
    ) {
    }

    public function executar(int $userId): ?DadosDeRecebimentoDeRecompensa
    {
        return $this->dados->buscarDoIndicador($userId);
    }

    public function chavePixDoPerfil(int $userId): ?string
    {
        return $this->perfis->findOneBy(['user' => $userId])?->getChavePixRecompensa();
    }
}
