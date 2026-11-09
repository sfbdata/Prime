<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\Shared\Service\DigitoVerificador;

/**
 * O que o "Gerar links e enviar" confere nos signatários, no servidor (contrato da etapa 2, decisão A): nome de todos,
 * dígito do CPF informado (o desenho, A12 e A13), só as assinaturas Simples e Reforçada (etapa 7) e e-mail de todos (o
 * código vai por e-mail). A ordem é a do desenho: primeiro os nomes, depois os CPFs.
 *
 * Etapa 7 (contrato da etapa 7, seção 4.3): a Reforçada colhe selfie e não é usada para menor (papel com a palavra
 * "menor", sem acento e sem caixa; "Responsável pelo menor" também cai, falso positivo assumido) e, havendo Reforçada,
 * a chave da selfie tem de estar configurada.
 */
final class ConferenciaDoEnvio
{
    private function __construct()
    {
    }

    /** @throws AcaoRecusada */
    public static function exigir(DocumentoParaAssinar $documento, bool $reforcadaConfigurada): void
    {
        $signatarios = $documento->getSignatarios();
        if ($signatarios === []) {
            throw new AcaoRecusada('assinatura_eletronica.erro.envio_sem_signatarios');
        }

        foreach ($signatarios as $s) {
            if (trim($s->getNome()) === '') {
                throw new AcaoRecusada('assinatura_eletronica.erro.envio_nome');
            }
        }
        foreach ($signatarios as $s) {
            if ($s->getCpf() !== null && !DigitoVerificador::cpfValido($s->getCpf())) {
                throw new AcaoRecusada('assinatura_eletronica.erro.envio_cpf');
            }
        }
        $temReforcada = false;
        foreach ($signatarios as $s) {
            $nivel = $s->getNivel();
            if ($nivel !== NivelDeAssinatura::Simples && $nivel !== NivelDeAssinatura::Reforcada) {
                throw new AcaoRecusada('assinatura_eletronica.erro.envio_nivel', ['nome' => $s->getNome()]);
            }
            if ($nivel === NivelDeAssinatura::Reforcada && self::papelDeMenor($s->getPapel())) {
                throw new AcaoRecusada('assinatura_eletronica.erro.envio_reforcada_menor', ['nome' => $s->getNome()]);
            }
            if ($s->getEmail() === null) {
                throw new AcaoRecusada('assinatura_eletronica.erro.envio_email', ['nome' => $s->getNome()]);
            }
            $temReforcada = $temReforcada || $nivel === NivelDeAssinatura::Reforcada;
        }
        if ($temReforcada && !$reforcadaConfigurada) {
            throw new AcaoRecusada('assinatura_eletronica.erro.reforcada_sem_chave');
        }
    }

    /**
     * O papel tem "menor", "criança" ou "adolescente", no singular ou no plural, com ou sem acento (ex.: "Menor
     * assistido(a)", "Menores representados", "Crianças"). Palavra inteira: "Menorá" não cai. O papel é texto livre,
     * então um menor com papel sem essas palavras passa (risco que fica, contrato I8).
     */
    private static function papelDeMenor(string $papel): bool
    {
        return preg_match('/(?<!\pL)(?:menor(?:es)?|crian[cç]as?|adolescentes?)(?!\pL)/iu', $papel) === 1;
    }
}
