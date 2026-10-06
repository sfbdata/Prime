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
 * "Responder" no Registro dos expedientes (desenho 1.2.3, l. 1311 e 5954): a resposta
 * fica RECUADA sob a raiz, com a faixa "Resposta a NOME", e não abre dia nem conta.
 *
 * Arranjo por combinador de FILHO DIRETO e de irmão adjacente: "a resposta existe
 * na página" seria verdade mesmo com ela solta no topo da lista.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaRegistroRespostasTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function registrar(Pasta $pasta, User $autor, Tenant $tenant, string $texto, string $quando, ?PastaMensagem $respostaA = null): PastaMensagem
    {
        $msg = (new PastaMensagem())
            ->setPasta($pasta)
            ->setAutor($autor)
            ->setTenant($tenant)
            ->setConteudo($texto)
            ->setRespostaA($respostaA);

        (new \ReflectionProperty(PastaMensagem::class, 'criadaEm'))->setValue($msg, new \DateTimeImmutable($quando));

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

    #[TestDox('a resposta fica recuada logo depois da raiz, com a faixa "Resposta a NOME", e não conta como registro')]
    public function testRespostaRecuadaSobARaiz(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $raiz            = $this->registrar($pasta, $user, $tenant, 'Combinado com o cliente', '-3 minutes');
        $r1              = $this->registrar($pasta, $user, $tenant, 'Primeira resposta', '-2 minutes', $raiz);
        $r2              = $this->registrar($pasta, $user, $tenant, 'Segunda resposta', '-1 minute', $raiz);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $conversa = $crawler->filter('#timelineList > #pasta-msg-' . $raiz->getId() . ' + .ps-respostas[data-resposta-de="' . $raiz->getId() . '"]');
        self::assertCount(1, $conversa, 'a conversa é o irmão logo depois da raiz, filha direta da lista');

        $respostas = $conversa->filter('.ps-respostas > article.ps-anotacao.ps-anotacao--resposta');
        self::assertSame(
            ['pasta-msg-' . $r1->getId(), 'pasta-msg-' . $r2->getId()],
            $respostas->each(fn ($n) => $n->attr('id')),
            'da mais antiga para a mais nova',
        );
        self::assertCount(0, $crawler->filter('#timelineList > #pasta-msg-' . $r1->getId()), 'a resposta não fica solta no primeiro nível');

        self::assertSame(
            'Resposta a ' . $user->getFullName(),
            trim($respostas->first()->filter('article > .ps-anotacao-resposta-rot')->text()),
        );
        self::assertCount(0, $crawler->filter('#timelineList > #pasta-msg-' . $raiz->getId() . ' > .ps-anotacao-resposta-rot'), 'a raiz não tem faixa');

        self::assertSame('1', trim($crawler->filter('#timeline-count')->text()), 'só a raiz conta');
        self::assertCount(1, $crawler->filter('#timelineList > .ps-dia'), 'respostas não abrem dia');
    }

    #[TestDox('raiz e resposta têm o botão Responder, e o da resposta aponta para a RAIZ')]
    public function testBotaoResponderApontaParaARaiz(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        // Fora da janela de 15 min: o Responder não depende de poder editar.
        $raiz            = $this->registrar($pasta, $user, $tenant, 'Original', '-2 hours');
        $resposta        = $this->registrar($pasta, $user, $tenant, 'Resposta', '-1 hour', $raiz);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        foreach ([$raiz, $resposta] as $msg) {
            $botao = $crawler->filter('#pasta-msg-' . $msg->getId() . ' > .ps-anotacao-topo > button.btn-responder-msg-pasta');
            self::assertCount(1, $botao, 'cartão ' . $msg->getId());
            self::assertSame((string) $raiz->getId(), $botao->attr('data-resposta-a'));
            self::assertSame($user->getFullName(), $botao->attr('data-resposta-nome'));
            self::assertCount(0, $crawler->filter('#pasta-msg-' . $msg->getId() . ' .ps-anotacao-acoes'), 'fora da janela não há editar/excluir');
        }

        // O compositor é o mesmo: campo escondido e a faixa "Respondendo a … · cancelar", apagada.
        self::assertCount(1, $crawler->filter('#formTimelineMensagem > input[type="hidden"][name="resposta_a"]#timelineRespostaA'));
        self::assertCount(1, $crawler->filter('#formTimelineMensagem > #timelineRespondendo.d-none > button#timelineRespondendoCancelar'));
    }

    #[TestDox('dentro dos 15 min a resposta também mostra editar/excluir e o "· N min"')]
    public function testJanelaDeEdicaoValeParaResposta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $raiz            = $this->registrar($pasta, $user, $tenant, 'Original', '-1 hour');
        $resposta        = $this->registrar($pasta, $user, $tenant, 'Resposta', '-5 minutes', $raiz);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $acoes = $crawler->filter('.ps-respostas > #pasta-msg-' . $resposta->getId() . ' > .ps-anotacao-topo > .ps-anotacao-acoes');
        self::assertCount(1, $acoes);
        self::assertCount(1, $acoes->filter('.ps-anotacao-acoes > .btn-editar-msg-pasta[data-url][data-csrf]'));
        self::assertCount(1, $acoes->filter('.ps-anotacao-acoes > .btn-excluir-msg-pasta[data-url][data-csrf]'));
        self::assertSame('· 10 min', trim($acoes->filter('.ps-anotacao-acoes > .ps-anotacao-janela')->text()));
    }

    #[TestDox('resposta cuja original foi excluída fica no primeiro nível dizendo "Resposta a uma mensagem excluída"')]
    public function testRespostaOrfa(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $raiz            = $this->registrar($pasta, $user, $tenant, 'Original', '-2 hours');
        $resposta        = $this->registrar($pasta, $user, $tenant, 'Resposta', '-1 hour', $raiz);

        // O banco solta o vínculo (ON DELETE SET NULL) quando a original é apagada.
        $this->em()->remove($raiz);
        $this->em()->flush();
        $this->em()->clear();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $orfa = $crawler->filter('#timelineList > article#pasta-msg-' . $resposta->getId() . '.ps-anotacao--resposta');
        self::assertCount(1, $orfa, 'sem a raiz, a resposta é filha direta da lista');
        self::assertSame('Resposta a uma mensagem excluída', trim($orfa->filter('article > .ps-anotacao-resposta-rot')->text()));
        self::assertCount(0, $crawler->filter('#timelineList > .ps-respostas'));
        self::assertSame('1', trim($crawler->filter('#timeline-count')->text()), 'a órfã passa a contar como registro');
        // Responder a ela liga a ela mesma.
        self::assertSame(
            (string) $resposta->getId(),
            $orfa->filter('article > .ps-anotacao-topo > .btn-responder-msg-pasta')->attr('data-resposta-a'),
        );
    }

    #[TestDox('respostas de uma raiz entre os "anteriores" nascem escondidas junto com ela')]
    public function testRespostasEscondidasComOsAnteriores(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $antiga          = $this->registrar($pasta, $user, $tenant, 'A mais antiga', '-5 hours');
        $this->registrar($pasta, $user, $tenant, 'Resposta à antiga', '-10 minutes', $antiga);
        foreach (range(1, 4) as $i) {
            $this->registrar($pasta, $user, $tenant, 'Recente ' . $i, '-' . (5 - $i) . ' hours');
        }

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(1, $crawler->filter(
            '#timelineList > #pasta-msg-' . $antiga->getId() . '.ps-anotacao--extra.d-none + .ps-respostas.ps-anotacao--extra.d-none'
        ));
        self::assertSame('5', trim($crawler->filter('#timeline-count')->text()));
    }
}
