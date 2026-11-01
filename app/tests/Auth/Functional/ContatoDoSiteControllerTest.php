<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\ContatoDoSiteController;
use App\Auth\DTO\ContatoDoSiteInput;
use App\Auth\Entity\MensagemSuporte;
use App\Tests\Auth\Cenario\CenarioDaEntrada;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Contato do site institucional: POST /contato público (form-urlencoded), vira mensagem ao suporte com origem 'site'
 * e avisa o Master. O limitador é trocado por um espião para provar QUANDO a cota é gasta: envio inválido e o do robô
 * (honeypot "website") não gastam.
 */
#[CoversClass(ContatoDoSiteController::class)]
#[CoversClass(ContatoDoSiteInput::class)]
final class ContatoDoSiteControllerTest extends JusPrimeWebTestCase
{
    use CenarioDaEntrada;

    private KernelBrowser $client;

    private ?RateLimiterFactoryEspiao $limite = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->limparAjustesDaEntrada();
    }

    #[TestDox('Sem login: grava a mensagem com origem site, avisa o Master "pelo site" e responde {ok: true}')]
    public function testEnvioValidoGravaEAvisa(): void
    {
        $master = $this->master('Marta Master');
        $email = uniqid('site_') . '@cliente.com';

        $this->enviar(['email' => '  ' . $email . ' ']);

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true], $this->resposta());
        $mensagem = $this->mensagemDe($email);
        self::assertNotNull($mensagem);
        self::assertSame(['Bia Visitante', 'Parcerias institucionais', 'Quero conhecer o BlueJus.', 'site'], [$mensagem->getNome(), $mensagem->getAssunto(), $mensagem->getMensagem(), $mensagem->getOrigem()]);
        $avisos = $this->avisosDoSuporte($master);
        self::assertCount(1, $avisos);
        self::assertStringEndsWith(') pelo site: Quero conhecer o BlueJus.', $avisos[0]['mensagem']);
        self::assertSame(['site:127.0.0.1'], $this->limite()->chavesConsumidas, 'cota própria do site, por IP');
    }

    #[TestDox('Honeypot "website" preenchido: responde ok, não grava e não gasta a cota')]
    public function testHoneypot(): void
    {
        $email = uniqid('robo_') . '@spam.com';

        $this->enviar(['email' => $email, 'website' => 'http://spam']);

        self::assertSame(['ok' => true], $this->resposta());
        self::assertNull($this->mensagemDe($email));
        self::assertSame([], $this->limite()->chavesConsumidas);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidos(): iterable
    {
        yield 'sem nome' => [['nome' => ' '], 'Informe o seu nome.'];
        yield 'e-mail inválido' => [['email' => 'a@b.com,c@d.com'], 'Informe um e-mail válido para a resposta.'];
        yield 'assunto fora da lista' => [['assunto' => 'Quero desconto'], 'Escolha o assunto.'];
        yield 'mensagem curta' => [['mensagem' => 'oi'], 'Escreva a sua mensagem.'];
        yield 'sem consentimento' => [['consentimento' => ''], 'Marque que concorda com o uso dos seus dados para a resposta.'];
    }

    /** @param array<string, string> $troca */
    #[DataProvider('invalidos')]
    #[TestDox('Inválido ($_dataName): 422 {ok: false, erro}, nada gravado e a cota intacta')]
    public function testInvalido(array $troca, string $erro): void
    {
        $email = uniqid('inv_') . '@cliente.com';

        $this->enviar($troca + ['email' => $email]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['ok' => false, 'erro' => $erro], $this->resposta());
        self::assertNull($this->mensagemDe($email));
        self::assertSame([], $this->limite()->chavesConsumidas);
    }

    #[TestDox('Cota do IP esgotada: 429 {ok: false, erro} e nada gravado')]
    public function testCotaEsgotada(): void
    {
        $this->limite(aceita: false);
        $email = uniqid('cota_') . '@cliente.com';

        $this->enviar(['email' => $email]);

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['ok' => false, 'erro' => 'Muitas tentativas. Tente de novo mais tarde.'], $this->resposta());
        self::assertNull($this->mensagemDe($email));
    }

    #[TestDox('Só POST: GET /contato não é a rota do formulário')]
    public function testSoPost(): void
    {
        $this->client->request('GET', '/contato');

        self::assertResponseStatusCodeSame(405);
    }

    /** @param array<string, string> $campos */
    private function enviar(array $campos): void
    {
        $this->limite();
        $this->client->request('POST', '/contato', $campos + [
            'nome'          => 'Bia Visitante',
            'email'         => 'bia@cliente.com',
            'assunto'       => 'Parcerias institucionais',
            'mensagem'      => 'Quero conhecer o BlueJus.',
            'consentimento' => '1',
            'website'       => '',
        ]);
    }

    private function limite(bool $aceita = true): RateLimiterFactoryEspiao
    {
        if ($this->limite === null) {
            $this->limite = new RateLimiterFactoryEspiao($aceita);
            static::getContainer()->set('limiter.suporte_entrada', $this->limite);
        }

        return $this->limite;
    }

    /** @return array<string, mixed> */
    private function resposta(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function mensagemDe(string $email): ?MensagemSuporte
    {
        $this->em()->clear();

        return $this->em()->getRepository(MensagemSuporte::class)->findOneBy(['email' => $email]);
    }
}
