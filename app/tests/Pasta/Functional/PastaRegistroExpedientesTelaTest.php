<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * ARRANJO da aba Dados no desenho "02 - EXPEDIENTES 1.2.3" (padrão PJe): o
 * "Registro dos expedientes" é uma linha do tempo — pílula com o dia, um cartão
 * por registro (avatar, autor, hora, texto) — e os cartões do trilho ganham o
 * chevron › que leva à aba e o ícone do tipo de arquivo.
 *
 * Tudo por combinador de FILHO DIRETO ou de irmão adjacente: a pílula do dia
 * PRECEDE o primeiro registro daquele dia (`.ps-dia + .ps-anotacao`), e "existe
 * na página" não distingue isso de uma pílula solta. Cor, sombra e a seta do
 * cartão seguem invisíveis para o teste — é smoke do dono.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaRegistroExpedientesTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function registrar(Pasta $pasta, User $autor, Tenant $tenant, string $texto, ?\DateTimeImmutable $quando = null): PastaMensagem
    {
        $msg = (new PastaMensagem())
            ->setPasta($pasta)
            ->setAutor($autor)
            ->setTenant($tenant)
            ->setConteudo($texto);

        // `criadaEm` nasce "agora" no construtor e não tem setter — e não deve ter:
        // é o servidor que data o registro. Para provar a pílula de OUTRO dia, o
        // teste precisa de um registro de ontem; reflexão é o único caminho.
        if ($quando !== null) {
            $prop = new \ReflectionProperty(PastaMensagem::class, 'criadaEm');
            $prop->setValue($msg, $quando);
        }

        $this->em()->persist($msg);
        $this->em()->flush();

        return $msg;
    }

    private function abrir(object $client, Pasta $pasta): object
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[TestDox('o painel se chama "Registro dos expedientes" e, sem registro, a lista fica no estado vazio (sem linha do tempo)')]
    public function testPainelVazio(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $painel = $crawler->filter('.ps-grade > .ps-anotacoes');
        self::assertCount(1, $painel, 'o painel é filho direto da grade da aba Dados');
        self::assertSame('Registro dos expedientes', trim($painel->filter('.ps-anotacoes > .ps-card-cab--painel > h2')->text()));
        self::assertSame('0', trim($painel->filter('#timeline-count')->text()));

        $lista = $painel->filter('#timelineList.ps-registro.ps-registro--vazio');
        self::assertCount(1, $lista, 'sem registro a lista se declara vazia: o CSS não desenha a linha do tempo');
        self::assertCount(1, $lista->filter('#timelineList > #timelineVazio'));
        self::assertCount(0, $lista->filter('.ps-dia'));
        self::assertNotEmpty($lista->attr('data-hoje'), 'o JS precisa da chave do dia de hoje para abrir a pílula do registro novo');
    }

    #[TestDox('cada dia abre com UMA pílula ("5 out 2026") que precede o primeiro registro daquele dia')]
    public function testPilulaDoDiaPrecedeOPrimeiroRegistroDoDia(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $ontem           = new \DateTimeImmutable('yesterday 10:15');
        $this->registrar($pasta, $user, $tenant, 'Registro de ontem', $ontem);
        $this->registrar($pasta, $user, $tenant, 'Primeiro de hoje');
        $this->registrar($pasta, $user, $tenant, 'Segundo de hoje');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $lista = $crawler->filter('#timelineList.ps-registro');
        self::assertCount(1, $lista);
        self::assertCount(0, $crawler->filter('#timelineList.ps-registro--vazio'));

        $pilulas = $lista->filter('#timelineList > .ps-dia');
        self::assertCount(2, $pilulas, 'dois dias = duas pílulas; os dois registros de hoje dividem a mesma');
        self::assertCount(3, $lista->filter('#timelineList > article.ps-anotacao'));

        // A pílula de hoje precede o primeiro registro de hoje; a de ontem, o de ontem.
        self::assertCount(2, $lista->filter('#timelineList > .ps-dia + .ps-anotacao'), 'cada pílula é imediatamente seguida por um registro');
        $hoje = new \DateTimeImmutable();
        self::assertSame($hoje->format('Y-m-d'), $pilulas->first()->attr('data-dia'), 'o mais recente vem primeiro');
        self::assertSame($ontem->format('Y-m-d'), $pilulas->last()->attr('data-dia'));

        $meses = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
        self::assertSame(
            $ontem->format('j') . ' ' . $meses[(int) $ontem->format('n') - 1] . ' ' . $ontem->format('Y'),
            trim($pilulas->last()->filter('.ps-dia > .ps-dia-pilula')->text()),
            'o rótulo é "dia mês-abreviado ano", como no desenho'
        );
    }

    #[TestDox('a pílula de um dia cujo primeiro registro está entre os "anteriores" nasce escondida junto com ele')]
    public function testPilulaEscondidaComOsAnteriores(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->registrar($pasta, $user, $tenant, 'De ontem', new \DateTimeImmutable('yesterday 09:00'));
        foreach (range(1, 4) as $i) {
            $this->registrar($pasta, $user, $tenant, 'Hoje ' . $i);
        }

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        // 4 visíveis hoje; o 5º (de ontem) é "anterior" — e a pílula de ontem vai junto.
        self::assertCount(1, $crawler->filter('#timelineList > .ps-dia.ps-anotacao--extra.d-none + article.ps-anotacao.ps-anotacao--extra.d-none'));
        self::assertCount(1, $crawler->filter('#timelineList > .ps-dia:not(.d-none)'), 'só a pílula de hoje aparece de cara');
        self::assertCount(1, $crawler->filter('#psAnotacoesMais'), 'o botão revela os dois juntos (mesma classe)');
    }

    #[TestDox('o cartão do registro tem avatar, autor, só a HORA (o dia está na pílula) e o texto com o megafone')]
    public function testCartaoDoRegistro(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $msg             = $this->registrar($pasta, $user, $tenant, 'Combinado com o cliente');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $cartao = $crawler->filter('#timelineList > article#pasta-msg-' . $msg->getId());
        self::assertCount(1, $cartao);

        $topo = $cartao->filter('article > .ps-anotacao-topo');
        self::assertCount(1, $topo, 'a linha de cima é filha direta do cartão');
        self::assertCount(1, $topo->filter('.ps-anotacao-topo > .ps-avatar.ps-avatar--24'));
        self::assertSame($user->getFullName(), trim($topo->filter('.ps-anotacao-topo > .ps-anotacao-autor')->text()));

        $hora = $topo->filter('.ps-anotacao-topo > .ps-anotacao-data');
        self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', trim($hora->text()), 'só a hora; a data inteira fica no title');
        self::assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/', (string) $hora->attr('title'));

        // Autor dentro da janela: editar e excluir continuam no cartão, com o contrato do JS.
        self::assertCount(1, $topo->filter('.ps-anotacao-acoes > .btn-editar-msg-pasta[data-url][data-csrf][data-conteudo]'));
        self::assertCount(1, $topo->filter('.ps-anotacao-acoes > .btn-excluir-msg-pasta[data-url][data-csrf]'));

        $corpo = $cartao->filter('article > .ps-anotacao-corpo');
        self::assertCount(1, $corpo->filter('.ps-anotacao-corpo > i.ps-anotacao-icone'));
        self::assertStringContainsString('Combinado com o cliente', $corpo->filter('.ps-anotacao-corpo > .ps-anotacao-texto.pasta-msg-conteudo')->text());
    }

    #[TestDox('os cartões do trilho têm o chevron › (botão) que leva à aba, no lugar dos links "metas"/"todos"')]
    public function testChevronDosCartoesDoTrilho(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(1, $crawler->filter('[data-trilho="prazos"] > .ps-card-cab > button.ps-card-cab-link[data-ps-ir-aba="tarefas-tab"]'));
        self::assertCount(1, $crawler->filter('[data-trilho="documentos"] > .ps-card-cab > button.ps-card-cab-link[data-ps-ir-aba="documentos-tab"]'));
        self::assertCount(0, $crawler->filter('.ps-trilho a[href="#"]'), 'nenhum link morto no trilho');
        // A ordem aprovada do trilho da aba DADOS não mudou (o Financeiro tem o seu próprio trilho).
        self::assertSame(
            ['prazos', 'clientes', 'documentos'],
            $crawler->filter('#dados > .ps-grade > .ps-trilho > [data-trilho]')->each(fn ($n) => $n->attr('data-trilho'))
        );
    }
}
