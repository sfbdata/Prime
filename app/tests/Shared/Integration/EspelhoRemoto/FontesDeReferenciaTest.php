<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\Comercial\Entity\ImagemDaCampanha;
use App\Entity\Tarefa\TarefaMensagem;
use App\Pasta\Entity\PastaDocumento;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\EspelhoRemoto\Liberacao\DatasDasReferencias;
use App\Shared\EspelhoRemoto\Reconciliacao\FontesDeReferencia;
use App\Shared\EspelhoRemoto\Reconciliacao\ReferenciaDeArquivo;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\DTO\DadosDoCurriculo;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\DocumentoDemanda;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\TipoArquivoDoCurriculo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * As fontes reais da reconciliação, contra linhas de verdade no banco: o escopo sai da coluna de
 * escritório da própria linha, o nome vai byte a byte, e o valor de tarefa passa pela MESMA regra
 * da `ChavesDeTarefa` (`admin/` e lixo não viram chave).
 */
#[CoversClass(FontesDeReferencia::class)]
#[CoversClass(DatasDasReferencias::class)]
final class FontesDeReferenciaTest extends KernelTestCase
{
    use Factories;

    #[TestDox('documento de pasta e anexo de tarefa viram chaves com o escritório da linha; valor fora do formato vira referência sem chave')]
    public function testReferenciasDasLinhas(): void
    {
        self::bootKernel();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant]);
        $tarefa = TarefaFactory::createOne(['pasta' => $pasta, 'tenant' => $tenant]);
        $autor  = UserFactory::createOne();

        $nome = 'e36d9f9f37beec5e7463a93e755e10a2. AÇAÇ - 01 AAAAA 2025';
        $doc  = new PastaDocumento();
        $doc->setTitulo('t');
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo($nome);
        $doc->setNomeOriginal('t.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(1);
        $doc->setPasta($pasta->_real());
        $doc->setTenant($tenant->_real());
        $em->persist($doc);

        $valido = $this->mensagem($tarefa->_real(), $autor->_real(), '/uploads/tarefas/chat/arquivo_6abab5669fac90.42347212.pdf');
        $admin  = $this->mensagem($tarefa->_real(), $autor->_real(), '/uploads/tarefas/admin/legado.pdf');
        foreach ([$valido, $admin] as $mensagem) {
            $em->persist($mensagem);
        }
        $em->flush();

        $porLinha = [];
        foreach (static::getContainer()->get(FontesDeReferencia::class)->referencias() as $referencia) {
            /** @var ReferenciaDeArquivo $referencia */
            $porLinha[$referencia->fonte . '#' . $referencia->idDaLinha] = $referencia;
        }

        $refDoc = $porLinha['pasta_documento#' . $doc->getId()] ?? self::fail('documento não listado');
        self::assertSame(CategoriaDeArquivo::PASTA_DOCUMENTO, $refDoc->chave?->categoria);
        self::assertSame($tenant->getId(), $refDoc->chave?->escopo->tenantIdOuNull());
        self::assertSame($nome, $refDoc->chave?->nome, 'byte a byte');

        $refValido = $porLinha['tarefa_mensagem#' . $valido->getId()] ?? self::fail('anexo válido não listado');
        self::assertSame(CategoriaDeArquivo::TAREFA_ANEXO, $refValido->chave?->categoria);
        self::assertSame('arquivo_6abab5669fac90.42347212.pdf', $refValido->chave?->nome);
        self::assertSame($tenant->getId(), $refValido->chave?->escopo->tenantIdOuNull());

        $refAdmin = $porLinha['tarefa_mensagem#' . $admin->getId()] ?? self::fail('anexo admin não listado');
        self::assertNull($refAdmin->chave, 'admin/ não é endereçável (DP-9): sai como chave inválida, nunca como outra chave');
    }

    #[TestDox('anexo novo de tarefa (pasta_documento:<id>) não entra pela fonte de tarefa: nem como chave, nem como fora do formato')]
    public function testAnexoNovoDeTarefaNaoViraForaDoFormato(): void
    {
        self::bootKernel();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = TenantFactory::createOne();
        $tarefa = TarefaFactory::createOne(['pasta' => PastaFactory::createOne(['tenant' => $tenant]), 'tenant' => $tenant]);

        $autor   = UserFactory::createOne()->_real();
        $novo    = $this->mensagem($tarefa->_real(), $autor, TarefaMensagem::PREFIXO_DOCUMENTO_DA_PASTA . '123');
        $legado  = $this->mensagem($tarefa->_real(), $autor, '/uploads/tarefas/chat/arquivo_6abab5669fac90.42347212.pdf');
        $em->persist($novo);
        $em->persist($legado);
        $em->flush();

        $porLinha = iterator_to_array($this->referencias());

        self::assertArrayNotHasKey('tarefa_mensagem#' . $novo->getId(), $porLinha, 'o documento da pasta já é coberto pela fonte pasta_documento');
        self::assertNotNull(($porLinha['tarefa_mensagem#' . $legado->getId()] ?? self::fail('legado não listado'))->chave, 'o legado continua virando chave');
    }

    #[TestDox('Trabalho Externo e Comercial: o documento leva o escritório da demanda; currículo e imagem da campanha são globais')]
    public function testFontesDoTrabalhoExternoEDoComercial(): void
    {
        self::bootKernel();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = TenantFactory::createOne();
        $pessoa = UserFactory::createOne()->_real();

        $demanda = new DemandaExterna($tenant->_real(), $pessoa);
        $doc     = new DocumentoDemanda($demanda, 'peticao.pdf', 'a1b2c3d4.pdf', 10, 'application/pdf', Autor::de($pessoa, Perfil::Solicitante));
        $agora   = new \DateTimeImmutable();
        $cv      = Curriculo::criar($pessoa, DadosDoCurriculo::deArray(['nome' => 'Ana Souza', 'email' => 'ana@exemplo.test', 'situacao' => 'bach', 'lgpd' => true, 'areas' => []]), $agora);
        $cv->anexarArquivo(TipoArquivoDoCurriculo::Curriculo, 'cv0001.pdf', 'curriculo.pdf', $agora);
        $cv->anexarArquivo(TipoArquivoDoCurriculo::Foto, 'cv0002.jpg', 'foto.jpg', $agora);
        // Currículo sem arquivo: a coluna fica `[]`, o valor inicial e o de depois de remover o último.
        $vazio = Curriculo::criar(UserFactory::createOne()->_real(), DadosDoCurriculo::deArray(['nome' => 'Bia Lima', 'email' => 'bia@exemplo.test', 'situacao' => 'bach', 'lgpd' => true, 'areas' => []]), $agora);
        $em->persist($vazio);
        $imagem = new ImagemDaCampanha('img0001.jpg', 'image/jpeg', 1, str_repeat('0', 64), 1, 1, $pessoa, $agora, $agora->modify('+30 days'));
        foreach ([$demanda, $doc, $cv, $imagem] as $entidade) {
            $em->persist($entidade);
        }
        $em->flush();

        $porLinha = iterator_to_array($this->referencias());

        $refDoc = $porLinha['trabalho_externo_documento#' . $doc->getId()] ?? self::fail('documento da demanda não listado');
        self::assertSame(CategoriaDeArquivo::TRABALHO_EXTERNO_DOCUMENTO, $refDoc->chave?->categoria);
        self::assertSame($tenant->getId(), $refDoc->chave?->escopo->tenantIdOuNull());
        self::assertSame('a1b2c3d4.pdf', $refDoc->chave?->nome);

        $doCurriculo = [];
        foreach (static::getContainer()->get(FontesDeReferencia::class)->referencias() as $referencia) {
            /** @var ReferenciaDeArquivo $referencia */
            if ($referencia->fonte === 'trabalho_externo_curriculo' && $referencia->idDaLinha === $cv->getId()) {
                self::assertSame(CategoriaDeArquivo::TRABALHO_EXTERNO_CURRICULO, $referencia->chave?->categoria);
                self::assertTrue($referencia->chave?->escopo->ehGlobal());
                $doCurriculo[] = $referencia->chave?->nome;
            }
        }
        self::assertEqualsCanonicalizing(['cv0001.pdf', 'cv0002.jpg'], $doCurriculo, 'um arquivo do currículo, uma referência');
        self::assertSame([], array_filter(
            array_keys($porLinha),
            static fn (string $chave): bool => $chave === 'trabalho_externo_curriculo#' . $vazio->getId(),
        ), 'currículo sem arquivo não gera referência');

        $refImagem = $porLinha['comercial_imagem#' . $imagem->getId()] ?? self::fail('imagem da campanha não listada');
        self::assertSame(CategoriaDeArquivo::COMERCIAL_IMAGEM, $refImagem->chave?->categoria);
        self::assertTrue($refImagem->chave?->escopo->ehGlobal());
        self::assertSame('img0001.jpg', $refImagem->chave?->nome);
    }

    #[TestDox('foto do cliente: vira chave com o escritório do cliente; cliente sem foto não gera referência')]
    public function testFotoDoCliente(): void
    {
        self::bootKernel();
        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $tenant  = TenantFactory::createOne();
        $comFoto = ClientePFFactory::createOne(['tenant' => $tenant])->_real();
        $semFoto = ClientePFFactory::createOne(['tenant' => $tenant])->_real();
        $comFoto->trocarFoto('f1a2b3c4.jpg', new \DateTimeImmutable());
        $em->flush();

        $porLinha = iterator_to_array($this->referencias());

        $ref = $porLinha['cliente_foto#' . $comFoto->getId()] ?? self::fail('foto do cliente não listada');
        self::assertSame(CategoriaDeArquivo::CLIENTE_FOTO, $ref->chave?->categoria);
        self::assertSame($tenant->getId(), $ref->chave?->escopo->tenantIdOuNull());
        self::assertSame('f1a2b3c4.jpg', $ref->chave?->nome);
        self::assertArrayNotHasKey('cliente_foto#' . $semFoto->getId(), $porLinha, 'cliente sem foto não gera referência');
    }

    #[TestDox('foto da pasta e logo do escritório: viram chave com o escritório da própria linha; sem arquivo, não geram referência')]
    public function testFotoDaPastaELogoDoEscritorio(): void
    {
        self::bootKernel();
        $conexao = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $tenant  = TenantFactory::createOne()->_real();
        $semLogo = TenantFactory::createOne()->_real();
        $comFoto = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $semFoto = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $conexao->executeStatement('UPDATE pasta SET foto = ? WHERE id = ?', ['a1b2c3d4.jpg', $comFoto->getId()]);
        $conexao->executeStatement('UPDATE pasta SET foto = NULL WHERE id = ?', [$semFoto->getId()]);
        $conexao->executeStatement('UPDATE tenant SET logo_chave = ? WHERE id = ?', ['e5f6a7b8.png', $tenant->getId()]);
        $conexao->executeStatement("UPDATE tenant SET logo_chave = '' WHERE id = ?", [$semLogo->getId()]);

        $porLinha = iterator_to_array($this->referencias());

        $foto = $porLinha['pasta_foto#' . $comFoto->getId()] ?? self::fail('foto da pasta não listada');
        self::assertSame(CategoriaDeArquivo::PASTA_FOTO, $foto->chave?->categoria);
        self::assertSame($tenant->getId(), $foto->chave?->escopo->tenantIdOuNull());
        self::assertSame('a1b2c3d4.jpg', $foto->chave?->nome);
        self::assertArrayNotHasKey('pasta_foto#' . $semFoto->getId(), $porLinha, 'pasta sem foto não gera referência');

        $logo = $porLinha['tenant_logo#' . $tenant->getId()] ?? self::fail('logo do escritório não listada');
        self::assertSame(CategoriaDeArquivo::TENANT_LOGO, $logo->chave?->categoria);
        self::assertSame($tenant->getId(), $logo->chave?->escopo->tenantIdOuNull());
        self::assertSame('e5f6a7b8.png', $logo->chave?->nome);
        self::assertArrayNotHasKey('tenant_logo#' . $semLogo->getId(), $porLinha, 'logo vazia não gera referência');
    }

    #[TestDox('anexo do chat, versão de documento jurídico, comprovante de recompensa e imagem do blog: as consultas rodam contra o esquema de teste; as linhas que houver saem com o escopo certo')]
    public function testFontesDasCategoriasNovas(): void
    {
        self::bootKernel();
        $fontes = [];
        foreach ($this->referencias() as $referencia) {
            $fontes[$referencia->fonte][] = $referencia;
        }

        foreach (['inteligencia_documento_juridico_versao', 'lancamento_de_recompensa', 'artigo_blog_imagem'] as $global) {
            foreach ($fontes[$global] ?? [] as $referencia) {
                self::assertNull($referencia->chave?->escopo->tenantIdOuNull(), $global . ' é global');
            }
        }
        foreach ($fontes['chat_ia_anexo'] ?? [] as $referencia) {
            self::assertNotNull($referencia->chave?->escopo->tenantIdOuNull(), 'anexo do chat tem escritório');
        }
        self::addToAssertionCount(1); // chegar aqui prova que as seis consultas rodam no esquema de teste
    }

    #[TestDox('rubrica de quem assinou (Assinatura, etapa 2): vira chave com o escritório do documento; sem rubrica, não gera referência')]
    public function testRubricaDaAssinatura(): void
    {
        self::bootKernel();
        [$tenantId, $assinou, $pendente] = $this->signatariosDaAssinatura();
        $conexao = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conexao->executeStatement('UPDATE assinatura_eletronica_signatario SET rubrica_chave = ? WHERE id = ?', ['c0ffee00c0ffee00c0ffee00c0ffee00.png', $assinou]);

        $porLinha = iterator_to_array($this->referencias());

        $rubrica = $porLinha['assinatura_signatario_rubrica#' . $assinou] ?? self::fail('rubrica do signatário não listada');
        self::assertSame(CategoriaDeArquivo::ASSINATURA_DOCUMENTO, $rubrica->chave?->categoria);
        self::assertSame($tenantId, $rubrica->chave?->escopo->tenantIdOuNull());
        self::assertSame('c0ffee00c0ffee00c0ffee00c0ffee00.png', $rubrica->chave?->nome);
        self::assertArrayNotHasKey('assinatura_signatario_rubrica#' . $pendente, $porLinha, 'quem não assinou não gera referência');
    }

    #[TestDox('selfie cifrada de quem assinou no nível Reforçada (etapa 7): vira chave com o escritório do documento; sem selfie, não gera referência')]
    public function testSelfieDaAssinatura(): void
    {
        self::bootKernel();
        [$tenantId, $assinou, $pendente] = $this->signatariosDaAssinatura();
        $conexao = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conexao->executeStatement('UPDATE assinatura_eletronica_signatario SET selfie_chave = ? WHERE id = ?', ['beefcafebeefcafebeefcafebeefcafe.cifrado', $assinou]);

        $porLinha = iterator_to_array($this->referencias());

        $selfie = $porLinha['assinatura_signatario_selfie#' . $assinou] ?? self::fail('selfie do signatário não listada');
        self::assertSame(CategoriaDeArquivo::ASSINATURA_DOCUMENTO, $selfie->chave?->categoria);
        self::assertSame($tenantId, $selfie->chave?->escopo->tenantIdOuNull());
        self::assertSame('beefcafebeefcafebeefcafebeefcafe.cifrado', $selfie->chave?->nome);
        self::assertArrayNotHasKey('assinatura_signatario_selfie#' . $pendente, $porLinha, 'quem não mandou selfie não gera referência');
    }

    #[TestDox('PDF final com selo (Assinatura, etapa 3): vira chave com o escritório do documento; sem PDF final, não gera referência; a data da liberação é a última alteração e a Lixeira conta')]
    public function testPdfFinalDaAssinatura(): void
    {
        self::bootKernel();
        $conexao = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        [$tenantId, $assinou] = $this->signatariosDaAssinatura();
        $comFinal = (int) $conexao->fetchOne('SELECT documento_id FROM assinatura_eletronica_signatario WHERE id = ?', [$assinou]);
        [, $outro] = $this->signatariosDaAssinatura();
        $semFinal = (int) $conexao->fetchOne('SELECT documento_id FROM assinatura_eletronica_signatario WHERE id = ?', [$outro]);
        $conexao->executeStatement(
            "UPDATE assinatura_eletronica_documento SET arquivo_final_chave = ?, alterado_em = '2026-01-02 03:04:05', excluido_em = '2026-02-01 00:00:00' WHERE id = ?",
            ['5e105e105e105e105e105e105e105e10.pdf', $comFinal],
        );

        $porLinha = iterator_to_array($this->referencias());

        $final = $porLinha['assinatura_documento_final#' . $comFinal] ?? self::fail('PDF final do documento não listado');
        self::assertSame(CategoriaDeArquivo::ASSINATURA_DOCUMENTO, $final->chave?->categoria);
        self::assertSame($tenantId, $final->chave?->escopo->tenantIdOuNull());
        self::assertSame('5e105e105e105e105e105e105e105e10.pdf', $final->chave?->nome);
        self::assertArrayNotHasKey('assinatura_documento_final#' . $semFinal, $porLinha, 'documento sem PDF final não gera referência');

        $datas = (new DatasDasReferencias($conexao))->para([$final]);
        $data = $datas[DatasDasReferencias::chaveDe('assinatura_documento_final', $comFinal)] ?? self::fail('data do PDF final não respondida');
        self::assertNull($data['data'], 'o PDF final nasce depois do último salvamento: a data é a do arquivo no disco');
        self::assertTrue($data['naLixeira'], 'documento na Lixeira fica fora da liberação');
    }

    #[TestDox('versão guardada do PDF (Assinatura, etapa 5): vira chave com o escritório do documento; a data da liberação é a da criação e a Lixeira é a do documento')]
    public function testVersaoDoPdfDaAssinatura(): void
    {
        self::bootKernel();
        $conexao = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        [$tenantId, $daLixeira] = $this->signatariosDaAssinatura();
        [$outroTenant, $ativo] = $this->signatariosDaAssinatura();
        $versoes = [];
        foreach ([[$daLixeira, '0a0b0c0d0e0f00010203040506070809.pdf'], [$ativo, '1a1b1c1d1e1f10111213141516171819.pdf']] as [$signatario, $chave]) {
            $documento = (int) $conexao->fetchOne('SELECT documento_id FROM assinatura_eletronica_signatario WHERE id = ?', [$signatario]);
            $versoes[] = (int) $conexao->fetchOne(
                "INSERT INTO assinatura_eletronica_versao (documento_id, signatario_id, ordem, origem, arquivo_chave, nome_original, sha256, tamanho, analise, criado_em)
                 VALUES (?, NULL, 1, 'original', ?, 'Contrato.pdf', ?, 10, '{\"v\":1}', '2026-03-04 05:06:07') RETURNING id",
                [$documento, $chave, str_repeat('a', 64)],
            );
        }
        [$naLixeira, $ativa] = $versoes;
        $conexao->executeStatement(
            "UPDATE assinatura_eletronica_documento SET excluido_em = '2026-04-01 00:00:00' WHERE id = (SELECT documento_id FROM assinatura_eletronica_versao WHERE id = ?)",
            [$naLixeira],
        );

        $porLinha = iterator_to_array($this->referencias());

        $versao = $porLinha['assinatura_versao#' . $naLixeira] ?? self::fail('versão do PDF não listada');
        self::assertSame(CategoriaDeArquivo::ASSINATURA_DOCUMENTO, $versao->chave?->categoria);
        self::assertSame($tenantId, $versao->chave?->escopo->tenantIdOuNull());
        self::assertSame('0a0b0c0d0e0f00010203040506070809.pdf', $versao->chave?->nome);
        $outra = $porLinha['assinatura_versao#' . $ativa] ?? self::fail('versão do outro escritório não listada');
        self::assertSame($outroTenant, $outra->chave?->escopo->tenantIdOuNull(), 'cada versão leva o escritório do próprio documento');

        $datas = (new DatasDasReferencias($conexao))->para([$versao, $outra]);
        $lixeira = $datas[DatasDasReferencias::chaveDe('assinatura_versao', $naLixeira)] ?? self::fail('data da versão não respondida');
        self::assertSame('2026-03-04 05:06:07', $lixeira['data']?->format('Y-m-d H:i:s'), 'a versão nunca muda: a data é a da criação');
        self::assertTrue($lixeira['naLixeira'], 'documento na Lixeira fica fora da liberação');
        self::assertFalse(($datas[DatasDasReferencias::chaveDe('assinatura_versao', $ativa)] ?? self::fail('data da versão ativa não respondida'))['naLixeira']);
    }

    /**
     * Um documento da Assinatura com dois signatários gravados, no escritório de uma fábrica.
     *
     * @return array{int, int, int} o id do escritório e os ids dos dois signatários
     */
    private function signatariosDaAssinatura(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = TenantFactory::createOne()->_real();
        $agora = new \DateTimeImmutable();
        $documento = new DocumentoParaAssinar($tenant, null, $agora);
        $documento->aplicarRascunho(DadosDoRascunho::deJson([
            'nome' => 'Contrato',
            'signatarios' => [['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com'], ['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com']],
        ]), [], $agora);
        $em->persist($documento);
        $em->flush();
        [$primeiro, $segundo] = $documento->getSignatarios();

        return [(int) $tenant->getId(), (int) $primeiro->getId(), (int) $segundo->getId()];
    }

    /** @return \Generator<string, ReferenciaDeArquivo> */
    private function referencias(): \Generator
    {
        foreach (static::getContainer()->get(FontesDeReferencia::class)->referencias() as $referencia) {
            /** @var ReferenciaDeArquivo $referencia */
            yield $referencia->fonte . '#' . $referencia->idDaLinha => $referencia;
        }
    }

    private function mensagem(\App\Entity\Tarefa\Tarefa $tarefa, \App\Entity\Auth\User $autor, string $valor): TarefaMensagem
    {
        $mensagem = new TarefaMensagem();
        $mensagem->setTarefa($tarefa);
        $mensagem->setUsuario($autor);
        $mensagem->setMensagem('m');
        $mensagem->setTenant($tarefa->getTenant() ?? self::fail('tarefa sem escritório'));
        $mensagem->setArquivoAnexo($valor);

        return $mensagem;
    }
}
