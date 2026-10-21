<?php

declare(strict_types=1);

namespace App\Comercial\Entity;

use App\Comercial\Repository\ModeloDeCampanhaRepository;
use App\Entity\Auth\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um modelo próprio do Master para campanhas (spec `comercial-e-emails.md` §8.2; no dc, o `localStorage
 * 'bj-com-modelos'`). Plataforma: sem escritório, só o Master lê e grava.
 *
 * NÃO é `Auditavel`: o AuditLogSubscriber gravaria até 500 caracteres do conteúdo no audit_log. A trilha é manual, no
 * repositório ({@see ModeloDeCampanhaRepository::salvar()}), com `changes` só `{nome, modo}` (R6).
 *
 * `conteudo`: o JSON dos campos do simples, o JSON dos blocos, ou o HTML já sanitizado (modo `html`).
 */
#[ORM\Entity(repositoryClass: ModeloDeCampanhaRepository::class)]
#[ORM\Table(name: 'comercial_modelo')]
class ModeloDeCampanha
{
    public const ICONE_PADRAO = 'bi-file-earmark';
    public const PADRAO_DO_ICONE = '/^bi-[a-z0-9-]{1,40}$/';
    public const TAMANHO_DO_NOME = 40;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $nome;

    #[ORM\Column(length: 50)]
    private string $icone;

    #[ORM\Column(length: 10)]
    private string $modo;

    #[ORM\Column(length: 255)]
    private string $assunto;

    #[ORM\Column(length: 120)]
    private string $selo;

    #[ORM\Column(length: 255)]
    private string $titulo;

    #[ORM\Column(type: 'text')]
    private string $conteudo;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'criado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $criadoPor;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(name: 'alterado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradoEm;

    public function __construct(string $nome, string $icone, string $modo, User $por, \DateTimeImmutable $agora)
    {
        $this->nome = self::nomeValido($nome);
        $this->icone = self::iconeValido($icone);
        $this->modo = $modo;
        $this->assunto = '';
        $this->selo = '';
        $this->titulo = '';
        $this->conteudo = '';
        $this->criadoPor = $por;
        $this->criadoEm = $agora;
        $this->alteradoEm = $agora;
    }

    /** O nome entre 1 e 40 caracteres (cortado, como o dc corta o do HTML importado em L.553). */
    public static function nomeValido(string $nome): string
    {
        $limpo = trim($nome);

        return mb_substr($limpo === '' ? 'Novo modelo' : $limpo, 0, self::TAMANHO_DO_NOME);
    }

    /** O ícone vai para uma classe na tela: só `bi-...`; fora do padrão vira o ícone de arquivo (§8.2). */
    public static function iconeValido(string $icone): string
    {
        return preg_match(self::PADRAO_DO_ICONE, $icone) === 1 ? $icone : self::ICONE_PADRAO;
    }

    public function preencher(string $assunto, string $selo, string $titulo, string $conteudo, \DateTimeImmutable $agora): void
    {
        $this->assunto = mb_substr($assunto, 0, 255);
        $this->selo = mb_substr($selo, 0, 120);
        $this->titulo = mb_substr($titulo, 0, 255);
        $this->conteudo = $conteudo;
        $this->alteradoEm = $agora;
    }

    public function renomear(string $nome): void
    {
        $this->nome = self::nomeValido($nome);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getIcone(): string
    {
        return $this->icone;
    }

    public function getModo(): string
    {
        return $this->modo;
    }

    public function getAssunto(): string
    {
        return $this->assunto;
    }

    public function getSelo(): string
    {
        return $this->selo;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function getConteudo(): string
    {
        return $this->conteudo;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
