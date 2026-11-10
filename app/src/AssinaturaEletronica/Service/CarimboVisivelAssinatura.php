<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\SituacaoDoSignatario;

/**
 * Uma assinatura eletrônica feita pelo BlueJus, como entra no carimbo visível: o nome e o CPF digitados na assinatura
 * e a hora dela. A linha do carimbo segue o desenho (dc:384).
 */
final readonly class CarimboVisivelAssinatura
{
    public function __construct(
        public string $nome,
        public ?string $cpf,
        public \DateTimeImmutable $assinadaEm,
    ) {
    }

    /**
     * As assinaturas BlueJus dos signatários, na ordem dada: só quem assinou e tem rubrica (o `x.st === 'assinado' &&
     * x.rub` do desenho, dc:384). O nome é o digitado na assinatura, ou o do convite; o CPF é só o digitado.
     *
     * @param iterable<Signatario> $signatarios
     *
     * @return list<self>
     */
    public static function dosSignatarios(iterable $signatarios): array
    {
        $assinaturas = [];
        foreach ($signatarios as $signatario) {
            $assinadoEm = $signatario->getAssinadoEm();
            if ($signatario->getSituacao() !== SituacaoDoSignatario::Assinado || (string) $signatario->getRubricaChave() === '' || $assinadoEm === null) {
                continue;
            }
            $assinaturas[] = new self($signatario->getNomeDigitado() ?: $signatario->getNome(), $signatario->getCpfDigitado(), $assinadoEm);
        }

        return $assinaturas;
    }

    /**
     * "Assinatura eletrônica BlueJus: {nome}, CPF ***.{4º ao 6º dígito}.***-**, em {data}", com a data no formato do
     * `toLocaleString('pt-BR')` do desenho ("09/11/2026, 14:05:03"), no fuso do sistema.
     */
    public function linha(): string
    {
        $digitos = (string) preg_replace('/\D/', '', (string) $this->cpf);
        $data = $this->assinadaEm->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d/m/Y, H:i:s');

        return 'Assinatura eletrônica BlueJus: ' . $this->nome . ', CPF ***.' . substr($digitos, 3, 3) . '.***-**, em ' . $data;
    }
}
