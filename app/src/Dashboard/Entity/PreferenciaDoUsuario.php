<?php

declare(strict_types=1);

namespace App\Dashboard\Entity;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um ajuste pessoal de tela, de UM usuário, em UM escritório (menu ⋮ da tabela Desempenho,
 * desenho "01 - Dashboard 1.2.2", "Personalização por usuário"). O ajuste de um não muda o de
 * ninguém, e o mesmo usuário em outro escritório começa do padrão.
 *
 * Mora no domínio Dashboard porque só ele usa (regra do `Shared/CLAUDE.md`: código de um
 * domínio só vai no próprio domínio). A tabela tem nome genérico e a chave é prefixada
 * (`dashboard.*`) para que outra tela possa usar a mesma estrutura depois — aí sim ela sobe
 * para o Shared.
 *
 * O valor é JSON, mas NUNCA JSON arbitrário: só entra o que o
 * {@see \App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard} aceitou (lista fechada
 * de chaves e de valores).
 *
 * Anatomia:
 *   - UNIQUE (tenant_id, user_id, chave): uma linha por ajuste — é o árbitro do
 *     `INSERT … ON CONFLICT` da gravação idempotente;
 *   - índice (user_id): cobre a FK de usuário (o unique começa pelo tenant);
 *   - `user_id` CASCADE: preferência pessoal some com o usuário;
 *   - `tenant_id` NOT NULL sem ação: entidade TenantAware, apagada pela purga do escritório.
 *
 * Sem setters de valor: a escrita é pelo repositório (SQL com ON CONFLICT), não pelo ORM.
 */
#[ORM\Entity(repositoryClass: PreferenciaDoUsuarioRepository::class)]
#[ORM\Table(name: 'preferencia_usuario')]
#[ORM\Index(name: 'idx_preferencia_usuario_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_preferencia_usuario_tenant_user_chave', columns: ['tenant_id', 'user_id', 'chave'])]
class PreferenciaDoUsuario implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'atualizado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(name: 'chave', type: 'string', length: 80)]
        private string $chave,
        #[ORM\Column(name: 'valor', type: 'json')]
        private mixed $valor,
    ) {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getChave(): string
    {
        return $this->chave;
    }

    public function getValor(): mixed
    {
        return $this->valor;
    }

    public function getAtualizadoEm(): \DateTimeImmutable
    {
        return $this->atualizadoEm;
    }
}
