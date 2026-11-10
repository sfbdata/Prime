<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * O envio da janela "Assinar folha" (desenho 16 L290 e L809; spec ponto-desenho-0811, E16, P33 e decisão 19): a
 * competência, a posição da pessoa (`concordo` ou `ressalva`) e o texto da ressalva, como chegaram. O textarea fica
 * sempre à vista: no "Concordo" o texto é ignorado, como no desenho; na ressalva ele é obrigatório e tem até 1.000
 * caracteres, contados como o `maxlength` do navegador conta (a quebra de linha vale 1). O IP e o navegador vêm do
 * pedido (prova do aceite), sem validação: quem grava tira o UTF-8 inválido e corta no tamanho da coluna.
 *
 * Quem assina e o escritório não vêm daqui: são a pessoa e o escritório da sessão.
 */
final class AssinarFolhaInput
{
    public function __construct(
        #[Assert\Range(min: 2000, max: 2999, notInRangeMessage: 'Competência inválida.')]
        public readonly int $ano,
        #[Assert\Range(min: 1, max: 12, notInRangeMessage: 'Competência inválida.')]
        public readonly int $mes,
        #[Assert\Choice(choices: ['concordo', 'ressalva'], message: 'Escolha a sua posição.')]
        public readonly string $situacao,
        public readonly string $ressalva = '',
        public readonly ?string $ip = null,
        public readonly ?string $navegador = null,
    ) {
    }

    /** Na ressalva, o texto é obrigatório e tem até 1.000 caracteres (as mensagens são as da entidade). */
    #[Assert\Callback]
    public function validarRessalva(ExecutionContextInterface $contexto): void
    {
        if ($this->situacao !== SituacaoDoAceiteDaFolha::Ressalva->value) {
            return;
        }
        try {
            AceiteDaFolhaDoPonto::exigirRessalvaCoerente(SituacaoDoAceiteDaFolha::Ressalva, $this->ressalvaParaGravar());
        } catch (\DomainException $recusa) {
            $contexto->buildViolation($recusa->getMessage())->atPath('ressalva')->addViolation();
        }
    }

    /** @throws \ValueError situação fora das duas (o validador recusa antes) */
    public function situacao(): SituacaoDoAceiteDaFolha
    {
        return SituacaoDoAceiteDaFolha::from($this->situacao);
    }

    /**
     * O texto da ressalva como é contado, selado e gravado; null no concordo (o texto digitado não é gravado, como no
     * desenho). O byte de UTF-8 inválido vira "?" (o texto selado é JSON), a quebra CRLF e o CR solto viram LF (o
     * navegador conta a quebra como 1 caractere e manda CRLF) e o texto sai aparado.
     */
    public function ressalvaParaGravar(): ?string
    {
        if ($this->situacao !== SituacaoDoAceiteDaFolha::Ressalva->value) {
            return null;
        }

        return trim(str_replace(["\r\n", "\r"], "\n", mb_scrub($this->ressalva, 'UTF-8')));
    }
}
