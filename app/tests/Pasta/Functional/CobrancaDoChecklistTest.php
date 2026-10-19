<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Command\CobrarChecklistCommand;
use App\Pasta\Entity\MotivoDesativacaoChecklist;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistCobranca;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaChecklistCobrancaRepository;
use App\Pasta\Service\AuditoriaDoChecklist;
use App\Pasta\Service\AvisoDoChecklist;
use App\Pasta\Service\CobrancasDoChecklistDaPasta;
use App\Pasta\Service\ControladoriaDoEscritorioInterface;
use App\Pasta\Service\DiaUtilDoEscritorio;
use App\Pasta\UseCase\CobrarChecklistDoEscritorioUseCase;
use App\Pasta\UseCase\InformarPrevisaoDoItemUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A cobrança automática do checklist de ponta a ponta (desenho 02; spec `docs/specs/pasta-cobranca-do-checklist.md`):
 * a rotina diária, a auditoria "sem anexo", o lembrete de 30 dias e o que a tela mostra.
 */
#[CoversClass(CobrarChecklistDoEscritorioUseCase::class)]
#[CoversClass(AuditoriaDoChecklist::class)]
#[CoversClass(DiaUtilDoEscritorio::class)]
#[CoversClass(CobrancasDoChecklistDaPasta::class)]
#[CoversClass(InformarPrevisaoDoItemUseCase::class)]
#[CoversClass(CobrarChecklistCommand::class)]
final class CobrancaDoChecklistTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    /** Quarta-feira sem feriado nacional. */
    private const QUARTA = '2026-10-21 09:00';

    #[TestDox('Dia útil: cobra o responsável (urgentes primeiro, teto de 2) e audita o item marcado sem anexo; rodar de novo no dia não repete')]
    public function testRotinaDoDia(): void
    {
        static::createClient();
        [$responsavel, $tenant] = $this->criarAdmin();
        $pasta = $this->pastaCom($tenant, $responsavel, [['PROCURAÇÃO', false], ['COMPROVANTE DE RESIDÊNCIA', false], ['LAUDO MÉDICO', false], ['CONTRATO DE HONORÁRIOS', true]]);

        $primeira = $this->rodar($tenant, self::QUARTA);
        self::assertSame(2, $primeira['cobrancas']);
        self::assertEqualsCanonicalizing(['Documento pendente: PROCURAÇÃO', 'Documento pendente: COMPROVANTE DE RESIDÊNCIA', 'Item marcado sem anexo: CONTRATO DE HONORÁRIOS'], $this->titulos($responsavel, AvisoDoChecklist::TIPO_COBRANCA));

        self::assertSame(0, $this->rodar($tenant, self::QUARTA)['cobrancas'], 'o envio de hoje fica registrado');
        self::assertCount(3, $this->titulos($responsavel, AvisoDoChecklist::TIPO_COBRANCA));

        $selos = static::getContainer()->get(CobrancasDoChecklistDaPasta::class)->selos($this->em()->find(Pasta::class, $pasta->getId()));
        self::assertCount(2, $selos);
        self::assertSame('cobrado 1x', array_values($selos)[0]['texto']);
        self::assertSame('Cobranças enviadas ao responsável: 21/10', array_values($selos)[0]['dica']);
    }

    #[TestDox('Sábado não cobra; pasta sem responsável não cobra')]
    public function testSemDiaUtilESemResponsavel(): void
    {
        static::createClient();
        [$responsavel, $tenant] = $this->criarAdmin();
        $this->pastaCom($tenant, $responsavel, [['PROCURAÇÃO', false]]);
        $this->pastaCom($tenant, null, [['CONTRATO', false]]);

        self::assertSame(0, $this->rodar($tenant, '2026-10-24 09:00')['cobrancas']);
        self::assertSame(1, $this->rodar($tenant, self::QUARTA)['cobrancas'], 'só a pasta com responsável');
    }

    #[TestDox('Checklist desativado há 30 dias: lembrete "Revisar checklist" ao responsável, uma vez a cada 30 dias, sem cobrança')]
    public function testLembreteDe30Dias(): void
    {
        static::createClient();
        [$responsavel, $tenant] = $this->criarAdmin();
        $pasta = $this->pastaCom($tenant, $responsavel, [['PROCURAÇÃO', false]]);
        $pasta->desativarChecklist(MotivoDesativacaoChecklist::Encerrada, $responsavel, new \DateTimeImmutable('2026-09-20 09:00'));
        $this->em()->flush();

        $r = $this->rodar($tenant, self::QUARTA);
        self::assertSame(0, $r['cobrancas']);
        self::assertSame(1, $r['lembretes']);
        $titulos = $this->titulos($responsavel, AvisoDoChecklist::TIPO_PROVIDENCIA);
        self::assertCount(1, $titulos);
        self::assertStringStartsWith('Revisar checklist da Pasta ', $titulos[0]);
        self::assertSame(0, $this->rodar($tenant, '2026-10-22 09:00')['lembretes']);
    }

    #[TestDox('Marcar na tela um item sem documento anexado avisa quem marcou; a pendência da aba conta os cobrados')]
    public function testMarcarSemAnexoNaTela(): void
    {
        $client = static::createClient();
        [$responsavel, $tenant] = $this->criarAdmin();
        $pasta = $this->pastaCom($tenant, $responsavel, [['PROCURAÇÃO', false], ['CERTIDÃO DE CASAMENTO', false]]);
        $this->rodar($tenant, self::QUARTA);
        $itemId = (int) $this->em()->getRepository(PastaChecklistItem::class)->findOneBy(['titulo' => 'CERTIDÃO DE CASAMENTO', 'pasta' => $pasta])?->getId();

        $this->logarComTenant($client, $responsavel, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertSame(2, $crawler->filter('#checklistLista > li > button.js-checklist-toggle > .pex-ck-cobrado')->count());
        self::assertStringContainsString('2 documentos cobrados e ainda não anexados', (string) $crawler->filter('#documentos-tab')->attr('title'));

        $token = $crawler->filter('li[data-item-id="' . $itemId . '"]')->attr('data-csrf-item');
        $client->request('POST', '/pasta/' . $pasta->getId() . '/checklist/' . $itemId . '/toggle', ['_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertContains('Item marcado sem anexo: CERTIDÃO DE CASAMENTO', $this->titulos($responsavel, AvisoDoChecklist::TIPO_COBRANCA));
    }

    #[TestDox('Previsão informada pelo responsável pausa a cobrança até a data; descumprida, volta como urgente e conta a quebra')]
    public function testPrevisao(): void
    {
        static::createClient();
        [$responsavel, $tenant] = $this->criarAdmin();
        $pasta = $this->pastaCom($tenant, $responsavel, [['LAUDO MÉDICO', false]]);
        $item = $this->em()->getRepository(PastaChecklistItem::class)->findOneBy(['pasta' => $pasta]);
        self::assertNotNull($item);
        // Sem consumidor ainda (a entrada da data está com o dono), o container compilado não guarda o serviço.
        $previsao = new InformarPrevisaoDoItemUseCase(static::getContainer()->get(PastaChecklistCobrancaRepository::class));
        $previsao->executar($item, $this->em()->find(User::class, $responsavel->getId()), new \DateTimeImmutable('2026-10-22'), new \DateTimeImmutable(self::QUARTA));

        self::assertSame(0, $this->rodar($tenant, self::QUARTA)['cobrancas'], 'pausado até 22/10');
        self::assertSame(1, $this->rodar($tenant, '2026-10-23 09:00')['cobrancas'], 'descumprida: cobra');
        self::assertSame(['Você não cumpriu o prazo: LAUDO MÉDICO'], $this->titulos($responsavel, AvisoDoChecklist::TIPO_COBRANCA));
        $registro = static::getContainer()->get(PastaChecklistCobrancaRepository::class)->findOneBy(['item' => $item->getId()]);
        self::assertSame(1, $registro?->getQuebras());
        self::assertNull($registro?->getPrevisao());

        $this->expectException(\DomainException::class);
        $previsao->executar($this->em()->find(PastaChecklistItem::class, $item->getId()), $this->criarAdmin()[0], new \DateTimeImmutable('2026-10-30'), new \DateTimeImmutable(self::QUARTA));
    }

    #[TestDox('Desativar o checklist avisa a controladoria com o motivo e as pendências (desenho dc 4247)')]
    public function testDesativarAvisaControladoria(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$responsavel, $tenant] = $this->criarAdmin();
        [$coordenadora] = $this->criarAdmin();
        $pasta = $this->pastaCom($tenant, $responsavel, [['PROCURAÇÃO', false], ['RG', false]]);
        $this->dubleDaControladoria((int) $coordenadora->getId());

        $this->logarComTenant($client, $responsavel, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        $client->request('POST', '/pasta/' . $pasta->getId() . '/checklist/estado', ['_token' => $crawler->filter('#pexChecklist')->attr('data-csrf-pasta'), 'ativo' => '0', 'motivo' => MotivoDesativacaoChecklist::Encerrada->value]);
        self::assertResponseIsSuccessful();

        $titulos = $this->titulos($coordenadora, AvisoDoChecklist::TIPO_PROVIDENCIA);
        self::assertCount(1, $titulos);
        self::assertStringStartsWith('Checklist desativado: Pasta ', $titulos[0]);
        $texto = (string) static::getContainer()->get(Connection::class)->fetchOne('SELECT mensagem FROM notificacao WHERE usuario_id = ? AND tipo = ?', [$coordenadora->getId(), AvisoDoChecklist::TIPO_PROVIDENCIA]);
        self::assertStringEndsWith('(' . MotivoDesativacaoChecklist::Encerrada->rotulo() . ') com 2 pendências em aberto.', $texto);
    }

    #[TestDox('Feriado nacional em dia de semana (Finados) não cobra; a rotina de um escritório não toca o outro')]
    public function testFeriadoEIsolamento(): void
    {
        static::createClient();
        [$responsavelA, $escritorioA] = $this->criarAdmin();
        [$responsavelB, $escritorioB] = $this->criarAdmin();
        $this->pastaCom($escritorioA, $responsavelA, [['PROCURAÇÃO', false]]);
        $this->pastaCom($escritorioB, $responsavelB, [['PROCURAÇÃO', false]]);

        self::assertSame(0, $this->rodar($escritorioA, '2026-11-02 09:00')['cobrancas'], 'Finados, segunda-feira');
        self::assertSame(1, $this->rodar($escritorioA, self::QUARTA)['cobrancas']);
        self::assertSame([], $this->titulos($responsavelB, AvisoDoChecklist::TIPO_COBRANCA), 'o escritório B não foi cobrado');
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM pasta_checklist_cobranca WHERE tenant_id = ?', [$escritorioB->getId()]));
    }

    #[TestDox('Arquivada, cancelada e responsável que saiu do escritório ficam fora da cobrança')]
    public function testForaDaCobranca(): void
    {
        static::createClient();
        [$responsavel, $tenant] = $this->criarAdmin();
        $arquivada = $this->pastaCom($tenant, $responsavel, [['PROCURAÇÃO', false]]);
        $arquivada->setSituacao(Pasta::SITUACAO_ARQUIVADA);
        $this->em()->flush();
        self::assertSame(0, $this->rodar($tenant, self::QUARTA)['cobrancas'], 'arquivada');

        $this->pastaCom($tenant, $responsavel, [['CONTRATO', false]]);
        static::getContainer()->get(Connection::class)->executeStatement('UPDATE user_tenant SET is_active = false WHERE user_id = ? AND tenant_id = ?', [$responsavel->getId(), $tenant->getId()]);
        self::assertSame(0, $this->rodar($tenant, self::QUARTA)['cobrancas'], 'saiu do escritório');
    }

    #[TestDox('Auditoria: a controladoria recebe "Pasta N para auditoria"; desmarcar limpa e anexar o documento apaga o registro')]
    public function testAuditoriaCompleta(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$responsavel, $tenant] = $this->criarAdmin();
        [$coordenadora] = $this->criarAdmin();
        $this->dubleDaControladoria((int) $coordenadora->getId());
        $pasta = $this->pastaCom($tenant, $responsavel, [['PROCURAÇÃO', true]]);

        $this->rodar($tenant, self::QUARTA);
        $titulos = $this->titulos($coordenadora, AvisoDoChecklist::TIPO_COBRANCA);
        self::assertCount(1, $titulos);
        self::assertStringEndsWith(' para auditoria', $titulos[0]);
        self::assertNotNull($this->registroDe($pasta)?->getAuditoriaEm());

        $this->rodar($tenant, '2026-10-22 09:00');
        self::assertCount(1, $this->titulos($coordenadora, AvisoDoChecklist::TIPO_COBRANCA), 'uma vez por item');

        $documento = new PastaDocumento();
        $documento->setPasta($this->em()->find(Pasta::class, $pasta->getId()));
        $documento->setTenant($this->em()->find(Tenant::class, $tenant->getId()));
        $documento->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $documento->setTitulo('Procuração assinada');
        $documento->setNomeOriginal('procuracao.pdf');
        $documento->setCaminhoArquivo('procuracao-' . uniqid() . '.pdf');
        $documento->setMimeType('application/pdf');
        $documento->setTamanhoBytes(1024);
        $this->em()->persist($documento);
        $this->em()->flush();
        $this->rodar($tenant, '2026-10-23 09:00');
        self::assertNull($this->registroDe($pasta), 'com o documento anexado o registro sai');
    }

    #[TestDox('O comando roda todos os escritórios e resume o que fez')]
    public function testComando(): void
    {
        $kernel = static::bootKernel();
        $tester = new CommandTester((new Application($kernel))->find('app:pasta:cobrar-checklist'));

        self::assertSame(0, $tester->execute([]));
        self::assertMatchesRegularExpression('/Cobranças: \d+ · lembretes: \d+ · escritórios com falha: 0/', $tester->getDisplay());
    }

    #[TestDox('Piloto: --escritorio roda só um; --simular lista quem seria cobrado e não envia nem grava nada')]
    public function testComandoPilotoESimulacao(): void
    {
        $kernel = static::bootKernel();
        [$responsavel, $tenant] = $this->criarAdmin();
        $this->pastaCom($tenant, $responsavel, [['PROCURAÇÃO', false]]);
        $tester = new CommandTester((new Application($kernel))->find('app:pasta:cobrar-checklist'));

        self::assertSame(0, $tester->execute(['--escritorio' => (string) $tenant->getId(), '--simular' => true]));
        $saida = $tester->getDisplay();
        self::assertStringContainsString('SIMULAÇÃO (nada foi enviado)', $saida);
        if ((int) (new \DateTimeImmutable())->format('N') <= 5) {
            self::assertStringContainsString('PROCURAÇÃO, 1º aviso para', $saida);
        }
        self::assertSame([], $this->titulos($responsavel, AvisoDoChecklist::TIPO_COBRANCA), 'simulação não envia');
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM pasta_checklist_cobranca WHERE tenant_id = ?', [$tenant->getId()]), 'nem grava');
    }

    /** @return array{cobrancas: int, lembretes: int, detalhes: list<string>} */
    private function rodar(Tenant $tenant, string $quando): array
    {
        $escritorio = $this->em()->find(Tenant::class, $tenant->getId());
        self::assertNotNull($escritorio);
        $r = static::getContainer()->get(CobrarChecklistDoEscritorioUseCase::class)->executar($escritorio, new \DateTimeImmutable($quando));
        $this->em()->clear();

        return $r;
    }

    /** @param list<array{string, bool}> $itens */
    private function pastaCom(Tenant $tenant, ?User $responsavel, array $itens): Pasta
    {
        // A rotina solta (clear) tudo ao terminar: o escritório é relido antes de criar a pasta.
        $pasta = $this->criarPasta($this->em()->find(Tenant::class, $tenant->getId()) ?? $tenant);
        $pasta->setResponsavel($responsavel === null ? null : $this->em()->find(User::class, $responsavel->getId()));
        foreach ($itens as $i => [$titulo, $concluido]) {
            $this->em()->persist((new PastaChecklistItem())->setPasta($pasta)->setTenant($pasta->getTenant())->setTitulo($titulo)->setConcluido($concluido)->setOrdem($i));
        }
        $this->em()->flush();
        $this->em()->refresh($pasta);

        return $pasta;
    }

    /** @return list<string> */
    private function titulos(User $usuario, string $tipo): array
    {
        return array_map('strval', static::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT titulo FROM notificacao WHERE usuario_id = ? AND tipo = ? ORDER BY id', [$usuario->getId(), $tipo]));
    }

    /** A controladoria real (#75) relê as pessoas do banco a cada chamada; o dublê faz o mesmo. */
    private function dubleDaControladoria(int $idDaPessoa): void
    {
        $em = $this->em();
        static::getContainer()->set(ControladoriaDoEscritorioInterface::class, new class ($em, $idDaPessoa) implements ControladoriaDoEscritorioInterface {
            public function __construct(private readonly EntityManagerInterface $em, private readonly int $id)
            {
            }

            public function pessoas(Tenant $tenant): array
            {
                $pessoa = $this->em->find(User::class, $this->id);

                return $pessoa === null ? [] : [$pessoa];
            }
        });
    }

    private function registroDe(Pasta $pasta): ?PastaChecklistCobranca
    {
        $this->em()->clear();
        $item = $this->em()->getRepository(PastaChecklistItem::class)->findOneBy(['pasta' => $pasta->getId()]);

        return $item === null ? null : static::getContainer()->get(PastaChecklistCobrancaRepository::class)->findOneBy(['item' => $item]);
    }
}
