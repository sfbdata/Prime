<?php

declare(strict_types=1);

namespace App\Tests\Notificacao\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Service\NotificacaoService;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\Perfil;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Achado 1.15 (2ª rodada da auditoria): sem escritório na sessão (a tela /escritorio/selecionar, logo depois
 * do login), o TenantFilter está desligado e o corte do cadeado zera. O sino não pode listar nem contar os
 * avisos de todos os escritórios da pessoa: só o Master continua vendo como hoje, e quem não é Master vê só
 * os avisos do Trabalho Externo dos escritórios em que tem vínculo ativo ou convite vigente.
 */
#[CoversClass(NotificacaoService::class)]
final class SinoSemEscritorioTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    protected function setUp(): void
    {
        $this->prepararCliente();
    }

    #[TestDox('dois escritórios, aviso de pasta trancada e oculta em A: a seleção de escritório não mostra nem conta o aviso')]
    public function testAvisoDePastaOcultaNaoSaiSemEscritorio(): void
    {
        [$primario, $tenantA] = $this->criarEscritorio();
        $ana = $this->criarColaborador($tenantA);
        [, $tenantB] = $this->criarEscritorio();
        $this->vincular($ana, $tenantB, false, ['modules.expediente.view']);
        $pasta = $this->criarPasta($tenantA);
        $this->aviso($ana, $tenantA, 'MENCAO NA PASTA TRANCADA', 'PREVIA DO REGISTRO TRANCADO', Notificacao::TIPO_PASTA_MENCAO_REGISTRO, '/pasta/' . $pasta->getId() . '#pasta-msg-1');
        $this->trancar($pasta, $primario);

        $html = $this->abrirSelecaoSemEscritorio($ana);

        self::assertStringNotContainsString('MENCAO NA PASTA TRANCADA', $html);
        self::assertStringNotContainsString('PREVIA DO REGISTRO TRANCADO', $html);
        self::assertSame('0', $this->contagemDoSino());
    }

    #[TestDox('suspenso em A, sem outro vínculo: a seleção de escritório não mostra nenhum aviso de A')]
    public function testSuspensoNaoLeAvisosDoEscritorio(): void
    {
        [, $tenantA] = $this->criarEscritorio();
        $ana = $this->criarColaborador($tenantA);
        $this->aviso($ana, $tenantA, 'AVISO DO ESCRITORIO QUE SUSPENDEU', 'TEXTO DO AVISO', Notificacao::TIPO_TAREFA_CRIADA, null);
        $this->aviso($ana, $tenantA, 'ANDAMENTO DO TE NO ESCRITORIO QUE SUSPENDEU', 'TEXTO DO TE', Notificacao::TIPO_TRABALHO_EXTERNO_DEMANDA_ANDAMENTO, '/trabalho-externo?te=TE-1');
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $ana, 'tenant' => $tenantA]);
        $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $this->em()->flush();

        $html = $this->abrirSelecaoSemEscritorio($ana);

        self::assertStringNotContainsString('AVISO DO ESCRITORIO QUE SUSPENDEU', $html);
        self::assertStringNotContainsString('TEXTO DO AVISO', $html);
        self::assertStringNotContainsString('ANDAMENTO DO TE NO ESCRITORIO QUE SUSPENDEU', $html);
        self::assertSame('0', $this->contagemDoSino());
    }

    #[TestDox('sem escritório, o aviso do Trabalho Externo de um escritório com vínculo ativo continua no sino; o resto não')]
    public function testTrabalhoExternoContinuaSemEscritorio(): void
    {
        [, $tenantA] = $this->criarEscritorio();
        $ana = $this->criarColaborador($tenantA);
        [, $tenantB] = $this->criarEscritorio();
        $this->vincular($ana, $tenantB, false, ['modules.expediente.view']);
        $this->aviso($ana, $tenantA, 'ANDAMENTO DA DEMANDA TE', 'TEXTO DO ANDAMENTO', Notificacao::TIPO_TRABALHO_EXTERNO_DEMANDA_ANDAMENTO, '/trabalho-externo?te=TE-1');
        $this->aviso($ana, $tenantB, 'META NOVA EM B', 'TEXTO DA META', Notificacao::TIPO_TAREFA_CRIADA, null);

        $html = $this->abrirSelecaoSemEscritorio($ana);

        self::assertStringContainsString('ANDAMENTO DA DEMANDA TE', $html);
        self::assertStringNotContainsString('META NOVA EM B', $html);
        self::assertSame('1', $this->contagemDoSino());
    }

    #[TestDox('Convidado sem vínculo nenhum, com convite vigente: vê o aviso do Trabalho Externo daquele escritório e nenhum outro')]
    public function testConvidadoSoComConviteVigente(): void
    {
        [$convidado, $doConvite, $outro] = $this->cenarioDoConvidado();

        $html = $this->abrirSelecaoSemEscritorio($convidado);

        self::assertStringContainsString('ANDAMENTO DO CONVITE', $html);
        self::assertStringNotContainsString('META NO ESCRITORIO DO CONVITE', $html, 'só os avisos do Trabalho Externo');
        self::assertStringNotContainsString('ANDAMENTO DE OUTRO ESCRITORIO', $html, 'só do escritório do convite');
        self::assertSame('1', $this->contagemDoSino());
        self::assertNotSame($doConvite->getId(), $outro->getId());
    }

    #[TestDox('Convidado sem vínculo nenhum, com o convite vencido: o sino não mostra nenhum aviso')]
    public function testConvidadoComConviteVencidoNaoVe(): void
    {
        [$convidado] = $this->cenarioDoConvidado();
        // Ontem pelo relógio do PHP, que é o que o sistema compara (`ConviteDemanda::vigente`). O
        // CURRENT_DATE do Postgres do CI está em UTC: das 21h à meia-noite em Brasília, o "ontem" dele é o
        // "hoje" do PHP, o convite ainda vale e o teste falhava (Suíte completa de 06/11 por volta de 00:00Z).
        $this->em()->getConnection()->executeStatement(
            'UPDATE trabalho_externo_convite SET validade = ? WHERE convidado_id = ?',
            [(new \DateTimeImmutable('yesterday'))->format('Y-m-d'), $convidado->getId()],
        );
        $this->em()->clear();

        $html = $this->abrirSelecaoSemEscritorio($convidado);

        self::assertStringNotContainsString('ANDAMENTO DO CONVITE', $html);
        self::assertSame('0', $this->contagemDoSino());
    }

    /** @return array{0: User, 1: Tenant, 2: Tenant} o convidado (sem vínculo), o escritório do convite e outro */
    private function cenarioDoConvidado(): array
    {
        [$primario, $doConvite] = $this->criarEscritorio();
        [, $outro] = $this->criarEscritorio();
        $convidado = $this->novoUsuario('Carlos Convidado');
        $this->em()->flush();

        $demanda = new DemandaExterna($doConvite, $primario);
        $dados = new DadosDaSolicitacao();
        $dados->descricao = 'Audiência';
        $dados->cidadeExecucao = 'Brasília';
        $demanda->preencher($dados);
        $this->em()->persist($demanda);
        $convite = new ConviteDemanda($demanda, 'Carlos Convidado', (string) $convidado->getEmail(), null, new \DateTimeImmutable('+30 days'), str_repeat('c', 64), ['chat' => true], new Autor($primario, 'Paula Primária', Perfil::Master));
        $convite->aceitar($convidado, new \DateTimeImmutable('today'));
        $this->em()->persist($convite);
        $this->em()->flush();

        $this->aviso($convidado, $doConvite, 'ANDAMENTO DO CONVITE', 'TEXTO DO ANDAMENTO', Notificacao::TIPO_TRABALHO_EXTERNO_DEMANDA_ANDAMENTO, '/trabalho-externo?te=TE-1');
        $this->aviso($convidado, $doConvite, 'META NO ESCRITORIO DO CONVITE', 'TEXTO DA META', Notificacao::TIPO_TAREFA_CRIADA, null);
        $this->aviso($convidado, $outro, 'ANDAMENTO DE OUTRO ESCRITORIO', 'TEXTO DE OUTRO', Notificacao::TIPO_TRABALHO_EXTERNO_DEMANDA_ANDAMENTO, '/trabalho-externo?te=TE-2');

        return [$convidado, $doConvite, $outro];
    }

    #[TestDox('regressão: o Master sem escritório continua vendo e contando o sino como hoje')]
    public function testMasterSemEscritorioVeComoHoje(): void
    {
        [, $tenantA] = $this->criarEscritorio();
        [, $tenantB] = $this->criarEscritorio();
        $master = $this->novoUsuario('Mario Master');
        $master->setRoles(['ROLE_SUPER_ADMIN']);
        $this->vincular($master, $tenantA, false, ['modules.expediente.view']);
        $this->vincular($master, $tenantB, false, ['modules.expediente.view']);
        $this->aviso($master, $tenantA, 'AVISO DO MASTER EM A', 'TEXTO A', Notificacao::TIPO_TAREFA_CRIADA, null);
        $this->aviso($master, $tenantB, 'AVISO DO MASTER EM B', 'TEXTO B', Notificacao::TIPO_TAREFA_CRIADA, null);

        $html = $this->abrirSelecaoSemEscritorio($master);

        self::assertStringContainsString('AVISO DO MASTER EM A', $html);
        self::assertStringContainsString('AVISO DO MASTER EM B', $html);
        self::assertSame('2', $this->contagemDoSino());
    }

    private function abrirSelecaoSemEscritorio(User $user): string
    {
        $this->client->loginUser($user);
        $this->marcarTermosAceitos($this->client);
        $this->client->request('GET', '/escritorio/selecionar');
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function contagemDoSino(): string
    {
        return trim($this->client->getCrawler()->filter('#notificacaoCountText')->text());
    }

    private function aviso(User $user, Tenant $tenant, string $titulo, string $mensagem, string $tipo, ?string $url): void
    {
        $n = new Notificacao();
        $n->setUsuario($user);
        $n->setTenant($tenant);
        $n->setTipo($tipo);
        $n->setTitulo($titulo);
        $n->setMensagem($mensagem);
        $n->setUrl($url);
        $this->em()->persist($n);
        $this->em()->flush();
    }
}
