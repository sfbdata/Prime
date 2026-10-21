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
 * Vaga da Central (spec VS 18.10.26 §4.1, desenho 06 l.979–1005). Publicada pela Central já nasce
 * aberta; divulgada por um usuário externo (de escritório ou Convidado) nasce pendente até a Central
 * aprovar. Não é `TenantAware`, como a demanda: a Central lê todas; quem isola é o `VagaRepository` e a
 * `PoliticaDaVaga`. O dono externo é o escritório dele ou, sem escritório, o próprio usuário.
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

    /**
     * Início da rodada atual (`inicio` do desenho): publicar, "Reabrir" e "Ofertar novamente" recomeçam.
     * Os aceites da rodada contam a partir daqui, do zero (coordenador, 19/10); os anteriores continuam
     * "Aprovado".
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $inicioDaRodada = null;

    /** Número da rodada atual: o aceite guarda o dele, e a contagem não depende da precisão do relógio. */
    #[ORM\Column(options: ['default' => 0])]
    private int $rodada = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motivo = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    /** Publicada pela Central (Master ou Equipe com `talentos`); senão, pedido de divulgação externo. */
    #[ORM\Column(options: ['default' => false])]
    private bool $daCentral = false;

    /** O Master escolheu o tipo; sem isso, a aprovação aplica o tempo padrão da configuração (`tipoDef`, l.979). */
    #[ORM\Column(options: ['default' => false])]
    private bool $tipoDefinido = false;

    /** Quantas vezes foi divulgada pelo "Compartilhar" (`shares`, KPI "VAGAS COMPARTILHADAS"). */
    #[ORM\Column(options: ['default' => 0])]
    private int $divulgacoes = 0;

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

    /** "Nova vaga" da Central: publica na hora (l.1005). */
    public static function daCentral(User $autor, string $quem, DadosDaVaga $dados, \DateTimeImmutable $agora): self
    {
        $v = new self(null, $autor);
        $v->daCentral = true;
        $v->preencher($dados);
        $v->tipoDefinido = $dados->ofertaDefinida;
        $v->publicar($agora);
        $v->registrar('bi-megaphone', '#0a6fa0', ($v->oferta
            ? sprintf('Oferta publicada por %d min · %d vaga(s)', $v->duracaoDaOferta, $v->qtd)
            : 'Vaga publicada') . ' por ' . $quem, $agora);

        return $v;
    }

    /** "Divulgar uma vaga na Central" do externo: espera a aprovação (l.1005). Sem escritório, o dono é o usuário. */
    public static function doExterno(?Tenant $escritorio, User $autor, string $quem, DadosDaVaga $dados, \DateTimeImmutable $agora): self
    {
        $v = new self($escritorio, $autor);
        $v->preencher($dados);
        $v->registrar('bi-send', '#0a6fa0', 'Pedido de divulgação enviado por ' . $quem, $agora);

        return $v;
    }

    /**
     * "Editar vaga" da Central, "Editar pedido" e "Corrigir e reenviar" do dono (l.1005): o dono que
     * corrige uma reprovada a devolve para a fila de aprovação. Quem não é Master não muda o tipo.
     */
    public function editar(DadosDaVaga $dados, string $quem, bool $peloMaster, bool $pelaCentral, \DateTimeImmutable $agora): void
    {
        $oferta = $this->oferta;
        $this->preencher($dados);
        if (!$peloMaster) {
            $this->oferta = $oferta;
        } else {
            $this->tipoDefinido = true;
        }
        if (!$pelaCentral && $this->situacao === SituacaoVaga::Reprovada) {
            $this->situacao = SituacaoVaga::Pendente;
            $this->motivo = null;
            $this->registrar('bi-send', '#0a6fa0', 'Corrigida e reenviada por ' . $quem, $agora);

            return;
        }
        $this->registrar('bi-pencil', '#4a6373', 'Editada por ' . $quem, $agora);
    }

    /** Cada canal usado no "Divulgar vaga(s)" soma uma divulgação e vira histórico (`regShare`, l.1077). */
    public function registrarDivulgacao(string $canal, string $quem, \DateTimeImmutable $agora): void
    {
        ++$this->divulgacoes;
        $icone = match ($canal) {
            'WhatsApp' => 'bi-whatsapp',
            'E-mail' => 'bi-envelope',
            default => 'bi-link-45deg',
        };
        $this->registrar($icone, '#0a6fa0', sprintf('Divulgada por %s (%s)', $canal, $quem), $agora);
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

    /**
     * Uma ação de situação (l.979–983). `$padraoDaOferta` é o tempo padrão da configuração em minutos
     * (0 = vaga comum) e `$maxDias` o teto de divulgação para vaga externa (0 = sem limite).
     */
    public function aplicar(AcaoVaga $acao, ?string $motivo, string $quem, int $padraoDaOferta, int $maxDias, \DateTimeImmutable $agora, int $aceitesNaRodada = 0): void
    {
        $this->gravarVencimento($agora, $aceitesNaRodada);
        $this->exigirOrigem($acao);
        $motivo = $motivo !== null && trim($motivo) !== '' ? mb_substr(trim($motivo), 0, 255) : null;

        match ($acao) {
            AcaoVaga::Aprovar => $this->aprovar($padraoDaOferta, $maxDias, $agora),
            AcaoVaga::Reabrir, AcaoVaga::OfertarNovamente => $this->reabrir($agora),
            default => $this->situacao = $acao->destino(),
        };
        if ($acao->pedeMotivo()) {
            $this->motivo = $motivo;
        }
        [$ic, $cor, $txt] = $acao->historico($quem, $motivo, $this->duracaoDaOferta);
        $this->registrar($ic, $cor, $txt, $agora);
    }

    /** "Aprovar e publicar" (l.979): teto de divulgação, tipo padrão da configuração e publicação. */
    private function aprovar(int $padraoDaOferta, int $maxDias, \DateTimeImmutable $agora): void
    {
        if ($maxDias > 0 && !$this->daCentral && ($this->periodoDias === 0 || $this->periodoDias > $maxDias)) {
            $this->periodoDias = $maxDias;
        }
        if (!$this->tipoDefinido) {
            $this->oferta = $padraoDaOferta > 0;
            $this->duracaoDaOferta = $padraoDaOferta > 0 ? $padraoDaOferta : 15;
        }
        $this->publicar($agora);
        if ($this->fimDivulgacao !== null) {
            $this->inscricoesAte = $this->fimDivulgacao->setTime(0, 0);
        }
    }

    /** Reabrir e "Ofertar novamente": a oferta com tempo recomeça o contador (l.982–983). */
    private function reabrir(\DateTimeImmutable $agora): void
    {
        $this->situacao = SituacaoVaga::Aberta;
        $this->inicioDaRodada = $agora;
        ++$this->rodada;
        if ($this->oferta) {
            $this->expiraEm = $agora->modify(sprintf('+%d minutes', $this->duracaoDaOferta));
        }
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

    /** Grava o vencimento que {@see situacaoEm()} só calculava, com a linha do histórico do desenho (l.844). */
    public function gravarVencimento(\DateTimeImmutable $agora, int $aceitesNaRodada = 0): void
    {
        $situacao = $this->situacaoEm($agora);
        if ($situacao === $this->situacao) {
            return;
        }
        $situacao === SituacaoVaga::Expirada
            ? $this->registrar('bi-hourglass-bottom', '#a3232b', 'Oferta expirou' . ($aceitesNaRodada > 0
                ? sprintf(' com %d de %d vaga(s) preenchida(s)', $aceitesNaRodada, $this->qtd)
                : ' sem aceite'), $this->expiraEm ?? $agora)
            : $this->registrar('bi-calendar-x', '#6b8494', sprintf('Período de divulgação de %d dia(s) encerrado', $this->periodoDias), $this->fimDivulgacao ?? $agora);
        $this->situacao = $situacao;
    }

    /** Período 0 é "Até a vaga ser preenchida": sem fim de divulgação (l.1185). */
    private function publicar(\DateTimeImmutable $agora): void
    {
        $this->situacao = SituacaoVaga::Aberta;
        $this->publicadaEm = $agora;
        $this->inicioDaRodada = $agora;
        ++$this->rodada;
        $this->fimDivulgacao = $this->periodoDias > 0 ? $agora->modify(sprintf('+%d days', $this->periodoDias)) : null;
        $this->expiraEm = $this->oferta ? $agora->modify(sprintf('+%d minutes', $this->duracaoDaOferta)) : null;
    }

    /** A vaga recebe candidatos agora: aberta (o tempo já contado) e com o prazo de inscrição em dia. */
    public function aceitaInscricao(\DateTimeImmutable $agora): bool
    {
        return $this->situacaoEm($agora) === SituacaoVaga::Aberta
            && ($this->inscricoesAte === null || $this->inscricoesAte >= $agora->setTime(0, 0));
    }

    /** "Candidatura de {nome}" no histórico (`candidatar`, l.974). */
    public function registrarCandidatura(string $nome, \DateTimeImmutable $agora): void
    {
        $this->registrar('bi-send', '#0a6fa0', 'Candidatura de ' . $nome, $agora);
    }

    /**
     * Um aceite da oferta com tempo (`aceitar`, l.978): "Aceita por {nome} ({n} de {qtd})" e, ao
     * completar, a vaga fica preenchida com o tempo da rodada. Quem chama segura a linha da vaga
     * (`SELECT … FOR UPDATE`) e conta os aceites da rodada antes deste.
     */
    public function registrarAceite(string $nome, int $aceitesAntes, \DateTimeImmutable $agora): void
    {
        if (!$this->oferta || !$this->aceitaInscricao($agora) || $aceitesAntes >= $this->qtd) {
            throw new TransicaoInvalida('Esta vaga já foi preenchida ou encerrada.');
        }
        $n = $aceitesAntes + 1;
        $this->registrar('bi-hand-thumbs-up', '#1e7b45', sprintf('Aceita por %s (%d de %d)', $nome, $n, $this->qtd), $agora);
        if ($n >= $this->qtd) {
            $this->situacao = SituacaoVaga::Ocupada;
            $this->registrar('bi-check2-all', '#0b5f86', 'Oferta preenchida em ' . self::mmss($agora, $this->inicioDaRodada ?? $agora) . ' min', $agora);
        }
    }

    /** "{n} candidato(s) enviado(s) ao escritório por {quem}" (`enviarCands`, l.981). */
    public function registrarEnvio(int $n, string $quem, \DateTimeImmutable $agora): void
    {
        $this->registrar('bi-send-check', '#1e7b45', sprintf('%d candidato(s) enviado(s) ao escritório por %s', $n, $quem), $agora);
    }

    /** `Component.mmss` (l.842): minutos e segundos, arredondando os segundos para cima. */
    private static function mmss(\DateTimeImmutable $agora, \DateTimeImmutable $inicio): string
    {
        $t = max(0, (int) ceil(((float) $agora->format('U.u')) - ((float) $inicio->format('U.u'))));

        return sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
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
        return $this->daCentral;
    }

    /** Vaga divulgada por um externo (`v.dono` do desenho): os candidatos são enviados a ele. */
    public function temDono(): bool
    {
        return !$this->daCentral;
    }

    public function getInicioDaRodada(): ?\DateTimeImmutable
    {
        return $this->inicioDaRodada;
    }

    public function getRodada(): int
    {
        return $this->rodada;
    }

    public function getCriadaPor(): ?User
    {
        return $this->criadaPor;
    }

    public function isTipoDefinido(): bool
    {
        return $this->tipoDefinido;
    }

    public function getDivulgacoes(): int
    {
        return $this->divulgacoes;
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
