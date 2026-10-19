<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\MotivoDesativacaoChecklist;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
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

/**
 * A cobrança automática do checklist de ponta a ponta (desenho 02; spec `docs/specs/pasta-cobranca-do-checklist.md`):
 * a rotina diária, a auditoria "sem anexo", o lembrete de 30 dias e o que a tela mostra.
 */
#[CoversClass(CobrarChecklistDoEscritorioUseCase::class)]
#[CoversClass(AuditoriaDoChecklist::class)]
#[CoversClass(DiaUtilDoEscritorio::class)]
#[CoversClass(CobrancasDoChecklistDaPasta::class)]
#[CoversClass(InformarPrevisaoDoItemUseCase::class)]
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
        self::assertSame(['cobrancas' => 0, 'lembretes' => 1], $r);
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
        // A controladoria real (#75) relê as pessoas do banco a cada chamada; o dublê faz o mesmo.
        $em = $this->em();
        $idDaCoordenadora = (int) $coordenadora->getId();
        static::getContainer()->set(ControladoriaDoEscritorioInterface::class, new class ($em, $idDaCoordenadora) implements ControladoriaDoEscritorioInterface {
            public function __construct(private readonly EntityManagerInterface $em, private readonly int $id)
            {
            }

            public function pessoas(Tenant $tenant): array
            {
                $pessoa = $this->em->find(User::class, $this->id);

                return $pessoa === null ? [] : [$pessoa];
            }
        });

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

    /** @return array{cobrancas: int, lembretes: int} */
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
        $pasta = $this->criarPasta($tenant);
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
}
