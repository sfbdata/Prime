<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\EstadoCivil;
use App\Cobranca\Repository\PessoaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pessoa (SPEC §4/§7): cadastro reutilizável de gente que participa do domínio de cobranças
 * (proprietário, inquilino, devedor, fiador, outro). Não vira Cliente por participar de uma
 * cobrança (invariável 3). CPF/CNPJ são opcionais; quando informados, ajudam a evitar
 * duplicidades APENAS dentro do mesmo tenant (invariável 24). Pessoa, vínculo e pessoa cobrada
 * atual são conceitos diferentes (invariável 23).
 *
 * Qualificação (spec de qualificação §3/§4/§6): campos únicos (dataNascimento, estadoCivil,
 * profissao, rg, orgaoEmissorRg) + três LISTAS (enderecos/telefones/emails) com um item `atual`
 * cada — nada é apagado ao atualizar, só adicionado. `email`/`telefone` continuam existindo como
 * colunas-sombra (compat de 1 release, SPEC §5.4/§6), SEMPRE sincronizadas com o item atual pelos
 * UseCases Adicionar/MarcarAtual (via sincronizarEmailSombra()/sincronizarTelefoneSombra()) e
 * pelo bridge setEmail()/setTelefone(): getEmail()/getTelefone() passam a PREFERIR a sombra
 * (leitura escalar, sem iterar a lista — evita N+1) e só derivam do item atual da lista quando a
 * sombra é null (dado legado pré-transição).
 */
#[ORM\Entity(repositoryClass: PessoaRepository::class)]
#[ORM\Table(name: 'cobranca_pessoa')]
#[ORM\Index(name: 'idx_cobranca_pessoa_tenant_cpf', columns: ['tenant_id', 'cpf'])]
#[ORM\Index(name: 'idx_cobranca_pessoa_tenant_cnpj', columns: ['tenant_id', 'cnpj'])]
#[ORM\HasLifecycleCallbacks]
class Pessoa implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255)]
    private string $nome = '';

    /** CPF em dígitos ou formatado; opcional (SPEC §7). */
    #[ORM\Column(length: 14, nullable: true)]
    private ?string $cpf = null;

    /** CNPJ em dígitos ou formatado; opcional (SPEC §7). */
    #[ORM\Column(length: 18, nullable: true)]
    private ?string $cnpj = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telefone = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observacao = null;

    /** Qualificação (spec §3): data de nascimento, opcional. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataNascimento = null;

    /** Qualificação (spec §3): campo ÚNICO — não é lista, ao contrário de endereço/telefone/e-mail. */
    #[ORM\Column(enumType: EstadoCivil::class, nullable: true)]
    private ?EstadoCivil $estadoCivil = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $profissao = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $rg = null;

    /** Ex.: "SSP/CE". */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $orgaoEmissorRg = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $criadoPor = null;

    /**
     * Lista de endereços (spec §4), ordem de linha do tempo `criadoEm ASC`.
     *
     * @var Collection<int, PessoaEndereco>
     */
    #[ORM\OneToMany(targetEntity: PessoaEndereco::class, mappedBy: 'pessoa', cascade: ['persist'])]
    #[ORM\OrderBy(['criadoEm' => 'ASC'])]
    private Collection $enderecos;

    /**
     * Lista de telefones (spec §4), ordem de linha do tempo `criadoEm ASC`.
     *
     * @var Collection<int, PessoaTelefone>
     */
    #[ORM\OneToMany(targetEntity: PessoaTelefone::class, mappedBy: 'pessoa', cascade: ['persist'])]
    #[ORM\OrderBy(['criadoEm' => 'ASC'])]
    private Collection $telefones;

    /**
     * Lista de e-mails (spec §4), ordem de linha do tempo `criadoEm ASC`.
     *
     * @var Collection<int, PessoaEmail>
     */
    #[ORM\OneToMany(targetEntity: PessoaEmail::class, mappedBy: 'pessoa', cascade: ['persist'])]
    #[ORM\OrderBy(['criadoEm' => 'ASC'])]
    private Collection $emails;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
        $this->enderecos = new ArrayCollection();
        $this->telefones = new ArrayCollection();
        $this->emails = new ArrayCollection();
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

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function setNome(string $nome): self
    {
        $this->nome = $nome;

        return $this;
    }

    public function getCpf(): ?string
    {
        return $this->cpf;
    }

    public function setCpf(?string $cpf): self
    {
        $this->cpf = $cpf;

        return $this;
    }

    public function getCnpj(): ?string
    {
        return $this->cnpj;
    }

    public function setCnpj(?string $cnpj): self
    {
        $this->cnpj = $cnpj;

        return $this;
    }

    /**
     * SPEC §5.4/§6: prefere a coluna-sombra (mantida sincronizada com o item atual pelos
     * UseCases Adicionar/MarcarAtual e pelo bridge setEmail()) — leitura escalar, sem iterar a
     * coleção `emails` (evita N+1 nos read-paths quentes). Só deriva da lista quando a sombra
     * é null (dado legado pré-transição que ainda não passou por um Adicionar/MarcarAtual).
     */
    public function getEmail(): ?string
    {
        return $this->email ?? $this->emailAtual()?->getEmail();
    }

    /**
     * Compat (SPEC §6): ponte para código legado (importação, telas antigas). Mantém a
     * coluna-sombra sincronizada e cria/atualiza o item `atual` da lista de e-mails — sem
     * tenant atribuído ainda (pessoa recém-instanciada), só a sombra é gravada; a lista é
     * populada quando um Adicionar/MarcarAtual explícito ocorrer.
     */
    public function setEmail(?string $email): self
    {
        $this->email = $email;

        if ($email === null) {
            return $this;
        }

        $atual = $this->emailAtual();
        if ($atual !== null) {
            $atual->setEmail($email);

            return $this;
        }

        if ($this->tenant === null) {
            return $this;
        }

        $novo = new PessoaEmail();
        $novo->setTenant($this->tenant);
        $novo->setEmail($email);
        $novo->setAtual(true);
        $this->adicionarEmail($novo);

        return $this;
    }

    /**
     * SPEC §5.4/§6: prefere a coluna-sombra (mantida sincronizada com o item atual pelos
     * UseCases Adicionar/MarcarAtual e pelo bridge setTelefone()) — leitura escalar, sem iterar
     * a coleção `telefones` (evita N+1 nos read-paths quentes). Só deriva da lista quando a
     * sombra é null (dado legado pré-transição que ainda não passou por um Adicionar/MarcarAtual).
     */
    public function getTelefone(): ?string
    {
        return $this->telefone ?? $this->telefoneAtual()?->getNumero();
    }

    /**
     * Compat (SPEC §6): ponte para código legado (importação, telas antigas). Mantém a
     * coluna-sombra sincronizada e cria/atualiza o item `atual` da lista de telefones — sem
     * tenant atribuído ainda (pessoa recém-instanciada), só a sombra é gravada; a lista é
     * populada quando um Adicionar/MarcarAtual explícito ocorrer.
     */
    public function setTelefone(?string $telefone): self
    {
        $this->telefone = $telefone;

        if ($telefone === null) {
            return $this;
        }

        $atual = $this->telefoneAtual();
        if ($atual !== null) {
            $atual->setNumero($telefone);

            return $this;
        }

        if ($this->tenant === null) {
            return $this;
        }

        $novo = new PessoaTelefone();
        $novo->setTenant($this->tenant);
        $novo->setNumero($telefone);
        $novo->setAtual(true);
        $this->adicionarTelefone($novo);

        return $this;
    }

    /**
     * Baixo nível (SPEC §5.4): grava SOMENTE a coluna-sombra, sem tocar a lista de e-mails —
     * usada pelos UseCases Adicionar/MarcarAtual para manter a sombra "sincronizada com o item
     * atual" depois de mexerem na lista diretamente (evita a dupla-escrita do bridge setEmail(),
     * que criaria/atualizaria um item por conta própria).
     */
    public function sincronizarEmailSombra(?string $email): void
    {
        $this->email = $email;
    }

    /**
     * Baixo nível (SPEC §5.4): grava SOMENTE a coluna-sombra, sem tocar a lista de telefones —
     * usada pelos UseCases Adicionar/MarcarAtual para manter a sombra "sincronizada com o item
     * atual" depois de mexerem na lista diretamente (evita a dupla-escrita do bridge
     * setTelefone(), que criaria/atualizaria um item por conta própria).
     */
    public function sincronizarTelefoneSombra(?string $telefone): void
    {
        $this->telefone = $telefone;
    }

    /**
     * Determinístico: se por algum motivo houver mais de um `atual = true` na coleção (não
     * deveria, mas MarcarEmailAtualUseCase se auto-corrige), o último encontrado vence — a
     * coleção é ordenada `criadoEm ASC` (linha do tempo), então o último é o mais recente.
     */
    private function emailAtual(): ?PessoaEmail
    {
        $atual = null;

        foreach ($this->emails as $item) {
            if ($item->isAtual()) {
                $atual = $item;
            }
        }

        return $atual;
    }

    /**
     * Determinístico: se por algum motivo houver mais de um `atual = true` na coleção (não
     * deveria, mas MarcarTelefoneAtualUseCase se auto-corrige), o último encontrado vence — a
     * coleção é ordenada `criadoEm ASC` (linha do tempo), então o último é o mais recente.
     */
    private function telefoneAtual(): ?PessoaTelefone
    {
        $atual = null;

        foreach ($this->telefones as $item) {
            if ($item->isAtual()) {
                $atual = $item;
            }
        }

        return $atual;
    }

    public function getObservacao(): ?string
    {
        return $this->observacao;
    }

    public function setObservacao(?string $observacao): self
    {
        $this->observacao = $observacao;

        return $this;
    }

    public function getDataNascimento(): ?\DateTimeImmutable
    {
        return $this->dataNascimento;
    }

    public function setDataNascimento(?\DateTimeImmutable $dataNascimento): self
    {
        $this->dataNascimento = $dataNascimento;

        return $this;
    }

    public function getEstadoCivil(): ?EstadoCivil
    {
        return $this->estadoCivil;
    }

    public function setEstadoCivil(?EstadoCivil $estadoCivil): self
    {
        $this->estadoCivil = $estadoCivil;

        return $this;
    }

    public function getProfissao(): ?string
    {
        return $this->profissao;
    }

    public function setProfissao(?string $profissao): self
    {
        $this->profissao = $profissao;

        return $this;
    }

    public function getRg(): ?string
    {
        return $this->rg;
    }

    public function setRg(?string $rg): self
    {
        $this->rg = $rg;

        return $this;
    }

    public function getOrgaoEmissorRg(): ?string
    {
        return $this->orgaoEmissorRg;
    }

    public function setOrgaoEmissorRg(?string $orgaoEmissorRg): self
    {
        $this->orgaoEmissorRg = $orgaoEmissorRg;

        return $this;
    }

    /** @return Collection<int, PessoaEndereco> */
    public function getEnderecos(): Collection
    {
        return $this->enderecos;
    }

    public function adicionarEndereco(PessoaEndereco $endereco): self
    {
        if (!$this->enderecos->contains($endereco)) {
            $this->enderecos->add($endereco);
            $endereco->setPessoa($this);
        }

        return $this;
    }

    /** @return Collection<int, PessoaTelefone> */
    public function getTelefones(): Collection
    {
        return $this->telefones;
    }

    public function adicionarTelefone(PessoaTelefone $telefone): self
    {
        if (!$this->telefones->contains($telefone)) {
            $this->telefones->add($telefone);
            $telefone->setPessoa($this);
        }

        return $this;
    }

    /** @return Collection<int, PessoaEmail> */
    public function getEmails(): Collection
    {
        return $this->emails;
    }

    public function adicionarEmail(PessoaEmail $email): self
    {
        if (!$this->emails->contains($email)) {
            $this->emails->add($email);
            $email->setPessoa($this);
        }

        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function setCriadoPor(?User $criadoPor): self
    {
        $this->criadoPor = $criadoPor;

        return $this;
    }
}
