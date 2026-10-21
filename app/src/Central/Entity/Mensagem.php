<?php

declare(strict_types=1);

namespace App\Central\Entity;

use App\Central\Enum\TipoDeItemEncaminhado;
use App\Central\Repository\MensagemRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mensagem de texto numa conversa da Central (A1). Texto puro: a tela escapa (Twig) ou usa `textContent`
 * (JS) — nunca HTML. O id é o cursor do polling e da leitura, por isso o índice `(conversa_id, id)`.
 *
 * Fora da auditoria de propósito, como a conversa (ver `Conversa`).
 *
 * A2: pode responder a outra mensagem — sempre da MESMA conversa (invariante do construtor). Apagar a original
 * não apaga a resposta: a citação some (`ON DELETE SET NULL`).
 *
 * Despacho ("Encaminhar via Chat I.A", `docs/specs/encaminhar-via-chat-ia.md` §3): a mensagem guarda a REFERÊNCIA da
 * pasta (`despacho_pasta_id`) e o tipo do item (`despacho_tipo`), nunca o teor à parte. O texto gravado leva título,
 * resumo e NUP, por isso a leitura de uma mensagem com `despacho_tipo` passa SEMPRE por
 * `App\Central\Service\LeituraDasMensagens`, que confere se quem lê vê a pasta naquela hora. Pasta apagada vira
 * `NULL` (`ON DELETE SET NULL`) e o tipo fica: conta como "não vê". Mensagem comum tem as duas colunas nulas.
 */
#[ORM\Entity(repositoryClass: MensagemRepository::class)]
#[ORM\Table(name: 'central_mensagem')]
#[ORM\Index(name: 'idx_central_mensagem_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_central_mensagem_conversa_id', columns: ['conversa_id', 'id'])]
#[ORM\Index(name: 'idx_central_mensagem_tenant_criada', columns: ['tenant_id', 'criada_em'])]
class Mensagem implements TenantAware
{
    /** Teto do texto VISÍVEL: a menção conta como `@Nome`, não como o token gravado (A2, spec §2). */
    public const TAMANHO_MAXIMO = 4000;

    /** Token canônico de menção `@[Nome](user:ID)` — o mesmo que a tela lê (`central.js`, PADRAO_MENCAO). */
    public const PADRAO_MENCAO = '/@\[([^\[\]<>]{1,120})\]\(user:(\d{1,10})\)/u';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private string $conteudo;

    /**
     * Mensagem de sistema do grupo (B): "Ana adicionou Bruno". O texto é montado pelo servidor, o autor é quem fez;
     * não conta como não lida, não pode ser respondida e não menciona ninguém.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $sistema = false;

    #[ORM\ManyToOne(targetEntity: Pasta::class)]
    #[ORM\JoinColumn(name: 'despacho_pasta_id', nullable: true, onDelete: 'SET NULL')]
    private ?Pasta $despachoPasta = null;

    #[ORM\Column(name: 'despacho_tipo', length: 30, nullable: true, enumType: TipoDeItemEncaminhado::class)]
    private ?TipoDeItemEncaminhado $despachoTipo = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Conversa::class)]
        #[ORM\JoinColumn(name: 'conversa_id', nullable: false, onDelete: 'CASCADE')]
        private Conversa $conversa,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'autor_id', nullable: false)]
        private User $autor,
        string $conteudo,
        #[ORM\Column(name: 'criada_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $criadaEm,
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(name: 'resposta_a_id', nullable: true, onDelete: 'SET NULL')]
        private ?Mensagem $respostaA = null,
    ) {
        if ($respostaA !== null && $respostaA->getConversa() !== $conversa) {
            throw new \DomainException('A mensagem respondida não é desta conversa.');
        }
        $conteudo = trim($conteudo);
        if ($conteudo === '') {
            throw new \DomainException('A mensagem está vazia.');
        }
        if (mb_strlen(self::textoVisivel($conteudo)) > self::TAMANHO_MAXIMO) {
            throw new \DomainException(sprintf('A mensagem passa de %d caracteres.', self::TAMANHO_MAXIMO));
        }

        $this->conteudo = $conteudo;
    }

    public static function deSistema(Tenant $tenant, Conversa $conversa, User $autor, string $texto, \DateTimeImmutable $em): self
    {
        $mensagem = new self($tenant, $conversa, $autor, $texto, $em);
        $mensagem->sistema = true;

        return $mensagem;
    }

    public function isSistema(): bool
    {
        return $this->sistema;
    }

    /**
     * Marca a mensagem como despacho de um item desta pasta. Uma vez só, antes de gravar, nunca em mensagem de sistema,
     * e a pasta é do MESMO escritório da mensagem.
     */
    public function referenciarDespacho(Pasta $pasta, TipoDeItemEncaminhado $tipo): void
    {
        if ($this->sistema || $this->despachoTipo !== null) {
            throw new \DomainException('Esta mensagem não pode ser um despacho.');
        }
        $daPasta = $pasta->getTenant();
        if ($daPasta === null || ($daPasta !== $this->tenant && $daPasta->getId() !== $this->tenant->getId())) {
            throw new \DomainException('A pasta do despacho não é deste escritório.');
        }

        $this->despachoPasta = $pasta;
        $this->despachoTipo = $tipo;
    }

    /** Tem referência de despacho (mesmo que a pasta já tenha sido apagada). */
    public function isDespacho(): bool
    {
        return $this->despachoTipo !== null;
    }

    public function getDespachoTipo(): ?TipoDeItemEncaminhado
    {
        return $this->despachoTipo;
    }

    /** O id da pasta do despacho, sem carregar a pasta: quem lê a busca com o escritório explícito. */
    public function getDespachoPastaId(): ?int
    {
        return $this->despachoPasta?->getId();
    }

    /**
     * O texto como a pessoa o vê: cada token de menção vira `@Nome`. É o que conta no limite de tamanho — quem
     * digita 4.000 caracteres com uma menção não pode ser recusado pelos bytes do `(user:ID)` que não vê.
     */
    public static function textoVisivel(string $conteudo): string
    {
        return preg_replace(self::PADRAO_MENCAO, '@$1', $conteudo) ?? $conteudo;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getConversa(): Conversa
    {
        return $this->conversa;
    }

    public function getAutor(): User
    {
        return $this->autor;
    }

    /**
     * O texto GRAVADO. Para mostrar a alguém, use `LeituraDasMensagens`: num despacho o texto leva o teor da pasta, e
     * só sai para quem vê a pasta.
     */
    public function getConteudo(): string
    {
        return $this->conteudo;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getRespostaA(): ?Mensagem
    {
        return $this->respostaA;
    }
}
