<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Entity\EnvioDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use Doctrine\DBAL\Connection;

/**
 * Registra em `comercial_envio` um e-mail que saiu fora do Carteiro (o `cad`, que o CadastroMailer manda, P5) ou uma
 * simulação do "Simular novo cadastro" (que não envia nada). Só para "Últimos disparos"; sem conteúdo. E os e-mails
 * síncronos, só para a cota ({@see self::registrarSemDados()}).
 */
final class RegistroDeEnvioExterno
{
    public const DESTINATARIO_SEM_DADOS = 'sistema';
    public const ASSUNTO_SEM_DADOS = 'E-mail do sistema';

    public function __construct(
        private readonly Connection $conexao,
    ) {
    }

    public function registrar(string $email, string $assunto, string $origem, string $situacao, \DateTimeImmutable $em): void
    {
        $this->conexao->insert('comercial_envio', [
            'token' => bin2hex(random_bytes(16)),
            'destinatario' => mb_strtolower(trim($email)),
            'assunto' => mb_substr($assunto, 0, 255),
            'tipo' => TipoDeEnvio::Transacional->value,
            'origem' => $origem,
            'situacao' => $situacao,
            'criado_em' => $em->format('Y-m-d H:i:s'),
            'enviado_em' => $situacao === EnvioDeEmail::ENVIADO ? $em->format('Y-m-d H:i:s') : null,
            'aberturas' => 0,
            'cliques' => 0,
        ]);
    }

    /**
     * Só para a cota de envio (spec §3.6): um e-mail que saiu, sem endereço nem assunto (LGPD, minimização: a cota só
     * precisa da situação, da origem e de `enviado_em`). As colunas obrigatórias levam marcadores fixos.
     */
    public function registrarSemDados(string $origem, \DateTimeImmutable $em): void
    {
        $this->registrar(self::DESTINATARIO_SEM_DADOS, self::ASSUNTO_SEM_DADOS, $origem, EnvioDeEmail::ENVIADO, $em);
    }
}
