<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaSecaoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\Descartavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PastaSecaoRepository::class)]
#[ORM\Table(name: 'pasta_secao')]
#[ORM\Index(name: 'idx_pasta_secao_pasta', columns: ['pasta_id'])]
#[ORM\Index(name: 'idx_pasta_secao_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_pasta_secao_pai', columns: ['secao_pai_id'])]
// Parcial, como em `pasta_documento`: só a lixeira entra, e o `where` vai entre parênteses como o
// PostgreSQL o devolve ao `schema:validate`.
#[ORM\Index(name: 'idx_pasta_secao_lixeira', columns: ['excluido_em'], options: ['where' => '(excluido_em IS NOT NULL)'])]
class PastaSecao implements Auditavel, TenantAware, Descartavel
{
    /**
     * Trava anti-laço na travessia da árvore (não é o teto de produto, que é 10). Pública porque
     * outras recursões que descem a árvore fora desta classe (PastaSecaoRepository::contarConteudoRecursivo,
     * PurgarLixeiraUseCase::coletarArvore, LixeiraDaPastaOutput::caminho) usam o MESMO limite, em
     * vez de duplicar o número.
     */
    public const LIMITE_SEGURANCA = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Pasta::class, inversedBy: 'secoes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Pasta $pasta = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[Assert\NotBlank(message: 'O nome é obrigatório.')]
    #[Assert\Length(max: 255, maxMessage: 'O nome não pode ter mais de {{ limit }} caracteres.')]
    #[ORM\Column(length: 255)]
    private string $nome = '';

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $ordem = 0;

    #[ORM\Column(name: 'criada_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    #[ORM\OneToMany(mappedBy: 'secao', targetEntity: PastaDocumento::class, cascade: ['remove'])]
    private Collection $documentos;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'filhas')]
    #[ORM\JoinColumn(name: 'secao_pai_id', nullable: true, onDelete: 'CASCADE')]
    private ?self $pai = null;

    /** @var Collection<int, PastaSecao> */
    #[ORM\OneToMany(mappedBy: 'pai', targetEntity: self::class, cascade: ['remove'])]
    private Collection $filhas;

    /**
     * Lixeira (D7, L7): preenchido = a seção foi "excluída" pela tela, com toda a subárvore
     * carimbada com o MESMO instante (é por ele que o restaurar devolve o bloco). A linha fica até
     * a purga. Sem setter de propósito — ver o mesmo campo em `PastaDocumento`.
     */
    #[ORM\Column(name: 'excluido_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $excluidoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'excluido_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $excluidoPor = null;

    public function __construct()
    {
        $this->criadaEm = new \DateTimeImmutable();
        $this->documentos = new ArrayCollection();
        $this->filhas = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPasta(): ?Pasta
    {
        return $this->pasta;
    }

    public function setPasta(?Pasta $pasta): self
    {
        $this->pasta = $pasta;

        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): self
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
        $this->nome = mb_strtoupper(trim($nome));

        return $this;
    }

    public function getOrdem(): int
    {
        return $this->ordem;
    }

    public function setOrdem(int $ordem): self
    {
        $this->ordem = $ordem;

        return $this;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    /** @return Collection<int, PastaDocumento> */
    public function getDocumentos(): Collection
    {
        return $this->documentos;
    }

    public function getPai(): ?self
    {
        return $this->pai;
    }

    public function setPai(?self $pai): self
    {
        if ($this->pai === $pai) {
            return $this;
        }

        if ($this->pai !== null) {
            $this->pai->getFilhas()->removeElement($this);
        }

        $this->pai = $pai;

        if ($pai !== null && !$pai->getFilhas()->contains($this)) {
            $pai->getFilhas()->add($this);
        }

        return $this;
    }

    /** @return Collection<int, PastaSecao> */
    public function getFilhas(): Collection
    {
        return $this->filhas;
    }

    /**
     * Nível desta seção na árvore. Seção sem pai está no nível 1.
     *
     * O teto de LIMITE_SEGURANCA não é o teto de produto (que é 10, validado nos UseCases) — é
     * uma trava contra ciclo gravado no banco, que aqui viraria laço infinito.
     */
    public function getProfundidade(): int
    {
        $nivel = 1;
        $atual = $this->pai;
        while ($atual !== null && $nivel < self::LIMITE_SEGURANCA) {
            ++$nivel;
            $atual = $atual->getPai();
        }

        return $nivel;
    }

    /**
     * Quantos níveis a subárvore desta seção ocupa, contando ela mesma. Folha = 1.
     *
     * O corte em LIMITE_SEGURANCA aqui não é o teto de produto (10, validado nos UseCases ao
     * criar/mover) — é proteção contra ciclo GRAVADO NO BANCO, que viraria recursão infinita e
     * estouraria a memória do PHP. `DesfazerAlteracaoAuditLogUseCase` grava `pai` direto pelo
     * setter da entidade, sem passar pelos UseCases nem pelos guards de ciclo deles — é o
     * caminho que provou o estouro (ver o teste que monta `a.pai = b; b.pai = a` à mão).
     */
    public function getAltura(int $profundidade = 0): int
    {
        if ($profundidade >= self::LIMITE_SEGURANCA) {
            return 1;
        }

        $altura = 1;
        foreach ($this->filhas as $filha) {
            $altura = max($altura, 1 + $filha->getAltura($profundidade + 1));
        }

        return $altura;
    }

    /** Esta seção está em algum lugar abaixo de $possivelAncestral? Não considera a si mesma. */
    public function descendeDe(self $possivelAncestral): bool
    {
        $passos = 0;
        $atual  = $this->pai;
        while ($atual !== null && $passos < self::LIMITE_SEGURANCA) {
            if ($atual === $possivelAncestral) {
                return true;
            }
            $atual = $atual->getPai();
            ++$passos;
        }

        return false;
    }

    // ── Lixeira (D7) ────────────────────────────────────────────────────────────

    public function estaNaLixeira(): bool
    {
        return $this->excluidoEm !== null;
    }

    public function getExcluidoEm(): ?\DateTimeImmutable
    {
        return $this->excluidoEm;
    }

    public function getExcluidoPor(): ?User
    {
        return $this->excluidoPor;
    }

    /**
     * Só esta seção. Para o que a tela chama de "excluir a pasta" use {@see marcarArvoreExcluida()}:
     * uma seção na lixeira com filhas e documentos vivos deixaria itens vivos pendurados num pai
     * invisível (o proxy do pai falharia ao carregar).
     */
    public function marcarExcluido(User $por, \DateTimeImmutable $em): self
    {
        if ($this->estaNaLixeira()) {
            throw new \LogicException('Esta pasta já está na lixeira.');
        }

        $this->excluidoEm  = $em;
        $this->excluidoPor = $por;

        return $this;
    }

    public function restaurar(): self
    {
        if (!$this->estaNaLixeira()) {
            throw new \LogicException('Esta pasta não está na lixeira.');
        }

        $this->excluidoEm  = null;
        $this->excluidoPor = null;

        return $this;
    }

    /**
     * Manda esta seção e TODA a descendência (filhas, netas… e os documentos de cada uma) para a
     * lixeira, com o mesmo carimbo — é o bloco que {@see restaurarArvore()} devolve inteiro.
     *
     * O que já estava na lixeira (excluído antes, com carimbo próprio) é pulado: com o
     * `LixeiraFilter` ligado ele nem aparece nas coleções; se aparecer (filtro desligado), mantém
     * o carimbo original — restaurar a seção não ressuscita o que o usuário tinha apagado antes.
     *
     * Devolve o que foi marcado, nos mesmos escopos de `PastaSecaoRepository::contarConteudoRecursivo`:
     * `subpastas` só as DESCENDENTES (esta não se conta), `arquivos` os desta mais os de toda a
     * descendência. O corte em LIMITE_SEGURANCA é a trava anti-ciclo (ver `getAltura()`).
     *
     * @return array{subpastas: int, arquivos: int}
     */
    public function marcarArvoreExcluida(User $por, \DateTimeImmutable $em, int $profundidade = 0): array
    {
        if ($profundidade >= self::LIMITE_SEGURANCA) {
            return ['subpastas' => 0, 'arquivos' => 0];
        }

        $subpastas = 0;
        $arquivos  = 0;

        if (!$this->estaNaLixeira()) {
            $this->marcarExcluido($por, $em);
        }

        foreach ($this->documentos as $documento) {
            if ($documento->estaNaLixeira()) {
                continue;
            }
            $documento->marcarExcluido($por, $em);
            ++$arquivos;
        }

        foreach ($this->filhas as $filha) {
            // Ciclo gravado no banco (a.pai = b, b.pai = a): numa árvore cada seção é visitada uma
            // vez, então reencontrar uma já marcada NESTA ação (mesmo carimbo) só acontece no laço —
            // pular evita contar os mesmos nós até a trava de LIMITE_SEGURANCA.
            if ($filha->getExcluidoEm() == $em) {
                continue;
            }
            $abaixo     = $filha->marcarArvoreExcluida($por, $em, $profundidade + 1);
            $subpastas += 1 + $abaixo['subpastas'];
            $arquivos  += $abaixo['arquivos'];
        }

        return ['subpastas' => $subpastas, 'arquivos' => $arquivos];
    }

    /**
     * Tira da lixeira esta seção e, abaixo dela, só o que foi excluído JUNTO (mesmo carimbo). O que
     * estava na lixeira com outro carimbo fica lá — foi excluído em outra ação, e volta por outra.
     *
     * PRÉ-CONDIÇÃO: chamado com o `LixeiraFilter` desligado (`AcessoALixeira`), e com a árvore
     * carregada nesse estado — senão as coleções vêm sem os itens da lixeira e a travessia não
     * acha nada para restaurar (resultado vazio com cara de certo).
     *
     * @return array{subpastas: int, arquivos: int} contagens nos mesmos escopos de `marcarArvoreExcluida()`
     */
    public function restaurarArvore(\DateTimeImmutable $carimbo, int $profundidade = 0): array
    {
        if ($profundidade >= self::LIMITE_SEGURANCA) {
            return ['subpastas' => 0, 'arquivos' => 0];
        }

        $subpastas = 0;
        $arquivos  = 0;

        if ($this->estaNaLixeira()) {
            $this->restaurar();
        }

        foreach ($this->documentos as $documento) {
            if (!self::mesmoCarimbo($documento->getExcluidoEm(), $carimbo)) {
                continue;
            }
            $documento->restaurar();
            ++$arquivos;
        }

        foreach ($this->filhas as $filha) {
            if (!self::mesmoCarimbo($filha->getExcluidoEm(), $carimbo)) {
                continue;
            }
            $abaixo     = $filha->restaurarArvore($carimbo, $profundidade + 1);
            $subpastas += 1 + $abaixo['subpastas'];
            $arquivos  += $abaixo['arquivos'];
        }

        return ['subpastas' => $subpastas, 'arquivos' => $arquivos];
    }

    /** Por valor, não por identidade: o carimbo lido do banco é outro objeto que o gravado. */
    private static function mesmoCarimbo(?\DateTimeImmutable $a, \DateTimeImmutable $b): bool
    {
        return $a !== null && $a->format('Y-m-d H:i:s') === $b->format('Y-m-d H:i:s');
    }
}
