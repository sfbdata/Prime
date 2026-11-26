<?php

declare(strict_types=1);

namespace App\Voto\Entity;

use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\OcorrenciaDaVotacaoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ocorrência que o eleitor registra durante a votação, pelo Portal do Eleitor (pedido de 26/11/2026): texto curto, da
 * assembleia toda ou de uma pauta. Diferente da {@see OcorrenciaDaUnidade}, que é da lista de unidades e abre avaliação
 * do conselho: esta só registra e aparece ao gestor e na ata.
 *
 * A qualificação (nome do responsável e rótulo da unidade) é CONGELADA na gravação: mudar o cadastro depois não reescreve
 * o que ficou registrado. Só acrescenta. Não é `Auditavel`: o texto livre e o nome do eleitor não vão para o audit_log.
 * O tenant vem da assembleia (nunca de fora). A pauta é opcional (`null` = a votação toda).
 */
#[ORM\Entity(repositoryClass: OcorrenciaDaVotacaoRepository::class)]
#[ORM\Table(name: 'ocorrencia_da_votacao', schema: 'voto')]
#[ORM\Index(name: 'idx_voto_ocorrencia_votacao_assembleia', columns: ['assembleia_id', 'criada_em'])]
class OcorrenciaDaVotacao implements TenantAware
{
    public const MAXIMO_DO_TEXTO = 500;
    public const MAXIMO_POR_UNIDADE = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 500)]
    private string $texto;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Assembleia::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Assembleia $assembleia,
        #[ORM\ManyToOne(targetEntity: UnidadeDaAssembleia::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private UnidadeDaAssembleia $unidade,
        #[ORM\ManyToOne(targetEntity: PautaDaAssembleia::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?PautaDaAssembleia $pauta,
        string $texto,
        #[ORM\Column(length: 180)]
        private string $nomeDoEleitor,
        #[ORM\Column(length: 180)]
        private string $rotuloDaUnidade,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $criadaEm = new \DateTimeImmutable(),
    ) {
        $this->tenant = $assembleia->getTenant();
        $this->texto = self::textoValido($texto);
        $this->nomeDoEleitor = mb_substr($nomeDoEleitor, 0, 180);
        $this->rotuloDaUnidade = mb_substr($rotuloDaUnidade, 0, 180);
    }

    /** Texto limpo, de 1 a 500 caracteres; vazio ou maior que isso é recusado (nunca corta em silêncio). */
    public static function textoValido(string $texto): string
    {
        $texto = trim($texto);
        if ('' === $texto) {
            throw new OperacaoInvalidaNaAssembleiaException('Escreva a ocorrência.');
        }
        if (mb_strlen($texto) > self::MAXIMO_DO_TEXTO) {
            throw new OperacaoInvalidaNaAssembleiaException('A ocorrência pode ter no máximo ' . self::MAXIMO_DO_TEXTO . ' caracteres.');
        }

        return $texto;
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

    public function getPauta(): ?PautaDaAssembleia
    {
        return $this->pauta;
    }

    public function getTexto(): string
    {
        return $this->texto;
    }

    public function getNomeDoEleitor(): string
    {
        return $this->nomeDoEleitor;
    }

    public function getRotuloDaUnidade(): string
    {
        return $this->rotuloDaUnidade;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }
}
