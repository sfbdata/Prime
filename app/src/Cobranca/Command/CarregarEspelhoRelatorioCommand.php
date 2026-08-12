<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\DTO\GravarEspelhoRelatorioInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Repository\RelatorioLinhaRepository;
use App\Cobranca\Service\Espelho\ArquivoForaDoLayoutException;
use App\Cobranca\Service\Espelho\AtribuidorDeCarteira;
use App\Cobranca\Service\Espelho\LeitorEspelhoRelatorio;
use App\Cobranca\Service\Espelho\ReconciliacaoInternaFalhouException;
use App\Cobranca\Service\Importacao\RecorteEsperado;
use App\Cobranca\Service\Importacao\ValidadorRodapeFiltros;
use App\Cobranca\UseCase\GravarEspelhoRelatorioUseCase;
use App\Entity\Auth\User;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Guarda relatórios da contabilidade no espelho (SPEC docs/specs/cobranca-espelho-da-contabilidade.md).
 *
 * ⛔ **NÃO é importação.** Não cria dívida, não altera obrigação, não toca em nenhuma tabela de
 * cobrança fora das três do espelho. Serve para o sistema passar a guardar o que a contabilidade
 * disse — hoje ele lê a planilha, extrai o que precisa e joga o resto fora.
 *
 * ⛔ **Carregar histórico pelo importador (`app:cobranca:importar`) é PROIBIDO**: reimportar um
 * arquivo antigo sobrescreveria os encargos atuais com o snapshot velho. Este comando é o caminho
 * seguro — só escreve nas tabelas novas.
 *
 * A carga de um diretório roda em DUAS passadas (INV-AC1 do {@see AtribuidorDeCarteira}): primeiro
 * os arquivos cujo NOME identifica a carteira, depois os demais. Sem isso, um arquivo anônimo lido
 * antes dos nomeados seria recusado só por causa da ordem alfabética.
 *
 * Uso (memória folgada — o relatório maior tem 4.207 linhas):
 *   php -d memory_limit=512M bin/console app:cobranca:espelho:carregar \
 *     --tenant-id=1 --carteira-id=3 --arquivo=/caminho/relatorio.xlsx
 *
 *   php -d memory_limit=512M bin/console app:cobranca:espelho:carregar \
 *     --tenant-id=1 --diretorio="/caminho/planilhas atualizadas"
 */
#[AsCommand(
    name: 'app:cobranca:espelho:carregar',
    description: 'Guarda relatórios da contabilidade no espelho (não importa, não cria dívida)',
)]
final class CarregarEspelhoRelatorioCommand extends Command
{
    use ConfereRecorteDoArquivo;

    public function __construct(
        private readonly GravarEspelhoRelatorioUseCase $gravar,
        private readonly LeitorEspelhoRelatorio $leitor,
        private readonly AtribuidorDeCarteira $atribuidor,
        private readonly RelatorioLinhaRepository $linhas,
        private readonly TenantRepository $tenants,
        private readonly EntityManagerInterface $em,
        private readonly ValidadorRodapeFiltros $validadorRodape,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'ID do escritório dono das carteiras')
            ->addOption('arquivo', null, InputOption::VALUE_REQUIRED, 'Caminho de UM .xlsx a espelhar')
            ->addOption('diretorio', null, InputOption::VALUE_REQUIRED, 'Diretório varrido recursivamente atrás de relatórios de inadimplência')
            ->addOption('carteira-id', null, InputOption::VALUE_REQUIRED, 'Força a carteira (dispensa a atribuição automática)')
            ->addOption('usuario-id', null, InputOption::VALUE_REQUIRED, 'ID do usuário que assina a leitura');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenant = $this->tenants->find((int) $input->getOption('tenant-id'));

        if ($tenant === null) {
            $io->error('Escritório (tenant) não encontrado.');

            return Command::FAILURE;
        }

        /** @var list<Carteira> $carteiras */
        $carteiras = $this->em->getRepository(Carteira::class)->findBy(['tenant' => $tenant]);

        if ($carteiras === []) {
            $io->error('Este escritório não tem carteira de cobrança.');

            return Command::FAILURE;
        }

        $usuario = null;
        $usuarioId = $input->getOption('usuario-id');

        if ($usuarioId !== null) {
            $usuario = $this->em->getRepository(User::class)->find((int) $usuarioId);
        }

        $arquivos = $this->reunirArquivos($input, $io);

        if ($arquivos === null) {
            return Command::FAILURE;
        }

        $carteiraId = $input->getOption('carteira-id');
        $forcada = $this->carteiraForcada($input, $carteiras);

        if ($carteiraId !== null && $forcada === null) {
            // Sem isto, um id digitado errado (ou de OUTRO escritório) caía no `?? atribuidor` da
            // passada 1 e o comando voltava a ADIVINHAR a carteira, reportando sucesso. Pedir uma
            // carteira específica e receber outra é o modo de falha mais perigoso desta carga.
            $io->error(sprintf(
                'Carteira %s não existe neste escritório. Nada foi lido — corrija o --carteira-id ou omita-o.',
                (string) $carteiraId
            ));

            return Command::FAILURE;
        }

        $io->title(sprintf('Espelho da contabilidade — %d arquivo(s)', count($arquivos)));
        $io->text('Nenhuma dívida é criada ou alterada por este comando.');

        // Passada 1: quem se identifica pelo nome. Passada 2: o resto, já com o espelho povoado.
        //
        // ⚠️ Só IDs atravessam as passadas, nunca entidades. O `clear()` entre arquivos (necessário
        // para a memória não acumular ~4 mil entidades por relatório) DESANEXA carteira, tenant e
        // usuário — e uma entidade desanexada reaparece para o Doctrine como "nova", que foi
        // exatamente o erro que derrubou a primeira execução desta carga.
        $tenantId = (int) $input->getOption('tenant-id');
        $usuarioId = $usuario?->getId();

        $porNome = [];
        $anonimos = [];

        foreach ($arquivos as $caminho) {
            $carteira = $forcada ?? $this->atribuidor->porNome(basename($caminho), $carteiras);

            if ($carteira !== null) {
                $porNome[] = [$caminho, (int) $carteira->getId()];

                continue;
            }

            $anonimos[] = $caminho;
        }

        $resumo = [];
        $falhas = 0;

        foreach ($porNome as [$caminho, $carteiraId]) {
            $falhas += $this->espelhar($caminho, $carteiraId, $usuarioId, $io, $resumo);
        }

        foreach ($anonimos as $caminho) {
            $carteira = $this->atribuirSemNome($caminho, $this->carteirasDoTenant($tenantId), $tenantId, $io);

            if ($carteira === null) {
                ++$falhas;
                $resumo[] = [basename($caminho), '—', 'RECUSADO: carteira não identificável', ''];

                continue;
            }

            $falhas += $this->espelhar($caminho, (int) $carteira->getId(), $usuarioId, $io, $resumo);
        }

        $io->table(['arquivo', 'carteira', 'situação', 'linhas'], $resumo);

        if ($falhas > 0) {
            $io->warning(sprintf('%d arquivo(s) não entraram. Nada foi gravado para eles.', $falhas));

            return Command::FAILURE;
        }

        $io->success('Espelho atualizado.');

        return Command::SUCCESS;
    }

    /**
     * @param list<Carteira> $carteiras
     */
    private function atribuirSemNome(string $caminho, array $carteiras, int $tenantId, SymfonyStyle $io): ?Carteira
    {
        if (!$this->recorteConfere($io, $caminho, RecorteEsperado::inadimplencia())) {
            return null;
        }

        try {
            $espelhado = $this->leitor->ler($caminho);
        } catch (ArquivoForaDoLayoutException $e) {
            $io->text(sprintf('  <error>%s</error>: %s', basename($caminho), $e->getMessage()));

            return null;
        }

        $porHonorarios = $this->atribuidor->porHonorarios($espelhado->configDeclarada, $carteiras);

        if ($porHonorarios !== null) {
            return $porHonorarios;
        }

        $unidades = array_values(array_filter(array_map(
            static fn ($l): ?string => $l->unidade,
            $espelhado->linhasDeDados(),
        )));

        $tenant = $this->tenants->find($tenantId);

        if ($tenant === null) {
            return null;
        }

        return $this->atribuidor->porUnidades($unidades, $this->linhas->unidadesPorCarteira($tenant), $carteiras);
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $resumo
     *
     * @return int 1 se falhou, 0 se entrou
     */
    private function espelhar(
        string $caminho,
        int $carteiraId,
        ?int $usuarioId,
        SymfonyStyle $io,
        array &$resumo,
    ): int {
        $nome = basename($caminho);

        // O recorte do rodapé é conferido ANTES de qualquer leitura, como nos quatro importadores.
        // Um relatório emitido com filtro parcial (uma unidade, um período) passaria na reconciliação
        // interna — ela fecha contra o totalizador do PRÓPRIO arquivo filtrado — e entraria no espelho
        // como "a verdade absoluta", produzindo falta em massa na conferência. Sob a premissa deste
        // módulo, espelho envenenado é pior do que espelho vazio.
        if (!$this->recorteConfere($io, $caminho, RecorteEsperado::inadimplencia())) {
            // O trait é compartilhado com os quatro importadores e fala em "importação"; aqui não se
            // importa nada, então a frase precisa ser corrigida em vez de confundir o operador.
            $io->note(sprintf('"%s" não entrou no ESPELHO. Nenhuma dívida foi criada ou alterada.', $nome));
            $resumo[] = [$nome, '—', 'RECUSADO: recorte do relatório', ''];

            return 1;
        }

        // Buscadas AGORA, depois do último `clear()` — ver o aviso no `execute()`.
        $carteira = $this->em->getRepository(Carteira::class)->find($carteiraId);
        $usuario = $usuarioId === null ? null : $this->em->getRepository(User::class)->find($usuarioId);

        if ($carteira === null) {
            $resumo[] = [$nome, '—', 'RECUSADO: carteira sumiu', ''];

            return 1;
        }

        $carteiraNome = $carteira->getNome() ?? '?';

        try {
            $saida = $this->gravar->executar(
                new GravarEspelhoRelatorioInput($carteira, $caminho, lidoPor: $usuario)
            );
        } catch (ArquivoForaDoLayoutException|ReconciliacaoInternaFalhouException $e) {
            $resumo[] = [$nome, $carteiraNome, 'RECUSADO', ''];
            $io->text(sprintf('  <error>%s</error>: %s', $nome, $e->getMessage()));

            return 1;
        }

        $resumo[] = [
            $nome,
            $carteiraNome,
            $saida->jaExistia ? 'já estava' : 'gravado',
            sprintf('%d dados / %d total', $saida->linhasDados, $saida->linhasTotal),
        ];

        // O Doctrine acumula ~4 mil entidades por arquivo; sem limpar, a carga do acervo inteiro
        // estoura a memória por acúmulo entre arquivos.
        $this->em->clear();

        return 0;
    }

    /**
     * Relê as carteiras do escritório — obrigatório depois de um `clear()`.
     *
     * @return list<Carteira>
     */
    private function carteirasDoTenant(int $tenantId): array
    {
        /** @var list<Carteira> $carteiras */
        $carteiras = $this->em->getRepository(Carteira::class)->findBy(['tenant' => $tenantId]);

        return $carteiras;
    }

    /**
     * @param list<Carteira> $carteiras
     */
    private function carteiraForcada(InputInterface $input, array $carteiras): ?Carteira
    {
        $id = $input->getOption('carteira-id');

        if ($id === null) {
            return null;
        }

        foreach ($carteiras as $carteira) {
            if ($carteira->getId() === (int) $id) {
                return $carteira;
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function reunirArquivos(InputInterface $input, SymfonyStyle $io): ?array
    {
        $arquivo = $input->getOption('arquivo');

        if ($arquivo !== null) {
            if (!is_file($arquivo)) {
                $io->error(sprintf('Arquivo não encontrado: %s', $arquivo));

                return null;
            }

            return [$arquivo];
        }

        $diretorio = $input->getOption('diretorio');

        if ($diretorio === null) {
            $io->error('Informe --arquivo ou --diretorio.');

            return null;
        }

        if (!is_dir($diretorio)) {
            // Distinguir "não informou" de "informei e não existe" não é preciosismo: em produção o
            // comando roda DENTRO do container, que não enxerga o sistema de arquivos do servidor.
            // O primeiro operador a rodar isso passou `/opt/jusprime/lotes` (caminho do host) e
            // recebeu "informe --diretorio", que manda procurar o erro no lugar errado.
            $io->error(sprintf('Diretório não encontrado: %s', $diretorio));
            $io->note(
                'Se estiver rodando dentro do container, o caminho tem de ser o de LÁ DENTRO — o '
                . 'diretório do servidor não é visível aqui. Copie o lote antes: '
                . 'docker cp <origem> <container>:/tmp/lote'
            );

            return null;
        }

        $encontrados = [];

        try {
            $varredura = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($diretorio, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            $arquivos = iterator_to_array($varredura, false);
        } catch (\UnexpectedValueException $e) {
            // Subpasta ilegível derrubava o comando com rastro de exceção, sem dizer o que fazer.
            // Acontece de verdade: `docker cp` de um DIRETÓRIO leva junto as permissões restritas do
            // servidor, e o PHP no container roda como usuário comum — o wrapper de importação evita
            // isso copiando arquivo por arquivo para uma pasta criada lá dentro.
            $io->error(sprintf('Não consegui ler o diretório: %s', $e->getMessage()));
            $io->note(
                'Se o lote foi copiado com `docker cp`, ajuste a permissão antes: '
                . 'docker exec -u 0 <container> chmod -R a+rX <diretório>'
            );

            return null;
        }

        foreach ($arquivos as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                continue;
            }

            $nome = $item->getFilename();

            // Só relatórios de inadimplência; os outros três tipos têm layout diferente e
            // entram numa fatia posterior.
            if (str_ends_with(mb_strtolower($nome), '.xlsx') && stripos($nome, 'nadimpl') !== false) {
                $encontrados[] = $item->getPathname();
            }
        }

        sort($encontrados);

        if ($encontrados === []) {
            $io->error('Nenhum relatório de inadimplência (.xlsx) encontrado no diretório.');

            return null;
        }

        return $encontrados;
    }
}
