<?php

declare(strict_types=1);

namespace App\Voto\Entity;

use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use App\Voto\Enum\CenarioDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Enum\TipoDaPauta;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Assembleia de condomínio (spec docs/specs/voto-assembleia-condominio.md, seções 1 e 3): o EVENTO. Cada pauta é uma
 * votação com estado próprio (PautaDaAssembleia); a lista de unidades é da assembleia.
 *
 * Rascunho: pautas e unidades mudam. Publicada: as pautas travam, a mesa abre e encerra cada uma, e as unidades só
 * mudam enquanto nenhuma pauta saiu de "aguardando" ("a lista bloqueia na abertura").
 *
 * É `Auditavel` (quem criou, editou e publicou fica no audit_log). As tabelas do voto em si (opção e participação) não
 * são: o sigilo vem antes da trilha (seção 2).
 *
 * Mora no schema `voto`; a FK de tenant com ON DELETE CASCADE é a mesma exceção da `voto.liberacao`, e a purga do
 * escritório apaga a linha pela ORDEM_DELECAO.
 */
#[ORM\Entity(repositoryClass: AssembleiaRepository::class)]
#[ORM\Table(name: 'assembleia', schema: 'voto')]
#[ORM\UniqueConstraint(name: 'uniq_voto_assembleia_codigo_publico', columns: ['codigo_publico'])]
class Assembleia implements TenantAware, Auditavel
{
    /** Quantas unidades sem fração a recusa nomeia; as demais viram "e mais N". */
    private const MAXIMO_DE_UNIDADES_NA_MENSAGEM = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $codigoPublico;

    #[ORM\Column(length: 12, enumType: EstadoDaAssembleia::class)]
    private EstadoDaAssembleia $estado = EstadoDaAssembleia::Rascunho;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publicadaEm = null;

    /** Arquivada: sai das listas (Votar, Resultados, Acompanhar, telão), mas dados e cédulas ficam, sem prazo de purga. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $arquivadaEm = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $arquivadaMotivo = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $arquivadaPorNome = null;

    /** Nome da entidade (o condomínio) que o painel usa para agrupar as assembleias. Opcional; fora das regras e do hash. */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $entidadeNome = null;

    /** CNPJ da entidade, só os 14 dígitos (a máscara é da exibição). Opcional: separa entidades com nome parecido. */
    #[ORM\Column(length: 14, nullable: true)]
    private ?string $cnpj = null;

    /**
     * Simulação (desenho 18, "Votação de simulação"): treino da mesa e teste do link. Marca só em rascunho; pode ser
     * reiniciada e apagada, não se arquiva e fica fora das estatísticas. Entra no hash da configuração só quando verdadeira.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $simulacao = false;

    /** @var Collection<int, PautaDaAssembleia> */
    #[ORM\OneToMany(mappedBy: 'assembleia', targetEntity: PautaDaAssembleia::class, cascade: ['persist'])]
    #[ORM\OrderBy(['ordem' => 'ASC', 'id' => 'ASC'])]
    private Collection $pautas;

    /** @var Collection<int, UnidadeDaAssembleia> */
    #[ORM\OneToMany(mappedBy: 'assembleia', targetEntity: UnidadeDaAssembleia::class, cascade: ['persist'])]
    #[ORM\OrderBy(['bloco' => 'ASC', 'identificacao' => 'ASC', 'id' => 'ASC'])]
    private Collection $unidades;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\Column(length: 160)]
        private string $titulo,
        #[ORM\Column(length: 10, enumType: PesoDoVoto::class)]
        private PesoDoVoto $peso,
        #[ORM\Column(length: 180)]
        private string $criadaPorNome,
        /** Tipo de entidade (rótulos dos documentos); só em rascunho. Padrão condomínio. */
        #[ORM\Column(length: 12, enumType: CenarioDaAssembleia::class, options: ['default' => 'condominio'])]
        private CenarioDaAssembleia $cenario = CenarioDaAssembleia::Condominio,
    ) {
        $this->titulo = self::tituloValido($titulo);
        $this->codigoPublico = bin2hex(random_bytes(16));
        $this->criadaEm = new \DateTimeImmutable();
        $this->pautas = new ArrayCollection();
        $this->unidades = new ArrayCollection();
    }

    /**
     * Título e peso do voto só mudam em rascunho. Com peso por fração ideal, toda unidade apta precisa de fração (uma
     * apta sem fração votaria com peso zero sem ninguém perceber).
     */
    public function editar(string $titulo, PesoDoVoto $peso): void
    {
        $this->exigirPautasAlteraveis();
        $titulo = self::tituloValido($titulo);
        $this->exigirFracaoNasAptas($peso);
        $this->titulo = $titulo;
        $this->peso = $peso;
    }

    /**
     * Nome da entidade e CNPJ (só dígitos, 14), ambos opcionais; só em rascunho, como o título. A validação dos dígitos
     * verificadores é do UseCase; aqui só o formato, para a coluna nunca guardar máscara.
     */
    public function identificarEntidade(?string $nome, ?string $cnpj): void
    {
        $this->exigirPautasAlteraveis();
        $nome = null === $nome ? '' : trim($nome);
        $cnpj = null === $cnpj ? '' : $cnpj;
        if ('' !== $cnpj && 1 !== preg_match('/^\d{14}$/', $cnpj)) {
            throw new OperacaoInvalidaNaAssembleiaException('O CNPJ tem 14 dígitos.');
        }
        $this->entidadeNome = '' === $nome ? null : mb_substr($nome, 0, 160);
        $this->cnpj = '' === $cnpj ? null : $cnpj;
    }

    /** O cenário só muda em rascunho, como o título; define rótulos e não entra nas regras nem no hash. */
    public function definirCenario(CenarioDaAssembleia $cenario): void
    {
        $this->exigirPautasAlteraveis();
        $this->cenario = $cenario;
    }

    /** A marca de simulação só muda em rascunho, como o título: depois de publicada, a votação não vira real nem simulação. */
    public function definirSimulacao(bool $simulacao): void
    {
        $this->exigirPautasAlteraveis();
        $this->simulacao = $simulacao;
    }

    public function isSimulacao(): bool
    {
        return $this->simulacao;
    }

    /** Só simulação se reinicia ou se apaga: a votação real nunca perde voto. */
    public function exigirSimulacao(string $mensagem): void
    {
        if (!$this->simulacao) {
            throw new OperacaoInvalidaNaAssembleiaException($mensagem);
        }
    }

    /**
     * Volta a simulação ao estado de antes de publicar: rascunho, pautas aguardando e acesso das unidades limpo. Os votos,
     * as participações e os contadores saem por SQL no UseCase (mesma transação). Pautas, opções e lista ficam.
     * O acesso é novo: código público da assembleia e semente e token de cada unidade mudam, então os links do teste
     * nunca valem numa votação real, mesmo se a marca for desmarcada depois.
     *
     * @param \Closure(): array{string, string} $novoAcesso devolve [semente, tokenHash] (TokenDaUnidade)
     */
    public function reiniciarSimulacao(\Closure $novoAcesso): void
    {
        $this->exigirSimulacao('Só votação de simulação pode ser reiniciada.');
        $this->estado = EstadoDaAssembleia::Rascunho;
        $this->publicadaEm = null;
        $this->arquivadaEm = null;
        $this->arquivadaMotivo = null;
        $this->arquivadaPorNome = null;
        $this->codigoPublico = bin2hex(random_bytes(16));
        foreach ($this->pautas as $pauta) {
            $pauta->reiniciar();
        }
        foreach ($this->unidades as $unidade) {
            [$semente, $tokenHash] = $novoAcesso();
            $unidade->reiniciarAcesso($semente, $tokenHash);
        }
    }

    /**
     * Rascunho vira publicada e as pautas travam. Exige ao menos uma pauta, cada pauta com duas opções de voto (a
     * abstenção não conta), ao menos uma unidade apta e, com peso por fração ideal, a fração de toda unidade apta.
     */
    public function publicar(?\DateTimeImmutable $agora = null): void
    {
        $this->exigirPublicacaoPossivel();
        $this->estado = EstadoDaAssembleia::Publicada;
        $this->publicadaEm = $agora ?? new \DateTimeImmutable();
    }

    /** A revisão e a confirmação usam a mesma regra, sem mudar o estado durante a consulta. */
    public function exigirPublicacaoPossivel(): void
    {
        if (EstadoDaAssembleia::Rascunho !== $this->estado) {
            throw new OperacaoInvalidaNaAssembleiaException('A assembleia já foi publicada.');
        }
        $this->exigirPautasCompletas();
        if (!$this->temUnidadeApta()) {
            throw new OperacaoInvalidaNaAssembleiaException('Inclua ao menos uma unidade apta a votar.');
        }
        $this->exigirFracaoNasAptas($this->peso);
    }

    /**
     * Guarda a assembleia publicada fora das listas. Só sem pauta aberta (suspensa também conta como aberta): a mesa
     * encerra antes. Pauta encerrada já está apurada (o resultado sai no encerramento, sem guardiões), cancelada ou
     * não. Rascunho não se arquiva: pode ser excluído. Motivo com 5 ou mais caracteres.
     */
    public function arquivar(string $motivo, string $por, ?\DateTimeImmutable $agora = null): void
    {
        if ($this->arquivada()) {
            throw new OperacaoInvalidaNaAssembleiaException('Votação já arquivada.');
        }
        if ($this->simulacao) {
            throw new OperacaoInvalidaNaAssembleiaException('Simulação não se arquiva: apague ou reinicie.');
        }
        if (EstadoDaAssembleia::Publicada !== $this->estado) {
            throw new OperacaoInvalidaNaAssembleiaException('Rascunho não se arquiva: pode ser excluído.');
        }
        foreach ($this->pautas as $pauta) {
            if (EstadoDaPauta::Aberta === $pauta->getEstado()) {
                throw new OperacaoInvalidaNaAssembleiaException('Encerre a votação antes de arquivar.');
            }
        }
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < PautaDaAssembleia::MOTIVO_MINIMO) {
            throw new OperacaoInvalidaNaAssembleiaException('Informe o motivo do arquivamento.');
        }
        $this->arquivadaEm = $agora ?? new \DateTimeImmutable();
        $this->arquivadaMotivo = mb_substr($motivo, 0, 200);
        $this->arquivadaPorNome = mb_substr($por, 0, 180);
    }

    /** Volta a assembleia arquivada para as listas (quem desarquivou fica na trilha de auditoria). */
    public function desarquivar(string $por): void
    {
        if (!$this->arquivada()) {
            throw new OperacaoInvalidaNaAssembleiaException('Votação não está arquivada.');
        }
        $this->arquivadaEm = null;
        $this->arquivadaMotivo = null;
        $this->arquivadaPorNome = null;
    }

    public function arquivada(): bool
    {
        return null !== $this->arquivadaEm;
    }

    /** @internal Usada pelas regras da pauta: assembleia arquivada não abre nem retira pauta. */
    public function exigirNaoArquivada(string $mensagem): void
    {
        if ($this->arquivada()) {
            throw new OperacaoInvalidaNaAssembleiaException($mensagem);
        }
    }

    /** Só o rascunho nunca publicado pode ser excluído; a publicada pode ser arquivada. */
    public function exigirExclusaoPossivel(): void
    {
        if (EstadoDaAssembleia::Rascunho !== $this->estado || null !== $this->publicadaEm) {
            throw new OperacaoInvalidaNaAssembleiaException('Só rascunho nunca publicado pode ser excluído. Votação publicada pode ser arquivada.');
        }
    }

    public function getArquivadaEm(): ?\DateTimeImmutable
    {
        return $this->arquivadaEm;
    }

    public function getArquivadaMotivo(): ?string
    {
        return $this->arquivadaMotivo;
    }

    public function getArquivadaPorNome(): ?string
    {
        return $this->arquivadaPorNome;
    }

    public function podeAlterarPautas(): bool
    {
        return EstadoDaAssembleia::Rascunho === $this->estado;
    }

    public function getCodigoPublico(): string
    {
        return $this->codigoPublico;
    }

    /** A lista de unidades muda até a primeira pauta ser aberta. */
    public function podeAlterarUnidades(): bool
    {
        foreach ($this->pautas as $pauta) {
            if (EstadoDaPauta::Aguardando !== $pauta->getEstado()) {
                return false;
            }
        }

        return true;
    }

    public function exigirPautasAlteraveis(): void
    {
        if (!$this->podeAlterarPautas()) {
            throw new OperacaoInvalidaNaAssembleiaException('As pautas só mudam enquanto a assembleia está em rascunho.');
        }
    }

    /** A equipe própria só muda em rascunho (spec voto-equipe-e-master-1611, seção 1.2). */
    public function exigirEquipeAlteravel(): void
    {
        if (!$this->podeAlterarPautas()) {
            throw new OperacaoInvalidaNaAssembleiaException('A equipe só muda enquanto a assembleia está em rascunho.');
        }
    }

    public function exigirUnidadesAlteraveis(): void
    {
        if (!$this->podeAlterarUnidades()) {
            throw new OperacaoInvalidaNaAssembleiaException('A lista fica bloqueada depois da abertura da votação.');
        }
    }

    /** @internal Chamado só pelo construtor da PautaDaAssembleia, para a coleção em memória acompanhar o banco. */
    public function registrarPauta(PautaDaAssembleia $pauta): void
    {
        if (!$this->pautas->contains($pauta)) {
            $this->pautas->add($pauta);
        }
    }

    /** @internal Chamado só pelo construtor da UnidadeDaAssembleia, para a coleção em memória acompanhar o banco. */
    public function registrarUnidade(UnidadeDaAssembleia $unidade): void
    {
        if (!$this->unidades->contains($unidade)) {
            $this->unidades->add($unidade);
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

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function getPeso(): PesoDoVoto
    {
        return $this->peso;
    }

    public function getEstado(): EstadoDaAssembleia
    {
        return $this->estado;
    }

    public function getEntidadeNome(): ?string
    {
        return $this->entidadeNome;
    }

    public function getCenario(): CenarioDaAssembleia
    {
        return $this->cenario;
    }

    public function getCnpj(): ?string
    {
        return $this->cnpj;
    }

    public function getCriadaPorNome(): string
    {
        return $this->criadaPorNome;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getPublicadaEm(): ?\DateTimeImmutable
    {
        return $this->publicadaEm;
    }

    /** @return Collection<int, PautaDaAssembleia> pela ordem */
    public function getPautas(): Collection
    {
        return $this->pautas;
    }

    /** @return Collection<int, UnidadeDaAssembleia> por bloco e identificação */
    public function getUnidades(): Collection
    {
        return $this->unidades;
    }

    private function exigirPautasCompletas(): void
    {
        if ($this->pautas->isEmpty()) {
            throw new OperacaoInvalidaNaAssembleiaException('Inclua ao menos uma pauta.');
        }
        foreach ($this->pautas as $pauta) {
            if (TipoDaPauta::EleicaoDeCargo === $pauta->getTipo()) {
                $pauta->exigirCandidaturasParaPublicacao();
            } elseif (\count($pauta->getOpcoesDeVoto()) < 2) {
                throw new OperacaoInvalidaNaAssembleiaException(sprintf('A pauta "%s" precisa de ao menos duas opções.', $pauta->getTitulo()));
            }
            if ($pauta->getVagas() > \count($pauta->getOpcoesDeVoto())) {
                throw new OperacaoInvalidaNaAssembleiaException(sprintf('A pauta "%s" tem mais vagas (%d) do que candidaturas.', $pauta->getTitulo(), $pauta->getVagas()));
            }
        }
    }

    /** Com peso por fração ideal, recusa se alguma unidade apta está sem fração ou com fração zero. */
    private function exigirFracaoNasAptas(PesoDoVoto $peso): void
    {
        if (PesoDoVoto::Fracao !== $peso) {
            return;
        }
        $semFracao = [];
        foreach ($this->unidades as $unidade) {
            if ($unidade->isApta() && bccomp($unidade->getFracao() ?? '0', '0', 6) <= 0) {
                $semFracao[] = '' === $unidade->getBloco() ? $unidade->getIdentificacao() : $unidade->getBloco() . ' · ' . $unidade->getIdentificacao();
            }
        }
        if ([] === $semFracao) {
            return;
        }
        $alem = \count($semFracao) - self::MAXIMO_DE_UNIDADES_NA_MENSAGEM;
        throw new OperacaoInvalidaNaAssembleiaException(sprintf(
            'Com peso por fração ideal, toda unidade apta precisa de fração. Falta em: %s%s.',
            implode(', ', \array_slice($semFracao, 0, self::MAXIMO_DE_UNIDADES_NA_MENSAGEM)),
            $alem > 0 ? sprintf(' e mais %d', $alem) : '',
        ));
    }

    private function temUnidadeApta(): bool
    {
        foreach ($this->unidades as $unidade) {
            if ($unidade->isApta()) {
                return true;
            }
        }

        return false;
    }

    private static function tituloValido(string $titulo): string
    {
        $titulo = trim($titulo);
        if ('' === $titulo) {
            throw new OperacaoInvalidaNaAssembleiaException('Informe o título da assembleia.');
        }

        return mb_substr($titulo, 0, 160);
    }
}
