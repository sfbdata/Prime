<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\CadeadoDaPastaRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * O cadeado da pasta (spec pasta-cadeado, desenho 02 L507-550): exclusivo de quem configura (Master e
 * primário, K1), vale para a equipe inteira do escritório.
 *
 * Existir a linha é a pasta ter cadeado; remover o cadeado apaga a linha. Dois modos (K2):
 *  - `Todos`: ninguém da equipe vê a pasta; a lista de pessoas fica vazia;
 *  - `PorPessoa`: cada pessoa da lista tem `Visualizar` ou `Editar`. Quem não está na lista fica
 *    `Oculto`, inclusive quem entrar no escritório depois (padrão fechado do desenho). Por isso a
 *    lista só guarda quem vê: `Oculto` gravado e `Oculto` ausente são a mesma coisa.
 *
 * Quem configura nunca é restringido: essa regra mora no `AcessoAPasta`, não aqui, porque depende do
 * vínculo da pessoa com o escritório.
 *
 * Anatomia: UNIQUE (pasta_id), uma pasta tem no máximo um cadeado; `pasta_id` CASCADE; quem trancou e
 * quem alterou por último SET NULL (o cadeado não some se a conta de quem trancou sumir).
 */
#[ORM\Entity(repositoryClass: CadeadoDaPastaRepository::class)]
#[ORM\Table(name: 'pasta_cadeado')]
#[ORM\Index(name: 'idx_pasta_cadeado_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pasta_cadeado_pasta', columns: ['pasta_id'])]
class CadeadoDaPasta implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ModoDoCadeado::class)]
    private ModoDoCadeado $modo;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'criado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $criadoPor;

    #[ORM\Column(name: 'alterado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradoEm;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradoPor;

    /** @var Collection<int, AcessoAPastaTrancada> */
    #[ORM\OneToMany(mappedBy: 'cadeado', targetEntity: AcessoAPastaTrancada::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $acessos;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Pasta::class)]
        #[ORM\JoinColumn(name: 'pasta_id', nullable: false, onDelete: 'CASCADE')]
        private Pasta $pasta,
        ModoDoCadeado $modo,
        ?User $por,
        \DateTimeImmutable $em,
    ) {
        $this->modo        = $modo;
        $this->criadoPor   = $por;
        $this->criadoEm    = $em;
        $this->alteradoPor = $por;
        $this->alteradoEm  = $em;
        $this->acessos     = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getPasta(): Pasta
    {
        return $this->pasta;
    }

    public function getModo(): ModoDoCadeado
    {
        return $this->modo;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function getAlteradoEm(): \DateTimeImmutable
    {
        return $this->alteradoEm;
    }

    public function getAlteradoPor(): ?User
    {
        return $this->alteradoPor;
    }

    /** @return list<AcessoAPastaTrancada> */
    public function getAcessos(): array
    {
        return array_values($this->acessos->toArray());
    }

    /** O nível que o cadeado dá a uma pessoa da equipe (quem configura é tratado antes, no `AcessoAPasta`). */
    public function nivelDe(User $pessoa): NivelDeAcesso
    {
        if ($this->modo === ModoDoCadeado::Todos) {
            return NivelDeAcesso::Oculto;
        }

        return $this->acessoDe($pessoa)?->getNivel() ?? NivelDeAcesso::Oculto;
    }

    /** "Ocultar para todos": a lista de pessoas deixa de valer e é apagada. */
    public function ocultarParaTodos(?User $por, \DateTimeImmutable $em): void
    {
        $this->modo = ModoDoCadeado::Todos;
        $this->acessos->clear();
        $this->marcarAlteracao($por, $em);
    }

    /**
     * "Escolher por pessoa": troca a lista inteira pela grade do modal. Atualiza no lugar quem já estava
     * (a UNIQUE (cadeado, pessoa) não aceita apagar e inserir a mesma pessoa na mesma descarga) e tira
     * quem ficou `Oculto` ou saiu da grade.
     *
     * @param list<array{0: User, 1: NivelDeAcesso}> $niveis
     */
    public function definirPorPessoa(array $niveis, ?User $por, \DateTimeImmutable $em): void
    {
        $this->modo = ModoDoCadeado::PorPessoa;
        $desejados  = [];
        foreach ($niveis as [$pessoa, $nivel]) {
            if ($nivel->podeVer()) {
                $desejados[self::chave($pessoa)] = [$pessoa, $nivel];
            }
        }

        $this->tirarQuemNaoFicou($desejados);
        foreach ($desejados as [$pessoa, $nivel]) {
            $this->gravarNivel($pessoa, $nivel);
        }
        $this->marcarAlteracao($por, $em);
    }

    /**
     * Liberação de um pedido (K5, `cadResolver` do protótipo): no modo `Todos` a pasta passa a
     * `PorPessoa`, com todos os outros em `Oculto` (a lista começa vazia) e o solicitante no nível
     * liberado; em `PorPessoa`, só o nível do solicitante muda.
     */
    public function liberar(User $pessoa, NivelDeAcesso $nivel, ?User $por, \DateTimeImmutable $em): void
    {
        if (!$nivel->podeVer()) {
            throw new \InvalidArgumentException('Liberar para ocultar não existe.');
        }
        if ($this->modo === ModoDoCadeado::Todos) {
            $this->modo = ModoDoCadeado::PorPessoa;
            $this->acessos->clear();
        }
        $this->gravarNivel($pessoa, $nivel);
        $this->marcarAlteracao($por, $em);
    }

    /** @param array<string, array{0: User, 1: NivelDeAcesso}> $desejados */
    private function tirarQuemNaoFicou(array $desejados): void
    {
        foreach ($this->acessos->toArray() as $acesso) {
            if (!isset($desejados[self::chave($acesso->getPessoa())])) {
                $this->acessos->removeElement($acesso);
            }
        }
    }

    private function gravarNivel(User $pessoa, NivelDeAcesso $nivel): void
    {
        $acesso = $this->acessoDe($pessoa);
        if ($acesso !== null) {
            $acesso->mudarNivel($nivel);

            return;
        }

        $this->acessos->add(new AcessoAPastaTrancada($this->tenant, $this, $pessoa, $nivel));
    }

    private function acessoDe(User $pessoa): ?AcessoAPastaTrancada
    {
        foreach ($this->acessos as $acesso) {
            if (self::chave($acesso->getPessoa()) === self::chave($pessoa)) {
                return $acesso;
            }
        }

        return null;
    }

    /** Proxy x entidade carregada: compara por id; sem id, pela identidade do objeto. */
    private static function chave(User $pessoa): string
    {
        return $pessoa->getId() !== null ? 'id:' . $pessoa->getId() : 'obj:' . spl_object_id($pessoa);
    }

    private function marcarAlteracao(?User $por, \DateTimeImmutable $em): void
    {
        $this->alteradoPor = $por;
        $this->alteradoEm  = $em;
    }
}
