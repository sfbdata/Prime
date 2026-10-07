<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Pasta\Entity\PastaMensagem;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Teto de avisos no sino que UM autor pode disparar pelo Registro da pasta (SEC-008) — as
 * @menções e o "respondeu seu comentário".
 *
 * Por quê: cada mensagem notifica até {@see MencoesDoRegistro::MAXIMO_POR_REGISTRO} pessoas, e a
 * anti-duplicata é por link — cada mensagem nova tem link novo. Sem teto, um laço (ou um script)
 * inunda o sino dos colegas.
 *
 * O que o teto limita é a NOTIFICAÇÃO, não a mensagem: quando estoura, a mensagem é gravada
 * normalmente, o aviso é suprimido e fica um `warning` no log. Recusar o envio puniria quem escreve
 * para registrar trabalho; o abuso que importa é no sino dos outros.
 *
 * Chave = id do AUTOR (não IP: ver os blocos de `SYMFONY_TRUSTED_PROXIES` no framework.yaml). Uma
 * ficha por aviso que de fato seria criado — quem chama consome só depois dos próprios filtros
 * (o autor, sem acesso, anti-duplicata), para que aviso que não sairia não gaste cota.
 */
final class CotaDeNotificacoesDoRegistro
{
    public function __construct(
        // `limiter.pasta_registro_notificacao`. Interface, não a classe concreta: a injeção de
        // `RateLimiterFactory` por autowiring é deprecada no Symfony 7.4 (e deixa trocar por
        // dublê em teste, como no PastaDocumentoController).
        private readonly RateLimiterFactoryInterface $pastaRegistroNotificacaoLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Consome uma ficha da cota do autor. `false` = estourou: quem chama NÃO cria o aviso, e o
     * resto do envio segue.
     */
    public function permite(User $autor, User $destinatario, string $tipo, PastaMensagem $mensagem): bool
    {
        $aceito = $this->pastaRegistroNotificacaoLimiter
            ->create((string) $autor->getId())
            ->consume()
            ->isAccepted();

        if (!$aceito) {
            try {
                $this->logger->warning('Aviso do Registro suprimido: o autor estourou a cota de notificações.', [
                    'autor_id'        => $autor->getId(),
                    'destinatario_id' => $destinatario->getId(),
                    'tipo'            => $tipo,
                    'mensagem_id'     => $mensagem->getId(),
                ]);
            } catch (\Throwable) {
                // Registrar não pode derrubar o envio da mensagem.
            }
        }

        return $aceito;
    }
}
