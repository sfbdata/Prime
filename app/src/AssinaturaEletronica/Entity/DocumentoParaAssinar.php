<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Entity;

use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\Enum\CorDaLinha;
use App\AssinaturaEletronica\Enum\EstadoDaPagina;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\SituacaoDoSignatario;
use App\AssinaturaEletronica\Enum\TipoDeLogo;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\Descartavel;
use App\Shared\Contract\TenantAware;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um documento da tela Assinatura (contrato da etapa 1, seção 3.2). Nasce rascunho; só o rascunho aceita alteração.
 * Os blocos chegam montados pelo navegador e já validados ({@see \App\AssinaturaEletronica\Service\ValidadorDeBlocos}).
 *
 * Lixeira (desenho): mover cancela o envio que aguardava assinatura; restaurar não o reativa. Só rascunho na Lixeira
 * sai de vez (os enviados ficam para preservar as evidências).
 *
 * Etapa 2 (contrato da etapa 2, seção 4.1): enviar congela o conteúdo (Rascunho vira Aguardando, com o hash do conteúdo
 * canônico e o prazo contado da hora do envio); cada signatário assina na vez dele (com "em ordem", depois de todos os
 * anteriores) e o último conclui; a recusa de um encerra o documento; cancelar o envio para os links. Prazo vencido não
 * muda a situação: é calculado. Quem chama decide com a linha travada e relida.
 */
#[ORM\Entity(repositoryClass: DocumentoParaAssinarRepository::class)]
#[ORM\Table(name: 'assinatura_eletronica_documento')]
class DocumentoParaAssinar implements TenantAware, Descartavel, Auditavel
{
    public const NOME_PADRAO = 'Documento';
    public const NOME_MAXIMO = 200;
    public const PRAZO_PADRAO = 7;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'criado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $criadoPor;

    #[ORM\Column(length: 200)]
    private string $nome = self::NOME_PADRAO;

    #[ORM\Column(name: 'pasta_rotulo', length: 200, nullable: true)]
    private ?string $pastaRotulo = null;

    #[ORM\Column(name: 'modelo_chave', length: 60, nullable: true)]
    private ?string $modeloChave = null;

    #[ORM\Column(length: 80)]
    private string $categoria = '';

    /** @var list<array<string, int|string>> */
    #[ORM\Column(type: 'json')]
    private array $blocos = [];

    #[ORM\Column(length: 10)]
    private string $layout = '';

    // "lateral" é palavra reservada no Postgres: o nome vai entre crases para o Doctrine pôr aspas.
    #[ORM\Column(name: '`lateral`', type: 'text', nullable: true)]
    private ?string $lateral = null;

    #[ORM\Column(name: 'folha_branca')]
    private bool $folhaBranca = false;

    #[ORM\Column(name: 'logo_tipo', length: 20, enumType: TipoDeLogo::class)]
    private TipoDeLogo $logoTipo = TipoDeLogo::Bluejus;

    #[ORM\Column(name: 'logo_chave', length: 255, nullable: true)]
    private ?string $logoChave = null;

    #[ORM\Column(name: 'em_ordem')]
    private bool $emOrdem = false;

    #[ORM\Column(type: 'text')]
    private string $mensagem = '';

    #[ORM\Column(name: 'prazo_dias', type: 'smallint', options: ['default' => self::PRAZO_PADRAO])]
    private int $prazoDias = self::PRAZO_PADRAO;

    #[ORM\Column(length: 20, enumType: SituacaoDoDocumento::class)]
    private SituacaoDoDocumento $situacao = SituacaoDoDocumento::Rascunho;

    #[ORM\Column(length: 20, enumType: CorDaLinha::class, options: ['default' => ''])]
    private CorDaLinha $cor = CorDaLinha::Nenhuma;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(name: 'alterado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradoEm;

    #[ORM\Column(name: 'enviado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $enviadoEm = null;

    #[ORM\Column(name: 'vence_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $venceEm = null;

    #[ORM\Column(name: 'concluido_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $concluidoEm = null;

    #[ORM\Column(name: 'hash_enviado', length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $hashEnviado = null;

    #[ORM\Column(name: 'arquivo_enviado_chave', length: 255, nullable: true)]
    private ?string $arquivoEnviadoChave = null;

    #[ORM\Column(name: 'arquivo_enviado_nome', length: 255, nullable: true)]
    private ?string $arquivoEnviadoNome = null;

    #[ORM\Column(name: 'arquivo_enviado_paginas', type: 'integer', nullable: true)]
    private ?int $arquivoEnviadoPaginas = null;

    /** Lixeira: preenchido = o documento está na Lixeira. Sem setter: só pelas transições abaixo. */
    #[ORM\Column(name: 'excluido_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $excluidoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'excluido_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $excluidoPor = null;

    /** @var Collection<int, Signatario> */
    #[ORM\OneToMany(mappedBy: 'documento', targetEntity: Signatario::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordem' => 'ASC'])]
    private Collection $signatarios;

    public function __construct(Tenant $tenant, ?User $criadoPor, \DateTimeImmutable $agora)
    {
        $this->tenant = $tenant;
        $this->criadoPor = $criadoPor;
        $this->criadoEm = $agora;
        $this->alteradoEm = $agora;
        $this->signatarios = new ArrayCollection();
    }

    /**
     * A cópia do "Duplicar" (desenho): rascunho novo com o nome dado, a mesma pasta, cor, conteúdo e logo, e os
     * signatários de volta a pendentes. Os arquivos (PDF e logo própria) ganham chave nova no UseCase.
     */
    public static function copiaDe(self $origem, string $nome, ?User $por, \DateTimeImmutable $agora): self
    {
        $copia = new self($origem->tenant, $por, $agora);
        $copia->nome = $nome;
        $copia->pastaRotulo = $origem->pastaRotulo;
        $copia->modeloChave = $origem->modeloChave;
        $copia->categoria = $origem->categoria;
        $copia->blocos = $origem->blocos;
        $copia->layout = $origem->layout;
        $copia->lateral = $origem->lateral;
        $copia->folhaBranca = $origem->folhaBranca;
        $copia->logoTipo = $origem->logoTipo;
        $copia->emOrdem = $origem->emOrdem;
        $copia->mensagem = $origem->mensagem;
        $copia->prazoDias = $origem->prazoDias;
        $copia->cor = $origem->cor;

        foreach ($origem->signatarios as $signatario) {
            $copia->signatarios->add($signatario->copiaPendentePara($copia));
        }

        return $copia;
    }

    /**
     * "Salvar rascunho": troca o conteúdo inteiro pelo que a tela mandou. Os signatários do rascunho são
     * substituídos (ainda não têm evidência nenhuma).
     *
     * @param list<array<string, int|string>> $blocos já validados
     *
     * @throws AcaoRecusada fora do rascunho
     */
    public function aplicarRascunho(DadosDoRascunho $dados, array $blocos, \DateTimeImmutable $agora): void
    {
        $this->exigirRascunho();
        $this->nome = $dados->nome;
        $this->pastaRotulo = $dados->pasta;
        $this->modeloChave = $dados->modelo;
        $this->categoria = $dados->categoria;
        $this->blocos = $blocos;
        $this->layout = $dados->layout;
        $this->lateral = $dados->lateral;
        $this->folhaBranca = $dados->folhaBranca;
        $this->emOrdem = $dados->emOrdem;
        $this->mensagem = $dados->mensagem;
        $this->prazoDias = $dados->prazoDias;
        $this->alteradoEm = $agora;
        $this->substituirSignatarios($dados);
    }

    /** @throws AcaoRecusada fora do rascunho */
    public function definirArquivo(string $chave, string $nome, ?int $paginas): void
    {
        $this->exigirRascunho();
        $this->arquivoEnviadoChave = $chave;
        $this->arquivoEnviadoNome = $nome;
        $this->arquivoEnviadoPaginas = $paginas;
    }

    /**
     * Tira o PDF do documento (rascunho sem página do PDF não o guarda). O arquivo sai do disco no UseCase, depois do
     * COMMIT.
     *
     * @throws AcaoRecusada fora do rascunho
     */
    public function descartarArquivo(): void
    {
        $this->exigirRascunho();
        $this->arquivoEnviadoChave = null;
        $this->arquivoEnviadoNome = null;
        $this->arquivoEnviadoPaginas = null;
    }

    /**
     * A logo do documento. A chave só existe na logo própria: nas outras, a imagem não é deste documento.
     *
     * @throws AcaoRecusada fora do rascunho
     */
    public function definirLogo(TipoDeLogo $tipo, ?string $chave): void
    {
        $this->exigirRascunho();
        $this->logoTipo = $tipo;
        $this->logoChave = $tipo === TipoDeLogo::Propria ? $chave : null;
    }

    /**
     * Leva o documento para a Lixeira. Se ele aguardava assinatura, o envio é cancelado (os links param).
     *
     * @return bool se havia envio em andamento que foi cancelado
     */
    public function moverParaLixeira(?User $por, \DateTimeImmutable $agora): bool
    {
        if ($this->estaNaLixeira()) {
            throw new \LogicException('O documento já está na Lixeira.');
        }

        $cancelou = $this->situacao === SituacaoDoDocumento::Aguardando;
        if ($cancelou) {
            $this->situacao = SituacaoDoDocumento::Cancelado;
        }

        $this->excluidoEm = $agora;
        $this->excluidoPor = $por;

        return $cancelou;
    }

    /**
     * "Gerar links e enviar": só rascunho fora da Lixeira, com ao menos um signatário. O prazo conta da hora do envio.
     *
     * @throws AcaoRecusada
     */
    public function enviar(string $hash, \DateTimeImmutable $agora): void
    {
        $this->exigirEnviavel();

        $agora = self::emSegundos($agora);
        $this->situacao = SituacaoDoDocumento::Aguardando;
        $this->enviadoEm = $agora;
        $this->venceEm = $agora->modify(sprintf('+%d days', $this->prazoDias));
        $this->hashEnviado = $hash;
    }

    /**
     * O que a situação exige para enviar, antes das conferências dos signatários: rascunho, fora da Lixeira e com ao
     * menos um signatário.
     *
     * @throws AcaoRecusada
     */
    public function exigirEnviavel(): void
    {
        if (!$this->ehRascunho()) {
            throw new AcaoRecusada('assinatura_eletronica.erro.envio_ja_feito');
        }
        if ($this->estaNaLixeira()) {
            throw new AcaoRecusada('assinatura_eletronica.erro.envio_na_lixeira');
        }
        if ($this->signatarios->isEmpty()) {
            throw new AcaoRecusada('assinatura_eletronica.erro.envio_sem_signatarios');
        }
    }

    /** "Cancelar envio" do detalhe: só o que aguarda assinatura. Documento e evidências ficam. */
    public function cancelarEnvio(): void
    {
        if ($this->situacao !== SituacaoDoDocumento::Aguardando) {
            throw new AcaoRecusada('assinatura_eletronica.erro.cancelar_fora_do_envio');
        }

        $this->situacao = SituacaoDoDocumento::Cancelado;
    }

    /** Aguardando com o prazo passado: a tela mostra Expirado e o link, "Prazo encerrado". */
    public function estaVencido(\DateTimeImmutable $agora): bool
    {
        return $this->situacao === SituacaoDoDocumento::Aguardando && $this->venceEm !== null && $agora > $this->venceEm;
    }

    /**
     * O que a tela pública mostra a este signatário, na precedência do desenho (inventário G10): já assinou > recusou >
     * cancelado (inclusive na Lixeira e recusado por outro) > concluído > prazo > ordem.
     */
    public function estadoPara(Signatario $signatario, \DateTimeImmutable $agora): EstadoDaPagina
    {
        return match (true) {
            $signatario->getSituacao() === SituacaoDoSignatario::Assinado => $this->situacao === SituacaoDoDocumento::Concluido
                ? EstadoDaPagina::Concluido
                : EstadoDaPagina::JaAssinado,
            $signatario->getSituacao() === SituacaoDoSignatario::Recusado => EstadoDaPagina::Recusado,
            $this->estaNaLixeira() || $this->situacao === SituacaoDoDocumento::Cancelado => EstadoDaPagina::Cancelado,
            // Recusado por outro signatário: "Envio cancelado", sem dizer quem recusou (texto G7).
            $this->situacao === SituacaoDoDocumento::Recusado => EstadoDaPagina::Cancelado,
            $this->situacao === SituacaoDoDocumento::Concluido => EstadoDaPagina::Concluido,
            $this->situacao !== SituacaoDoDocumento::Aguardando => EstadoDaPagina::Invalido,
            $this->estaVencido($agora) => EstadoDaPagina::PrazoEncerrado,
            $this->anteriorPendente($signatario) !== null => EstadoDaPagina::AguardandoAnterior,
            default => EstadoDaPagina::Pronto,
        };
    }

    /** Com "em ordem": o primeiro signatário antes deste que ainda não assinou. Sem ordem, ninguém espera ninguém. */
    public function anteriorPendente(Signatario $signatario): ?Signatario
    {
        if (!$this->emOrdem) {
            return null;
        }

        foreach ($this->getSignatarios() as $outro) {
            if ($outro->getOrdem() >= $signatario->getOrdem()) {
                break;
            }
            if ($outro->getSituacao() !== SituacaoDoSignatario::Assinado) {
                return $outro;
            }
        }

        return null;
    }

    /** O primeiro pendente na ordem da lista: o alvo do lembrete e, com "em ordem", quem tem a vez. */
    public function primeiroPendente(): ?Signatario
    {
        foreach ($this->getSignatarios() as $signatario) {
            if ($signatario->estaPendente()) {
                return $signatario;
            }
        }

        return null;
    }

    /**
     * Registra a assinatura de quem tem a vez. O último a assinar conclui o documento.
     *
     * @return bool se o documento foi concluído agora
     *
     * @throws AcaoRecusada fora da vez
     */
    public function assinar(Signatario $signatario, string $nome, string $cpf, string $rubricaChave, string $metodo, ?string $ip, ?string $userAgent, \DateTimeImmutable $agora): bool
    {
        $this->exigirVez($signatario, $agora);
        $signatario->registrarAssinatura($nome, $cpf, $rubricaChave, $metodo, $ip, $userAgent, $agora);
        if ($this->contarAssinados() < $this->signatarios->count()) {
            return false;
        }

        $this->situacao = SituacaoDoDocumento::Concluido;
        $this->concluidoEm = self::emSegundos($agora);

        return true;
    }

    /**
     * A recusa de quem tem a vez encerra o documento: os links dos demais deixam de valer.
     *
     * @throws AcaoRecusada fora da vez
     */
    public function recusar(Signatario $signatario, string $motivo, ?string $ip, ?string $userAgent, \DateTimeImmutable $agora): void
    {
        $this->exigirVez($signatario, $agora);
        $signatario->registrarRecusa($motivo, $ip, $userAgent);
        $this->situacao = SituacaoDoDocumento::Recusado;
    }

    /**
     * Só quem está "pronto" age: documento aguardando, no prazo, signatário pendente e, com ordem, a vez dele.
     *
     * @throws AcaoRecusada com o texto do estado em que o signatário está
     */
    public function exigirVez(Signatario $signatario, \DateTimeImmutable $agora): void
    {
        if ($signatario->getDocumento() !== $this) {
            throw new \LogicException('O signatário não é deste documento.');
        }

        $estado = $this->estadoPara($signatario, $agora);
        if ($estado !== EstadoDaPagina::Pronto) {
            throw new AcaoRecusada($estado->chaveDoTexto());
        }
    }

    /** Tira da Lixeira. A situação fica como está: o envio cancelado não volta. */
    public function restaurar(): void
    {
        if (!$this->estaNaLixeira()) {
            throw new \LogicException('O documento não está na Lixeira.');
        }

        $this->excluidoEm = null;
        $this->excluidoPor = null;
    }

    public function pintar(CorDaLinha $cor): void
    {
        $this->cor = $cor;
    }

    /** Só rascunho na Lixeira sai de vez: o que foi enviado guarda as evidências. */
    public function podeSairDeVez(): bool
    {
        return $this->situacao === SituacaoDoDocumento::Rascunho && $this->estaNaLixeira();
    }

    public function ehRascunho(): bool
    {
        return $this->situacao === SituacaoDoDocumento::Rascunho;
    }

    public function estaNaLixeira(): bool
    {
        return $this->excluidoEm !== null;
    }

    public function getExcluidoEm(): ?\DateTimeImmutable
    {
        return $this->excluidoEm;
    }

    public function getExcluidoPor(): ?User
    {
        return $this->excluidoPor;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getPastaRotulo(): ?string
    {
        return $this->pastaRotulo;
    }

    public function getModeloChave(): ?string
    {
        return $this->modeloChave;
    }

    public function getCategoria(): string
    {
        return $this->categoria;
    }

    /** @return list<array<string, int|string>> */
    public function getBlocos(): array
    {
        return $this->blocos;
    }

    public function getLayout(): string
    {
        return $this->layout;
    }

    public function getLateral(): ?string
    {
        return $this->lateral;
    }

    public function isFolhaBranca(): bool
    {
        return $this->folhaBranca;
    }

    public function getLogoTipo(): TipoDeLogo
    {
        return $this->logoTipo;
    }

    public function getLogoChave(): ?string
    {
        return $this->logoChave;
    }

    public function isEmOrdem(): bool
    {
        return $this->emOrdem;
    }

    public function getMensagem(): string
    {
        return $this->mensagem;
    }

    public function getPrazoDias(): int
    {
        return $this->prazoDias;
    }

    public function getSituacao(): SituacaoDoDocumento
    {
        return $this->situacao;
    }

    public function getCor(): CorDaLinha
    {
        return $this->cor;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAlteradoEm(): \DateTimeImmutable
    {
        return $this->alteradoEm;
    }

    public function getEnviadoEm(): ?\DateTimeImmutable
    {
        return $this->enviadoEm;
    }

    public function getVenceEm(): ?\DateTimeImmutable
    {
        return $this->venceEm;
    }

    public function getConcluidoEm(): ?\DateTimeImmutable
    {
        return $this->concluidoEm;
    }

    public function getHashEnviado(): ?string
    {
        return $this->hashEnviado;
    }

    public function getArquivoEnviadoChave(): ?string
    {
        return $this->arquivoEnviadoChave;
    }

    public function getArquivoEnviadoNome(): ?string
    {
        return $this->arquivoEnviadoNome;
    }

    public function getArquivoEnviadoPaginas(): ?int
    {
        return $this->arquivoEnviadoPaginas;
    }

    public function temArquivo(): bool
    {
        return $this->arquivoEnviadoChave !== null;
    }

    /** Se algum bloco é página do PDF enviado (`pg`): sem nenhum, o documento não guarda o PDF. */
    public function temPaginaDoArquivo(): bool
    {
        foreach ($this->blocos as $bloco) {
            if (($bloco['k'] ?? null) === 'pg') {
                return true;
            }
        }

        return false;
    }

    /** @return list<Signatario> */
    public function getSignatarios(): array
    {
        return array_values($this->signatarios->toArray());
    }

    public function contarAssinados(): int
    {
        return \count(array_filter(
            $this->getSignatarios(),
            static fn (Signatario $s): bool => $s->getSituacao() === SituacaoDoSignatario::Assinado,
        ));
    }

    /** As colunas guardam segundos: o objeto em memória fica igual ao que volta do banco (e o token, igual). */
    private static function emSegundos(\DateTimeImmutable $agora): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $agora->getTimestamp()))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /** @throws AcaoRecusada */
    private function exigirRascunho(): void
    {
        if (!$this->ehRascunho()) {
            throw new AcaoRecusada('assinatura_eletronica.erro.so_rascunho');
        }
    }

    private function substituirSignatarios(DadosDoRascunho $dados): void
    {
        $this->signatarios->clear();

        foreach ($dados->signatarios as $ordem => $s) {
            $this->signatarios->add(new Signatario($this, $ordem, $s->nome, $s->email, $s->cpf, $s->papel, $s->nivel));
        }
    }
}
