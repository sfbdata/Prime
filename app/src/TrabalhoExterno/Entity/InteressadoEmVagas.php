<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\TrabalhoExterno\Repository\InteressadoEmVagasRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Quem pediu "Receba vagas da sua área por e-mail" na página pública (spec VS 18.10.26 §4.2), com
 * o consentimento LGPD: quando, e qual texto aceitou. Um registro por e-mail; pedir de novo
 * atualiza as preferências. O envio e o descadastro são de outras frentes (§4.2).
 */
#[ORM\Entity(repositoryClass: InteressadoEmVagasRepository::class)]
#[ORM\Table(name: 'trabalho_externo_interessado')]
#[ORM\UniqueConstraint(name: 'uniq_te_interessado_email', columns: ['email'])]
class InteressadoEmVagas
{
    /** O texto do consentimento, versionado: mudar a frase da tela é mudar a versão. */
    public const CONSENTIMENTO = 'Autorizo o BlueJus a me enviar vagas por e-mail (LGPD). Posso cancelar a qualquer momento pelo link no e-mail.';
    public const VERSAO_DO_CONSENTIMENTO = '1';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 2)]
    private string $uf = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $areas = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $consentidoEm;

    #[ORM\Column(length: 10)]
    private string $versaoDoConsentimento = self::VERSAO_DO_CONSENTIMENTO;

    /** Hash do token do link de onde veio, se veio de um (origem da inscrição). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $origemLink = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct(
        #[ORM\Column(length: 180)]
        private string $email,
    ) {
        $this->email = mb_strtolower(trim($email));
        $this->criadoEm = new \DateTimeImmutable();
        $this->consentidoEm = $this->criadoEm;
    }

    /** @param list<string> $areas */
    public function definirPreferencias(string $uf, array $areas, ?string $origemLink, \DateTimeImmutable $agora): void
    {
        $this->uf = $uf;
        $this->areas = $areas;
        $this->origemLink = $origemLink ?? $this->origemLink;
        $this->consentidoEm = $agora;
        $this->versaoDoConsentimento = self::VERSAO_DO_CONSENTIMENTO;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUf(): string
    {
        return $this->uf;
    }

    /** @return list<string> */
    public function getAreas(): array
    {
        return $this->areas;
    }

    public function getConsentidoEm(): \DateTimeImmutable
    {
        return $this->consentidoEm;
    }

    public function getVersaoDoConsentimento(): string
    {
        return $this->versaoDoConsentimento;
    }

    /** "Inscrito em" do CSV: a primeira inscrição (pedir de novo só atualiza as preferências). */
    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getOrigemLink(): ?string
    {
        return $this->origemLink;
    }
}
