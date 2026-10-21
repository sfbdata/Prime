<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\TrabalhoExterno\Enum\StatusCandidatura;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma candidatura de um currículo a uma vaga (spec VS 18.10.26 §4.6, desenho 06 l.974 a 985). Uma por
 * par (vaga, currículo); some com a vaga ou com o currículo (FK CASCADE, como o desenho apaga).
 *
 * Não é `TenantAware`: a vaga e o currículo são da plataforma. Quem isola é o `CandidaturaRepository`
 * (só consultas a partir da vaga já localizada no escopo ou do currículo do próprio usuário) e a
 * `PoliticaDaVaga`.
 *
 * `datas` guarda quando cada etapa foi alcançada (analise, enviado, entrevista, aprovado, contratado,
 * nao), em ISO; a linha do tempo (`etapas`) sai disso. O aceite de oferta com tempo nasce em
 * "Aprovado" (decisão 4 da §4.4) e conta na rodada que estava aberta.
 */
#[ORM\Entity(repositoryClass: CandidaturaRepository::class)]
#[ORM\Table(name: 'trabalho_externo_candidatura')]
#[ORM\UniqueConstraint(name: 'uniq_te_candidatura_vaga_curriculo', columns: ['vaga_id', 'curriculo_id'])]
class Candidatura
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(enumType: StatusCandidatura::class, length: 20)]
    private StatusCandidatura $status = StatusCandidatura::Recebida;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $enviadaEm;

    /** @var array<string, string> etapa → quando (ISO) */
    #[ORM\Column(type: 'json')]
    private array $datas = [];

    #[ORM\Column(options: ['default' => false])]
    private bool $enviadoAoEscritorio = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $enviadoAoEscritorioEm = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $aceiteDeOferta = false;

    /** A rodada da vaga em que o aceite aconteceu (só no aceite de oferta). */
    #[ORM\Column(nullable: true)]
    private ?int $rodada = null;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: Vaga::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Vaga $vaga,
        #[ORM\ManyToOne(targetEntity: Curriculo::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Curriculo $curriculo,
        \DateTimeImmutable $agora,
    ) {
        $this->enviadaEm = $agora;
    }

    /** "Candidatar-se" (`candidatar`, l.974): entra como "Recebida". */
    public static function candidatar(Vaga $vaga, Curriculo $curriculo, \DateTimeImmutable $agora): self
    {
        return new self($vaga, $curriculo, $agora);
    }

    /**
     * "Aceitar oferta" (`aceitar`, l.978): já em "Aprovado". Na vaga com dono, entra direto nos enviados
     * ao escritório, porque o toast promete que "O escritório já foi avisado" (coordenador, 19/10).
     */
    public static function aceitarOferta(Vaga $vaga, Curriculo $curriculo, \DateTimeImmutable $agora): self
    {
        $c = new self($vaga, $curriculo, $agora);
        $c->aceiteDeOferta = true;
        $c->rodada = $vaga->getRodada();
        $c->mudarStatus(StatusCandidatura::Aprovado, $agora);
        if ($vaga->temDono()) {
            $c->marcarEnviada($agora);
        }

        return $c;
    }

    /** "Qualquer AST para qualquer outro" (`aStatus`, l.1047): a data da etapa é a de agora. */
    public function mudarStatus(StatusCandidatura $status, \DateTimeImmutable $agora): void
    {
        $this->status = $status;
        if ($status !== StatusCandidatura::Recebida) {
            $this->datas[$status->value] = $agora->format(\DATE_ATOM);
        }
    }

    /**
     * "Enviar ao escritório" (`enviarCands`, l.981): "Recebida" vira "Em análise". Sem "desenviar".
     *
     * @return bool se foi enviada agora (a que já estava enviada não conta de novo)
     */
    public function enviarAoEscritorio(\DateTimeImmutable $agora): bool
    {
        if ($this->enviadoAoEscritorio) {
            return false;
        }
        if ($this->status === StatusCandidatura::Recebida) {
            $this->status = StatusCandidatura::Analise;
        }
        $this->datas['analise'] ??= $agora->format(\DATE_ATOM);
        $this->marcarEnviada($agora);

        return true;
    }

    private function marcarEnviada(\DateTimeImmutable $agora): void
    {
        $this->enviadoAoEscritorio = true;
        $this->enviadoAoEscritorioEm = $agora;
        $this->datas['enviado'] = $agora->format(\DATE_ATOM);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVaga(): Vaga
    {
        return $this->vaga;
    }

    public function getCurriculo(): Curriculo
    {
        return $this->curriculo;
    }

    public function getStatus(): StatusCandidatura
    {
        return $this->status;
    }

    public function getEnviadaEm(): \DateTimeImmutable
    {
        return $this->enviadaEm;
    }

    /** @return array<string, string> etapa → quando (ISO) */
    public function getDatas(): array
    {
        return $this->datas;
    }

    public function isEnviadoAoEscritorio(): bool
    {
        return $this->enviadoAoEscritorio;
    }

    public function getEnviadoAoEscritorioEm(): ?\DateTimeImmutable
    {
        return $this->enviadoAoEscritorioEm;
    }

    public function isAceiteDeOferta(): bool
    {
        return $this->aceiteDeOferta;
    }

    public function getRodada(): ?int
    {
        return $this->rodada;
    }
}
