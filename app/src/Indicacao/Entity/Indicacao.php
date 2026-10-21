<?php

declare(strict_types=1);

namespace App\Indicacao\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Indicacao\Enum\CanalDeIndicacao;
use App\Indicacao\Repository\IndicacaoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma indicação do BlueJus (spec `indicar-bluejus.md`, modelo de dados). Dado de plataforma: sem tenant (o escritório
 * de origem é só para mostrar ao Master e vira NULL se o escritório for purgado).
 *
 * O e-mail é gravado já em minúsculas ({@see self::porEmail()}): as consultas comparam a coluna direto, sem índice de
 * expressão.
 *
 * A conta criada pelo link curto (`/i/{codigo}`) vira uma indicação de canal link com `indicadoUser` preenchido
 * ({@see self::porCadastroDoLink()}): é a atribuição, não um envio, então fica fora dos limites e do alerta. Uma por
 * conta nova (índice único parcial). `porCadastro` a marca para sempre: se a conta nova for apagada (SET NULL), a
 * linha não vira um "Copiar link" e continua fora dos envios.
 */
#[ORM\Entity(repositoryClass: IndicacaoRepository::class)]
#[ORM\Table(name: 'indicacao')]
#[ORM\Index(name: 'idx_indicacao_por_criada', columns: ['indicado_por_id', 'criada_em'])]
#[ORM\UniqueConstraint(name: 'uniq_indicacao_indicado_user', columns: ['indicado_user_id'], options: ['where' => '(indicado_user_id IS NOT NULL)'])]
class Indicacao
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** A conta nova que veio pelo link curto de quem indicou; nulo em todo envio. Some (SET NULL) se a conta for apagada. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'indicado_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $indicadoUser = null;

    /** Verdadeiro só na atribuição do cadastro pelo link: não é envio (fora de limites, alerta e contagens de envio). */
    #[ORM\Column(name: 'por_cadastro', options: ['default' => false])]
    private bool $porCadastro = false;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'indicado_por_id', nullable: false, onDelete: 'CASCADE')]
        private User $indicadoPor,
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_origem_id', nullable: true, onDelete: 'SET NULL')]
        private ?Tenant $tenantOrigem,
        #[ORM\Column(length: 10, enumType: CanalDeIndicacao::class)]
        private CanalDeIndicacao $canal,
        #[ORM\Column(name: 'criada_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $criadaEm,
        #[ORM\Column(length: 180, nullable: true)]
        private ?string $email = null,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $nome = null,
    ) {
        $this->exigirCoerenciaDoCanal();
    }

    public static function porEmail(User $por, ?Tenant $origem, string $email, ?string $nome, \DateTimeImmutable $em): self
    {
        $nome = $nome === null || trim($nome) === '' ? null : mb_substr(trim($nome), 0, 120);

        return new self($por, $origem, CanalDeIndicacao::Email, $em, mb_strtolower(trim($email)), $nome);
    }

    public static function porCanal(User $por, ?Tenant $origem, CanalDeIndicacao $canal, \DateTimeImmutable $em): self
    {
        return new self($por, $origem, $canal, $em);
    }

    /**
     * A atribuição de uma conta criada pelo link curto de quem indicou (spec, comportamento 7): canal link, sem e-mail
     * nem nome (o indicador não fica sabendo quem é a pessoa).
     */
    public static function porCadastroDoLink(User $indicadoPor, ?Tenant $origem, User $indicado, \DateTimeImmutable $em): self
    {
        if ($indicado === $indicadoPor || ($indicado->getId() !== null && $indicado->getId() === $indicadoPor->getId())) {
            throw new \InvalidArgumentException('Ninguém se indica.');
        }
        $atribuicao = new self($indicadoPor, $origem, CanalDeIndicacao::Link, $em);
        $atribuicao->indicadoUser = $indicado;
        $atribuicao->porCadastro = true;

        return $atribuicao;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIndicadoPor(): User
    {
        return $this->indicadoPor;
    }

    public function getTenantOrigem(): ?Tenant
    {
        return $this->tenantOrigem;
    }

    public function getCanal(): CanalDeIndicacao
    {
        return $this->canal;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getNome(): ?string
    {
        return $this->nome;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getIndicadoUser(): ?User
    {
        return $this->indicadoUser;
    }

    public function isPorCadastro(): bool
    {
        return $this->porCadastro;
    }

    /** E-mail e nome só existem no canal e-mail; nos outros a indicação é só a contagem. */
    private function exigirCoerenciaDoCanal(): void
    {
        $porEmail = $this->canal === CanalDeIndicacao::Email;
        if ($porEmail && ($this->email === null || $this->email === '')) {
            throw new \InvalidArgumentException('Indicação por e-mail exige o endereço.');
        }
        if (!$porEmail && ($this->email !== null || $this->nome !== null)) {
            throw new \InvalidArgumentException('Só a indicação por e-mail guarda endereço e nome.');
        }
    }
}
