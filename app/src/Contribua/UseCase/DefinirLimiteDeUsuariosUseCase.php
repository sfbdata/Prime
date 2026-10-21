<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\DefinirLimiteDeUsuariosInput;
use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Repository\UsuariosDoEscritorioRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Entity\Auth\User;

/**
 * O Master define quantos usuários um escritório pode ter (desenho 05, "Definir limite de usuários").
 *
 * Este domínio só guarda o limite; quem o aplica no convite é o Perfis. A autorização
 * (`ROLE_SUPER_ADMIN`) é do controller que chama.
 *
 * Regras do desenho: justificativa obrigatória e nunca "Menor que os usuários já cadastrados."
 */
final class DefinirLimiteDeUsuariosUseCase
{
    public function __construct(
        private readonly EspacoDoEscritorioRepository $espacos,
        private readonly PlanoRepository $planos,
        private readonly UsuariosDoEscritorioRepository $usuarios,
        private readonly SituacaoDoEspaco $situacao,
    ) {
    }

    public function executar(DefinirLimiteDeUsuariosInput $input, ?User $master): RetratoDoEspaco
    {
        if (trim($input->justificativa) === '') {
            throw new \DomainException('A justificativa é obrigatória e fica na auditoria.');
        }
        if ($input->limite !== null && $input->limite < $this->usuarios->quantos($input->tenantId)) {
            throw new \DomainException('Menor que os usuários já cadastrados.');
        }

        $espaco = $this->espacos->doTenantOuNovo($input->tenantId, $this->planos->free());
        $espaco->definirLimiteDeUsuarios($input->limite, trim($input->justificativa), $master);
        $this->espacos->salvar($espaco, flush: true);

        return $this->situacao->doEscritorio($input->tenantId);
    }
}
