<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Service\PrazoDoComentario;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Tenant\Support\CenarioDeEdicaoSemPrazo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Edição sem prazo nos pontos 1 a 4 (spec `edicao-sem-prazo-master-primario.md` §2 e §7): Registro/mensagem da pasta,
 * observação de Detalhes, observação do Financeiro e nota técnica. Pela rota de verdade: autor dentro do prazo pode,
 * fora não; Master e primário (com a opção) editam e excluem o que é de outra pessoa, fora do prazo, e isso entra na
 * auditoria; com a opção desligada, o primário volta à regra de sempre.
 */
#[CoversClass(PrazoDoComentario::class)]
#[Group('pasta')]
final class EdicaoSemPrazoNaPastaTest extends JusPrimeWebTestCase
{
    use CenarioDeEdicaoSemPrazo;

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $autor;
    private User $primario;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfPrevisivel();
        $this->escritorio = $this->novoEscritorio();
        // O autor é primário também: passa pelas permissões do módulo e chega à regra do prazo.
        $this->autor = $this->novaPessoa($this->escritorio, 'Ana Autora', true);
        $this->primario = $this->novaPessoa($this->escritorio, 'Paula Primária', true);
    }

    /** @return iterable<string, array{string}> */
    public static function pontos(): iterable
    {
        yield '1 registro da pasta' => ['mensagem'];
        yield '2 observação de Detalhes' => ['detalhes'];
        yield '3 observação do Financeiro' => ['financeiro'];
        yield '4 nota técnica' => ['nota'];
    }

    #[DataProvider('pontos')]
    #[TestDox('Ponto $ponto: o autor edita dentro do prazo e não fora dele (opção desligada)')]
    public function testAutorSoDentroDoPrazo(string $ponto): void
    {
        $this->desligarOpcaoDoPrimario($this->escritorio, $this->primario);
        $novo = $this->criar($ponto, $this->autor, '-5 minutes');
        $velho = $this->criar($ponto, $this->autor, '-30 minutes');
        $this->logarComTenant($this->client, $this->autor, $this->escritorio);

        $this->editar($ponto, $novo);
        self::assertResponseIsSuccessful();
        $this->editar($ponto, $velho);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->auditoriaSemPrazo($this->escritorio), 'a regra de sempre não audita');
    }

    #[DataProvider('pontos')]
    #[TestDox('Ponto $ponto: terceiro não edita nem exclui o conteúdo alheio, nem dentro do prazo')]
    public function testTerceiroNao(string $ponto): void
    {
        $this->desligarOpcaoDoPrimario($this->escritorio, $this->primario);
        $conteudo = $this->criar($ponto, $this->autor, '-5 minutes');
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $this->editar($ponto, $conteudo);
        self::assertResponseStatusCodeSame(403);
        $this->excluir($ponto, $conteudo);
        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('pontos')]
    #[TestDox('Ponto $ponto: o Master edita e exclui o conteúdo alheio fora do prazo, e fica na auditoria')]
    public function testMasterSemPrazo(string $ponto): void
    {
        $master = $this->novaPessoa($this->escritorio, 'Marcos Master', false, ['ROLE_SUPER_ADMIN']);
        $this->desligarOpcaoDoPrimario($this->escritorio, $this->primario);
        $conteudo = $this->criar($ponto, $this->autor, '-3 days');
        $this->logarComTenant($this->client, $master, $this->escritorio);

        $this->editar($ponto, $conteudo);
        self::assertResponseIsSuccessful();
        $this->excluir($ponto, $conteudo);
        self::assertResponseIsSuccessful();

        $linhas = $this->auditoriaSemPrazo($this->escritorio);
        self::assertSame(['sp_editar', 'sp_excluir'], array_map(static fn ($l) => $l->getAction(), $linhas));
        self::assertSame($master->getId(), $linhas[1]->getActorUserId());
        self::assertSame($conteudo::class, $linhas[1]->getEntityClass());
        self::assertSame((string) $this->idDe($conteudo, $linhas[0]), $linhas[1]->getEntityId());
        self::assertSame(['papel' => 'Master', 'poder' => 'master', 'autor_id' => $this->autor->getId(), 'alheio' => true], $linhas[1]->getChanges());
    }

    #[DataProvider('pontos')]
    #[TestDox('Ponto $ponto: o primário edita e exclui o alheio com a opção ligada (auditado); desligada, 403')]
    public function testPrimarioConformeAOpcao(string $ponto): void
    {
        $conteudo = $this->criar($ponto, $this->autor, '-2 hours');
        $outro = $this->criar($ponto, $this->autor, '-2 hours');
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $this->editar($ponto, $conteudo);
        self::assertResponseIsSuccessful();
        $this->excluir($ponto, $conteudo);
        self::assertResponseIsSuccessful();
        $linhas = $this->auditoriaSemPrazo($this->escritorio);
        self::assertCount(2, $linhas);
        self::assertSame('Usuário primário', $linhas[0]->getChanges()['papel'] ?? null);

        $this->desligarOpcaoDoPrimario($this->escritorio, $this->primario);
        $this->editar($ponto, $outro);
        self::assertResponseStatusCodeSame(403);
        $this->excluir($ponto, $outro);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(2, $this->auditoriaSemPrazo($this->escritorio));
    }

    // ── fixtures ────────────────────────────────────────────────────────────────

    private function criar(string $ponto, User $autor, string $quando): object
    {
        $conteudo = match ($ponto) {
            'mensagem' => (new PastaMensagem())->setPasta($this->pasta())->setAutor($autor)->setTenant($this->escritorio)->setConteudo('Original'),
            'detalhes' => (new PastaObservacaoDetalhes())->setPasta($this->pasta())->setAutor($autor)->setTenant($this->escritorio)->setConteudo('Original'),
            'financeiro' => (new PastaObservacaoFinanceira())->setPasta($this->pasta())->setAutor($autor)->setTenant($this->escritorio)->setConteudo('Original'),
            'nota' => new NotaTecnica($this->escritorio, $this->processo(), $autor, '<p>Original</p>'),
        };
        (new \ReflectionProperty($conteudo::class, 'criadaEm'))->setValue($conteudo, new \DateTimeImmutable($quando));
        $this->em->persist($conteudo);
        $this->em->flush();

        return $conteudo;
    }

    private function pasta(): Pasta
    {
        $pasta = (new Pasta())->setNup('SP-' . uniqid())->setTenant($this->escritorio);
        $this->em->persist($pasta);

        return $pasta;
    }

    private function processo(): Processo
    {
        $processo = new Processo();
        $processo->setTenant($this->escritorio);
        $processo->setNumeroProcesso('0701' . random_int(1000000000000000, 9999999999999999));
        $processo->setClasseProcessual('Procedimento Comum');
        $this->em->persist($processo);

        return $processo;
    }

    private function editar(string $ponto, object $conteudo): void
    {
        [$url, $token] = $this->rota($ponto, $conteudo, 'editar');
        $this->client->request('POST', $url, ['_token' => 'TOKEN_' . $token, 'conteudo' => $ponto === 'nota' ? '<p>Corrigido</p>' : 'Corrigido']);
    }

    private function excluir(string $ponto, object $conteudo): void
    {
        [$url, $token] = $this->rota($ponto, $conteudo, 'excluir');
        $this->client->request('POST', $url, ['_token' => 'TOKEN_' . $token]);
    }

    /** @return array{string, string} */
    private function rota(string $ponto, object $c, string $acao): array
    {
        $id = $c->getId();

        return match ($ponto) {
            'mensagem' => ["/pasta/{$c->getPasta()->getId()}/mensagem/{$id}/{$acao}", "pasta_mensagem_{$acao}_{$id}"],
            'detalhes' => ["/pasta/{$c->getPasta()->getId()}/detalhes/observacao/{$id}/{$acao}", "pasta_detalhes_obs_{$acao}_{$id}"],
            'financeiro' => ["/pasta/{$c->getPasta()->getId()}/financeiro/observacao/{$id}/{$acao}", "pasta_financeiro_obs_{$acao}_{$id}"],
            'nota' => ["/processos/{$c->getProcesso()->getId()}/nota-tecnica/{$id}/{$acao}", "processo_nota_tecnica_{$acao}_{$id}"],
        };
    }

    /** O id do conteúdo já excluído vem da linha da edição, gravada antes. */
    private function idDe(object $conteudo, object $linhaDaEdicao): int
    {
        return $conteudo->getId() ?? (int) $linhaDaEdicao->getEntityId();
    }
}
