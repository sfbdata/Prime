<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Security\RememberMe\DoctrineTokenProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\RememberMe\PersistentToken;
use Symfony\Component\Security\Core\Authentication\RememberMe\PersistentTokenInterface;
use Symfony\Component\Security\Core\Authentication\RememberMe\TokenProviderInterface;
use Symfony\Component\Security\Core\Authentication\RememberMe\TokenVerifierInterface;

/**
 * Séries do "Manter conectado" (auditoria 3.29), guardadas na tabela `rememberme_token` pelo `DoctrineTokenProvider`
 * do Symfony, com uma diferença: o banco guarda o HMAC (kernel.secret) da parte aleatória do valor, nunca o valor.
 * O provider puro grava o valor em claro, e o cookie é só `usuário:série:valor`; quem lesse o banco (consulta só de
 * leitura, dump, backup) montava um cookie válido. O resumo da senha (`.digest`, depois do ponto) fica como está:
 * o handler o lê do valor guardado para conferir a troca de senha, e ele já sai do hash da senha.
 *
 * Também: a cada série nova, apaga as que passaram 30 dias sem uso (a validade do cookie no security.yaml); sem
 * isso, quem fecha o navegador sem sair deixava a linha (com o e-mail) para sempre. E o valor errado numa série que
 * existe (o Symfony trata como cookie roubado e não registra) fica no log.
 */
final class TokenDoManterConectado implements TokenProviderInterface, TokenVerifierInterface
{
    /** Igual ao `remember_me.lifetime` do firewall main. */
    public const VALIDADE_EM_SEGUNDOS = 2592000;
    private const SEPARADOR_DO_RESUMO = '.';

    private readonly DoctrineTokenProvider $doctrine;

    public function __construct(
        private readonly Connection $conexao,
        #[Autowire('%kernel.secret%')] #[\SensitiveParameter] private readonly string $segredo,
        private readonly LoggerInterface $logger,
    ) {
        $this->doctrine = new DoctrineTokenProvider($conexao);
    }

    public function loadTokenBySeries(string $series): PersistentTokenInterface
    {
        return $this->doctrine->loadTokenBySeries($series);
    }

    public function deleteTokenBySeries(string $series): void
    {
        $this->doctrine->deleteTokenBySeries($series);
    }

    public function updateToken(string $series, #[\SensitiveParameter] string $tokenValue, \DateTimeInterface $lastUsed): void
    {
        $this->doctrine->updateToken($series, $this->guardado($tokenValue), $lastUsed);
    }

    public function createNewToken(PersistentTokenInterface $token): void
    {
        $this->conexao->executeStatement(
            'DELETE FROM rememberme_token WHERE lastUsed < ?',
            [(new \DateTimeImmutable())->modify('-' . self::VALIDADE_EM_SEGUNDOS . ' seconds')->format('Y-m-d H:i:s')],
        );
        $this->doctrine->createNewToken(new PersistentToken(
            $token->getUserIdentifier(),
            $token->getSeries(),
            $this->guardado($token->getTokenValue()),
            $token->getLastUsed(),
        ));
    }

    public function verifyToken(PersistentTokenInterface $token, #[\SensitiveParameter] string $tokenValue): bool
    {
        // O provider confere a série e a cópia anterior (pedidos paralelos na troca do valor) pelo valor guardado.
        $confere = $this->doctrine->verifyToken($token, $this->guardado($tokenValue));
        if (!$confere) {
            $this->logger->warning('Manter conectado: valor errado numa série que existe (cookie copiado ou pedidos paralelos); a série foi encerrada.');
        }

        return $confere;
    }

    public function updateExistingToken(PersistentTokenInterface $token, #[\SensitiveParameter] string $tokenValue, \DateTimeInterface $lastUsed): void
    {
        // Guarda a cópia do valor anterior (já guardado como HMAC) para o pedido paralelo; o valor novo vai pelo updateToken.
        $this->doctrine->updateExistingToken($token, $this->guardado($tokenValue), $lastUsed);
    }

    /** Para o listener de esquema: a tabela continua no mapeamento como no provider puro. */
    public function configureSchema(Schema $schema, Connection $forConnection, \Closure $isSameDatabase): Schema
    {
        return $this->doctrine->configureSchema($schema, $forConnection, $isSameDatabase);
    }

    public function conexao(): Connection
    {
        return $this->conexao;
    }

    /** `aleatorio[.resumo]` vira `hmac(aleatorio)[.resumo]`, com o mesmo tamanho da parte aleatória (44). */
    private function guardado(#[\SensitiveParameter] string $valor): string
    {
        $ponto = strpos($valor, self::SEPARADOR_DO_RESUMO);
        $aleatorio = $ponto === false ? $valor : substr($valor, 0, $ponto);
        $resumo = $ponto === false ? '' : substr($valor, $ponto);
        $hmac = strtr(base64_encode(substr(hash_hmac('sha256', 'manter-conectado:' . $aleatorio, $this->segredo, true), 0, 33)), '+/=', '-_~');

        return $hmac . $resumo;
    }
}
