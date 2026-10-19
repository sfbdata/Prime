<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\Enum\AcaoVaga;
use App\TrabalhoExterno\Enum\SituacaoVaga;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\VagaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Vaga da Central (spec VS 18.10.26 §4.1). Criada pela Central já nasce aberta; divulgada por um
 * escritório nasce pendente até a Central aprovar. Não é `TenantAware`, como a demanda: a Central lê
 * as de todos os escritórios; quem isola é o `VagaRepository` e a `PoliticaDaVaga`.
 *
 * O tempo vence sem cron: {@see situacaoEm()} diz a situação de agora, e a próxima escrita grava.
 */
#[ORM\Entity(repositoryClass: VagaRepository::class)]
#[ORM\Table(name: 'trabalho_externo_vaga')]
class Vaga
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(enumType: SituacaoVaga::class, length: 20)]
    private SituacaoVaga $situacao;

    #[ORM\Column(length: 150)]
    private string $titulo = '';

    #[ORM\Column(length: 60)]
    private string $cargo = '';

    #[ORM\Column(length: 40)]
    private string $contratacao = '';

    #[ORM\Column(length: 20)]
    private string $modalidade = '';

    #[ORM\Column(length: 120)]
    private string $cidade = '';

    #[ORM\Column(length: 2)]
    private string $uf = '';

    #[ORM\Column(length: 120)]
    private string $remuneracao = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $areas = [];

    #[ORM\Column(type: 'text')]
    private string $requisitos = '';

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $inscricoesAte = null;

    #[ORM\Column]
    private bool $exigeOab = false;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $ofertante = [];

    #[ORM\Column]
    private int $periodoDias = 15;

    #[ORM\Column]
    private bool $oferta = false;

    #[ORM\Column]
    private int $duracaoDaOferta = 15;

    #[ORM\Column]
    private int $qtd = 1;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publicadaEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $fimDivulgacao = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiraEm = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motivo = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    /** @var Collection<int, HistoricoVaga> */
    #[ORM\OneToMany(targetEntity: HistoricoVaga::class, mappedBy: 'vaga', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['em' => 'ASC', 'id' => 'ASC'])]
    private Collection $historico;

    private function __construct(
        /** Escritório que divulga; nulo é vaga da própria Central. */
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?Tenant $escritorio,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $criadaPor,
    ) {
        $this->historico = new ArrayCollection();
        $this->criadaEm = new \DateTimeImmutable();
        $this->situacao = SituacaoVaga::Pendente;
    }

    /** "Nova vaga" da Central: publica na hora. */
    public static function daCentral(User $autor, DadosDaVaga $dados, \DateTimeImmutable $agora): self
    {
        $v = new self(null, $autor);
        $v->preencher($dados);
        $v->registrar('bi-plus-circle', '#0a6fa0', 'Vaga publicada pela Central', $agora);
        $v->publicar($agora);

        return $v;
    }

    /** "Divulgar uma vaga" do escritório: espera a aprovação da Central. */
    public static function doEscritorio(Tenant $escritorio, User $autor, DadosDaVaga $dados, \DateTimeImmutable $agora): self
    {
        $v = new self($escritorio, $autor);
        $v->preencher($dados);
        $v->registrar('bi-megaphone', '#9a5b00', 'Vaga enviada para aprovação da Central', $agora);

        return $v;
    }

    public function preencher(DadosDaVaga $d): void
    {
        $this->titulo = $d->titulo;
        $this->cargo = $d->cargo;
        $this->contratacao = $d->contratacao;
        $this->modalidade = $d->modalidade;
        $this->cidade = $d->cidade;
        $this->uf = $d->uf;
        $this->remuneracao = $d->remuneracao;
        $this->areas = $d->areas;
        $this->requisitos = $d->requisitos;
        $this->inscricoesAte = $d->inscricoesAte;
        $this->exigeOab = $d->exigeOab;
        $this->ofertante = $d->ofertante;
        $this->periodoDias = $d->periodoDias;
        $this->oferta = $d->oferta;
        $this->duracaoDaOferta = $d->duracaoDaOferta;
        $this->qtd = $d->qtd;
    }

    /** Escritório não escolhe período acima do limite da Central (`maxDias`; 0 = sem limite). */
    public function limitarPeriodo(int $maxDias): void
    {
        if ($maxDias > 0 && $this->periodoDias > $maxDias) {
            $this->periodoDias = $maxDias;
        }
    }

    public function aplicar(AcaoVaga $acao, ?string $motivo, \DateTimeImmutable $agora): void
    {
        $this->gravarVencimento($agora);
        $this->exigirOrigem($acao);
        $motivo = $motivo !== null && trim($motivo) !== '' ? mb_substr(trim($motivo), 0, 255) : null;
        if ($acao->exigeMotivo() && $motivo === null) {
            throw new TransicaoInvalida('Informe o motivo.');
        }

        [$ic, $cor, $txt] = $acao->historico($motivo);
        $this->registrar($ic, $cor, $txt, $agora);
        if ($acao === AcaoVaga::Aprovar) {
            $this->publicar($agora);

            return;
        }
        $this->situacao = $acao->destino();
        $this->motivo = $acao->exigeMotivo() ? $motivo : $this->motivo;
    }

    /** A situação de agora, com o tempo de divulgação e o da oferta já contados. */
    public function situacaoEm(\DateTimeImmutable $agora): SituacaoVaga
    {
        if ($this->situacao !== SituacaoVaga::Aberta) {
            return $this->situacao;
        }

        return match (true) {
            $this->oferta && $this->expiraEm !== null && $this->expiraEm <= $agora => SituacaoVaga::Expirada,
            $this->fimDivulgacao !== null && $this->fimDivulgacao <= $agora => SituacaoVaga::Encerrada,
            default => SituacaoVaga::Aberta,
        };
    }

    /** Grava o vencimento que {@see situacaoEm()} só calculava, com a linha do histórico do desenho. */
    public function gravarVencimento(\DateTimeImmutable $agora): void
    {
        $situacao = $this->situacaoEm($agora);
        if ($situacao === $this->situacao) {
            return;
        }
        $situacao === SituacaoVaga::Expirada
            ? $this->registrar('bi-hourglass-bottom', '#a3232b', 'Oferta expirou sem aceite', $this->expiraEm ?? $agora)
            : $this->registrar('bi-calendar-x', '#6b8494', sprintf('Período de divulgação de %d dia(s) encerrado', $this->periodoDias), $this->fimDivulgacao ?? $agora);
        $this->situacao = $situacao;
    }

    private function publicar(\DateTimeImmutable $agora): void
    {
        $this->situacao = SituacaoVaga::Aberta;
        $this->publicadaEm = $agora;
        $this->fimDivulgacao = $agora->modify(sprintf('+%d days', $this->periodoDias));
        $this->expiraEm = $this->oferta ? $agora->modify(sprintf('+%d minutes', $this->duracaoDaOferta)) : null;
    }

    private function exigirOrigem(AcaoVaga $acao): void
    {
        if (!in_array($this->situacao, $acao->origens(), true)) {
            throw new TransicaoInvalida(sprintf('A vaga está %s: a ação não se aplica mais. Recarregue a tela.', mb_strtolower($this->situacao->rotulo())));
        }
    }

    private function registrar(string $icone, string $cor, string $texto, \DateTimeImmutable $em): void
    {
        $this->historico->add(new HistoricoVaga($this, $icone, $cor, $texto, $em));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodigo(): string
    {
        return 'VG-' . str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public static function idDoCodigo(string $codigo): ?int
    {
        return preg_match('/^VG-(\d{1,9})$/', $codigo, $m) === 1 ? (int) $m[1] : null;
    }

    public function getEscritorio(): ?Tenant
    {
        return $this->escritorio;
    }

    public function ehDaCentral(): bool
    {
        return $this->escritorio === null;
    }

    public function getSituacao(): SituacaoVaga
    {
        return $this->situacao;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function getCargo(): string
    {
        return $this->cargo;
    }

    public function getContratacao(): string
    {
        return $this->contratacao;
    }

    public function getModalidade(): string
    {
        return $this->modalidade;
    }

    public function getCidade(): string
    {
        return $this->cidade;
    }

    public function getUf(): string
    {
        return $this->uf;
    }

    public function getRemuneracao(): string
    {
        return $this->remuneracao;
    }

    /** @return list<string> */
    public function getAreas(): array
    {
        return $this->areas;
    }

    public function getRequisitos(): string
    {
        return $this->requisitos;
    }

    public function getInscricoesAte(): ?\DateTimeImmutable
    {
        return $this->inscricoesAte;
    }

    public function isExigeOab(): bool
    {
        return $this->exigeOab;
    }

    /** @return array<string, mixed> */
    public function getOfertante(): array
    {
        return $this->ofertante;
    }

    public function getPeriodoDias(): int
    {
        return $this->periodoDias;
    }

    public function isOferta(): bool
    {
        return $this->oferta;
    }

    public function getDuracaoDaOferta(): int
    {
        return $this->duracaoDaOferta;
    }

    public function getQtd(): int
    {
        return $this->qtd;
    }

    public function getPublicadaEm(): ?\DateTimeImmutable
    {
        return $this->publicadaEm;
    }

    public function getFimDivulgacao(): ?\DateTimeImmutable
    {
        return $this->fimDivulgacao;
    }

    public function getExpiraEm(): ?\DateTimeImmutable
    {
        return $this->expiraEm;
    }

    public function getMotivo(): ?string
    {
        return $this->motivo;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    /** @return list<HistoricoVaga> */
    public function getHistorico(): array
    {
        return array_values($this->historico->toArray());
    }
}
