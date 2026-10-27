<?php

namespace App\Entity\Tarefa;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\TarefaMensagemRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tarefa\Exception\DespachoRegistradoException;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mensagem da meta, que a tela chama de despacho (desenho 04B de 22/10; spec `minhas-metas-esteira-2210.md`, Fase 2).
 * Desde a Fase 2a o despacho guarda natureza, prazo de cumprimento, ciência, certidão e o código do registro (D1,
 * C13). As mensagens antigas ficam sem código: a tela não mostra a linha de registro nelas.
 */
#[ORM\Entity(repositoryClass: TarefaMensagemRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_tarefa_mensagem_tenant_codigo', columns: ['tenant_id', 'codigo'])]
class TarefaMensagem implements Auditavel, TenantAware
{
    /** Prefixo da referência ao documento da pasta em `arquivoAnexo` (C4): `pasta_documento:<id>`. */
    public const PREFIXO_DOCUMENTO_DA_PASTA = 'pasta_documento:';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tarefa::class, inversedBy: 'mensagens')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tarefa $tarefa = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $usuario = null;

    #[ORM\Column(type: 'text')]
    private string $mensagem;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $arquivoAnexo = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(name: 'editado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $editadoEm = null;

    #[ORM\Column(length: 20, nullable: true, enumType: NaturezaDoDespacho::class)]
    private ?NaturezaDoDespacho $natureza = null;

    #[ORM\Column(name: 'prazo_cumprimento', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $prazoCumprimento = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'ciente_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $cientePor = null;

    #[ORM\Column(name: 'ciente_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cienteEm = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $certidao = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'certificado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $certificadoPor = null;

    #[ORM\Column(name: 'certificado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $certificadoEm = null;

    /** Código do registro (D1): `XXXX.XXXX`, sorteado e único no escritório. Nulo nas mensagens antigas. */
    #[ORM\Column(length: 9, nullable: true)]
    private ?string $codigo = null;

    /** Quem assinou o registro, congelado no momento do despacho (troca de nome ou de cargo não muda o registro). */
    #[ORM\Column(length: 20, nullable: true, enumType: AcaoDoDespacho::class)]
    private ?AcaoDoDespacho $acao = null;

    #[ORM\Column(name: 'registrado_por_nome', length: 180, nullable: true)]
    private ?string $registradoPorNome = null;

    #[ORM\Column(name: 'registrado_por_cargo', length: 120, nullable: true)]
    private ?string $registradoPorCargo = null;

    /**
     * Para quem o ato foi (o "quem → para" do desenho 04B L161): o novo responsável na passagem de etapa e quem
     * recebe a meta na devolução. No despacho comum o "para" sai das @menções do texto.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'destinatario_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $destinatario = null;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTarefa(): ?Tarefa
    {
        return $this->tarefa;
    }

    public function setTarefa(?Tarefa $tarefa): self
    {
        $this->tarefa = $tarefa;
        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getUsuario(): ?User
    {
        return $this->usuario;
    }

    public function setUsuario(?User $usuario): self
    {
        $this->usuario = $usuario;
        return $this;
    }

    public function getMensagem(): string
    {
        return $this->mensagem;
    }

    /** Despacho registrado não muda de texto (D1, C16): nem pela tela, nem pelo desfazer da auditoria. */
    public function setMensagem(string $mensagem): self
    {
        $this->exigirNaoRegistrado();
        $this->mensagem = $mensagem;
        return $this;
    }

    public function getArquivoAnexo(): ?string
    {
        return $this->arquivoAnexo;
    }

    public function setArquivoAnexo(?string $arquivoAnexo): self
    {
        $this->arquivoAnexo = $arquivoAnexo;
        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function setCriadoEm(\DateTimeImmutable $criadoEm): self
    {
        $this->criadoEm = $criadoEm;
        return $this;
    }

    public function getEditadoEm(): ?\DateTimeImmutable
    {
        return $this->editadoEm;
    }

    public function setEditadoEm(?\DateTimeImmutable $editadoEm): self
    {
        $this->editadoEm = $editadoEm;
        return $this;
    }

    public function getNatureza(): ?NaturezaDoDespacho
    {
        return $this->natureza;
    }

    public function setNatureza(?NaturezaDoDespacho $natureza): self
    {
        $this->exigirNaoRegistrado();
        $this->natureza = $natureza;

        return $this;
    }

    public function getPrazoCumprimento(): ?\DateTimeImmutable
    {
        return $this->prazoCumprimento;
    }

    public function setPrazoCumprimento(?\DateTimeImmutable $prazoCumprimento): self
    {
        $this->exigirNaoRegistrado();
        $this->prazoCumprimento = $prazoCumprimento?->setTime(0, 0);

        return $this;
    }

    public function getCodigo(): ?string
    {
        return $this->codigo;
    }

    /**
     * Registro do despacho (D1): o código e quem assinou, com nome e cargo congelados agora. É dado uma vez só:
     * trocar o de um despacho já registrado invalidaria o que foi conferido. O `DespacharNaMetaUseCase` grava o
     * código direto no banco ({@see \App\Repository\TarefaMensagemRepository::gravarCodigoSeLivre()}), para
     * a colisão no índice único ser tratada; aqui é o mesmo registro em memória.
     */
    public function registrar(string $codigo, string $nome, ?string $cargo): self
    {
        if ($this->codigo !== null) {
            throw new \LogicException('Este despacho já tem código de registro.');
        }
        if (preg_match('/^\d{4}\.\d{4}$/', $codigo) !== 1) {
            throw new \InvalidArgumentException('Código de registro fora do formato XXXX.XXXX.');
        }

        $this->registrarAutoria($nome, $cargo);
        $this->codigo = $codigo;

        return $this;
    }

    /** Nome e cargo de quem despacha, congelados antes do código. */
    public function registrarAutoria(string $nome, ?string $cargo): self
    {
        $this->exigirNaoRegistrado();
        $this->registradoPorNome  = mb_substr(trim($nome), 0, 180);
        $cargo                    = trim((string) $cargo);
        $this->registradoPorCargo = $cargo === '' ? null : mb_substr($cargo, 0, 120);

        return $this;
    }

    public function getDestinatario(): ?User
    {
        return $this->destinatario;
    }

    public function setDestinatario(?User $destinatario): self
    {
        $this->exigirNaoRegistrado();
        $this->destinatario = $destinatario;

        return $this;
    }

    public function getAcao(): ?AcaoDoDespacho
    {
        return $this->acao;
    }

    public function setAcao(?AcaoDoDespacho $acao): self
    {
        $this->exigirNaoRegistrado();
        $this->acao = $acao;

        return $this;
    }

    private function exigirNaoRegistrado(): void
    {
        if ($this->codigo !== null) {
            throw DespachoRegistradoException::imutavel();
        }
    }

    public function getRegistradoPorNome(): ?string
    {
        return $this->registradoPorNome;
    }

    public function getRegistradoPorCargo(): ?string
    {
        return $this->registradoPorCargo;
    }

    /** Despacho registrado (com código), lembrete e redistribuição não se editam; mensagem antiga, sem código, continua editável. */
    public function podeSerEditado(): bool
    {
        return $this->codigo === null && !in_array($this->acao, [AcaoDoDespacho::Lembrete, AcaoDoDespacho::Redistribuicao], true);
    }

    /**
     * Anexo do despacho juntado aos documentos da pasta (C4, desenho 04B L145): a mensagem guarda só a referência ao
     * documento, no lugar do caminho que as mensagens antigas guardam. O arquivo é do documento, pela regra de chaves.
     */
    public function juntarDocumentoDaPasta(int $documentoId): self
    {
        $this->arquivoAnexo = self::PREFIXO_DOCUMENTO_DA_PASTA . $documentoId;

        return $this;
    }

    /** O id do documento da pasta juntado por este despacho; nulo na mensagem sem anexo ou com anexo antigo. */
    public function getDocumentoJuntadoId(): ?int
    {
        $anexo = (string) $this->arquivoAnexo;
        if (!str_starts_with($anexo, self::PREFIXO_DOCUMENTO_DA_PASTA)) {
            return null;
        }
        $id = substr($anexo, strlen(self::PREFIXO_DOCUMENTO_DA_PASTA));

        return ctype_digit($id) ? (int) $id : null;
    }

    public function getCientePor(): ?User
    {
        return $this->cientePor;
    }

    public function getCienteEm(): ?\DateTimeImmutable
    {
        return $this->cienteEm;
    }

    public function temCiencia(): bool
    {
        return $this->cienteEm !== null;
    }

    /**
     * Ciência automática (C14) em memória: a primeira vale. A gravação concorrente é o UPDATE condicional de
     * {@see \App\Repository\TarefaMensagemRepository::darCienciaSeAinda()}.
     */
    public function darCiencia(User $quem, \DateTimeImmutable $quando): void
    {
        if ($this->cienteEm !== null) {
            return;
        }

        $this->cientePor = $quem;
        $this->cienteEm  = $quando;
    }

    public function getCertidao(): ?string
    {
        return $this->certidao;
    }

    public function getCertificadoPor(): ?User
    {
        return $this->certificadoPor;
    }

    public function getCertificadoEm(): ?\DateTimeImmutable
    {
        return $this->certificadoEm;
    }

    public function temCertidao(): bool
    {
        return $this->certidao !== null;
    }

    /**
     * Certidão de cumprimento (C15) em memória: uma por despacho. A gravação concorrente é o UPDATE condicional de
     * {@see \App\Repository\TarefaMensagemRepository::certificarSeAinda()}.
     */
    public function certificar(User $quem, string $texto, \DateTimeImmutable $quando): void
    {
        if ($this->certidao !== null) {
            throw new \LogicException('Este despacho já tem certidão.');
        }

        $this->certidao       = $texto;
        $this->certificadoPor = $quem;
        $this->certificadoEm  = $quando;
    }
}
