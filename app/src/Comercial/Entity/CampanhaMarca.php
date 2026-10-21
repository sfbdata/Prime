<?php

declare(strict_types=1);

namespace App\Comercial\Entity;

use App\Comercial\Repository\CampanhaMarcaRepository;
use App\Entity\Auth\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * As marcas de um Master numa campanha do Relatório (desenho 05 VS 21.10.26, L301-304 e L698-717; spec
 * `comercial-marcas.md`): a estrela, o arquivo e a remoção do relatório. Cada Master tem as próprias marcas (no desenho
 * elas ficam no navegador de quem marcou); sem linha, sem marca.
 *
 * "Remover do relatório" só esconde para quem removeu (`removida_em`): nada da campanha nem dos envios é apagado.
 *
 * Plataforma (sem escritório, do Master), como o resto do Comercial: não é TenantAware nem Auditavel. Gravada e lida
 * por SQL ({@see CampanhaMarcaRepository}); a entidade existe para o esquema.
 */
#[ORM\Entity(repositoryClass: CampanhaMarcaRepository::class)]
#[ORM\Table(name: 'comercial_campanha_marca')]
#[ORM\Index(name: 'idx_comercial_campanha_marca_user', columns: ['user_id'])]
class CampanhaMarca
{
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $estrela = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $arquivada = false;

    #[ORM\Column(name: 'removida_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $removidaEm = null;

    #[ORM\Column(name: 'alterada_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradaEm;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: CampanhaDeEmail::class)]
        #[ORM\JoinColumn(name: 'campanha_id', nullable: false, onDelete: 'CASCADE')]
        private CampanhaDeEmail $campanha,
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private User $master,
    ) {
        $this->alteradaEm = new \DateTimeImmutable();
    }

    public function getCampanha(): CampanhaDeEmail
    {
        return $this->campanha;
    }

    public function getMaster(): User
    {
        return $this->master;
    }
}
