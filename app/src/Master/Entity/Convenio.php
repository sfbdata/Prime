<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
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
