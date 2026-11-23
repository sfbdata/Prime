<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Tela do modo campanha "BlueJus Black" (spec docs/specs/contribua-campanha.md, "Contratos da tela"):
 * faixa #black, FAQ, painel #campanha do Master e aba do topo. Os dados da campanha entram por SQL nas
 * tabelas da spec, para o teste não depender do construtor da entidade.
 */
final class CampanhaTelaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private User $comum;
    private User $master;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->escritorio = new Tenant();
        $this->escritorio->setName('Escritório CAMPANHA ' . uniqid());
        $this->em->persist($this->escritorio);
        $this->em->flush();

        $this->comum = $this->pessoa('comum', ['ROLE_USER']);
        $this->master = $this->pessoa('master', ['ROLE_SUPER_ADMIN']);
    }

    #[TestDox('Campanha ativa: a faixa #black é filha direta de .ct-pagina, entre a Apresentação e o Convênio')]
    public function testFaixaComCampanhaAtiva(): void
    {
        $this->campanha(true);
        $this->abrir($this->comum, '/contribua');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.ct-pagina > section#black.cp-faixa');
        self::assertSelectorTextContains('.ct-pagina > section#black .cp-faixa-titulo', 'BlueJus Black: Desafio de R$ 1,00. Não gostou? Devolvemos R$ 2,00.');
        self::assertSelectorTextContains('.ct-pagina > section#black > .cp-faixa-botao[data-campanha-abrir]', 'Conhecer o Desafio');
        $ids = $this->client->getCrawler()->filter('.ct-pagina > section')->each(
            static fn ($no): string => (string) ($no->attr('id') ?: ''),
        );
        self::assertSame(['', 'black', 'convenio'], \array_slice($ids, 0, 3), 'Apresentação, faixa, Convênio');
    }

    #[TestDox('Campanha desligada: sem faixa, sem item do FAQ e sem frase paga para o usuário comum')]
    public function testSemCampanhaLigada(): void
    {
        $this->campanha(false);
        $html = $this->abrir($this->comum, '/contribua');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('section#black');
        self::assertSelectorNotExists('section#campanha');
        self::assertSelectorCount(5, '#faq details.ct-faq');
        self::assertStringNotContainsString('inscrição opcional no Desafio', $html);
        self::assertStringNotContainsString('por que o Desafio de', $html);
    }

    #[TestDox('FAQ: a resposta paga ganha a frase da campanha e o item do Desafio vem logo depois, com o link das regras')]
    public function testFaq(): void
    {
        $this->campanha(true);
        $html = $this->abrir($this->comum, '/contribua');

        self::assertSelectorCount(6, '#faq details.ct-faq');
        self::assertStringContainsString('Durante a campanha BlueJus Black, existe também a inscrição opcional no Desafio, de R$ 1,00, com devolução de R$ 2,00 se você não gostar.', $html);
        $perguntas = $this->client->getCrawler()->filter('#faq details.ct-faq > summary')->each(
            static fn ($no): string => trim($no->text()),
        );
        self::assertSame('Existe alguma coisa paga?', $perguntas[2]);
        self::assertSame('Se o BlueJus é gratuito, por que o Desafio de R$ 1,00?', $perguntas[3]);
        // O texto da pergunta é um item flex só (o summary tem gap de 8px): nada no meio dele ganha espaço extra.
        self::assertSelectorCount(6, '#faq details.ct-faq > summary > span');
        self::assertStringContainsString('em até 30 dias e recebe R$ 2,00, o dobro do que pagou', $this->client->getCrawler()->filter('#faq details.ct-faq > div')->eq(3)->text());
        self::assertSelectorExists('#faq details.ct-faq > div > a[data-campanha-abrir][href="#black"]');
    }

    #[TestDox('O painel #campanha existe só para o Master, depois do Suporte e antes da Integração bancária')]
    public function testPainelSoParaOMaster(): void
    {
        $this->campanha(true);

        $this->abrir($this->comum, '/contribua');
        self::assertSelectorNotExists('section#campanha');
        self::assertSelectorNotExists('[data-cp-painel]');

        $this->abrir($this->master, '/contribua');
        self::assertSelectorCount(1, '.ct-pagina > section#campanha.cp-painel[data-cp-painel]');
        self::assertSelectorTextContains('.ct-pagina > section#campanha > .cp-painel-topo > .cp-painel-titulo', 'Modo campanha: BlueJus Black');
        self::assertSelectorTextContains('#campanha .cp-painel-estado', 'No ar');
        self::assertSelectorExists('#campanha button[role="switch"][data-cp-ligar]');
        self::assertSelectorExists('#campanha input[data-cp-campo="preco"]');
        self::assertSelectorExists('#campanha button[data-cp-mult="3"]');
        self::assertSelectorExists('#campanha button[data-cp-padrao]');

        $filhos = $this->client->getCrawler()->filter('.ct-pagina > section')->each(
            static fn ($no): string => (string) ($no->attr('id') ?: ($no->attr('class') ?? '')),
        );
        $suporte = array_search('ct-cartao ct-suporte', $filhos, true);
        $campanha = array_search('campanha', $filhos, true);
        self::assertNotFalse($suporte);
        self::assertSame($suporte + 1, $campanha, 'o painel vem logo depois do Suporte');
        self::assertCount($campanha + 2, $filhos, 'depois dele só a Integração bancária');
    }

    #[TestDox('Sem aba no topo (pedido M5.6, 23/11): outra tela logada não mostra a campanha, mas a janela segue abrindo pelo link #black')]
    public function testSemAbaNoTopoEmOutraTela(): void
    {
        $this->campanha(true);
        $this->abrir($this->comum, '/perfil');

        self::assertSelectorNotExists('#cp-aba');
        self::assertSelectorNotExists('.cp-aba');
        self::assertSelectorExists('#cp-raiz[data-url-janela="/contribua/campanha/janela"]');
        self::assertSelectorExists('script[src*="contribua-campanha.js"]');

        $this->abrir($this->master, '/dashboard');
        self::assertSelectorNotExists('#cp-aba', 'nem a prévia do Master');
    }

    #[TestDox('O painel do Master não tem mais a caixa "Aba no topo de todas as telas"')]
    public function testPainelSemCaixaDaAba(): void
    {
        $this->campanha(true);
        $html = $this->abrir($this->master, '/contribua');

        self::assertSelectorExists('[data-cp-painel]');
        self::assertSelectorNotExists('[data-cp-campo="abaNoTopo"]');
        self::assertStringNotContainsString('Aba no topo de todas as telas', $html);
    }

    private function abrir(User $quem, string $url): string
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $this->client->request('GET', $url);

        return (string) $this->client->getResponse()->getContent();
    }

    /** Deixa uma única campanha (a padrão do desenho: R$ 1,00, dobro, 30 dias), de ontem a daqui a 30 dias. */
    private function campanha(bool $ligada): void
    {
        $con = static::getContainer()->get(Connection::class);
        $con->executeStatement('DELETE FROM contribua_campanha_participacao');
        $con->executeStatement('DELETE FROM contribua_campanha');
        $con->insert('contribua_campanha', [
            'nome' => 'BlueJus Black',
            'chamada' => 'Black Friday BlueJus',
            'preco_centavos' => 100,
            'multiplicador' => 2,
            'prazo_pedido_dias' => 30,
            'prazo_pagamento_dias_uteis' => 7,
            'inicio' => (new \DateTimeImmutable('-1 day', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d'),
            'fim' => (new \DateTimeImmutable('+30 days', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d'),
            'vagas' => 0,
            'mostrar_contador' => 'false',
            'aba_no_topo' => 'true',
            'beneficios' => json_encode(['Treinamento ao vivo para a sua equipe'], \JSON_THROW_ON_ERROR),
            'ligada' => $ligada ? 'true' : 'false',
            'versao_regras' => 1,
            'atualizada_em' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /** @param list<string> $roles */
    private function pessoa(string $prefixo, array $roles): User
    {
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa ' . ucfirst($prefixo));
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $papel = new TenantRole();
        $papel->setTenant($this->escritorio);
        $papel->setName('Papel ' . uniqid());
        $papel->setIsSystem(false);
        $this->em->persist($papel);

        $vinculo = new UserTenant($user, $this->escritorio);
        $vinculo->setTenantRole($papel);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }
}
