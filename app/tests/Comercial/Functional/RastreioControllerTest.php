<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Controller\RastreioController;
use App\Comercial\Service\MontadorDeEmail;
use App\Tests\Comercial\Support\MontaOMontador;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Pixel e clique (spec §3.8, §3.9): públicos, sem login; GIF igual para token inválido; assinatura errada 404. */
#[CoversClass(RastreioController::class)]
final class RastreioControllerTest extends JusPrimeWebTestCase
{
    use MontaOMontador;

    private KernelBrowser $client;
    private Connection $conexao;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->conexao = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function envio(): string
    {
        $token = bin2hex(random_bytes(16));
        $this->conexao->executeStatement(
            "INSERT INTO comercial_envio (token, destinatario, assunto, tipo, lista, origem, situacao, criado_em, enviado_em, aberturas, cliques)
             VALUES (:token, 'ana@exemplo.com', 'x', 'lista', 'comercial_dicas', 'campanha:1', 'enviado', NOW(), NOW(), 0, 0)",
            ['token' => $token],
        );

        return $token;
    }

    /** @return array{aberturas: int, cliques: int, abriu: bool, clicou: bool} */
    private function contadores(string $token): array
    {
        $l = $this->conexao->fetchAssociative('SELECT aberturas, cliques, primeira_abertura_em, primeiro_clique_em FROM comercial_envio WHERE token = ?', [$token]);
        self::assertIsArray($l);

        return ['aberturas' => (int) $l['aberturas'], 'cliques' => (int) $l['cliques'], 'abriu' => $l['primeira_abertura_em'] !== null, 'clicou' => $l['primeiro_clique_em'] !== null];
    }

    private function linkDeClique(string $token, string $destino): string
    {
        $caminho = static::getContainer()->get(UrlGeneratorInterface::class)
            ->generate(MontadorDeEmail::ROTA_CLIQUE, ['token' => $token, 'u' => $destino], UrlGeneratorInterface::ABSOLUTE_PATH);

        return self::assinadorDeClique()->sign($caminho);
    }

    #[TestDox('Pixel: sem login, responde o GIF e soma a abertura (a primeira fica gravada)')]
    public function testPixelRegistra(): void
    {
        $token = $this->envio();

        $this->client->request('GET', '/e/a/' . $token . '.gif');
        $this->client->request('GET', '/e/a/' . $token . '.gif');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'image/gif');
        self::assertSame(['aberturas' => 2, 'cliques' => 0, 'abriu' => true, 'clicou' => false], $this->contadores($token));
    }

    #[TestDox('Pixel com token inválido ou desconhecido: o mesmo GIF, sem erro e sem redirecionar ao login')]
    public function testPixelTokenInvalidoIgual(): void
    {
        $this->client->request('GET', '/e/a/' . $this->envio() . '.gif');
        $valido = (string) $this->client->getResponse()->getContent();

        foreach (['/e/a/ffffffffffffffffffffffffffffffff.gif', '/e/a/nao-e-token.gif'] as $caminho) {
            $this->client->request('GET', $caminho);
            self::assertResponseStatusCodeSame(200, $caminho);
            self::assertSame($valido, (string) $this->client->getResponse()->getContent(), $caminho);
        }
        self::assertStringStartsWith('GIF89a', $valido);
    }

    #[TestDox('Clique assinado: sem login, soma o clique e redireciona 302 para o destino')]
    public function testCliqueRedireciona(): void
    {
        $token = $this->envio();

        $this->client->request('GET', $this->linkDeClique($token, 'https://exemplo.com/artigo?a=1&b=2'));

        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'https://exemplo.com/artigo?a=1&b=2');
        self::assertSame(1, $this->contadores($token)['cliques']);
        self::assertTrue($this->contadores($token)['clicou']);
    }

    #[TestDox('Clique com assinatura errada, sem assinatura, com destino trocado ou token trocado: 404, sem redirecionar')]
    public function testCliqueAssinaturaErrada(): void
    {
        $token = $this->envio();
        $outro = $this->envio();
        $link = $this->linkDeClique($token, 'https://exemplo.com/artigo');
        $tentativas = [
            (string) preg_replace('/([?&]s=)[^&]+/', '$1assinaturaerrada', $link),
            '/e/c/' . $token . '?u=' . rawurlencode('https://exemplo.com/artigo'),
            str_replace(rawurlencode('https://exemplo.com/artigo'), rawurlencode('https://golpe.example/'), $link),
            str_replace('/e/c/' . $token, '/e/c/' . $outro, $link),
        ];

        foreach ($tentativas as $tentativa) {
            $this->client->request('GET', $tentativa);
            self::assertResponseStatusCodeSame(404, $tentativa);
            self::assertFalse($this->client->getResponse()->headers->has('Location'), $tentativa);
        }
        self::assertSame(0, $this->contadores($token)['cliques']);
        self::assertSame(0, $this->contadores($outro)['cliques']);
    }
}
