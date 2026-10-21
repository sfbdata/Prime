<?php

declare(strict_types=1);

namespace App\Inteligencia\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Repository\PerguntaDaConversaRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * UMA pergunta da aba "Perguntar" do drawer da BlueJus IA (spec inteligencia-drawer-da-pasta, F3).
 *
 * A conversa é das perguntas da MESMA pessoa na MESMA pasta: ninguém vê a conversa de outra pessoa.
 * A resposta não mora aqui: cada pergunta tem a sua {@see AnaliseDeInteligencia} de tipo
 * `pergunta_livre` (status, provedor, tokens e o `texto_da_analise`, que é a resposta). Por isso a
 * análise é única e, se a análise for apagada (purga do escritório), a pergunta cai junto (CASCADE).
 *
 * O texto é o que a pessoa escreveu, aparado (1 a 2000 caracteres, regra do
 * `PerguntarSobreAPastaInput`). Nunca vai para log; vai ao provedor só pelo `PromptDaPergunta`,
 * neutralizado e mascarado.
 *
 * Índices nomeados à mão, como na `AnaliseDeInteligencia`, para a migration bater com o mapeamento.
 */
#[ORM\Entity(repositoryClass: PerguntaDaConversaRepository::class)]
#[ORM\Table(name: 'inteligencia_pergunta')]
#[ORM\Index(name: 'idx_inteligencia_pergunta_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_inteligencia_pergunta_conversa', columns: ['tenant_id', 'pasta_id', 'autor_id'])]
#[ORM\Index(name: 'idx_inteligencia_pergunta_autor', columns: ['autor_id'])]
#[ORM\UniqueConstraint(name: 'uniq_inteligencia_pergunta_analise', columns: ['analise_id'])]
class PerguntaDaConversa implements TenantAware
{
    public const TAMANHO_MAXIMO = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\Column(type: 'integer')]
        private int $pastaId,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $autor,
        #[ORM\Column(type: 'text')]
        private string $texto,
        #[ORM\OneToOne(targetEntity: AnaliseDeInteligencia::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private AnaliseDeInteligencia $analise,
    ) {
        $this->criadaEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getPastaId(): int
    {
        return $this->pastaId;
    }

    public function getAutor(): User
    {
        return $this->autor;
    }

    public function getTexto(): string
    {
        return $this->texto;
    }

    public function getAnalise(): AnaliseDeInteligencia
    {
        return $this->analise;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }
}
