<?php
declare(strict_types=1);
namespace App\Profile\Entity;

use App\Entity\Auth\User;
use App\Profile\Enum\Atuacao;
use App\Profile\Enum\Genero;
use App\Profile\Enum\Idioma;
use App\Profile\Repository\UserProfileRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserProfileRepository::class)]
#[ORM\Table(name: 'user_profiles')]
#[ORM\HasLifecycleCallbacks]
class UserProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fotoUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomeCompleto = null;

    #[ORM\Column(length: 14, nullable: true)]
    private ?string $cpf = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $dataNascimento = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $ctps = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $serie = null;

    /** Telefone / WhatsApp, só dígitos (desenho 07, VS 18.10.26). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telefone = null;

    /** Chave PIX para receber recompensas (desenho 07; spec meu-perfil-pix-recompensas), como digitada; só a pessoa e o Master leem. */
    #[ORM\Column(length: 140, nullable: true)]
    private ?string $chavePixRecompensa = null;

    /** Gênero declarado (desenho 05 de 21/10); nulo = não preenchido. Sem backfill. */
    #[ORM\Column(length: 20, nullable: true, enumType: Genero::class)]
    private ?Genero $genero = null;

    /** Atuação declarada (desenho 05 de 21/10, filtro Cargo); nulo = não preenchida. Sem backfill. */
    #[ORM\Column(length: 20, nullable: true, enumType: Atuacao::class)]
    private ?Atuacao $atuacao = null;

    /** Idioma do sistema escolhido pela pessoa (spec idioma-do-sistema); nulo = o padrão, português. */
    #[ORM\Column(length: 10, nullable: true, enumType: Idioma::class)]
    private ?Idioma $idioma = null;

    /** Última vez em que a pessoa confirmou, na revisão mensal, que o cadastro está certo. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $revisadoEm = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    public function __construct(
        #[ORM\OneToOne(inversedBy: 'profile')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
    ) {
        $this->criadoEm = new \DateTimeImmutable();
    }

    /**
     * O perfil de uma conta que está nascendo (cadastro, convites, Trabalho Externo), já com o gênero informado
     * (spec genero-no-cadastro). Fica ligado ao User, que o persiste em cascata.
     */
    public static function daContaNova(User $user, Genero $genero): self
    {
        $perfil = new self($user);
        $perfil->setGenero($genero);
        $user->setProfile($perfil);

        return $perfil;
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getFotoUrl(): ?string
    {
        return $this->fotoUrl;
    }

    public function setFotoUrl(?string $fotoUrl): static
    {
        $this->fotoUrl = $fotoUrl;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getNomeCompleto(): ?string
    {
        return $this->nomeCompleto;
    }

    public function setNomeCompleto(?string $nomeCompleto): static
    {
        $this->nomeCompleto = $nomeCompleto;

        return $this;
    }

    public function getCpf(): ?string
    {
        return $this->cpf;
    }

    public function setCpf(?string $cpf): static
    {
        $this->cpf = $cpf;

        return $this;
    }

    public function getDataNascimento(): ?\DateTimeInterface
    {
        return $this->dataNascimento;
    }

    public function setDataNascimento(?\DateTimeInterface $dataNascimento): static
    {
        $this->dataNascimento = $dataNascimento;

        return $this;
    }

    public function getCtps(): ?string
    {
        return $this->ctps;
    }

    public function setCtps(?string $ctps): static
    {
        $this->ctps = $ctps;

        return $this;
    }

    public function getSerie(): ?string
    {
        return $this->serie;
    }

    public function setSerie(?string $serie): static
    {
        $this->serie = $serie;

        return $this;
    }

    public function getGenero(): ?Genero
    {
        return $this->genero;
    }

    public function setGenero(?Genero $genero): static
    {
        $this->genero = $genero;

        return $this;
    }

    /** O idioma em uso: o escolhido, se ainda estiver liberado; senão o padrão. */
    public function getIdioma(): Idioma
    {
        return $this->idioma !== null && $this->idioma->ativo() ? $this->idioma : Idioma::PADRAO;
    }

    public function setIdioma(Idioma $idioma): static
    {
        $this->idioma = $idioma;

        return $this;
    }

    public function getAtuacao(): ?Atuacao
    {
        return $this->atuacao;
    }

    public function setAtuacao(?Atuacao $atuacao): static
    {
        $this->atuacao = $atuacao;

        return $this;
    }

    public function getTelefone(): ?string
    {
        return $this->telefone;
    }

    /** Guarda só os dígitos; vazio vira null. */
    public function setTelefone(?string $telefone): static
    {
        $digitos        = preg_replace('/\D/', '', (string) $telefone) ?? '';
        $this->telefone = $digitos === '' ? null : $digitos;

        return $this;
    }

    public function getChavePixRecompensa(): ?string
    {
        return $this->chavePixRecompensa;
    }

    /** Guarda como digitada, aparada; vazio vira null. */
    public function setChavePixRecompensa(?string $chave): static
    {
        $chave                    = trim((string) $chave);
        $this->chavePixRecompensa = $chave === '' ? null : $chave;

        return $this;
    }

    public function getRevisadoEm(): ?\DateTimeImmutable
    {
        return $this->revisadoEm;
    }

    public function confirmarRevisao(\DateTimeImmutable $quando): void
    {
        $this->revisadoEm = $quando;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }
}
