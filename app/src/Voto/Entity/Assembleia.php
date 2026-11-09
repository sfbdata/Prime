<?php

declare(strict_types=1);

namespace App\Voto\Entity;

use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Assembleia de condomínio (spec docs/specs/voto-assembleia-condominio.md, seções 1 e 3): o EVENTO. Cada pauta é uma
 * votação com estado próprio (PautaDaAssembleia); a lista de unidades é da assembleia.
 *
 * Rascunho: pautas e unidades mudam. Publicada: as pautas travam, a mesa abre e encerra cada uma, e as unidades só
 * mudam enquanto nenhuma pauta saiu de "aguardando" ("a lista bloqueia na abertura").
 *
 * É `Auditavel` (quem criou, editou e publicou fica no audit_log). As tabelas do voto em si (opção e participação) não
 * são: o sigilo vem antes da trilha (seção 2).
 *
 * Mora no schema `voto`; a FK de tenant com ON DELETE CASCADE é a mesma exceção da `voto.liberacao`, e a purga do
 * escritório apaga a linha pela ORDEM_DELECAO.
 */
#[ORM\Entity(repositoryClass: AssembleiaRepository::class)]
#[ORM\Table(name: 'assembleia', schema: 'voto')]
class Assembleia implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 12, enumType: EstadoDaAssembleia::class)]
    private EstadoDaAssembleia $estado = EstadoDaAssembleia::Rascunho;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publicadaEm = null;

    /** @var Collection<int, PautaDaAssembleia> */
    #[ORM\OneToMany(mappedBy: 'assembleia', targetEntity: PautaDaAssembleia::class, cascade: ['persist'])]
    #[ORM\OrderBy(['ordem' => 'ASC', 'id' => 'ASC'])]
    private Collection $pautas;

    /** @var Collection<int, UnidadeDaAssembleia> */
    #[ORM\OneToMany(mappedBy: 'assembleia', targetEntity: UnidadeDaAssembleia::class, cascade: ['persist'])]
    #[ORM\OrderBy(['bloco' => 'ASC', 'identificacao' => 'ASC', 'id' => 'ASC'])]
    private Collection $unidades;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\Column(length: 160)]
        private string $titulo,
        #[ORM\Column(length: 10, enumType: PesoDoVoto::class)]
        private PesoDoVoto $peso,
        #[ORM\Column(length: 180)]
        private string $criadaPorNome,
    ) {
        $this->titulo = self::tituloValido($titulo);
        $this->criadaEm = new \DateTimeImmutable();
        $this->pautas = new ArrayCollection();
        $this->unidades = new ArrayCollection();
    }

    /** Título e peso do voto só mudam em rascunho. */
    public function editar(string $titulo, PesoDoVoto $peso): void
    {
        $this->exigirPautasAlteraveis();
        $this->titulo = self::tituloValido($titulo);
        $this->peso = $peso;
    }

    /**
     * Rascunho vira publicada e as pautas travam. Exige ao menos uma pauta, cada pauta com duas opções de voto (a
     * abstenção não conta) e ao menos uma unidade apta.
     */
    public function publicar(?\DateTimeImmutable $agora = null): void
    {
        if (EstadoDaAssembleia::Rascunho !== $this->estado) {
            throw new OperacaoInvalidaNaAssembleiaException('A assembleia já foi publicada.');
        }
        $this->exigirPautasCompletas();
        if (!$this->temUnidadeApta()) {
            throw new OperacaoInvalidaNaAssembleiaException('Inclua ao menos uma unidade apta a votar.');
        }
        $this->estado = EstadoDaAssembleia::Publicada;
        $this->publicadaEm = $agora ?? new \DateTimeImmutable();
    }

    public function podeAlterarPautas(): bool
    {
        return EstadoDaAssembleia::Rascunho === $this->estado;
    }

    /** A lista de unidades muda até a primeira pauta ser aberta. */
    public function podeAlterarUnidades(): bool
    {
        foreach ($this->pautas as $pauta) {
            if (EstadoDaPauta::Aguardando !== $pauta->getEstado()) {
                return false;
            }
        }

        return true;
    }

    public function exigirPautasAlteraveis(): void
    {
        if (!$this->podeAlterarPautas()) {
            throw new OperacaoInvalidaNaAssembleiaException('As pautas só mudam enquanto a assembleia está em rascunho.');
        }
    }

    public function exigirUnidadesAlteraveis(): void
    {
        if (!$this->podeAlterarUnidades()) {
            throw new OperacaoInvalidaNaAssembleiaException('A lista fica bloqueada depois da abertura da votação.');
        }
    }

    /** @internal Chamado só pelo construtor da PautaDaAssembleia, para a coleção em memória acompanhar o banco. */
    public function registrarPauta(PautaDaAssembleia $pauta): void
    {
        if (!$this->pautas->contains($pauta)) {
            $this->pautas->add($pauta);
        }
    }

    /** @internal Chamado só pelo construtor da UnidadeDaAssembleia, para a coleção em memória acompanhar o banco. */
    public function registrarUnidade(UnidadeDaAssembleia $unidade): void
    {
        if (!$this->unidades->contains($unidade)) {
            $this->unidades->add($unidade);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function getPeso(): PesoDoVoto
    {
        return $this->peso;
    }

    public function getEstado(): EstadoDaAssembleia
    {
        return $this->estado;
    }

    public function getCriadaPorNome(): string
    {
        return $this->criadaPorNome;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getPublicadaEm(): ?\DateTimeImmutable
    {
        return $this->publicadaEm;
    }

    /** @return Collection<int, PautaDaAssembleia> pela ordem */
    public function getPautas(): Collection
    {
        return $this->pautas;
    }

    /** @return Collection<int, UnidadeDaAssembleia> por bloco e identificação */
    public function getUnidades(): Collection
    {
        return $this->unidades;
    }

    private function exigirPautasCompletas(): void
    {
        if ($this->pautas->isEmpty()) {
            throw new OperacaoInvalidaNaAssembleiaException('Inclua ao menos uma pauta.');
        }
        foreach ($this->pautas as $pauta) {
            if (\count($pauta->getOpcoesDeVoto()) < 2) {
                throw new OperacaoInvalidaNaAssembleiaException(sprintf('A pauta "%s" precisa de ao menos duas opções.', $pauta->getTitulo()));
            }
        }
    }

    private function temUnidadeApta(): bool
    {
        foreach ($this->unidades as $unidade) {
            if ($unidade->isApta()) {
                return true;
            }
        }

        return false;
    }

    private static function tituloValido(string $titulo): string
    {
        $titulo = trim($titulo);
        if ('' === $titulo) {
            throw new OperacaoInvalidaNaAssembleiaException('Informe o título da assembleia.');
        }

        return mb_substr($titulo, 0, 160);
    }
}
