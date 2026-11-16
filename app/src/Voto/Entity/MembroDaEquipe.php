<?php

declare(strict_types=1);

namespace App\Voto\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use App\Voto\Enum\PapelNaEquipe;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\MembroDaEquipeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma linha da equipe própria de uma assembleia (spec docs/specs/voto-equipe-e-master-1611.md, seção 1.1). A equipe é
 * do evento, nunca da pauta. Assembleia sem nenhuma linha usa a equipe padrão (vale o módulo `voto`, como sempre).
 *
 * Só dá acesso a linha com `user` (usuário com vínculo ativo no escritório): nome e e-mail sem conta ficam como cargo
 * ("A designar" ou pessoa sem conta). O papel é TETO, nunca permissão (ver `AcessoAAssembleia`). Uma pessoa tem um
 * único papel por assembleia (UNIQUE assembleia + user; o NULL não colide).
 *
 * É `Auditavel`: quem foi designado, alterado e removido fica no audit_log do escritório. O tenant vem da assembleia.
 */
#[ORM\Entity(repositoryClass: MembroDaEquipeRepository::class)]
#[ORM\Table(name: 'membro_da_equipe', schema: 'voto')]
#[ORM\UniqueConstraint(name: 'uniq_voto_membro_da_equipe_pessoa', columns: ['assembleia_id', 'user_id'])]
class MembroDaEquipe implements TenantAware, Auditavel
{
    /** O nome das linhas que "Aplicar cargos da categoria" cria: o cargo existe, a pessoa ainda não foi indicada. */
    public const NOME_A_DESIGNAR = 'A designar';

    private const MAXIMO_DO_NOME = 120;
    private const MAXIMO_DO_EMAIL = 180;
    private const MAXIMO_DO_CARGO = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 10, enumType: PapelNaEquipe::class)]
    private PapelNaEquipe $papel;

    #[ORM\Column(length: self::MAXIMO_DO_CARGO, options: ['default' => ''])]
    private string $cargo;

    #[ORM\Column(length: self::MAXIMO_DO_NOME)]
    private string $nome;

    #[ORM\Column(length: self::MAXIMO_DO_EMAIL, nullable: true)]
    private ?string $email;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Assembleia::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Assembleia $assembleia,
        PapelNaEquipe $papel,
        string $nome,
        ?string $email = null,
        string $cargo = '',
        ?User $user = null,
    ) {
        $this->tenant = $assembleia->getTenant();
        $this->preencher($papel, $nome, $email, $cargo, $user);
    }

    public function alterar(PapelNaEquipe $papel, string $nome, ?string $email, string $cargo, ?User $user): void
    {
        $this->preencher($papel, $nome, $email, $cargo, $user);
    }

    private function preencher(PapelNaEquipe $papel, string $nome, ?string $email, string $cargo, ?User $user): void
    {
        $nome = trim($nome);
        $email = null === $email ? '' : trim($email);
        if ('' === $nome) {
            throw new OperacaoInvalidaNaAssembleiaException('Informe o nome da pessoa.');
        }
        if (mb_strlen($nome) > self::MAXIMO_DO_NOME) {
            throw new OperacaoInvalidaNaAssembleiaException('O nome tem no máximo ' . self::MAXIMO_DO_NOME . ' caracteres.');
        }
        if (mb_strlen($email) > self::MAXIMO_DO_EMAIL || ('' !== $email && false === filter_var($email, \FILTER_VALIDATE_EMAIL))) {
            throw new OperacaoInvalidaNaAssembleiaException('Informe um e-mail válido.');
        }
        $this->papel = $papel;
        $this->nome = $nome;
        $this->email = '' === $email ? null : $email;
        $this->cargo = mb_substr(trim($cargo), 0, self::MAXIMO_DO_CARGO);
        $this->user = $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getAssembleia(): Assembleia
    {
        return $this->assembleia;
    }

    public function getPapel(): PapelNaEquipe
    {
        return $this->papel;
    }

    public function getCargo(): string
    {
        return $this->cargo;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function aDesignar(): bool
    {
        return null === $this->user && self::NOME_A_DESIGNAR === $this->nome;
    }

    /** O rótulo da linha: o cargo, ou o papel quando o cargo está em branco (desenho: `RP`). */
    public function rotulo(): string
    {
        return '' !== $this->cargo ? $this->cargo : $this->papel->rotulo();
    }
}
