<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\DTO\ModeloDaGrade;
use App\Comercial\Entity\ModeloDeCampanha;
use App\Comercial\Enum\Automacao;
use App\Comercial\Envio\LayoutDoEmail;
use App\Comercial\Repository\ModeloDeCampanhaRepository;
use App\Comercial\Service\DisparadorDeAutomacao;
use App\Comercial\Service\ModelosDeAutomacao;
use App\Comercial\Service\ModelosEfetivos;
use App\Entity\Auth\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * M5.8 (pedido do Farlei, decisão do coordenador 23/11/2026): editado o padrão da Central que é cópia de uma automação,
 * a automação passa a enviar a versão do Master; sem ela, ou com "Voltar ao padrão", o texto do sistema de sempre.
 */
#[CoversClass(ModelosDeAutomacao::class)]
#[CoversClass(ModelosEfetivos::class)]
#[CoversClass(ComposicaoDaCampanha::class)]
#[CoversClass(DisparadorDeAutomacao::class)]
final class AutomacaoComVersaoEditadaTest extends KernelTestCase
{
    private Connection $conexao;
    private ?User $master = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(Connection::class);
    }

    #[TestDox('Sem versão editada, a boas-vindas sai com o texto e o layout de sempre')]
    public function testSemVersaoEditada(): void
    {
        $modelo = $this->modelos()->montar(Automacao::Val, 'ana souza');

        self::assertSame(['Bem-vindo ao BlueJus, seu escritório digital', 'Seja bem-vindo(a), Ana!', LayoutDoEmail::Padrao], [$modelo->assunto, $modelo->titulo, $modelo->layout]);
    }

    #[TestDox('Com a boas-vindas editada, val e conv saem com a versão do Master ({nome} no título), no layout da campanha')]
    public function testBoasVindasEditada(): void
    {
        $this->editar('boasvindas', ['modo' => 'blocos', 'assunto' => 'Bem-vindo, versão do Master', 'selo' => 'OLÁ', 'titulo' => 'Oi, {nome}', 'blocos' => [['t' => 'texto', 'a' => 'Texto que o Master escreveu']]]);

        foreach ([Automacao::Val, Automacao::Conv] as $automacao) {
            $modelo = $this->modelos()->montar($automacao, 'ana souza');
            self::assertSame(['Bem-vindo, versão do Master', 'OLÁ', 'Oi, Ana', LayoutDoEmail::Linhas], [$modelo->assunto, $modelo->selo, $modelo->titulo, $modelo->layout]);
            self::assertStringContainsString('Texto que o Master escreveu', $modelo->corpoHtml);
        }
    }

    #[TestDox('O aviso de 90% editado troca {limite} pelo limite escolhido no assunto, no título e no texto')]
    public function testEspaco90ComLimite(): void
    {
        $this->editar('espaco90', ['modo' => 'blocos', 'assunto' => 'Espaço em {limite}%', 'selo' => 'ESPAÇO', 'titulo' => '{nome}, chegou a {limite}%', 'blocos' => [['t' => 'texto', 'a' => 'O espaço está com {limite}% ocupado.']]]);

        $modelo = $this->modelos()->montar(Automacao::E90, 'Ana', ['limite' => '80']);
        self::assertSame(['Espaço em 80%', 'Ana, chegou a 80%'], [$modelo->assunto, $modelo->titulo]);
        self::assertStringContainsString('O espaço está com 80% ocupado.', $modelo->corpoHtml);
        self::assertStringNotContainsString('{limite}', $modelo->corpoHtml);
    }

    #[TestDox('O lembrete de confirmação (link único de cada pessoa) não usa a cópia editada da Central')]
    public function testLembreteNaoMuda(): void
    {
        $this->editar('validar', ['modo' => 'blocos', 'assunto' => 'Validar editado', 'blocos' => [['t' => 'texto', 'a' => 'x']]]);

        self::assertSame('Confirme seu e-mail para ativar o BlueJus', $this->modelos()->montar(Automacao::Lemb, 'Ana', ['urlDeConfirmacao' => '#'])->assunto);
    }

    #[TestDox('Versão editada sem assunto não vale: a automação sai com o texto do sistema')]
    public function testVersaoSemAssuntoNaoVale(): void
    {
        $this->editar('primeiros', ['modo' => 'blocos', 'assunto' => 'x', 'blocos' => [['t' => 'texto', 'a' => 'y']]]);
        $this->conexao->executeStatement("UPDATE comercial_modelo SET assunto = '' WHERE fixo_chave = 'primeiros'");
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertSame('Uma semana de BlueJus: dicas para render mais', $this->modelos()->montar(Automacao::Dica, 'Ana')->assunto);
    }

    #[TestDox('O disparo grava o envio com a versão editada; "Voltar ao padrão" devolve o texto do sistema')]
    public function testDisparoEVoltarAoPadrao(): void
    {
        $versao = $this->editar('pedido', ['modo' => 'blocos', 'assunto' => 'Pedido recebido, versão do Master', 'blocos' => [['t' => 'texto', 'a' => 'Cuidamos já']]]);
        $this->conexao->executeStatement("UPDATE comercial_automacao SET ligada = true WHERE chave = 'ped'");

        self::assertTrue(static::getContainer()->get(DisparadorDeAutomacao::class)->disparar(Automacao::Ped, 'pedido@exemplo.com', 'Ana'));
        self::assertSame('Pedido recebido, versão do Master', $this->conexao->fetchOne("SELECT assunto FROM comercial_envio WHERE origem = 'automacao:ped' ORDER BY id DESC LIMIT 1"));

        static::getContainer()->get(ModeloDeCampanhaRepository::class)->remover($versao, $this->master(), ModeloDeCampanhaRepository::VOLTOU_AO_PADRAO);
        self::assertSame('Recebemos seu pedido de mais espaço no BlueJus', $this->modelos()->montar(Automacao::Ped, 'Ana')->assunto);
    }

    private function modelos(): ModelosDeAutomacao
    {
        return static::getContainer()->get(ModelosDeAutomacao::class);
    }

    /** @param array<string, mixed> $mensagem */
    private function editar(string $chave, array $mensagem): ModeloDeCampanha
    {
        $base = (array) (new ModelosDeCampanha())->fixo($chave);
        $modelo = ModeloDeCampanha::paraFixo($chave, (string) $base['nome'], (string) $base['icone'], (string) $mensagem['modo'], $this->master(), new \DateTimeImmutable());
        $m = MensagemDaCampanha::deArray($mensagem);
        $modelo->preencher($m->assunto, $m->selo, $m->titulo, ModeloDaGrade::conteudoParaGravar($m), new \DateTimeImmutable());
        static::getContainer()->get(ModeloDeCampanhaRepository::class)->salvar($modelo, ModeloDeCampanhaRepository::ALTERADO, $this->master());

        return $modelo;
    }

    private function master(): User
    {
        if ($this->master === null) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $this->master = (new User())->setEmail('auto_' . uniqid() . '@test.com')->setFullName('Master Auto')->setRoles(['ROLE_SUPER_ADMIN'])->setIsActive(true);
            $this->master->setPassword('x');
            $em->persist($this->master);
            $em->flush();
        }

        return $this->master;
    }
}
