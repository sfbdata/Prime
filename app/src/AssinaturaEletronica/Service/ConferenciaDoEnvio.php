<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\Shared\Service\DigitoVerificador;

/**
 * O que o "Gerar links e enviar" confere nos signatários, no servidor (contrato da etapa 2, decisão A): nome de todos,
 * dígito do CPF informado (o desenho, A12 e A13), só a assinatura Simples nesta etapa e e-mail de quem é Simples (o
 * código vai por e-mail). A ordem é a do desenho: primeiro os nomes, depois os CPFs.
 */
final class ConferenciaDoEnvio
{
    private function __construct()
    {
    }

    /** @throws AcaoRecusada */
    public static function exigir(DocumentoParaAssinar $documento): void
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
        foreach ($signatarios as $s) {
            if ($s->getNivel() !== NivelDeAssinatura::Simples) {
                throw new AcaoRecusada('assinatura_eletronica.erro.envio_nivel', ['nome' => $s->getNome()]);
            }
            if ($s->getEmail() === null) {
                throw new AcaoRecusada('assinatura_eletronica.erro.envio_email', ['nome' => $s->getNome()]);
            }
        }
    }
}
