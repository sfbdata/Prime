<?php

declare(strict_types=1);

namespace App\Voto\Entity;

use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use App\Voto\Enum\BaseDaDecisao;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\RegraDeDecisao;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pauta da assembleia de condomínio (spec docs/specs/voto-assembleia-condominio.md, seções 1 e 3): cada pauta é uma
 * votação com estado próprio, aberta e encerrada pela mesa (aguardando, aberta, encerrada).
 *
 * As regras da pauta (critério, base, dígitos, vagas e quórum de cada convocação) ficam definidas no rascunho e param
 * de mudar quando a assembleia é publicada; o padrão é o de sempre (mais votos, válidos, 1 dígito, 1 vaga, quórum
 * 50% na 1ª e 0% na 2ª). Abrir grava a convocação e o quórum exigido dela. O prazo é opcional; vencido, a pauta deixa de
 * aceitar voto e é encerrada na primeira leitura depois dele com o motivo "Prazo definido pela mesa"
 * ({@see encerrarSePrazoVenceu()}).
 *
 * O tenant vem da assembleia (nunca de fora), para a pauta não poder nascer em outro escritório. É `Auditavel`: abrir e
 * encerrar ficam no audit_log; nada aqui liga unidade a opção.
 */
#[ORM\Entity(repositoryClass: PautaDaAssembleiaRepository::class)]
#[ORM\Table(name: 'assembleia_pauta', schema: 'voto')]
class PautaDaAssembleia implements TenantAware, Auditavel
{
    /** Motivo quando a mesa encerra sem escrever um. */
    public const MOTIVO_DA_MESA = 'Determinação da mesa';
    /** Motivo do encerramento pelo prazo vencido. */
    public const MOTIVO_DO_PRAZO = 'Prazo definido pela mesa';

    // Contadores agregados mudam só por SQL direto, sem auditoria ou vínculo com a unidade.
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $nulosUnidades = 0;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 6, options: ['default' => 0])]
    private string $nulosPeso = '0.000000';

    public const VAGAS_MAXIMO = 9;
    public const DIGITOS_MAXIMO = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 12, enumType: EstadoDaPauta::class)]
    private EstadoDaPauta $estado = EstadoDaPauta::Aguardando;

    #[ORM\Column(length: 12, enumType: RegraDeDecisao::class, options: ['default' => 'mais'])]
    private RegraDeDecisao $criterio = RegraDeDecisao::Mais;

    #[ORM\Column(length: 12, enumType: BaseDaDecisao::class, options: ['default' => 'validos'])]
    private BaseDaDecisao $base = BaseDaDecisao::Validos;

    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $digitos = 1;

    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $vagas = 1;

    /** Quórum da 1ª convocação (% do peso apto). */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, options: ['default' => 50])]
    private string $quorumPrimeira = '50.00';

    /** Quórum da 2ª convocação; nulo repete o da 1ª (como o desenho). */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true, options: ['default' => 0])]
    private ?string $quorumSegunda = '0.00';

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $convocacao = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $quorumExigido = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $abertaEm = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $abertaPorNome = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $prazoAte = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $encerradaEm = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $encerradaPorNome = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $motivoEncerramento = null;

    /** @var Collection<int, OpcaoDaPauta> */
    #[ORM\OneToMany(mappedBy: 'pauta', targetEntity: OpcaoDaPauta::class, cascade: ['persist'])]
    #[ORM\OrderBy(['abstencao' => 'ASC', 'numero' => 'ASC', 'id' => 'ASC'])]
    private Collection $opcoes;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Assembleia::class, inversedBy: 'pautas')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Assembleia $assembleia,
        #[ORM\Column(type: 'smallint')]
        private int $ordem,
        #[ORM\Column(length: 200)]
        private string $titulo,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $descricao = null,
    ) {
        $this->tenant = $assembleia->getTenant();
        $this->titulo = self::tituloValido($titulo);
        $this->opcoes = new ArrayCollection();
        $assembleia->registrarPauta($this);
    }

    /** Título e descrição só mudam com a assembleia em rascunho. */
    public function editar(string $titulo, ?string $descricao): void
    {
        $this->assembleia->exigirPautasAlteraveis();
        $this->titulo = self::tituloValido($titulo);
        $this->descricao = $descricao;
    }

    /**
     * Define as regras da pauta (só em rascunho): critério, base, dígitos dos números (1 a 5), vagas (1 a 9) e quórum
     * de 0 a 100% na 1ª e, opcional, na 2ª convocação (nulo: repete o da 1ª).
     */
    public function configurar(
        RegraDeDecisao $criterio,
        BaseDaDecisao $base,
        int $digitos,
        int $vagas,
        int $quorumPrimeira,
        ?int $quorumSegunda,
    ): void {
        $this->assembleia->exigirPautasAlteraveis();
        self::exigirEntre($digitos, 1, self::DIGITOS_MAXIMO, 'Os dígitos do número vão de 1 a ' . self::DIGITOS_MAXIMO . '.');
        self::exigirEntre($vagas, 1, self::VAGAS_MAXIMO, 'As vagas vão de 1 a ' . self::VAGAS_MAXIMO . '.');
        self::exigirEntre($quorumPrimeira, 0, 100, 'O quórum vai de 0 a 100%.');
        if (null !== $quorumSegunda) {
            self::exigirEntre($quorumSegunda, 0, 100, 'O quórum vai de 0 a 100%.');
        }
        $this->criterio = $criterio;
        $this->base = $base;
        $this->digitos = $digitos;
        $this->vagas = $vagas;
        $this->quorumPrimeira = self::percentual($quorumPrimeira);
        $this->quorumSegunda = null === $quorumSegunda ? null : self::percentual($quorumSegunda);
    }

    /**
     * A mesa abre a pauta: assembleia publicada e pauta aguardando. Convocação 1 ou 2 (o quórum é o da pauta para ela); prazo
     * opcional, sempre no futuro.
     */
    public function abrir(string $nome, int $convocacao, ?\DateTimeImmutable $prazoAte, ?\DateTimeImmutable $agora = null): void
    {
        $agora ??= new \DateTimeImmutable();
        if (EstadoDaAssembleia::Publicada !== $this->assembleia->getEstado()) {
            throw new OperacaoInvalidaNaAssembleiaException('Publique a assembleia antes de abrir a votação.');
        }
        $this->exigirEstado(EstadoDaPauta::Aguardando, 'Esta pauta já foi aberta.');
        $this->exigirPrazoNoFuturo($prazoAte, $agora);
        $this->quorumExigido = $this->quorumDaConvocacao($convocacao);
        $this->convocacao = $convocacao;
        $this->estado = EstadoDaPauta::Aberta;
        $this->abertaEm = $agora;
        $this->abertaPorNome = $nome;
        $this->prazoAte = $prazoAte;
    }

    /** Define ("Definir prazo") ou retira ("Sem prazo", null) o prazo da pauta aberta. */
    public function definirPrazo(?\DateTimeImmutable $prazoAte, ?\DateTimeImmutable $agora = null): void
    {
        $this->exigirEstado(EstadoDaPauta::Aberta, 'Só a pauta aberta tem prazo.');
        $this->exigirPrazoNoFuturo($prazoAte, $agora ?? new \DateTimeImmutable());
        $this->prazoAte = $prazoAte;
    }

    /**
     * A mesa encerra a pauta aberta. Motivo vazio vira "Determinação da mesa". Se o prazo já tinha vencido, vale o
     * encerramento pelo prazo (hora do prazo e motivo do prazo): nenhum voto foi aceito depois dele.
     */
    public function encerrar(string $nome, string $motivo, ?\DateTimeImmutable $agora = null): void
    {
        $agora ??= new \DateTimeImmutable();
        $this->exigirEstado(EstadoDaPauta::Aberta, 'Só a pauta aberta pode ser encerrada.');
        if ($this->encerrarSePrazoVenceu($agora)) {
            return;
        }
        $motivo = trim($motivo);
        $this->fechar($agora, $nome, '' === $motivo ? self::MOTIVO_DA_MESA : $motivo);
    }

    /**
     * Encerra a pauta aberta cujo prazo venceu, com a hora do prazo e o motivo "Prazo definido pela mesa". Devolve
     * true se encerrou agora; false se não estava aberta ou o prazo não venceu. Quem lê a pauta (painel, cédula) chama
     * antes de mostrar.
     */
    public function encerrarSePrazoVenceu(\DateTimeImmutable $agora): bool
    {
        if (!$this->prazoVencidoEm($agora)) {
            return false;
        }
        \assert(null !== $this->prazoAte);
        $this->fechar($this->prazoAte, null, self::MOTIVO_DO_PRAZO);

        return true;
    }

    /** Aberta e (sem prazo ou antes do prazo). */
    public function aceitaVotoEm(\DateTimeImmutable $agora): bool
    {
        return EstadoDaPauta::Aberta === $this->estado
            && (null === $this->prazoAte || $agora < $this->prazoAte);
    }

    /** Aberta, com prazo, e o prazo já passou (o instante do prazo conta como vencido). */
    public function prazoVencidoEm(\DateTimeImmutable $agora): bool
    {
        return EstadoDaPauta::Aberta === $this->estado
            && null !== $this->prazoAte
            && $agora >= $this->prazoAte;
    }

    /** @internal Chamado só pelo construtor da OpcaoDaPauta, para a coleção em memória acompanhar o banco. */
    public function registrarOpcao(OpcaoDaPauta $opcao): void
    {
        if (!$this->opcoes->contains($opcao)) {
            $this->opcoes->add($opcao);
        }
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

    public function getOrdem(): int
    {
        return $this->ordem;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function getDescricao(): ?string
    {
        return $this->descricao;
    }

    public function getEstado(): EstadoDaPauta
    {
        return $this->estado;
    }

    public function getConvocacao(): ?int
    {
        return $this->convocacao;
    }

    public function getCriterio(): RegraDeDecisao
    {
        return $this->criterio;
    }

    public function getBase(): BaseDaDecisao
    {
        return $this->base;
    }

    /** Quantos dígitos tem o número de cada opção (1 a 5). */
    public function getDigitos(): int
    {
        return $this->digitos;
    }

    /** Quantos nomes a pauta elege (1 a 9); com mais de um, o eleitor escolhe até esse tanto. */
    public function getVagas(): int
    {
        return $this->vagas;
    }

    public function getQuorumPrimeira(): string
    {
        return $this->quorumPrimeira;
    }

    /** Nulo quando a 2ª convocação repete o quórum da 1ª. */
    public function getQuorumSegunda(): ?string
    {
        return $this->quorumSegunda;
    }

    /** Percentual do peso apto com duas casas ("50.00", "0.00"); null enquanto aguardando. */
    public function getQuorumExigido(): ?string
    {
        return $this->quorumExigido;
    }

    public function getAbertaEm(): ?\DateTimeImmutable
    {
        return $this->abertaEm;
    }

    public function getAbertaPorNome(): ?string
    {
        return $this->abertaPorNome;
    }

    public function getPrazoAte(): ?\DateTimeImmutable
    {
        return $this->prazoAte;
    }

    public function getEncerradaEm(): ?\DateTimeImmutable
    {
        return $this->encerradaEm;
    }

    /** Null quando a pauta foi encerrada pelo prazo. */
    public function getEncerradaPorNome(): ?string
    {
        return $this->encerradaPorNome;
    }

    public function getMotivoEncerramento(): ?string
    {
        return $this->motivoEncerramento;
    }

    /** @return Collection<int, OpcaoDaPauta> as opções de voto pelo número e, por último, a abstenção */
    public function getOpcoes(): Collection
    {
        return $this->opcoes;
    }

    /** @return list<OpcaoDaPauta> só as opções de voto (sem a abstenção), pelo número */
    public function getOpcoesDeVoto(): array
    {
        $opcoes = array_values($this->opcoes->filter(static fn (OpcaoDaPauta $o): bool => !$o->isAbstencao())->toArray());
        // Recém-criadas ainda não passaram pelo OrderBy do banco: ordena aqui também.
        usort($opcoes, static fn (OpcaoDaPauta $a, OpcaoDaPauta $b): int => $a->getNumero() <=> $b->getNumero());

        return $opcoes;
    }

    public function getNulosUnidades(): int
    {
        return $this->nulosUnidades;
    }

    public function getNulosPeso(): string
    {
        return $this->nulosPeso;
    }

    public function getAbstencao(): ?OpcaoDaPauta
    {
        foreach ($this->opcoes as $opcao) {
            if ($opcao->isAbstencao()) {
                return $opcao;
            }
        }

        return null;
    }

    private function fechar(\DateTimeImmutable $quando, ?string $nome, string $motivo): void
    {
        $this->estado = EstadoDaPauta::Encerrada;
        $this->encerradaEm = $quando;
        $this->encerradaPorNome = $nome;
        $this->motivoEncerramento = mb_substr($motivo, 0, 200);
    }

    private function exigirEstado(EstadoDaPauta $esperado, string $mensagem): void
    {
        if ($esperado !== $this->estado) {
            throw new OperacaoInvalidaNaAssembleiaException($mensagem);
        }
    }

    private function exigirPrazoNoFuturo(?\DateTimeImmutable $prazoAte, \DateTimeImmutable $agora): void
    {
        if (null !== $prazoAte && $prazoAte <= $agora) {
            throw new OperacaoInvalidaNaAssembleiaException('O prazo precisa ser depois de agora.');
        }
    }

    private function quorumDaConvocacao(int $convocacao): string
    {
        return match ($convocacao) {
            1 => $this->quorumPrimeira,
            2 => $this->quorumSegunda ?? $this->quorumPrimeira,
            default => throw new OperacaoInvalidaNaAssembleiaException('Escolha a 1ª ou a 2ª convocação.'),
        };
    }

    private static function exigirEntre(int $valor, int $minimo, int $maximo, string $mensagem): void
    {
        if ($valor < $minimo || $valor > $maximo) {
            throw new OperacaoInvalidaNaAssembleiaException($mensagem);
        }
    }

    private static function percentual(int $valor): string
    {
        return sprintf('%d.00', $valor);
    }

    private static function tituloValido(string $titulo): string
    {
        $titulo = trim($titulo);
        if ('' === $titulo) {
            throw new OperacaoInvalidaNaAssembleiaException('Informe o título da pauta.');
        }

        return mb_substr($titulo, 0, 200);
    }
}
