<?php

declare(strict_types=1);

namespace App\Comercial\Entity;

use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\Repository\ContatoComercialRepository;
use App\Entity\Auth\User;
use App\Shared\Attribute\SemIsolamentoDeTenant;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um contato da Base de contatos do Comercial (Painel do Master > Comercial). Global da plataforma: sem escritório.
 * O endereço é único e normalizado (minúsculas e sem espaços nas pontas; os pontos ficam, como no resto do Comercial).
 *
 * A situação (ativo ou descadastrado) NÃO é coluna: vem do descadastro da plataforma
 * ({@see \App\Shared\Email\Descadastro\DescadastroDeEmailInterface}), a única fonte da verdade. `ultimo_convite_em`
 * fica vazio até o envio de convites usar a base.
 *
 * NÃO é `Auditavel`: as ações do Master vão para o `audit_log` pelo caso de uso.
 */
#[ORM\Entity(repositoryClass: ContatoComercialRepository::class)]
#[SemIsolamentoDeTenant('Base de contatos de e-mail do Master, da plataforma: não pertence a nenhum escritório; só o Master lê e escreve')]
#[ORM\Table(name: 'comercial_contato')]
#[ORM\UniqueConstraint(name: 'uniq_comercial_contato_email', columns: ['email'])]
#[ORM\Index(name: 'idx_comercial_contato_criado_em', columns: ['criado_em'])]
#[ORM\Index(name: 'idx_comercial_contato_importado_por', columns: ['importado_por_id'])]
class ContatoComercial
{
    public const ORIGEM_IMPORTACAO = 'importacao';
    public const ORIGEM_MANUAL = 'manual';
    public const TAMANHO_DO_EMAIL = 180;
    public const TAMANHO_DO_DETALHE = 180;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $nome;

    #[ORM\Column(length: 20)]
    private string $origem;

    #[ORM\Column(name: 'origem_detalhe', length: 180, nullable: true)]
    private ?string $origemDetalhe;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'importado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $importadoPor;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(name: 'atualizado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadoEm;

    #[ORM\Column(name: 'ultimo_convite_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ultimoConviteEm = null;

    public function __construct(string $email, ?string $nome, string $origem, ?string $origemDetalhe, ?User $importadoPor, \DateTimeImmutable $agora)
    {
        $this->email = mb_strtolower(trim($email));
        $this->nome = self::nomeLimpo($nome);
        $this->origem = $origem;
        $this->origemDetalhe = $origemDetalhe !== null && trim($origemDetalhe) !== '' ? mb_substr(trim($origemDetalhe), 0, self::TAMANHO_DO_DETALHE) : null;
        $this->importadoPor = $importadoPor;
        $this->criadoEm = $agora;
        $this->atualizadoEm = $agora;
    }

    /** Nome sem os caracteres que o envio não aceita; vazio vira null. */
    public static function nomeLimpo(?string $nome): ?string
    {
        $limpo = DestinatarioDaCampanha::limparNome((string) $nome);

        return $limpo !== '' ? $limpo : null;
    }

    public function renomear(?string $nome, \DateTimeImmutable $agora): void
    {
        $this->nome = self::nomeLimpo($nome);
        $this->atualizadoEm = $agora;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getNome(): ?string
    {
        return $this->nome;
    }

    public function getImportadoPorId(): ?int
    {
        return $this->importadoPor?->getId();
    }

    public function getOrigem(): string
    {
        return $this->origem;
    }

    public function getOrigemDetalhe(): ?string
    {
        return $this->origemDetalhe;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): \DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    public function getUltimoConviteEm(): ?\DateTimeImmutable
    {
        return $this->ultimoConviteEm;
    }
}
