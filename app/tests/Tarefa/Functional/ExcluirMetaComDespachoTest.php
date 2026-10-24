<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\UseCase\ExcluirTarefaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Tenant\Support\CenarioDeEdicaoSemPrazo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Meta com despacho registrado (decisão do dono, 23/10): ninguém a exclui pela regra de sempre, nem o criador dentro
 * das 24h. Master e primário com a opção ligada excluem só confirmando ("Entendo que o registro oficial será apagado",
 * `confirmar_despacho=1`); sem a confirmação, 422. A trilha `sp_excluir` leva quantos despachos e os códigos.
 */
#[CoversClass(TarefaController::class)]
#[CoversClass(ExcluirTarefaUseCase::class)]
#[Group('tarefa')]
final class ExcluirMetaComDespachoTest extends JusPrimeWebTestCase
{
    use CenarioDeEdicaoSemPrazo;

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $criador;
    private Tarefa $meta;
    private string $codigo;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfPrevisivel();
        $this->escritorio = $this->novoEscritorio();
        // Primário, para passar pelas permissões do módulo; a opção desligada o deixa na regra de sempre.
        $this->criador = $this->novaPessoa($this->escritorio, 'Carla Criadora', true);
        $this->meta = $this->metaComDespacho();
    }

    #[TestDox('O criador, dentro das 24h, e um terceiro não excluem a meta com despacho registrado')]
    public function testCriadorETerceiroSaoRecusados(): void
    {
        $terceiro = $this->novaPessoa($this->escritorio, 'Tiago Terceiro', true);
        $this->desligarOpcaoDoPrimario($this->escritorio, $this->criador);

        foreach ([$this->criador, $terceiro] as $quem) {
            $this->logarComTenant($this->client, $quem, $this->escritorio);
            $this->excluir(true);
            self::assertResponseRedirects('/tarefas/' . $this->meta->getId());
            self::assertContains(ExcluirTarefaUseCase::COM_DESPACHO, $this->client->getRequest()->getSession()->getFlashBag()->get('danger'));
        }
        self::assertTrue($this->metaExiste());
        self::assertSame([], $this->auditoriaSemPrazo($this->escritorio));
    }

    #[TestDox('Master e primário (opção ligada) sem a confirmação explícita: 422, e a meta fica')]
    public function testPoderSemConfirmacaoRecebe422(): void
    {
        $master = $this->novaPessoa($this->escritorio, 'Marcos Master', false, ['ROLE_SUPER_ADMIN']);
        $primario = $this->novaPessoa($this->escritorio, 'Paula Primária', true);

        foreach ([$master, $primario] as $quem) {
            $this->logarComTenant($this->client, $quem, $this->escritorio);
            $this->excluir(false);
            self::assertResponseStatusCodeSame(422);
        }
        self::assertTrue($this->metaExiste());
        self::assertSame([], $this->auditoriaSemPrazo($this->escritorio));
    }

    #[TestDox('O primário (opção ligada) exclui confirmando, e a trilha registra o despacho: quantos e os códigos')]
    public function testPrimarioExcluiConfirmando(): void
    {
        $primario = $this->novaPessoa($this->escritorio, 'Paula Primária', true);
        $id = (int) $this->meta->getId();
        $this->logarComTenant($this->client, $primario, $this->escritorio);

        $this->excluir(true);

        self::assertResponseRedirects();
        self::assertFalse($this->metaExiste());
        $linhas = $this->auditoriaSemPrazo($this->escritorio);
        self::assertCount(1, $linhas);
        self::assertSame('sp_excluir', $linhas[0]->getAction());
        self::assertSame((string) $id, $linhas[0]->getEntityId());
        self::assertSame(['quantos' => 1, 'codigos' => [$this->codigo]], $linhas[0]->getChanges()['despacho_registrado'] ?? null);
        self::assertSame('Usuário primário', $linhas[0]->getChanges()['papel'] ?? null);
    }

    #[TestDox('O Master exclui confirmando, mesmo com a opção do primário desligada, e fica na trilha')]
    public function testMasterExcluiConfirmando(): void
    {
        $master = $this->novaPessoa($this->escritorio, 'Marcos Master', false, ['ROLE_SUPER_ADMIN']);
        $this->desligarOpcaoDoPrimario($this->escritorio, $this->criador);
        $this->logarComTenant($this->client, $master, $this->escritorio);

        $this->excluir(true);

        self::assertFalse($this->metaExiste());
        $linhas = $this->auditoriaSemPrazo($this->escritorio);
        self::assertCount(1, $linhas);
        self::assertSame('Master', $linhas[0]->getChanges()['papel'] ?? null);
        self::assertSame(1, $linhas[0]->getChanges()['despacho_registrado']['quantos'] ?? null);
    }

    private function excluir(bool $confirmando): void
    {
        $id = $this->meta->getId();
        $this->client->request('POST', "/tarefas/{$id}/excluir", array_filter([
            '_token' => 'TOKEN_excluir_tarefa_' . $id,
            'confirmar_despacho' => $confirmando ? '1' : null,
        ]));
    }

    private function metaExiste(): bool
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM tarefa WHERE id = ?', [$this->meta->getId()]) === 1;
    }

    private function metaComDespacho(): Tarefa
    {
        $pasta = (new Pasta())->setNup('DESP-' . uniqid())->setTenant($this->escritorio);
        $pasta->setCriadoPor($this->criador);
        $this->em->persist($pasta);
        $meta = new Tarefa();
        $meta->setTitulo('Meta com despacho');
        $meta->setDescricao('Descrição');
        $meta->setPasta($pasta);
        $meta->setTenant($this->escritorio);
        $meta->setCriadoPor($this->criador);
        $this->em->persist($meta);
        $this->codigo = sprintf('%04d.%04d', random_int(0, 9999), random_int(0, 9999));
        $despacho = (new TarefaMensagem())->setUsuario($this->criador)->setTenant($this->escritorio)->setMensagem('<p>Cumpra-se.</p>');
        $despacho->setAcao(AcaoDoDespacho::Despacho)->registrar($this->codigo, 'Carla Criadora', 'Sócia');
        $meta->addMensagem($despacho);
        $this->em->persist($despacho);
        $this->em->flush();

        return $meta;
    }
}
