<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaComentarioDestaqueController;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\UseCase\DestacarComentarioUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * "Destacar comentário" (spec pasta-destacar-comentario.md): um por pasta e aba, o último prevalece e avisa de quem
 * era o anterior; as outras abas ficam com o próprio; sem cor tira; só quem edita a pasta; tenant e cadeado; excluir o
 * comentário apaga o destaque; a tela da pasta mostra a cor no cartão.
 */
#[CoversClass(PastaComentarioDestaqueController::class)]
#[CoversClass(DestacarComentarioUseCase::class)]
final class PastaComentarioDestaqueControllerTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private User $primario;
    private Tenant $tenant;
    private Pasta $pasta;

    protected function setUp(): void
    {
        $this->prepararCliente();
        [$this->primario, $this->tenant] = $this->criarEscritorio();
        $this->pasta = $this->criarPasta($this->tenant);
    }

    #[TestDox('Destaca um registro de Dados: grava a cor da paleta, compartilhada (a tela da pasta mostra no cartão)')]
    public function testDestacaEMostraNaTela(): void
    {
        $msg = $this->mensagem($this->primario, 'Primeiro registro');
        $this->entrar($this->primario, $this->tenant);

        $json = $this->destacar('dados', $msg->getId(), '#FFE94D');

        self::assertResponseIsSuccessful();
        self::assertSame(['cor' => '#ffe94d', 'desfeito' => null], $json);
        $raiz = $this->client->request('GET', '/pasta/' . $this->pasta->getId())->filter('#psDestaques');
        self::assertSame(['dados' => [(string) $msg->getId() => '#ffe94d']], json_decode((string) $raiz->attr('data-mapa'), true));
        self::assertSame('1', $raiz->attr('data-pode'));
        self::assertSame('/pasta/' . $this->pasta->getId() . '/comentario/destaque', $raiz->attr('data-url'));
    }

    #[TestDox('Quem só lê a pasta vê o destaque, mas o menu não oferece "Destacar" (data-pode=0)')]
    public function testLeitorVeMasNaoDestaca(): void
    {
        $msg = $this->mensagem($this->primario, 'Registro');
        $this->entrar($this->primario, $this->tenant);
        $this->destacar('dados', $msg->getId(), '#1f3a6e');
        $leitor = $this->criarColaborador($this->tenant, 'Lia Leitora', ['modules.expediente.view', 'resources.pasta.view']);

        $this->entrar($leitor, $this->tenant);
        $raiz = $this->client->request('GET', '/pasta/' . $this->pasta->getId())->filter('#psDestaques');

        self::assertSame('0', $raiz->attr('data-pode'));
        self::assertStringContainsString('#1f3a6e', (string) $raiz->attr('data-mapa'));
    }

    #[TestDox('Um por aba: destacar outro comentário na mesma aba desfaz o anterior e diz de quem era; outra aba fica com o seu')]
    public function testUltimoPrevaleceNaAba(): void
    {
        $colega = $this->criarColaborador($this->tenant, 'Carlos Colega');
        $primeiro = $this->mensagem($colega, 'Do colega');
        $segundo = $this->mensagem($this->primario, 'Meu');
        $obs = $this->observacaoDetalhes('Relatório inicial');
        $this->entrar($this->primario, $this->tenant);

        $this->destacar('dados', $primeiro->getId(), '#86e08a');
        $this->destacar('detalhes', $obs->getId(), '#1b1f22');
        $json = $this->destacar('dados', $segundo->getId(), '#e5534b');

        self::assertSame(['comentario' => $primeiro->getId(), 'autor' => 'Carlos Colega'], $json['desfeito']);
        self::assertSame([['aba' => 'dados', 'cor' => '#e5534b', 'mensagem_id' => $segundo->getId()], ['aba' => 'detalhes', 'cor' => '#1b1f22', 'mensagem_id' => null]], $this->destaquesDaPasta());

        $json = $this->destacar('dados', $segundo->getId(), '#6eaef2');
        self::assertNull($json['desfeito'], 'trocar a cor do mesmo comentário não desfaz ninguém');
    }

    #[TestDox('Sem cor tira o destaque daquele comentário; tirar de um comentário que não está destacado não mexe no outro')]
    public function testSemCorTira(): void
    {
        $destacado = $this->mensagem($this->primario, 'Destacado');
        $outro = $this->mensagem($this->primario, 'Outro');
        $this->entrar($this->primario, $this->tenant);
        $this->destacar('dados', $destacado->getId(), '#ffe94d');

        $this->destacar('dados', $outro->getId(), '');
        self::assertCount(1, $this->destaquesDaPasta());

        self::assertSame(['cor' => null, 'desfeito' => null], $this->destacar('dados', $destacado->getId(), ''));
        self::assertCount(0, $this->destaquesDaPasta());
    }

    #[TestDox('Financeiro também destaca; excluir o comentário apaga o destaque junto')]
    public function testFinanceiroEExclusaoApagaODestaque(): void
    {
        $obs = $this->observacaoFinanceira('Honorários combinados');
        $this->entrar($this->primario, $this->tenant);
        $this->destacar('financeiro', $obs->getId(), '#7fdcef');
        self::assertCount(1, $this->destaquesDaPasta());

        $this->em()->getConnection()->executeStatement('DELETE FROM pasta_observacao_financeira WHERE id = ?', [$obs->getId()]);
        self::assertCount(0, $this->destaquesDaPasta());
    }

    #[TestDox('Só quem pode editar a pasta destaca: colaborador só de leitura recebe 403; pasta trancada para ele também')]
    public function testSoQuemEdita(): void
    {
        $msg = $this->mensagem($this->primario, 'Registro');
        $leitor = $this->criarColaborador($this->tenant, 'Lia Leitora', ['modules.expediente.view', 'resources.pasta.view']);
        $editor = $this->criarColaborador($this->tenant, 'Edu Editor');

        $this->entrar($leitor, $this->tenant);
        $this->destacar('dados', $msg->getId(), '#ffe94d');
        self::assertResponseStatusCodeSame(403);

        $this->trancar($this->pasta, $this->primario);
        $this->entrar($editor, $this->tenant);
        $this->destacar('dados', $msg->getId(), '#ffe94d');
        // A barreira do cadeado responde antes do controller: redireciona quem não vê a pasta.
        self::assertResponseRedirects();
        self::assertCount(0, $this->destaquesDaPasta());
    }

    #[TestDox('Recusas: pasta de outro escritório 404, comentário de outra pasta 404, cor fora da paleta e aba sem comentários 422, token 403')]
    public function testRecusas(): void
    {
        $msg = $this->mensagem($this->primario, 'Registro');
        $outraPasta = $this->criarPasta($this->tenant, 'OUTRO CLIENTE');
        [$outroPrimario, $outroTenant] = $this->criarEscritorio();
        $pastaDeFora = $this->criarPasta($outroTenant);
        $this->entrar($this->primario, $this->tenant);

        $this->destacar('dados', $msg->getId(), '#ffe94d', $pastaDeFora);
        self::assertResponseStatusCodeSame(404);
        $this->destacar('dados', $msg->getId(), '#ffe94d', $outraPasta);
        self::assertResponseStatusCodeSame(404);
        $this->destacar('dados', $msg->getId(), '#123456');
        self::assertResponseStatusCodeSame(422);
        $this->destacar('metas', $msg->getId(), '#ffe94d');
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/pasta/' . $this->pasta->getId() . '/comentario/destaque', ['aba' => 'dados', 'comentario' => $msg->getId(), 'cor' => '#ffe94d', '_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->destaquesDaPasta());
        unset($outroPrimario);
    }

    #[TestDox('Aba oculta para quem pede: o POST é recusado como na barreira (403) e a tela não manda o destaque dela')]
    public function testAbaOculta(): void
    {
        $obs = $this->observacaoFinanceira('Honorários');
        $this->entrar($this->primario, $this->tenant);
        $this->destacar('financeiro', $obs->getId(), '#ffe94d');
        $editor = $this->criarColaborador($this->tenant, 'Edu Editor');
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->tenant, $this->pasta, AbaDaPasta::Financeiro, $this->primario, new \DateTimeImmutable()));
        $this->em()->flush();

        $this->entrar($editor, $this->tenant);
        $json = $this->destacar('financeiro', $obs->getId(), '#e5534b');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('A aba Financeiro está oculta para você nesta pasta.', $json['erro']);
        $raiz = $this->client->request('GET', '/pasta/' . $this->pasta->getId())->filter('#psDestaques');
        self::assertSame([], json_decode((string) $raiz->attr('data-mapa'), true));
        self::assertSame('#ffe94d', $this->destaquesDaPasta()[0]['cor'], 'nada mudou');
    }

    #[TestDox('Comentário de uma aba mandado com o nome de outra é 404; o destaque fica no audit_log')]
    public function testAbaTrocadaEAuditoria(): void
    {
        $obs = $this->observacaoDetalhes('Relatório inicial');
        $this->entrar($this->primario, $this->tenant);

        $this->destacar('dados', $obs->getId(), '#ffe94d');
        self::assertResponseStatusCodeSame(404);

        $this->destacar('detalhes', $obs->getId(), '#ffe94d');
        self::assertResponseIsSuccessful();
        $trilha = (int) $this->em()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE entity_class LIKE '%PastaComentarioDestaque'",
        );
        self::assertGreaterThan(0, $trilha);
    }

    /** @return array<string, mixed> */
    private function destacar(string $aba, ?int $comentario, string $cor, ?Pasta $pasta = null): array
    {
        $id = ($pasta ?? $this->pasta)->getId();
        $this->client->request('POST', '/pasta/' . $id . '/comentario/destaque', [
            'aba' => $aba, 'comentario' => $comentario, 'cor' => $cor, '_token' => 'TOKEN_pasta_comentario_destaque_' . $id,
        ]);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return list<array<string, mixed>> */
    private function destaquesDaPasta(): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            'SELECT aba, cor, mensagem_id FROM pasta_comentario_destaque WHERE pasta_id = ? ORDER BY aba',
            [$this->pasta->getId()],
        );
    }

    private function mensagem(User $autor, string $texto): PastaMensagem
    {
        $msg = (new PastaMensagem())->setPasta($this->pasta)->setAutor($autor)->setTenant($this->tenant)->setConteudo($texto);
        $this->em()->persist($msg);
        $this->em()->flush();

        return $msg;
    }

    private function observacaoDetalhes(string $texto): PastaObservacaoDetalhes
    {
        $obs = (new PastaObservacaoDetalhes())->setPasta($this->pasta)->setAutor($this->primario)->setTenant($this->tenant)->setConteudo($texto);
        $this->em()->persist($obs);
        $this->em()->flush();

        return $obs;
    }

    private function observacaoFinanceira(string $texto): PastaObservacaoFinanceira
    {
        $obs = (new PastaObservacaoFinanceira())->setPasta($this->pasta)->setAutor($this->primario)->setTenant($this->tenant)->setConteudo($texto);
        $this->em()->persist($obs);
        $this->em()->flush();

        return $obs;
    }
}
