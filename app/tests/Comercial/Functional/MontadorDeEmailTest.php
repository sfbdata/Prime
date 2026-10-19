<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Service\MontadorDeEmail;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Entity\Tenant\Tenant;
use App\Tests\Comercial\Support\MontaOMontador;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;

/**
 * O e-mail montado (spec §3.1 passo 4, §3.7, §3.8): rodapé por escopo e tipo, link de descadastro em todo e-mail,
 * List-Unsubscribe só em lista, identidade (reply-to do chamador, nome do remetente), rastreio só com as duas chaves e
 * a reescrita que poupa o descadastro e a Política.
 */
#[CoversClass(MontadorDeEmail::class)]
final class MontadorDeEmailTest extends KernelTestCase
{
    use MontaOMontador;

    private const TOKEN = '0123456789abcdef0123456789abcdef';
    private const RAZAO_SOCIAL = 'BlueJus Gestão e Tecnologia LTDA · CNPJ 66.803.163/0001-10 · Brasília/DF';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    private static function mensagem(TipoDeEnvio $tipo, ?Tenant $escopo = null, bool $rastrear = false): MensagemDeEmail
    {
        return new MensagemDeEmail(
            'ana@exemplo.com',
            'Novidades',
            '<p>Veja <a href="https://exemplo.com/artigo?a=1&amp;b=2">o artigo</a>.</p>',
            $tipo,
            'campanha:1',
            $tipo === TipoDeEnvio::Lista ? 'comercial_dicas' : null,
            $escopo,
            'Ana Souza',
            'NOVIDADE',
            'Olá, {nome}',
            rastrear: $rastrear,
        );
    }

    private static function rodape(Email $email): Crawler
    {
        return (new Crawler((string) $email->getHtmlBody()))->filter('td.rodape-legal');
    }

    private function tenant(string $nome): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName($nome);
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    #[TestDox('Plataforma em lista: frase do desenho, "Não quero mais receber" com o link da própria lista, razão social e Política')]
    public function testRodapePlataformaLista(): void
    {
        $email = $this->montador()->montar(self::mensagem(TipoDeEnvio::Lista), self::TOKEN);
        $rodape = self::rodape($email);

        self::assertStringContainsString(self::RAZAO_SOCIAL, $rodape->text());
        self::assertStringContainsString('Você recebeu este e-mail porque se cadastrou no BlueJus ou autorizou o envio de novidades.', $rodape->text());
        $descadastro = $rodape->filter('a[data-descadastro]');
        self::assertSame('Não quero mais receber', $descadastro->text());
        $esperado = static::getContainer()->get(DescadastroDeEmailInterface::class)->gerarLink(null, 'ana@exemplo.com', 'comercial_dicas');
        self::assertSame($esperado, $descadastro->attr('href'));
        self::assertStringContainsString('/politica-de-privacidade', (string) $rodape->filter('a[data-politica]')->attr('href'));
    }

    #[TestDox('Plataforma em transacional: frase da conta e "Não quero receber novidades do BlueJus" com o link de todos')]
    public function testRodapePlataformaTransacional(): void
    {
        $email = $this->montador()->montar(self::mensagem(TipoDeEnvio::Transacional), self::TOKEN);
        $rodape = self::rodape($email);

        self::assertStringContainsString('Você recebeu este e-mail por causa da sua conta no BlueJus.', $rodape->text());
        self::assertStringNotContainsString('autorizou o envio de novidades', $rodape->text());
        $descadastro = $rodape->filter('a[data-descadastro]');
        self::assertSame('Não quero receber novidades do BlueJus', $descadastro->text());
        $esperado = static::getContainer()->get(DescadastroDeEmailInterface::class)->gerarLink(null, 'ana@exemplo.com', 'todos');
        self::assertSame($esperado, $descadastro->attr('href'));
        self::assertStringContainsString(self::RAZAO_SOCIAL, $rodape->text());
    }

    #[TestDox('Escopo de escritório: "Enviado por <escritório> por meio do BlueJus", razão social, descadastro e Política')]
    public function testRodapeEscritorio(): void
    {
        $tenant = $this->tenant('Silva Advogados');

        foreach ([TipoDeEnvio::Lista, TipoDeEnvio::Transacional] as $tipo) {
            $rodape = self::rodape($this->montador()->montar(self::mensagem($tipo, $tenant), self::TOKEN));

            self::assertStringContainsString('Enviado por Silva Advogados por meio do BlueJus', $rodape->text());
            self::assertStringContainsString(self::RAZAO_SOCIAL, $rodape->text());
            self::assertCount(1, $rodape->filter('a[data-descadastro]'), 'link de descadastro em todo e-mail');
            self::assertCount(1, $rodape->filter('a[data-politica]'));
        }
    }

    #[TestDox('Rodapé da confirmação de endereço: a frase alternativa troca só a frase, o resto do rodapé fica')]
    public function testRodapeConfirmacao(): void
    {
        $html = static::getContainer()->get(\Twig\Environment::class)->render(MontadorDeEmail::TEMPLATE, [
            'assunto' => 'Confirme', 'selo' => null, 'titulo' => 'Confirme', 'corpo' => '<p>x</p>', 'logo' => 'https://x/logo.png',
            'tipo' => 'transacional', 'escritorio' => null, 'linkDescadastro' => 'https://x/email/descadastro?d=1',
            'linkPolitica' => 'https://x/politica-de-privacidade', 'pixel' => null,
            'motivoDoEnvio' => 'Este endereço foi informado na página de vagas do BlueJus. Se não foi você, ignore este e-mail.',
        ]);
        $rodape = (new Crawler($html))->filter('td.rodape-legal');

        self::assertStringContainsString('Este endereço foi informado na página de vagas do BlueJus.', $rodape->text());
        self::assertStringNotContainsString('por causa da sua conta', $rodape->text());
        self::assertStringContainsString(self::RAZAO_SOCIAL, $rodape->text());
        self::assertCount(1, $rodape->filter('a[data-descadastro]'));
    }

    #[TestDox('List-Unsubscribe e List-Unsubscribe-Post só em lista, com o link da lista')]
    public function testListUnsubscribeSoEmLista(): void
    {
        $lista = $this->montador()->montar(self::mensagem(TipoDeEnvio::Lista), self::TOKEN);
        $transacional = $this->montador()->montar(self::mensagem(TipoDeEnvio::Transacional), self::TOKEN);

        $link = (string) self::rodape($lista)->filter('a[data-descadastro]')->attr('href');
        self::assertSame('<' . $link . '>', $lista->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
        self::assertSame('List-Unsubscribe=One-Click', $lista->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
        self::assertFalse($transacional->getHeaders()->has('List-Unsubscribe'));
        self::assertFalse($transacional->getHeaders()->has('List-Unsubscribe-Post'));
    }

    #[TestDox('Cabeçalho do desenho, nome no título e no To, prefixo [BlueJus] e versão texto')]
    public function testCabecalhoETexto(): void
    {
        $email = $this->montador()->montar(self::mensagem(TipoDeEnvio::Lista), self::TOKEN);

        self::assertStringContainsString('linear-gradient(135deg,#0a6fa0,#0b4f75)', (string) $email->getHtmlBody());
        self::assertStringContainsString('NOVIDADE', (string) $email->getHtmlBody());
        self::assertStringContainsString('Olá, Ana', (string) $email->getHtmlBody());
        self::assertSame('Ana Souza', $email->getTo()[0]->getName());
        self::assertSame('[BlueJus] Novidades', $email->getSubject());
        $texto = (string) $email->getTextBody();
        self::assertStringContainsString('o artigo (https://exemplo.com/artigo?a=1&b=2)', $texto);
        self::assertStringNotContainsString('<p', $texto);
        self::assertStringNotContainsString('<td', $texto);
    }

    #[TestDox('Identidade: o replyTo da mensagem vence o da plataforma; nomeDoRemetente troca só o nome do From; Cc')]
    public function testIdentidade(): void
    {
        $mensagem = new MensagemDeEmail(
            'ana@exemplo.com',
            'Aviso',
            '<p>x</p>',
            TipoDeEnvio::Transacional,
            'consumidor:push',
            replyTo: 'responsavel@escritorio.com',
            cc: ['controladoria@escritorio.com'],
            nomeDoRemetente: 'Escritório X via BlueJus',
        );
        $padrao = $this->montador()->montar(new MensagemDeEmail('ana@exemplo.com', 'Aviso', '<p>x</p>', TipoDeEnvio::Transacional, 'teste'), self::TOKEN);

        $email = $this->montador()->montar($mensagem, self::TOKEN);

        self::assertSame(['responsavel@escritorio.com'], array_map(static fn ($a) => $a->getAddress(), $email->getReplyTo()));
        self::assertSame(['controladoria@escritorio.com'], array_map(static fn ($a) => $a->getAddress(), $email->getCc()));
        self::assertSame('Escritório X via BlueJus', $email->getFrom()[0]->getName());
        self::assertSame($padrao->getFrom()[0]->getAddress(), $email->getFrom()[0]->getAddress(), 'o endereço do remetente não muda');
    }

    #[TestDox('Rastreio desligado (flag ou constante): rastrear é ignorado, sem pixel e sem link reescrito')]
    public function testRastreioDesligadoIgnoraRastrear(): void
    {
        foreach ([[false, false], [true, false], [false, true]] as [$flag, $constante]) {
            $montador = $this->montador($flag, $constante);
            $email = $montador->montar(self::mensagem(TipoDeEnvio::Lista, rastrear: true), self::TOKEN);

            self::assertFalse($montador->rastreia(self::mensagem(TipoDeEnvio::Lista, rastrear: true)));
            self::assertStringNotContainsString('/e/a/', (string) $email->getHtmlBody());
            self::assertStringNotContainsString('/e/c/', (string) $email->getHtmlBody());
            self::assertStringContainsString('href="https://exemplo.com/artigo?a=1&amp;b=2"', (string) $email->getHtmlBody());
        }
    }

    #[TestDox('O container nasce com o rastreio desligado (padrão e constante)')]
    public function testContainerDesligado(): void
    {
        self::assertFalse(static::getContainer()->getParameter('comercial.rastreio_ligado'));
    }

    #[TestDox('Com a flag ligada e a constante da Política como está (CITA_RASTREIO_DE_EMAIL = false), rastrear é ignorado')]
    public function testConstanteDaPoliticaDesligaORastreio(): void
    {
        $c = static::getContainer();
        $montador = new MontadorDeEmail(
            $c->get(\Twig\Environment::class),
            $c->get(\App\Shared\Email\IdentidadeDeEmail::class),
            $c->get(DescadastroDeEmailInterface::class),
            $c->get(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class),
            self::assinadorDeClique(),
            true,
        );

        $email = $montador->montar(self::mensagem(TipoDeEnvio::Lista, rastrear: true), self::TOKEN);

        self::assertStringNotContainsString('/e/a/', (string) $email->getHtmlBody());
        self::assertStringNotContainsString('/e/c/', (string) $email->getHtmlBody());
    }

    #[TestDox('Rastreio ligado só vale em lista: transacional com rastrear sai sem pixel')]
    public function testRastreioSoEmLista(): void
    {
        $email = $this->montador(true, true)->montar(self::mensagem(TipoDeEnvio::Transacional, rastrear: true), self::TOKEN);

        self::assertStringNotContainsString('/e/a/', (string) $email->getHtmlBody());
        self::assertStringNotContainsString('/e/c/', (string) $email->getHtmlBody());
    }

    #[TestDox('Rastreio ligado: pixel do token e cada link vira /e/c/ assinado, exceto o descadastro e a Política')]
    public function testReescritaPoupaDescadastroEPolitica(): void
    {
        $email = $this->montador(true, true)->montar(self::mensagem(TipoDeEnvio::Lista, rastrear: true), self::TOKEN);
        $pagina = new Crawler((string) $email->getHtmlBody());

        self::assertCount(1, $pagina->filter('img[src$="/e/a/' . self::TOKEN . '.gif"]'));
        $artigo = (string) $pagina->filter('a')->first()->attr('href');
        self::assertStringContainsString('/e/c/' . self::TOKEN . '?', $artigo);
        $consulta = [];
        parse_str((string) parse_url($artigo, PHP_URL_QUERY), $consulta);
        self::assertSame('https://exemplo.com/artigo?a=1&b=2', $consulta['u'] ?? null);
        self::assertNotEmpty($consulta['s'] ?? null);
        self::assertTrue(self::assinadorDeClique()->check((string) parse_url($artigo, PHP_URL_PATH) . '?' . parse_url($artigo, PHP_URL_QUERY)));

        $rodape = $pagina->filter('td.rodape-legal');
        self::assertStringContainsString('/email/descadastro', (string) $rodape->filter('a[data-descadastro]')->attr('href'));
        self::assertStringNotContainsString('/e/c/', (string) $rodape->filter('a[data-descadastro]')->attr('href'));
        self::assertStringEndsWith('/politica-de-privacidade', (string) $rodape->filter('a[data-politica]')->attr('href'));
    }
}
