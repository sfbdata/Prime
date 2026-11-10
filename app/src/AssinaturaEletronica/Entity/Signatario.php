<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Entity;

use App\AssinaturaEletronica\DTO\LocalizacaoDoSignatario;
use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Enum\SituacaoDoSignatario;
use App\Shared\Attribute\SemIsolamentoDeTenant;
use Doctrine\ORM\Mapping as ORM;

/**
 * Quem assina um documento (contrato da etapa 1, seção 3.2). As colunas da assinatura em si (token, código, rubrica,
 * IP, localização...) já nascem aqui, nulas, para a etapa 2 não precisar de outra migration nesta tabela.
 *
 * Etapa 2 (contrato da etapa 2, seções 3 e 4.1): o hash do token do link, o código de 6 dígitos (só o HMAC, 15 minutos,
 * 5 erros queimam), o bloqueio do teto diário e o registro da assinatura ou da recusa. Quem decide a vez e a situação
 * do documento é o {@see DocumentoParaAssinar}; aqui só se guarda o que aconteceu com este signatário.
 *
 * Etapa 7 (contrato da etapa 7, seção 4): na Reforçada, a chave do arquivo cifrado da selfie, o sha256 do JPEG e a
 * versão da chave da selfie (M5), e a localização nas colunas que já existiam desde a M1.
 *
 * Etapa 5 (contrato da etapa 5, seção 2, item 3): gov.br e ICP assinam sem rubrica; a prova é o PDF devolvido.
 */
#[ORM\Entity]
#[ORM\Table(name: 'assinatura_eletronica_signatario')]
#[ORM\UniqueConstraint(name: 'uniq_assinatura_eletronica_signatario_token', columns: ['token_hash'])]
#[SemIsolamentoDeTenant('Filho do documento (TenantAware, CASCADE): lido pela coleção do documento já filtrado ou, na tela pública, pelo hash do token, com o escritório tirado do próprio documento')]
class Signatario
{
    /** Validade do código de 6 dígitos, a partir do envio. */
    public const CODIGO_VALIDADE = '+15 minutes';
    /** Erros seguidos que queimam o código (o signatário pede outro). */
    public const CODIGO_ERROS_MAXIMO = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DocumentoParaAssinar::class, inversedBy: 'signatarios')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DocumentoParaAssinar $documento;

    #[ORM\Column(type: 'smallint')]
    private int $ordem;

    #[ORM\Column(length: 200)]
    private string $nome;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email;

    #[ORM\Column(length: 11, nullable: true)]
    private ?string $cpf;

    #[ORM\Column(length: 120)]
    private string $papel;

    #[ORM\Column(length: 20, enumType: NivelDeAssinatura::class)]
    private NivelDeAssinatura $nivel;

    #[ORM\Column(length: 20, enumType: SituacaoDoSignatario::class)]
    private SituacaoDoSignatario $situacao = SituacaoDoSignatario::Pendente;

    #[ORM\Column(name: 'token_hash', length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $tokenHash = null;

    #[ORM\Column(name: 'codigo_hash', length: 255, nullable: true)]
    private ?string $codigoHash = null;

    #[ORM\Column(name: 'codigo_enviado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $codigoEnviadoEm = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $tentativas = 0;

    #[ORM\Column(name: 'bloqueado_ate', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $bloqueadoAte = null;

    #[ORM\Column(name: 'assinado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $assinadoEm = null;

    #[ORM\Column(name: 'nome_digitado', length: 200, nullable: true)]
    private ?string $nomeDigitado = null;

    #[ORM\Column(name: 'cpf_digitado', length: 11, nullable: true)]
    private ?string $cpfDigitado = null;

    #[ORM\Column(name: 'rubrica_chave', length: 255, nullable: true)]
    private ?string $rubricaChave = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $metodo = null;

    #[ORM\Column(name: 'motivo_recusa', type: 'text', nullable: true)]
    private ?string $motivoRecusa = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(name: 'user_agent', type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 8, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(type: 'decimal', precision: 11, scale: 8, nullable: true)]
    private ?string $longitude = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $precisao = null;

    #[ORM\Column(name: 'selfie_chave', length: 255, nullable: true)]
    private ?string $selfieChave = null;

    #[ORM\Column(name: 'selfie_sha256', length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $selfieSha256 = null;

    #[ORM\Column(name: 'selfie_versao', type: 'smallint', nullable: true)]
    private ?int $selfieVersao = null;

    public function __construct(
        DocumentoParaAssinar $documento,
        int $ordem,
        string $nome,
        ?string $email,
        ?string $cpf,
        string $papel,
        NivelDeAssinatura $nivel,
    ) {
        $this->documento = $documento;
        $this->ordem = $ordem;
        $this->nome = $nome;
        $this->email = $email;
        $this->cpf = $cpf;
        $this->papel = $papel;
        $this->nivel = $nivel;
    }

    /** Para o "Duplicar": os mesmos dados, de volta a pendente, sem nada da assinatura anterior. */
    public function copiaPendentePara(DocumentoParaAssinar $copia): self
    {
        return new self($copia, $this->ordem, $this->nome, $this->email, $this->cpf, $this->papel, $this->nivel);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocumento(): DocumentoParaAssinar
    {
        return $this->documento;
    }

    public function getOrdem(): int
    {
        return $this->ordem;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getCpf(): ?string
    {
        return $this->cpf;
    }

    public function getPapel(): string
    {
        return $this->papel;
    }

    public function getNivel(): NivelDeAssinatura
    {
        return $this->nivel;
    }

    public function getSituacao(): SituacaoDoSignatario
    {
        return $this->situacao;
    }

    public function getAssinadoEm(): ?\DateTimeImmutable
    {
        return $this->assinadoEm;
    }

    public function getMetodo(): ?string
    {
        return $this->metodo;
    }

    public function getTentativas(): int
    {
        return $this->tentativas;
    }

    public function getTokenHash(): ?string
    {
        return $this->tokenHash;
    }

    public function getCodigoHash(): ?string
    {
        return $this->codigoHash;
    }

    public function getCodigoEnviadoEm(): ?\DateTimeImmutable
    {
        return $this->codigoEnviadoEm;
    }

    public function getBloqueadoAte(): ?\DateTimeImmutable
    {
        return $this->bloqueadoAte;
    }

    public function getNomeDigitado(): ?string
    {
        return $this->nomeDigitado;
    }

    public function getCpfDigitado(): ?string
    {
        return $this->cpfDigitado;
    }

    public function getRubricaChave(): ?string
    {
        return $this->rubricaChave;
    }

    public function getMotivoRecusa(): ?string
    {
        return $this->motivoRecusa;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getLatitude(): ?string
    {
        return $this->latitude;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function getPrecisao(): ?string
    {
        return $this->precisao;
    }

    public function getSelfieChave(): ?string
    {
        return $this->selfieChave;
    }

    public function getSelfieSha256(): ?string
    {
        return $this->selfieSha256;
    }

    public function getSelfieVersao(): ?int
    {
        return $this->selfieVersao;
    }

    public function estaPendente(): bool
    {
        return $this->situacao === SituacaoDoSignatario::Pendente;
    }

    /** No envio: o banco guarda só o sha256 do token do link. */
    public function definirTokenHash(string $hash): void
    {
        $this->tokenHash = $hash;
    }

    /** Código novo: o anterior deixa de valer e os erros voltam a zero. */
    public function registrarCodigo(string $hash, \DateTimeImmutable $agora): void
    {
        $this->codigoHash = $hash;
        $this->codigoEnviadoEm = self::emSegundos($agora);
        $this->tentativas = 0;
    }

    public function temCodigo(): bool
    {
        return $this->codigoHash !== null;
    }

    /** Até quando o código pedido vale; nulo sem código. */
    public function codigoValidoAte(): ?\DateTimeImmutable
    {
        if ($this->codigoHash === null || $this->codigoEnviadoEm === null) {
            return null;
        }

        return $this->codigoEnviadoEm->modify(self::CODIGO_VALIDADE);
    }

    public function codigoVencido(\DateTimeImmutable $agora): bool
    {
        $ate = $this->codigoValidoAte();

        return $ate === null || $agora > $ate;
    }

    /**
     * Um código errado. No quinto, o código queima: o signatário pede outro.
     *
     * @return bool se o código queimou agora
     */
    public function errarCodigo(): bool
    {
        ++$this->tentativas;
        if ($this->tentativas < self::CODIGO_ERROS_MAXIMO) {
            return false;
        }

        $this->codigoHash = null;

        return true;
    }

    /** Teto diário estourado: nada de código novo nem de conferência até lá. */
    public function bloquearAte(\DateTimeImmutable $ate): void
    {
        $this->bloqueadoAte = self::emSegundos($ate);
    }

    public function estaBloqueado(\DateTimeImmutable $agora): bool
    {
        return $this->bloqueadoAte !== null && $this->bloqueadoAte > $agora;
    }

    /**
     * O registro da assinatura: nome e CPF como digitados, a rubrica guardada, o método e a evidência da requisição.
     * O código usado deixa de valer. Só pela transição do documento, que confere a vez antes. Signatário gov.br ou ICP
     * não passa por aqui: a prova dele é o PDF assinado ({@see registrarAssinaturaComCertificado()}).
     */
    public function registrarAssinatura(string $nome, string $cpf, string $rubricaChave, string $metodo, ?string $ip, ?string $userAgent, \DateTimeImmutable $agora): void
    {
        if ($this->assinaComCertificado()) {
            throw new \LogicException('Signatário gov.br ou ICP só assina com certificado.');
        }
        $this->registrar($nome, $cpf, $rubricaChave, $metodo, $ip, $userAgent, $agora);
    }

    /**
     * O registro da assinatura gov.br ou ICP (contrato da etapa 5, seção 2, item 3): sem rubrica (o lugar de assinatura
     * mostra só o "Assinado em"), com o nível como método (`govbr` ou `icp`). A prova é o PDF devolvido, guardado como
     * {@see VersaoDoPdf} na mesma transação. Só pela transição do documento, que confere a vez antes.
     */
    public function registrarAssinaturaComCertificado(string $nome, string $cpf, ?string $ip, ?string $userAgent, \DateTimeImmutable $agora): void
    {
        if (!$this->assinaComCertificado()) {
            throw new \LogicException('Só signatário gov.br ou ICP assina com certificado.');
        }
        $this->registrar($nome, $cpf, null, $this->nivel->value, $ip, $userAgent, $agora);
    }

    /** Conta gov.br ou certificado ICP-Brasil: assina fora do BlueJus e devolve o PDF assinado. */
    public function assinaComCertificado(): bool
    {
        return $this->nivel === NivelDeAssinatura::Govbr || $this->nivel === NivelDeAssinatura::Icp;
    }

    /**
     * As evidências da Reforçada (selfie cifrada e localização), gravadas na mesma transação e antes da transição
     * {@see DocumentoParaAssinar::assinar()}. Só de signatário pendente e Reforçada.
     */
    public function registrarReforco(string $selfieChave, string $selfieSha256, int $versao, LocalizacaoDoSignatario $localizacao): void
    {
        $this->exigirPendente();
        if ($this->nivel !== NivelDeAssinatura::Reforcada) {
            throw new \LogicException('Só signatário Reforçada tem selfie e localização.');
        }
        $this->selfieChave = $selfieChave;
        $this->selfieSha256 = $selfieSha256;
        $this->selfieVersao = $versao;
        $this->latitude = $localizacao->latitude;
        $this->longitude = $localizacao->longitude;
        $this->precisao = $localizacao->precisao;
    }

    /** O registro da recusa, com o motivo e a evidência da requisição. Só pela transição do documento. */
    public function registrarRecusa(string $motivo, ?string $ip, ?string $userAgent): void
    {
        $this->exigirPendente();
        $this->situacao = SituacaoDoSignatario::Recusado;
        $this->motivoRecusa = $motivo;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
        $this->codigoHash = null;
    }

    /** O que toda assinatura registra; o código usado deixa de valer. */
    private function registrar(string $nome, string $cpf, ?string $rubricaChave, string $metodo, ?string $ip, ?string $userAgent, \DateTimeImmutable $agora): void
    {
        $this->exigirPendente();
        $this->situacao = SituacaoDoSignatario::Assinado;
        $this->assinadoEm = self::emSegundos($agora);
        $this->nomeDigitado = $nome;
        $this->cpfDigitado = $cpf;
        $this->rubricaChave = $rubricaChave;
        $this->metodo = $metodo;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
        $this->codigoHash = null;
    }

    private function exigirPendente(): void
    {
        if (!$this->estaPendente()) {
            throw new \LogicException('Só signatário pendente assina ou recusa.');
        }
    }

    /** As colunas guardam segundos: o objeto em memória fica igual ao que volta do banco. */
    private static function emSegundos(\DateTimeImmutable $data): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $data->getTimestamp()))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
