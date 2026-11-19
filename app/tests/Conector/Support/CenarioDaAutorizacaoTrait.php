<?php

declare(strict_types=1);

namespace App\Tests\Conector\Support;

use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Service\LiberacaoDoConector;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;

/**
 * O que a autorização do conector precisa no banco (spec mcp-remoto-oauth 3.4 e 14.a), sem depender do bloco C (busca
 * do documento CIMD): o cliente gravado com o cache dos metadados ainda válido, e o escritório ligado com o aceite.
 * Usa o {@see CenarioDoConectorTrait} para escritório, pessoa e vínculo.
 */
trait CenarioDaAutorizacaoTrait
{
    use CenarioDoConectorTrait;

    private const CLIENT_ID = 'https://claude.ai/oauth/mcp-client-metadata';
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    private const LOOPBACK = 'http://127.0.0.1:33418/callback';
    /** O SHA-256 em base64url do verificador do exemplo da RFC 7636 (apêndice B). */
    private const DESAFIO = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    /**
     * Cliente CIMD gravado e com os metadados válidos: o esqueleto do registro (commit 0) o aceita pelo cache.
     *
     * @param list<string> $redirects
     */
    private function clienteValido(string $clientId = self::CLIENT_ID, array $redirects = [self::REDIRECT, self::LOOPBACK], string $nome = 'Nome que o app diz ter'): ClienteDoConector
    {
        $agora = new \DateTimeImmutable();
        $cliente = new ClienteDoConector($clientId, TipoDeClienteDoConector::Cimd, $nome, $redirects, $agora);
        $cliente->renovarMetadados($nome, $redirects, $agora, $agora->modify('+1 day'));
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    /** Os níveis 2 e 3 da liberação (decisão 14.a) neste escritório. */
    private function liberarConector(Tenant $tenant, bool $ligado = true): EscritorioDoConector
    {
        $escritorio = new EscritorioDoConector($tenant);
        if ($ligado) {
            $escritorio->ligar($this->administradorQualquer(), new \DateTimeImmutable());
        }
        $this->em()->persist($escritorio);
        $this->em()->flush();

        return $escritorio;
    }

    /** O aceite gravado com uma versão anterior à vigente (texto do aceite mudou depois do aceite). */
    private function aceiteEmVersaoAntiga(EscritorioDoConector $escritorio): void
    {
        (new \ReflectionProperty(EscritorioDoConector::class, 'aceiteVersao'))->setValue($escritorio, 0);
        $this->em()->flush();
    }

    private function desligarChaveGeral(): void
    {
        $estado = $this->em()->getRepository(RecursoEstado::class)->findOneBy(['chave' => LiberacaoDoConector::CHAVE_GERAL])
            ?? new RecursoEstado(LiberacaoDoConector::CHAVE_GERAL);
        $estado->desativar('Pausa', null, null, new \DateTimeImmutable());
        $this->em()->persist($estado);
        $this->em()->flush();
    }

    private function administradorQualquer(): User
    {
        return $this->pessoa();
    }

    /**
     * Os parâmetros de um pedido válido ao `/oauth/authorize`.
     *
     * @param array<string, string|null> $trocas valor nulo tira o parâmetro
     *
     * @return array<string, string>
     */
    private function pedidoValido(array $trocas = []): array
    {
        $pedido = array_merge([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT,
            'state' => 'estado-do-app-123',
            'code_challenge' => self::DESAFIO,
            'code_challenge_method' => 'S256',
            'resource' => ServidorDeAutorizacao::RESOURCE,
            'scope' => 'mcp:leitura mcp:escrita offline_access',
        ], $trocas);

        return array_filter($pedido, static fn (?string $valor): bool => $valor !== null);
    }
}
