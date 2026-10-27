<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Notificacao;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Pasta\Controller\PushCompartilhadoController;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Pasta\Entity\Pasta;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use App\Master\Entity\TemaConfig;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A página pública do link externo do Push (spec push-link-externo §3-4, §7): sem login, todos os
 * estados, código, bloqueio, acesso contado só no `ok`, e o que NUNCA aparece.
 */
#[CoversClass(PushCompartilhadoController::class)]
#[Group('pasta')]
final class PushCompartilhadoControllerTest extends JusPrimeWebTestCase
{
    use CriaLinkExternoDoPushTrait;

    private const FIM_DO_TEOR = 'TRECHO-FINAL-DO-TEOR-QUE-NUNCA-SAI';

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    /** @return array{LinkExternoDoPush, Pasta} */
    private function cenario(?string $codigo = null, string $criadoHa = 'now', bool $incluirAnalises = false): array
    {
        [$user, $tenant] = $this->criarAdmin();
        $user->setOabNumero('12345');
        $user->setOabUf('DF');
        $teor = '<p>Fica a parte intimada para manifestação.</p>' . str_repeat(' palavra', 80) . ' ' . self::FIM_DO_TEOR;
        [$pasta, $processo] = $this->criarPastaComPush($tenant, $teor);
        $pasta->setNomeCliente('Maria Silva');
        $this->em()->flush();

        return [$this->criarLink($tenant, $pasta, $processo, $user, $codigo, $criadoHa, $incluirAnalises), $pasta];
    }

    private function conteudo(KernelBrowser $client): string
    {
        return (string) $client->getResponse()->getContent();
    }

    private function gravarTema(string $modo, ?string $manual): void
    {
        $this->em()->clear();
        $config = $this->em()->getRepository(TemaConfig::class)->findOneBy([]) ?? new TemaConfig();
        $config->definirModo($modo, $manual, null, new \DateTimeImmutable());
        $this->em()->persist($config);
        $this->em()->flush();
        $this->em()->clear();
    }

    #[TestDox('Tema comemorativo ativo: variáveis do tema, fita e cabeçalho marcado; sem tema, nada é injetado')]
    public function testTemaComemorativo(): void
    {
        $client = $this->cliente();
        [$link] = $this->cenario();

        $this->gravarTema('desligado', null);
        $client->request('GET', '/p/' . $link->getEndereco());
        self::assertStringNotContainsString('id="bjtFita"', $this->conteudo($client), 'sem tema não se injeta a fita');

        $this->gravarTema('manual', 'outrosa');

        $crawler = $client->request('GET', '/p/' . $link->getEndereco());

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('header.pc-cabecalho[data-bjt-cabecalho]'));
        self::assertCount(1, $crawler->filter('#bjtTema'));
        self::assertCount(1, $crawler->filter('#bjtFita'));
        self::assertStringContainsString('--bjt-p:#d0558f', $this->conteudo($client));
    }

    #[TestDox('Sem login: 200, cabeçalhos de proteção, processo com máscara, cliente, movimentação e 1 acesso contado')]
    public function testOkSemLogin(): void
    {
        $client = $this->cliente();
        [$link] = $this->cenario();

        $client->request('GET', '/p/' . $link->getEndereco());

        self::assertResponseStatusCodeSame(200);
        $resposta = $client->getResponse();
        self::assertSame('noindex, nofollow', $resposta->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $resposta->headers->get('Referrer-Policy'));
        $html = $this->conteudo($client);
        self::assertStringContainsString('0701134-57.2025.8.07.0007', $html);
        self::assertStringContainsString('MARIA SILVA', $html);
        self::assertStringContainsString('Fica a parte intimada para manifestação.', $html);
        self::assertStringContainsString('Movimentações recebidas', $html);
        self::assertStringContainsString('OAB 12345/DF', $html);
        self::assertStringContainsString('Consulta protegida: impressão, cópia e captura são bloqueadas', $html);
        self::assertStringNotContainsString(self::FIM_DO_TEOR, $html, 'o teor inteiro nunca aparece');
        self::assertStringNotContainsString('base.html', $html);
        self::assertSame(1, $this->contarAcessos($link));
    }

    #[TestDox('Logado em OUTRO escritório: a página abre igual (o TenantFilter da sessão não esconde o link)')]
    public function testLogadoEmOutroEscritorio(): void
    {
        $client = $this->cliente();
        [$link] = $this->cenario();
        [$outro, $outroTenant] = $this->criarAdmin();

        $this->logarComTenant($client, $outro, $outroTenant);
        $client->request('GET', '/p/' . $link->getEndereco());

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Fica a parte intimada para manifestação.', $this->conteudo($client));
    }

    #[TestDox('Endereço desconhecido: 404 "Link não encontrado"')]
    public function testNaoEncontrado(): void
    {
        $client = $this->cliente();
        $client->request('GET', '/p/bluejus-nao-existe-aaaaaaaa');

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('Link não encontrado', $this->conteudo($client));
    }

    #[TestDox('Revogado: "Link desativado", sem dado do processo e sem acesso contado')]
    public function testRevogado(): void
    {
        $client = $this->cliente();
        [$link] = $this->cenario();
        $link->revogar($link->getCriadoPor(), new \DateTimeImmutable());
        $this->em()->flush();

        $client->request('GET', '/p/' . $link->getEndereco());

        $html = $this->conteudo($client);
        self::assertStringContainsString('Link desativado', $html);
        self::assertStringNotContainsString('0701134-57.2025.8.07.0007', $html);
        self::assertStringNotContainsString('Fica a parte intimada', $html);
        self::assertSame(0, $this->contarAcessos($link));
    }

    #[TestDox('Expirado: pede renovação, avisa quem criou, mostra "pedido enviado"; recusado mostra a recusa')]
    public function testExpiradoEPedidoDeRenovacao(): void
    {
        $client = $this->cliente();
        [$link, $pasta] = $this->cenario(null, '-40 days');
        $endereco = $link->getEndereco();
        $this->instalarCsrfPrevisivel();

        $client->request('GET', '/p/' . $endereco);
        $html = $this->conteudo($client);
        self::assertStringContainsString('Link expirado', $html);
        self::assertStringContainsString('Você pode pedir a renovação ao escritório.', $html);
        self::assertStringContainsString('Pedir renovação do link', $html);
        self::assertStringNotContainsString('Fica a parte intimada', $html);

        $client->request('POST', '/p/' . $endereco . '/renovacao', [
            '_token' => 'TOKEN_push_publico_' . $endereco,
            'nome' => 'Carlos Terceiro',
            'mensagem' => 'Preciso acompanhar',
        ]);
        self::assertResponseRedirects('/p/' . $endereco);

        $aviso = $this->em()->getRepository(Notificacao::class)->findOneBy(['usuario' => $link->getCriadoPor(), 'tipo' => Notificacao::TIPO_PUSH_LINK_RENOVACAO]);
        self::assertNotNull($aviso);
        self::assertSame('Carlos Terceiro pediu a renovação do link externo do Push da Pasta ' . $pasta->getNup() . '.', $aviso->getMensagem());
        self::assertSame('/pasta/' . $pasta->getId() . '#push', $aviso->getUrl());

        $client->request('GET', '/p/' . $endereco);
        $html = $this->conteudo($client);
        self::assertStringContainsString('Pedido de renovação enviado em', $html);
        self::assertStringNotContainsString('Pedir renovação do link', $html);

        $link = $this->em()->find(LinkExternoDoPush::class, $link->getId());
        self::assertNotNull($link);
        $link->recusarRenovacao();
        $this->em()->flush();
        $client->request('GET', '/p/' . $endereco);
        self::assertStringContainsString('O escritório não aceitou a renovação. Fale com o advogado responsável.', $this->conteudo($client));
    }

    #[TestDox('Com código: GET pede o código; errado mostra o erro; certo mostra o andamento; só o certo conta acesso')]
    public function testCodigo(): void
    {
        $client = $this->cliente();
        [$link] = $this->cenario('123456');
        $endereco = $link->getEndereco();
        $this->instalarCsrfPrevisivel();

        $client->request('GET', '/p/' . $endereco);
        self::assertStringContainsString('Digite o código de 6 dígitos enviado pelo escritório.', $this->conteudo($client));
        self::assertStringNotContainsString('Fica a parte intimada', $this->conteudo($client));

        $client->request('POST', '/p/' . $endereco, ['_token' => 'TOKEN_push_publico_' . $endereco, 'codigo' => '000000']);
        self::assertStringContainsString('Código incorreto. Confira e tente de novo.', $this->conteudo($client));
        self::assertSame(0, $this->contarAcessos($link));

        $client->request('POST', '/p/' . $endereco, ['_token' => 'TOKEN_push_publico_' . $endereco, 'codigo' => '123456']);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Fica a parte intimada para manifestação.', $this->conteudo($client));
        self::assertSame(1, $this->contarAcessos($link));
    }

    #[TestDox('Código sem CSRF válido não é conferido (volta para a página, sem somar tentativa)')]
    public function testCodigoSemCsrf(): void
    {
        $client = $this->cliente();
        [$link] = $this->cenario('123456');

        $client->request('POST', '/p/' . $link->getEndereco(), ['_token' => 'x', 'codigo' => '123456']);

        self::assertResponseRedirects('/p/' . $link->getEndereco());
        self::assertSame(0, $this->contarAcessos($link));
    }

    #[TestDox('Cinco códigos errados: acesso bloqueado, inclusive para o código certo')]
    public function testBloqueioDepoisDeCincoErros(): void
    {
        $client = $this->cliente();
        [$link] = $this->cenario('123456');
        $endereco = $link->getEndereco();
        $this->instalarCsrfPrevisivel();

        for ($i = 0; $i < 5; ++$i) {
            $client->request('POST', '/p/' . $endereco, ['_token' => 'TOKEN_push_publico_' . $endereco, 'codigo' => '000000']);
        }
        self::assertStringContainsString('Acesso bloqueado por 15 minutos', $this->conteudo($client));

        $client->request('POST', '/p/' . $endereco, ['_token' => 'TOKEN_push_publico_' . $endereco, 'codigo' => '123456']);
        self::assertStringContainsString('Acesso bloqueado por 15 minutos', $this->conteudo($client));
        self::assertSame(0, $this->contarAcessos($link));
    }

    #[TestDox('Análise interna do escritório nunca aparece; a compartilhável aparece com o aviso do desenho')]
    public function testAnaliseInternaNuncaAparece(): void
    {
        $client = $this->cliente();
        [$link, $pasta] = $this->cenario(null, 'now', true);
        $this->criarAnalise($link, $pasta, 'RESUMO-INTERNO-SIGILOSO', interna: true);
        $this->criarAnalise($link, $pasta, 'RESUMO-PARA-O-CLIENTE', interna: false);

        $client->request('GET', '/p/' . $link->getEndereco());

        $html = $this->conteudo($client);
        self::assertStringContainsString('RESUMO-PARA-O-CLIENTE', $html);
        self::assertStringContainsString('Gerada por inteligência artificial · não é ato oficial do processo', $html);
        self::assertStringNotContainsString('RESUMO-INTERNO-SIGILOSO', $html);
    }

    #[TestDox('Limitador push_publico estourado: 429 com a mesma página, antes de abrir o link (não conta acesso)')]
    public function testLimitador(): void
    {
        $client = $this->cliente();
        [$link] = $this->cenario();
        $espiao = new RateLimiterFactoryEspiao(aceita: false);
        static::getContainer()->set('limiter.push_publico', $espiao);

        $client->request('GET', '/p/' . $link->getEndereco());

        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString('Muitas tentativas em pouco tempo', $this->conteudo($client));
        self::assertStringNotContainsString('Fica a parte intimada', $this->conteudo($client));
        self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame(['127.0.0.1'], $espiao->chavesConsumidas, 'a chave é o IP');
        self::assertSame(0, $this->contarAcessos($link));
    }

    #[TestDox('Todas as rotas públicas passam pelo limitador (GET, código e renovação)')]
    public function testTodasAsRotasConsomemOLimitador(): void
    {
        $client = $this->cliente();
        [$link] = $this->cenario('123456', '-40 days');
        $espiao = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.push_publico', $espiao);
        $endereco = $link->getEndereco();

        $client->request('GET', '/p/' . $endereco);
        $client->request('POST', '/p/' . $endereco, ['_token' => 'x', 'codigo' => '1']);
        $client->request('POST', '/p/' . $endereco . '/renovacao', ['_token' => 'x']);

        self::assertCount(3, $espiao->chavesConsumidas);
    }

    private function criarAnalise(LinkExternoDoPush $link, Pasta $pasta, string $resumo, bool $interna): void
    {
        $analise = new AnaliseDeInteligencia($link->getTenant(), null, TipoDeAnalise::ResumoPush, AnaliseDeInteligencia::ALVO_PASTA, (int) $pasta->getId(), 'v1', str_repeat('a', 64), []);
        $analise->iniciarProcessamento();
        $analise->concluir($resumo, [['tipo' => 'prazo', 'texto' => 'Prazo de 15 dias']], null, '{}', 'falso', 'falso', null, null, null);
        if (!$interna) {
            $analise->alternarInterna();
        }
        $this->em()->persist($analise);
        $this->em()->flush();
    }
}
