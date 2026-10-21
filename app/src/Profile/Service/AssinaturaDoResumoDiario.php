<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Preferencia\CatalogoDePreferenciasDoPerfil;
use App\Profile\UseCase\EnviarResumoDiarioDoEscritorioUseCase;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;

/**
 * Fonte única de "o Resumo diário por e-mail está ligado?" (decisão do coordenador, 21/10/2026; spec
 * docs/specs/resumo-diario-por-email.md): a chave do Meu Perfil e o descadastro do rodapé do e-mail dizem a mesma
 * coisa. Ligado = a pessoa não desligou a chave E não está descadastrada da lista `resumo_diario` no escritório (um
 * "não quero receber nada" do escritório também conta, salvo se ela religou o resumo depois).
 *
 * Mexer na chave mexe nos dois: desligar descadastra; ligar reativa só o resumo (mesmo com o "todos" ativo, o
 * descadastro guarda a exceção). Assim quem se descadastrou pelo rodapé vê a chave desligada, e religar pelo Meu
 * Perfil volta a receber.
 */
final class AssinaturaDoResumoDiario
{
    public const ORIGEM = 'meu_perfil';

    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $preferencias,
        private readonly DescadastroDeEmailInterface $descadastro,
    ) {
    }

    public function ligado(Tenant $tenant, User $pessoa): bool
    {
        $gravado = $this->preferencias->valoresDoUsuario($tenant, $pessoa, [CatalogoDePreferenciasDoPerfil::RESUMO_EMAIL]);
        if (($gravado[CatalogoDePreferenciasDoPerfil::RESUMO_EMAIL] ?? true) === false) {
            return false;
        }

        return !$this->descadastro->estaDescadastrado($tenant, (string) $pessoa->getEmail(), EnviarResumoDiarioDoEscritorioUseCase::LISTA);
    }

    public function definir(Tenant $tenant, User $pessoa, bool $ligado): void
    {
        $this->preferencias->gravar($tenant, $pessoa, CatalogoDePreferenciasDoPerfil::RESUMO_EMAIL, $ligado);
        $email = (string) $pessoa->getEmail();
        if ($ligado) {
            $this->descadastro->reativar($tenant, $email, EnviarResumoDiarioDoEscritorioUseCase::LISTA, self::ORIGEM, $pessoa);

            return;
        }
        $this->descadastro->descadastrar($tenant, $email, EnviarResumoDiarioDoEscritorioUseCase::LISTA, self::ORIGEM);
    }
}
