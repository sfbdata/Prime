<?php

declare(strict_types=1);

namespace App\Backup\Coletor;

use App\Backup\Service\ZipDoBackup;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Armazenamento\ChavesDePerfil;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Perfil no backup (prioridade 0, o último): os dados da PRÓPRIA pessoa que pediu, como "Meu perfil" mostra: nome,
 * e-mail, OAB, telefone e a foto. Sem módulo (todo usuário tem perfil). Nunca entram senha, hash, 2FA, tokens de
 * convite, ids de login social nem códigos. O perfil de outra pessoa nunca entra.
 */
#[AsTaggedItem(priority: 0)]
final class ColetorDoPerfil implements ColetorDoBackup
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function coletar(User $usuario, Tenant $tenant, ZipDoBackup $zip): void
    {
        // Os coletores anteriores soltam o EntityManager em lotes: recarrega a pessoa (sempre a que pediu, nunca outra).
        $usuario = $this->em->find(User::class, (int) $usuario->getId()) ?? throw new \LogicException('Usuário do backup sumiu.');
        $perfil  = $usuario->getProfile();
        $dados  = [
            'nome'     => $usuario->getFullName(),
            'email'    => $usuario->getEmail(),
            'oab'      => [
                'numero' => $usuario->getOabNumero(),
                'uf'     => $usuario->getOabUf(),
            ],
            'telefone' => $perfil?->getTelefone(),
            'foto'     => null,
        ];

        if ($perfil !== null && $perfil->getFotoUrl() !== null && $perfil->getFotoUrl() !== '') {
            $dir = $zip->reservarDiretorio('', 'perfil');
            $nome = $zip->adicionarArquivo($dir, (string) $perfil->getFotoUrl(), static fn () => ChavesDePerfil::foto($perfil));
            $dados['foto'] = $nome;
            if ($nome !== null) {
                $zip->contar('Foto do perfil');
            }
        }

        $dir = $zip->diretorioDeDados();
        $zip->adicionarJson($dir . '/perfil.json', $dados);
    }
}
