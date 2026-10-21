<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaComentarioDestaqueRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * O destaque de cor de um comentário da pasta (spec pasta-destacar-comentario.md; dc02 L4593-4607). Um por pasta e
 * aba (o UNIQUE é a regra "o último prevalece"), compartilhado com todos os que veem a pasta. Uma FK por tipo de
 * comentário, só uma preenchida: excluir o comentário apaga o destaque junto.
 */
#[ORM\Entity(repositoryClass: PastaComentarioDestaqueRepository::class)]
#[ORM\Table(name: 'pasta_comentario_destaque')]
#[ORM\UniqueConstraint(name: 'uniq_pasta_comentario_destaque_aba', columns: ['pasta_id', 'aba'])]
#[ORM\Index(name: 'idx_pasta_comentario_destaque_tenant', columns: ['tenant_id'])]
class PastaComentarioDestaque implements TenantAware, Auditavel
{
    /** As 15 cores do dc (`DEST_CORES`, L4590), na ordem da paleta: hex => nome. */
    public const CORES = [
        '#ffe94d' => 'Amarelo', '#86e08a' => 'Verde claro', '#7fdcef' => 'Ciano', '#f59bcb' => 'Rosa', '#6eaef2' => 'Azul',
        '#e5534b' => 'Vermelho', '#1f3a6e' => 'Azul-marinho', '#0f7c7a' => 'Verde-petróleo', '#2e7d32' => 'Verde',
        '#6a3fa0' => 'Roxo', '#8e2430' => 'Vinho', '#8a8526' => 'Oliva', '#8a959c' => 'Cinza', '#cfd6db' => 'Cinza claro',
        '#1b1f22' => 'Preto',
    ];

    public const ABA_DADOS = 'dados';
    public const ABA_DETALHES = 'detalhes';
    public const ABA_FINANCEIRO = 'financeiro';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Pasta::class)]
    #[ORM\JoinColumn(name: 'pasta_id', nullable: false, onDelete: 'CASCADE')]
    private Pasta $pasta;

    #[ORM\Column(length: 20)]
    private string $aba;

    #[ORM\ManyToOne(targetEntity: PastaMensagem::class)]
    #[ORM\JoinColumn(name: 'mensagem_id', nullable: true, onDelete: 'CASCADE')]
    private ?PastaMensagem $mensagem = null;

    #[ORM\ManyToOne(targetEntity: PastaObservacaoDetalhes::class)]
    #[ORM\JoinColumn(name: 'observacao_detalhes_id', nullable: true, onDelete: 'CASCADE')]
    private ?PastaObservacaoDetalhes $observacaoDetalhes = null;

    #[ORM\ManyToOne(targetEntity: PastaObservacaoFinanceira::class)]
    #[ORM\JoinColumn(name: 'observacao_financeira_id', nullable: true, onDelete: 'CASCADE')]
    private ?PastaObservacaoFinanceira $observacaoFinanceira = null;

    #[ORM\Column(length: 7)]
    private string $cor;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'destacado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $destacadoPor;

    #[ORM\Column(name: 'destacado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $destacadoEm;

    public function __construct(
        Pasta $pasta,
        PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira $comentario,
        string $cor,
        User $por,
        \DateTimeImmutable $em,
    ) {
        $tenant = $pasta->getTenant() ?? throw new \InvalidArgumentException('Pasta sem escritório.');
        $this->tenant = $tenant;
        $this->pasta = $pasta;
        $this->aba = self::abaDe($comentario);
        $this->apontarPara($comentario, $cor, $por, $em);
    }

    /**
     * O último destaque da aba prevalece: a mesma linha passa a apontar para o novo comentário (ou só troca a cor).
     * Apagar e inserir no mesmo flush quebraria o UNIQUE, porque o Doctrine insere antes de apagar.
     */
    public function apontarPara(
        PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira $comentario,
        string $cor,
        User $por,
        \DateTimeImmutable $em,
    ): void {
        if (self::abaDe($comentario) !== $this->aba) {
            throw new \InvalidArgumentException('Comentário de outra aba.');
        }
        $this->mensagem = $comentario instanceof PastaMensagem ? $comentario : null;
        $this->observacaoDetalhes = $comentario instanceof PastaObservacaoDetalhes ? $comentario : null;
        $this->observacaoFinanceira = $comentario instanceof PastaObservacaoFinanceira ? $comentario : null;
        $this->cor = self::corValida($cor);
        $this->destacadoPor = $por;
        $this->destacadoEm = $em;
    }

    public function ehDo(PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira $comentario): bool
    {
        return $this->comentario() === $comentario
            || (self::abaDe($comentario) === $this->aba && $comentario->getId() !== null && $comentario->getId() === $this->comentario()->getId());
    }

    public static function abaDe(PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira $comentario): string
    {
        return match (true) {
            $comentario instanceof PastaMensagem => self::ABA_DADOS,
            $comentario instanceof PastaObservacaoDetalhes => self::ABA_DETALHES,
            default => self::ABA_FINANCEIRO,
        };
    }

    /** Hex da paleta, em minúsculas; fora dela, lança. */
    public static function corValida(string $cor): string
    {
        $cor = mb_strtolower(trim($cor));
        if (!array_key_exists($cor, self::CORES)) {
            throw new \InvalidArgumentException('Cor de destaque inválida.');
        }

        return $cor;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getAba(): string
    {
        return $this->aba;
    }

    public function getCor(): string
    {
        return $this->cor;
    }

    public function comentario(): PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira
    {
        return $this->mensagem ?? $this->observacaoDetalhes ?? $this->observacaoFinanceira
            ?? throw new \LogicException('Destaque sem comentário.');
    }

    public function comentarioId(): int
    {
        return (int) $this->comentario()->getId();
    }
}
