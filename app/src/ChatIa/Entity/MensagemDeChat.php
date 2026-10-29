<?php

declare(strict_types=1);

namespace App\ChatIa\Entity;

use App\ChatIa\Enum\PapelDaMensagem;
use App\ChatIa\Repository\MensagemDeChatRepository;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma fala da conversa do Chat I.A. Texto puro (a tela escapa). `pasta` é a pasta citada NESTA pergunta (o servidor
 * relê o contexto na hora; o texto da pasta nunca é gravado aqui). Os tokens são os REAIS do provedor; numa resposta
 * interrompida são uma estimativa (o provedor não chegou a informar). Fora da auditoria (é o texto da conversa; a trilha é a da conversa e do anexo).
 */
#[ORM\Entity(repositoryClass: MensagemDeChatRepository::class)]
#[ORM\Table(name: 'chat_ia_mensagem')]
#[ORM\Index(name: 'idx_chat_ia_mensagem_conversa', columns: ['conversa_id', 'id'])]
#[ORM\Index(name: 'idx_chat_ia_mensagem_tenant_criada', columns: ['tenant_id', 'criada_em'])]
class MensagemDeChat implements TenantAware
{
    public const TAMANHO_MAXIMO_DO_TEXTO = 8000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Pasta::class)]
    #[ORM\JoinColumn(name: 'pasta_id', nullable: true, onDelete: 'SET NULL')]
    private ?Pasta $pasta = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $modelo = null;

    #[ORM\Column(name: 'tokens_entrada', type: 'integer', nullable: true)]
    private ?int $tokensEntrada = null;

    #[ORM\Column(name: 'tokens_saida', type: 'integer', nullable: true)]
    private ?int $tokensSaida = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $interrompida = false;

    /** Resposta trocada por regeneração/edição: o texto sai, modelo e tokens ficam (o custo conta no teto do dia). */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $substituida = false;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: ConversaDeChat::class)]
        #[ORM\JoinColumn(name: 'conversa_id', nullable: false, onDelete: 'CASCADE')]
        private ConversaDeChat $conversa,
        #[ORM\Column(length: 12, enumType: PapelDaMensagem::class)]
        private PapelDaMensagem $papel,
        #[ORM\Column(type: 'text')]
        private string $conteudo,
        #[ORM\Column(name: 'criada_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $criadaEm,
    ) {
        if ($conversa->getTenant()?->getId() !== $tenant->getId()) {
            throw new \LogicException('A mensagem precisa ser do mesmo escritório da conversa.');
        }
    }

    public static function doUsuario(ConversaDeChat $conversa, string $texto, ?Pasta $pasta, \DateTimeImmutable $em): self
    {
        $mensagem = new self($conversa->getTenant() ?? throw new \LogicException('Conversa sem escritório.'), $conversa, PapelDaMensagem::Usuario, $texto, $em);
        $mensagem->pasta = $pasta;

        return $mensagem;
    }

    public static function doAssistente(ConversaDeChat $conversa, string $texto, string $modelo, ?int $tokensEntrada, ?int $tokensSaida, bool $interrompida, \DateTimeImmutable $em): self
    {
        $mensagem = new self($conversa->getTenant() ?? throw new \LogicException('Conversa sem escritório.'), $conversa, PapelDaMensagem::Assistente, $texto, $em);
        $mensagem->modelo = $modelo;
        $mensagem->tokensEntrada = $tokensEntrada;
        $mensagem->tokensSaida = $tokensSaida;
        $mensagem->interrompida = $interrompida;

        return $mensagem;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getConversa(): ConversaDeChat
    {
        return $this->conversa;
    }

    public function getPapel(): PapelDaMensagem
    {
        return $this->papel;
    }

    public function getConteudo(): string
    {
        return $this->conteudo;
    }

    public function getPasta(): ?Pasta
    {
        return $this->pasta;
    }

    public function getModelo(): ?string
    {
        return $this->modelo;
    }

    public function getTokensEntrada(): ?int
    {
        return $this->tokensEntrada;
    }

    public function getTokensSaida(): ?int
    {
        return $this->tokensSaida;
    }

    public function foiInterrompida(): bool
    {
        return $this->interrompida;
    }

    public function foiSubstituida(): bool
    {
        return $this->substituida;
    }

    /** Só a resposta que gastou tokens vira registro de custo; a pergunta e a resposta sem consumo são removidas. */
    public function guardaCustoAoSerSubstituida(): bool
    {
        return $this->papel === PapelDaMensagem::Assistente && ($this->tokensEntrada !== null || $this->tokensSaida !== null);
    }

    /** Apaga o texto e mantém modelo e tokens; a mensagem deixa de aparecer na conversa e no histórico. */
    public function substituir(): void
    {
        $this->conteudo = '';
        $this->substituida = true;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }
}
