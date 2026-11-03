<?php

declare(strict_types=1);

namespace App\Blog\Command;

use App\Blog\DTO\ImportacaoDoArtigoOutput;
use App\Blog\DTO\ImportarArtigoDoRepositorioInput;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Exception\ArtigoDoRepositorioRecusado;
use App\Blog\Service\ArquivoDoArtigo;
use App\Blog\UseCase\ImportarArtigoDoRepositorioUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `blog:importar-do-repositorio <pasta-dos-sites>`: traz para o banco os artigos que hoje estão no repositório dos
 * sites (spec painel-artigos-blogs, seção 10, item 5; fatia P2). Roda uma vez, na implantação.
 *
 * A pasta é a raiz do repositório dos sites: lê os `.md` de `blog/src/content/artigos/` e de
 * `blog-gratis/src/content/artigos/` (com as subpastas; arquivo começado por `_` fica de fora, como no Astro). O mesmo
 * slug nos dois blogs, com o mesmo texto, vira um artigo só, em "os dois". Slug que já está no banco é pulado: rodar de
 * novo não duplica. `--simular` mostra o que faria, sem gravar.
 */
#[AsCommand(name: 'blog:importar-do-repositorio', description: 'Importa para o banco os artigos dos blogs que estão no repositório dos sites')]
final class ImportarArtigosDoRepositorioCommand extends Command
{
    private const PASTAS = ['blog' => BlogsDoArtigo::Normal, 'blog-gratis' => BlogsDoArtigo::Gratis];
    private const ARTIGOS = '/src/content/artigos';

    public function __construct(private readonly ImportarArtigoDoRepositorioUseCase $importar)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pasta', InputArgument::REQUIRED, 'A raiz do repositório dos sites (a pasta que tem blog/ e blog-gratis/)')
            ->addOption('simular', null, InputOption::VALUE_NONE, 'Mostra o que seria importado, sem gravar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raiz = rtrim((string) $input->getArgument('pasta'), '/');
        $simular = (bool) $input->getOption('simular');
        $pastas = array_filter(self::PASTAS, static fn (string $pasta): bool => is_dir($raiz . '/' . $pasta . self::ARTIGOS), \ARRAY_FILTER_USE_KEY);
        if ($pastas === []) {
            $output->writeln(sprintf('<error>A pasta "%s" não tem blog%2$s nem blog-gratis%2$s.</error>', $raiz, self::ARTIGOS));

            return Command::INVALID;
        }
        if ($simular) {
            $output->writeln('Simulação: nada será gravado.');
        }

        $recusados = 0;
        $artigos = $this->lerArquivos($raiz, $pastas, $output, $recusados);
        $slugs = array_keys($artigos);
        $importados = 0;
        $pulados = 0;

        foreach ($artigos as $slug => [$conteudo, $blogs, $origem]) {
            try {
                $resultado = $this->importar->executar(new ImportarArtigoDoRepositorioInput($conteudo, $blogs, $simular, array_map('strval', $slugs)));
            } catch (\DomainException $e) {
                $output->writeln(sprintf('Recusado: %s (%s). %s', $slug, $origem, $e->getMessage()));
                ++$recusados;
                continue;
            }
            $this->escrever($output, $resultado, $origem, $simular);
            $resultado->importado ? ++$importados : ++$pulados;
        }

        $output->writeln(sprintf('%s: %d. Já existiam: %d. Recusados: %d.', $simular ? 'Seriam importados' : 'Importados', $importados, $pulados, $recusados));

        return $recusados > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Lê os arquivos dos blogs e junta pelo slug do frontmatter.
     *
     * @param array<string, BlogsDoArtigo> $pastas
     *
     * @return array<string, array{string, BlogsDoArtigo, string}> slug => [conteúdo, blogs, de onde veio]
     */
    private function lerArquivos(string $raiz, array $pastas, OutputInterface $output, int &$recusados): array
    {
        /** @var array<string, array<string, array{string, string}>> $porSlug slug => pasta => [conteúdo, caminho] */
        $porSlug = [];
        $duplicados = [];
        foreach ($pastas as $pasta => $blogs) {
            foreach ($this->arquivosMd($raiz . '/' . $pasta . self::ARTIGOS) as $caminho) {
                $relativo = substr($caminho, \strlen($raiz) + 1);
                $conteudo = (string) file_get_contents($caminho);
                try {
                    $slug = ArquivoDoArtigo::ler($conteudo)->slug();
                } catch (ArtigoDoRepositorioRecusado $e) {
                    $output->writeln(sprintf('Recusado: %s. %s', $relativo, $e->getMessage()));
                    ++$recusados;
                    continue;
                }
                if (isset($porSlug[$slug][$pasta])) {
                    // Dois arquivos com o mesmo slug no mesmo blog: recusa os dois, para não ficar só o último sem aviso.
                    $output->writeln(sprintf('Recusado: %s. O slug "%s" já aparece em %s.', $relativo, $slug, $porSlug[$slug][$pasta][1]));
                    ++$recusados;
                    $duplicados[$slug] = true;
                    continue;
                }
                $porSlug[$slug][$pasta] = [$conteudo, $relativo];
            }
        }
        foreach (array_keys($duplicados) as $slug) {
            $output->writeln(sprintf('Recusado: %s. Corrija o slug repetido e rode de novo.', $porSlug[$slug][array_key_first($porSlug[$slug])][1]));
            ++$recusados;
            unset($porSlug[$slug]);
        }
        ksort($porSlug);

        $artigos = [];
        foreach ($porSlug as $slug => $origens) {
            $slug = (string) $slug;
            if (\count($origens) === 1) {
                $pasta = (string) array_key_first($origens);
                $artigos[$slug] = [$origens[$pasta][0], self::PASTAS[$pasta], $origens[$pasta][1]];
                continue;
            }
            if (trim($origens['blog'][0]) !== trim($origens['blog-gratis'][0])) {
                $output->writeln(sprintf('Recusado: %s. %s', $slug, ArtigoDoRepositorioRecusado::textosDiferentesNosDoisBlogs($slug)->getMessage()));
                ++$recusados;
                continue;
            }
            $artigos[$slug] = [$origens['blog'][0], BlogsDoArtigo::Ambos, $origens['blog'][1] . ' e ' . $origens['blog-gratis'][1]];
        }

        return $artigos;
    }

    /** @return list<string> os `.md` da pasta e das subpastas, em ordem; nome começado por `_` fica de fora */
    private function arquivosMd(string $pasta): array
    {
        $arquivos = [];
        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterador as $arquivo) {
            if (!$arquivo instanceof \SplFileInfo || !$arquivo->isFile() || strtolower($arquivo->getExtension()) !== 'md') {
                continue;
            }
            $relativo = substr($arquivo->getPathname(), \strlen($pasta) + 1);
            if (preg_match('#(^|/)_#', $relativo) === 1) {
                continue;
            }
            $arquivos[] = $arquivo->getPathname();
        }
        sort($arquivos);

        return $arquivos;
    }

    private function escrever(OutputInterface $output, ImportacaoDoArtigoOutput $resultado, string $origem, bool $simular): void
    {
        if (!$resultado->importado) {
            $output->writeln(sprintf('Já existe no banco, pulado: %s (%s).', $resultado->slug, $origem));

            return;
        }

        $output->writeln(sprintf(
            '%s: %s (%s), blog: %s, situação: %s.',
            $simular ? 'Seria importado' : 'Importado',
            $resultado->slug,
            $origem,
            $resultado->blogs?->rotulo(),
            $resultado->situacao?->rotulo(),
        ));
        foreach ($resultado->avisos as $aviso) {
            $output->writeln('  Aviso: ' . $aviso);
        }
    }
}
