<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A tela pergunta a janela de editar/excluir ao MESMO serviço que os UseCases usam
 * (`JanelaDeEdicaoDeComentario`, 15 min — decisão do dono, 2026-10-05): dentro dela o autor vê
 * os botões e "· N min"; fora dela (aqui, 20 min — que a regra antiga de 24h ainda mostraria)
 * os botões somem. Vale para os três lugares: chat da aba Dados, Detalhes e Financeiro.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaJanelaDeEdicaoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    /**
     * @template T of PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function comentar(string $classe, Pasta $pasta, User $autor, Tenant $tenant, string $quando): object
    {
        $comentario = (new $classe())
            ->setPasta($pasta)
            ->setAutor($autor)
            ->setTenant($tenant)
            ->setConteudo('Comentário de teste');

        // `criadaEm` nasce "agora" e não tem setter: reflexão é o único jeito de envelhecer.
        (new \ReflectionProperty($classe, 'criadaEm'))->setValue($comentario, new \DateTimeImmutable($quando));

        $this->em()->persist($comentario);
        $this->em()->flush();

        return $comentario;
    }

    private function abrir(object $client, Pasta $pasta): object
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[TestDox('dentro dos 15 min o autor vê editar/excluir e quanto falta ("· 10 min") nos três lugares')]
    public function testDentroDaJanelaMostraBotoesEMinutosRestantes(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $msg = $this->comentar(PastaMensagem::class, $pasta, $user, $tenant, '-5 minutes');
        $det = $this->comentar(PastaObservacaoDetalhes::class, $pasta, $user, $tenant, '-5 minutes');
        $fin = $this->comentar(PastaObservacaoFinanceira::class, $pasta, $user, $tenant, '-5 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        foreach ([
            '#pasta-msg-' . $msg->getId()      => ['.btn-editar-msg-pasta', '.btn-excluir-msg-pasta'],
            '#detalhes-obs-' . $det->getId()   => ['.btn-editar-obs-det', '.btn-excluir-obs-det'],
            '#financeiro-obs-' . $fin->getId() => ['.btn-editar-obs', '.btn-excluir-obs'],
        ] as $cartao => [$editar, $excluir]) {
            $acoes = $crawler->filter($cartao . ' > .ps-anotacao-topo > .ps-anotacao-acoes');
            self::assertCount(1, $acoes, $cartao);
            self::assertCount(1, $acoes->filter('.ps-anotacao-acoes > ' . $editar . '[data-url][data-csrf]'), $cartao);
            self::assertCount(1, $acoes->filter('.ps-anotacao-acoes > ' . $excluir . '[data-url][data-csrf]'), $cartao);
            // Criado há 5 min: faltam um pouco menos de 10 → arredonda para cima, 10.
            self::assertSame('· 10 min', trim($acoes->filter('.ps-anotacao-acoes > .ps-anotacao-janela')->text()), $cartao);
        }
    }

    #[TestDox('passados os 15 min (20 min, que as 24h antigas ainda aceitariam) os botões e o "· N min" somem')]
    public function testForaDaJanelaEscondeBotoes(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $msg = $this->comentar(PastaMensagem::class, $pasta, $user, $tenant, '-20 minutes');
        $det = $this->comentar(PastaObservacaoDetalhes::class, $pasta, $user, $tenant, '-20 minutes');
        $fin = $this->comentar(PastaObservacaoFinanceira::class, $pasta, $user, $tenant, '-20 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        foreach (['#pasta-msg-' . $msg->getId(), '#detalhes-obs-' . $det->getId(), '#financeiro-obs-' . $fin->getId()] as $cartao) {
            self::assertCount(1, $crawler->filter($cartao), $cartao);
            self::assertCount(0, $crawler->filter($cartao . ' .ps-anotacao-acoes'), $cartao);
            self::assertCount(0, $crawler->filter($cartao . ' .ps-anotacao-janela'), $cartao);
        }
    }

    #[TestDox('o cartão montado no cliente após publicar nasce com "· 15 min" (o JS recebe a duração do servidor)')]
    public function testCartaoMontadoNoClienteNasceComAJanelaInteira(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $this->abrir($client, $pasta);
        $html = (string) $client->getResponse()->getContent();

        // Os três montadores em JS (chat, Detalhes, Financeiro) carregam o mesmo rótulo.
        self::assertSame(3, substr_count($html, '>· 15 min</span>'));
    }
}
