<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Functional;

use App\ChatIa\Armazenamento\ChavesDeChatIa;
use App\ChatIa\Entity\AnexoDeChat;
use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Entity\MensagemDeChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Tenant\UseCase\PurgarEscritorioUseCase;
use App\Tests\Factory\Auth\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/** A purga do escritório leva as três tabelas do Chat I.A e os arquivos dos anexos, e só os do escritório purgado. */
#[CoversClass(PurgarEscritorioUseCase::class)]
final class PurgaDoChatTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private ArmazenamentoDeArquivos $armazenamento;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->armazenamento = static::getContainer()->get(ArmazenamentoDeArquivos::class);
    }

    private function escritorEmQuarentena(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Chat purga ' . uniqid());
        $tenant->setIsActive(false);
        $tenant->setExcluidoEm(new \DateTimeImmutable('-400 days'));
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @return array{AnexoDeChat, \App\Shared\Armazenamento\ChaveDeArquivo} */
    private function semearConversa(Tenant $tenant, User $dono): array
    {
        $agora = new \DateTimeImmutable('2026-10-28 10:00:00');
        $conversa = new ConversaDeChat($tenant, $dono, ModeloDeIa::PADRAO, $agora);
        $this->em->persist($conversa);
        $pergunta = MensagemDeChat::doUsuario($conversa, 'Pergunta', null, $agora);
        $this->em->persist($pergunta);
        $this->em->persist(MensagemDeChat::doAssistente($conversa, 'Resposta', 'claude-haiku-5-5', 1, 1, false, $agora));
        $armazenado = $this->armazenamento->gravar(ChavesDeChatIa::novoAnexo($conversa, 'png'), FonteDeConteudo::deTexto('conteudo'));
        $anexo = new AnexoDeChat($tenant, $dono, $conversa, $armazenado->chave->nome, 'foto.png', 'image/png', 8, $agora);
        $anexo->anexarA($pergunta);
        $this->em->persist($anexo);
        $this->em->flush();

        return [$anexo, ChavesDeChatIa::anexo($anexo)];
    }

    private function contar(string $tabela, int $tenantId): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM $tabela WHERE tenant_id = ?", [$tenantId]);
    }

    #[TestDox('Purgar o escritório apaga conversas, mensagens, anexos e os arquivos do chat; o vizinho fica intacto')]
    public function testPurgaCobreOChat(): void
    {
        $dono = UserFactory::createOne()->_real();
        $alvo = $this->escritorEmQuarentena();
        $vizinho = $this->escritorEmQuarentena();
        [, $chaveDoAlvo] = $this->semearConversa($alvo, $dono);
        [, $chaveDoVizinho] = $this->semearConversa($vizinho, $dono);
        self::assertTrue($this->armazenamento->existe($chaveDoAlvo));

        $resultado = static::getContainer()->get(PurgarEscritorioUseCase::class)->executar($alvo, dryRun: false);

        foreach (['chat_ia_conversa', 'chat_ia_mensagem', 'chat_ia_anexo'] as $tabela) {
            self::assertSame(0, $this->contar($tabela, (int) $alvo->getId()), "$tabela do escritório purgado ficou");
        }
        self::assertFalse($this->armazenamento->existe($chaveDoAlvo), 'o arquivo do anexo deveria ter saído do disco');
        self::assertSame([], $resultado->arquivosNaoRemovidos);

        foreach (['chat_ia_conversa' => 1, 'chat_ia_mensagem' => 2, 'chat_ia_anexo' => 1] as $tabela => $esperadas) {
            self::assertSame($esperadas, $this->contar($tabela, (int) $vizinho->getId()), "$tabela do vizinho foi apagada");
        }
        self::assertTrue($this->armazenamento->existe($chaveDoVizinho), 'a purga do alvo apagou o arquivo do vizinho');
        $this->armazenamento->excluir($chaveDoVizinho);
    }
}
