<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Controller\PeticionarController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\PreviaDeDocumentoOffice;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as EscritorXlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** `GET /pasta/documento/{id}/previa`: DOCX e XLSX em HTML só de leitura (F9 do Peticionar). */
#[CoversClass(PeticionarController::class)]
#[CoversClass(PreviaDeDocumentoOffice::class)]
final class PeticionarPreviaOfficeControllerTest extends JusPrimeWebTestCase
{
    private function docx(): string
    {
        $word = new PhpWord();
        $word->addSection()->addText('Texto da petição de teste');
        $caminho = tempnam(sys_get_temp_dir(), 'prv') ?: '';
        IOFactory::createWriter($word, 'Word2007')->save($caminho);
        $conteudo = (string) file_get_contents($caminho);
        @unlink($caminho);

        return $conteudo;
    }

    private function xlsx(): string
    {
        $planilha = new Spreadsheet();
        $planilha->getActiveSheet()->setCellValue('A1', 'Cliente')->setCellValue('B1', 'Valor ABC')->setCellValue('A900', 'Linha cortada');
        $caminho = tempnam(sys_get_temp_dir(), 'prv') ?: '';
        (new EscritorXlsx($planilha))->save($caminho);
        $conteudo = (string) file_get_contents($caminho);
        @unlink($caminho);

        return $conteudo;
    }

    /** @return array{\Symfony\Bundle\FrameworkBundle\KernelBrowser, callable(string, string): PastaDocumento} */
    private function cenario(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $armazenamento = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());

        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant PREVIA ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('previa_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Prévia');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));

        $pasta = new Pasta();
        $pasta->setNup('PREV-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        $criar = function (string $mime, string $conteudo) use ($em, $pasta, $tenant, $armazenamento): PastaDocumento {
            $doc = new PastaDocumento();
            $pasta->addDocumento($doc);
            $doc->setTenant($tenant);
            $doc->setTitulo('Arquivo');
            $doc->setCategoria(PastaDocumento::CATEGORIA_PECA);
            $doc->setNomeOriginal('arquivo');
            $doc->setCaminhoArquivo('prev_' . uniqid() . '.bin');
            $doc->setMimeType($mime);
            $doc->setTamanhoBytes(strlen($conteudo));
            $em->persist($doc);
            $em->flush();
            $armazenamento->gravar(ChavesDePasta::documento($doc), FonteDeConteudo::deTexto($conteudo));

            return $doc;
        };

        return [$client, $criar];
    }

    #[TestDox('DOCX vira HTML com o texto, sob CSP sandbox sem script')]
    public function testDocx(): void
    {
        [$client, $criar] = $this->cenario();
        $doc = $criar(PreviaDeDocumentoOffice::MIME_DOCX, $this->docx());

        $client->request('GET', "/pasta/documento/{$doc->getId()}/previa");

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Texto da petição de teste', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('sandbox', (string) $client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertStringContainsString("default-src 'none'", (string) $client->getResponse()->headers->get('Content-Security-Policy'));
    }

    #[TestDox('XLSX vira tabela e corta as linhas além do limite')]
    public function testXlsx(): void
    {
        [$client, $criar] = $this->cenario();
        $doc = $criar(PreviaDeDocumentoOffice::MIME_XLSX, $this->xlsx());

        $client->request('GET', "/pasta/documento/{$doc->getId()}/previa");

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Valor ABC', $html);
        self::assertStringNotContainsString('Linha cortada', $html, 'a linha 900 passa de ' . PreviaDeDocumentoOffice::LINHAS);
    }

    #[TestDox('arquivo que não é Office válido e tipo sem prévia mostram o aviso, nunca erro')]
    public function testSemPrevia(): void
    {
        [$client, $criar] = $this->cenario();
        $ruim = $criar(PreviaDeDocumentoOffice::MIME_DOCX, 'isto não é um docx');
        $pdf  = $criar('application/pdf', '%PDF-1.4');

        foreach ([$ruim, $pdf] as $doc) {
            $client->request('GET', "/pasta/documento/{$doc->getId()}/previa");
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Use Baixar', (string) $client->getResponse()->getContent());
        }
    }
}
