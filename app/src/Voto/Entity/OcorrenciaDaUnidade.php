<?php

declare(strict_types=1);

namespace App\Voto\Entity;

use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use App\Voto\Enum\AvaliacaoDaOcorrencia;
use App\Voto\Enum\OrigemDaOcorrencia;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\OcorrenciaDaUnidadeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ocorrência registrada para uma unidade da assembleia, e a avaliação do conselho quando a unidade não é apta (spec
 * docs/specs/voto-avaliacoes-do-conselho-1611.md, seções 1 e 2). Só acrescenta: cada registro é uma linha nova, nunca
 * sobrescreve a anterior (ao contrário do protótipo do desenho).
 *
 * Avaliação: `null` (pessoa apta, ou vinda da importação), `pendente` (aguarda o conselho) e, decidida, `mantida` ou
 * `tornada_apta`. Uma só `pendente` por unidade (índice único parcial). A decisão tomada não se refaz.
 *
 * Não é `Auditavel`: o rastro é a trilha própria (`RegistrarAuditoriaDasAvaliacoesUseCase`), só com o id da unidade, sem
 * o texto livre nem o nome da pessoa. O tenant vem da assembleia (nunca de fora).
 */
#[ORM\Entity(repositoryClass: OcorrenciaDaUnidadeRepository::class)]
#[ORM\Table(name: 'ocorrencia_da_unidade', schema: 'voto')]
#[ORM\UniqueConstraint(name: 'uniq_voto_ocorrencia_pendente', columns: ['unidade_id'], options: ['where' => "((avaliacao)::text = 'pendente'::text)"])]
class OcorrenciaDaUnidade implements TenantAware
{
    public const MAXIMO_DO_TEXTO = 80;
    public const MAXIMO_DA_JUSTIFICATIVA = 160;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 80)]
    private string $texto;

    #[ORM\Column(length: 12, nullable: true, enumType: AvaliacaoDaOcorrencia::class)]
    private ?AvaliacaoDaOcorrencia $avaliacao = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decididaEm = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $decididaPorNome = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $justificativa = null;

    /**
     * @param bool $abreAvaliacao a unidade não é apta: a ocorrência abre a avaliação do conselho (`pendente`)
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Assembleia::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Assembleia $assembleia,
        #[ORM\ManyToOne(targetEntity: UnidadeDaAssembleia::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private UnidadeDaAssembleia $unidade,
        string $texto,
        #[ORM\Column(length: 10, enumType: OrigemDaOcorrencia::class)]
        private OrigemDaOcorrencia $origem,
        #[ORM\Column(length: 180)]
        private string $registradaPorNome,
        bool $abreAvaliacao = false,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $registradaEm = new \DateTimeImmutable(),
    ) {
        $this->tenant = $assembleia->getTenant();
        $this->texto = self::textoValido($texto);
        $this->registradaPorNome = mb_substr($registradaPorNome, 0, 180);
        $this->avaliacao = $abreAvaliacao ? AvaliacaoDaOcorrencia::Pendente : null;
    }

    /** Texto limpo, de 1 a 80 caracteres ("Escreva a ocorrência." do desenho se vier vazio). */
    public static function textoValido(string $texto): string
    {
        $texto = trim($texto);
        if ('' === $texto) {
            throw new OperacaoInvalidaNaAssembleiaException('Escreva a ocorrência.');
        }

        return mb_substr($texto, 0, self::MAXIMO_DO_TEXTO);
    }

    public function pendente(): bool
    {
        return AvaliacaoDaOcorrencia::Pendente === $this->avaliacao;
    }

    /**
     * Decisão do conselho: `Mantida` ou `TornadaApta`, com justificativa de 1 a 160 caracteres. Só quem está pendente
     * decide, uma vez. Quem muda a unidade (no caso de `TornadaApta`) é o UseCase, pelo método rico dela.
     */
    public function decidir(AvaliacaoDaOcorrencia $decisao, string $justificativa, string $por, ?\DateTimeImmutable $agora = null): void
    {
        if (!$this->pendente()) {
            throw new OperacaoInvalidaNaAssembleiaException('Não há avaliação pendente.');
        }
        if (AvaliacaoDaOcorrencia::Pendente === $decisao) {
            throw new OperacaoInvalidaNaAssembleiaException('Decisão inválida.');
        }
        $justificativa = trim($justificativa);
        if ('' === $justificativa) {
            throw new OperacaoInvalidaNaAssembleiaException('Informe a justificativa.');
        }
        $this->avaliacao = $decisao;
        $this->justificativa = mb_substr($justificativa, 0, self::MAXIMO_DA_JUSTIFICATIVA);
        $this->decididaEm = $agora ?? new \DateTimeImmutable();
        $this->decididaPorNome = mb_substr($por, 0, 180);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getAssembleia(): Assembleia
    {
        return $this->assembleia;
    }

    public function getUnidade(): UnidadeDaAssembleia
    {
        return $this->unidade;
    }

    public function getTexto(): string
    {
        return $this->texto;
    }

    public function getOrigem(): OrigemDaOcorrencia
    {
        return $this->origem;
    }

    public function getRegistradaEm(): \DateTimeImmutable
    {
        return $this->registradaEm;
    }

    public function getRegistradaPorNome(): string
    {
        return $this->registradaPorNome;
    }

    public function getAvaliacao(): ?AvaliacaoDaOcorrencia
    {
        return $this->avaliacao;
    }

    public function getDecididaEm(): ?\DateTimeImmutable
    {
        return $this->decididaEm;
    }

    public function getDecididaPorNome(): ?string
    {
        return $this->decididaPorNome;
    }

    public function getJustificativa(): ?string
    {
        return $this->justificativa;
    }
}
