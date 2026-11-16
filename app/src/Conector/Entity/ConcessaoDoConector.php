<?php

declare(strict_types=1);

namespace App\Conector\Entity;

use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use App\Conector\Repository\ConcessaoDoConectorRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * A cadeia de uma autorização (`docs/specs/mcp-remoto-oauth.md`, 9.1 e 13.2): uma por autorização na tela, dentro de um
 * consentimento. O código, os tokens de acesso e as renovações dela apontam para cá, e a rotação não copia nada.
 *
 * - `autorizado_em` é o instante da autorização (o "criado em" da concessão): "Sair de todos os aparelhos" derruba a
 *   concessão autorizada até o corte, inclusive no mesmo segundo ({@see foiCortadoEm()}, regra 3 da seção 4).
 * - `impressao_da_senha` é o HMAC do hash da senha na autorização: senha trocada não bate ({@see impressaoDaSenhaBate()}).
 *   Quem calcula o HMAC é o servidor de autorização (PR 3).
 * - `expira_em` é o limite absoluto: 7 dias do `autorizado_em` (decisão 14.c). A pessoa faz login uma vez por semana.
 * - Derrubar a cadeia é revogar a concessão, com o motivo de lista fechada ({@see MotivoDaRevogacaoDaConcessao}).
 *
 * FKs em cascata para escritório, pessoa e consentimento (9.9).
 */
#[ORM\Entity(repositoryClass: ConcessaoDoConectorRepository::class)]
#[ORM\Table(name: 'conector_ia_concessao')]
#[ORM\Index(name: 'idx_conector_ia_concessao_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_conector_ia_concessao_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_conector_ia_concessao_consentimento', columns: ['consentimento_id'])]
class ConcessaoDoConector implements TenantAware
{
    use ConfereHash;

    /** Limite absoluto da cadeia: 7 dias do `autorizado_em` (decisão 14.c). */
    public const VALIDADE = 'P7D';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Copiados do consentimento: pessoa e escritório são os dele, nunca outros. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $escopos;

    #[ORM\Column(name: 'impressao_da_senha', length: 64)]
    private string $impressaoDaSenha;

    #[ORM\Column(name: 'expira_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiraEm;

    #[ORM\Column(name: 'revogada_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revogadaEm = null;

    #[ORM\Column(length: 40, nullable: true, enumType: MotivoDaRevogacaoDaConcessao::class)]
    private ?MotivoDaRevogacaoDaConcessao $motivo = null;

    /**
     * @param list<string> $escopos os concedidos na tela
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ConsentimentoDoConector::class)]
        #[ORM\JoinColumn(name: 'consentimento_id', nullable: false, onDelete: 'CASCADE')]
        private ConsentimentoDoConector $consentimento,
        array $escopos,
        #[ORM\Column(length: 255)]
        private string $resource,
        string $impressaoDaSenha,
        #[ORM\Column(name: 'autorizado_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $autorizadoEm,
    ) {
        if (!$consentimento->isAtivo()) {
            throw new \DomainException('Consentimento revogado não abre concessão.');
        }
        $this->user = $consentimento->getUser();
        $this->tenant = $consentimento->getTenant() ?? throw new \LogicException('Consentimento sem escritório.');
        $this->escopos = Escopos::exigir($escopos);
        $this->impressaoDaSenha = self::exigirHash($impressaoDaSenha, 'impressao_da_senha');
        $this->expiraEm = $autorizadoEm->add(new \DateInterval(self::VALIDADE));
    }

    /** Derruba a cadeia. Revogar de novo não muda a data nem o motivo da primeira revogação. */
    public function revogar(MotivoDaRevogacaoDaConcessao $motivo, \DateTimeImmutable $agora): void
    {
        if ($this->revogadaEm !== null) {
            return;
        }

        $this->revogadaEm = $agora;
        $this->motivo = $motivo;
    }

    /** Não revogada, dentro dos 7 dias e com o consentimento ativo. As barreiras de pessoa e escritório são do portão. */
    public function estaValidaEm(\DateTimeImmutable $agora): bool
    {
        return $this->revogadaEm === null && $agora < $this->expiraEm && $this->consentimento->isAtivo();
    }

    /**
     * "Sair de todos os aparelhos" derruba a concessão autorizada até o corte, inclusive no mesmo segundo (o mesmo critério
     * do `SessaoEncerradaEmTodosOsAparelhosListener`). Sem corte gravado, nada cai.
     */
    public function foiCortadoEm(?\DateTimeImmutable $sessoesEncerradasEm): bool
    {
        return $sessoesEncerradasEm !== null && $this->autorizadoEm <= $sessoesEncerradasEm;
    }

    /** A impressão da senha de agora é a mesma gravada na autorização? Comparação em tempo constante. */
    public function impressaoDaSenhaBate(string $impressaoAtual): bool
    {
        return hash_equals($this->impressaoDaSenha, $impressaoAtual);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConsentimento(): ConsentimentoDoConector
    {
        return $this->consentimento;
    }

    public function getCliente(): ClienteDoConector
    {
        return $this->consentimento->getCliente();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    /** @return list<string> */
    public function getEscopos(): array
    {
        return $this->escopos;
    }

    public function getResource(): string
    {
        return $this->resource;
    }

    public function getAutorizadoEm(): \DateTimeImmutable
    {
        return $this->autorizadoEm;
    }

    public function getImpressaoDaSenha(): string
    {
        return $this->impressaoDaSenha;
    }

    public function getExpiraEm(): \DateTimeImmutable
    {
        return $this->expiraEm;
    }

    public function getRevogadaEm(): ?\DateTimeImmutable
    {
        return $this->revogadaEm;
    }

    public function getMotivo(): ?MotivoDaRevogacaoDaConcessao
    {
        return $this->motivo;
    }
}
