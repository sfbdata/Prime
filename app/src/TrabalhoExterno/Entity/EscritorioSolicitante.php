<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\TrabalhoExterno\DTO\DadosDoEscritorioSolicitante;
use App\TrabalhoExterno\Repository\EscritorioSolicitanteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Escritório que UM usuário guardou na "Nova solicitação" (desenho 06 de 25/10, "Novo escritório" e
 * "Salvar edição"): nome, e-mail, telefone, UF e município de quem pede. Só preenche os campos de texto
 * da solicitação; nunca é um Tenant e nunca altera um.
 *
 * Não é `TenantAware`: pertence ao usuário (como a `PreferenciaDoUsuario`), some com ele (ON DELETE
 * CASCADE) e toda leitura recebe o usuário logado.
 */
#[ORM\Entity(repositoryClass: EscritorioSolicitanteRepository::class)]
#[ORM\Table(name: 'trabalho_externo_escritorio_solicitante')]
#[ORM\Index(name: 'idx_te_escritorio_solicitante_usuario', columns: ['usuario_id'])]
class EscritorioSolicitante
{
    /** Teto de escritórios guardados por usuário. */
    public const LIMITE_POR_USUARIO = 20;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $nome = '';

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telefone = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $uf = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $cidade = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        DadosDoEscritorioSolicitante $dados,
        \DateTimeImmutable $agora = new \DateTimeImmutable(),
    ) {
        $this->criadoEm = $agora;
        $this->preencher($dados, $agora);
    }

    public function preencher(DadosDoEscritorioSolicitante $dados, \DateTimeImmutable $agora = new \DateTimeImmutable()): void
    {
        $this->nome = $dados->nome;
        $this->email = $dados->email;
        $this->telefone = $dados->telefone;
        $this->uf = $dados->uf;
        $this->cidade = $dados->cidade;
        $this->atualizadoEm = $agora;
    }

    /**
     * A linha do select "Escritórios da sua conta" do modal. O `id` leva o prefixo `s` para nunca colidir
     * com o id numérico de um escritório (tenant) na mesma lista.
     *
     * @return array{id: string, salvo: true, nome: string, email: string, telefone: string, uf: string, cidade: string}
     */
    public function paraPainel(): array
    {
        return [
            'id' => 's' . $this->id,
            'salvo' => true,
            'nome' => $this->nome,
            'email' => (string) $this->email,
            'telefone' => (string) $this->telefone,
            'uf' => (string) $this->uf,
            'cidade' => (string) $this->cidade,
        ];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getTelefone(): ?string
    {
        return $this->telefone;
    }

    public function getUf(): ?string
    {
        return $this->uf;
    }

    public function getCidade(): ?string
    {
        return $this->cidade;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): \DateTimeImmutable
    {
        return $this->atualizadoEm;
    }
}
