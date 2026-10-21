<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\DTO\PastaMetasResumoOutput;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * "Responsáveis nas metas" abre a conversa (desenho 02 L.1683-1692 e L.6706; spec `encaminhar-via-chat-ia.md` §4.1,
 * PR3): cada pessoa é um botão com a dica "Conversar com PRIMEIRO_NOME no chat", o `bi-chat-dots` e o id do colega, e o
 * pasta-metas.js chama a janela pelo evento `bj-central:conversar`.
 */
#[CoversClass(PastaMetasResumoOutput::class)]
final class PastaMetasResponsaveisConversaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    #[TestDox('Com a Central na página, cada responsável é um botão filho direto da lista, com dica, ícone e o id do colega')]
    public function testResponsavelViraBotao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $user->setFullName('Mariana Costa');
        $pasta = $this->criarPasta($tenant);
        $meta  = (new Tarefa())->setTitulo('Protocolar a réplica')->setDescricao('Prazo de 15 dias')->setStatus('pendente')->setPasta($pasta)->setTenant($tenant)->setCriadoPor($user);
        $meta->addResponsavel($user);
        $pasta->getTarefas()->add($meta);   // a pasta já está no EntityManager: a coleção não se recarrega sozinha
        $this->em()->persist($meta);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $id    = $user->getId();
        $botao = $crawler->filter('.ps-metas-pessoas > .ps-metas-pessoas-lista > button.js-metas-conversar[data-user-id="' . $id . '"]');
        self::assertCount(1, $botao);
        self::assertSame('Conversar com Mariana no chat', $botao->attr('title'));
        self::assertSame('Conversar com Mariana no chat', $botao->attr('aria-label'));
        self::assertCount(1, $botao->filter('button > span.ps-metas-pessoa-nome + span.ps-metas-pessoa-n + i.bi-chat-dots'));
        self::assertSame('1 meta', trim($botao->filter('.ps-metas-pessoa-n')->text()));

        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/pasta-metas.js');
        self::assertStringContainsString("e.target.closest('.js-metas-conversar')", $js);
        self::assertStringContainsString("new CustomEvent('bj-central:conversar', { detail: { userId: id } })", $js);
    }
}
