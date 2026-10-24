<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\Enum\NaturezaDoDespacho;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\TipoDeDespacho;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma linha do DESPACHO no Resumo da demanda (desenho 06 de 24/10, l.512 a 530 e 1391 a 1394): o despacho que
 * a Central publica (natureza, prazo, texto e anexos) ou a resposta de uma parte. Só se acrescenta: nada edita
 * nem apaga um despacho publicado.
 *
 * `codigo` é o `id: Date.now()` do protótipo, o instante da publicação em milissegundos, gravado uma vez: a
 * assinatura eletrônica mostra os 8 últimos dígitos ("Cód. 12345678"), e ele não muda ao reler.
 * `cidade` é o lugar da assinatura ("{Cidade}, 24 de outubro de 2026."), a cidade de execução no momento.
 * Os anexos são `DocumentoDemanda` com este despacho (a mesma guarda e a mesma purga dos documentos).
 */
#[ORM\Entity]
#[ORM\Table(name: 'trabalho_externo_despacho')]
class DespachoDemanda
{
    public const MAX_TEXTO = 5000;
    public const MAX_RESPOSTA = 3000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint')]
    private string|int $codigo;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $autor;

    #[ORM\Column(length: 255)]
    private string $nomeAutor;

    #[ORM\Column(enumType: Perfil::class, length: 20)]
    private Perfil $perfilAutor;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $em;

    /** @var Collection<int, DocumentoDemanda> */
    #[ORM\OneToMany(targetEntity: DocumentoDemanda::class, mappedBy: 'despacho')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $documentos;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: DemandaExterna::class, inversedBy: 'despachos')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DemandaExterna $demanda,
        #[ORM\Column(enumType: TipoDeDespacho::class, length: 20)]
        private TipoDeDespacho $tipo,
        #[ORM\Column(enumType: NaturezaDoDespacho::class, length: 20, nullable: true)]
        private ?NaturezaDoDespacho $natureza,
        #[ORM\Column(type: 'text')]
        private string $texto,
        #[ORM\Column(type: 'date_immutable', nullable: true)]
        private ?\DateTimeImmutable $prazo,
        #[ORM\Column(length: 255)]
        private string $cidade,
        Autor $autor,
    ) {
        $this->autor = $autor->usuario;
        $this->nomeAutor = $autor->nome;
        $this->perfilAutor = $autor->perfil;
        $this->em = new \DateTimeImmutable();
        $this->codigo = (int) floor(microtime(true) * 1000);
        $this->documentos = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDemanda(): DemandaExterna
    {
        return $this->demanda;
    }

    /** Os 8 últimos dígitos do código, como na assinatura do desenho (`String(x.id).slice(-8)`). */
    public function codigoCurto(): string
    {
        return substr((string) $this->codigo, -8);
    }

    public function getTipo(): TipoDeDespacho
    {
        return $this->tipo;
    }

    public function ehResposta(): bool
    {
        return $this->tipo === TipoDeDespacho::Resposta;
    }

    public function getNatureza(): ?NaturezaDoDespacho
    {
        return $this->natureza;
    }

    public function getTexto(): string
    {
        return $this->texto;
    }

    public function getPrazo(): ?\DateTimeImmutable
    {
        return $this->prazo;
    }

    public function getCidade(): string
    {
        return $this->cidade;
    }

    public function getAutor(): ?User
    {
        return $this->autor;
    }

    public function getNomeAutor(): string
    {
        return $this->nomeAutor;
    }

    public function getPerfilAutor(): Perfil
    {
        return $this->perfilAutor;
    }

    public function getEm(): \DateTimeImmutable
    {
        return $this->em;
    }

    public function anexar(DocumentoDemanda $documento): void
    {
        $this->documentos->add($documento);
    }

    /** @return list<DocumentoDemanda> */
    public function getDocumentos(): array
    {
        return array_values($this->documentos->toArray());
    }
}
