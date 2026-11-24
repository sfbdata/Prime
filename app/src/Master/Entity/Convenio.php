<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\DTO\DadosDoConvenio;
use App\Master\Enum\AbrangenciaDoConvenio;
use App\Master\Enum\BeneficioDoConvenio;
use App\Master\Enum\ProvaDoConvenio;
use App\Master\Exception\ConvenioRecusado;
use App\Master\Repository\ConvenioRepository;
use App\Master\Service\LocalizacaoDaPessoa;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um convênio do BlueJus com uma instituição (spec docs/specs/convenios.md, §3.1). GLOBAL, sem tenant, como o
 * {@see ConvenioDaUf}: é configuração da plataforma, mexida só pelo Master. Quem participa está em
 * {@see ConvenioVinculo}; a leitura para telas, cobrança e espaço é a {@see \App\Master\Service\ConveniosDoEscritorio}.
 *
 * A migration Version20261124120000 cria a linha `oab` (o Convênio OAB de hoje). As exceções por UF dele continuam em
 * `convenio_uf` (§3.3). As regras das CHECK da tabela são conferidas aqui também, para a recusa ter texto.
 *
 * Auditável: o AuditLogSubscriber grava cada mudança no escopo de plataforma.
 */
#[ORM\Entity(repositoryClass: ConvenioRepository::class)]
#[ORM\Table(name: 'convenio')]
#[ORM\UniqueConstraint(name: 'uniq_convenio_chave', columns: ['chave'])]
#[ORM\UniqueConstraint(name: 'uniq_convenio_codigo', columns: ['codigo_convite'], options: ['where' => '(codigo_convite IS NOT NULL)'])]
#[ORM\Index(name: 'idx_convenio_instituicao', columns: ['instituicao_chave'])]
#[ORM\Index(name: 'idx_convenio_alterado_por', columns: ['alterado_por_id'])]
class Convenio implements Auditavel
{
    public const CHAVE_OAB = 'oab';
    public const TAMANHO_DO_SELO = 40;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validoAte = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $ativo = true;

    /** Desligar com aviso prévio grava a data; no dia, `ativo` vira falso (§6.2). */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $encerraEm = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $motivo = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradoEm;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradoPor = null;

    public function __construct(
        #[ORM\Column(length: 40)]
        private string $chave,
        #[ORM\Column(length: 120)]
        private string $nome,
        #[ORM\Column(length: 120)]
        private string $instituicao,
        #[ORM\Column(length: 40)]
        private string $instituicaoChave,
        #[ORM\Column(length: 12, enumType: AbrangenciaDoConvenio::class)]
        private AbrangenciaDoConvenio $abrangencia,
        #[ORM\Column(length: 2, nullable: true)]
        private ?string $uf,
        #[ORM\Column(length: 10, enumType: ProvaDoConvenio::class)]
        private ProvaDoConvenio $prova,
        #[ORM\Column(length: 20, enumType: BeneficioDoConvenio::class)]
        private BeneficioDoConvenio $beneficio,
        #[ORM\Column(type: 'integer', options: ['default' => 0])]
        private int $beneficioValor,
        #[ORM\Column(length: self::TAMANHO_DO_SELO)]
        private string $textoSelo,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $validoDe,
        \DateTimeImmutable $em,
        #[ORM\Column(length: 16, nullable: true)]
        private ?string $codigoConvite = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        private ?int $limiteDeUsos = null,
        ?User $por = null,
    ) {
        $this->exigirValido();
        $this->criadoEm = $em;
        $this->alteradoEm = $em;
        $this->alteradoPor = $por;
    }

    /** Vale no dia: ligado e dentro da validade. O fim marcado (`encerraEm`) só vira desligado pela tarefa diária. */
    public function vigenteEm(\DateTimeImmutable $hoje): bool
    {
        $dia = $hoje->format('Y-m-d');

        return $this->ativo
            && $this->validoDe->format('Y-m-d') <= $dia
            && ($this->validoAte === null || $this->validoAte->format('Y-m-d') >= $dia);
    }

    public function ehOab(): bool
    {
        return $this->chave === self::CHAVE_OAB;
    }

    /** Convênio novo pelo Master (§7.3): prova por código de convite (D8), com o código já gerado. */
    public static function novoPorCodigo(string $chave, DadosDoConvenio $dados, string $codigo, ?User $por, \DateTimeImmutable $em): self
    {
        $convenio = new self($chave, $dados->nome, $dados->instituicao, $dados->instituicaoChave, $dados->abrangencia, $dados->uf, ProvaDoConvenio::Codigo, $dados->beneficio, $dados->beneficioValor, $dados->textoSelo, $dados->validoDe, $em, $codigo, $dados->limiteDeUsos, $por);
        $convenio->validoAte = $dados->validoAte;
        $convenio->exigirValidade();

        return $convenio;
    }

    /**
     * Muda o que o Master pode mudar (§7.3). `chave` e `prova` ficam. No convênio OAB, a abrangência e o benefício
     * também ficam: as exceções por estado moram no painel "Convênio OAB por estado".
     */
    public function editar(DadosDoConvenio $dados, ?User $por, \DateTimeImmutable $em): void
    {
        if ($this->ehOab() && ($dados->abrangencia !== $this->abrangencia || $dados->beneficio !== $this->beneficio || $dados->beneficioValor !== $this->beneficioValor)) {
            throw new ConvenioRecusado('No convênio OAB, a abrangência e o benefício não mudam por aqui.');
        }
        [$this->nome, $this->instituicao, $this->instituicaoChave, $this->abrangencia, $this->uf] = [$dados->nome, $dados->instituicao, $dados->instituicaoChave, $dados->abrangencia, $dados->uf];
        [$this->beneficio, $this->beneficioValor, $this->textoSelo] = [$dados->beneficio, $dados->beneficioValor, $dados->textoSelo];
        [$this->validoDe, $this->validoAte, $this->limiteDeUsos] = [$dados->validoDe, $dados->validoAte, $dados->limiteDeUsos];
        $this->exigirValido();
        $this->exigirValidade();
        $this->alterado($por, $em);
    }

    /** "Gerar outro" (§7.3): o código anterior deixa de valer para novos usos. */
    public function trocarCodigo(string $codigo, ?User $por, \DateTimeImmutable $em): void
    {
        if ($this->prova !== ProvaDoConvenio::Codigo) {
            throw new ConvenioRecusado('O código de convite vale só para convênio com prova por código.');
        }
        $this->codigoConvite = $codigo;
        $this->alterado($por, $em);
    }

    /** Desligar com aviso prévio (§6.2): marca o dia do fim; no dia, a tarefa diária desliga. */
    public function marcarFim(\DateTimeImmutable $dia, ?string $motivo, ?User $por, \DateTimeImmutable $em): void
    {
        if (!$this->ativo) {
            throw new ConvenioRecusado('Este convênio já está desligado.');
        }
        if ($motivo !== null && mb_strlen($motivo) > 200) {
            throw new ConvenioRecusado('O motivo tem até 200 caracteres.');
        }
        $this->encerraEm = $dia;
        $this->motivo = $motivo;
        $this->alterado($por, $em);
    }

    /** Religar antes do dia cancela o fim (§6.2, item 5). Devolve se havia fim marcado. */
    public function cancelarFim(?User $por, \DateTimeImmutable $em): bool
    {
        if (!$this->ativo || $this->encerraEm === null) {
            return false;
        }
        $this->encerraEm = null;
        $this->motivo = null;
        $this->alterado($por, $em);

        return true;
    }

    /** O dia do fim chegou (ou o Master desligou na hora um convênio só de gratuidade). */
    public function desligar(?User $por, \DateTimeImmutable $em): void
    {
        $this->ativo = false;
        $this->alterado($por, $em);
    }

    /** Religa um convênio desligado. Os vínculos voltam pela sincronização (OAB) ou por código novo. */
    public function ligar(?User $por, \DateTimeImmutable $em): void
    {
        $this->ativo = true;
        $this->encerraEm = null;
        $this->motivo = null;
        $this->alterado($por, $em);
    }

    /** Ligado com o dia do fim já chegado: a tarefa diária desliga. */
    public function fimChegouEm(\DateTimeImmutable $hoje): bool
    {
        return $this->ativo && $this->encerraEm !== null && $this->encerraEm->format('Y-m-d') <= $hoje->format('Y-m-d');
    }

    private function alterado(?User $por, \DateTimeImmutable $em): void
    {
        $this->alteradoPor = $por;
        $this->alteradoEm = $em;
    }

    private function exigirValidade(): void
    {
        if ($this->validoAte !== null && $this->validoAte->format('Y-m-d') < $this->validoDe->format('Y-m-d')) {
            throw new ConvenioRecusado('A validade termina antes de começar.');
        }
        if ($this->limiteDeUsos !== null && $this->limiteDeUsos < 1) {
            throw new ConvenioRecusado('O limite de usos é de 1 para cima.');
        }
    }

    private function exigirValido(): void
    {
        $this->exigirTextos();
        if (($this->abrangencia === AbrangenciaDoConvenio::Uf) !== ($this->uf !== null)) {
            throw new ConvenioRecusado('Informe o estado só quando o convênio vale para um estado.');
        }
        if ($this->uf !== null && LocalizacaoDaPessoa::uf($this->uf) !== $this->uf) {
            throw new ConvenioRecusado('Estado inválido.');
        }
        if ($this->beneficioValor < 0 || ($this->beneficio === BeneficioDoConvenio::DescontoPercentual && ($this->beneficioValor < 1 || $this->beneficioValor > 100))) {
            throw new ConvenioRecusado('Valor do benefício inválido.');
        }
        if (($this->prova === ProvaDoConvenio::Codigo) !== ($this->codigoConvite !== null)) {
            throw new ConvenioRecusado('O código de convite vale só para convênio com prova por código.');
        }
    }

    private function exigirTextos(): void
    {
        foreach ([$this->chave, $this->nome, $this->instituicao, $this->instituicaoChave, $this->textoSelo] as $texto) {
            if (trim($texto) === '') {
                throw new ConvenioRecusado('Preencha nome, instituição e selo do convênio.');
            }
        }
        if (mb_strlen($this->textoSelo) > self::TAMANHO_DO_SELO) {
            throw new ConvenioRecusado('O selo tem até 40 caracteres.');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChave(): string
    {
        return $this->chave;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getInstituicao(): string
    {
        return $this->instituicao;
    }

    public function getInstituicaoChave(): string
    {
        return $this->instituicaoChave;
    }

    public function getAbrangencia(): AbrangenciaDoConvenio
    {
        return $this->abrangencia;
    }

    public function getUf(): ?string
    {
        return $this->uf;
    }

    public function getProva(): ProvaDoConvenio
    {
        return $this->prova;
    }

    public function getBeneficio(): BeneficioDoConvenio
    {
        return $this->beneficio;
    }

    public function getBeneficioValor(): int
    {
        return $this->beneficioValor;
    }

    public function getTextoSelo(): string
    {
        return $this->textoSelo;
    }

    public function getCodigoConvite(): ?string
    {
        return $this->codigoConvite;
    }

    public function getLimiteDeUsos(): ?int
    {
        return $this->limiteDeUsos;
    }

    public function getValidoDe(): \DateTimeImmutable
    {
        return $this->validoDe;
    }

    public function getValidoAte(): ?\DateTimeImmutable
    {
        return $this->validoAte;
    }

    public function isAtivo(): bool
    {
        return $this->ativo;
    }

    public function getEncerraEm(): ?\DateTimeImmutable
    {
        return $this->encerraEm;
    }

    public function getMotivo(): ?string
    {
        return $this->motivo;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAlteradoEm(): \DateTimeImmutable
    {
        return $this->alteradoEm;
    }

    public function getAlteradoPor(): ?User
    {
        return $this->alteradoPor;
    }
}
